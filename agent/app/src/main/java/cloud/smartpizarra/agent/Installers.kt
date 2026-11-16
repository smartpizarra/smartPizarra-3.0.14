// Versión del archivo: 1.0.0 (2026-11-16)
package cloud.smartpizarra.agent

import android.app.PendingIntent
import android.app.admin.DevicePolicyManager
import android.content.BroadcastReceiver
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.content.pm.PackageManager
import android.os.Build
import android.util.Log
import java.io.File
import java.util.concurrent.ArrayBlockingQueue
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.TimeUnit

/** Resultado normalizado de una instalación o desinstalación. */
sealed class InstallResult {
    object Ok : InstallResult()
    /** Error que puede arreglarse solo (espacio, red, sesión abortada): se reintenta con backoff. */
    data class Retry(val code: String, val detail: String = "") : InstallResult()
    /** Error definitivo (APK incompatible, otra firma, versión inferior...): no se reintenta. */
    data class Fail(val code: String, val detail: String = "") : InstallResult()
}

interface Installer {
    /** owner | system | root | interactive (se informa al servidor en cada heartbeat). */
    val mode: String
    fun install(apk: File, pkg: String): InstallResult
    fun uninstall(pkg: String): InstallResult
}

/**
 * Elige el instalador según el privilegio que tenga el equipo, del más fiable al menos:
 *  1. Device Owner  (adb shell dpm set-device-owner ...): PackageInstaller sin preguntar.
 *  2. App de sistema con INSTALL_PACKAGES: igual que Device Owner.
 *  3. Root (su): pm install / pm uninstall.
 *  4. ADB local (AdbLocal): el shell de adb del propio equipo, si su adb de red está
 *     encendido (las TV box sin Device Owner suelen traerlo). También silencioso.
 *  5. Interactivo: Android muestra "¿Instalar?" y alguien debe aceptar con el mando.
 */
object Installers {
    fun admin(ctx: Context) = ComponentName(ctx, AdminReceiver::class.java)

    fun isDeviceOwner(ctx: Context): Boolean = try {
        ctx.getSystemService(DevicePolicyManager::class.java).isDeviceOwnerApp(ctx.packageName)
    } catch (e: Exception) {
        false
    }

    fun pick(ctx: Context): Installer = when {
        isDeviceOwner(ctx) -> SessionInstaller(ctx, "owner")
        ctx.checkSelfPermission("android.permission.INSTALL_PACKAGES") == PackageManager.PERMISSION_GRANTED ->
            SessionInstaller(ctx, "system")
        RootShell.available() -> ShellInstaller(ctx, RootShell, "root")
        AdbLocal.available() -> ShellInstaller(ctx, AdbLocal, "adb")
        else -> SessionInstaller(ctx, "interactive")
    }

    /** Al arrancar: sesiones de instalación que quedaron a medias (corte de luz) => fuera. */
    fun abandonOrphanSessions(ctx: Context) {
        try {
            val pi = ctx.packageManager.packageInstaller
            pi.mySessions.forEach { s -> try { pi.abandonSession(s.sessionId) } catch (_: Exception) {} }
        } catch (e: Exception) {
            Log.w(AgentApp.TAG, "abandonOrphanSessions: ${e.message}")
        }
    }
}

/**
 * Instalación con PackageInstaller (API oficial). Con Device Owner o permiso de sistema
 * es SILENCIOSA; si no, Android pide confirmación (STATUS_PENDING_USER_ACTION) y se abre
 * su diálogo para que alguien acepte con el mando.
 */
class SessionInstaller(private val ctx: Context, override val mode: String) : Installer {

    override fun install(apk: File, pkg: String): InstallResult {
        val pi = ctx.packageManager.packageInstaller
        val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL).apply {
            setAppPackageName(pkg)
            setSize(apk.length())
            if (Build.VERSION.SDK_INT >= 31) setRequireUserAction(PackageInstaller.SessionParams.USER_ACTION_NOT_REQUIRED)
        }
        val id = try {
            pi.createSession(params)
        } catch (e: Exception) {
            return InstallResult.Retry("SESSION", e.message ?: "")
        }
        return try {
            pi.openSession(id).use { s ->
                s.openWrite("base.apk", 0, apk.length()).use { out ->
                    apk.inputStream().use { it.copyTo(out, 64 * 1024) }
                    s.fsync(out)
                }
                val q = InstallBus.register(id)
                s.commit(InstallBus.sender(ctx, id))
                InstallBus.await(id, q, if (mode == "interactive") 15 else 5)
            }
        } catch (e: Exception) {
            try { pi.abandonSession(id) } catch (_: Exception) {}
            InstallResult.Retry("SESSION", e.message ?: "")
        }
    }

    override fun uninstall(pkg: String): InstallResult {
        val token = 0x40000000 or (pkg.hashCode() and 0x3fffffff)
        val q = InstallBus.register(token)
        return try {
            ctx.packageManager.packageInstaller.uninstall(pkg, InstallBus.sender(ctx, token))
            InstallBus.await(token, q, if (mode == "interactive") 15 else 5)
        } catch (e: Exception) {
            InstallBus.unregister(token)
            InstallResult.Retry("UNINSTALL", e.message ?: "")
        }
    }
}

/**
 * Instalación con un shell privilegiado (root o adb local): pm install / pm uninstall
 * (argumentos separados, nunca texto libre). Silenciosa.
 * El APK se deja en /data/local/tmp (pm lo lee ahí sin problemas de SELinux). El shell de
 * adb no puede leer los archivos privados del agente: para él se pasa antes por la carpeta
 * externa del agente (/sdcard/Android/data/cloud.smartpizarra.agent/files).
 */
class ShellInstaller(private val ctx: Context, private val sh: PrivShell, override val mode: String) : Installer {

    override fun install(apk: File, pkg: String): InstallResult {
        val tmp = "/data/local/tmp/spagent-${apk.name}"
        var staged: File? = null
        val source = if (sh === RootShell) apk.absolutePath else {
            val dir = ctx.getExternalFilesDir(null) ?: return InstallResult.Retry("NO_EXTERNAL", "sin almacenamiento externo")
            staged = File(dir, apk.name)
            try { apk.copyTo(staged, overwrite = true) } catch (e: Exception) {
                return InstallResult.Retry("STAGE", e.message ?: "")
            }
            staged.absolutePath
        }
        try {
            val cp = sh.run(listOf("cp", source, tmp), 120_000)
            if (cp.code != 0) return InstallResult.Retry("COPY", cp.out.take(200))
            sh.run(listOf("chmod", "644", tmp), 10_000)
            // -t (APK de prueba) solo en la compilación debug, para probar la autoactualización
            val cmd = listOf("pm", "install", "-r", "-g") + (if (BuildConfig.DEBUG) listOf("-t") else emptyList()) + tmp
            return pmResult(sh.run(cmd, 300_000).out)
        } finally {
            sh.run(listOf("rm", "-f", tmp), 10_000)
            staged?.delete()
        }
    }

    override fun uninstall(pkg: String): InstallResult = pmResult(sh.run(listOf("pm", "uninstall", pkg), 120_000).out)

    private fun pmResult(out: String): InstallResult {
        if (out.contains("Success")) return InstallResult.Ok
        val code = Regex("\\[([A-Z_]+)").find(out)?.groupValues?.get(1) ?: "PM_FAILED"
        return when {
            code.contains("INSUFFICIENT_STORAGE") -> InstallResult.Retry("INSUFFICIENT_STORAGE", out.take(200))
            code.contains("VERSION_DOWNGRADE") || code.contains("UPDATE_INCOMPATIBLE") ||
                code.contains("INCONSISTENT_CERTIFICATES") || code.contains("NO_MATCHING_ABIS") ||
                code.contains("OLDER_SDK") || code.contains("INVALID_APK") || code.contains("PARSE") ||
                code.contains("NOT_INSTALLED") || code.contains("DELETE_FAILED_INTERNAL_ERROR") ->
                InstallResult.Fail(code, out.take(200))
            else -> InstallResult.Retry(code, out.take(200))
        }
    }
}

/**
 * Une el commit de PackageInstaller (asíncrono) con el hilo del agente: cada sesión tiene
 * una cola; InstallResultReceiver deja ahí el resultado y await() lo recoge.
 */
object InstallBus {
    private val waiting = ConcurrentHashMap<Int, ArrayBlockingQueue<Intent>>()
    const val EXTRA_TOKEN = "token"

    fun register(token: Int): ArrayBlockingQueue<Intent> = ArrayBlockingQueue<Intent>(1).also { waiting[token] = it }
    fun unregister(token: Int) { waiting.remove(token) }

    fun sender(ctx: Context, token: Int): android.content.IntentSender {
        val i = Intent(ctx, InstallResultReceiver::class.java).putExtra(EXTRA_TOKEN, token)
        var flags = PendingIntent.FLAG_UPDATE_CURRENT
        if (Build.VERSION.SDK_INT >= 31) flags = flags or PendingIntent.FLAG_MUTABLE
        return PendingIntent.getBroadcast(ctx, token, i, flags).intentSender
    }

    fun deliver(token: Int, intent: Intent) {
        waiting.remove(token)?.offer(intent)
    }

    fun await(token: Int, q: ArrayBlockingQueue<Intent>, minutes: Long): InstallResult {
        val r = q.poll(minutes, TimeUnit.MINUTES)
        waiting.remove(token)
        if (r == null) return InstallResult.Retry("TIMEOUT")
        val status = r.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE)
        val msg = r.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE) ?: ""
        return when (status) {
            PackageInstaller.STATUS_SUCCESS -> InstallResult.Ok
            PackageInstaller.STATUS_FAILURE_STORAGE -> InstallResult.Retry("INSUFFICIENT_STORAGE", msg)
            PackageInstaller.STATUS_FAILURE_ABORTED -> InstallResult.Retry("ABORTED", msg)
            PackageInstaller.STATUS_FAILURE_INCOMPATIBLE -> InstallResult.Fail("INCOMPATIBLE", msg)
            PackageInstaller.STATUS_FAILURE_CONFLICT -> InstallResult.Fail("CONFLICT", msg)
            PackageInstaller.STATUS_FAILURE_INVALID -> InstallResult.Fail("INVALID", msg)
            PackageInstaller.STATUS_FAILURE_BLOCKED -> InstallResult.Fail("BLOCKED", msg)
            else -> InstallResult.Retry("FAILURE", msg)
        }
    }
}

/** Resultado de PackageInstaller. Si Android pide confirmación, abre su diálogo. */
class InstallResultReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val token = intent.getIntExtra(InstallBus.EXTRA_TOKEN, -1)
        val status = intent.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE)
        if (status == PackageInstaller.STATUS_PENDING_USER_ACTION) {
            @Suppress("DEPRECATION")
            val confirm = intent.getParcelableExtra<Intent>(Intent.EXTRA_INTENT)
            if (confirm != null) {
                confirm.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                try { context.startActivity(confirm) } catch (e: Exception) { Log.w(AgentApp.TAG, "confirmar: ${e.message}") }
            }
            return
        }
        InstallBus.deliver(token, intent)
    }
}

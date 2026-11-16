// Versión del archivo: 1.0.0 (2026-11-16)
package cloud.smartpizarra.agent

import android.app.ActivityManager
import android.app.admin.DevicePolicyManager
import android.content.Context
import android.os.Build
import android.os.Environment
import android.os.StatFs
import android.os.SystemClock
import android.util.Base64
import org.json.JSONObject
import java.io.File
import java.security.KeyFactory
import java.security.Signature
import java.security.spec.X509EncodedKeySpec

/**
 * COMANDOS REMOTOS. Solo se ejecutan si:
 *  1. el nombre está en la LISTA BLANCA de esta versión del agente (nunca un shell libre);
 *  2. la FIRMA es válida: ECDSA P-256 / SHA-256 sobre "id|target|name|args|exp" con la clave
 *     privada del servidor (private/agent_command_key.pem). La pública va dentro del APK
 *     (keys/command_public.pem => BuildConfig.COMMAND_PUBLIC_KEY). Así, quien solo consiga
 *     escribir en la base de datos no puede ordenar nada a las pantallas;
 *  3. va dirigido a ESTE equipo ("a:<agentId>") o a su grupo ("g:<groupId>");
 *  4. no caducó (exp en segundos unix, comparado con la HORA DEL SERVIDOR).
 * Cada argumento se valida (números en rango, nombres de paquete) antes de usarlo.
 */
object Commands {
    val NAMES = setOf(
        "reboot", "set_resolution", "reset_resolution", "clear_app_data", "force_stop",
        "set_animations", "collect_diagnostics", "restart_main_app",
    )
    private val PKG = Regex("^[a-zA-Z][a-zA-Z0-9_]*(\\.[a-zA-Z0-9_]+)+$")

    /** null si el comando es válido; si no, el código de error que se informa. */
    fun check(c: Manifest.CommandSpec, agentId: String, groupId: Long): String? {
        if (c.name !in NAMES) return "UNKNOWN_COMMAND"
        if (c.target != "a:$agentId" && c.target != "g:$groupId") return "WRONG_TARGET"
        if (c.exp * 1000 < Prefs.serverNow()) return "EXPIRED"
        if (!verify(c)) return "BAD_SIGNATURE"
        return null
    }

    private fun verify(c: Manifest.CommandSpec): Boolean = try {
        val key = KeyFactory.getInstance("EC").generatePublic(
            X509EncodedKeySpec(Base64.decode(BuildConfig.COMMAND_PUBLIC_KEY, Base64.DEFAULT))
        )
        val s = Signature.getInstance("SHA256withECDSA")
        s.initVerify(key)
        s.update("${c.id}|${c.target}|${c.name}|${c.args}|${c.exp}".toByteArray())
        s.verify(Base64.decode(c.sig, Base64.DEFAULT))
    } catch (e: Exception) {
        false
    }

    /** Resultado: ok, código y salida (recortada a 4 KB). rebootAfter = reiniciar tras informar. */
    data class Out(val ok: Boolean, val code: String, val output: String, val rebootAfter: Boolean = false)

    fun execute(ctx: Context, c: Manifest.CommandSpec): Out {
        val a = try { JSONObject(c.args) } catch (e: Exception) { JSONObject() }
        val owner = Installers.isDeviceOwner(ctx)
        val dpm = ctx.getSystemService(DevicePolicyManager::class.java)
        return when (c.name) {
            "reboot" ->
                if (owner || Priv.shell() != null) Out(true, "OK", "reinicio tras informar", rebootAfter = true)
                else Out(false, "UNSUPPORTED", "sin root, adb local ni Device Owner")

            "set_resolution" -> {
                val w = a.optInt("w"); val h = a.optInt("h"); val d = a.optInt("density", 0)
                if (w !in 320..3840 || h !in 240..2160 || (d != 0 && d !in 80..640)) Out(false, "BAD_ARGS", "")
                else root(listOf("wm", "size", "${w}x$h")).let { r ->
                    if (d != 0 && r.ok) root(listOf("wm", "density", "$d")) else r
                }
            }

            "reset_resolution" -> root(listOf("wm", "size", "reset")).let { r ->
                if (r.ok) root(listOf("wm", "density", "reset")) else r
            }

            "clear_app_data" -> {
                val p = a.optString("package")
                when {
                    !PKG.matches(p) || p == ctx.packageName -> Out(false, "BAD_ARGS", "")
                    Priv.shell() != null -> root(listOf("pm", "clear", p))
                    owner && Build.VERSION.SDK_INT >= 28 -> {
                        dpm.clearApplicationUserData(Installers.admin(ctx), p, ctx.mainExecutor) { _, ok -> }
                        Out(true, "OK", "solicitado")
                    }
                    else -> Out(false, "UNSUPPORTED", "")
                }
            }

            "force_stop" -> {
                val p = a.optString("package")
                if (!PKG.matches(p) || p == ctx.packageName) Out(false, "BAD_ARGS", "") else root(listOf("am", "force-stop", p))
            }

            "set_animations" -> {
                val s = a.optDouble("scale", -1.0)
                if (s != 0.0 && s != 0.5 && s != 1.0) Out(false, "BAD_ARGS", "")
                else {
                    var r = Out(true, "OK", "")
                    for (k in listOf("window_animation_scale", "transition_animation_scale", "animator_duration_scale")) {
                        r = root(listOf("settings", "put", "global", k, s.toString()))
                        if (!r.ok) break
                    }
                    r
                }
            }

            "collect_diagnostics" -> Out(true, "OK", diagnostics(ctx).toString())

            "restart_main_app" -> {
                val main = Manifest.cached()?.mainPackage
                if (main != null && Priv.shell() != null) root(listOf("am", "force-stop", main))
                MainApp.requestLaunch(ctx, immediate = true)
                Out(true, "OK", "")
            }

            else -> Out(false, "UNKNOWN_COMMAND", "")
        }
    }

    /** Ejecuta con root o, si no hay, con el shell de adb local (Priv). */
    private fun root(args: List<String>): Out {
        val sh = Priv.shell() ?: return Out(false, "UNSUPPORTED", "sin root ni adb local")
        val r = sh.run(args, 30_000)
        return Out(r.code == 0, if (r.code == 0) "OK" else "EXIT_${r.code}", r.out.take(4096))
    }

    /** Reinicia el equipo (después de informar el resultado al servidor). */
    fun reboot(ctx: Context) {
        if (Installers.isDeviceOwner(ctx)) {
            try {
                ctx.getSystemService(DevicePolicyManager::class.java).reboot(Installers.admin(ctx)); return
            } catch (_: Exception) {}
        }
        if (RootShell.available()) RootShell.run(listOf("reboot"), 10_000)
        else AdbLocal.run(listOf("svc", "power", "reboot"), 10_000)
    }

    /** Estado del equipo (también va resumido en cada heartbeat). */
    fun diagnostics(ctx: Context): JSONObject {
        val am = ctx.getSystemService(ActivityManager::class.java)
        val mi = ActivityManager.MemoryInfo().also { am.getMemoryInfo(it) }
        return JSONObject()
            .put("uptime", SystemClock.elapsedRealtime() / 1000)
            .put("memTotalMb", mi.totalMem / 1048576)
            .put("memAvailMb", mi.availMem / 1048576)
            .put("lowMemory", mi.lowMemory)
            .put("freeMb", freeMb())
            .put("tempC", temperature())
            .put("model", Build.MODEL).put("maker", Build.MANUFACTURER).put("sdk", Build.VERSION.SDK_INT)
            .put("abi", Build.SUPPORTED_ABIS.firstOrNull() ?: "")
            .put("restarts", Prefs.restarts)
            .put("lastError", Prefs.lastError)
    }

    fun freeMb(): Long = try {
        StatFs(Environment.getDataDirectory().path).availableBytes / 1048576
    } catch (e: Exception) { -1 }

    /** Temperatura del procesador si el equipo la expone (°C) o -1. */
    fun temperature(): Int {
        try {
            File("/sys/class/thermal/thermal_zone0/temp").takeIf { it.canRead() }?.readText()?.trim()?.toIntOrNull()?.let {
                return if (it > 1000) it / 1000 else it
            }
        } catch (_: Exception) {}
        try {
            // HiSilicon: "Tsensor: temperature = 66 degree"
            File("/proc/msp/pm_cpu").takeIf { it.canRead() }?.readText()?.let { t ->
                Regex("temperature\\s*=\\s*(\\d+)").find(t)?.groupValues?.get(1)?.toIntOrNull()?.let { return it }
            }
        } catch (_: Exception) {}
        return -1
    }
}

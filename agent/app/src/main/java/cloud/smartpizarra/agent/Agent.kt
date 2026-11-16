// Versión del archivo: 1.0.0 (2026-11-16)
package cloud.smartpizarra.agent

import android.content.Context
import android.content.pm.ApplicationInfo
import android.content.pm.PackageManager
import android.net.ConnectivityManager
import android.net.Network
import android.os.Build
import android.os.SystemClock
import android.util.Log
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.io.IOException
import java.security.MessageDigest
import java.util.Calendar
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.TimeUnit
import kotlin.random.Random

/**
 * MOTOR DEL AGENTE: un solo hilo de baja prioridad que repite el ciclo
 *
 *   heartbeat ─► ¿ETag distinto? ─► manifiesto ─► planificar ─► tareas ─► informar ─► dormir
 *
 *  - HEARTBEAT = POLLING: una petición pequeña cada "next" segundos (lo decide el servidor,
 *    300 por defecto) con ±10 % de azar para que miles de pantallas no consulten a la vez.
 *  - El manifiesto solo se descarga si cambió su ETag (casi nunca).
 *  - Sin red o con error del servidor: BACKOFF 10 s, 20 s, 40 s... hasta 30 min (o el
 *    Retry-After que diga el servidor). Al volver la red, reintenta en 0-20 s.
 *  - Sin servidor sigue funcionando con el ÚLTIMO manifiesto válido (lanza la app principal).
 *  - Descargas con la COLA DE TURNOS del servidor (como mirror.html): pide turno, descarga
 *    todo lo que falta y lo libera; si no hay turno espera lo que diga el servidor.
 */
object Agent {
    private lateinit var ctx: Context
    private lateinit var store: TaskStore
    private val exec = Executors.newSingleThreadScheduledExecutor { r ->
        Thread(r, "agent").apply { priority = Thread.MIN_PRIORITY + 1 }
    }
    private var pending: ScheduledFuture<*>? = null
    @Volatile private var running = false
    @Volatile private var kickRequested = false
    @Volatile var started = false
        private set
    @Volatile var lastCycleAt = 0L
        private set
    private var failures = 0
    private var nextPollSec = 300

    @Synchronized
    fun start(c: Context) {
        if (started) return
        started = true
        ctx = c.applicationContext
        store = TaskStore(ctx)
        exec.execute { Installers.abandonOrphanSessions(ctx) }
        watchNetwork()
        // Con manifiesto guardado no hay prisa (la app principal ya se lanza con él): 0-20 s
        // al azar para que un corte de luz en un local no dispare todas las consultas a la vez.
        schedule(if (Prefs.manifestJson == null) 0 else Random.nextLong(2_000, 20_000))
    }

    /** Sincronizar cuanto antes (adb, menú, vuelta de la red). */
    fun kick(delayMs: Long = 0) {
        if (!started) return
        if (running) kickRequested = true else schedule(delayMs)
    }

    @Synchronized
    private fun schedule(ms: Long) {
        pending?.cancel(false)
        pending = exec.schedule({ runCycle() }, ms, TimeUnit.MILLISECONDS)
    }

    private fun runCycle() {
        running = true
        val wait = try {
            cycle().also {
                failures = 0
                Prefs.lastError = ""
                Prefs.lastSyncAt = System.currentTimeMillis()
            }
        } catch (e: Throwable) {
            failures++
            Prefs.lastError = (e.message ?: e.javaClass.simpleName).take(200)
            Log.w(AgentApp.TAG, "ciclo: ${e.message}")
            backoff(e)
        } finally {
            UiState.end()
        }
        lastCycleAt = SystemClock.elapsedRealtime()
        running = false
        if (kickRequested) {
            kickRequested = false
            schedule(1_000)
        } else schedule(wait)
    }

    private fun jitter(ms: Long) = (ms * Random.nextDouble(0.9, 1.1)).toLong()

    private fun backoff(e: Throwable): Long {
        if (e is ApiException && e.retryAfter != null) return jitter(e.retryAfter.coerceIn(5, 3600) * 1000)
        val sec = (10L shl (failures - 1).coerceIn(0, 8)).coerceAtMost(1800)
        return jitter(sec * 1000)
    }

    /* ------------------------------ CICLO ------------------------------ */

    private fun cycle(): Long {
        Identity.ensure()
        val installer = Installers.pick(ctx)
        UiState.mode = installer.mode
        val inv = Inventory.collect(ctx)
        val cached = Manifest.cached()
        val hb = Api.post(
            "heartbeat", JSONObject()
                .put("agent", BuildConfig.VERSION_NAME).put("agentCode", BuildConfig.VERSION_CODE)
                .put("model", Build.MODEL).put("maker", Build.MANUFACTURER).put("sdk", Build.VERSION.SDK_INT)
                .put("abi", Build.SUPPORTED_ABIS.firstOrNull() ?: "")
                .put("privilege", installer.mode)
                .put("etag", Prefs.etag ?: "")
                .put("inv", inv.hash)
                .put("state", UiState.stateName)
                .put("uptime", SystemClock.elapsedRealtime() / 1000)
                .put("freeMb", Commands.freeMb())
                .put("temp", Commands.temperature())
                .put("mainVersion", cached?.mainPackage?.let { MainApp.installedVersion(ctx, it) } ?: -1)
                .put("restarts", Prefs.restarts)
                .put("lastError", Prefs.lastError)
        )
        hb.optLong("serverTime", 0).takeIf { it > 0 }?.let { Prefs.serverOffsetMs = it * 1000 - System.currentTimeMillis() }
        Prefs.status = hb.optString("status", Prefs.status)
        nextPollSec = hb.optInt("next", 300).coerceIn(30, 3600)

        val etag = hb.optString("etag")
        if (Prefs.manifestJson == null || etag != Prefs.etag) {
            Api.manifest(if (Prefs.manifestJson != null) Prefs.etag else null)?.let { (tag, json) ->
                if (Manifest.parse(json) != null) {
                    Prefs.manifestJson = json
                    Prefs.etag = tag
                    UiState.notifyChanged()
                }
            }
        }
        if (hb.optBoolean("inventoryWanted")) Api.post("inventory", JSONObject().put("hash", inv.hash).put("packages", inv.json))

        val m = Manifest.cached() ?: return jitter(nextPollSec * 1000L)
        updateLogo(m)
        report()
        if (m.status == "authorized") work(m, installer)
        report()
        store.prune()

        // Siguiente consulta: la del servidor, o antes si hay una tarea esperando su reintento
        var wait = jitter(nextPollSec * 1000L)
        nextRetryAt(m)?.let { wait = minOf(wait, (it - System.currentTimeMillis()).coerceAtLeast(5_000)) }
        return wait
    }

    /* --------------------------- PLANIFICAR ---------------------------- */

    private class Step(val task: TaskStore.Task, val kind: String, val command: Manifest.CommandSpec? = null)

    /**
     * Diferencia entre el estado deseado y lo instalado => pasos a ejecutar ahora.
     * Orden: desinstalaciones, comandos, instalaciones; el PROPIO agente siempre el último
     * (al actualizarse, Android mata este proceso y MY_PACKAGE_REPLACED lo vuelve a abrir).
     */
    private fun plan(m: Manifest): List<Step> {
        val now = System.currentTimeMillis()
        val (agentId, _) = Identity.ensure()
        val out = ArrayList<Step>()
        fun due(t: TaskStore.Task) = t.nextTryAt <= now

        for (r in m.uninstall) {
            store.ensure(r.taskId, "uninstall", r.pkg)
            val t = store.get(r.taskId) ?: continue
            val installed = MainApp.installedVersion(ctx, r.pkg) >= 0
            when {
                t.state == TaskStore.DONE || t.state == TaskStore.FAILED -> {}
                !installed -> store.setState(t.id, TaskStore.DONE, "NOT_INSTALLED")
                r.pkg == ctx.packageName -> store.setState(t.id, TaskStore.FAILED, "SELF")
                t.state == TaskStore.INSTALLING -> store.retryLater(t.id, "INTERRUPTED", "")
                due(t) -> out += Step(t, "uninstall")
            }
        }
        for (c in m.commands) {
            if (store.get(c.taskId) == null) {
                store.ensure(c.taskId, "command", c.name)
                Commands.check(c, agentId, m.groupId)?.let { err -> store.setState(c.taskId, TaskStore.FAILED, err) }
            }
            val t = store.get(c.taskId) ?: continue
            when (t.state) {
                // Un comando cortado a medias (p. ej. un reinicio) NO se repite: evita bucles
                TaskStore.INSTALLING -> store.setState(t.id, TaskStore.DONE, "INTERRUPTED")
                TaskStore.PENDING -> if (due(t)) out += Step(t, "command", c)
            }
        }
        val installs = ArrayList<Step>()
        for (a in m.apps) {
            val have = MainApp.installedVersion(ctx, a.pkg)
            val existing = store.get(a.taskId)
            if (have >= a.versionCode) {
                if (existing == null) {
                    store.ensure(a.taskId, "install", a.pkg, a.versionCode, a.versionId, a.sha256, a.size)
                    store.setState(a.taskId, TaskStore.DONE, "ALREADY")
                } else if (existing.state != TaskStore.DONE && existing.state != TaskStore.FAILED) {
                    store.setState(a.taskId, TaskStore.DONE, "OK")
                    apkFile(a.sha256).delete()
                }
                continue
            }
            store.ensure(a.taskId, "install", a.pkg, a.versionCode, a.versionId, a.sha256, a.size)
            val t = store.get(a.taskId) ?: continue
            when (t.state) {
                TaskStore.FAILED, TaskStore.DONE -> {}
                TaskStore.INSTALLING -> store.retryLater(t.id, "INTERRUPTED", "")
                else -> if (due(t)) installs += Step(t, "install")
            }
        }
        // Ventana horaria: las ACTUALIZACIONES (el paquete ya existe) esperan a la franja;
        // las primeras instalaciones no esperan.
        val inWindow = inInstallWindow(m.params.installWindow)
        installs.removeAll { !inWindow && MainApp.installedVersion(ctx, it.task.pkg) >= 0 }
        installs.sortBy { if (it.task.pkg == ctx.packageName) 1 else 0 }
        out += installs
        return out
    }

    private fun inInstallWindow(w: String): Boolean {
        val mm = Regex("^(\\d{1,2}):(\\d{2})-(\\d{1,2}):(\\d{2})$").find(w.trim()) ?: return true
        val (h1, m1, h2, m2) = mm.destructured
        val start = h1.toInt() * 60 + m1.toInt()
        val end = h2.toInt() * 60 + m2.toInt()
        val c = Calendar.getInstance()
        val now = c.get(Calendar.HOUR_OF_DAY) * 60 + c.get(Calendar.MINUTE)
        return if (start <= end) now in start until end else now >= start || now < end
    }

    private fun nextRetryAt(m: Manifest): Long? {
        val ids = m.apps.map { it.taskId } + m.uninstall.map { it.taskId }
        return ids.mapNotNull { store.get(it) }
            .filter { it.state == TaskStore.PENDING && it.nextTryAt > System.currentTimeMillis() }
            .minOfOrNull { it.nextTryAt }
    }

    /* ----------------------------- EJECUTAR ----------------------------- */

    private fun work(m: Manifest, installer: Installer) {
        val steps = plan(m)
        if (steps.isEmpty()) return
        val toDownload = steps.filter {
            it.kind == "install" && (it.task.state != TaskStore.DOWNLOADED || !apkFile(it.task.sha256).exists())
        }
        val mainMissing = m.mainPackage != null && MainApp.installedVersion(ctx, m.mainPackage) < 0
        UiState.begin(m.params, steps.size, toDownload.sumOf { it.task.size }, show = m.params.foregroundDownloads || mainMissing)
        if (UiState.screen) MaintenanceActivity.open(ctx)

        if (toDownload.isNotEmpty()) downloadAll(toDownload)

        val ready = steps.filter { it.kind != "install" || store.get(it.task.id)?.state == TaskStore.DOWNLOADED }
        if (ready.isEmpty()) return
        UiState.installing()
        // La pantalla solo para instalar o desinstalar (un comando de diagnóstico no la muestra)
        if (!UiState.screen && ready.any { it.kind != "command" }) { UiState.screen = true; MaintenanceActivity.open(ctx) }
        for (s in ready) {
            if (s.kind == "install" && s.task.pkg == ctx.packageName) report() // antes de que Android nos reinicie
            store.setState(s.task.id, TaskStore.INSTALLING)
            when (s.kind) {
                "uninstall" -> apply(s.task, installer.uninstall(s.task.pkg))
                "command" -> {
                    val r = Commands.execute(ctx, s.command!!)
                    store.setState(s.task.id, if (r.ok) TaskStore.DONE else TaskStore.FAILED, r.code, r.output)
                    if (r.rebootAfter) {
                        report()
                        Commands.reboot(ctx)
                    }
                }
                "install" -> {
                    val f = apkFile(s.task.sha256)
                    val r = installer.install(f, s.task.pkg)
                    val result = if (r is InstallResult.Ok && MainApp.installedVersion(ctx, s.task.pkg) < s.task.versionCode)
                        InstallResult.Retry("VERSION_NOT_UPDATED") else r
                    apply(s.task, result)
                    if (result !is InstallResult.Retry) f.delete()
                }
            }
            UiState.stepDone()
        }
    }

    private fun apply(t: TaskStore.Task, r: InstallResult) {
        when (r) {
            is InstallResult.Ok -> store.setState(t.id, TaskStore.DONE, "OK")
            is InstallResult.Retry -> store.retryLater(t.id, r.code, r.detail)
            is InstallResult.Fail -> store.setState(t.id, TaskStore.FAILED, r.code, r.detail)
        }
    }

    private fun apkDir() = File(ctx.filesDir, "apk").apply { mkdirs() }
    private fun apkFile(sha: String) = File(apkDir(), "$sha.apk")

    /** Pide turno en la cola del servidor, descarga todo lo que falta y libera el turno. */
    private fun downloadAll(list: List<Step>) {
        UiState.stateName = "waiting_turn"
        while (true) {
            val r = Api.post("turn", JSONObject())
            if (r.optBoolean("granted")) break
            UiState.waitingTurn(r.optInt("position"))
            Thread.sleep(r.optLong("retryAfter", 30).coerceIn(5, 600) * 1000)
        }
        try {
            for (s in list) if (!downloadOne(s.task)) break
        } finally {
            try { Api.post("done", JSONObject()) } catch (_: Exception) {}
        }
    }

    /** @return false si hay que dejar las demás descargas para más tarde (turno perdido). */
    private fun downloadOne(t: TaskStore.Task): Boolean {
        val file = apkFile(t.sha256)
        if (file.exists() && file.length() == t.size) {
            store.setState(t.id, TaskStore.DOWNLOADED); return true
        }
        val part = File(file.path + ".part")
        // Espacio: el APK + la copia que hace Android al instalar + margen
        val need = (t.size * 2.5).toLong()
        if (Commands.freeMb() * 1048576 < need) {
            apkDir().listFiles()?.forEach { if (it.name != part.name) it.delete() }
            if (Commands.freeMb() * 1048576 < need) {
                store.retryLater(t.id, "INSUFFICIENT_STORAGE", "libres ${Commands.freeMb()} MB"); return true
            }
        }
        UiState.stateName = "downloading"
        val base = UiState.bytesDone
        return try {
            val sha = Api.download(Api.fileUrl(t.versionId), part) { have -> UiState.downloaded(base, have) }
            if (part.length() != t.size || sha != t.sha256) {
                part.delete()
                store.retryLater(t.id, "HASH_MISMATCH", "size=${part.length()}")
            } else {
                part.renameTo(file)
                store.setState(t.id, TaskStore.DOWNLOADED)
            }
            UiState.downloaded(base, t.size)
            true
        } catch (e: ApiException) {
            store.retryLater(t.id, "HTTP_${e.code}", e.error)
            e.code != 503
        } catch (e: IOException) {
            // Se conserva el .part: la próxima vez sigue donde iba (Range)
            store.retryLater(t.id, "DOWNLOAD", e.message ?: "")
            false
        }
    }

    /* ----------------------------- INFORMAR ----------------------------- */

    /** Envía al servidor los resultados finales pendientes (hasta 50 por llamada). */
    private fun report() {
        repeat(10) {
            val list = store.unreported()
            if (list.isEmpty()) return
            val arr = JSONArray()
            list.forEach { t ->
                arr.put(
                    JSONObject().put("taskId", t.id).put("type", t.type).put("package", t.pkg)
                        .put("versionCode", t.versionCode).put("status", if (t.state == TaskStore.DONE) "done" else "failed")
                        .put("code", t.code).put("detail", t.detail.take(4096)).put("attempts", t.attempts)
                )
            }
            try {
                Api.post("results", JSONObject().put("results", arr))
                store.markReported(list.map { it.id })
            } catch (e: Exception) {
                Log.w(AgentApp.TAG, "results: ${e.message}")
                return
            }
        }
    }

    /** Logo de la pantalla de mantenimiento: se descarga solo si cambió su hash. */
    private fun updateLogo(m: Manifest) {
        val sha = m.params.logoSha256
        val f = File(ctx.filesDir, "logo.img")
        if (sha.isEmpty()) {
            if (f.exists()) f.delete(); Prefs.logoSha = null; return
        }
        if (sha == Prefs.logoSha && f.exists()) return
        try {
            val bytes = Api.fetchBytes("logo", "&h=$sha")
            if (MessageDigest.getInstance("SHA-256").digest(bytes).toHex() == sha) {
                f.writeBytes(bytes); Prefs.logoSha = sha
            }
        } catch (e: Exception) {
            Log.w(AgentApp.TAG, "logo: ${e.message}")
        }
    }

    /** Al volver la red, sincronizar pronto (0-20 s al azar) si el último ciclo falló. */
    private fun watchNetwork() {
        try {
            ctx.getSystemService(ConnectivityManager::class.java).registerDefaultNetworkCallback(
                object : ConnectivityManager.NetworkCallback() {
                    override fun onAvailable(network: Network) {
                        if (failures > 0) kick(Random.nextLong(0, 20_000))
                    }
                })
        } catch (e: Exception) {
            Log.w(AgentApp.TAG, "red: ${e.message}")
        }
    }
}

/** Paquetes instalados por el usuario o actualizados (no los de sistema intactos) + su hash. */
object Inventory {
    class Inv(val hash: String, val json: JSONArray)

    fun collect(ctx: Context): Inv {
        val pm = ctx.packageManager
        @Suppress("DEPRECATION")
        val list = pm.getInstalledPackages(0)
            .filter { p ->
                val f = p.applicationInfo?.flags ?: 0
                (f and ApplicationInfo.FLAG_SYSTEM) == 0 || (f and ApplicationInfo.FLAG_UPDATED_SYSTEM_APP) != 0
            }
            .map { p -> p.packageName to MainApp.versionOf(p) }
            .sortedBy { it.first }
        val text = list.joinToString("\n") { "${it.first}:${it.second}" }
        val hash = MessageDigest.getInstance("SHA-1").digest(text.toByteArray()).toHex().take(16)
        val arr = JSONArray()
        list.forEach { arr.put(JSONObject().put("p", it.first).put("v", it.second)) }
        return Inv(hash, arr)
    }
}

/** Utilidades de la app principal: versión instalada e intención para abrirla. */
object MainApp {
    @Suppress("DEPRECATION")
    fun versionOf(p: android.content.pm.PackageInfo): Long =
        if (Build.VERSION.SDK_INT >= 28) p.longVersionCode else p.versionCode.toLong()

    /** versionCode instalado o -1 si no está. */
    fun installedVersion(ctx: Context, pkg: String): Long = try {
        versionOf(ctx.packageManager.getPackageInfo(pkg, 0))
    } catch (e: PackageManager.NameNotFoundException) {
        -1
    }

    fun launchIntent(ctx: Context, m: Manifest?): android.content.Intent? {
        val pkg = m?.mainPackage ?: return null
        if (installedVersion(ctx, pkg) < 0) return null
        val i = if (m.mainActivity != null) {
            android.content.Intent(android.content.Intent.ACTION_MAIN).setClassName(pkg, m.mainActivity)
        } else {
            ctx.packageManager.getLeanbackLaunchIntentForPackage(pkg) ?: ctx.packageManager.getLaunchIntentForPackage(pkg)
        }
        return i?.addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK or android.content.Intent.FLAG_ACTIVITY_RESET_TASK_IF_NEEDED)
    }

    /** Android 10+ prohíbe abrir pantallas desde segundo plano salvo Device Owner o permiso de superposición. */
    fun canStartFromBackground(ctx: Context): Boolean =
        Build.VERSION.SDK_INT < 29 || Installers.isDeviceOwner(ctx) || android.provider.Settings.canDrawOverlays(ctx)

    /** Pide al launcher (HomeActivity) que abra la app principal. */
    fun requestLaunch(ctx: Context, immediate: Boolean) {
        val i = android.content.Intent(ctx, HomeActivity::class.java)
            .addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK)
            .putExtra(HomeActivity.EXTRA_LAUNCH_NOW, immediate)
        if (HomeActivity.visible || canStartFromBackground(ctx)) {
            try { ctx.startActivity(i) } catch (e: Exception) { Log.w(AgentApp.TAG, "requestLaunch: ${e.message}") }
        }
    }
}

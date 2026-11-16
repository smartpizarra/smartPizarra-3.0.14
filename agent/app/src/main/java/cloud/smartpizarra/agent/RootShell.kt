// Versión del archivo: 1.0.0 (2026-11-16)
package cloud.smartpizarra.agent

import java.util.concurrent.TimeUnit

/**
 * Root, si el firmware lo permite a las apps. Dos formas de su:
 *   "su -c <cmd>"        (Magisk, SuperSU y la mayoría)
 *   "su 0 sh -c <cmd>"   (su de AOSP userdebug, como el de la caja HiSilicon .137;
 *                         ojo: ese su solo acepta al shell de adb, no a las apps)
 * Se detecta una vez. Los argumentos se pasan entre comillas simples escapadas: nunca se
 * concatena texto del servidor sin validar (Commands.kt valida cada argumento antes).
 */
/** Shell con privilegios: root (su) o el shell de adb local (AdbLocal). */
interface PrivShell {
    fun available(): Boolean
    fun run(args: List<String>, timeoutMs: Long): RootShell.Res
}

/** El mejor shell privilegiado disponible: root primero, si no adb local; null si ninguno. */
object Priv {
    fun shell(): PrivShell? = when {
        RootShell.available() -> RootShell
        AdbLocal.available() -> AdbLocal
        else -> null
    }
}

object RootShell : PrivShell {
    data class Res(val code: Int, val out: String)

    @Volatile private var form = -1 // -1 sin probar, 0 sin root, 1 "su -c", 2 "su 0 sh -c"

    override fun available(): Boolean {
        if (form < 0) form = when {
            exec(listOf("su", "-c", "id"), 8_000).out.contains("uid=0") -> 1
            exec(listOf("su", "0", "sh", "-c", "id"), 8_000).out.contains("uid=0") -> 2
            else -> 0
        }
        return form > 0
    }

    private fun quote(a: String) = "'" + a.replace("'", "'\\''") + "'"

    /** Ejecuta un comando como root. code = -1 si no hay root o no terminó a tiempo. */
    override fun run(args: List<String>, timeoutMs: Long): Res {
        if (!available()) return Res(-1, "no root")
        val line = args.joinToString(" ") { quote(it) }
        val cmd = if (form == 1) listOf("su", "-c", line) else listOf("su", "0", "sh", "-c", line)
        return exec(cmd, timeoutMs)
    }

    /** Lanza el proceso, lee su salida y lo mata si se pasa del tiempo (su puede quedarse esperando). */
    private fun exec(cmd: List<String>, timeoutMs: Long): Res = try {
        val p = ProcessBuilder(cmd).redirectErrorStream(true).start()
        val killer = Thread {
            try { Thread.sleep(timeoutMs); p.destroy() } catch (_: InterruptedException) {}
        }.apply { isDaemon = true; start() }
        val out = p.inputStream.bufferedReader().use { it.readText() }
        val code = if (android.os.Build.VERSION.SDK_INT >= 26) {
            if (p.waitFor(1, TimeUnit.SECONDS)) p.exitValue() else -1
        } else p.waitFor()
        killer.interrupt()
        Res(code, out.trim())
    } catch (e: Exception) {
        Res(-1, e.message ?: "error")
    }
}

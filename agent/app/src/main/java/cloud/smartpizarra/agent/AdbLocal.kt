// Versión del archivo: 1.0.1 (2026-11-17)
package cloud.smartpizarra.agent

import android.util.Base64
import android.util.Log
import java.io.DataInputStream
import java.io.File
import java.io.OutputStream
import java.math.BigInteger
import java.net.InetSocketAddress
import java.net.Socket
import java.nio.ByteBuffer
import java.nio.ByteOrder
import java.security.KeyFactory
import java.security.KeyPairGenerator
import java.security.PrivateKey
import java.security.Signature
import java.security.interfaces.RSAPublicKey
import java.security.spec.PKCS8EncodedKeySpec
import java.security.spec.X509EncodedKeySpec

/**
 * ADB LOCAL: muchas TV box traen el adb de red encendido (puerto 5555, 3222...) y sin
 * Device Owner posible (les falta la función android.software.device_admin). El agente
 * se conecta a SU PROPIO adbd por 127.0.0.1 con el protocolo de adb y ejecuta comandos
 * con los permisos del shell (uid 2000): "pm install" silencioso, "pm uninstall", "wm",
 * "settings"... Es la misma técnica que usan Shizuku o LADB. No modifica /system.
 *
 *  - adbd sin autenticación (ro.adb.secure vacío, como la HiSilicon .137): conecta directo.
 *  - adbd con autenticación (.201): firma el reto con la clave RSA del agente; la PRIMERA
 *    vez Android muestra "¿Permitir depuración?" en la TV y alguien debe aceptar con el
 *    mando marcando "Permitir siempre". Después ya no pregunta.
 *  - Si adbd no está encendido: este modo no está disponible (se usa otro instalador).
 * Solo conecta a 127.0.0.1: nunca a otro equipo.
 */
object AdbLocal : PrivShell {
    private const val CNXN = 0x4e584e43
    private const val AUTH = 0x48545541
    private const val OPEN = 0x4e45504f
    private const val OKAY = 0x59414b4f
    private const val CLSE = 0x45534c43
    private const val WRTE = 0x45545257
    private const val VERSION = 0x01000000
    private const val MAX_DATA = 256 * 1024

    /** Prefijo DigestInfo de SHA-1: adbd trata el reto de 20 bytes como un hash SHA-1. */
    private val SHA1_PREFIX = byteArrayOf(
        0x30, 0x21, 0x30, 0x09, 0x06, 0x05, 0x2b, 0x0e, 0x03, 0x02, 0x1a, 0x05, 0x00, 0x04, 0x14,
    )

    @Volatile private var port = -1   // -1 sin probar, 0 no disponible
    @Volatile private var checkedAt = 0L
    /** En la última prueba se envió la clave pública: la TV muestra "¿Permitir depuración?". */
    @Volatile private var keyOffered = false
    /** Ya se intentó fijar el puerto (persist.adb.tcp.port) en este proceso. */
    @Volatile private var persistTried = false

    private class Msg(val cmd: Int, val arg0: Int, val arg1: Int, val data: ByteArray)

    /**
     * ¿Hay adbd local utilizable? Si no lo había se vuelve a probar cada 10 min, o al
     * minuto si la última vez se mostró en la TV el aviso de depuración (alguien puede
     * aceptarlo justo después: 2026-11-17 en la .201 se aceptó y el agente siguió 10 min
     * en modo interactivo).
     */
    override fun available(): Boolean {
        val now = System.currentTimeMillis()
        if (port > 0) return true
        val wait = if (keyOffered) 60_000 else 10 * 60_000
        if (port == 0 && now - checkedAt < wait) return false
        checkedAt = now
        keyOffered = false
        port = 0
        for (p in candidatePorts()) {
            val r = exec(p, "echo ok", 20_000)
            if (r.code == 0 && r.out.trim() == "ok") { port = p; break }
        }
        Log.i(AgentApp.TAG, "adb local: " + if (port > 0) "puerto $port" else "no disponible")
        if (port > 0) persistPort(port)
        return port > 0
    }

    /**
     * Muchas box abren el adb de red solo hasta el siguiente reinicio (service.adb.tcp.port,
     * p. ej. la .201): al reiniciar, el agente pierde la instalación silenciosa. Si el
     * equipo tiene "su" usable por el shell (compilaciones userdebug/eng), se fija
     * persist.adb.tcp.port para que adbd vuelva a escuchar en ese puerto tras reiniciar.
     * adb sigue exigiendo una clave autorizada (ro.adb.secure). Una vez por proceso.
     */
    private fun persistPort(p: Int) {
        if (persistTried) return
        persistTried = true
        val cur = exec(p, "getprop persist.adb.tcp.port", 10_000)
        if (cur.code != 0 || cur.out.trim().toIntOrNull() == p) return
        val r = exec(p, "su 0 setprop persist.adb.tcp.port $p && getprop persist.adb.tcp.port", 10_000)
        Log.i(AgentApp.TAG, "adb local: fijar puerto $p => " + if (r.code == 0 && r.out.trim() == "$p") "ok" else "no (${r.out.take(80)})")
    }

    private fun candidatePorts(): List<Int> {
        val props = listOf("service.adb.tcp.port", "persist.adb.tcp.port").mapNotNull { prop ->
            try {
                ProcessBuilder("getprop", prop).start().inputStream.bufferedReader().use { it.readText() }.trim().toIntOrNull()
            } catch (e: Exception) { null }
        }
        return (props + 5555).filter { it in 1..65535 }.distinct()
    }

    override fun run(args: List<String>, timeoutMs: Long): RootShell.Res {
        if (!available()) return RootShell.Res(-1, "sin adb local")
        val r = exec(port, args.joinToString(" ") { "'" + it.replace("'", "'\\''") + "'" }, timeoutMs)
        // adbd desapareció (reinicio sin puerto fijo, depuración desactivada...): olvidar el
        // puerto para que la próxima tarea vuelva a probar y, si no hay, use otro instalador
        if (r.code == -1 && r.out.startsWith("adb:")) { port = -1; checkedAt = 0L }
        return r
    }

    /** Ejecuta una línea de shell y devuelve su salida y su código (añade "echo $?"). */
    private fun exec(p: Int, line: String, timeoutMs: Long): RootShell.Res = try {
        Socket().use { s ->
            s.connect(InetSocketAddress("127.0.0.1", p), 3_000)
            s.soTimeout = timeoutMs.coerceAtMost(Int.MAX_VALUE.toLong()).toInt()
            val inp = DataInputStream(s.getInputStream().buffered())
            val out = s.getOutputStream()
            if (!handshake(inp, out)) return RootShell.Res(-1, "adb: conexión rechazada")
            send(out, OPEN, 1, 0, "shell:$line; echo __SP_EXIT__\$?\u0000".toByteArray())
            val buf = StringBuilder()
            var remote = 0
            loop@ while (true) {
                val m = read(inp)
                when (m.cmd) {
                    OKAY -> remote = m.arg0
                    WRTE -> { buf.append(String(m.data)); send(out, OKAY, 1, m.arg0, ByteArray(0)) }
                    CLSE -> { send(out, CLSE, 1, remote, ByteArray(0)); break@loop }
                }
            }
            val text = buf.toString()
            val code = Regex("__SP_EXIT__(\\d+)").find(text)?.groupValues?.get(1)?.toIntOrNull() ?: -1
            RootShell.Res(code, text.substringBefore("__SP_EXIT__").trim())
        }
    } catch (e: Exception) {
        RootShell.Res(-1, "adb: ${e.message}")
    }

    /** CNXN y, si adbd lo pide, AUTH (firma; si no la conoce, envía la clave pública y espera). */
    private fun handshake(inp: DataInputStream, out: OutputStream): Boolean {
        send(out, CNXN, VERSION, MAX_DATA, "host::\u0000".toByteArray())
        var sentKey = false
        var signed = false
        while (true) {
            val m = read(inp)
            when {
                m.cmd == CNXN -> return true
                m.cmd == AUTH && m.arg0 == 1 && !signed -> {
                    signed = true
                    send(out, AUTH, 2, 0, sign(m.data))
                }
                m.cmd == AUTH && m.arg0 == 1 && !sentKey -> {
                    // Clave no autorizada: Android muestra el diálogo en la TV (60 s para aceptar)
                    sentKey = true
                    keyOffered = true // si no se acepta a tiempo, available() reintenta al minuto
                    send(out, AUTH, 3, 0, (publicKeyString() + "\u0000").toByteArray())
                }
                m.cmd == AUTH -> return false
            }
        }
    }

    private fun send(out: OutputStream, cmd: Int, a0: Int, a1: Int, data: ByteArray) {
        val b = ByteBuffer.allocate(24 + data.size).order(ByteOrder.LITTLE_ENDIAN)
        b.putInt(cmd).putInt(a0).putInt(a1).putInt(data.size).putInt(data.sumOf { it.toInt() and 0xff }).putInt(cmd.inv())
        b.put(data)
        out.write(b.array())
        out.flush()
    }

    private fun read(inp: DataInputStream): Msg {
        val h = ByteArray(24).also { inp.readFully(it) }
        val b = ByteBuffer.wrap(h).order(ByteOrder.LITTLE_ENDIAN)
        val cmd = b.int; val a0 = b.int; val a1 = b.int; val len = b.int
        if (len < 0 || len > 1024 * 1024) throw IllegalStateException("adb: paquete inválido")
        val data = ByteArray(len).also { inp.readFully(it) }
        return Msg(cmd, a0, a1, data)
    }

    /* --------------------- Clave RSA del agente para adbd --------------------- */

    private fun keyFiles() = File(AgentApp.instance.filesDir, "adbkey.priv") to File(AgentApp.instance.filesDir, "adbkey.pub")

    @Synchronized
    private fun keys(): Pair<PrivateKey, RSAPublicKey> {
        val (fPriv, fPub) = keyFiles()
        val kf = KeyFactory.getInstance("RSA")
        if (fPriv.exists() && fPub.exists()) {
            try {
                return kf.generatePrivate(PKCS8EncodedKeySpec(fPriv.readBytes())) to
                    (kf.generatePublic(X509EncodedKeySpec(fPub.readBytes())) as RSAPublicKey)
            } catch (_: Exception) {}
        }
        val kp = KeyPairGenerator.getInstance("RSA").apply { initialize(2048) }.generateKeyPair()
        fPriv.writeBytes(kp.private.encoded)
        fPub.writeBytes(kp.public.encoded)
        return kp.private to (kp.public as RSAPublicKey)
    }

    private fun sign(token: ByteArray): ByteArray {
        val s = Signature.getInstance("NONEwithRSA")
        s.initSign(keys().first)
        s.update(SHA1_PREFIX + token)
        return s.sign()
    }

    /** Clave pública en el formato de adb (estructura RSA de 2048 bits en base64 + nombre). */
    private fun publicKeyString(): String {
        val pub = keys().second
        val n = pub.modulus
        val words = 64
        val r32 = BigInteger.ONE.shiftLeft(32)
        val n0inv = r32.subtract(n.mod(r32).modInverse(r32)).toInt()
        val rr = BigInteger.ONE.shiftLeft(4096).mod(n)
        val b = ByteBuffer.allocate(4 + 4 + words * 4 + words * 4 + 4).order(ByteOrder.LITTLE_ENDIAN)
        b.putInt(words).putInt(n0inv)
        fun putBig(x: BigInteger) {
            var v = x
            repeat(words) { b.putInt(v.mod(r32).toInt()); v = v.shiftRight(32) }
        }
        putBig(n); putBig(rr)
        b.putInt(pub.publicExponent.toInt())
        return Base64.encodeToString(b.array(), Base64.NO_WRAP) + " smartpizarra-agent@tv"
    }
}

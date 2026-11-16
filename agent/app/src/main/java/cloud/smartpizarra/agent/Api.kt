// Versión del archivo: 1.0.0 (2026-11-16)
package cloud.smartpizarra.agent

import org.json.JSONObject
import java.io.File
import java.io.FileOutputStream
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest

/** Error HTTP de la API: código y, si el servidor lo dice, segundos hasta reintentar. */
class ApiException(val code: Int, val retryAfter: Long?, val error: String) : IOException("HTTP $code $error")

/**
 * Cliente de server/public_html/api/agent.php (una URL, parámetro action=...).
 * Autenticación: cabeceras X-Agent-Id + X-Agent-Secret en cada petición (siempre HTTPS en
 * producción; el servidor guarda solo el sha256 del secreto, igual que api/device.php).
 * HttpURLConnection de la plataforma: gzip automático y sin librerías.
 */
object Api {
    private const val CONNECT_MS = 15_000
    private const val READ_MS = 30_000

    private fun url(action: String, extra: String = ""): String {
        val base = Prefs.serverUrl
        return base + (if (base.contains('?')) "&" else "?") + "action=" + action + extra
    }

    private fun open(u: String, method: String): HttpURLConnection {
        val (id, secret) = Identity.ensure()
        val c = URL(u).openConnection() as HttpURLConnection
        c.connectTimeout = CONNECT_MS
        c.readTimeout = READ_MS
        c.requestMethod = method
        c.useCaches = false
        c.instanceFollowRedirects = false
        c.setRequestProperty("X-Agent-Id", id)
        c.setRequestProperty("X-Agent-Secret", secret)
        c.setRequestProperty("User-Agent", "SmartPizarraAgent/${BuildConfig.VERSION_NAME}")
        c.setRequestProperty("Accept", "application/json")
        return c
    }

    /** Toma la hora del servidor de la cabecera Date (corrige equipos sin reloj con pila). */
    private fun learnClock(c: HttpURLConnection) {
        val d = c.date
        if (d > 0) Prefs.serverOffsetMs = d - System.currentTimeMillis()
    }

    private fun fail(c: HttpURLConnection): Nothing {
        val code = c.responseCode
        val body = try { c.errorStream?.bufferedReader()?.use { it.readText() } ?: "" } catch (e: IOException) { "" }
        val err = try { JSONObject(body).optString("error", "") } catch (e: Exception) { "" }
        val retry = c.getHeaderField("Retry-After")?.toLongOrNull()
            ?: try { JSONObject(body).optLong("retryAfter", 0).takeIf { it > 0 } } catch (e: Exception) { null }
        throw ApiException(code, retry, err)
    }

    /** POST JSON => JSON. Lanza ApiException (HTTP) o IOException (red). */
    fun post(action: String, body: JSONObject): JSONObject {
        val c = open(url(action), "POST")
        try {
            val bytes = body.toString().toByteArray()
            c.doOutput = true
            c.setRequestProperty("Content-Type", "application/json; charset=utf-8")
            c.setFixedLengthStreamingMode(bytes.size)
            c.outputStream.use { it.write(bytes) }
            learnClock(c)
            if (c.responseCode !in 200..299) fail(c)
            val text = c.inputStream.bufferedReader().use { it.readText() }
            return if (text.isBlank()) JSONObject() else JSONObject(text)
        } finally {
            c.disconnect()
        }
    }

    /**
     * Manifiesto con ETag: null si no cambió (304); si cambió, (etag, json).
     */
    fun manifest(etag: String?): Pair<String, String>? {
        val c = open(url("manifest"), "GET")
        try {
            if (!etag.isNullOrEmpty()) c.setRequestProperty("If-None-Match", "\"$etag\"")
            learnClock(c)
            if (c.responseCode == 304) return null
            if (c.responseCode != 200) fail(c)
            val text = c.inputStream.bufferedReader().use { it.readText() }
            val tag = (c.getHeaderField("ETag") ?: JSONObject(text).optString("etag")).trim('"')
            return tag to text
        } finally {
            c.disconnect()
        }
    }

    /**
     * Descarga reanudable a [part]: pide desde el byte que ya tiene (Range). Devuelve el
     * SHA-256 (hex) del archivo COMPLETO: rehace el hash de lo ya descargado tras un corte.
     * [onBytes] recibe los bytes totales del archivo cada vez que avanza.
     */
    fun download(u: String, part: File, onBytes: (Long) -> Unit): String {
        val md = MessageDigest.getInstance("SHA-256")
        val buf = ByteArray(64 * 1024)
        if (part.exists()) part.inputStream().use { inp ->
            while (true) { val n = inp.read(buf); if (n < 0) break; md.update(buf, 0, n) }
        }
        var have = if (part.exists()) part.length() else 0L
        val c = open(u, "GET")
        try {
            c.readTimeout = 60_000
            c.setRequestProperty("Accept", "*/*")
            c.setRequestProperty("Accept-Encoding", "identity")
            if (have > 0) c.setRequestProperty("Range", "bytes=$have-")
            val code = c.responseCode
            if (code == 416) {
                // Ya lo teníamos completo (o el .part es más grande que el archivo): se verifica fuera
                return md.digest().toHex()
            }
            if (code != 200 && code != 206) fail(c)
            if (code == 200 && have > 0) {
                // El servidor ignoró Range: empezar de cero
                part.delete(); md.reset(); have = 0
            }
            FileOutputStream(part, true).use { out ->
                c.inputStream.use { inp ->
                    var sinceSync = 0L
                    var lastReport = 0L
                    while (true) {
                        val n = inp.read(buf)
                        if (n < 0) break
                        out.write(buf, 0, n)
                        md.update(buf, 0, n)
                        have += n
                        sinceSync += n
                        if (sinceSync >= 1 shl 20) { out.fd.sync(); sinceSync = 0 }
                        val now = System.currentTimeMillis()
                        if (now - lastReport > 500) { onBytes(have); lastReport = now }
                    }
                    out.fd.sync()
                }
            }
            onBytes(have)
            return md.digest().toHex()
        } finally {
            c.disconnect()
        }
    }

    /** Archivo pequeño (el logo) a memoria. */
    fun fetchBytes(action: String, extra: String): ByteArray {
        val c = open(url(action, extra), "GET")
        try {
            c.setRequestProperty("Accept", "*/*")
            if (c.responseCode != 200) fail(c)
            return c.inputStream.use { it.readBytes() }
        } finally {
            c.disconnect()
        }
    }

    fun fileUrl(versionId: Long): String = url("file", "&v=$versionId")
}

fun ByteArray.toHex(): String = joinToString("") { "%02x".format(it) }

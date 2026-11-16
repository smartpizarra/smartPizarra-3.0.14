// Versión del archivo: 1.0.0 (2026-11-16)
package cloud.smartpizarra.agent

import android.content.Context
import android.content.SharedPreferences
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import android.util.Log
import java.security.KeyStore
import java.security.SecureRandom
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Datos persistentes del agente (SharedPreferences "agent"):
 *  server        URL de la API (por defecto BuildConfig.DEFAULT_SERVER; adb puede cambiarla)
 *  agentId       "AG-XXXX-XXXX-XXXX", lo genera el propio equipo la primera vez
 *  secret        64 hex aleatorios, CIFRADO con una clave del Android Keystore (si el
 *                Keystore del equipo falla, que pasa en algunas cajas baratas, se guarda
 *                con el prefijo "p:" para no dejar el equipo sin identidad)
 *  etag / manifest  último manifiesto válido (con él se lanza la app principal sin red)
 *  serverOffset  diferencia (ms) entre la hora del servidor y la del equipo: muchas TV box
 *                no tienen reloj con pila y arrancan con la fecha mal
 */
object Prefs {
    private val sp: SharedPreferences by lazy {
        AgentApp.instance.getSharedPreferences("agent", Context.MODE_PRIVATE)
    }

    var serverUrl: String
        get() = sp.getString("server", null)?.takeIf { it.isNotBlank() } ?: BuildConfig.DEFAULT_SERVER
        set(v) = sp.edit().putString("server", v).apply()

    var agentId: String?
        get() = sp.getString("agentId", null)
        set(v) = sp.edit().putString("agentId", v).apply()

    var secret: String?
        get() = sp.getString("secret", null)?.let { KeystoreBox.open(it) }
        set(v) = sp.edit().putString("secret", v?.let { KeystoreBox.seal(it) }).apply()

    var etag: String?
        get() = sp.getString("etag", null)
        set(v) = sp.edit().putString("etag", v).apply()

    var manifestJson: String?
        get() = sp.getString("manifest", null)
        set(v) = sp.edit().putString("manifest", v).apply()

    var serverOffsetMs: Long
        get() = sp.getLong("serverOffset", 0L)
        set(v) = sp.edit().putLong("serverOffset", v).apply()

    var status: String
        get() = sp.getString("status", "") ?: ""
        set(v) = sp.edit().putString("status", v).apply()

    var logoSha: String?
        get() = sp.getString("logoSha", null)
        set(v) = sp.edit().putString("logoSha", v).apply()

    var lastSyncAt: Long
        get() = sp.getLong("lastSyncAt", 0L)
        set(v) = sp.edit().putLong("lastSyncAt", v).apply()

    var lastError: String
        get() = sp.getString("lastError", "") ?: ""
        set(v) = sp.edit().putString("lastError", v).apply()

    /** Momentos (ms) de los últimos lanzamientos de la app principal: detecta bucles de cierres. */
    var launches: String
        get() = sp.getString("launches", "") ?: ""
        set(v) = sp.edit().putString("launches", v).apply()

    var restarts: Int
        get() = sp.getInt("restarts", 0)
        set(v) = sp.edit().putInt("restarts", v).apply()

    /** Hora del servidor (ms) según el último desfase conocido. */
    fun serverNow(): Long = System.currentTimeMillis() + serverOffsetMs

    /** Borra identidad y caché (equipo "nuevo" para el servidor). Solo por adb (ConfigReceiver). */
    fun resetIdentity() {
        sp.edit().remove("agentId").remove("secret").remove("etag").remove("manifest").remove("status").apply()
    }
}

/** Identidad del equipo ante el servidor: la crea una sola vez. */
object Identity {
    private val rnd = SecureRandom()

    @Synchronized
    fun ensure(): Pair<String, String> {
        val id = Prefs.agentId
        val secret = Prefs.secret
        if (id != null && secret != null) return id to secret
        val b = ByteArray(6).also { rnd.nextBytes(it) }
        val hex = b.joinToString("") { "%02X".format(it) }
        val newId = "AG-${hex.substring(0, 4)}-${hex.substring(4, 8)}-${hex.substring(8, 12)}"
        val newSecret = ByteArray(32).also { rnd.nextBytes(it) }.joinToString("") { "%02x".format(it) }
        Prefs.agentId = newId
        Prefs.secret = newSecret
        return newId to newSecret
    }
}

/** Cifrado AES-GCM con una clave que nunca sale del Android Keystore. */
object KeystoreBox {
    private const val ALIAS = "agent_secret"
    private const val PLAIN = "p:"

    private fun key(): SecretKey {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (ks.getKey(ALIAS, null) as? SecretKey)?.let { return it }
        val gen = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        gen.init(
            KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .build()
        )
        return gen.generateKey()
    }

    fun seal(plain: String): String = try {
        val c = Cipher.getInstance("AES/GCM/NoPadding")
        c.init(Cipher.ENCRYPT_MODE, key())
        val out = c.iv + c.doFinal(plain.toByteArray())
        "k:" + Base64.encodeToString(out, Base64.NO_WRAP)
    } catch (e: Exception) {
        Log.w(AgentApp.TAG, "Keystore no disponible: ${e.message}")
        PLAIN + plain
    }

    fun open(stored: String): String? = try {
        if (stored.startsWith(PLAIN)) stored.substring(PLAIN.length)
        else {
            val raw = Base64.decode(stored.removePrefix("k:"), Base64.NO_WRAP)
            val c = Cipher.getInstance("AES/GCM/NoPadding")
            c.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, raw, 0, 12))
            String(c.doFinal(raw, 12, raw.size - 12))
        }
    } catch (e: Exception) {
        Log.w(AgentApp.TAG, "no se pudo leer el secreto: ${e.message}")
        null
    }
}

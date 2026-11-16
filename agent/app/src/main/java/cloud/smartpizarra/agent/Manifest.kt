// Versión del archivo: 1.0.0 (2026-11-16)
package cloud.smartpizarra.agent

import android.graphics.Color
import org.json.JSONObject

/**
 * ESTADO DESEADO que el servidor publica para este equipo (api/agent.php action=manifest).
 *
 *  status      pending | authorized | revoked (solo "authorized" recibe tareas)
 *  groupId     grupo del equipo (los comandos pueden ir dirigidos al grupo: "g:<id>")
 *  mainApp     app que se lanza cuando no hay tareas (package + actividad opcional)
 *  apps        versión mínima que debe tener cada paquete: si la instalada es menor, se
 *              descarga e instala (taskId estable: repetirla no hace nada nuevo)
 *  uninstall   paquetes que deben quitarse
 *  commands    comandos FIRMADOS por el servidor (ver Commands.kt)
 *  params      ajustes: intervalo de consulta, texto/colores de la pantalla de mantenimiento,
 *              ventana horaria para actualizar, logo...
 */
data class Manifest(
    val etag: String,
    val status: String,
    val groupId: Long,
    val mainPackage: String?,
    val mainActivity: String?,
    val apps: List<AppSpec>,
    val uninstall: List<RemoveSpec>,
    val commands: List<CommandSpec>,
    val params: Params,
) {
    data class AppSpec(
        val taskId: String, val pkg: String, val versionCode: Long, val versionId: Long,
        val sha256: String, val size: Long,
    )

    data class RemoveSpec(val taskId: String, val pkg: String)

    data class CommandSpec(
        val id: Long, val target: String, val name: String, val args: String, val exp: Long, val sig: String,
    ) {
        val taskId get() = "c$id"
    }

    data class Params(
        val pollSeconds: Int,
        val maintenanceText: String,
        val brandColor: Int,
        val textColor: Int,
        val showPercent: Boolean,
        val installWindow: String,
        val foregroundDownloads: Boolean,
        val logoSha256: String,
        /** Giro de las pantallas del agente (0, 90, 180, 270): TV montadas en vertical. */
        val rotation: Int,
    )

    companion object {
        fun parse(text: String?): Manifest? {
            if (text.isNullOrBlank()) return null
            return try {
                val o = JSONObject(text)
                val main = o.optJSONObject("mainApp")
                val apps = o.optJSONArray("apps")
                val rem = o.optJSONArray("uninstall")
                val cmds = o.optJSONArray("commands")
                val p = o.optJSONObject("params") ?: JSONObject()
                Manifest(
                    etag = o.optString("etag"),
                    status = o.optString("status", "pending"),
                    groupId = o.optLong("groupId", 0),
                    mainPackage = main?.optString("package")?.takeIf { it.isNotBlank() },
                    mainActivity = main?.optString("activity")?.takeIf { it.isNotBlank() && it != "null" },
                    apps = List(apps?.length() ?: 0) { i ->
                        val a = apps!!.getJSONObject(i)
                        AppSpec(
                            a.getString("taskId"), a.getString("package"), a.getLong("versionCode"),
                            a.getLong("versionId"), a.getString("sha256").lowercase(), a.getLong("size"),
                        )
                    },
                    uninstall = List(rem?.length() ?: 0) { i ->
                        val r = rem!!.getJSONObject(i)
                        RemoveSpec(r.getString("taskId"), r.getString("package"))
                    },
                    commands = List(cmds?.length() ?: 0) { i ->
                        val c = cmds!!.getJSONObject(i)
                        CommandSpec(
                            c.getLong("id"), c.getString("target"), c.getString("name"),
                            c.optString("args", "{}"), c.getLong("exp"), c.getString("sig"),
                        )
                    },
                    params = Params(
                        pollSeconds = p.optInt("pollSeconds", 300).coerceIn(30, 3600),
                        maintenanceText = p.optString("maintenanceText", "").ifBlank {
                            AgentApp.instance.getString(R.string.maintenance_default)
                        },
                        brandColor = color(p.optString("brandColor"), 0xFF002B4F.toInt()),
                        textColor = color(p.optString("textColor"), 0xFFD9DEE5.toInt()),
                        showPercent = p.optBoolean("showPercent", true),
                        installWindow = p.optString("installWindow", ""),
                        foregroundDownloads = p.optBoolean("foregroundDownloads", false),
                        logoSha256 = p.optString("logoSha256", "").lowercase(),
                        rotation = p.optInt("rotation", 0).let { if (it in listOf(0, 90, 180, 270)) it else 0 },
                    ),
                )
            } catch (e: Exception) {
                null
            }
        }

        private fun color(s: String?, fallback: Int): Int = try {
            if (s.isNullOrBlank()) fallback else Color.parseColor(s)
        } catch (e: Exception) {
            fallback
        }

        /** Manifiesto guardado (el último válido). */
        fun cached(): Manifest? = parse(Prefs.manifestJson)
    }
}

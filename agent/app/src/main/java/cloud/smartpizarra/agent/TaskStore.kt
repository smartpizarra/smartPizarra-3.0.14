// Versión del archivo: 1.0.0 (2026-11-16)
package cloud.smartpizarra.agent

import android.content.ContentValues
import android.content.Context
import android.database.Cursor
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper

/**
 * DIARIO DE TAREAS en SQLite. Cada paso se anota ANTES de hacerlo: tras un apagón (las
 * TV box se apagan de golpe), el agente sabe dónde iba y lo repite sin efectos dobles.
 *
 *  id        estable, lo da el servidor: "d<despliegue>r<reintento>:<paquete>:<versionCode>",
 *            "m<grupo>:<paquete>:<versionCode>" (app principal), "u<despliegue>r<n>:<paquete>",
 *            "c<comando>"
 *  type      install | uninstall | command
 *  state     PENDING -> DOWNLOADED -> INSTALLING -> DONE | FAILED
 *            (un error recuperable vuelve a PENDING con next_try_at y attempts+1)
 *  reported  1 cuando el servidor ya recibió el resultado final
 */
class TaskStore(ctx: Context) : SQLiteOpenHelper(ctx, "agent.db", null, 1) {

    data class Task(
        val id: String, val type: String, val pkg: String, val versionCode: Long, val versionId: Long,
        val sha256: String, val size: Long, val state: String, val attempts: Int, val nextTryAt: Long,
        val code: String, val detail: String, val reported: Boolean, val startedAt: Long,
    )

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            """CREATE TABLE tasks (
                id TEXT PRIMARY KEY, type TEXT NOT NULL, pkg TEXT NOT NULL DEFAULT '',
                version_code INTEGER NOT NULL DEFAULT 0, version_id INTEGER NOT NULL DEFAULT 0,
                sha256 TEXT NOT NULL DEFAULT '', size INTEGER NOT NULL DEFAULT 0,
                state TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, next_try_at INTEGER NOT NULL DEFAULT 0,
                code TEXT NOT NULL DEFAULT '', detail TEXT NOT NULL DEFAULT '',
                reported INTEGER NOT NULL DEFAULT 0, started_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0)"""
        )
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {}

    private fun Cursor.task() = Task(
        getString(0), getString(1), getString(2), getLong(3), getLong(4), getString(5), getLong(6),
        getString(7), getInt(8), getLong(9), getString(10), getString(11), getInt(12) == 1, getLong(13),
    )

    private val cols = "id,type,pkg,version_code,version_id,sha256,size,state,attempts,next_try_at,code,detail,reported,started_at"

    @Synchronized
    fun get(id: String): Task? = readableDatabase.rawQuery("SELECT $cols FROM tasks WHERE id = ?", arrayOf(id)).use {
        if (it.moveToFirst()) it.task() else null
    }

    /** Crea la tarea si no existe (si ya existe, conserva su estado y sus intentos). */
    @Synchronized
    fun ensure(id: String, type: String, pkg: String, versionCode: Long = 0, versionId: Long = 0, sha256: String = "", size: Long = 0) {
        val v = ContentValues().apply {
            put("id", id); put("type", type); put("pkg", pkg); put("version_code", versionCode)
            put("version_id", versionId); put("sha256", sha256); put("size", size); put("state", PENDING)
            put("started_at", System.currentTimeMillis()); put("updated_at", System.currentTimeMillis())
        }
        writableDatabase.insertWithOnConflict("tasks", null, v, SQLiteDatabase.CONFLICT_IGNORE)
    }

    @Synchronized
    fun setState(id: String, state: String, code: String = "", detail: String = "") {
        val v = ContentValues().apply {
            put("state", state); put("code", code); put("detail", detail.take(4096))
            put("updated_at", System.currentTimeMillis())
            if (state == DONE || state == FAILED) put("reported", 0)
        }
        writableDatabase.update("tasks", v, "id = ?", arrayOf(id))
    }

    /** Error recuperable: vuelve a PENDING y espera (backoff 1, 2, 4, 8 min...). Al 5.º intento, FAILED. */
    @Synchronized
    fun retryLater(id: String, code: String, detail: String) {
        val t = get(id) ?: return
        val attempts = t.attempts + 1
        if (attempts >= MAX_ATTEMPTS) {
            setState(id, FAILED, code, detail)
            writableDatabase.execSQL("UPDATE tasks SET attempts = ? WHERE id = ?", arrayOf<Any>(attempts, id))
            return
        }
        val wait = 60_000L shl (attempts - 1).coerceAtMost(5)
        val v = ContentValues().apply {
            put("state", PENDING); put("attempts", attempts); put("next_try_at", System.currentTimeMillis() + wait)
            put("code", code); put("detail", detail.take(4096)); put("updated_at", System.currentTimeMillis())
        }
        writableDatabase.update("tasks", v, "id = ?", arrayOf(id))
    }

    /** Resultados finales que el servidor aún no tiene. */
    @Synchronized
    fun unreported(): List<Task> = readableDatabase.rawQuery(
        "SELECT $cols FROM tasks WHERE state IN ('$DONE','$FAILED') AND reported = 0 LIMIT 50", null
    ).use { c -> buildList { while (c.moveToNext()) add(c.task()) } }

    @Synchronized
    fun markReported(ids: List<String>) {
        val db = writableDatabase
        db.beginTransaction()
        try {
            ids.forEach { db.execSQL("UPDATE tasks SET reported = 1 WHERE id = ?", arrayOf(it)) }
            db.setTransactionSuccessful()
        } finally {
            db.endTransaction()
        }
    }

    /** Tareas que quedaron "instalando" (apagón): se resuelven al planificar. */
    @Synchronized
    fun interrupted(): List<Task> = readableDatabase.rawQuery(
        "SELECT $cols FROM tasks WHERE state = '$INSTALLING'", null
    ).use { c -> buildList { while (c.moveToNext()) add(c.task()) } }

    /** Limpieza: resultados ya informados de hace más de 30 días. */
    @Synchronized
    fun prune() {
        writableDatabase.execSQL(
            "DELETE FROM tasks WHERE reported = 1 AND updated_at < ?",
            arrayOf<Any>(System.currentTimeMillis() - 30L * 86_400_000L)
        )
    }

    companion object {
        const val PENDING = "PENDING"
        const val DOWNLOADED = "DOWNLOADED"
        const val INSTALLING = "INSTALLING"
        const val DONE = "DONE"
        const val FAILED = "FAILED"
        const val MAX_ATTEMPTS = 5
    }
}

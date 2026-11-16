// Versión del archivo: 1.0.0 (2026-11-16)
package cloud.smartpizarra.agent

import android.os.Handler
import android.os.Looper
import android.view.Gravity
import android.view.View
import android.widget.FrameLayout
import java.util.concurrent.CopyOnWriteArrayList

/**
 * Giro de las pantallas del agente para TV montadas en vertical: el "escenario" ocupa la
 * pantalla con ancho y alto intercambiados y se gira sobre su centro (como hace
 * SmartPizarra con su contenido). Sin coste de CPU: es una transformación de la vista.
 */
object Look {
    fun rotate(root: FrameLayout, stage: View, deg: Int) {
        root.post {
            val w = root.width
            val h = root.height
            if (w == 0 || h == 0) return@post
            val swap = deg % 180 != 0
            stage.layoutParams = FrameLayout.LayoutParams(if (swap) h else w, if (swap) w else h, Gravity.CENTER)
            stage.rotation = deg.toFloat()
        }
    }
}

/**
 * Estado que comparten el motor (hilo del agente) y las pantallas (hilo principal):
 * si hay una operación en curso, su progreso y si se muestra la pantalla de mantenimiento.
 * Progreso de un lote: descarga 80 % (por bytes) + instalación 20 % (por pasos).
 * Los avisos a las pantallas se agrupan: como mucho 2 por segundo (poca CPU).
 */
object UiState {
    @Volatile var busy = false
        private set
    @Volatile var screen = false
    /** idle | waiting_turn | downloading | installing (se informa en el heartbeat). */
    @Volatile var stateName = "idle"
    /** Privilegio de instalación detectado: owner | system | root | interactive. */
    @Volatile var mode = "?"
    @Volatile var params: Manifest.Params? = null
        private set
    @Volatile private var steps = 1
    @Volatile private var stepsDone = 0
    @Volatile private var bytesTotal = 0L
    @Volatile var bytesDone = 0L
        private set

    private val listeners = CopyOnWriteArrayList<() -> Unit>()
    private val main = Handler(Looper.getMainLooper())
    @Volatile private var posted = false
    @Volatile private var lastPost = 0L

    fun addListener(l: () -> Unit) { listeners += l }
    fun removeListener(l: () -> Unit) { listeners -= l }

    /** Progreso 0..1000. */
    fun progress(): Int {
        val s = stepsDone.toDouble() / steps.coerceAtLeast(1)
        val p = if (bytesTotal > 0) 0.8 * (bytesDone.toDouble() / bytesTotal).coerceIn(0.0, 1.0) + 0.2 * s else s
        return (p * 1000).toInt().coerceIn(0, 1000)
    }

    fun begin(p: Manifest.Params, stepCount: Int, totalBytes: Long, show: Boolean) {
        params = p; steps = stepCount; stepsDone = 0; bytesTotal = totalBytes; bytesDone = 0
        busy = true; screen = show; stateName = "working"
        notifyChanged(force = true)
    }

    fun waitingTurn(position: Int) { stateName = "waiting_turn"; notifyChanged() }
    fun downloaded(base: Long, fileBytes: Long) { bytesDone = base + fileBytes; notifyChanged() }
    fun installing() { stateName = "installing"; bytesDone = bytesTotal; notifyChanged(force = true) }
    fun stepDone() { stepsDone++; notifyChanged() }

    fun end() {
        if (!busy && !screen) return
        busy = false; screen = false; stateName = "idle"
        notifyChanged(force = true)
    }

    /** Avisa a las pantallas en el hilo principal (agrupado: máx. 2 avisos por segundo). */
    fun notifyChanged(force: Boolean = false) {
        val now = System.currentTimeMillis()
        if (!force && (posted || now - lastPost < 500)) return
        posted = true
        main.post {
            posted = false
            lastPost = System.currentTimeMillis()
            listeners.forEach { it() }
        }
    }
}

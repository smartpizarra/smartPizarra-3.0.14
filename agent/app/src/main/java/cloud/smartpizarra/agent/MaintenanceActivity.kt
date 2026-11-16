// Versión del archivo: 1.0.0 (2026-11-16)
package cloud.smartpizarra.agent

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.graphics.BitmapFactory
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.view.WindowManager
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import java.io.File
import kotlin.random.Random

/**
 * PANTALLA DE MANTENIMIENTO: fondo corporativo, logo (del servidor), el texto
 * "Actualizando librerías del sistema..." (configurable), una barra de progreso y el
 * porcentaje (opcional). NUNCA muestra paquetes, rutas, errores ni datos internos: si algo
 * falla, la pantalla se cierra y el detalle solo viaja al servidor.
 *  - Se cierra sola al terminar las tareas (UiState.busy = false).
 *  - Texto gris claro (no blanco puro) y el bloque se desplaza un poco cada minuto para
 *    no marcar las pantallas.
 *  - Sin animaciones continuas: la barra solo se redibuja cuando cambia el valor.
 */
class MaintenanceActivity : Activity() {
    private val handler = Handler(Looper.getMainLooper())
    private lateinit var root: FrameLayout
    private lateinit var stage: FrameLayout
    private var rotation = -1
    private lateinit var box: LinearLayout
    private lateinit var logo: ImageView
    private lateinit var text: TextView
    private lateinit var bar: ProgressBar
    private lateinit var percent: TextView
    private var lastProgress = -1

    private val listener: () -> Unit = { refresh() }
    private val tick = object : Runnable {
        override fun run() { refresh(); handler.postDelayed(this, 1_000) }
    }
    private val drift = object : Runnable {
        override fun run() {
            box.translationX = Random.nextInt(-3, 4) * stage.width / 100f
            box.translationY = Random.nextInt(-3, 4) * stage.height / 100f
            handler.postDelayed(this, 60_000)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        buildViews()
    }

    override fun onResume() {
        super.onResume()
        UiState.addListener(listener)
        handler.post(tick)
        handler.postDelayed(drift, 60_000)
        refresh()
    }

    override fun onPause() {
        super.onPause()
        UiState.removeListener(listener)
        handler.removeCallbacks(tick)
        handler.removeCallbacks(drift)
    }

    @Deprecated("Deprecated in Java")
    override fun onBackPressed() { /* durante el mantenimiento no se sale */ }

    private fun refresh() {
        if (!UiState.busy || !UiState.screen) { finish(); return }
        val p = UiState.params
        if (p != null) {
            root.setBackgroundColor(p.brandColor)
            text.setTextColor(p.textColor); percent.setTextColor(p.textColor)
            if (text.text != p.maintenanceText) text.text = p.maintenanceText
            percent.visibility = if (p.showPercent) View.VISIBLE else View.GONE
            if (p.rotation != rotation) { rotation = p.rotation; Look.rotate(root, stage, rotation) }
        }
        val v = UiState.progress()
        if (v != lastProgress) {
            lastProgress = v
            bar.progress = v
            percent.text = "${v / 10} %"
        }
    }

    private fun dp(v: Int) = TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v.toFloat(), resources.displayMetrics).toInt()

    private fun buildViews() {
        root = FrameLayout(this)
        box = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER_HORIZONTAL }
        logo = ImageView(this).apply { adjustViewBounds = true }
        val f = File(filesDir, "logo.img")
        val bmp = if (f.exists()) BitmapFactory.decodeFile(f.path, BitmapFactory.Options().apply { inSampleSize = 2 }) else null
        if (bmp != null) {
            logo.setImageBitmap(bmp)
            box.addView(logo, LinearLayout.LayoutParams(dp(260), dp(130)).apply { bottomMargin = dp(28) })
        }
        text = TextView(this).apply { textSize = 22f; gravity = Gravity.CENTER; setTextColor(0xFFD9DEE5.toInt()) }
        box.addView(text, LinearLayout.LayoutParams(-2, -2))
        bar = ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal).apply { max = 1000; isIndeterminate = false }
        box.addView(bar, LinearLayout.LayoutParams(dp(420), dp(10)).apply { topMargin = dp(24) })
        percent = TextView(this).apply { textSize = 16f; gravity = Gravity.CENTER; setTextColor(0xFFD9DEE5.toInt()) }
        box.addView(percent, LinearLayout.LayoutParams(-2, -2).apply { topMargin = dp(10) })
        stage = FrameLayout(this)
        stage.addView(box, FrameLayout.LayoutParams(-2, -2, Gravity.CENTER))
        root.addView(stage, FrameLayout.LayoutParams(-1, -1, Gravity.CENTER))
        root.setBackgroundColor(0xFF002B4F.toInt())
        text.text = getString(R.string.maintenance_default)
        setContentView(root)
    }

    companion object {
        /** Abre la pantalla si Android lo permite (desde Android 10, solo con privilegio o en primer plano). */
        fun open(ctx: Context) {
            if (!MainApp.canStartFromBackground(ctx) && !HomeActivity.visible) return
            try {
                ctx.startActivity(Intent(ctx, MaintenanceActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            } catch (e: Exception) {
                Log.w(AgentApp.TAG, "mantenimiento: ${e.message}")
            }
        }
    }
}

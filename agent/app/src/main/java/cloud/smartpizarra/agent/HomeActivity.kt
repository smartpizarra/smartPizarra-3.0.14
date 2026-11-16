// Versión del archivo: 1.0.0 (2026-11-16)
package cloud.smartpizarra.agent

import android.app.Activity
import android.content.Intent
import android.graphics.BitmapFactory
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.provider.Settings
import android.text.format.DateFormat
import android.util.TypedValue
import android.view.Gravity
import android.view.KeyEvent
import android.view.View
import android.widget.Button
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import java.io.File

/**
 * LAUNCHER (HOME) del equipo. Android la abre al encender, al pulsar Inicio y cuando la
 * app principal se cierra o falla: así la app principal se vuelve a abrir sola sin
 * vigilar nada en segundo plano (cero CPU).
 *
 *  - Con tareas en curso: abre la pantalla de mantenimiento.
 *  - Sin tareas: abre la app principal del manifiesto tras una pausa corta (1,5 s tras
 *    encender, 3 s si se cerró, 60 s si se cerró 5 veces en 10 min: bucle de fallos).
 *  - Sin app principal configurada o instalada: solo el fondo corporativo y, abajo, el ID
 *    del equipo (para autorizarlo en el panel).
 *  - Cualquier tecla del mando durante la pausa abre un MENÚ técnico (ID, estado, abrir
 *    la app, Ajustes de Android, sincronizar). Se cierra solo al minuto.
 */
class HomeActivity : Activity() {
    private val handler = Handler(Looper.getMainLooper())
    private lateinit var root: FrameLayout
    private lateinit var stage: FrameLayout
    private lateinit var logo: ImageView
    private lateinit var info: TextView
    private lateinit var menu: LinearLayout
    private lateinit var menuInfo: TextView
    private var menuShown = false
    private var launchNow = false
    private var launchScheduled = false

    private val listener: () -> Unit = { if (!menuShown) evaluate() }
    private val launchRunnable = Runnable { launchScheduled = false; launchMain() }
    private val hideMenu = Runnable { closeMenu() }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        AgentService.start(this)
        buildViews()
        launchNow = intent?.getBooleanExtra(EXTRA_LAUNCH_NOW, false) == true
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        if (intent.getBooleanExtra(EXTRA_LAUNCH_NOW, false)) launchNow = true
    }

    override fun onResume() {
        super.onResume()
        visible = true
        UiState.addListener(listener)
        applyLook()
        if (!menuShown) evaluate()
    }

    override fun onPause() {
        super.onPause()
        visible = false
        UiState.removeListener(listener)
        handler.removeCallbacks(launchRunnable)
        launchScheduled = false
    }

    /** Decide qué hacer: mantenimiento, abrir la app principal o esperar configuración. */
    private fun evaluate() {
        if (UiState.busy && UiState.screen) {
            MaintenanceActivity.open(this); return
        }
        val m = Manifest.cached()
        val launch = MainApp.launchIntent(this, m)
        if (launch == null) {
            info.text = getString(R.string.waiting_config) + "  ·  ID " + (Prefs.agentId ?: "—")
            info.visibility = View.VISIBLE
            return
        }
        info.visibility = View.GONE
        if (launchScheduled) return
        launchScheduled = true
        handler.postDelayed(launchRunnable, launchDelay())
    }

    private fun recentLaunches(): List<Long> {
        val now = System.currentTimeMillis()
        return Prefs.launches.split(',').mapNotNull { it.toLongOrNull() }.filter { now - it < 10 * 60_000 }
    }

    private fun launchDelay(): Long = when {
        launchNow -> 300
        recentLaunches().size >= 5 -> 60_000
        SystemClock.elapsedRealtime() < 3 * 60_000 && recentLaunches().isEmpty() -> 1_500
        else -> 3_000
    }

    private fun launchMain() {
        launchNow = false
        val i = MainApp.launchIntent(this, Manifest.cached()) ?: return evaluate()
        Prefs.launches = (recentLaunches() + System.currentTimeMillis()).takeLast(10).joinToString(",")
        try {
            startActivity(i)
        } catch (e: Exception) {
            info.text = "No se pudo abrir la aplicación"
            info.visibility = View.VISIBLE
        }
    }

    /* ------------------------------ MENÚ ------------------------------- */

    override fun onKeyDown(keyCode: Int, event: KeyEvent?): Boolean {
        if (keyCode == KeyEvent.KEYCODE_HOME) return super.onKeyDown(keyCode, event)
        if (!menuShown) {
            openMenu(); return true
        }
        handler.removeCallbacks(hideMenu)
        handler.postDelayed(hideMenu, 60_000)
        return super.onKeyDown(keyCode, event)
    }

    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        // Es el launcher: "atrás" no sale a ninguna parte. Solo cierra el menú.
        if (menuShown) closeMenu()
    }

    private fun openMenu() {
        handler.removeCallbacks(launchRunnable)
        launchScheduled = false
        menuShown = true
        val last = Prefs.lastSyncAt.takeIf { it > 0 }?.let { DateFormat.format("dd/MM HH:mm", it).toString() } ?: "—"
        menuInfo.text = buildString {
            append("ID: ").append(Prefs.agentId ?: "—").append('\n')
            append("Estado: ").append(Prefs.status.ifEmpty { "—" }).append("   Modo: ").append(UiState.mode).append('\n')
            append("Agente ").append(BuildConfig.VERSION_NAME).append("   Última sincronización: ").append(last).append('\n')
            append("Servidor: ").append(Prefs.serverUrl)
            if (Prefs.lastError.isNotEmpty()) append('\n').append("Último error: ").append(Prefs.lastError)
        }
        menu.visibility = View.VISIBLE
        menu.getChildAt(1)?.requestFocus()
        handler.postDelayed(hideMenu, 60_000)
    }

    private fun closeMenu() {
        handler.removeCallbacks(hideMenu)
        menuShown = false
        menu.visibility = View.GONE
        evaluate()
    }

    /* ----------------------------- VISTAS ------------------------------ */

    private fun dp(v: Int) = TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v.toFloat(), resources.displayMetrics).toInt()

    private fun buildViews() {
        root = FrameLayout(this)
        stage = FrameLayout(this)
        root.addView(stage, FrameLayout.LayoutParams(-1, -1, Gravity.CENTER))
        logo = ImageView(this).apply { adjustViewBounds = true; visibility = View.GONE }
        stage.addView(logo, FrameLayout.LayoutParams(dp(220), dp(120), Gravity.CENTER))
        info = TextView(this).apply {
            setTextColor(0xFFD9DEE5.toInt()); textSize = 14f; visibility = View.GONE
        }
        stage.addView(info, FrameLayout.LayoutParams(-2, -2, Gravity.BOTTOM or Gravity.START).apply {
            setMargins(dp(32), 0, 0, dp(24))
        })
        menu = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setBackgroundColor(0xEE0B1A2A.toInt())
            setPadding(dp(24), dp(20), dp(24), dp(20))
            visibility = View.GONE
        }
        menuInfo = TextView(this).apply { setTextColor(0xFFD9DEE5.toInt()); textSize = 14f }
        menu.addView(menuInfo)
        fun button(text: Int, action: () -> Unit) = Button(this).apply {
            setText(text); isAllCaps = false; setOnClickListener { action() }
            menu.addView(this, LinearLayout.LayoutParams(-1, -2).apply { topMargin = dp(8) })
        }
        button(R.string.menu_open_app) { menuShown = false; menu.visibility = View.GONE; launchMain() }
        button(R.string.menu_settings) {
            try { startActivity(Intent(Settings.ACTION_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) } catch (_: Exception) {}
        }
        button(R.string.menu_sync) { Agent.kick(); menuInfo.append("\nSincronizando…") }
        button(R.string.menu_close) { closeMenu() }
        stage.addView(menu, FrameLayout.LayoutParams(dp(520), -2, Gravity.CENTER))
        setContentView(root)
    }

    /** Color corporativo y logo del último manifiesto. */
    private fun applyLook() {
        val p = Manifest.cached()?.params
        root.setBackgroundColor(p?.brandColor ?: 0xFF002B4F.toInt())
        Look.rotate(root, stage, p?.rotation ?: 0)
        info.setTextColor(p?.textColor ?: 0xFFD9DEE5.toInt())
        val f = File(filesDir, "logo.img")
        if (f.exists()) {
            val o = BitmapFactory.Options().apply { inSampleSize = 2 }
            BitmapFactory.decodeFile(f.path, o)?.let { logo.setImageBitmap(it); logo.visibility = View.VISIBLE }
        } else logo.visibility = View.GONE
    }

    companion object {
        const val EXTRA_LAUNCH_NOW = "launch_now"
        @Volatile var visible = false
            private set
    }
}

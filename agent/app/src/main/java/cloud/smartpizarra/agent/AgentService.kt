// Versión del archivo: 1.0.1 (2026-11-16)
package cloud.smartpizarra.agent

import android.app.Notification
import android.app.Service
import android.app.admin.DevicePolicyManager
import android.app.job.JobParameters
import android.app.job.JobService
import android.content.BroadcastReceiver
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.os.SystemClock
import android.util.Log
import android.app.admin.DeviceAdminReceiver

/**
 * SERVICIO EN PRIMER PLANO que mantiene vivo el motor (Agent). START_STICKY: si Android
 * mata el proceso, lo vuelve a crear. La notificación es obligatoria (en TV no se ve).
 * Con Device Owner, además, fija este agente como launcher HOME de forma permanente.
 */
class AgentService : Service() {

    override fun onCreate() {
        super.onCreate()
        val n = if (Build.VERSION.SDK_INT >= 26) Notification.Builder(this, AgentApp.CHANNEL) else @Suppress("DEPRECATION") Notification.Builder(this)
        val notification = n.setSmallIcon(R.drawable.ic_stat)
            .setContentTitle(getString(R.string.notification_title))
            .setOngoing(true)
            .build()
        try {
            if (Build.VERSION.SDK_INT >= 34) startForeground(1, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE)
            else startForeground(1, notification)
        } catch (e: Exception) {
            Log.w(AgentApp.TAG, "startForeground: ${e.message}")
        }
        Prefs.restarts = Prefs.restarts + 1
        if (Installers.isDeviceOwner(this)) pinAsHome(this)
        Agent.start(this)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_SYNC) Agent.kick()
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    companion object {
        const val ACTION_SYNC = "cloud.smartpizarra.agent.SYNC"

        fun start(ctx: Context, sync: Boolean = false) {
            val i = Intent(ctx, AgentService::class.java)
            if (sync) i.action = ACTION_SYNC
            try {
                if (Build.VERSION.SDK_INT >= 26) ctx.startForegroundService(i) else ctx.startService(i)
            } catch (e: Exception) {
                // Android 12+ puede negarlo desde segundo plano: el launcher o el arranque lo abrirán
                Log.w(AgentApp.TAG, "servicio: ${e.message}")
            }
        }

        /** Device Owner: este launcher queda como HOME preferido y no se puede cambiar por error. */
        fun pinAsHome(ctx: Context) {
            try {
                val dpm = ctx.getSystemService(DevicePolicyManager::class.java)
                val filter = IntentFilter(Intent.ACTION_MAIN).apply {
                    addCategory(Intent.CATEGORY_HOME); addCategory(Intent.CATEGORY_DEFAULT)
                }
                dpm.addPersistentPreferredActivity(Installers.admin(ctx), filter, ComponentName(ctx, HomeActivity::class.java))
            } catch (e: Exception) {
                Log.w(AgentApp.TAG, "pinAsHome: ${e.message}")
            }
        }
    }
}

/** Arranque del equipo y actualización del propio agente => servicio en marcha. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        AgentService.start(context)
        // Autoactualización: Android mató el proceso (y con él la pantalla de mantenimiento)
        // y deja a la vista la pantalla anterior (a veces el launcher de fábrica). Al arrancar
        // el equipo no hace falta: Android ya abre el HOME (este agente). Aquí abrimos
        // nuestro launcher, que lanza la app principal o el mantenimiento si queda trabajo.
        if (intent.action == Intent.ACTION_MY_PACKAGE_REPLACED) MainApp.requestLaunch(context, immediate = true)
    }
}

/** Watchdog (cada 15 min): servicio muerto => arrancarlo; ciclo atascado => despertarlo. */
class WatchdogJob : JobService() {
    override fun onStartJob(params: JobParameters?): Boolean {
        if (!Agent.started) AgentService.start(this)
        else if (SystemClock.elapsedRealtime() - Agent.lastCycleAt > 45 * 60_000L) Agent.kick()
        return false
    }

    override fun onStopJob(params: JobParameters?): Boolean = false
}

/** Administrador del dispositivo: requisito del modo Device Owner. */
class AdminReceiver : DeviceAdminReceiver()

/**
 * CONFIGURACIÓN POR ADB (solo el shell de adb o el sistema: el receptor exige DUMP).
 *   adb shell am broadcast -a cloud.smartpizarra.agent.CONFIGURE -n cloud.smartpizarra.agent/.ConfigReceiver \
 *       --es server https://smartpizarra.cloud/api/agent.php     cambia la URL de la API
 *       --ez sync true                                             sincroniza ya
 *       --ez reset true                                            identidad nueva (equipo nuevo)
 *       --ez release_owner true                                    deja de ser Device Owner
 */
class ConfigReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val done = ArrayList<String>()
        intent.getStringExtra("server")?.let { s ->
            if (s.startsWith("https://") || (BuildConfig.DEBUG && s.startsWith("http://"))) {
                Prefs.serverUrl = s; Prefs.etag = null; done += "server"
            } else done += "server rechazado (solo https)"
        }
        if (intent.getBooleanExtra("reset", false)) { Prefs.resetIdentity(); done += "reset" }
        if (intent.getBooleanExtra("release_owner", false)) {
            try {
                val dpm = context.getSystemService(DevicePolicyManager::class.java)
                dpm.clearPackagePersistentPreferredActivities(Installers.admin(context), context.packageName)
                @Suppress("DEPRECATION") dpm.clearDeviceOwnerApp(context.packageName)
                done += "release_owner"
            } catch (e: Exception) {
                done += "release_owner: ${e.message}"
            }
        }
        AgentService.start(context, sync = true)
        if (intent.getBooleanExtra("sync", false) || done.isNotEmpty()) done += "sync"
        resultData = "id=${Prefs.agentId} ${done.joinToString(", ")}"
    }
}

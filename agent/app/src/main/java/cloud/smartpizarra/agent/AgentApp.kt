// Versión del archivo: 1.0.0 (2026-11-16)
package cloud.smartpizarra.agent

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.job.JobInfo
import android.app.job.JobScheduler
import android.content.ComponentName
import android.os.Build
import android.util.Log

/**
 * Aplicación del agente. Al crear el proceso:
 *  - canal de la notificación obligatoria del servicio en primer plano (importancia mínima);
 *  - programa el WATCHDOG (JobScheduler, cada 15 min, sobrevive a reinicios) que vuelve a
 *    arrancar el servicio si Android lo mató.
 * No usa librerías externas (ni WorkManager ni AndroidX): menos RAM y un APK más pequeño.
 */
class AgentApp : Application() {

    override fun onCreate() {
        super.onCreate()
        instance = this
        if (Build.VERSION.SDK_INT >= 26) {
            val ch = NotificationChannel(CHANNEL, getString(R.string.channel_name), NotificationManager.IMPORTANCE_MIN)
            ch.setShowBadge(false)
            getSystemService(NotificationManager::class.java).createNotificationChannel(ch)
        }
        scheduleWatchdog()
    }

    private fun scheduleWatchdog() {
        try {
            val js = getSystemService(JobScheduler::class.java)
            if (js.getPendingJob(WATCHDOG_JOB) != null) return
            val job = JobInfo.Builder(WATCHDOG_JOB, ComponentName(this, WatchdogJob::class.java))
                .setPeriodic(15 * 60 * 1000L)
                .setPersisted(true)
                .build()
            js.schedule(job)
        } catch (e: Exception) {
            Log.w(TAG, "watchdog: ${e.message}")
        }
    }

    companion object {
        const val TAG = "SPAgent"
        const val CHANNEL = "agent"
        const val WATCHDOG_JOB = 4711
        lateinit var instance: AgentApp
            private set
    }
}

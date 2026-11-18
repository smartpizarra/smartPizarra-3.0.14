<!-- Versión del archivo: 1.0.1 (2026-11-17) -->
# Agente SmartPizarra (launcher de las TV box)

APK nativo `cloud.smartpizarra.agent` (Kotlin, sin librerías externas, ~80 KB en release). Es el **launcher (HOME)** de la TV: al encender abre la app principal que diga el servidor y, en segundo plano, instala / actualiza / desinstala APK y ejecuta comandos firmados. Servidor: `server/public_html/api/agent.php`; panel: `/admin/agentes.php` (ver `server/README.md`, sección *Agente de las TV*).

## Piezas

| Archivo | Qué hace |
|---|---|
| `AgentService.kt` | Servicio en primer plano (`START_STICKY`), `BootReceiver` (encendido y autoactualización), watchdog cada 15 min, `ConfigReceiver` (configuración por adb) |
| `Agent.kt` | Motor: heartbeat, manifiesto con ETag, plan (desinstalar → comandos → instalar; el propio agente el último), cola de descargas, backoff |
| `Api.kt` / `Manifest.kt` | Cliente HTTP y estado deseado (cache local) |
| `Installers.kt` | Instalador según privilegios: `owner` (Device Owner) → `system` → `root` → `adb` (adbd local) → `interactive` (pide confirmación en pantalla) |
| `AdbLocal.kt` / `RootShell.kt` | Shell con privilegios: protocolo adb contra 127.0.0.1 o `su` |
| `Commands.kt` | Lista blanca de comandos; firma ECDSA P-256 verificada con `keys/command_public.pem` |
| `TaskStore.kt` | Estado de cada tarea (idempotente, reintentos, sobrevive a reinicios) |
| `HomeActivity.kt` / `MaintenanceActivity.kt` / `UiState.kt` | Launcher y pantalla "Actualizando librerías del sistema..." (logo, barra, %, giro 0/90/180/270) |

Medido en la TV .137 (Android 9, ARMv7, 1 GB): **22 MB de RAM y 0 % de CPU** en reposo.

## Compilar

Herramientas en `E:\AndroidDev` (JDK 17, Android SDK 34, Gradle 8.7). Desde `agent/`:

```powershell
$env:JAVA_HOME = "E:\AndroidDev\jdk17"
E:\AndroidDev\gradle-8.7\bin\gradle.bat --no-daemon assembleRelease   # app\build\outputs\apk\release\app-release.apk
E:\AndroidDev\gradle-8.7\bin\gradle.bat --no-daemon assembleDebug     # pruebas (testOnly, acepta http://)
```

- **Cada versión publicada necesita un `versionCode` mayor**: súbelo en `app/build.gradle.kts` (o `-PagentVersionCode=N`).
- **Firma release**: `signing/agent-release.jks` + `signing/keystore.properties` (fuera de git). **Haz copia de seguridad**: sin esa clave no se pueden actualizar los agentes ya instalados (Android exige la misma firma).
- El release solo acepta servidor `https://` (`DEFAULT_SERVER` en `build.gradle.kts`). Un APK debug y uno release tienen firmas distintas: para pasar de uno a otro hay que desinstalar.

## Instalar en una TV (una vez por equipo)

```powershell
adb connect IP:PUERTO
adb install -r app-release.apk
adb shell cmd package set-home-activity cloud.smartpizarra.agent/.HomeActivity   # launcher por defecto
```

Modo de instalación silenciosa (el agente usa el mejor disponible; el panel lo muestra como *Modo*):

- **Device Owner** (lo ideal): `adb shell dpm set-device-owner cloud.smartpizarra.agent/.AdminReceiver`. Solo funciona sin cuentas en el equipo y si existe la función `android.software.device_admin` (las box .137 y .201 **no** la tienen).
- **adb local**: si el equipo tiene adb de red encendido (5555, 3222…). Sin autenticación (.137) funciona directo; con autenticación (.201) la primera vez hay que aceptar "¿Permitir depuración?" con el mando, marcando *Permitir siempre* (el agente reintenta al minuto). **Cada instalación nueva del agente (o borrar sus datos) genera otra clave y vuelve a salir el aviso.** Desde la 1.0.1, si el equipo tiene `su` usable por el shell (userdebug, como la .201), el agente fija `persist.adb.tcp.port` para que el adb de red siga encendido tras reiniciar; si no, al reiniciar se pierde el modo silencioso hasta volver a abrir el puerto.
- **root**: si existe `su`.
- Si no hay ninguno, cada instalación pide confirmación en pantalla.

Configuración por adb (solo shell de adb o el sistema):

```
adb shell am broadcast -a cloud.smartpizarra.agent.CONFIGURE -n cloud.smartpizarra.agent/.ConfigReceiver --es server https://smartpizarra.cloud/api/agent.php
   --ez sync true          sincroniza ya
   --ez reset true         identidad nueva (aparece como equipo nuevo)
   --ez release_owner true deja de ser Device Owner
```

Después autoriza el equipo en `/admin/agentes.php` y ponlo en su grupo (el grupo dice cuál es la app principal).

## Comportamiento importante

- **Sin manifiesto no abre la app principal**: un equipo recién instalado que nunca llegó al servidor muestra "esperando configuración" con su ID. Por eso: instala la migración en el servidor antes de poner agentes release en las TV.
- Tras una **autoactualización** Android mata el proceso; `BootReceiver` (`MY_PACKAGE_REPLACED`) lo arranca y vuelve a abrir la app principal (~1 s se ve la pantalla anterior).
- Android 10+ prohíbe abrir pantallas desde segundo plano salvo Device Owner o permiso de superposición; en Android 9 (las box actuales) no hay restricción.

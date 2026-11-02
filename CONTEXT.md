# CONTEXT — smartPizarra 3.0.14

Documento de memoria del proyecto. Leerlo antes de trabajar y actualizarlo tras cada cambio significativo.

## Objetivo
SmartPizarra, versión 3.0.14. Proyecto desarrollado con Kodular (descripción funcional pendiente de completar).

## Estado actual (2026-11-02)
- Git rama `main`; último respaldo `db1e073` (antes de hacer global la orientación). Ese cambio aún sin commit.
- **Orientación GLOBAL + escala POR PANTALLA** (2026-11-02, decisión del usuario): una sola orientación `smartpizarra.orientation` (default `DEFAULT_ORIENTATION` = 90 en `loading.html`) válida para todos los documentos. Escala propia por pantalla: `PAGES` en `loading.html` = `{loading:{scale:85}, config:{scale:100}}` → claves `smartpizarra.<pantalla>.scale`. `loading.html` crea todas y aplica orientación global + su escala. `updateSettings`: `orientation` es global; `"page"` solo indica de qué pantalla es `scale` (sin page => loading; desconocida => error); también `"config.scale"` directo. JSON a la app: `"orientation"` (global) + `"pages":{"loading":{"scale"},"config":{"scale"}}` (factor). `config.html`: sección Orientación "todas las pantallas" (gira en vivo al elegirla), luego Ligas, luego "Pantalla a configurar" (solo afecta a la escala) y Escala; se abre en Configuración con lo guardado y relee localStorage cada 1 s / pageshow / visibilitychange / storage si no hay cambios sin guardar; la tarjeta usa siempre la escala de Configuración. Verificado en el dispositivo.
- **Conflicto con la app instalada**: en cada carga de página la app ejecuta `updateSettings('{"version":"3.0.14","url":…,"email":…,"orientation":90,"scale":80,"league":"mlb"}')` (capturado en el dispositivo). Pisa la orientación global, `loading.scale` y `league`, deshaciendo lo guardado en config.html. Hay que quitar esa llamada de los bloques de Kodular o dejar solo version/url/email. `example1.html` y `example2.html` son del usuario (sin commit, referencia).
- **`config.html`** (creado desde `example2.html`, ES5 var/function): pantalla para MODIFICAR orientación, ligas y escala con control remoto. NO tiene valores por defecto (son de `loading.html`): clave inexistente => "Sin configurar", null en el JSON, y "Guardar" solo escribe lo que tiene valor (escala sin clave parte de 100 solo en pantalla). Muestra versión/web/email/id leídos de localStorage (solo lectura; se quitó `updateData`). Mismas claves y `LEAGUE_OPTIONS` (`todos` en vez de `none`). Responsive como loading (FIT_RATIO 0.9 + revisión cada 1 s). Único JSON al pulsar "Salir": `{"origin":"config","action":"exit","status":"saved|unchanged|error","internetStatus","version","url","email","deviceId","orientation","scale"(factor),"league","leagues"}`; si internet aún no se verificó, espera hasta 3 s. Verificado en el dispositivo (con y sin claves, offline, 4 tamaños).
- Observado en el dispositivo: la app instalada ejecuta `updateSettings(...)` en la WebView por su cuenta (errores "updateSettings is not defined" en páginas que no la tienen).
- **Mensajes a la app** (`sendToAppInventor`, 4 métodos: AppInventor.setWebViewString → android → androidInterface → postMessage). Se envía un JSON:
  - al terminar la animación (`action:"loading"`), tras esperar el resultado de internet (máx. 3 s);
  - tras cada `updateSettings()` (`action:"updateSettings"`, con internet = último resultado conocido, más `ok/applied/rejected/error`).
  Formato: `{"origin":"loadingPage","action":…,"status":"true","internetStatus":"online|offline","version","url","email","deviceId","orientation","scale"(factor %/100, como example1),"league"(opción),"leagues"(lista de LEAGUE_OPTIONS)}`. Los settings se releen de localStorage justo antes de enviar.
- **Internet**: clase `InternetChecker` copiada de example1 (5 endpoints DNS/IP con CORS, se valida el contenido). Comprobación al iniciar y al 60 % de la animación. Verificado en el dispositivo online y offline.
- **API pública `updateSettings(json)`** (global; la app la llama con RunJavaScript/EvaluateJavaScript). Acepta texto JSON u objeto; campos opcionales `version`, `url`, `email`, `orientation`, `scale`, `league` (lista en `CONFIG.editableSettings`; `deviceId` NO es editable). Valida con el `parse` de cada setting, guarda el valor normalizado en localStorage y refresca la pantalla al momento. Devuelve texto JSON `{"ok","applied":[...],"rejected":[...],"error"?}`. Un stub en `<head>` encola las llamadas hechas antes de que arranque la página (`window.__pendingSettings`). Verificado en el dispositivo.
- **Responsive**: `LayoutManager` mide el tamaño natural de `#scaler` (offsetWidth/Height; `#scaler` sin ancho fijo y `flex-shrink:0`) y lo escala para ocupar `CONFIG.fitRatio` (0.9) del escenario rotado. `smartpizarra.scale` multiplica ese ajuste: 100 % = ocupa el 90 % de la pantalla (>100 % puede salirse). No se usan vw/vh porque la rotación los invertiría. Verificado en el dispositivo con 960x540, 1280x720, 480x320, 540x960 y 360x640 × 0°/90°: siempre cabe.
- `CONFIG.settings.*.defaultValue` puede ser una función (se llama solo al crear el valor).
- Limitación: el id vive en el localStorage del WebView; si se borran los datos de la app o se reinstala, se genera otro.
- `example1.html`: pantalla de carga completa de referencia (animación + internet + updateData + rotación/escala + mensaje JSON a Kodular).
- `loading.html`: solo la animación extraída de `example1.html` (logo flotante, barra scaleX, texto, 4 pasos, puntos, fundido → bienvenida). Arranca sola al cargar; sin comunicación con la app. Clases: `SettingsStore`, `LayoutManager`, `InfoPanel`, `LoadingAnimation`, `LoadingScreen`; ajustes en `CONFIG`.
- **Settings compartidos (localStorage)**: `loading.html` es el dueño/creador. Al iniciar verifica cada clave y, si falta o es inválida, la crea con el valor por defecto; luego aplica rotación (#stage) y escala (#scaler). Reacciona a `storage`, `resize`, `load`, `pageshow`, `visibilitychange` y a una revisión cada `CONFIG.watchMs` (1 s) que cubre el arranque del WebView compilado sin tamaño (0x0) y cambios sin evento `storage`; `LayoutManager.apply` solo toca el DOM si cambió orientación/escala/tamaño.
  | clave | valores | defecto |
  |---|---|---|
  | `smartpizarra.orientation` | 0/90/180/270 (global) | 90 (`DEFAULT_ORIENTATION`) |
  | `smartpizarra.<pantalla>.scale` | 10–300 (%) | `PAGES` (loading 85, config 100) |
  | `smartpizarra.version` | texto no vacío | 3.0.14 |
  | `smartpizarra.url` | texto no vacío | smartpizarra.com |
  | `smartpizarra.email` | texto no vacío | smartpizarra@gmail.com |
  | `smartpizarra.deviceId` | texto no vacío | generado una vez: `SP-XXXX-XXXX-XXXX` (48 bits hex, `crypto.getRandomValues`); se muestra como "Id: …" |
  | `smartpizarra.league` | `mlb` / `nba` / `nflnhl` / `todos` (sin distinguir mayúsculas) | todos |
  `smartpizarra.league` guarda el **id de la opción**, no la lista. Tabla `LEAGUE_OPTIONS` en `loading.html` (copiarla igual en otras páginas): mlb=`LIDOM,MLB,KO,PRO,AAAI,AAAP,LMP,LVBP,CARIB`, nba=`NBA,CBK,NBAPROP,WNBA,WCBK,EUBK`, nflnhl=`NHL,NFL,CFB,CFBAA,CFL,SOC,SOCCER,BOX,BOXING,TEN,TENNIS`, todos=`""` (sin filtro). Nota: `example1.html` usaba `none` en lugar de `todos`.
  Los textos de versión/web/email (y la web de la bienvenida) se leen SIEMPRE de localStorage vía `InfoPanel` (textContent, sin XSS); un cambio desde otra página se ve en ≤1 s.
  Nuevos settings: añadir entrada en `CONFIG.settings` (key, defaultValue, parse). Las otras páginas deben usar las mismas claves.
- `example1.html` sigue sin commit (es del usuario).

## Pruebas en dispositivo
- TV box en `adb connect 10.0.0.123:3222` (WebView del sistema 101.0.4951.61, ventana 960x540 CSS px).
- `loading.html` verificado ahí (2026-11-02) mediante DevTools sobre la WebView de la app SmartPizarra, sirviendo el archivo desde el PC (otro origen, sin tocar el localStorage real de la app): crea claves por defecto, aplica rotación 90/0, repara escala inválida, animación completa, sin errores de consola. Repetido con la revisión periódica: un cambio a 180°/150 % hecho desde la misma página se aplica en ≤1 s.
- **Ojo (2026-11-02)**: el APK instalado (`io.kodular.smartpizarra.smartPizarra_3_0_14`, `http://localhost/loading.html`) contiene la versión ANTERIOR de `loading.html` (sin settings). Hay que subir el `loading.html` actual a los assets de Kodular y recompilar.

## Decisiones técnicas
- Claves separadas (no un JSON único) para ser compatibles con `config.html`/`connection.html`/`fingerprint.html` de la versión anterior, que usan `smartpizarra.orientation` y `smartpizarra.scale`.
- Ojo: en `example1.html` el `scale` guardado por config.html tenía defecto 60 y significaba zoom de la app (no tamaño visual). En `loading.html` ahora es tamaño visual con defecto 100. Unificar cuando se reciban las otras páginas.

## Dispositivo objetivo (compatibilidad obligatoria)
- HiSilicon Hi3798MV300H, 4 núcleos ARMv7 **32 bits** (`armeabi-v7a`; no asumir ARM64).
- RAM 1 GB · data 3,3 GB · system 1,6 GB.
- Android 9 (API 28, `minSdkVersion 28`) · Android System WebView **101**.
- HTML/JS/CSS deben funcionar en Chromium 101 (nada posterior sin alternativa). Prioridad: estabilidad y bajo consumo.

## Entorno
- SO: Windows 10 · Editor: VSCode · Plataforma: Kodular.
- Carpeta padre (`../`, fuera del repo) contiene: `smartPizarra-3.0.13/` (versión anterior), `backup/`, `ClaudCodeSmartPizarra/`, y archivos HTML sueltos (`config - copia.html`, `config - copia (2).html`, `fingerprint1.backup.html`).

## Convenciones de trabajo
- Commit de respaldo en Git antes de modificar código; no incluir cambios ajenos sin autorización.
- Código comentado, nombres descriptivos, POO cuando aporte valor.
- No guardar secretos ni credenciales en este documento.

## Pendiente / próximos pasos
- Definir objetivo y funcionalidades de la versión 3.0.14.
- Incorporar el código fuente al repositorio (¿migrar desde 3.0.13?).
- Documentar arquitectura, dependencias e instrucciones de ejecución cuando existan.

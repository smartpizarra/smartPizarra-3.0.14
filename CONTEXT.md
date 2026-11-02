# CONTEXT — smartPizarra 3.0.14

Documento de memoria del proyecto. Leerlo antes de trabajar y actualizarlo tras cada cambio significativo.

## Objetivo
SmartPizarra, versión 3.0.14. Proyecto desarrollado con Kodular (descripción funcional pendiente de completar).

## Estado actual (2026-11-02)
- Git rama `main`; último respaldo `094ba68` (revisión periódica del layout). Versión/web/email en localStorage aún sin commit.
- `example1.html`: pantalla de carga completa de referencia (animación + internet + updateData + rotación/escala + mensaje JSON a Kodular).
- `loading.html`: solo la animación extraída de `example1.html` (logo flotante, barra scaleX, texto, 4 pasos, puntos, fundido → bienvenida). Arranca sola al cargar; sin comunicación con la app. Clases: `SettingsStore`, `LayoutManager`, `InfoPanel`, `LoadingAnimation`, `LoadingScreen`; ajustes en `CONFIG`.
- **Settings compartidos (localStorage)**: `loading.html` es el dueño/creador. Al iniciar verifica cada clave y, si falta o es inválida, la crea con el valor por defecto; luego aplica rotación (#stage) y escala (#scaler). Reacciona a `storage`, `resize`, `load`, `pageshow`, `visibilitychange` y a una revisión cada `CONFIG.watchMs` (1 s) que cubre el arranque del WebView compilado sin tamaño (0x0) y cambios sin evento `storage`; `LayoutManager.apply` solo toca el DOM si cambió orientación/escala/tamaño.
  | clave | valores | defecto |
  |---|---|---|
  | `smartpizarra.orientation` | 0/90/180/270 | 90 |
  | `smartpizarra.scale` | 10–300 (%) | 100 |
  | `smartpizarra.version` | texto no vacío | 3.0.14 |
  | `smartpizarra.url` | texto no vacío | smartpizarra.com |
  | `smartpizarra.email` | texto no vacío | smartpizarra@gmail.com |
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

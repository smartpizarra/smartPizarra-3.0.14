# CONTEXT — smartPizarra 3.0.14

Documento de memoria del proyecto. Leerlo antes de trabajar y actualizarlo tras cada cambio significativo.

## Objetivo
SmartPizarra, versión 3.0.14. Proyecto desarrollado con Kodular (descripción funcional pendiente de completar).

## Estado actual (2026-11-02)
- Repositorio Git inicializado en rama `main` con un único commit (`Initial commit`).
- `example1.html`: pantalla de carga completa de referencia (animación + internet + updateData + rotación/escala + mensaje JSON a Kodular).
- `loading.html`: solo la animación extraída de `example1.html` (logo flotante, barra scaleX, texto, 4 pasos, puntos, fundido → bienvenida). Arranca sola al cargar; sin comunicación con la app. Clases: `LoadingAnimation`, `LoadingScreen`; ajustes en `CONFIG`.
- Archivos aún sin commit (untracked): `CONTEXT.md`, `example1.html`, `loading.html`.

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

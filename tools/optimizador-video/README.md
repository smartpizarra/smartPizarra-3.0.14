<!-- Versión del archivo: 1.0.0 (2026-11-15) -->
# Smart Pizarra · Optimizador de video (Windows)

Convierte cualquier video para que las pantallas (TV box Hi3798, Android 9) lo
reproduzcan fluido y para que el panel lo acepte al subirlo.

## Uso

1. Abre `SmartPizarra-Optimizador.exe`. También puedes arrastrar videos sobre el icono del programa.
2. Pulsa **Agregar videos…** o **Agregar carpeta…**.
3. Elige las opciones (las de fábrica son las recomendadas) y pulsa **Optimizar**.
4. Los videos quedan en una carpeta `optimizados` junto a cada original, con el nombre
   `<original>_smartpizarra.mp4`. El original no se modifica.

La columna **Resultado** muestra el archivo final verificado con las mismas reglas
que usa el servidor. "Listo ✓ cumple" significa que el panel lo aceptará.

## Qué hace

| Parámetro | Valor |
|---|---|
| Resolución | 720×1280 vertical (o 1280×720 horizontal, o según cada video) |
| Encaje | bordes negros, fondo difuminado o llenar recortando (nunca deforma) |
| Códec | H.264 perfil High, nivel 4.0, yuv420p 8 bits |
| FPS | constantes: 30 (25 o 24 si el original es de 25/50 o 24/48; nunca 60) |
| Bitrate | ~4 Mbps (Normal), ~5 (Alta), ~2.5 (Ligera) |
| Fotograma clave | cada 2 s |
| Audio | se quita (opcional: conservarlo en AAC 128 kbps) |
| MP4 | `faststart`, sin metadatos de giro (el giro se aplica a la imagen) |

**Por qué 720p.** Medido en el TV: con la pantalla girada 90°, un video de 1080×1920
pierde entre el 15 y el 30 % de los cuadros. Con menos píxeles, la composición del
giro es más ligera. Un anuncio de 10 s queda en 3–5 MB en lugar de 10–20 MB.

## Compilar el .exe

Ejecuta `build_exe.bat`. Necesita Python 3.10 o superior. El resultado queda en
`dist\SmartPizarra-Optimizador.exe` (~80 MB, con ffmpeg dentro).

Para probarlo sin compilar: `python optimizador_video.py`. Necesita `pip install imageio-ffmpeg`.

Si junto al programa hay un `ffmpeg.exe`, se usa ese en lugar del incluido.

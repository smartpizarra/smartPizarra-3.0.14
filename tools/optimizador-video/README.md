<!-- Versión del archivo: 1.0.1 (2026-11-19) -->
# Smart Pizarra · Optimizador de video (Windows)

Convierte cualquier video para que las pantallas (TV box HiSilicon .137 y Allwinner .201)
lo reproduzcan fluido **a pantalla completa** y para que el panel lo acepte al subirlo.

## Elige la pantalla correcta

El video sale con la **forma exacta de la pantalla** donde se va a ver; así llena la TV de
borde a borde. Nunca se añaden bordes negros.

| Pantalla | Cuándo |
|---|---|
| **TV horizontal 1280×720** (por defecto) | TV colgada normal, como la .201 y la .137 |
| **TV girada en vertical 720×1280** | TV montada de pie (orientación 90°/270° en config) |
| **Las dos versiones** | Si tienes TV de los dos tipos: crea `_horizontal` y `_vertical` |

| Encaje | Resultado |
|---|---|
| **Pantalla completa automática** (por defecto) | Si el video casi tiene la forma de la pantalla, la llena recortando ese poco; si no, muestra el video entero sobre un fondo difuminado del propio video |
| Llenar recortando los bordes | Siempre llena; corta lo que sobra por los lados o arriba/abajo |
| Video entero sobre fondo difuminado | Nunca corta; el fondo difuminado ocupa el resto |

Ejemplo real (2026-11-19, .201): un video horizontal convertido con la versión anterior a
"vertical con bordes negros" se veía en un recuadro de 405×228 en el centro de la TV. La
misma carrera convertida para "TV horizontal" ocupa los 1280×720 y perdió 4 de 509 cuadros (0,8 %).

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
| Resolución | 1280×720 (TV horizontal) o 720×1280 (TV girada), siempre a pantalla completa |
| Encaje | automático, recortando o con fondo difuminado (nunca deforma ni deja bordes negros) |
| Códec | H.264 perfil High, nivel 4.0, yuv420p 8 bits (decodificador por hardware de las dos cajas; nunca WebM/VP9) |
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

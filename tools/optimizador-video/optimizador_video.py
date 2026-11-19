# Versión del archivo: 1.1.0 (2026-11-19)
"""
SMART PIZARRA · OPTIMIZADOR DE VIDEO (Windows)

Convierte cualquier video para que las pantallas (TV box Hi3798, Android 9,
WebView 101) lo reproduzcan fluido y para que el servidor lo acepte al subirlo.

QUÉ HACE CON CADA VIDEO
  - SIEMPRE A PANTALLA COMPLETA (2026-11-19). La salida tiene la forma EXACTA de
    la pantalla donde se va a ver: 1280x720 si la TV está horizontal (la .201 y
    la .137: 1280x720 físicos) o 720x1280 si la página se gira 90° (TV montada
    en vertical). mirror.html encaja el video con object-fit: contain, así que
    solo un video con la forma de la pantalla la llena. Antes la opción por
    defecto era "Vertical 720x1280 con bordes negros": un video horizontal
    quedaba con franjas negras y, en una TV horizontal, encogido en el centro
    (405x228 de 1280x720 en la .201). Ya no hay bordes negros:
      * Automático (recomendado): si el video casi tiene la forma de la pantalla
        (diferencia <= 15 %), la llena recortando ese poco; si no (p. ej. video
        vertical de celular en TV horizontal), lo muestra entero sobre un fondo
        difuminado del propio video que ocupa toda la pantalla.
      * Llenar recortando o Fondo difuminado, a elección.
    "Las dos versiones" genera la horizontal y la vertical de cada video.
  - 720p y no 1080p: medido en el TV, con la pantalla girada 90° un 1080x1920
    pierde 15-30 % de los cuadros; con menos píxeles la composición es más ligera.
  - H.264 perfil High nivel 4.0, yuv420p 8 bits: decodificador por hardware de
    las dos cajas (OMX.hivendor.video.decoder.avc en la .137 HiSilicon,
    OMX.allwinner.video.decoder.avc en la .201 Allwinner). Nunca VP9/WebM.
  - Fotogramas por segundo CONSTANTES: 30 (o 25/24 si el original es de 25/24
    o de 50/48; nunca 60). Corrige los videos de celular con fps variable.
    (El video con saltos de la .201 era WebM VP9 1920x1080 a 50 fps.)
  - Bitrate limitado (unos 4 Mbps): un anuncio de 10 s queda en 3-5 MB, se
    descarga antes y alivia la cola de descargas del servidor.
  - Sin audio (las pantallas no lo usan). Opcional: conservarlo en AAC.
  - MP4 con "faststart" (la cabecera al principio) y sin metadatos de giro.
  - Al terminar VERIFICA el archivo con las mismas reglas que el servidor
    (MediaRepository::videoProblem): códec, nivel, fps y resolución.

FFMPEG
  Usa ffmpeg.exe junto al programa si existe; si no, el que trae el paquete
  imageio-ffmpeg (va dentro del .exe al compilarlo con build_exe.bat).

USO
  - Doble clic en el .exe, "Agregar videos..." y "Optimizar".
  - También se pueden arrastrar videos sobre el icono del .exe.
  - Desarrollo: python optimizador_video.py [videos...]
"""
import os
import queue
import re
import struct
import subprocess
import sys
import threading
import tkinter as tk
from tkinter import filedialog, messagebox, ttk

APP_TITLE = 'Smart Pizarra · Optimizador de video'
VIDEO_EXT = ('.mp4', '.mov', '.m4v', '.mkv', '.avi', '.wmv', '.webm', '.flv', '.mpg', '.mpeg', '.3gp', '.ts', '.mts', '.m2ts')
SUFFIX = '_smartpizarra'

# Reglas del servidor (server/private/lib.php, MediaRepository)
MAX_FPS = 30.5
MAX_H264_LEVEL = 41
MAX_LONG, MAX_SHORT = 1920, 1080

# Pantalla donde se verá -> tamaños de salida (uno o varios archivos por video)
ORIENTATIONS = {
    'TV horizontal 1280x720 (como la .201 y la .137)': [(1280, 720)],
    'TV girada en vertical 720x1280': [(720, 1280)],
    'Las dos versiones (horizontal y vertical)': [(1280, 720), (720, 1280)],
}
# Todas llenan la pantalla: nunca bordes negros
FITS = {
    'Pantalla completa automática (recomendado)': 'auto',
    'Llenar recortando los bordes': 'crop',
    'Video entero sobre fondo difuminado': 'blur',
}
# "auto": si las proporciones difieren hasta este punto, se recorta; si más, fondo difuminado
AUTO_CROP_MAX = 0.15
# Calidad -> (crf, maxrate kbps)
QUALITIES = {
    'Normal (recomendada, ~4 Mbps)': (23, 4000),
    'Alta (~5 Mbps)': (20, 5000),
    'Ligera (~2.5 Mbps)': (26, 2500),
}

NO_WINDOW = 0x08000000 if os.name == 'nt' else 0  # CREATE_NO_WINDOW: sin consola negra


# ---------------------------------------------------------------- ffmpeg

def find_ffmpeg():
    """ffmpeg.exe junto al programa (o en PATH) o, si no, el de imageio-ffmpeg."""
    base = os.path.dirname(sys.executable if getattr(sys, 'frozen', False) else os.path.abspath(__file__))
    for name in ('ffmpeg.exe', 'ffmpeg'):
        p = os.path.join(base, name)
        if os.path.isfile(p):
            return p
    try:
        import imageio_ffmpeg
        return imageio_ffmpeg.get_ffmpeg_exe()
    except Exception:
        pass
    from shutil import which
    return which('ffmpeg')


def probe(ffmpeg, path):
    """Lee duración, tamaño, fps, códec, giro y audio del video (salida de 'ffmpeg -i')."""
    r = subprocess.run([ffmpeg, '-hide_banner', '-i', path], capture_output=True, text=True,
                       encoding='utf-8', errors='replace', creationflags=NO_WINDOW)
    txt = r.stderr
    info = {'duration': None, 'width': None, 'height': None, 'fps': None, 'codec': '?', 'rotation': 0, 'audio': False}
    m = re.search(r'Duration: (\d+):(\d+):(\d+(?:\.\d+)?)', txt)
    if m:
        info['duration'] = int(m.group(1)) * 3600 + int(m.group(2)) * 60 + float(m.group(3))
    v = re.search(r'Stream #\S+.*?: Video: (\w+)(.*)', txt)
    if not v:
        return None
    info['codec'] = v.group(1)
    rest = v.group(2)
    m = re.search(r'(?<![\dx])(\d{2,5})x(\d{2,5})(?![\dx])', rest)
    if m:
        info['width'], info['height'] = int(m.group(1)), int(m.group(2))
    m = re.search(r'([\d.]+) fps', rest) or re.search(r'([\d.]+)k? tbr', rest)
    if m:
        info['fps'] = float(m.group(1))
    m = re.search(r'rotation of (-?[\d.]+) degrees', txt) or re.search(r'rotate\s*:\s*(-?\d+)', txt)
    if m:
        info['rotation'] = int(round(float(m.group(1)))) % 360
    if info['rotation'] in (90, 270) and info['width']:
        info['width'], info['height'] = info['height'], info['width']  # ffmpeg lo gira al convertir
    info['audio'] = bool(re.search(r'Stream #\S+.*?: Audio:', txt))
    return info


def target_fps(src):
    """fps de salida: 30 constantes; 25/24 si el original es de 25/24 (o 50/48); los bajos se respetan."""
    if not src:
        return '30', 30.0
    if abs(src - 50) < 1 or 24.5 <= src <= 25.5:
        return '25', 25.0
    if abs(src - 48) < 1 or 23.99 <= src < 24.5:
        return '24', 24.0
    if 23.9 < src < 23.99:
        return '24000/1001', 23.976
    if src < 23.9:
        return '%.3f' % src, src  # p. ej. 15 fps: no se inventan cuadros
    return '30', 30.0


def resolve_fit(fit, info, w, h):
    """'auto' => 'crop' si la forma del video casi coincide con la pantalla, si no 'blur'."""
    if fit != 'auto':
        return fit
    if not info.get('width') or not info.get('height'):
        return 'blur'
    src = info['width'] / info['height']
    dst = w / h
    diff = max(src, dst) / min(src, dst) - 1  # 0 = misma forma; 16:9 vs 9:16 = 2.16
    return 'crop' if diff <= AUTO_CROP_MAX else 'blur'


def build_filter(fit, w, h, fps_expr):
    """Cadena de filtros que LLENA WxH (recortando o con fondo difuminado), fps constantes, yuv420p."""
    tail = ',setsar=1,fps=%s,format=yuv420p' % fps_expr
    if fit == 'crop':
        return 'scale=%d:%d:force_original_aspect_ratio=increase:flags=lanczos,crop=%d:%d%s' % (w, h, w, h, tail)
    # Fondo: el propio video ampliado hasta llenar, muy difuminado y algo oscurecido;
    # encima, el video entero. Se difumina a 1/4 de tamaño (mismo aspecto, mucho menos cálculo).
    return ('split=2[bg][fg];'
            '[bg]scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,boxblur=10:2,eq=brightness=-0.10,'
            'scale=%d:%d[b];'
            '[fg]scale=%d:%d:force_original_aspect_ratio=decrease:force_divisible_by=2:flags=lanczos[f];'
            '[b][f]overlay=(W-w)/2:(H-h)/2%s') % (w // 4, h // 4, w // 4, h // 4, w, h, w, h, tail)


def build_command(ffmpeg, src, info, w, h, fit, quality, audio, out):
    """Línea de ffmpeg para convertir src a WxH (fit 'crop' o 'blur') en el archivo out."""
    fps_expr, fps_val = target_fps(info['fps'])
    crf, maxrate = quality
    gop = max(1, int(round(fps_val * 2)))  # fotograma clave cada 2 s
    cmd = [ffmpeg, '-hide_banner', '-y', '-nostats', '-progress', 'pipe:1', '-i', src,
           '-filter_complex', '[0:v:0]' + build_filter(fit, w, h, fps_expr) + '[v]', '-map', '[v]',
           '-map_metadata', '-1', '-map_chapters', '-1',
           '-c:v', 'libx264', '-preset', 'slow', '-profile:v', 'high', '-level:v', '4.0',
           '-crf', str(crf), '-maxrate', '%dk' % maxrate, '-bufsize', '%dk' % (maxrate * 2),
           '-g', str(gop), '-keyint_min', str(max(1, gop // 2)), '-pix_fmt', 'yuv420p',
           '-fps_mode', 'cfr', '-movflags', '+faststart']
    if audio and info['audio']:
        cmd += ['-map', '0:a:0?', '-c:a', 'aac', '-b:a', '128k', '-ac', '2', '-ar', '44100']
    else:
        cmd += ['-an']
    return cmd + ['-sn', '-dn', out]


# ---------------------------------------------------------------- verificación MP4 (igual que el servidor)

def _boxes(data, start, end):
    pos = start
    while pos + 8 <= end:
        size, typ = struct.unpack('>I4s', data[pos:pos + 8])
        head = 8
        if size == 1:
            size = struct.unpack('>Q', data[pos + 8:pos + 16])[0]
            head = 16
        elif size == 0:
            size = end - pos
        if size < head or pos + size > end:
            return
        yield typ, pos + head, pos + size
        pos += size


def _child(data, start, end, typ):
    for t, s, e in _boxes(data, start, end):
        if t == typ:
            return s, e
    return None


def mp4_info(path):
    """Códec, nivel H.264, tamaño y fps de la pista de video de un MP4 (como MediaRepository::mp4Info)."""
    with open(path, 'rb') as f:
        moov = None
        pos = 0
        size_file = os.path.getsize(path)
        while pos + 8 <= size_file:
            f.seek(pos)
            hdr = f.read(16)
            if len(hdr) < 8:
                break
            size, typ = struct.unpack('>I4s', hdr[:8])
            if size == 1:
                size = struct.unpack('>Q', hdr[8:16])[0]
            elif size == 0:
                size = size_file - pos
            if size < 8:
                break
            if typ == b'moov':
                f.seek(pos)
                moov = f.read(size)
                break
            pos += size
    if not moov:
        return None
    for t, s, e in _boxes(moov, 8, len(moov)):
        if t != b'trak':
            continue
        mdia = _child(moov, s, e, b'mdia')
        if not mdia:
            continue
        hdlr = _child(moov, mdia[0], mdia[1], b'hdlr')
        if not hdlr or moov[hdlr[0] + 8:hdlr[0] + 12] != b'vide':
            continue
        mdhd = _child(moov, mdia[0], mdia[1], b'mdhd')
        timescale = None
        if mdhd:
            ver = moov[mdhd[0]]
            off = mdhd[0] + (20 if ver == 1 else 12)
            timescale = struct.unpack('>I', moov[off:off + 4])[0]
        minf = _child(moov, mdia[0], mdia[1], b'minf')
        stbl = minf and _child(moov, minf[0], minf[1], b'stbl')
        if not stbl:
            return None
        info = {'codec': 'otro', 'level': None, 'width': 0, 'height': 0, 'fps': None}
        stsd = _child(moov, stbl[0], stbl[1], b'stsd')
        if stsd:
            entry = stsd[0] + 8
            esize, etype = struct.unpack('>I4s', moov[entry:entry + 8])
            info['codec'] = {b'avc1': 'h264', b'avc3': 'h264', b'hvc1': 'hevc', b'hev1': 'hevc'}.get(etype, etype.decode('latin1'))
            info['width'], info['height'] = struct.unpack('>HH', moov[entry + 32:entry + 36])
            avcc = _child(moov, entry + 86, entry + esize, b'avcC')
            if avcc:
                info['level'] = moov[avcc[0] + 3]
        stts = _child(moov, stbl[0], stbl[1], b'stts')
        if stts and timescale:
            n = struct.unpack('>I', moov[stts[0] + 4:stts[0] + 8])[0]
            frames = total = 0
            for i in range(n):
                c, d = struct.unpack('>II', moov[stts[0] + 8 + i * 8:stts[0] + 16 + i * 8])
                frames += c
                total += c * d
            if total:
                info['fps'] = frames * timescale / total
        return info
    return None


def server_problems(info):
    """Lo que el servidor rechazaría (lista vacía = lo acepta)."""
    p = []
    if info['codec'] == 'hevc':
        p.append('HEVC')
    if info['fps'] and info['fps'] > MAX_FPS:
        p.append('%d fps' % round(info['fps']))
    if info['codec'] == 'h264' and info['level'] and info['level'] > MAX_H264_LEVEL:
        p.append('nivel %.1f' % (info['level'] / 10))
    if max(info['width'], info['height']) > MAX_LONG or min(info['width'], info['height']) > MAX_SHORT:
        p.append('%dx%d' % (info['width'], info['height']))
    return p


def describe_output(out, fit):
    """(texto del resultado, cumple las reglas del servidor) de un MP4 ya convertido."""
    res = mp4_info(out)
    size = os.path.getsize(out)
    if not res:
        return 'No se pudo verificar · ' + human_size(size), False
    problems = server_problems(res)
    desc = '%dx%d %s · %s fps · H.264 %.1f · %s' % (
        res['width'], res['height'], 'recortado' if fit == 'crop' else 'fondo difuminado',
        ('%.2f' % res['fps']).rstrip('0').rstrip('.') if res['fps'] else '?',
        (res['level'] or 0) / 10, human_size(size))
    return (desc + (' · ' + ', '.join(problems) if problems else '')), not problems


# ---------------------------------------------------------------- utilidades

def human_size(n):
    for unit in ('B', 'KB', 'MB', 'GB'):
        if n < 1024 or unit == 'GB':
            return ('%.0f %s' if unit in ('B', 'KB') else '%.1f %s') % (n, unit)
        n /= 1024.0


def unique_path(path):
    base, ext = os.path.splitext(path)
    i = 2
    while os.path.exists(path):
        path = '%s (%d)%s' % (base, i, ext)
        i += 1
    return path


# ---------------------------------------------------------------- interfaz

class App:
    def __init__(self, root, files):
        self.root = root
        self.ffmpeg = find_ffmpeg()
        self.items = {}  # iid -> {'path', 'info'}
        self.events = queue.Queue()
        self.worker = None
        self.cancel = threading.Event()
        self.proc = None

        root.title(APP_TITLE)
        root.geometry('980x600')
        root.minsize(820, 480)
        try:
            ttk.Style().theme_use('vista')
        except tk.TclError:
            pass

        top = ttk.Frame(root, padding=(12, 10, 12, 4))
        top.pack(fill='x')
        ttk.Label(top, text='Optimiza videos para las pantallas Smart Pizarra',
                  font=('Segoe UI', 13, 'bold')).pack(anchor='w')
        ttk.Label(top, text='Siempre a pantalla completa (sin bordes negros) · H.264 nivel 4.0 · '
                            'fps constantes (30 máx.) · 720p · sin audio · verificado con las reglas del servidor',
                  foreground='#555').pack(anchor='w')

        bar = ttk.Frame(root, padding=(12, 6))
        bar.pack(fill='x')
        self.btn_add = ttk.Button(bar, text='Agregar videos…', command=self.add_files)
        self.btn_add.pack(side='left')
        self.btn_dir = ttk.Button(bar, text='Agregar carpeta…', command=self.add_folder)
        self.btn_dir.pack(side='left', padx=6)
        self.btn_del = ttk.Button(bar, text='Quitar seleccionados', command=self.remove_selected)
        self.btn_del.pack(side='left')
        self.btn_clear = ttk.Button(bar, text='Vaciar lista', command=self.clear)
        self.btn_clear.pack(side='left', padx=6)

        cols = ('archivo', 'original', 'estado', 'resultado')
        frame = ttk.Frame(root, padding=(12, 0))
        frame.pack(fill='both', expand=True)
        self.tree = ttk.Treeview(frame, columns=cols, show='headings', selectmode='extended')
        for c, t, w in (('archivo', 'Archivo', 260), ('original', 'Original', 250),
                        ('estado', 'Estado', 150), ('resultado', 'Resultado', 280)):
            self.tree.heading(c, text=t)
            self.tree.column(c, width=w, anchor='w')
        sb = ttk.Scrollbar(frame, orient='vertical', command=self.tree.yview)
        self.tree.configure(yscrollcommand=sb.set)
        self.tree.pack(side='left', fill='both', expand=True)
        sb.pack(side='right', fill='y')
        self.tree.tag_configure('ok', foreground='#1a7f37')
        self.tree.tag_configure('err', foreground='#b42318')

        opts = ttk.LabelFrame(root, text='Opciones', padding=(10, 6))
        opts.pack(fill='x', padx=12, pady=8)
        self.v_orient = tk.StringVar(value=list(ORIENTATIONS)[0])
        self.v_fit = tk.StringVar(value=list(FITS)[0])
        self.v_quality = tk.StringVar(value=list(QUALITIES)[0])
        self.v_audio = tk.BooleanVar(value=False)
        self.v_outdir = tk.StringVar(value='')
        row = 0
        for label, var, values in (('Pantalla:', self.v_orient, ORIENTATIONS), ('Encaje:', self.v_fit, FITS),
                                   ('Calidad:', self.v_quality, QUALITIES)):
            ttk.Label(opts, text=label).grid(row=row, column=0, sticky='w', pady=2)
            ttk.Combobox(opts, textvariable=var, values=list(values), state='readonly', width=50).grid(
                row=row, column=1, sticky='w', padx=6)
            row += 1
        ttk.Checkbutton(opts, text='Conservar el audio (no recomendado: las pantallas no lo usan)',
                        variable=self.v_audio).grid(row=0, column=2, sticky='w', padx=16)
        ttk.Label(opts, text='Guardar en:').grid(row=1, column=2, sticky='w', padx=16)
        out = ttk.Frame(opts)
        out.grid(row=2, column=2, sticky='we', padx=16)
        self.out_label = ttk.Label(out, text='Carpeta "optimizados" junto a cada video', foreground='#555', width=44)
        self.out_label.pack(side='left')
        ttk.Button(out, text='Elegir…', command=self.choose_outdir).pack(side='left', padx=4)
        ttk.Button(out, text='Restablecer', command=self.reset_outdir).pack(side='left')

        foot = ttk.Frame(root, padding=(12, 0, 12, 12))
        foot.pack(fill='x')
        self.progress = ttk.Progressbar(foot, mode='determinate', maximum=100)
        self.progress.pack(side='left', fill='x', expand=True)
        self.status = ttk.Label(foot, text='', width=34)
        self.status.pack(side='left', padx=10)
        self.btn_open = ttk.Button(foot, text='Abrir carpeta', command=self.open_output, state='disabled')
        self.btn_open.pack(side='left')
        self.btn_cancel = ttk.Button(foot, text='Cancelar', command=self.stop, state='disabled')
        self.btn_cancel.pack(side='left', padx=6)
        self.btn_go = ttk.Button(foot, text='Optimizar', command=self.start)
        self.btn_go.pack(side='left')
        self.last_output = None

        root.protocol('WM_DELETE_WINDOW', self.on_close)
        root.after(100, self.pump)
        if not self.ffmpeg:
            messagebox.showerror(APP_TITLE, 'No se encontró ffmpeg.\nCopia ffmpeg.exe junto a este programa.')
            self.btn_go.state(['disabled'])
        self.add_paths(files)

    # ---- lista
    def add_files(self):
        paths = filedialog.askopenfilenames(title='Elige los videos', filetypes=[
            ('Videos', ' '.join('*' + e for e in VIDEO_EXT)), ('Todos los archivos', '*.*')])
        self.add_paths(paths)

    def add_folder(self):
        d = filedialog.askdirectory(title='Elige una carpeta con videos')
        if d:
            self.add_paths([os.path.join(d, n) for n in sorted(os.listdir(d))
                            if n.lower().endswith(VIDEO_EXT) and SUFFIX not in n])

    def add_paths(self, paths):
        known = {it['path'] for it in self.items.values()}
        for p in paths:
            p = os.path.abspath(p)
            if os.path.isdir(p):
                self.add_paths([os.path.join(p, n) for n in sorted(os.listdir(p)) if n.lower().endswith(VIDEO_EXT)])
                continue
            if not os.path.isfile(p) or p in known:
                continue
            iid = self.tree.insert('', 'end', values=(os.path.basename(p), 'leyendo…', 'En espera', ''))
            self.items[iid] = {'path': p, 'info': None}
            known.add(p)
            threading.Thread(target=self._probe_item, args=(iid, p), daemon=True).start()

    def _probe_item(self, iid, path):
        info = probe(self.ffmpeg, path) if self.ffmpeg else None
        self.events.put(('probed', iid, info))

    def remove_selected(self):
        if self.worker:
            return
        for iid in self.tree.selection():
            self.tree.delete(iid)
            self.items.pop(iid, None)

    def clear(self):
        if self.worker:
            return
        self.tree.delete(*self.tree.get_children())
        self.items.clear()

    def choose_outdir(self):
        d = filedialog.askdirectory(title='Carpeta donde guardar los videos optimizados')
        if d:
            self.v_outdir.set(d)
            self.out_label.configure(text=d)

    def reset_outdir(self):
        self.v_outdir.set('')
        self.out_label.configure(text='Carpeta "optimizados" junto a cada video')

    def open_output(self):
        if self.last_output and os.path.isdir(self.last_output):
            os.startfile(self.last_output)

    # ---- proceso
    def set_busy(self, busy):
        for b in (self.btn_add, self.btn_dir, self.btn_del, self.btn_clear, self.btn_go):
            b.state(['disabled'] if busy else ['!disabled'])
        self.btn_cancel.state(['!disabled'] if busy else ['disabled'])

    def start(self):
        jobs = [iid for iid in self.tree.get_children() if self.items[iid]['info']]
        if not jobs:
            messagebox.showinfo(APP_TITLE, 'Agrega al menos un video válido.')
            return
        opts = {
            'sizes': ORIENTATIONS[self.v_orient.get()],
            'fit': FITS[self.v_fit.get()],
            'quality': QUALITIES[self.v_quality.get()],
            'audio': self.v_audio.get(),
            'outdir': self.v_outdir.get(),
        }
        self.cancel.clear()
        self.set_busy(True)
        self.progress['value'] = 0
        self.worker = threading.Thread(target=self.run_jobs, args=(jobs, opts), daemon=True)
        self.worker.start()

    def stop(self):
        self.cancel.set()
        if self.proc and self.proc.poll() is None:
            self.proc.kill()

    def run_jobs(self, jobs, opts):
        total = len(jobs)
        for n, iid in enumerate(jobs):
            if self.cancel.is_set():
                self.events.put(('state', iid, 'Cancelado', '', 'err'))
                continue
            try:
                self.convert(iid, opts, n, total)
            except Exception as e:  # nunca deja la interfaz colgada
                self.events.put(('state', iid, 'Error', str(e)[:120], 'err'))
        self.events.put(('done',))

    def convert(self, iid, opts, n, total):
        """Convierte un video a cada tamaño elegido (1 o 2 archivos) y muestra el resultado."""
        item = self.items[iid]
        src, info = item['path'], item['info']
        outdir = opts['outdir'] or os.path.join(os.path.dirname(src), 'optimizados')
        os.makedirs(outdir, exist_ok=True)
        sizes = opts['sizes']
        results = []
        for k, (w, h) in enumerate(sizes):
            # Con dos versiones el nombre dice cuál es cuál
            tag = '' if len(sizes) == 1 else ('_horizontal' if w > h else '_vertical')
            out = unique_path(os.path.join(outdir, os.path.splitext(os.path.basename(src))[0] + SUFFIX + tag + '.mp4'))
            fit = resolve_fit(opts['fit'], info, w, h)
            # progreso total: este video ocupa 1/total, repartido entre sus versiones
            part = (n + k / len(sizes)) / total, 1 / (total * len(sizes))
            res = self.encode(iid, src, info, w, h, fit, opts, out, part)
            if res is None:
                return  # error o cancelado: ya se mostró
            results.append(res)
        tag = 'ok' if all(r[1] for r in results) else 'err'
        state = 'Listo ✓ cumple' if tag == 'ok' else 'No cumple'
        self.events.put(('state', iid, state, '  |  '.join(r[0] for r in results), tag))

    def encode(self, iid, src, info, w, h, fit, opts, out, part):
        """Ejecuta ffmpeg para una versión. Devuelve (descripción, cumple) o None si falló/canceló."""
        tmp = out[:-4] + '.parcial.mp4'
        cmd = build_command(self.ffmpeg, src, info, w, h, fit, opts['quality'], opts['audio'], tmp)
        label = '%s %dx%d' % ('horizontal' if w > h else 'vertical', w, h)
        self.events.put(('state', iid, 'Optimizando 0 %', label, ''))
        self.proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
                                     encoding='utf-8', errors='replace', creationflags=NO_WINDOW)
        err_tail = []

        def read_errors(stream):
            # Vacía stderr (si se llena, ffmpeg se bloquea) y guarda las últimas líneas para el mensaje de error
            for line in stream:
                err_tail.append(line)
                del err_tail[:-15]

        threading.Thread(target=read_errors, args=(self.proc.stderr,), daemon=True).start()
        dur = info['duration'] or 0
        for line in self.proc.stdout:
            if line.startswith('out_time_us=') and dur:
                try:
                    t = int(line.split('=')[1]) / 1e6
                except ValueError:
                    continue
                pct = max(0.0, min(100.0, t / dur * 100))
                self.events.put(('progress', iid, pct, (part[0] + part[1] * pct / 100) * 100))
        code = self.proc.wait()
        if self.cancel.is_set() or code != 0:
            try:
                os.remove(tmp)
            except OSError:
                pass
            if self.cancel.is_set():
                self.events.put(('state', iid, 'Cancelado', '', 'err'))
            else:
                msg = next((l.strip() for l in reversed(err_tail) if l.strip()), 'ffmpeg terminó con error %d' % code)
                self.events.put(('state', iid, 'Error', msg[:150], 'err'))
            return None
        os.replace(tmp, out)
        self.last_output = os.path.dirname(out)
        return describe_output(out, fit)

    # ---- eventos del hilo de trabajo -> interfaz
    def pump(self):
        try:
            while True:
                ev = self.events.get_nowait()
                kind = ev[0]
                if kind == 'probed':
                    _, iid, info = ev
                    if iid not in self.items:
                        continue
                    self.items[iid]['info'] = info
                    if not info:
                        self.tree.set(iid, 'original', 'no es un video válido')
                        self.tree.set(iid, 'estado', 'Se omitirá')
                        self.tree.item(iid, tags=('err',))
                    else:
                        size = human_size(os.path.getsize(self.items[iid]['path']))
                        fps = ('%.2f' % info['fps']).rstrip('0').rstrip('.') if info['fps'] else '?'
                        self.tree.set(iid, 'original', '%sx%s · %s fps · %s · %s' % (
                            info['width'], info['height'], fps, info['codec'], size))
                elif kind == 'progress':
                    _, iid, pct, overall = ev
                    self.tree.set(iid, 'estado', 'Optimizando %d %%' % pct)
                    self.progress['value'] = overall
                    self.status.configure(text='Total %d %%' % overall)
                elif kind == 'state':
                    _, iid, state, result, tag = ev
                    if iid in self.items:
                        self.tree.set(iid, 'estado', state)
                        self.tree.set(iid, 'resultado', result)
                        self.tree.item(iid, tags=(tag,) if tag else ())
                        self.tree.see(iid)
                elif kind == 'done':
                    self.worker = None
                    self.proc = None
                    self.set_busy(False)
                    if not self.cancel.is_set():
                        self.progress['value'] = 100
                    states = [self.tree.set(i, 'estado') for i in self.tree.get_children()]
                    ok = sum(1 for s in states if s.startswith('Listo'))
                    self.status.configure(text='%d de %d listos' % (ok, len(states)))
                    if self.last_output:
                        self.btn_open.state(['!disabled'])
        except queue.Empty:
            pass
        self.root.after(150, self.pump)

    def on_close(self):
        if self.worker and not messagebox.askyesno(APP_TITLE, 'Hay una conversión en curso. ¿Cancelarla y salir?'):
            return
        self.stop()
        self.root.destroy()


def main():
    if os.name == 'nt':
        try:  # texto nítido en pantallas con escala (125 %, 150 %)
            import ctypes
            ctypes.windll.shcore.SetProcessDpiAwareness(1)
        except Exception:
            pass
    root = tk.Tk()
    App(root, sys.argv[1:])
    root.mainloop()


if __name__ == '__main__':
    main()

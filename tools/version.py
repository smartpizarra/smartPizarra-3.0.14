# Versión del archivo: 1.0.0 (2026-11-12)
"""
VERSIÓN POR ARCHIVO del proyecto Smart Pizarra (2026-11-12).

Cada archivo de texto del proyecto lleva al principio una línea con su
versión y la fecha de su último cambio:

    Versión del archivo: 1.0.0 (2026-11-12)

dentro del comentario propio de su tipo:
    .html  <!-- ... -->  en la línea 2 (justo después de <!doctype html>)
    .php   // ...        en la línea 2 (justo después de <?php)
    .sql   -- ...        en la línea 1
    .md    <!-- ... -->  en la línea 1 (no se ve al mostrar el Markdown)
    .py, .gitignore, .htaccess   # ...   en la línea 1

REGLA: cada vez que se modifica un archivo, su versión sube en el último
número (1.0.0 -> 1.0.1) y la fecha pasa a la del día. Se sube UNA vez por
cambio (por petición), aunque el archivo se edite varias veces en ella.

USO (desde la raíz del proyecto):
    python tools/version.py init               pone 1.0.0 a los que no la tienen
    python tools/version.py bump a.html b.php  sube la versión de esos archivos
    python tools/version.py changed            sube la de los modificados según git
                                               (git status: modificados y nuevos)
    python tools/version.py list               muestra la versión de cada archivo

Conserva los saltos de línea de cada archivo (CRLF o LF) y la codificación UTF-8.
"""
import datetime
import io
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LABEL = 'Versión del archivo'
VERSION_RE = re.compile(LABEL + r': (\d+)\.(\d+)\.(\d+) \((\d{4}-\d\d-\d\d)\)')
# Archivos binarios o que no llevan versión
SKIP_EXT = {'.jpeg', '.jpg', '.png', '.gif', '.webp', '.mp4', '.webm', '.ico', '.zip'}


def today():
    return datetime.date.today().isoformat()


def stamp(version, date):
    return '%s: %s (%s)' % (LABEL, version, date)


def comment(path, text):
    """Línea de comentario con el texto, según el tipo de archivo."""
    ext = os.path.splitext(path)[1].lower()
    name = os.path.basename(path)
    if ext in ('.html', '.htm', '.md'):
        return '<!-- %s -->' % text
    if ext == '.php':
        return '// %s' % text
    if ext == '.sql':
        return '-- %s' % text
    if ext == '.py' or name in ('.gitignore', '.htaccess'):
        return '# %s' % text
    if ext in ('.js', '.css'):
        return '/* %s */' % text
    return None


def read(path):
    raw = io.open(path, encoding='utf-8', newline='').read()
    nl = '\r\n' if '\r\n' in raw else '\n'
    return raw.replace('\r\n', '\n'), nl


def write(path, text, nl):
    io.open(path, 'w', encoding='utf-8', newline='').write(text.replace('\n', nl))


def project_files():
    out = subprocess.run(['git', 'ls-files'], cwd=ROOT, capture_output=True, text=True, encoding='utf-8').stdout.split('\n')
    return [f for f in out if f and os.path.splitext(f)[1].lower() not in SKIP_EXT]


def init_file(rel):
    """Pone la versión 1.0.0 si el archivo no la tiene. @return texto del resultado."""
    path = os.path.join(ROOT, rel)
    line = comment(rel, stamp('1.0.0', today()))
    if line is None:
        return 'sin tipo de comentario conocido: no se toca'
    text, nl = read(path)
    if VERSION_RE.search(text[:600]):
        return 'ya tenía versión'
    lines = text.split('\n')
    first = lines[0].strip().lower() if lines else ''
    # HTML: después de <!doctype>; PHP: después de <?php (debe ser lo primero)
    at = 1 if first.startswith('<!doctype') or first.startswith('<?php') else 0
    lines.insert(at, line)
    write(path, '\n'.join(lines), nl)
    return 'versión 1.0.0'


def bump_file(rel):
    """Sube el último número de la versión y pone la fecha de hoy (o la crea)."""
    path = os.path.join(ROOT, rel)
    if not os.path.isfile(path):
        return 'no existe'
    text, nl = read(path)
    m = VERSION_RE.search(text[:600])
    if not m:
        return init_file(rel) + ' (no tenía)'
    new = '%s.%s.%d' % (m.group(1), m.group(2), int(m.group(3)) + 1)
    text = text[:m.start()] + stamp(new, today()) + text[m.end():]
    write(path, text, nl)
    return '%s.%s.%s -> %s' % (m.group(1), m.group(2), m.group(3), new)


def changed_files():
    out = subprocess.run(['git', 'status', '--porcelain'], cwd=ROOT, capture_output=True, text=True, encoding='utf-8').stdout
    files = []
    for line in out.split('\n'):
        if len(line) > 3 and line[:2].strip() and 'D' not in line[:2]:
            name = line[3:].strip().strip('"')
            if ' -> ' in name:
                name = name.split(' -> ')[1]
            if os.path.splitext(name)[1].lower() not in SKIP_EXT and not name.endswith('/'):
                files.append(name)
    return files


def main(argv):
    if len(argv) < 2 or argv[1] not in ('init', 'bump', 'changed', 'list'):
        print(__doc__)
        return 1
    cmd = argv[1]
    if cmd == 'init':
        for f in project_files():
            print('%-55s %s' % (f, init_file(f)))
    elif cmd == 'bump':
        for f in argv[2:]:
            print('%-55s %s' % (f, bump_file(f)))
    elif cmd == 'changed':
        for f in changed_files():
            print('%-55s %s' % (f, bump_file(f)))
    else:
        for f in project_files():
            text, _ = read(os.path.join(ROOT, f))
            m = VERSION_RE.search(text[:600])
            print('%-55s %s' % (f, '%s.%s.%s (%s)' % m.groups() if m else '(sin versión)'))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))

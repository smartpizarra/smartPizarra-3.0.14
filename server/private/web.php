<?php
/*
 * =====================================================================
 *  SMART PIZARRA - UTILIDADES DE LAS PÁGINAS WEB
 * =====================================================================
 *  Comunes al panel del administrador (public_html/admin/index.php) y a
 *  la página de los usuarios (public_html/cuenta/index.php):
 *    - startSecurePage()  sesión con cookie segura + cabeceras (CSP con nonce)
 *    - h(), param()        escapar HTML y leer parámetros sin avisos
 *    - csrfToken/Field()   token anti-CSRF por sesión
 *    - redirectWith()      mensaje "flash" + Post/Redirect/Get
 *    - ago/agoTag/dateTag/onlineTag  fechas UTC => texto
 *    - formatBytes()       tamaño legible (KB / MB / GB)
 *    - baseStyles(), timeScript()    CSS y JS compartidos
 *    - overlay*()          tiras y banners de mirror.html (formulario, datos, vista previa)
 *  Va en private/ junto a lib.php.
 */

declare(strict_types=1);

/**
 * Abre la sesión (cookie solo para $path, HttpOnly, SameSite=Strict,
 * Secure con HTTPS) y envía las cabeceras de seguridad.
 * @param string $sessionName Nombre de la cookie (distinto por página: admin y usuarios no comparten sesión).
 * @param string $path        Ruta de la cookie, p. ej. "/admin/".
 * @param string $extraScript Origen extra permitido para scripts (p. ej. el CDN del lector de QR) o "".
 * @return string Nonce para las etiquetas <style> y <script> de la página.
 */
function startSecurePage(string $sessionName, string $path, string $extraScript = ''): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    $nonce = base64_encode(random_bytes(16));
    header("Content-Security-Policy: default-src 'self'; script-src 'nonce-$nonce'"
        . ($extraScript !== '' ? ' ' . $extraScript : '') . '; '
        . "style-src 'nonce-$nonce'; img-src 'self' data:; media-src 'self' blob:; connect-src 'self'; "
        . "frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    return $nonce;
}

/** Escapa texto para HTML. */
function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Lee un parámetro de $_GET/$_POST como texto. Si no existe o llega como
 * lista (p. ej. "q[]=x"), devuelve "" (evita avisos "Array to string").
 * @param array<string,mixed> $src $_GET o $_POST.
 */
function param(array $src, string $key): string
{
    $v = $src[$key] ?? '';
    return is_string($v) ? $v : '';
}

/** Token CSRF de la sesión. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Campo oculto con el token CSRF. */
function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrfToken()) . '">';
}

/** Guarda un mensaje para la siguiente página y redirige (Post/Redirect/Get). */
function redirectWith(string $message, string $query = ''): never
{
    $_SESSION['flash'] = $message;
    header('Location: ./' . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

/** Fecha UTC de la base ("Y-m-d H:i:s") => segundos unix (0 si no hay). */
function utcTs(?string $utc): int
{
    return $utc ? (int) strtotime($utc . ' UTC') : 0;
}

/**
 * Fecha UTC => texto relativo ("hace 5 min", "hace 3 d").
 * El mismo cálculo existe en JavaScript (agoText de timeScript) para refrescarlo.
 */
function ago(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    $d = max(0, time() - utcTs($utc));
    if ($d < 60) {
        return 'hace ' . $d . ' s';
    }
    if ($d < 3600) {
        return 'hace ' . intdiv($d, 60) . ' min';
    }
    if ($d < 86400) {
        return 'hace ' . intdiv($d, 3600) . ' h';
    }
    return 'hace ' . intdiv($d, 86400) . ' d';
}

/** Texto relativo que timeScript() recalcula cada 30 s. */
function agoTag(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    return '<span data-ago="' . utcTs($utc) . '">' . h(ago($utc)) . '</span>';
}

/**
 * Fecha y hora completas. El servidor la escribe en UTC y timeScript()
 * la pasa a la hora local del navegador.
 */
function dateTag(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    $t = utcTs($utc);
    return '<time datetime="' . gmdate('Y-m-d\TH:i:s\Z', $t) . '" data-ts="' . $t . '">'
        . h(gmdate('d/m/Y H:i', $t)) . ' UTC</time>';
}

/**
 * Estado del equipo según su última conexión (online_at).
 * @param int $timeout Segundos sin señal para considerarlo desconectado.
 * @return string HTML: "En línea · hace X", "Desconectado · hace X" o "Sin conexión registrada".
 */
function onlineTag(?string $utc, int $timeout): string
{
    if (!$utc) {
        return '<span class="online never">Sin conexión registrada</span>';
    }
    $t = utcTs($utc);
    $on = time() - $t < $timeout;
    return '<span class="online ' . ($on ? 'on' : 'off') . '" data-online="' . $t . '">'
        . ($on ? 'En línea · ' : 'Desconectado · ')
        . '<span data-ago="' . $t . '">' . h(ago($utc)) . '</span></span>';
}

/** Tamaño legible: 1.5 MB, 820 KB... */
function formatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
}

/**
 * Vigencia del contenido de un usuario, para mostrarla.
 * @param string|null $until content_until (UTC).
 * @return array{state:string,days:int,text:string} state: active | expired | none
 */
function contentValidity(?string $until): array
{
    if (!$until) {
        return ['state' => 'none', 'days' => 0, 'text' => 'Sin vigencia asignada'];
    }
    $left = utcTs($until) - time();
    if ($left <= 0) {
        return ['state' => 'expired', 'days' => 0, 'text' => 'Vencida el ' . dateTag($until)];
    }
    $days = (int) ceil($left / 86400);
    return ['state' => 'active', 'days' => $days,
        'text' => 'Vigente hasta el ' . dateTag($until) . ' (' . ($days === 1 ? 'queda 1 día' : 'quedan ' . $days . ' días') . ')'];
}

/**
 * Fecha de un <input type="datetime-local"> (hora del navegador del
 * administrador o del usuario) a UTC para la base. $tz = getTimezoneOffset() del
 * navegador en minutos (UTC - local); sin JS llega 0 y se toma como UTC.
 * @return string|null "Y-m-d H:i:s" UTC, null si está vacía, false si no es válida.
 */
function localToUtc(string $value, int $tz): string|null|false
{
    if (trim($value) === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', substr(trim($value), 0, 16), new DateTimeZone('UTC'));
    if ($dt === false) {
        return false;
    }
    $tz = max(-900, min(900, $tz));
    return $dt->modify(($tz >= 0 ? '+' : '') . $tz . ' minutes')->format('Y-m-d H:i:s');
}

/** Segundos sin señal para considerar un equipo desconectado (config, mín. 30). */
function onlineTimeout(array $config): int
{
    return max(30, (int) ($config['online_timeout_seconds'] ?? 180));
}

/** CSS común (colores con modo oscuro, tarjetas, formularios, insignias). */
function baseStyles(): string
{
    return <<<'CSS'
    :root {
      --bg: #f4f6fb; --card: #fff; --text: #1b1f2a; --muted: #5d6578; --line: #dfe3ec;
      --accent: #1a56db; --ok: #157347; --warn: #b45309; --bad: #b42318;
    }
    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #11141b; --card: #1a1f2b; --text: #e7eaf1; --muted: #9aa3b5; --line: #2c3344;
        --accent: #6c9bff; --ok: #4ade80; --warn: #fbbf24; --bad: #f87171;
      }
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text);
      font: 16px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
    main { max-width: 860px; margin: 0 auto; padding: 16px; }
    header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
    h1 { font-size: 1.3rem; margin: 0; }
    h2 { font-size: 1.1rem; margin: 0 0 8px; }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px; }
    .flash { border-color: var(--accent); margin-bottom: 12px; }
    .error { border-color: var(--bad); color: var(--bad); margin-bottom: 12px; }
    .secret { font: 600 1.15rem ui-monospace, Consolas, monospace; letter-spacing: 1px; user-select: all; }
    label { display: block; font-size: .9rem; color: var(--muted); margin: 10px 0 4px; }
    input[type=text], input[type=password], input[type=search], input[type=email], input[type=tel] {
      width: 100%; padding: 10px 12px; font: inherit; color: var(--text);
      background: var(--bg); border: 1px solid var(--line); border-radius: 8px; }
    button, .btn { font: inherit; padding: 9px 14px; border-radius: 8px; border: 1px solid var(--line);
      background: var(--card); color: var(--text); cursor: pointer; text-decoration: none; display: inline-block; }
    button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
    button.ok { color: var(--ok); border-color: var(--ok); }
    button.bad { color: var(--bad); border-color: var(--bad); }
    .device { margin-bottom: 10px; }
    .device-head { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; align-items: baseline; }
    .device-id { font: 600 1.05rem ui-monospace, Consolas, monospace; letter-spacing: .5px; }
    .alias { font-weight: 600; font-size: 1.05rem; margin-right: 8px; }
    .badge { font-size: .8rem; padding: 2px 10px; border-radius: 999px; border: 1px solid currentColor; }
    .badge.pending { color: var(--warn); } .badge.authorized { color: var(--ok); } .badge.revoked { color: var(--bad); }
    .meta { color: var(--muted); font-size: .85rem; margin: 6px 0 10px; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .actions form { margin: 0; }
    .online { font-size: .85rem; white-space: nowrap; }
    .online::before { content: ""; display: inline-block; width: 8px; height: 8px; border-radius: 50%;
      margin-right: 6px; background: currentColor; vertical-align: middle; }
    .online.on { color: var(--ok); } .online.off { color: var(--bad); } .online.never { color: var(--muted); }
    .empty { color: var(--muted); text-align: center; padding: 24px; }
    /* Buscador de las listas de dispositivos al asignar (deviceFilterBox / deviceFilterScript) */
    .device-filter { display: block; width: 100%; margin: 6px 0 4px; }
    .device-hidden { display: none !important; }
CSS;
}

/**
 * BUSCADOR DE DISPOSITIVOS al ASIGNAR (2026-11-12): caja que se pone al
 * principio de una lista de casillas de dispositivos. deviceFilterScript()
 * oculta las casillas (label[data-search]) del MISMO contenedor que no
 * coinciden. Las casillas marcadas que se ocultan SIGUEN marcadas.
 */
function deviceFilterBox(string $placeholder = 'Buscar dispositivo por nombre, ID o MAC del case'): string
{
    return '<input type="search" class="device-filter" data-device-filter placeholder="' . h($placeholder) . '" aria-label="Buscar dispositivo">'
        . '<span class="hint device-hidden" data-device-filter-none>Ningún dispositivo coincide.</span>';
}

/**
 * Atributo data-search de una casilla: textos en minúsculas y, de cada MAC,
 * también su versión SIN separadores (para buscar "e8bb3e", "e8-bb" o "fb5a").
 * @param string[] $texts Textos (nombre, ID, alias...).
 * @param string[] $macs  MACs ("E8:BB:3E:C8:FB:5A").
 */
function deviceSearchAttr(array $texts, array $macs = []): string
{
    $parts = array_map('strval', array_filter($texts, static fn($v) => $v !== null && $v !== ''));
    foreach ($macs as $m) {
        if ($m !== null && $m !== '') {
            $parts[] = (string) $m;
            $parts[] = str_replace(':', '', (string) $m);
        }
    }
    return ' data-search="' . h(mb_strtolower(implode(' ', $parts))) . '"';
}

/** JS de deviceFilterBox(): filtra mientras se escribe (sin recargar la página). */
function deviceFilterScript(): string
{
    return <<<'JS'
  (function () {
    document.querySelectorAll("input[data-device-filter]").forEach(function (box) {
      var list = box.parentNode;
      var none = list.querySelector("[data-device-filter-none]");
      var items = list.querySelectorAll("label[data-search]");
      function apply() {
        var q = box.value.trim().toLowerCase();
        var hex = q.replace(/[\s:.\-]/g, "");
        var useHex = hex.length >= 2 && /^[0-9a-f]+$/.test(hex);
        var shown = 0;
        items.forEach(function (it) {
          var text = it.getAttribute("data-search");
          var ok = q === "" || text.indexOf(q) !== -1 || (useHex && text.indexOf(hex) !== -1);
          it.classList.toggle("device-hidden", !ok);
          if (ok) shown++;
        });
        if (none) none.classList.toggle("device-hidden", shown > 0 || items.length === 0);
      }
      box.addEventListener("input", apply);
      // Enter dentro de la caja NO envía el formulario de asignación
      box.addEventListener("keydown", function (e) { if (e.key === "Enter") e.preventDefault(); });
    });
  })();
JS;
}

/**
 * JavaScript común de fechas:
 *  - <time data-ts> => fecha y hora LOCAL del navegador (el servidor la da en UTC).
 *  - [data-ago]     => "hace X", recalculado cada 30 s (mismo cálculo que ago()).
 *  - [data-online]  => En línea / Desconectado según el timeout (segundos).
 *  - form[data-confirm] => pide confirmación antes de enviar.
 * SKEW corrige la diferencia entre el reloj del navegador y el del servidor.
 */
function timeScript(int $onlineTimeout): string
{
    $js = <<<'JS'
  (function () {
    "use strict";
    document.querySelectorAll("form[data-confirm]").forEach(function (f) {
      f.addEventListener("submit", function (e) {
        if (!window.confirm(f.getAttribute("data-confirm"))) e.preventDefault();
      });
    });
    var ONLINE_TIMEOUT = __TIMEOUT__;
    var SKEW = __NOW__ - Math.floor(Date.now() / 1000);
    function nowTs() { return Math.floor(Date.now() / 1000) + SKEW; }
    function agoText(ts) {
      var d = Math.max(0, nowTs() - ts);
      if (d < 60) return "hace " + d + " s";
      if (d < 3600) return "hace " + Math.floor(d / 60) + " min";
      if (d < 86400) return "hace " + Math.floor(d / 3600) + " h";
      return "hace " + Math.floor(d / 86400) + " d";
    }
    function pad(n) { return (n < 10 ? "0" : "") + n; }
    document.querySelectorAll("time[data-ts]").forEach(function (el) {
      var dt = new Date(+el.getAttribute("data-ts") * 1000);
      el.textContent = pad(dt.getDate()) + "/" + pad(dt.getMonth() + 1) + "/" + dt.getFullYear()
        + " " + pad(dt.getHours()) + ":" + pad(dt.getMinutes());
    });
    setInterval(function () {
      document.querySelectorAll("[data-ago]").forEach(function (el) {
        el.textContent = agoText(+el.getAttribute("data-ago"));
      });
      document.querySelectorAll("[data-online]").forEach(function (el) {
        var on = nowTs() - (+el.getAttribute("data-online")) < ONLINE_TIMEOUT;
        el.className = "online " + (on ? "on" : "off");
        el.firstChild.nodeValue = on ? "En línea · " : "Desconectado · "; // nodo de texto inicial
      });
    }, 30000);
  })();
JS;
    return strtr($js, ['__TIMEOUT__' => (string) $onlineTimeout, '__NOW__' => (string) time()]);
}

/* =====================================================================
   TIRAS Y BANNERS (mirror.html): formulario, datos y vista previa
   comunes al panel del administrador y a /cuenta/. El DESTINO (equipos,
   usuarios) y las FECHAS los pone cada página con sus propios campos.
   Ver OverlayRepository (lib.php) y api/media.php.
   ===================================================================== */

/** Textos de las opciones (paneles). */
const OVERLAY_LABELS = [
    'kind' => ['text' => 'Tira de texto', 'image' => 'Banner (imagen)'],
    'place' => ['top' => 'Arriba', 'bottom' => 'Abajo'],
    'size' => ['s' => 'Pequeña', 'm' => 'Mediana', 'l' => 'Grande'],
    'motion' => ['static' => 'Fija', 'scroll' => 'Desplazándose'],
    'speed' => ['slow' => 'Lenta', 'normal' => 'Normal', 'fast' => 'Rápida'],
    'fit' => ['contain' => 'Proporcional (sin deformar)', 'stretch' => 'Estirar a todo el ancho'],
];

/** Grupo de botones de radio de una opción de OVERLAY_LABELS. */
function overlayRadios(string $field, string $name, string $value): string
{
    $html = '';
    foreach (OVERLAY_LABELS[$field] as $k => $text) {
        $html .= '<label class="inline"><input type="radio" name="' . $name . '" value="' . $k . '"'
            . ($value === $k ? ' checked' : '') . '> ' . $text . '</label>';
    }
    return $html;
}

/**
 * Campos de estilo de una tira/banner: tipo (solo al crear), nombre,
 * imagen (banner nuevo), mensaje, colores, movimiento, velocidad,
 * tamaño, posición y una VISTA PREVIA que el JS (overlayScript) actualiza.
 * @param array<string,mixed>|null $o Tira/banner a editar (null = nuevo).
 * @param string $p Prefijo único para los id de los campos.
 */
function overlayFields(?array $o, string $p): string
{
    $kind = (string) ($o['kind'] ?? 'text');
    $html = '<div class="ov-form" data-ov-form>';
    if ($o === null) {
        $html .= '<fieldset class="targets"><legend>Tipo</legend>' . overlayRadios('kind', 'kind', 'text') . '</fieldset>';
    } else {
        // El tipo no se cambia al editar (un banner lleva su imagen)
        $html .= '<input type="hidden" name="kind" value="' . h($kind) . '">';
    }
    $html .= '<label for="' . $p . 'name">Nombre (solo para el panel)</label>'
        . '<input type="text" id="' . $p . 'name" name="name" maxlength="100" value="' . h((string) ($o['name'] ?? '')) . '" placeholder="p. ej. Oferta del día">';
    // Banner: imagen (solo al crear)
    if ($o === null) {
        $html .= '<div data-ov-only="image"><label for="' . $p . 'file">Imagen del banner</label>'
            . '<input type="file" id="' . $p . 'file" name="file" accept="' . implode(',', OverlayRepository::IMAGE_TYPES) . '">'
            . '<span class="hint">JPG, PNG, WEBP o GIF de hasta ' . h(formatBytes(OverlayRepository::maxBytes()))
            . '. Mejor una imagen ANCHA (p. ej. 1920×160).</span></div>';
    }
    // Banner: ajuste de la imagen (al crear y al editar)
    $html .= '<fieldset class="targets" data-ov-only="image"><legend>Ajuste de la imagen</legend>'
        . overlayRadios('fit', 'fit', (string) ($o['image_fit'] ?? 'contain'))
        . '<br><span class="hint">Proporcional: no se deforma, pero si la imagen no es lo bastante ancha queda centrada. '
        . 'Estirar: llena todo el ancho de la pantalla (se deforma en horizontal).</span></fieldset>';
    // Tira: mensaje
    $html .= '<div data-ov-only="text"><label for="' . $p . 'message">Mensaje</label>'
        . '<input type="text" id="' . $p . 'message" name="message" maxlength="' . OverlayRepository::MAX_MESSAGE . '" value="'
        . h((string) ($o['message'] ?? '')) . '" placeholder="p. ej. 2x1 en pizzas todos los martes" data-ov-message></div>';
    // Colores (la letra solo en tiras)
    $html .= '<div class="ov-row">'
        . '<div><label for="' . $p . 'bg">Color de fondo</label><input type="color" id="' . $p . 'bg" name="bg" value="' . h(strtolower((string) ($o['bg_color'] ?? '#000000'))) . '" data-ov-bg></div>'
        . '<div data-ov-only="text"><label for="' . $p . 'fg">Color de la letra</label><input type="color" id="' . $p . 'fg" name="fg" value="' . h(strtolower((string) ($o['fg_color'] ?? '#ffffff'))) . '" data-ov-fg></div>'
        . '</div>';
    $html .= '<fieldset class="targets" data-ov-only="text"><legend>Movimiento</legend>' . overlayRadios('motion', 'motion', (string) ($o['motion'] ?? 'static'))
        . '<div data-ov-speed><span class="hint">Velocidad:</span> ' . overlayRadios('speed', 'speed', (string) ($o['speed'] ?? 'normal')) . '</div></fieldset>';
    $html .= '<fieldset class="targets"><legend>Posición en la pantalla</legend>' . overlayRadios('place', 'place', (string) ($o['place'] ?? 'bottom'))
        . '<br><span class="hint">Tamaño de la franja:</span> ' . overlayRadios('size', 'size', (string) ($o['band_size'] ?? 'm')) . '</fieldset>';
    // Vista previa (pantalla en miniatura): la actualiza el JS al cambiar los campos
    $html .= '<div class="hint">Vista previa</div><div class="ov-screen" data-ov-preview data-kind="' . h($kind) . '"'
        . ($o !== null && $kind === 'image' ? ' data-img="' . h((string) ($o['_img'] ?? '')) . '"' : '') . '>'
        . '<div class="ov-band"><span class="ov-text"></span></div><div class="ov-content">Contenido</div></div>';
    return $html . '</div>';
}

/**
 * Datos de estilo de una tira/banner desde el formulario.
 * @param array<string,mixed>|null $o Tira/banner que se edita (null = nuevo: el tipo viene del formulario).
 * @return array{kind:string,name:string,message:?string,bg:string,fg:string,motion:string,speed:string,size:string,place:string}|string
 *         Los datos, o el texto del error.
 */
function overlayStyleData(?array $o, string $fallbackName = ''): array|string
{
    $kind = $o !== null ? (string) $o['kind'] : OverlayRepository::pick(param($_POST, 'kind'), OverlayRepository::KINDS);
    $message = OverlayRepository::cleanText(param($_POST, 'message'), OverlayRepository::MAX_MESSAGE);
    if ($kind === 'text' && $message === null) {
        return 'Escribe el mensaje de la tira.';
    }
    $name = OverlayRepository::cleanText(param($_POST, 'name'), 100)
        ?? ($kind === 'text' ? mb_substr((string) $message, 0, 40) : OverlayRepository::cleanText($fallbackName, 100))
        ?? ($kind === 'text' ? 'Tira' : 'Banner');
    return [
        'kind' => $kind,
        'name' => $name,
        'message' => $kind === 'text' ? $message : null,
        'bg' => OverlayRepository::cleanColor(param($_POST, 'bg'), '#000000'),
        'fg' => OverlayRepository::cleanColor(param($_POST, 'fg'), '#FFFFFF'),
        'motion' => OverlayRepository::pick(param($_POST, 'motion'), OverlayRepository::MOTIONS),
        'speed' => OverlayRepository::pick(param($_POST, 'speed'), ['normal', 'slow', 'fast']),
        'size' => OverlayRepository::pick(param($_POST, 'size'), ['m', 's', 'l']),
        'place' => OverlayRepository::pick(param($_POST, 'place'), ['bottom', 'top']),
        // Banner: proporcional o estirado a todo el ancho
        'fit' => OverlayRepository::pick(param($_POST, 'fit'), OverlayRepository::FITS),
    ];
}

/**
 * Imagen subida para un banner nuevo (campo "file"), ya validada.
 * @return array{0:string,1:string,2:string}|string [tmp, nombre, mime] o el texto del error.
 */
function overlayUpload(): array|string
{
    $f = $_FILES['file'] ?? null;
    $err = is_array($f) && is_int($f['error'] ?? null) ? $f['error'] : UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return 'La imagen supera el límite de PHP (upload_max_filesize: ' . ini_get('upload_max_filesize') . ').';
    }
    if ($err !== UPLOAD_ERR_OK) {
        return $err === UPLOAD_ERR_NO_FILE ? 'Elige la imagen del banner.' : 'La subida falló (código ' . $err . '). Inténtalo de nuevo.';
    }
    if ((int) $f['size'] > OverlayRepository::maxBytes()) {
        return 'La imagen supera el máximo de ' . formatBytes(OverlayRepository::maxBytes()) . '.';
    }
    $mime = MediaRepository::detectMime((string) $f['tmp_name']);
    if ($mime === null || !in_array($mime, OverlayRepository::IMAGE_TYPES, true)) {
        return 'Para un banner usa una imagen JPG, PNG, WEBP o GIF.';
    }
    return [(string) $f['tmp_name'], (string) $f['name'], $mime];
}

/**
 * Nombre y concordancia para los mensajes: "Tira «x» creada" / "Banner «x» creado".
 * @return array{0:string,1:string} [Tira|Banner, a|o]
 */
function overlayNoun(string $kind): array
{
    return $kind === 'text' ? ['Tira', 'a'] : ['Banner', 'o'];
}

/** Texto del aviso tras asignar: las otras tiras/banners a las que se quitaron esos equipos. */
function overlayExclusiveText(array $changed): string
{
    return $changed ? ' Cada equipo muestra una sola: se quitó de esos equipos a «' . implode('», «', $changed) . '».' : '';
}

/** ¿Está asignada a algún equipo? (scope none = sin asignar: no se ve en ninguna pantalla) */
function overlayAssigned(array $o): bool
{
    return $o['scope'] !== 'none' && ($o['scope'] === 'all' || !empty($o['users']) || !empty($o['devices']));
}

/** Resumen de una tira/banner para las listas: "Tira · Abajo · Mediana · Desplazándose (normal)". */
function overlaySummary(array $o): string
{
    $L = OVERLAY_LABELS;
    $text = $L['kind'][$o['kind']] . ' · ' . $L['place'][$o['place']] . ' · ' . $L['size'][$o['band_size']];
    if ($o['kind'] === 'text') {
        $text .= ' · ' . $L['motion'][$o['motion']] . ($o['motion'] === 'scroll' ? ' (' . mb_strtolower($L['speed'][$o['speed']]) . ')' : '');
    } else {
        $text .= (($o['image_fit'] ?? 'contain') === 'stretch' ? ' · Estirado a todo el ancho' : ' · Proporcional')
            . ' · ' . formatBytes((int) $o['size_bytes']);
    }
    return $text;
}

/**
 * Vista previa fija de una tira/banner (en las listas). Los colores los
 * aplica el JS (la CSP no permite style="" en línea).
 * @param string $imgUrl URL de la imagen del banner (vista previa del panel) o "".
 */
function overlayPreview(array $o, string $imgUrl): string
{
    return '<div class="ov-band ov-static ' . ($o['kind'] === 'text' && $o['motion'] === 'scroll' ? 'ov-scroll' : '') . '"'
        . ' data-bg="' . h((string) $o['bg_color']) . '" data-fg="' . h((string) $o['fg_color']) . '">'
        . ($o['kind'] === 'text'
            ? '<span class="ov-text">' . h((string) $o['message']) . '</span>'
            : '<img src="' . h($imgUrl) . '" alt="" loading="lazy"' . (($o['image_fit'] ?? 'contain') === 'stretch' ? ' class="ov-stretch"' : '') . '>')
        . '</div>';
}

/** CSS de los formularios y vistas previas de tiras/banners. */
function overlayStyles(): string
{
    return <<<'CSS'
    /* Tiras y banners */
    .ov-row { display: flex; gap: 16px; flex-wrap: wrap; }
    input[type=color] { width: 64px; height: 38px; padding: 2px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); cursor: pointer; }
    [data-ov-speed] { margin-top: 4px; }
    .ov-screen { position: relative; width: 100%; max-width: 420px; aspect-ratio: 16 / 9; background: #222; border-radius: 8px;
      overflow: hidden; display: flex; flex-direction: column; margin: 4px 0 12px; }
    .ov-screen .ov-content { flex: 1 1 auto; display: flex; align-items: center; justify-content: center; color: #777; font-size: .85rem;
      background: repeating-linear-gradient(45deg, #2a2a2a 0 10px, #262626 10px 20px); }
    .ov-screen.top .ov-band { order: 0; } .ov-screen.top .ov-content { order: 1; }
    .ov-screen.bottom .ov-band { order: 1; } .ov-screen.bottom .ov-content { order: 0; }
    .ov-screen.s .ov-band { height: 9%; } .ov-screen.m .ov-band { height: 12%; } .ov-screen.l .ov-band { height: 17%; }
    .ov-band { position: relative; flex: 0 0 auto; overflow: hidden; display: flex; align-items: center; justify-content: center;
      background: #000; color: #fff; white-space: nowrap; font-weight: 600; }
    .ov-screen .ov-band { font-size: clamp(9px, 2.6vw, 13px); }
    .ov-band img { width: 100%; height: 100%; object-fit: contain; display: block; }
    .ov-band img.ov-stretch { object-fit: fill; } /* banner estirado a todo el ancho */
    .ov-band img[hidden], .ov-band .ov-text[hidden] { display: none; }
    .ov-band .ov-text { padding: 0 6px; overflow: hidden; text-overflow: ellipsis; }
    .ov-band.ov-scroll { justify-content: flex-start; }
    .ov-band.ov-scroll .ov-text { overflow: visible; text-overflow: clip; padding-left: 100%; animation: ov-marquee 9s linear infinite; }
    .ov-band.speed-slow .ov-text { animation-duration: 14s; } .ov-band.speed-fast .ov-text { animation-duration: 5s; }
    @keyframes ov-marquee { from { transform: translateX(0); } to { transform: translateX(-100%); } }
    @media (prefers-reduced-motion: reduce) { .ov-band.ov-scroll .ov-text { animation: none; padding-left: 6px; } }
    /* En las listas: franja de muestra a lo ancho */
    .ov-static { width: 100%; max-width: 420px; height: 34px; border-radius: 6px; font-size: .95rem; margin-bottom: 6px; }
    .owner-tag { font-size: .8rem; color: var(--muted); }
CSS;
}

/**
 * JS de tiras/banners (con el nonce de la página):
 *  - formulario: muestra los campos del tipo elegido (tira / banner), la
 *    velocidad solo si se desplaza, y actualiza la vista previa;
 *  - listas: aplica los colores data-bg / data-fg de cada muestra.
 */
function overlayScript(): string
{
    return <<<'JS'
  (function () {
    "use strict";
    function paint(el) {
      if (el.getAttribute("data-bg")) el.style.background = el.getAttribute("data-bg");
      if (el.getAttribute("data-fg")) el.style.color = el.getAttribute("data-fg");
    }
    document.querySelectorAll(".ov-band[data-bg]").forEach(paint);
    document.querySelectorAll("[data-ov-form]").forEach(function (form) {
      var preview = form.querySelector("[data-ov-preview]");
      var band = preview.querySelector(".ov-band");
      var text = preview.querySelector(".ov-text");
      var img = null;
      function val(name, def) {
        var r = form.querySelector("input[name=" + name + "]:checked") || form.querySelector("input[type=hidden][name=" + name + "]");
        return r ? r.value : def;
      }
      function update() {
        var kind = val("kind", "text");
        var motion = val("motion", "static");
        form.querySelectorAll("[data-ov-only]").forEach(function (el) {
          el.hidden = el.getAttribute("data-ov-only") !== kind;
        });
        var speed = form.querySelector("[data-ov-speed]");
        if (speed) speed.hidden = motion !== "scroll";
        preview.className = "ov-screen " + val("place", "bottom") + " " + val("size", "m");
        band.className = "ov-band" + (kind === "text" && motion === "scroll" ? " ov-scroll speed-" + val("speed", "normal") : "");
        band.style.background = form.querySelector("[data-ov-bg]").value;
        band.style.color = form.querySelector("[data-ov-fg]").value;
        var msg = form.querySelector("[data-ov-message]");
        text.textContent = msg && msg.value.trim() ? msg.value.trim() : "Tu mensaje aquí";
        text.hidden = kind !== "text";
        if (img) {
          img.hidden = kind !== "image";
          img.className = val("fit", "contain") === "stretch" ? "ov-stretch" : "";
        }
      }
      function showImage(src) {
        if (!img) {
          img = document.createElement("img");
          img.alt = "";
          band.appendChild(img);
        }
        img.src = src;
        update();
      }
      // Banner guardado: su imagen; banner nuevo: la elegida en el campo de archivo
      if (preview.getAttribute("data-img")) showImage(preview.getAttribute("data-img"));
      var file = form.querySelector("input[type=file]");
      if (file) {
        file.addEventListener("change", function () {
          if (file.files && file.files[0] && window.URL) showImage(URL.createObjectURL(file.files[0]));
        });
      }
      form.addEventListener("input", update);
      form.addEventListener("change", update);
      update();
    });
  })();
JS;
}

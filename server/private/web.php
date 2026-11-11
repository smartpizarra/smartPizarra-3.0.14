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
CSS;
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

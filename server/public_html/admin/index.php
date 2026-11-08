<?php
/*
 * =====================================================================
 *  SMART PIZARRA - PANEL DE ADMINISTRACIÓN DE DISPOSITIVOS
 *  URL: https://smartpizarra.cloud/admin/
 * =====================================================================
 *  - Entrar con el usuario y la contraseña de private/config.php.
 *  - Ver los equipos registrados (pendientes primero), buscar por ID o
 *    nombre, o ESCANEAR el QR que muestra fingerprint.html con la cámara
 *    del móvil (el QR contiene el ID).
 *  - Autorizar, revocar, poner un ALIAS o eliminar un equipo. El equipo se
 *    entera en su próxima consulta (fingerprint.html la hace cada pocos
 *    segundos mientras espera; loading.html al arrancar la app).
 *  - Cada equipo muestra su fecha de registro en la base y su "Última
 *    conexión": "En línea" si hubo señal en los últimos
 *    online_timeout_seconds o hace cuánto llegó la última. La refrescan el
 *    arranque de loading.html (api/device.php con event=boot) y la señal
 *    de la app nativa (api/ping.php). Los tiempos se recalculan solos cada
 *    30 s sin recargar la página.
 *  - Historial de arranques: cuántas veces se ejecutó loading.html, el
 *    último arranque y, con el botón "Historial" (?history=SP-...), la
 *    lista con fecha/hora, IP y versión de cada arranque.
 *
 *  - GRUPOS (botón "Grupos", ?view=groups): todo equipo pertenece a un
 *    grupo (los nuevos entran en el grupo por defecto, id 1). Se crean,
 *    renombran, habilitan/deshabilitan y eliminan grupos (sus equipos
 *    pasan al grupo por defecto). Deshabilitar un grupo deja sin
 *    autorización a todos sus equipos sin cambiar su estado; para un solo
 *    equipo se usa "Revocar". El grupo de cada equipo se cambia con el
 *    selector de su tarjeta, y la lista se puede filtrar por grupo.
 *
 *  Seguridad: sesión con cookie segura, token CSRF en cada formulario,
 *  límite de intentos de login por IP, consultas preparadas, salida
 *  escapada y CSP con nonce.
 */

declare(strict_types=1);

require __DIR__ . '/../../private/lib.php';

/* ---------------------------------------------------------------------
   Sesión y cabeceras de seguridad
   --------------------------------------------------------------------- */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('sp_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'nonce-$nonce' https://cdn.jsdelivr.net; "
    . "style-src 'nonce-$nonce'; img-src 'self' data:; media-src 'self' blob:; connect-src 'self'; "
    . "frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

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
 * El mismo cálculo existe en JavaScript (agoText) para refrescarlo.
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

/** Texto relativo que el script del panel recalcula cada 30 s. */
function agoTag(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    return '<span data-ago="' . utcTs($utc) . '">' . h(ago($utc)) . '</span>';
}

/**
 * Fecha y hora completas. El servidor la escribe en UTC y el script del
 * panel la pasa a la hora local del navegador.
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
 * Estado de la app nativa según su última señal (api/ping.php).
 * @param int $timeout Segundos sin señal para considerarla desconectada.
 * @return string HTML: "En línea · hace X", "Desconectado · hace X" o "Sin señal de la app".
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

/*
 * Diagnóstico de instalación: si config.php falta o está mal escrito, o la
 * base no responde o le faltan tablas, el panel lo explica en pantalla
 * (sin mostrar valores ni contraseñas) en lugar de dar un error 500 vacío.
 */
$setupError = '';
try {
    $config = AppConfig::get();
    $setupError = Database::diagnose() ?? '';
    if ($setupError === '' && (string) $config['admin_password_hash'] === 'CAMBIAR') {
        $setupError = 'Falta la contraseña del panel: en private/config.php, admin_password_hash sigue diciendo CAMBIAR. '
            . 'Genera el hash como explica config.sample.php y pégalo entre comillas simples.';
    }
} catch (Throwable $e) {
    $config = [];
    $setupError = $e->getMessage();
}
// Segundos sin señal de la app para considerarla desconectada (mín. 30)
$onlineTimeout = max(30, (int) ($config['online_timeout_seconds'] ?? 180));
$loggedIn = !empty($_SESSION['admin']);
$error = '';

try {
    if ($setupError !== '') {
        // Sin configuración válida no se puede hacer nada más
        throw new RuntimeException($setupError);
    }
    /* -----------------------------------------------------------------
       Acciones (POST)
       ----------------------------------------------------------------- */
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = param($_POST, 'action');
        if (!hash_equals(csrfToken(), param($_POST, 'csrf'))) {
            redirectWith('La sesión caducó. Vuelve a intentarlo.');
        }

        if ($action === 'login') {
            $ip = Http::clientIp();
            $bucket = 'login:' . $ip;
            $window = (int) $config['login_window_seconds'];
            if (RateLimiter::count($bucket, $window) >= (int) $config['login_fails_per_ip']) {
                $error = 'Demasiados intentos. Espera unos minutos.';
            } else {
                $hash = (string) $config['admin_password_hash'];
                $userOk = hash_equals((string) $config['admin_user'], param($_POST, 'user'));
                $passOk = $hash !== 'CAMBIAR' && password_verify(param($_POST, 'password'), $hash);
                if ($userOk && $passOk) {
                    RateLimiter::reset($bucket);
                    session_regenerate_id(true);
                    $_SESSION['admin'] = true;
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));
                    redirectWith('Sesión iniciada.');
                }
                RateLimiter::hit($bucket, PHP_INT_MAX, $window);
                $error = 'Usuario o contraseña incorrectos.';
            }
        } elseif (!$loggedIn) {
            redirectWith('Inicia sesión.');
        } elseif ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            header('Location: ./', true, 303);
            exit;
        } elseif (str_starts_with($action, 'group_')) {
            /* ---- Acciones sobre GRUPOS (vista ?view=groups) ---- */
            $gid = (int) param($_POST, 'groupId');
            $group = $gid > 0 ? GroupRepository::find($gid) : null;
            if ($action !== 'group_create' && $group === null) {
                redirectWith('Ese grupo no existe.', 'view=groups');
            }
            switch ($action) {
                case 'group_create':
                case 'group_rename':
                    $name = GroupRepository::cleanName(param($_POST, 'name'));
                    if ($name === null) {
                        redirectWith('El nombre del grupo debe tener entre 1 y 60 caracteres.', 'view=groups');
                    }
                    if (GroupRepository::nameTaken($name, $action === 'group_rename' ? $gid : 0)) {
                        redirectWith("Ya existe un grupo llamado «{$name}».", 'view=groups');
                    }
                    if ($action === 'group_create') {
                        GroupRepository::create($name);
                        redirectWith("Grupo «{$name}» creado.", 'view=groups');
                    }
                    GroupRepository::rename($gid, $name);
                    redirectWith("Grupo renombrado a «{$name}».", 'view=groups');
                case 'group_enable':
                    GroupRepository::setEnabled($gid, true);
                    redirectWith("Grupo «{$group['name']}» habilitado: sus equipos autorizados vuelven a funcionar.", 'view=groups');
                case 'group_disable':
                    GroupRepository::setEnabled($gid, false);
                    redirectWith("Grupo «{$group['name']}» deshabilitado: sus equipos quedan sin autorización "
                        . 'en su próxima consulta.', 'view=groups');
                case 'group_delete':
                    $moved = GroupRepository::delete($gid);
                    redirectWith($moved < 0
                        ? 'El grupo por defecto no se puede eliminar.'
                        : "Grupo «{$group['name']}» eliminado; {$moved} equipo(s) pasan a "
                            . '«' . (GroupRepository::find(GroupRepository::DEFAULT_ID)['name'] ?? 'General') . '».',
                        'view=groups');
                default:
                    redirectWith('Acción desconocida.', 'view=groups');
            }
        } else {
            $id = strtoupper(trim(param($_POST, 'deviceId')));
            $back = param($_POST, 'back');
            if (!DeviceRepository::isValidId($id)) {
                redirectWith('ID de dispositivo inválido.', $back);
            }
            switch ($action) {
                case 'authorize':
                    DeviceRepository::setStatus($id, 'authorized');
                    redirectWith("$id autorizado. El equipo lo detectará en unos segundos.", $back);
                case 'revoke':
                    DeviceRepository::setStatus($id, 'revoked');
                    redirectWith("$id revocado.", $back);
                case 'pending':
                    DeviceRepository::setStatus($id, 'pending');
                    redirectWith("$id vuelve a pendiente.", $back);
                case 'label':
                    DeviceRepository::setLabel($id, trim(param($_POST, 'label')));
                    redirectWith("Alias de $id guardado.", $back);
                case 'set_group':
                    $ok = DeviceRepository::setGroup($id, (int) param($_POST, 'groupId'));
                    redirectWith($ok ? "Grupo de $id cambiado." : 'Ese grupo no existe.', $back);
                case 'delete':
                    DeviceRepository::delete($id);
                    redirectWith("$id eliminado. Si el equipo vuelve a consultar, se registrará como pendiente.", $back);
                default:
                    redirectWith('Acción desconocida.', $back);
            }
        }
    }

    /* -----------------------------------------------------------------
       Datos para la página (GET)
       ----------------------------------------------------------------- */
    $filters = ['pending' => 'Pendientes', 'authorized' => 'Autorizados', 'revoked' => 'Revocados', 'all' => 'Todos'];
    $filter = param($_GET, 'filter') ?: 'pending';
    if (!isset($filters[$filter])) {
        $filter = 'pending';
    }
    $q = mb_substr(trim(param($_GET, 'q')), 0, 40);
    // Una búsqueda por ID muestra el equipo esté en el estado que esté
    if ($q !== '' && !isset($_GET['filter'])) {
        $filter = 'all';
    }
    // Vista: lista de equipos (por defecto) o gestión de grupos (?view=groups)
    $view = param($_GET, 'view') === 'groups' ? 'groups' : 'list';
    // Grupos (para el filtro, el selector de cada equipo y la vista de grupos)
    $groups = $loggedIn ? GroupRepository::all() : [];
    $groupNames = array_column($groups, 'name', 'id');
    // Filtro de grupo (?group=ID); se ignora si el grupo no existe
    $groupFilter = (int) param($_GET, 'group');
    $groupFilter = isset($groupNames[$groupFilter]) ? $groupFilter : null;
    $back = http_build_query(array_filter(['filter' => $filter, 'q' => $q, 'group' => $groupFilter]));
    $devices = $loggedIn ? DeviceRepository::search($filter === 'all' ? null : $filter, $q, $groupFilter) : [];
    $counts = $loggedIn ? DeviceRepository::counts($groupFilter) : [];
    $flash = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);

    // Vista de historial de arranques de un equipo (?history=SP-...)
    $historyDevice = null;
    $boots = [];
    $bootTotal = 0;
    $historyId = strtoupper(trim(param($_GET, 'history')));
    if ($loggedIn && DeviceRepository::isValidId($historyId)) {
        $historyDevice = DeviceRepository::find($historyId);
        if ($historyDevice !== null) {
            $boots = BootLog::forDevice($historyId, 300);
            $bootTotal = BootLog::count($historyId);
        }
    }
} catch (Throwable $e) {
    error_log('smartpizarra admin: ' . $e->getMessage());
    http_response_code(500);
    // Error de instalación: se explica; cualquier otro: mensaje genérico
    $error = $setupError !== ''
        ? 'Problema de instalación: ' . $setupError
        : 'Error del servidor. Revisa el registro de errores de PHP en hPanel.';
    $devices = [];
    $counts = [];
    $flash = '';
    $filters = ['pending' => 'Pendientes', 'authorized' => 'Autorizados', 'revoked' => 'Revocados', 'all' => 'Todos'];
    $filter = 'pending';
    $q = '';
    $back = '';
    $historyDevice = null;
    $boots = [];
    $bootTotal = 0;
    $view = 'list';
    $groups = [];
    $groupNames = [];
    $groupFilter = null;
}

/** Enlace del panel conservando filtros: q, filter, group, view... (vacíos fuera). */
function panelUrl(array $params): string
{
    $query = http_build_query(array_filter($params, static fn($v) => $v !== null && $v !== ''));
    return './' . ($query !== '' ? '?' . $query : '');
}

$statusText = ['pending' => 'Pendiente', 'authorized' => 'Autorizado', 'revoked' => 'Revocado'];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Dispositivos - Smart Pizarra</title>
  <style nonce="<?= h($nonce) ?>">
    /* Colores en variables; modo oscuro según el sistema */
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
    header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
    h1 { font-size: 1.3rem; margin: 0; }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px; }
    .flash { border-color: var(--accent); margin-bottom: 12px; }
    .error { border-color: var(--bad); color: var(--bad); margin-bottom: 12px; }
    label { display: block; font-size: .9rem; color: var(--muted); margin: 10px 0 4px; }
    input[type=text], input[type=password], input[type=search] {
      width: 100%; padding: 10px 12px; font: inherit; color: var(--text);
      background: var(--bg); border: 1px solid var(--line); border-radius: 8px; }
    button, .btn { font: inherit; padding: 9px 14px; border-radius: 8px; border: 1px solid var(--line);
      background: var(--card); color: var(--text); cursor: pointer; text-decoration: none; display: inline-block; }
    button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
    button.ok { color: var(--ok); border-color: var(--ok); }
    button.bad { color: var(--bad); border-color: var(--bad); }
    .tools { display: flex; gap: 8px; margin-bottom: 12px; }
    .tools input { flex: 1; }
    .tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
    .tabs a { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--line); color: var(--text); text-decoration: none; font-size: .9rem; }
    .tabs a.on { background: var(--accent); border-color: var(--accent); color: #fff; }
    .device { margin-bottom: 10px; }
    .device-head { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; align-items: baseline; }
    .device-id { font: 600 1.05rem ui-monospace, Consolas, monospace; letter-spacing: .5px; }
    .badge { font-size: .8rem; padding: 2px 10px; border-radius: 999px; border: 1px solid currentColor; }
    .badge.pending { color: var(--warn); } .badge.authorized { color: var(--ok); } .badge.revoked { color: var(--bad); }
    .meta { color: var(--muted); font-size: .85rem; margin: 6px 0 10px; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .actions form { margin: 0; }
    .label-form { display: flex; gap: 6px; flex: 1 1 220px; }
    .label-form input { padding: 7px 10px; }
    .alias { font-weight: 600; font-size: 1.05rem; margin-right: 8px; }
    /* Estado de la app nativa: punto de color + texto */
    .online { font-size: .85rem; white-space: nowrap; }
    .online::before { content: ""; display: inline-block; width: 8px; height: 8px; border-radius: 50%;
      margin-right: 6px; background: currentColor; vertical-align: middle; }
    .online.on { color: var(--ok); } .online.off { color: var(--bad); } .online.never { color: var(--muted); }
    .empty { color: var(--muted); text-align: center; padding: 24px; }
    /* Historial de arranques */
    .history-head { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 8px; }
    .boots { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .boots th, .boots td { text-align: left; padding: 7px 8px; border-bottom: 1px solid var(--line); }
    .boots th { color: var(--muted); font-weight: 500; }
    .boots td.n { color: var(--muted); width: 1%; white-space: nowrap; }
    .table-wrap { overflow-x: auto; }
    /* Grupos */
    .header-nav { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .header-nav a.on { background: var(--accent); border-color: var(--accent); color: #fff; }
    select { font: inherit; padding: 9px 10px; border-radius: 8px; border: 1px solid var(--line);
      background: var(--bg); color: var(--text); max-width: 100%; }
    .tools { flex-wrap: wrap; }
    .tools input { min-width: 180px; }
    .group-tag { font-size: .85rem; color: var(--muted); }
    .group-tag.disabled { color: var(--bad); font-weight: 600; }
    .badge.blocked { color: var(--bad); }
    .group-form { display: flex; gap: 6px; }
    .group-head { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; align-items: baseline; }
    .group-name { font-weight: 600; font-size: 1.05rem; }
    .badge.enabled { color: var(--ok); } .badge.disabled { color: var(--bad); }
    .new-group { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
    .new-group input { flex: 1 1 220px; }
    /* Escáner de QR (pantalla completa) */
    #scanner { position: fixed; inset: 0; background: #000; display: none; flex-direction: column;
      align-items: center; justify-content: center; z-index: 10; padding: 16px; }
    #scanner.on { display: flex; }
    #scanner video { width: 100%; max-width: 480px; border-radius: 12px; }
    #scanner p { color: #fff; text-align: center; }
    #scanner button { margin-top: 12px; }
  </style>
</head>
<body>
<main>
<?php if (!$loggedIn): ?>
  <header><h1>Smart Pizarra · Dispositivos</h1></header>
  <?php if ($flash): ?><div class="card flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="card error"><?= h($error) ?></div><?php endif; ?>
  <form class="card" method="post" autocomplete="on">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="login">
    <label for="user">Usuario</label>
    <input type="text" id="user" name="user" autocomplete="username" required>
    <label for="password">Contraseña</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>
    <p><button class="primary" type="submit">Entrar</button></p>
  </form>
<?php else: ?>
  <header>
    <h1>Smart Pizarra · Dispositivos</h1>
    <nav class="header-nav">
      <!-- Vistas: equipos / grupos -->
      <a class="btn <?= $view === 'list' && !$historyDevice ? 'on' : '' ?>" href="./">Dispositivos</a>
      <a class="btn <?= $view === 'groups' ? 'on' : '' ?>" href="?view=groups">Grupos</a>
      <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="logout"><button type="submit">Salir</button></form>
    </nav>
  </header>
  <?php if ($flash): ?><div class="card flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="card error"><?= h($error) ?></div><?php endif; ?>

  <form class="tools" method="get">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Buscar por ID (SP-...) o alias" aria-label="Buscar">
    <!-- Filtro por grupo (se aplica al cambiarlo) -->
    <select name="group" aria-label="Grupo" data-autosubmit>
      <option value="">Todos los grupos</option>
      <?php foreach ($groups as $g): ?>
        <option value="<?= (int) $g['id'] ?>" <?= $groupFilter === (int) $g['id'] ? 'selected' : '' ?>>
          <?= h($g['name']) ?><?= (int) $g['enabled'] === 1 ? '' : ' (deshabilitado)' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php if ($view === 'list' && isset($_GET['filter'])): ?><input type="hidden" name="filter" value="<?= h($filter) ?>"><?php endif; ?>
    <button type="submit">Buscar</button>
    <button type="button" id="scanBtn" class="primary">Escanear QR</button>
  </form>

  <?php if ($view === 'groups' && !$historyDevice): ?>
    <!-- ================= GESTIÓN DE GRUPOS =================
         Deshabilitar un grupo quita la autorización a TODOS sus equipos
         (sin cambiar el estado de cada uno). Para deshabilitar un solo
         equipo se usa "Revocar" en su tarjeta. -->
    <form class="card new-group" method="post"><?= csrfField() ?>
      <input type="hidden" name="action" value="group_create">
      <input type="text" name="name" maxlength="60" required placeholder="Nombre del grupo nuevo (p. ej. Sucursal Norte)" aria-label="Nombre del grupo">
      <button class="primary" type="submit">Crear grupo</button>
    </form>
    <?php foreach ($groups as $g): $gid = (int) $g['id']; $on = (int) $g['enabled'] === 1; ?>
      <section class="card device">
        <div class="group-head">
          <span class="group-name"><?= h($g['name']) ?><?= $gid === GroupRepository::DEFAULT_ID ? ' <span class="group-tag">· por defecto</span>' : '' ?></span>
          <span class="badge <?= $on ? 'enabled' : 'disabled' ?>"><?= $on ? 'Habilitado' : 'Deshabilitado' ?></span>
        </div>
        <div class="meta">
          <?= (int) $g['devices'] ?> equipo(s) · <?= (int) $g['authorized'] ?> autorizado(s)
          <?= !$on && (int) $g['authorized'] > 0 ? ' · <span class="group-tag disabled">sin servicio mientras el grupo esté deshabilitado</span>' : '' ?>
          · Creado el <?= dateTag($g['created_at']) ?>
          <?= $g['enabled_at'] ? ' · Último cambio ' . dateTag($g['enabled_at']) : '' ?>
        </div>
        <div class="actions">
          <?php if ($on): ?>
            <form method="post" data-confirm="¿Deshabilitar el grupo «<?= h($g['name']) ?>»? Sus <?= (int) $g['authorized'] ?> equipo(s) autorizados dejarán de estar autorizados en su próxima consulta."><?= csrfField() ?>
              <input type="hidden" name="action" value="group_disable"><input type="hidden" name="groupId" value="<?= $gid ?>">
              <button class="bad" type="submit">Deshabilitar grupo</button></form>
          <?php else: ?>
            <form method="post"><?= csrfField() ?>
              <input type="hidden" name="action" value="group_enable"><input type="hidden" name="groupId" value="<?= $gid ?>">
              <button class="ok" type="submit">Habilitar grupo</button></form>
          <?php endif; ?>
          <form method="post" class="label-form"><?= csrfField() ?>
            <input type="hidden" name="action" value="group_rename"><input type="hidden" name="groupId" value="<?= $gid ?>">
            <input type="text" name="name" value="<?= h($g['name']) ?>" maxlength="60" required aria-label="Nombre del grupo">
            <button type="submit">Renombrar</button></form>
          <a class="btn" href="<?= h(panelUrl(['filter' => 'all', 'group' => $gid])) ?>">Ver equipos</a>
          <?php if ($gid !== GroupRepository::DEFAULT_ID): ?>
            <form method="post" data-confirm="¿Eliminar el grupo «<?= h($g['name']) ?>»? Sus equipos pasarán al grupo «<?= h($groupNames[GroupRepository::DEFAULT_ID] ?? 'General') ?>» conservando su estado."><?= csrfField() ?>
              <input type="hidden" name="action" value="group_delete"><input type="hidden" name="groupId" value="<?= $gid ?>">
              <button type="submit">Eliminar</button></form>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>

  <?php elseif ($historyDevice): $hid = $historyDevice['device_id']; ?>
    <!-- Historial de arranques de un equipo (cada ejecución de loading.html) -->
    <section class="card">
      <div class="history-head">
        <span>
          <?= $historyDevice['label'] ? '<span class="alias">' . h($historyDevice['label']) . '</span>' : '' ?>
          <span class="device-id"><?= h($hid) ?></span>
        </span>
        <a class="btn" href="./?<?= h(http_build_query(['q' => $hid])) ?>">Volver</a>
      </div>
      <div class="meta">
        Grupo: <?= h($historyDevice['group_name'] ?? '') ?> ·
        Historial de arranques: <?= (int) $bootTotal ?> en total
        <?= $bootTotal > count($boots) ? '(se muestran los ' . count($boots) . ' más recientes)' : '' ?>
        · Registrado el <?= dateTag($historyDevice['created_at']) ?>
      </div>
      <?php if (!$boots): ?>
        <p class="empty">Todavía no hay arranques registrados. Se anotan cada vez que el equipo abre la pantalla de carga.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="boots">
            <thead><tr><th>#</th><th>Fecha y hora</th><th>Hace</th><th>IP</th><th>Versión</th></tr></thead>
            <tbody>
            <?php foreach ($boots as $i => $b): ?>
              <tr>
                <td class="n"><?= (int) ($bootTotal - $i) ?></td>
                <td><?= dateTag($b['booted_at']) ?></td>
                <td><?= agoTag($b['booted_at']) ?></td>
                <td><?= h($b['ip'] ?? '—') ?></td>
                <td><?= $b['app_version'] ? 'v' . h($b['app_version']) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  <?php else: ?>

  <nav class="tabs">
    <?php foreach ($filters as $key => $text): ?>
      <a class="<?= $filter === $key ? 'on' : '' ?>" href="<?= h(panelUrl(['filter' => $key, 'q' => $q, 'group' => $groupFilter])) ?>">
        <?= h($text) ?><?= isset($counts[$key]) ? ' (' . (int) $counts[$key] . ')' : '' ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$devices): ?>
    <div class="card empty">
      <?= $q !== '' ? 'Ningún equipo coincide con «' . h($q) . '». Si es nuevo, abre en la TV la pantalla del código QR para que se registre.' : 'No hay equipos en esta lista.' ?>
    </div>
  <?php endif; ?>

  <?php foreach ($devices as $d):
      $id = $d['device_id'];
      $st = $d['status'];
      $gOn = DeviceRepository::groupEnabled($d); // grupo habilitado
  ?>
    <section class="card device">
      <div class="device-head">
        <span>
          <?= $d['label'] ? '<span class="alias">' . h($d['label']) . '</span>' : '' ?>
          <span class="device-id"><?= h($id) ?></span>
        </span>
        <?php if ($st === 'authorized' && !$gOn): ?>
          <!-- Autorizado, pero bloqueado por su grupo: el equipo recibe "revoked" -->
          <span class="badge blocked">Autorizado · bloqueado por grupo</span>
        <?php else: ?>
          <span class="badge <?= h($st) ?>"><?= h($statusText[$st] ?? $st) ?></span>
        <?php endif; ?>
      </div>
      <div class="meta">
        Grupo: <span class="group-tag <?= $gOn ? '' : 'disabled' ?>"><?= h($d['group_name']) ?><?= $gOn ? '' : ' (deshabilitado)' ?></span><br>
        <!-- Última conexión: arranque de loading.html o señal de la app nativa -->
        Última conexión: <?= onlineTag($d['online_at'] ?? null, $onlineTimeout) ?><br>
        Registrado el <?= dateTag($d['created_at']) ?>
        · Arranques: <?= (int) ($d['boots'] ?? 0) ?>
        <?= !empty($d['last_boot_at']) ? '(último ' . dateTag($d['last_boot_at']) . ')' : '' ?>
        · Última consulta <?= agoTag($d['last_seen_at']) ?>
        <?= $d['app_version'] ? ' · v' . h($d['app_version']) : '' ?>
        <?= $d['last_ip'] ? ' · IP ' . h($d['last_ip']) : '' ?>
      </div>
      <div class="actions">
        <?php if ($st !== 'authorized'): ?>
          <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="authorize">
            <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
            <button class="ok" type="submit">Autorizar</button></form>
        <?php endif; ?>
        <?php if ($st === 'authorized'): ?>
          <form method="post" data-confirm="¿Revocar la autorización de <?= h($id) ?>?"><?= csrfField() ?><input type="hidden" name="action" value="revoke">
            <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
            <button class="bad" type="submit">Revocar</button></form>
        <?php endif; ?>
        <form method="post" class="label-form"><?= csrfField() ?><input type="hidden" name="action" value="label">
          <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <input type="text" name="label" value="<?= h($d['label']) ?>" maxlength="100" placeholder="Alias (p. ej. Local Centro)" aria-label="Alias">
          <button type="submit">Guardar</button></form>
        <!-- Grupo del equipo (se guarda al cambiarlo) -->
        <form method="post" class="group-form"><?= csrfField() ?><input type="hidden" name="action" value="set_group">
          <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <select name="groupId" aria-label="Grupo del equipo" data-autosubmit>
            <?php foreach ($groups as $g): ?>
              <option value="<?= (int) $g['id'] ?>" <?= (int) $g['id'] === (int) $d['group_id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button type="submit">Mover</button></noscript></form>
        <form method="post" data-confirm="¿Eliminar <?= h($id) ?>? Si vuelve a conectarse quedará pendiente."><?= csrfField() ?><input type="hidden" name="action" value="delete">
          <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <button type="submit">Eliminar</button></form>
        <a class="btn" href="./?<?= h(http_build_query(['history' => $id])) ?>">Historial</a>
      </div>
    </section>
  <?php endforeach; ?>
  <?php endif; /* fin vista grupos / historial / lista */ ?>

  <!-- Escáner: cámara trasera + lector de QR -->
  <div id="scanner" role="dialog" aria-label="Escanear código QR">
    <video id="video" playsinline muted></video>
    <p id="scanMsg">Apunta al código QR de la pantalla de la TV.</p>
    <button type="button" id="scanClose">Cerrar</button>
  </div>

  <script nonce="<?= h($nonce) ?>">
  (function () {
    "use strict";
    // Confirmación antes de revocar/eliminar/deshabilitar un grupo
    document.querySelectorAll("form[data-confirm]").forEach(function (f) {
      f.addEventListener("submit", function (e) {
        if (!window.confirm(f.getAttribute("data-confirm"))) e.preventDefault();
      });
    });
    // Selectores que se aplican al cambiarlos (filtro de grupo, grupo del equipo)
    document.querySelectorAll("select[data-autosubmit]").forEach(function (s) {
      s.addEventListener("change", function () { s.form.submit(); });
    });

    /*
     * Tiempos del panel:
     *  - <time data-ts> => fecha y hora LOCAL del navegador (el servidor la da en UTC).
     *  - [data-ago]     => "hace X", recalculado cada 30 s (mismo cálculo que ago() en PHP).
     *  - [data-online]  => En línea / Desconectado según ONLINE_TIMEOUT (segundos).
     * SKEW corrige la diferencia entre el reloj del móvil y el del servidor.
     */
    var ONLINE_TIMEOUT = <?= (int) $onlineTimeout ?>;
    var SKEW = <?= time() ?> - Math.floor(Date.now() / 1000);
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
    function refreshTimes() {
      document.querySelectorAll("[data-ago]").forEach(function (el) {
        el.textContent = agoText(+el.getAttribute("data-ago"));
      });
      document.querySelectorAll("[data-online]").forEach(function (el) {
        var on = nowTs() - (+el.getAttribute("data-online")) < ONLINE_TIMEOUT;
        el.className = "online " + (on ? "on" : "off");
        el.firstChild.nodeValue = on ? "En línea · " : "Desconectado · "; // nodo de texto inicial
      });
    }
    setInterval(refreshTimes, 30000);

    /*
     * Escáner de QR. El QR de fingerprint.html contiene el ID (SP-XXXX-XXXX-XXXX).
     * Usa el lector nativo del navegador (BarcodeDetector, Chrome/Android) y, si
     * no existe (iPhone), la librería jsQR (versión fija con hash de integridad;
     * la CSP permite cdn.jsdelivr.net).
     * Al leer un ID abre la búsqueda de ese equipo, con el botón Autorizar.
     */
    var ID_RE = /SP-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}/i;
    var JSQR = {
      src: "https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js",
      integrity: "sha384-b5Ya4Bq3qCyz39m2ISh+4DxjAIljdeFwK/BsXLuj9gugaNwAcj/ia15fxNZL9Nlx"
    };
    var scanner = document.getElementById("scanner");
    var video = document.getElementById("video");
    var msg = document.getElementById("scanMsg");
    var stream = null, running = false, detector = null, canvas = null;

    function loadJsQR() {
      return new Promise(function (resolve, reject) {
        if (window.jsQR) return resolve();
        var s = document.createElement("script");
        s.src = JSQR.src; s.integrity = JSQR.integrity; s.crossOrigin = "anonymous";
        s.onload = resolve; s.onerror = function () { reject(new Error("No se pudo cargar el lector de QR")); };
        document.head.appendChild(s);
      });
    }

    function stop() {
      running = false;
      if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
      stream = null;
      scanner.classList.remove("on");
    }

    function found(text) {
      var m = ID_RE.exec(text || "");
      if (!m) { msg.textContent = "Ese QR no es de Smart Pizarra."; return false; }
      stop();
      location.href = "?q=" + encodeURIComponent(m[0].toUpperCase());
      return true;
    }

    function loop() {
      if (!running) return;
      if (video.readyState < 2) { setTimeout(loop, 200); return; }
      if (detector) {
        detector.detect(video).then(function (codes) {
          if (codes.length && found(codes[0].rawValue)) return;
          setTimeout(loop, 250);
        }).catch(function () { setTimeout(loop, 250); });
      } else {
        canvas = canvas || document.createElement("canvas");
        var w = video.videoWidth, hgt = video.videoHeight;
        canvas.width = w; canvas.height = hgt;
        var ctx = canvas.getContext("2d", { willReadFrequently: true });
        ctx.drawImage(video, 0, 0, w, hgt);
        var code = window.jsQR(ctx.getImageData(0, 0, w, hgt).data, w, hgt);
        if (code && found(code.data)) return;
        setTimeout(loop, 250);
      }
    }

    document.getElementById("scanBtn").addEventListener("click", function () {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert("Este navegador no permite usar la cámara. Escribe el ID en el buscador.");
        return;
      }
      msg.textContent = "Apunta al código QR de la pantalla de la TV.";
      scanner.classList.add("on");
      var ready = ("BarcodeDetector" in window)
        ? window.BarcodeDetector.getSupportedFormats().then(function (f) {
            if (f.indexOf("qr_code") !== -1) detector = new window.BarcodeDetector({ formats: ["qr_code"] });
            else return loadJsQR();
          })
        : loadJsQR();
      ready.then(function () {
        return navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" }, audio: false });
      }).then(function (s) {
        stream = s; video.srcObject = s; running = true;
        return video.play();
      }).then(loop).catch(function (e) {
        msg.textContent = (e && e.name === "NotAllowedError")
          ? "Permiso de cámara denegado. Actívalo en el navegador o escribe el ID."
          : "No se pudo abrir la cámara: " + (e && e.message ? e.message : e);
      });
    });
    document.getElementById("scanClose").addEventListener("click", stop);
  })();
  </script>
<?php endif; ?>
</main>
</body>
</html>

<?php
/*
 * =====================================================================
 *  SMART PIZARRA - PANEL DE ADMINISTRACIÓN DE DISPOSITIVOS
 *  URL: https://smartpizarra.cloud/admin/
 * =====================================================================
 *  - Entrar con el usuario y la contraseña de private/config.php.
 *  - Ver los equipos registrados (pendientes primero), buscar por ID,
 *    alias, empresa o email, o ESCANEAR el QR que muestra fingerprint.html
 *    con la cámara del móvil (el QR contiene el ID).
 *  - Autorizar, revocar, poner un ALIAS, asignar a un USUARIO o eliminar
 *    un equipo. El equipo se entera en su próxima consulta
 *    (fingerprint.html la hace cada pocos segundos mientras espera;
 *    loading.html al arrancar la app).
 *  - Cada equipo muestra su fecha de registro y su "Última conexión"
 *    (arranque de loading.html o señal de la app nativa, api/ping.php).
 *  - Historial de arranques (?history=SP-...).
 *
 *  - USUARIOS (botón "Usuarios", ?view=users): el administrador crea
 *    usuarios con email, teléfono y empresa. El sistema genera una
 *    contraseña temporal que se muestra UNA vez para dársela al usuario;
 *    el usuario entra en /cuenta/, la cambia y ve sus equipos (no puede
 *    autorizar ni revocar). Aquí se editan sus datos, se restablece su
 *    contraseña, se ven sus equipos (?user=ID) y se pueden REVOCAR TODOS
 *    sus equipos de una vez. Al eliminar un usuario sus equipos quedan
 *    sin asignar, con el estado que tenían.
 *
 *  Seguridad: sesión con cookie segura, token CSRF en cada formulario,
 *  límite de intentos de login por IP, consultas preparadas, salida
 *  escapada y CSP con nonce (private/web.php).
 */

declare(strict_types=1);

require __DIR__ . '/../../private/lib.php';
require __DIR__ . '/../../private/web.php';

// Sesión + cabeceras; el CDN permite cargar el lector de QR (jsQR)
$nonce = startSecurePage('sp_admin', '/admin/', 'https://cdn.jsdelivr.net');

/**
 * Lee el filtro de usuario de un parámetro: "" => null (todos),
 * "none" => 0 (sin asignar), número => ese usuario.
 */
function userFilterParam(string $v): ?int
{
    if ($v === 'none') {
        return 0;
    }
    return ctype_digit($v) && (int) $v > 0 ? (int) $v : null;
}

/** Enlace del panel conservando filtros: q, filter, user, view... (vacíos fuera). */
function panelUrl(array $params): string
{
    $query = http_build_query(array_filter($params, static fn($v) => $v !== null && $v !== ''));
    return './' . ($query !== '' ? '?' . $query : '');
}

/** Texto de un usuario para listas: "Empresa — email". */
function userText(array $u): string
{
    return $u['company'] . ' — ' . $u['email'];
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
$onlineTimeout = onlineTimeout($config);
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
        } elseif (str_starts_with($action, 'user_')) {
            /* ---- Acciones sobre USUARIOS (vista ?view=users) ---- */
            $uid = (int) param($_POST, 'userId');
            $user = $uid > 0 ? UserRepository::find($uid) : null;
            // Volver a la vista de usuarios o a la lista de equipos de ese usuario
            $back = param($_POST, 'back') !== '' ? param($_POST, 'back') : 'view=users';
            if ($action !== 'user_create' && $user === null) {
                redirectWith('Ese usuario no existe.', 'view=users');
            }
            switch ($action) {
                case 'user_create':
                case 'user_update':
                    $email = UserRepository::cleanEmail(param($_POST, 'email'));
                    $phone = UserRepository::cleanPhone(param($_POST, 'phone'));
                    $company = UserRepository::cleanCompany(param($_POST, 'company'));
                    if ($email === null) {
                        redirectWith('El email no es válido.', $back);
                    }
                    if ($phone === null) {
                        redirectWith('El teléfono no es válido (de 6 a 15 dígitos; se permiten +, espacios, guiones y paréntesis).', $back);
                    }
                    if ($company === null) {
                        redirectWith('El nombre de la empresa debe tener entre 1 y 100 caracteres.', $back);
                    }
                    if (UserRepository::emailTaken($email, $action === 'user_update' ? $uid : 0)) {
                        redirectWith("Ya existe un usuario con el email {$email}.", $back);
                    }
                    if ($action === 'user_update') {
                        UserRepository::update($uid, $email, $phone, $company);
                        redirectWith("Datos de «{$company}» guardados.", $back);
                    }
                    [, $password] = UserRepository::create($email, $phone, $company);
                    // La contraseña temporal se muestra UNA sola vez
                    $_SESSION['newPassword'] = ['email' => $email, 'company' => $company, 'password' => $password];
                    redirectWith("Usuario «{$company}» creado.", 'view=users');
                case 'user_reset':
                    $password = UserRepository::resetPassword($uid);
                    $_SESSION['newPassword'] = ['email' => $user['email'], 'company' => $user['company'], 'password' => $password];
                    redirectWith("Contraseña de «{$user['company']}» restablecida; su sesión abierta se cierra.", $back);
                case 'user_revoke_all':
                    $n = DeviceRepository::revokeAllForUser($uid);
                    redirectWith("Revocados {$n} equipo(s) de «{$user['company']}». "
                        . 'Lo detectarán en su próxima consulta; se vuelven a autorizar uno a uno.', $back);
                case 'user_delete':
                    UserRepository::delete($uid);
                    redirectWith("Usuario «{$user['company']}» eliminado; sus equipos quedan sin asignar con el estado que tenían.", 'view=users');
                default:
                    redirectWith('Acción desconocida.', 'view=users');
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
                case 'set_user':
                    // "" = sin asignar
                    $raw = param($_POST, 'userId');
                    $ok = DeviceRepository::setUser($id, $raw === '' ? null : (int) $raw);
                    redirectWith($ok ? "Usuario de $id cambiado." : 'Ese usuario no existe.', $back);
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
    // Vista: lista de equipos (por defecto) o gestión de usuarios (?view=users)
    $view = param($_GET, 'view') === 'users' ? 'users' : 'list';
    // Usuarios (para el filtro, el selector de cada equipo y la vista de usuarios)
    $users = $loggedIn ? UserRepository::all() : [];
    $usersById = array_column($users, null, 'id');
    // Filtro de usuario (?user=ID | ?user=none); se ignora si el usuario no existe
    $userFilter = userFilterParam(param($_GET, 'user'));
    if ($userFilter !== null && $userFilter > 0 && !isset($usersById[$userFilter])) {
        $userFilter = null;
    }
    $userParam = $userFilter === null ? null : ($userFilter === 0 ? 'none' : (string) $userFilter);
    // Una búsqueda o un usuario concreto muestran sus equipos estén en el estado que estén
    if (($q !== '' || $userFilter !== null) && !isset($_GET['filter'])) {
        $filter = 'all';
    }
    $back = http_build_query(array_filter(['filter' => $filter, 'q' => $q, 'user' => $userParam]));
    $devices = $loggedIn && $view === 'list' ? DeviceRepository::search($filter === 'all' ? null : $filter, $q, $userFilter) : [];
    $counts = $loggedIn ? DeviceRepository::counts($userFilter) : [];
    $flash = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    // Contraseña temporal recién creada/restablecida (se muestra una vez)
    $newPassword = $_SESSION['newPassword'] ?? null;
    unset($_SESSION['newPassword']);

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
    $newPassword = null;
    $filters = ['pending' => 'Pendientes', 'authorized' => 'Autorizados', 'revoked' => 'Revocados', 'all' => 'Todos'];
    $filter = 'pending';
    $q = '';
    $back = '';
    $historyDevice = null;
    $boots = [];
    $bootTotal = 0;
    $view = 'list';
    $users = [];
    $usersById = [];
    $userFilter = null;
    $userParam = null;
}

$statusText = ['pending' => 'Pendiente', 'authorized' => 'Autorizado', 'revoked' => 'Revocado'];
// Usuario cuyos equipos se están viendo (?user=ID)
$focusUser = $userFilter ? ($usersById[$userFilter] ?? null) : null;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Dispositivos - Smart Pizarra</title>
  <style nonce="<?= h($nonce) ?>">
<?= baseStyles() ?>

    .tools { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
    .tools input { flex: 1; min-width: 180px; }
    .tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
    .tabs a { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--line); color: var(--text); text-decoration: none; font-size: .9rem; }
    .tabs a.on { background: var(--accent); border-color: var(--accent); color: #fff; }
    .label-form { display: flex; gap: 6px; flex: 1 1 220px; }
    .label-form input { padding: 7px 10px; }
    select { font: inherit; padding: 9px 10px; border-radius: 8px; border: 1px solid var(--line);
      background: var(--bg); color: var(--text); max-width: 100%; }
    .header-nav { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .header-nav a.on { background: var(--accent); border-color: var(--accent); color: #fff; }
    .owner { font-size: .85rem; color: var(--muted); }
    .user-form { display: flex; gap: 6px; }
    /* Usuarios */
    .user-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 8px; margin-bottom: 10px; }
    .user-grid label { margin-top: 0; }
    .user-head { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; align-items: baseline; }
    .user-name { font-weight: 600; font-size: 1.05rem; }
    .new-user { margin-bottom: 12px; }
    .focus-user { margin-bottom: 12px; }
    /* Historial de arranques */
    .history-head { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 8px; }
    .boots { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .boots th, .boots td { text-align: left; padding: 7px 8px; border-bottom: 1px solid var(--line); }
    .boots th { color: var(--muted); font-weight: 500; }
    .boots td.n { color: var(--muted); width: 1%; white-space: nowrap; }
    .table-wrap { overflow-x: auto; }
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
      <!-- Vistas: equipos / usuarios -->
      <a class="btn <?= $view === 'list' && !$historyDevice ? 'on' : '' ?>" href="./">Dispositivos</a>
      <a class="btn <?= $view === 'users' ? 'on' : '' ?>" href="?view=users">Usuarios</a>
      <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="logout"><button type="submit">Salir</button></form>
    </nav>
  </header>
  <?php if ($flash): ?><div class="card flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="card error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($newPassword): ?>
    <!-- Contraseña temporal: solo se ve ahora (en la base se guarda cifrada) -->
    <div class="card flash">
      Contraseña temporal de <strong><?= h($newPassword['company']) ?></strong> (<?= h($newPassword['email']) ?>):<br>
      <span class="secret"><?= h($newPassword['password']) ?></span><br>
      <span class="owner">Cópiala ahora y dásela al usuario: no se volverá a mostrar. Entra en
        <a href="../cuenta/">https://smartpizarra.cloud/cuenta/</a> con su email y deberá cambiarla al entrar.</span>
    </div>
  <?php endif; ?>

  <form class="tools" method="get">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Buscar por ID, alias, empresa o email" aria-label="Buscar">
    <!-- Filtro por usuario (se aplica al cambiarlo) -->
    <select name="user" aria-label="Usuario" data-autosubmit>
      <option value="">Todos los usuarios</option>
      <option value="none" <?= $userFilter === 0 ? 'selected' : '' ?>>Sin asignar</option>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int) $u['id'] ?>" <?= $userFilter === (int) $u['id'] ? 'selected' : '' ?>><?= h(userText($u)) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($view === 'list' && isset($_GET['filter'])): ?><input type="hidden" name="filter" value="<?= h($filter) ?>"><?php endif; ?>
    <button type="submit">Buscar</button>
    <button type="button" id="scanBtn" class="primary">Escanear QR</button>
  </form>

  <?php if ($view === 'users' && !$historyDevice): ?>
    <!-- ================= GESTIÓN DE USUARIOS =================
         Datos requeridos: email (para entrar en /cuenta/), teléfono y
         empresa. La contraseña la genera el sistema y la cambia el usuario.
         Solo el administrador autoriza y revoca equipos. -->
    <form class="card new-user" method="post" autocomplete="off"><?= csrfField() ?>
      <h2>Nuevo usuario</h2>
      <input type="hidden" name="action" value="user_create">
      <div class="user-grid">
        <div><label for="nu-company">Empresa</label>
          <input type="text" id="nu-company" name="company" maxlength="100" required></div>
        <div><label for="nu-email">Email</label>
          <input type="email" id="nu-email" name="email" maxlength="190" required></div>
        <div><label for="nu-phone">Teléfono</label>
          <input type="tel" id="nu-phone" name="phone" maxlength="30" required placeholder="+52 55 1234 5678"></div>
      </div>
      <button class="primary" type="submit">Crear usuario</button>
      <span class="owner">Se generará una contraseña temporal para entregarle.</span>
    </form>
    <?php if (!$users): ?><div class="card empty">Todavía no hay usuarios.</div><?php endif; ?>
    <?php foreach ($users as $u): $uid = (int) $u['id']; ?>
      <section class="card device">
        <div class="user-head">
          <span class="user-name"><?= h($u['company']) ?></span>
          <span class="owner"><?= (int) $u['devices'] ?> equipo(s) · <?= (int) $u['authorized'] ?> autorizado(s)</span>
        </div>
        <div class="meta">
          Creado el <?= dateTag($u['created_at']) ?>
          · Último acceso: <?= $u['last_login_at'] ? dateTag($u['last_login_at']) : 'nunca' ?>
          <?= (int) $u['must_change_password'] === 1 ? ' · <strong>contraseña temporal sin cambiar</strong>' : '' ?>
        </div>
        <!-- Editar datos -->
        <form method="post" autocomplete="off"><?= csrfField() ?>
          <input type="hidden" name="action" value="user_update"><input type="hidden" name="userId" value="<?= $uid ?>">
          <div class="user-grid">
            <div><label for="c<?= $uid ?>">Empresa</label>
              <input type="text" id="c<?= $uid ?>" name="company" value="<?= h($u['company']) ?>" maxlength="100" required></div>
            <div><label for="e<?= $uid ?>">Email</label>
              <input type="email" id="e<?= $uid ?>" name="email" value="<?= h($u['email']) ?>" maxlength="190" required></div>
            <div><label for="p<?= $uid ?>">Teléfono</label>
              <input type="tel" id="p<?= $uid ?>" name="phone" value="<?= h($u['phone']) ?>" maxlength="30" required></div>
          </div>
          <div class="actions">
            <button type="submit">Guardar datos</button>
            <a class="btn" href="<?= h(panelUrl(['user' => $uid])) ?>">Ver equipos</a>
          </div>
        </form>
        <div class="actions">
          <form method="post" data-confirm="¿Revocar la autorización de TODOS los equipos de «<?= h($u['company']) ?>» (<?= (int) $u['devices'] ?>)? Después se autorizan de nuevo uno a uno."><?= csrfField() ?>
            <input type="hidden" name="action" value="user_revoke_all"><input type="hidden" name="userId" value="<?= $uid ?>">
            <button class="bad" type="submit" <?= (int) $u['devices'] === 0 ? 'disabled' : '' ?>>Revocar todos sus equipos</button></form>
          <form method="post" data-confirm="¿Generar una contraseña nueva para «<?= h($u['company']) ?>»? La actual dejará de funcionar."><?= csrfField() ?>
            <input type="hidden" name="action" value="user_reset"><input type="hidden" name="userId" value="<?= $uid ?>">
            <button type="submit">Restablecer contraseña</button></form>
          <form method="post" data-confirm="¿Eliminar el usuario «<?= h($u['company']) ?>»? Sus <?= (int) $u['devices'] ?> equipo(s) quedarán sin asignar CONSERVANDO su autorización."><?= csrfField() ?>
            <input type="hidden" name="action" value="user_delete"><input type="hidden" name="userId" value="<?= $uid ?>">
            <button type="submit">Eliminar usuario</button></form>
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
        Usuario: <?= $historyDevice['user_company'] ? h($historyDevice['user_company'] . ' — ' . $historyDevice['user_email']) : 'sin asignar' ?> ·
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

  <?php if ($focusUser): $fu = (int) $focusUser['id']; ?>
    <!-- Equipos de un usuario: datos de contacto + revocar todos -->
    <section class="card focus-user">
      <div class="user-head">
        <span class="user-name"><?= h($focusUser['company']) ?></span>
        <a class="btn" href="?view=users">Usuarios</a>
      </div>
      <div class="meta"><?= h($focusUser['email']) ?> · Tel. <?= h($focusUser['phone']) ?>
        · <?= (int) $focusUser['devices'] ?> equipo(s), <?= (int) $focusUser['authorized'] ?> autorizado(s)</div>
      <form method="post" data-confirm="¿Revocar la autorización de TODOS los equipos de «<?= h($focusUser['company']) ?>»?"><?= csrfField() ?>
        <input type="hidden" name="action" value="user_revoke_all"><input type="hidden" name="userId" value="<?= $fu ?>">
        <input type="hidden" name="back" value="<?= h($back) ?>">
        <button class="bad" type="submit" <?= (int) $focusUser['devices'] === 0 ? 'disabled' : '' ?>>Revocar todos sus equipos</button></form>
    </section>
  <?php endif; ?>

  <nav class="tabs">
    <?php foreach ($filters as $key => $text): ?>
      <a class="<?= $filter === $key ? 'on' : '' ?>" href="<?= h(panelUrl(['filter' => $key, 'q' => $q, 'user' => $userParam])) ?>">
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
  ?>
    <section class="card device">
      <div class="device-head">
        <span>
          <?= $d['label'] ? '<span class="alias">' . h($d['label']) . '</span>' : '' ?>
          <span class="device-id"><?= h($id) ?></span>
        </span>
        <span class="badge <?= h($st) ?>"><?= h($statusText[$st] ?? $st) ?></span>
      </div>
      <div class="meta">
        Usuario: <?= $d['user_company'] ? h($d['user_company']) . ' <span class="owner">(' . h($d['user_email']) . ')</span>' : '<em>sin asignar</em>' ?><br>
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
        <!-- Usuario dueño del equipo (se guarda al cambiarlo) -->
        <form method="post" class="user-form"><?= csrfField() ?><input type="hidden" name="action" value="set_user">
          <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <select name="userId" aria-label="Usuario del equipo" data-autosubmit>
            <option value="">Sin asignar</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) $d['user_id'] ? 'selected' : '' ?>><?= h(userText($u)) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button type="submit">Asignar</button></noscript></form>
        <form method="post" data-confirm="¿Eliminar <?= h($id) ?>? Si vuelve a conectarse quedará pendiente."><?= csrfField() ?><input type="hidden" name="action" value="delete">
          <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <button type="submit">Eliminar</button></form>
        <a class="btn" href="./?<?= h(http_build_query(['history' => $id])) ?>">Historial</a>
      </div>
    </section>
  <?php endforeach; ?>
  <?php endif; /* fin vista usuarios / historial / lista */ ?>

  <!-- Escáner: cámara trasera + lector de QR -->
  <div id="scanner" role="dialog" aria-label="Escanear código QR">
    <video id="video" playsinline muted></video>
    <p id="scanMsg">Apunta al código QR de la pantalla de la TV.</p>
    <button type="button" id="scanClose">Cerrar</button>
  </div>

  <!-- Fechas locales, "hace X", En línea/Desconectado y confirmaciones -->
  <script nonce="<?= h($nonce) ?>"><?= timeScript($onlineTimeout) ?></script>
  <script nonce="<?= h($nonce) ?>">
  (function () {
    "use strict";
    // Selectores que se aplican al cambiarlos (filtro de usuario, usuario del equipo)
    document.querySelectorAll("select[data-autosubmit]").forEach(function (s) {
      s.addEventListener("change", function () { s.form.submit(); });
    });

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

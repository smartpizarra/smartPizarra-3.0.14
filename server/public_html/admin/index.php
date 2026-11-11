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
 *  - CONTENIDO (imágenes/videos de mirror.html): permiso aparte de la
 *    autorización, botón "Habilitar contenido" en cada equipo. Un equipo
 *    solo muestra el contenido de su usuario si está autorizado Y tiene el
 *    contenido habilitado.
 *
 *  - USUARIOS (botón "Usuarios", ?view=users): el administrador crea
 *    usuarios con email, teléfono y empresa. El sistema genera una
 *    contraseña temporal que se muestra UNA vez para dársela al usuario;
 *    el usuario entra en /cuenta/, la cambia y ve sus equipos (no puede
 *    autorizar ni revocar). Aquí se editan sus datos, se restablece su
 *    contraseña, se ven sus equipos (?user=ID) y se pueden AUTORIZAR o
 *    REVOCAR TODOS sus equipos de una vez (o uno a uno en cada tarjeta). Al eliminar un usuario sus equipos quedan
 *    sin asignar, con el estado que tenían.
 *    Organización (2026-11-11): ?view=users es una LISTA COMPACTA (una fila
 *    por usuario) con buscador (q: empresa, email o teléfono) y filtros por
 *    estado (us=active|expired|none|temp|nodevices); "Nuevo usuario" va
 *    plegado. Cada usuario se abre en su FICHA (?view=users&user=ID) con
 *    secciones separadas: Vigencia, Equipos, Contenido, Datos, Acceso y
 *    Eliminar. Las acciones hechas desde la ficha vuelven a la ficha.
 *
 *  - VIGENCIA del contenido por usuario (vista Usuarios y ficha del
 *    usuario): días que el administrador SUMA o RESTA (o quita). Sus
 *    equipos solo muestran imágenes/videos mientras la vigencia está
 *    activa; el cambio llega a todos sus equipos en su próxima consulta.
 *  - CONTENIDO DE LOS USUARIOS (botón "Contenido", ?view=media): todas
 *    las imágenes/videos subidos en /cuenta/, filtrables por usuario, con
 *    vista previa (?file=ID), descarga con su nombre original
 *    (?file=ID&dl=1) y ELIMINAR (las pantallas lo quitan en su próxima
 *    consulta). El administrador no los edita: eso lo hace su dueño.
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

/**
 * Bloque de VIGENCIA de un usuario: estado + sumar/restar días + quitar.
 * @param array<string,mixed> $u    Fila de UserRepository::all().
 * @param string              $back Consulta a la que volver tras el cambio.
 */
function validityBlock(array $u, string $back): string
{
    $v = contentValidity($u['content_until'] ?? null);
    $badge = ['active' => 'authorized', 'expired' => 'revoked', 'none' => 'off'][$v['state']];
    $label = ['active' => 'Vigente', 'expired' => 'Vencida', 'none' => 'Sin vigencia'][$v['state']];
    $uid = (int) $u['id'];
    $html = '<div class="validity"><div class="validity-head"><span class="badge ' . $badge . '">' . $label . '</span> '
        . '<span class="owner">' . $v['text'] . '</span></div>'
        . '<form method="post" class="days-form">' . csrfField()
        . '<input type="hidden" name="action" value="user_days"><input type="hidden" name="userId" value="' . $uid . '">'
        . '<input type="hidden" name="back" value="' . h($back) . '">'
        . '<label for="days' . $uid . '" class="sr">Días</label>'
        . '<input type="number" id="days' . $uid . '" name="days" value="30" min="1" max="' . UserRepository::MAX_DAYS . '" required>'
        . '<span class="owner">días</span>'
        . '<button class="ok" type="submit" name="op" value="add">+ Sumar</button>'
        . '<button class="bad" type="submit" name="op" value="sub"' . ($v['state'] === 'none' ? ' disabled' : '') . '>− Restar</button>'
        . '</form>';
    if ($v['state'] !== 'none') {
        $html .= '<form method="post" data-confirm="¿Quitar la vigencia de «' . h($u['company']) . '»? Sus equipos dejarán de mostrar el contenido.">'
            . csrfField() . '<input type="hidden" name="action" value="user_clear_content">'
            . '<input type="hidden" name="userId" value="' . $uid . '"><input type="hidden" name="back" value="' . h($back) . '">'
            . '<button type="submit">Quitar vigencia</button></form>';
    }
    return $html . '</div>';
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
    // Archivo de un usuario: vista previa (?file=ID) o descarga (&dl=1), solo administrador
    if ($loggedIn && isset($_GET['file'])) {
        $media = MediaRepository::find((int) param($_GET, 'file'));
        if ($media === null) {
            http_response_code(404);
            exit('No encontrado');
        }
        MediaRepository::send($media, param($_GET, 'dl') === '1');
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
        } elseif ($action === 'media_delete') {
            /* ---- Eliminar un archivo de un usuario (vista ?view=media) ---- */
            $back = param($_POST, 'back') !== '' ? param($_POST, 'back') : 'view=media';
            $gone = MediaRepository::deleteById((int) param($_POST, 'mediaId'));
            redirectWith($gone === null
                ? 'Ese archivo ya no existe.'
                : '«' . $gone['original_name'] . '» de «' . $gone['user_company'] . '» eliminado. '
                    . 'Sus pantallas dejarán de mostrarlo en su próxima consulta.', $back);
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
                    [$newId, $password] = UserRepository::create($email, $phone, $company);
                    // La contraseña temporal se muestra UNA sola vez (en la ficha del usuario nuevo)
                    $_SESSION['newPassword'] = ['email' => $email, 'company' => $company, 'password' => $password];
                    redirectWith("Usuario «{$company}» creado.", 'view=users&user=' . $newId);
                case 'user_reset':
                    $password = UserRepository::resetPassword($uid);
                    $_SESSION['newPassword'] = ['email' => $user['email'], 'company' => $user['company'], 'password' => $password];
                    redirectWith("Contraseña de «{$user['company']}» restablecida; su sesión abierta se cierra.", $back);
                case 'user_days':
                    // Sumar o restar días de vigencia (afecta a todos sus equipos)
                    $days = (int) param($_POST, 'days');
                    if ($days < 1 || $days > UserRepository::MAX_DAYS) {
                        redirectWith('Indica entre 1 y ' . UserRepository::MAX_DAYS . ' días.', $back);
                    }
                    $sub = param($_POST, 'op') === 'sub';
                    $new = UserRepository::addContentDays($uid, $sub ? -$days : $days);
                    if ($new === null) {
                        redirectWith("«{$user['company']}» no tiene vigencia de la que restar días.", $back);
                    }
                    $active = UserRepository::contentActive($new);
                    redirectWith(($sub ? "Restados {$days} día(s)" : "Sumados {$days} día(s)") . " a «{$user['company']}». "
                        . ($active
                            ? 'Vigente hasta el ' . gmdate('d/m/Y H:i', (int) strtotime($new . ' UTC')) . ' UTC.'
                            : 'La vigencia quedó VENCIDA: sus equipos dejan de mostrar el contenido.')
                        . ' Se aplica a todos sus equipos en su próxima consulta.', $back);
                case 'user_clear_content':
                    UserRepository::clearContent($uid);
                    redirectWith("Vigencia de «{$user['company']}» quitada: sus equipos dejan de mostrar el contenido.", $back);
                case 'user_revoke_all':
                    $n = DeviceRepository::revokeAllForUser($uid);
                    redirectWith("Revocados {$n} equipo(s) de «{$user['company']}». "
                        . 'Lo detectarán en su próxima consulta.', $back);
                case 'user_authorize_all':
                    // Todos los equipos del usuario a "authorized" (los ya autorizados no cuentan)
                    $n = DeviceRepository::authorizeAllForUser($uid);
                    redirectWith("Autorizados {$n} equipo(s) de «{$user['company']}». "
                        . 'Lo detectarán en su próxima consulta.', $back);
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
                case 'media_enable':
                case 'media_disable':
                    // Permiso de contenido (imágenes/videos de mirror.html), aparte de la autorización
                    $on = $action === 'media_enable';
                    DeviceRepository::setMediaEnabled($id, $on);
                    redirectWith($on
                        ? "Contenido habilitado en $id: mostrará las imágenes/videos de su usuario si está autorizado."
                        : "Contenido deshabilitado en $id: dejará de mostrarlo en su próxima consulta.", $back);
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
    // Vista: lista de equipos (por defecto), usuarios (?view=users) o contenido (?view=media)
    $view = in_array(param($_GET, 'view'), ['users', 'media'], true) ? param($_GET, 'view') : 'list';
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
    // Contenido de los usuarios (?view=media[&user=ID])
    $mediaUser = $userFilter !== null && $userFilter > 0 ? $userFilter : null;
    $mediaAll = $loggedIn && $view === 'media' ? MediaRepository::allForAdmin($mediaUser) : [];
    $mediaTotals = $loggedIn && $view === 'media' ? MediaRepository::totals($mediaUser) : [0, 0];
    $mediaBack = http_build_query(array_filter(['view' => 'media', 'user' => $mediaUser]));

    /*
     * Vista Usuarios organizada: lista compacta filtrada (búsqueda + estado)
     * o la FICHA de un usuario (?view=users&user=ID).
     */
    $userStates = ['all' => 'Todos', 'active' => 'Vigentes', 'expired' => 'Vencidos', 'none' => 'Sin vigencia',
        'temp' => 'Contraseña temporal', 'nodevices' => 'Sin equipos'];
    $userState = param($_GET, 'us');
    if (!isset($userStates[$userState])) {
        $userState = 'all';
    }
    $userCard = $view === 'users' && $userFilter ? ($usersById[$userFilter] ?? null) : null;
    $userCounts = array_fill_keys(array_keys($userStates), 0);
    $userList = [];
    if ($view === 'users') {
        $needle = mb_strtolower($q);
        foreach ($users as $u) {
            // Estados de cada usuario (uno puede estar en varios filtros)
            $u['validity'] = contentValidity($u['content_until'] ?? null);
            $tags = ['all', $u['validity']['state']];
            if ((int) $u['must_change_password'] === 1) {
                $tags[] = 'temp';
            }
            if ((int) $u['devices'] === 0) {
                $tags[] = 'nodevices';
            }
            // La búsqueda (empresa, email o teléfono) se aplica antes de contar
            if ($needle !== '' && !str_contains(mb_strtolower($u['company'] . ' ' . $u['email'] . ' ' . $u['phone']), $needle)) {
                continue;
            }
            foreach ($tags as $t) {
                $userCounts[$t]++;
            }
            if (in_array($userState, $tags, true)) {
                $userList[] = $u;
            }
        }
    }
    // Volver a la ficha tras una acción hecha desde ella
    $userBack = $userCard ? 'view=users&user=' . (int) $userCard['id'] : 'view=users';
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
    $mediaAll = [];
    $mediaTotals = [0, 0];
    $mediaBack = '';
    $userStates = [];
    $userState = 'all';
    $userCard = null;
    $userCounts = [];
    $userList = [];
    $userBack = 'view=users';
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
    .badge.off { color: var(--muted); } /* contenido no habilitado / sin vigencia */
    /* Vigencia (días) */
    .validity { border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; margin: 8px 0 10px;
      display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .validity-head { flex: 1 1 100%; }
    .validity form { margin: 0; }
    .days-form { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
    .days-form input[type=number] { width: 90px; padding: 8px 10px; font: inherit; color: var(--text);
      background: var(--bg); border: 1px solid var(--line); border-radius: 8px; }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    /* Contenido de los usuarios (?view=media) */
    .media { display: flex; gap: 12px; flex-wrap: wrap; }
    .thumb { width: 160px; height: 90px; border-radius: 8px; background: #000; object-fit: contain; flex: 0 0 auto;
      display: flex; align-items: center; justify-content: center; color: #fff; font-size: .9rem; text-decoration: none; }
    .media-body { flex: 1 1 260px; min-width: 0; }
    .media-name { font-weight: 600; overflow-wrap: anywhere; }
    .user-form { display: flex; gap: 6px; }
    /* Usuarios */
    .user-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 8px; margin-bottom: 10px; }
    .user-grid label { margin-top: 0; }
    .user-head { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; align-items: baseline; }
    .user-name { font-weight: 600; font-size: 1.05rem; }
    .new-user { margin-bottom: 12px; }
    .new-user summary { cursor: pointer; color: var(--accent); }
    .new-user[open] summary { margin-bottom: 4px; }
    /* Lista compacta de usuarios: una fila por usuario, abre su ficha */
    .user-list { padding: 0; overflow: hidden; }
    .user-row { display: flex; gap: 10px 14px; align-items: center; flex-wrap: wrap; padding: 12px 16px;
      color: var(--text); text-decoration: none; border-bottom: 1px solid var(--line); }
    .user-row:last-child { border-bottom: 0; }
    .user-row:hover, .user-row:focus { background: var(--bg); }
    .user-main { flex: 1 1 240px; min-width: 0; display: flex; flex-direction: column; }
    .user-main .owner { overflow-wrap: anywhere; }
    .user-facts { display: flex; gap: 6px 8px; flex-wrap: wrap; align-items: center; }
    .chev { color: var(--muted); font-size: 1.4rem; line-height: 1; }
    @media (max-width: 560px) { .chev { display: none; } } /* en móvil la fila entera ya es el enlace */
    /* Ficha de un usuario */
    .user-card-head, .section { margin-bottom: 10px; }
    .section h2 { font-size: 1rem; }
    .section .validity { margin: 0; }
    .danger { border-color: var(--bad); }
    .back { margin-right: 8px; padding: 6px 10px; font-size: .9rem; }
    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 8px; }
    .stat { border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px; }
    .stat b { display: block; font-size: 1.3rem; }
    .stat span { font-size: .8rem; color: var(--muted); }
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
      <a class="btn <?= $view === 'media' ? 'on' : '' ?>" href="?view=media">Contenido</a>
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
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="<?= $view === 'users' ? 'Buscar usuario por empresa, email o teléfono' : 'Buscar por ID, alias, empresa o email' ?>" aria-label="Buscar">
    <!-- Filtro por usuario (se aplica al cambiarlo) -->
    <select name="user" aria-label="Usuario" data-autosubmit>
      <option value="">Todos los usuarios</option>
      <option value="none" <?= $userFilter === 0 ? 'selected' : '' ?>>Sin asignar</option>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int) $u['id'] ?>" <?= $userFilter === (int) $u['id'] ? 'selected' : '' ?>><?= h(userText($u)) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($view === 'list' && isset($_GET['filter'])): ?><input type="hidden" name="filter" value="<?= h($filter) ?>"><?php endif; ?>
    <?php if ($view === 'media' || $view === 'users'): ?><input type="hidden" name="view" value="<?= h($view) ?>"><?php endif; ?>
    <?php if ($view === 'users' && $userState !== 'all'): ?><input type="hidden" name="us" value="<?= h($userState) ?>"><?php endif; ?>
    <button type="submit">Buscar</button>
    <button type="button" id="scanBtn" class="primary">Escanear QR</button>
  </form>

  <?php if ($view === 'users' && !$historyDevice && $userCard): $uid = (int) $userCard['id']; $uv = contentValidity($userCard['content_until'] ?? null); ?>
    <!-- ================= FICHA DE UN USUARIO (?view=users&user=ID) =================
         Todo lo de un usuario, separado por secciones. Las acciones vuelven aquí ($userBack). -->
    <section class="card user-card-head">
      <div class="user-head">
        <span>
          <a class="btn back" href="<?= h(panelUrl(['view' => 'users'])) ?>">&larr; Usuarios</a>
          <span class="user-name"><?= h($userCard['company']) ?></span>
        </span>
        <span>
          <?php if ((int) $userCard['must_change_password'] === 1): ?><span class="badge pending">Contraseña temporal</span><?php endif; ?>
          <span class="badge <?= ['active' => 'authorized', 'expired' => 'revoked', 'none' => 'off'][$uv['state']] ?>"><?= ['active' => 'Vigente', 'expired' => 'Vencida', 'none' => 'Sin vigencia'][$uv['state']] ?></span>
        </span>
      </div>
      <div class="meta">
        <?= h($userCard['email']) ?> · Tel. <?= h($userCard['phone']) ?><br>
        Creado el <?= dateTag($userCard['created_at']) ?>
        · Último acceso a /cuenta/: <?= $userCard['last_login_at'] ? dateTag($userCard['last_login_at']) : 'nunca' ?>
      </div>
      <!-- Resumen en cifras -->
      <div class="stats">
        <div class="stat"><b><?= (int) $userCard['devices'] ?></b><span>Equipos</span></div>
        <div class="stat"><b><?= (int) $userCard['authorized'] ?></b><span>Autorizados</span></div>
        <div class="stat"><b><?= (int) $userCard['media'] ?></b><span>Archivos</span></div>
        <div class="stat"><b><?= $uv['state'] === 'active' ? (int) $uv['days'] : '—' ?></b><span>Días de vigencia</span></div>
      </div>
    </section>

    <!-- Vigencia del contenido (días) -->
    <section class="card section">
      <h2>Vigencia del contenido</h2>
      <?= validityBlock($userCard, $userBack) ?>
    </section>

    <!-- Equipos: ver y autorizar/revocar todos -->
    <section class="card section">
      <h2>Equipos</h2>
      <div class="meta"><?= (int) $userCard['devices'] ?> equipo(s) asignado(s), <?= (int) $userCard['authorized'] ?> autorizado(s).
        Para asignar un equipo, búscalo en Dispositivos y elige este usuario en su selector.</div>
      <div class="actions">
        <a class="btn" href="<?= h(panelUrl(['user' => $uid])) ?>">Ver sus equipos</a>
        <form method="post" data-confirm="¿Autorizar TODOS los equipos de «<?= h($userCard['company']) ?>» (<?= (int) $userCard['devices'] ?>)?"><?= csrfField() ?>
          <input type="hidden" name="action" value="user_authorize_all"><input type="hidden" name="userId" value="<?= $uid ?>">
          <input type="hidden" name="back" value="<?= h($userBack) ?>">
          <button class="ok" type="submit" <?= (int) $userCard['authorized'] === (int) $userCard['devices'] ? 'disabled' : '' ?>>Autorizar todos</button></form>
        <form method="post" data-confirm="¿Revocar la autorización de TODOS los equipos de «<?= h($userCard['company']) ?>» (<?= (int) $userCard['devices'] ?>)?"><?= csrfField() ?>
          <input type="hidden" name="action" value="user_revoke_all"><input type="hidden" name="userId" value="<?= $uid ?>">
          <input type="hidden" name="back" value="<?= h($userBack) ?>">
          <button class="bad" type="submit" <?= (int) $userCard['devices'] === 0 ? 'disabled' : '' ?>>Revocar todos</button></form>
      </div>
    </section>

    <!-- Contenido subido por el usuario -->
    <section class="card section">
      <h2>Contenido</h2>
      <div class="meta"><?= (int) $userCard['media'] ?> archivo(s) subido(s) desde /cuenta/.</div>
      <div class="actions">
        <a class="btn" href="<?= h(panelUrl(['view' => 'media', 'user' => $uid])) ?>">Ver su contenido</a>
      </div>
    </section>

    <!-- Datos de contacto (editar) -->
    <section class="card section">
      <h2>Datos</h2>
      <form method="post" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="user_update"><input type="hidden" name="userId" value="<?= $uid ?>">
        <input type="hidden" name="back" value="<?= h($userBack) ?>">
        <div class="user-grid">
          <div><label for="c<?= $uid ?>">Empresa</label>
            <input type="text" id="c<?= $uid ?>" name="company" value="<?= h($userCard['company']) ?>" maxlength="100" required></div>
          <div><label for="e<?= $uid ?>">Email</label>
            <input type="email" id="e<?= $uid ?>" name="email" value="<?= h($userCard['email']) ?>" maxlength="190" required></div>
          <div><label for="p<?= $uid ?>">Teléfono</label>
            <input type="tel" id="p<?= $uid ?>" name="phone" value="<?= h($userCard['phone']) ?>" maxlength="30" required></div>
        </div>
        <button type="submit">Guardar datos</button>
      </form>
    </section>

    <!-- Acceso a /cuenta/ -->
    <section class="card section">
      <h2>Acceso</h2>
      <div class="meta"><?= (int) $userCard['must_change_password'] === 1
          ? 'Todavía tiene la contraseña temporal: deberá cambiarla al entrar.'
          : 'Contraseña cambiada por el usuario.' ?></div>
      <form method="post" data-confirm="¿Generar una contraseña nueva para «<?= h($userCard['company']) ?>»? La actual dejará de funcionar."><?= csrfField() ?>
        <input type="hidden" name="action" value="user_reset"><input type="hidden" name="userId" value="<?= $uid ?>">
        <input type="hidden" name="back" value="<?= h($userBack) ?>">
        <button type="submit">Restablecer contraseña</button></form>
    </section>

    <!-- Eliminar (separado para no pulsarlo por error) -->
    <section class="card section danger">
      <h2>Eliminar usuario</h2>
      <div class="meta">Sus <?= (int) $userCard['devices'] ?> equipo(s) quedarán sin asignar CONSERVANDO su autorización; sus archivos se borran.</div>
      <form method="post" data-confirm="¿Eliminar el usuario «<?= h($userCard['company']) ?>»? Sus <?= (int) $userCard['devices'] ?> equipo(s) quedarán sin asignar CONSERVANDO su autorización."><?= csrfField() ?>
        <input type="hidden" name="action" value="user_delete"><input type="hidden" name="userId" value="<?= $uid ?>">
        <button class="bad" type="submit">Eliminar usuario</button></form>
    </section>

  <?php elseif ($view === 'users' && !$historyDevice): ?>
    <!-- ================= LISTA DE USUARIOS =================
         Una fila por usuario (resumen); todo lo demás está en su ficha.
         Datos requeridos al crear: email (para entrar en /cuenta/), teléfono y
         empresa. La contraseña la genera el sistema y la cambia el usuario. -->
    <details class="card new-user" <?= !$users ? 'open' : '' ?>>
      <summary><strong>+ Nuevo usuario</strong></summary>
      <form method="post" autocomplete="off"><?= csrfField() ?>
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
    </details>

    <!-- Filtros por estado (con la búsqueda aplicada) -->
    <nav class="tabs">
      <?php foreach ($userStates as $key => $text): ?>
        <a class="<?= $userState === $key ? 'on' : '' ?>" href="<?= h(panelUrl(['view' => 'users', 'us' => $key === 'all' ? '' : $key, 'q' => $q])) ?>">
          <?= h($text) ?> (<?= (int) $userCounts[$key] ?>)
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if (!$userList): ?>
      <div class="card empty"><?= !$users ? 'Todavía no hay usuarios.' : ($q !== '' ? 'Ningún usuario coincide con «' . h($q) . '».' : 'No hay usuarios en esta lista.') ?></div>
    <?php else: ?>
      <div class="card user-list">
        <?php foreach ($userList as $u): $uid = (int) $u['id']; $v = $u['validity']; ?>
          <!-- Fila: toda ella abre la ficha del usuario -->
          <a class="user-row" href="<?= h(panelUrl(['view' => 'users', 'user' => $uid])) ?>">
            <span class="user-main">
              <span class="user-name"><?= h($u['company']) ?></span>
              <span class="owner"><?= h($u['email']) ?> · <?= h($u['phone']) ?></span>
            </span>
            <span class="user-facts">
              <span class="badge <?= ['active' => 'authorized', 'expired' => 'revoked', 'none' => 'off'][$v['state']] ?>">
                <?= $v['state'] === 'active' ? 'Vigente · ' . (int) $v['days'] . ' d' : ($v['state'] === 'expired' ? 'Vencida' : 'Sin vigencia') ?></span>
              <?php if ((int) $u['must_change_password'] === 1): ?><span class="badge pending">Contraseña temporal</span><?php endif; ?>
              <span class="owner"><?= (int) $u['devices'] ?> equipo(s) · <?= (int) $u['authorized'] ?> aut. · <?= (int) $u['media'] ?> archivo(s)</span>
            </span>
            <span class="chev" aria-hidden="true">&rsaquo;</span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($view === 'media' && !$historyDevice): ?>
    <!-- ============ CONTENIDO SUBIDO POR LOS USUARIOS ============
         Solo ver, descargar o eliminar; ordenar/asignar lo hace su dueño. -->
    <section class="card focus-user">
      <div class="user-head">
        <span class="user-name">Contenido de <?= $mediaUser !== null ? h($usersById[$mediaUser]['company'] ?? '') : 'todos los usuarios' ?></span>
        <span class="owner"><?= (int) $mediaTotals[0] ?> archivo(s) · <?= h(formatBytes((int) $mediaTotals[1])) ?></span>
      </div>
      <div class="meta">Elige un usuario en el selector de arriba para ver solo su contenido. Al eliminar un archivo,
        las pantallas dejan de mostrarlo en su próxima consulta (≈ 1 min).</div>
    </section>
    <?php if (!$mediaAll): ?><div class="card empty">No hay archivos.</div><?php endif; ?>
    <?php foreach ($mediaAll as $m): $mid = (int) $m['id']; $isImg = $m['kind'] === 'image'; ?>
      <section class="card device">
        <div class="media">
          <?php if ($isImg): ?>
            <a href="?file=<?= $mid ?>" target="_blank" rel="noopener"><img class="thumb" src="?file=<?= $mid ?>" alt="" loading="lazy"></a>
          <?php else: ?>
            <a class="thumb" href="?file=<?= $mid ?>" target="_blank" rel="noopener">&#9654; Video</a>
          <?php endif; ?>
          <div class="media-body">
            <div class="media-name"><?= h($m['original_name']) ?></div>
            <div class="meta">
              Usuario: <a href="<?= h(panelUrl(['view' => 'media', 'user' => (int) $m['user_id']])) ?>"><?= h($m['user_company']) ?></a>
              <span class="owner">(<?= h($m['user_email']) ?>)</span><br>
              <?= $isImg ? 'Imagen · ' . (int) $m['seconds'] . ' s en pantalla' : 'Video' ?>
              · <?= h(formatBytes((int) $m['size_bytes'])) ?> · <?= h($m['mime']) ?>
              · Puesto <?= (int) $m['position'] ?><br>
              Se muestra en: <?= (int) $m['all_devices'] === 1 ? 'todos sus equipos' : (int) $m['devices_count'] . ' equipo(s) elegido(s)' ?>
              · Subido el <?= dateTag($m['created_at']) ?>
            </div>
            <div class="actions">
              <a class="btn" href="?file=<?= $mid ?>" target="_blank" rel="noopener">Ver</a>
              <a class="btn" href="?file=<?= $mid ?>&amp;dl=1">Descargar</a>
              <form method="post" data-confirm="¿Eliminar «<?= h($m['original_name']) ?>» de «<?= h($m['user_company']) ?>»? Se borra del servidor y sus pantallas dejarán de mostrarlo."><?= csrfField() ?>
                <input type="hidden" name="action" value="media_delete"><input type="hidden" name="mediaId" value="<?= $mid ?>">
                <input type="hidden" name="back" value="<?= h($mediaBack) ?>">
                <button class="bad" type="submit">Eliminar</button></form>
            </div>
          </div>
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
        <a class="btn" href="<?= h(panelUrl(['view' => 'users', 'user' => $fu])) ?>">Ficha del usuario</a>
      </div>
      <div class="meta"><?= h($focusUser['email']) ?> · Tel. <?= h($focusUser['phone']) ?>
        · <?= (int) $focusUser['devices'] ?> equipo(s), <?= (int) $focusUser['authorized'] ?> autorizado(s)</div>
      <?= validityBlock($focusUser, $back) ?>
      <!-- Todos a la vez; cada equipo se sigue pudiendo cambiar con sus botones de abajo -->
      <div class="actions">
        <form method="post" data-confirm="¿Autorizar TODOS los equipos de «<?= h($focusUser['company']) ?>»?"><?= csrfField() ?>
          <input type="hidden" name="action" value="user_authorize_all"><input type="hidden" name="userId" value="<?= $fu ?>">
          <input type="hidden" name="back" value="<?= h($back) ?>">
          <button class="ok" type="submit" <?= (int) $focusUser['authorized'] === (int) $focusUser['devices'] ? 'disabled' : '' ?>>Autorizar todos sus equipos</button></form>
        <form method="post" data-confirm="¿Revocar la autorización de TODOS los equipos de «<?= h($focusUser['company']) ?>»?"><?= csrfField() ?>
          <input type="hidden" name="action" value="user_revoke_all"><input type="hidden" name="userId" value="<?= $fu ?>">
          <input type="hidden" name="back" value="<?= h($back) ?>">
          <button class="bad" type="submit" <?= (int) $focusUser['devices'] === 0 ? 'disabled' : '' ?>>Revocar todos sus equipos</button></form>
      </div>
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
      $mediaOn = DeviceRepository::mediaEnabled($d); // permiso de contenido (mirror.html)
  ?>
    <section class="card device">
      <div class="device-head">
        <span>
          <?= $d['label'] ? '<span class="alias">' . h($d['label']) . '</span>' : '' ?>
          <span class="device-id"><?= h($id) ?></span>
        </span>
        <span>
          <!-- Permiso de contenido (mirror.html): independiente de la autorización -->
          <span class="badge <?= $mediaOn ? 'authorized' : 'off' ?>"><?= $mediaOn ? 'Contenido habilitado' : 'Contenido no habilitado' ?></span>
          <span class="badge <?= h($st) ?>"><?= h($statusText[$st] ?? $st) ?></span>
        </span>
      </div>
      <div class="meta">
        Usuario: <?= $d['user_company'] ? h($d['user_company']) . ' <span class="owner">(' . h($d['user_email']) . ')</span>'
          . ' · <span class="owner">' . contentValidity($d['user_content_until'] ?? null)['text'] . '</span>' : '<em>sin asignar</em>' ?><br>
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
        <!-- Habilitar/deshabilitar la función de contenido (imágenes/videos) -->
        <form method="post" <?= $mediaOn ? 'data-confirm="¿Deshabilitar el contenido en ' . h($id) . '? Dejará de mostrar imágenes y videos."' : '' ?>><?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $mediaOn ? 'media_disable' : 'media_enable' ?>">
          <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <button class="<?= $mediaOn ? 'bad' : 'ok' ?>" type="submit"><?= $mediaOn ? 'Deshabilitar contenido' : 'Habilitar contenido' ?></button></form>
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

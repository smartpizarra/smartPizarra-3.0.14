<?php
// Versión del archivo: 1.0.8 (2026-11-22)
/*
 * =====================================================================
 *  SMART PIZARRA - CUENTA DEL USUARIO (EMPRESA)
 *  URL: https://smartpizarra.cloud/cuenta/
 * =====================================================================
 *  - El usuario lo crea el administrador en el panel (admin/, Usuarios)
 *    con email, teléfono y empresa, y le entrega una contraseña temporal.
 *  - Entra aquí con su email y esa contraseña; la primera vez (o tras un
 *    "Restablecer contraseña" del administrador) DEBE cambiarla.
 *  - Ve sus datos y sus equipos (alias, ID, estado, última conexión).
 *  - VIDEOS FLUIDOS (2026-11-13): al subir un MP4 se RECHAZA si es de más
 *    de 30 fps, nivel H.264 > 4.1, H.265/HEVC o mayor de 1920×1080
 *    (MediaRepository::videoProblem): en las TV se vería a saltos.
 *  - PAUSAR EL CONTENIDO (2026-11-12): cada archivo tiene "Pausar" /
 *    "Activar", como las tiras (acciones media_pause / media_resume,
 *    MediaRepository::setActive). Pausado no se envía a las pantallas pero
 *    se conserva (sigue contando para el límite de archivos).
 *  - LÍMITES DEL PLAN (2026-11-22; sustituye al límite de archivos fijo):
 *    con el PLAN vigente puede tener hasta max_files archivos y storage_mb
 *    de espacio (PlanRepository::limits); sin plan o con el plan vencido no
 *    sube nada nuevo (lo ya subido se conserva). Contenido e Inicio lo
 *    muestran; media_upload lo comprueba. Pestaña "Mi plan" (?s=plan):
 *    datos del plan (nombre, estado, vence, días, archivos, espacio, costo)
 *    e HISTORIAL de renovaciones. Solo lectura: asigna y renueva el admin.
 *  - MENSAJES (pestaña ?s=mensajes, 2026-11-12): su conversación con el
 *    administrador (MessageRepository). Abrir la pestaña marca como leídos
 *    los mensajes del administrador; la pestaña e Inicio avisan de los no
 *    leídos. Acción msg_send (máx. MessageRepository::USER_PER_HOUR
 *    mensajes por hora).
 *  - MAC DEL CASE (2026-11-12): cada equipo muestra la MAC impresa en su
 *    case (la del PERFIL que registra el administrador; "sin registrar" si
 *    no tiene) y la pestaña Equipos tiene un BUSCADOR (?s=equipos&q=) que
 *    encuentra SUS equipos por esa MAC (completa o un trozo, sin importar
 *    ":", "-" ni mayúsculas), por su nombre o por su ID
 *    (DeviceRepository::search limitado a su usuario).
 *    Solo puede PONER/EDITAR EL NOMBRE (alias, columna label, el mismo
 *    que ve el administrador) de los equipos asignados a él (acción
 *    device_label, comprueba que el equipo sea suyo). Autorizar, revocar
 *    y habilitar el contenido lo hace el administrador.
 *  - LIGA DE CADA EQUIPO (acción device_league): para cada uno de sus
 *    equipos elige "Sin liga" o UNA de las ligas que le asignó el
 *    administrador (es el ÚNICO sitio donde se cambia la liga de una TV).
 *    Si el equipo tiene el CONTENIDO HABILITADO, el selector no se muestra y
 *    la acción se rechaza (2026-11-22).
 *    La TV la recibe en api/media.php (mirror.html, ≈ 1 min) o
 *    api/device.php y la guarda en su localStorage (smartpizarra.league =
 *    código del tipo, smartpizarra.leagues = códigos; "" sin liga).
 *  - Contenido (imágenes/videos): cada archivo es PERMANENTE o CON FECHAS
 *    (desde/hasta, opcionales, en la hora de su navegador => UTC), como
 *    los anuncios del administrador; fuera del periodo api/media.php no
 *    lo envía a sus equipos (MediaRepository::forDevice filtra por fecha).
 *  - TIRAS Y BANNERS (pestaña "Tiras y banners", ?s=tiras, 2026-11-11):
 *    franja ARRIBA o ABAJO de la pantalla de mirror.html: TIRA de texto
 *    (colores de fondo y letra, fija o desplazándose con velocidad) o
 *    BANNER de imagen; tamaño pequeña/mediana/grande. Se CREAN SIN EQUIPOS
 *    y luego se ASIGNAN ("Asignar a equipos" en cada una: sin asignar /
 *    todos mis equipos / algunos + TIEMPO DE VISIBILIDAD: permanente o con
 *    fechas; acción overlay_assign). Pausar/activar, editar y eliminar (acciones
 *    overlay_*). Vista previa del banner: ?ovfile=ID.
 *    Si el administrador pone una en la MISMA posición, se ve la suya.
 *  - Puede cambiar su contraseña cuando quiera (pide la actual).
 *
 *  Seguridad: sesión propia (cookie "sp_user" solo en /cuenta/), token
 *  CSRF, límite de intentos de login por IP (mismos valores que el panel),
 *  contraseñas con password_hash. Si el administrador restablece la
 *  contraseña o borra el usuario, la sesión abierta se cierra sola.
 */

declare(strict_types=1);

require __DIR__ . '/../../private/lib.php';
require __DIR__ . '/../../private/web.php';

$nonce = startSecurePage('sp_user', '/cuenta/');

/**
 * Huella de la contraseña guardada: cambia cada vez que se cambia la
 * contraseña, así una sesión abierta con la anterior deja de valer.
 * @param array<string,mixed> $user
 */
function passwordStamp(array $user): string
{
    return hash('sha256', (string) $user['password_hash']);
}

/**
 * Equipos marcados en un formulario de contenido: "all" = todos (también
 * los futuros) o la lista de casillas "devices[]".
 * @return array{0:bool,1:string[]} [todos, equipos]
 */
function targetDevices(): array
{
    $all = param($_POST, 'target') !== 'some';
    $list = isset($_POST['devices']) && is_array($_POST['devices'])
        ? array_values(array_filter($_POST['devices'], 'is_string'))
        : [];
    return [$all, $list];
}

/**
 * Selector de equipos de un archivo: "Todos mis equipos" (también los que
 * se asignen después) o casillas de equipos concretos.
 * @param array<int,array<string,mixed>> $devices Equipos del usuario.
 * @param string[] $selected device_id marcados.
 * @param string $uid Sufijo único para los id de los campos.
 */
function targetFields(array $devices, bool $all, array $selected, string $uid): string
{
    $html = '<fieldset class="targets"><legend>Mostrar en</legend>'
        . '<label class="inline"><input type="radio" name="target" value="all"' . ($all ? ' checked' : '') . '> Todos mis dispositivos</label>'
        . '<label class="inline"><input type="radio" name="target" value="some"' . ($all ? '' : ' checked') . '> Solo en:</label>'
        . '<div class="device-checks">' . ($devices ? deviceFilterBox() : '');
    if (!$devices) {
        $html .= '<span class="hint">Todavía no tienes dispositivos asignados.</span>';
    }
    foreach ($devices as $d) {
        $id = (string) $d['device_id'];
        $html .= '<label class="inline"' . deviceSearchAttr([$d['label'], $id, $d['profile_serial'] ?? null], [$d['profile_case_mac'] ?? null]) . '><input type="checkbox" name="devices[]" value="' . h($id) . '" id="d-' . h($uid . $id) . '"'
            . (in_array($id, $selected, true) ? ' checked' : '') . '> '
            . h($d['label'] ? $d['label'] . ' (' . $id . ')' : $id)
            . (DeviceRepository::mediaEnabled($d) ? '' : ' <span class="hint">(contenido no habilitado)</span>') . '</label>';
    }
    return $html . '</div></fieldset>';
}

/**
 * Selector "Cuándo se muestra" de un archivo: PERMANENTE o CON FECHAS
 * (desde / hasta, cualquiera opcional), igual que los anuncios del
 * administrador. Las fechas se escriben en la hora del navegador; el JS
 * rellena tz (getTimezoneOffset) y convierte data-utc (guardado) a local.
 * @param array<string,mixed>|null $m Archivo (null = subida nueva).
 * @param string $p Prefijo único para los id de los campos.
 */
function scheduleFields(?array $m, string $p): string
{
    $hasDates = $m !== null && (!empty($m['starts_at']) || !empty($m['ends_at']));
    $utc = static fn($v) => $v ? (string) utcTs((string) $v) : '';
    $date = static fn(string $name, string $text, $value) =>
        '<div><label for="' . $p . $name . '">' . $text . '</label>'
        . '<span class="date-field"><input type="datetime-local" id="' . $p . $name . '" name="' . $name . '" data-utc="' . $utc($value) . '">'
        . '<button type="button" data-pick="' . $p . $name . '" title="Abrir calendario" aria-label="Abrir calendario">&#128197;</button>'
        . '<button type="button" data-clear="' . $p . $name . '" title="Quitar fecha" aria-label="Quitar fecha">&times;</button></span></div>';
    return '<fieldset class="targets" data-when><legend>Cuándo se muestra</legend>'
        . '<input type="hidden" name="tz" value="0" data-tz>'
        . '<label class="inline"><input type="radio" name="when" value="always"' . ($hasDates ? '' : ' checked') . '> Permanente</label>'
        . '<label class="inline"><input type="radio" name="when" value="dates"' . ($hasDates ? ' checked' : '') . '> Con fechas</label>'
        . '<div class="dates" data-dates>'
        . $date('starts', 'Desde (opcional)', $m['starts_at'] ?? null)
        . $date('ends', 'Hasta (opcional)', $m['ends_at'] ?? null)
        . '<p class="hint">Fuera de esas fechas tus pantallas no lo muestran. Se aplica en su próxima consulta (≈ 1 min).</p>'
        . '</div></fieldset>';
}

/**
 * Fechas del formulario de contenido. "Permanente" => [null, null].
 * Si algo no es válido redirige con el mensaje (a la sección $back).
 * @return array{0:?string,1:?string} [desde, hasta] en UTC "Y-m-d H:i:s".
 */
function scheduleData(string $back = 's=contenido'): array
{
    if (param($_POST, 'when') !== 'dates') {
        return [null, null];
    }
    $tz = (int) param($_POST, 'tz');
    $starts = localToUtc(param($_POST, 'starts'), $tz);
    $ends = localToUtc(param($_POST, 'ends'), $tz);
    if ($starts === false || $ends === false) {
        redirectWith('Fecha no válida.', $back);
    }
    if ($starts === null && $ends === null) {
        redirectWith('Indica al menos una fecha (desde o hasta) o elige "Permanente".', $back);
    }
    if ($starts !== null && $ends !== null && $ends <= $starts) {
        redirectWith('La fecha "Hasta" debe ser posterior a "Desde".', $back);
    }
    return [$starts, $ends];
}

/** Insignia y texto del periodo de un archivo (Permanente / En curso / Programado / Finalizado). */
function scheduleTag(array $m): string
{
    $state = MediaRepository::state($m);
    [$class, $label] = [
        'permanent' => ['authorized', 'Permanente'],
        'active' => ['authorized', 'En curso'],
        'scheduled' => ['pending', 'Programado'],
        'ended' => ['revoked', 'Finalizado'],
    ][$state];
    $text = $state === 'permanent' ? 'se muestra siempre'
        : (!empty($m['starts_at']) ? 'desde ' . dateTag((string) $m['starts_at']) : 'desde ya')
          . ' · ' . (!empty($m['ends_at']) ? 'hasta ' . dateTag((string) $m['ends_at']) : 'sin fecha de fin');
    return '<span class="badge ' . $class . '">' . $label . '</span> <span class="hint">' . $text . '</span>';
}

/**
 * Formulario "Asignar a equipos" de una tira/banner del usuario: sin
 * asignar, todos mis equipos (también los futuros) o algunos.
 * @param array<int,array<string,mixed>> $devices Equipos del usuario.
 */
function overlayAssignFields(array $o, array $devices): string
{
    $scope = (string) $o['scope'];
    $p = 'as' . (int) $o['id'] . '-';
    $html = '<fieldset class="targets"><legend>Mostrar en</legend>';
    foreach (['none' => 'Sin asignar', 'all' => 'Todos mis dispositivos', 'devices' => 'Solo en:'] as $k => $text) {
        $html .= '<label class="inline"><input type="radio" name="scope" value="' . $k . '"' . ($scope === $k ? ' checked' : '') . '> ' . $text . '</label>';
    }
    // Buscador de dispositivos (siempre que tenga alguno)
    $html .= '<div class="device-checks">' . ($devices ? deviceFilterBox() : '');
    if (!$devices) {
        $html .= '<span class="hint">Todavía no tienes dispositivos asignados.</span>';
    }
    foreach ($devices as $d) {
        $id = (string) $d['device_id'];
        $html .= '<label class="inline"' . deviceSearchAttr([$d['label'], $id, $d['profile_serial'] ?? null], [$d['profile_case_mac'] ?? null]) . '><input type="checkbox" name="devices[]" value="' . h($id) . '" id="' . h($p . $id) . '"'
            . (in_array($id, $o['devices'], true) ? ' checked' : '') . '> '
            . h($d['label'] ? $d['label'] . ' (' . $id . ')' : $id)
            . (DeviceRepository::mediaEnabled($d) ? '' : ' <span class="hint">(contenido no habilitado)</span>') . '</label>';
    }
    return $html . '</div></fieldset>';
}

/** Insignia de una tira/banner: Pausada, o su periodo (Permanente / En curso / Programado / Finalizado). */
function overlayTag(array $o): string
{
    return (int) $o['active'] !== 1 ? '<span class="badge off">Pausada</span>' : scheduleTag($o);
}

/** Cierra la sesión del usuario. */
function logoutUser(): void
{
    $_SESSION = [];
    session_destroy();
}

$error = '';
$user = null;
$setupError = '';
try {
    $config = AppConfig::get();
    $setupError = Database::diagnose() ?? '';
} catch (Throwable $e) {
    $config = [];
    $setupError = $e->getMessage();
}
$onlineTimeout = onlineTimeout($config);

try {
    if ($setupError !== '') {
        throw new RuntimeException($setupError);
    }

    // Usuario de la sesión (si fue borrado o cambió su contraseña en otro lado => fuera)
    if (!empty($_SESSION['uid'])) {
        $user = UserRepository::find((int) $_SESSION['uid']);
        if ($user === null || !hash_equals((string) ($_SESSION['pwd'] ?? ''), passwordStamp($user))) {
            logoutUser();
            session_start();
            session_regenerate_id(true);
            $user = null;
            $_SESSION['flash'] = 'Tu sesión se cerró. Vuelve a entrar.';
        }
    }

    // Vista previa de un archivo propio (?file=ID) o su miniatura (?thumb=ID): solo su dueño con sesión
    if ($user !== null && (isset($_GET['file']) || isset($_GET['thumb']))) {
        $thumb = isset($_GET['thumb']);
        $media = MediaRepository::findForUser((int) param($_GET, $thumb ? 'thumb' : 'file'), (int) $user['id']);
        if ($media === null) {
            http_response_code(404);
            exit('No encontrado');
        }
        $thumb ? MediaRepository::sendThumb($media) : MediaRepository::send($media);
    }
    // Imagen de un banner propio (?ovfile=ID)
    if ($user !== null && isset($_GET['ovfile'])) {
        $ov = OverlayRepository::findForUser((int) param($_GET, 'ovfile'), (int) $user['id']);
        if ($ov === null || $ov['kind'] !== 'image') {
            http_response_code(404);
            exit('No encontrado');
        }
        MediaRepository::send($ov);
    }

    /* -----------------------------------------------------------------
       Acciones (POST)
       ----------------------------------------------------------------- */
    // Subida más grande que post_max_size de PHP: llega sin datos ni CSRF
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        redirectWith('El archivo es demasiado grande para el servidor (límite de PHP post_max_size: '
            . ini_get('post_max_size') . ').', 's=contenido');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = param($_POST, 'action');
        if (!hash_equals(csrfToken(), param($_POST, 'csrf'))) {
            redirectWith('La sesión caducó. Vuelve a intentarlo.');
        }

        if ($action === 'login') {
            $bucket = 'ulogin:' . Http::clientIp();
            $window = (int) $config['login_window_seconds'];
            if (RateLimiter::count($bucket, $window) >= (int) $config['login_fails_per_ip']) {
                $error = 'Demasiados intentos. Espera unos minutos.';
            } else {
                $found = UserRepository::verifyLogin(param($_POST, 'email'), param($_POST, 'password'));
                if ($found !== null) {
                    RateLimiter::reset($bucket);
                    session_regenerate_id(true);
                    $_SESSION['uid'] = (int) $found['id'];
                    $_SESSION['pwd'] = passwordStamp($found);
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));
                    UserRepository::markLogin((int) $found['id']);
                    redirectWith((int) $found['must_change_password'] === 1
                        ? 'Bienvenido. Antes de continuar, elige tu propia contraseña.'
                        : 'Sesión iniciada.');
                }
                RateLimiter::hit($bucket, PHP_INT_MAX, $window);
                $error = 'Email o contraseña incorrectos.';
            }
        } elseif ($user === null) {
            redirectWith('Inicia sesión.');
        } elseif ($action === 'logout') {
            logoutUser();
            header('Location: ./', true, 303);
            exit;
        } elseif ($action === 'password') {
            // Cambio de contraseña: pide la actual (también la temporal)
            $current = param($_POST, 'current');
            $new = param($_POST, 'new');
            if (!password_verify($current, (string) $user['password_hash'])) {
                redirectWith('La contraseña actual no es correcta.', 's=cuenta');
            }
            if ($new !== param($_POST, 'confirm')) {
                redirectWith('La contraseña nueva y su confirmación no coinciden.', 's=cuenta');
            }
            $problem = UserRepository::passwordProblem($new, (string) $user['email']);
            if ($problem === null && hash_equals($current, $new)) {
                $problem = 'La contraseña nueva debe ser distinta de la actual.';
            }
            if ($problem !== null) {
                redirectWith($problem, 's=cuenta');
            }
            UserRepository::setPassword((int) $user['id'], $new, false);
            // La sesión actual sigue abierta con la contraseña nueva
            session_regenerate_id(true);
            $_SESSION['pwd'] = passwordStamp(UserRepository::find((int) $user['id']) ?? []);
            redirectWith('Contraseña cambiada.', 's=cuenta');
        } elseif ($action === 'device_league') {
            /* ---- Liga de uno de SUS equipos: "Sin liga" o UNA de las que le asignó el administrador ---- */
            if ((int) $user['must_change_password'] === 1) {
                redirectWith('Primero elige tu contraseña.', 's=cuenta');
            }
            $id = strtoupper(trim(param($_POST, 'deviceId')));
            $device = DeviceRepository::isValidId($id) ? DeviceRepository::find($id) : null;
            if ($device === null || (int) ($device['user_id'] ?? 0) !== (int) $user['id']) {
                redirectWith('Ese dispositivo no está asignado a tu cuenta.', 's=equipos');
            }
            // Con el contenido habilitado el usuario no elige liga (el selector no se muestra)
            if (DeviceRepository::mediaEnabled($device)) {
                redirectWith('Este dispositivo tiene el contenido habilitado: su liga no se cambia desde aquí.', 's=equipos');
            }
            $raw = param($_POST, 'leagueId');
            $leagueId = ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null; // "" = Sin liga
            // setDeviceLeague solo acepta ligas asignadas a este usuario
            if (!LeagueRepository::setDeviceLeague($id, $leagueId)) {
                redirectWith('Esa liga no está asignada a tu cuenta.', 's=equipos');
            }
            $name = $device['label'] ? $device['label'] . " ($id)" : $id;
            $league = $leagueId === null ? null : LeagueRepository::find($leagueId);
            redirectWith(($league === null ? "$name queda sin liga." : "$name usará la liga «{$league['name']}».")
                . ' La TV lo recibe en su próxima consulta (≈ 1 min si está mostrando contenido).', 's=equipos');
        } elseif ($action === 'msg_send') {
            /* ---- MENSAJE al administrador (pestaña Mensajes) ---- */
            if ((int) $user['must_change_password'] === 1) {
                redirectWith('Primero elige tu contraseña.', 's=cuenta');
            }
            $body = MessageRepository::cleanBody(param($_POST, 'body'));
            if ($body === null) {
                redirectWith('Escribe el mensaje (máximo ' . MessageRepository::MAX_BODY . ' caracteres).', 's=mensajes');
            }
            // Límite por hora (anti-abuso)
            if (!RateLimiter::hit('msg:' . (int) $user['id'], MessageRepository::USER_PER_HOUR, 3600)) {
                redirectWith('Has enviado muchos mensajes en poco tiempo. Espera un rato e inténtalo de nuevo.', 's=mensajes');
            }
            MessageRepository::send((int) $user['id'], 'user', $body);
            redirectWith('Mensaje enviado al administrador.', 's=mensajes');
        } elseif ($action === 'device_label') {
            /* ---- Nombre (alias) de uno de SUS equipos ---- */
            if ((int) $user['must_change_password'] === 1) {
                redirectWith('Primero elige tu contraseña.', 's=cuenta');
            }
            $id = strtoupper(trim(param($_POST, 'deviceId')));
            $device = DeviceRepository::isValidId($id) ? DeviceRepository::find($id) : null;
            // Solo equipos asignados a este usuario (nunca los de otro)
            if ($device === null || (int) ($device['user_id'] ?? 0) !== (int) $user['id']) {
                redirectWith('Ese dispositivo no está asignado a tu cuenta.', 's=equipos');
            }
            // Texto en UTF-8 (si llega en Windows-1252 se convierte, para no perder ñ/acentos)
            $raw = param($_POST, 'label');
            if (!mb_check_encoding($raw, 'UTF-8')) {
                $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
            }
            // Sin saltos de línea ni caracteres de control; máx. 100 (setLabel recorta)
            $label = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw));
            DeviceRepository::setLabel($id, $label);
            redirectWith($label === '' ? "Nombre de $id quitado." : "Nombre de $id guardado: «" . mb_substr($label, 0, 100) . '».', 's=equipos');
        } elseif (str_starts_with($action, 'overlay_')) {
            /* ---- TIRAS Y BANNERS de sus pantallas (mirror.html) ---- */
            if ((int) $user['must_change_password'] === 1) {
                redirectWith('Primero elige tu contraseña.', 's=cuenta');
            }
            $uid = (int) $user['id'];
            $oid = (int) param($_POST, 'overlayId');
            $ov = $action === 'overlay_create' ? null : OverlayRepository::findForUser($oid, $uid);
            if ($action !== 'overlay_create' && $ov === null) {
                redirectWith('Esa tira o banner ya no existe.', 's=tiras');
            }
            if ($action === 'overlay_create' || $action === 'overlay_update') {
                if ($action === 'overlay_create' && OverlayRepository::countForUser($uid) >= OverlayRepository::maxPerUser()) {
                    redirectWith('Has llegado al máximo de ' . OverlayRepository::maxPerUser() . ' tiras y banners. Borra alguno.', 's=tiras');
                }
                $upload = null;
                if ($action === 'overlay_create' && param($_POST, 'kind') === 'image') {
                    $upload = overlayUpload();
                    if (is_string($upload)) {
                        redirectWith($upload, 's=tiras');
                    }
                }
                $style = overlayStyleData($ov, $upload ? pathinfo($upload[1], PATHINFO_FILENAME) : '');
                if (is_string($style)) {
                    redirectWith($style, 's=tiras');
                }
                // Se crea SIN equipos ni fechas: destino y tiempo de visibilidad se eligen al asignar
                $data = $style + [
                    'user' => $uid,
                    'scope' => 'none',
                    'active' => $action === 'overlay_create' || (int) $ov['active'] === 1,
                    'starts' => null,
                    'ends' => null,
                    'users' => [],
                    'devices' => [],
                ];
                [$what, $g] = overlayNoun($style['kind']);
                if ($action === 'overlay_create') {
                    OverlayRepository::create($data, $upload[0] ?? null, $upload[1] ?? '', $upload[2] ?? null);
                    redirectWith("$what «{$style['name']}» cread$g. Ahora usa «Asignar a dispositivos» para elegir en qué pantallas y durante cuánto tiempo se muestra.", 's=tiras');
                }
                OverlayRepository::update($oid, $data);
                redirectWith("$what «{$style['name']}» guardad$g. Tus pantallas lo aplicarán en su próxima consulta (≈ 1 min).", 's=tiras');
            }
            if ($action === 'overlay_assign') {
                /* ---- Asignar a SUS equipos: sin asignar / todos / algunos ---- */
                $scope = OverlayRepository::pick(param($_POST, 'scope'), ['none', 'all', 'devices']);
                $chosen = isset($_POST['devices']) && is_array($_POST['devices'])
                    ? array_values(array_filter($_POST['devices'], 'is_string')) : [];
                if ($scope === 'devices' && !$chosen) {
                    redirectWith('Marca al menos un dispositivo, o elige "Todos mis dispositivos" o "Sin asignar".', 's=tiras');
                }
                // Tiempo de visibilidad de esta asignación: permanente o con fechas
                [$starts, $ends] = $scope === 'none' ? [null, null] : scheduleData('s=tiras');
                // assign() solo guarda equipos de este usuario
                OverlayRepository::assign($oid, $uid, $scope, [], $chosen, $starts, $ends);
                $fresh = OverlayRepository::all($uid);
                $n = 0;
                foreach ($fresh as $f) {
                    $n = (int) $f['id'] === $oid ? count($f['devices']) : $n;
                }
                [$what, $g] = overlayNoun((string) $ov['kind']);
                redirectWith($scope === 'none' ? "$what «{$ov['name']}» sin asignar: no se mostrará en ninguna pantalla."
                    : "$what «{$ov['name']}» asignad$g a " . ($scope === 'all' ? 'todos tus dispositivos' : "$n dispositivo(s)")
                    . ($starts === null && $ends === null ? ' de forma permanente' : ' con fechas')
                    . '. Se aplica en su próxima consulta (≈ 1 min).', 's=tiras');
            }
            [$what, $g] = overlayNoun((string) $ov['kind']);
            switch ($action) {
                case 'overlay_pause':
                case 'overlay_resume':
                    OverlayRepository::setActive($oid, $action === 'overlay_resume');
                    redirectWith($action === 'overlay_resume' ? "$what «{$ov['name']}» activad$g." : "$what «{$ov['name']}» pausad$g: tus pantallas lo quitarán en su próxima consulta.", 's=tiras');
                case 'overlay_delete':
                    OverlayRepository::delete($oid);
                    redirectWith("$what «{$ov['name']}» eliminad$g. Tus pantallas lo quitarán en su próxima consulta.", 's=tiras');
                default:
                    redirectWith('Acción desconocida.', 's=tiras');
            }
        } elseif (str_starts_with($action, 'media_')) {
            /* ---- Contenido para mirror.html (imágenes y videos) ---- */
            if ((int) $user['must_change_password'] === 1) {
                redirectWith('Primero elige tu contraseña.', 's=contenido');
            }
            $uid = (int) $user['id'];
            $mid = (int) param($_POST, 'mediaId');
            if ($action !== 'media_upload' && MediaRepository::findForUser($mid, $uid) === null) {
                redirectWith('Ese archivo no existe.', 's=contenido');
            }
            [$all, $chosen] = targetDevices();
            if (in_array($action, ['media_upload', 'media_update'], true) && !$all && !$chosen) {
                redirectWith('Marca al menos un dispositivo o elige "Todos mis dispositivos".', 's=contenido');
            }
            // Permanente o con fechas (solo al subir o editar)
            [$starts, $ends] = in_array($action, ['media_upload', 'media_update'], true) ? scheduleData() : [null, null];
            $when = static function () use ($starts, $ends): string {
                $s = MediaRepository::state(['starts_at' => $starts, 'ends_at' => $ends]);
                return ['permanent' => '', 'active' => ' Se muestra desde ya.',
                    'scheduled' => ' Programado: empezará a mostrarse en la fecha "Desde".',
                    'ended' => ' Atención: la fecha "Hasta" ya pasó, no se mostrará.'][$s];
            };
            switch ($action) {
                case 'media_upload':
                    $f = $_FILES['file'] ?? null;
                    $err = is_array($f) && is_int($f['error'] ?? null) ? $f['error'] : UPLOAD_ERR_NO_FILE;
                    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                        redirectWith('El archivo supera el límite de PHP (upload_max_filesize: ' . ini_get('upload_max_filesize') . ').', 's=contenido');
                    }
                    if ($err !== UPLOAD_ERR_OK) {
                        redirectWith($err === UPLOAD_ERR_NO_FILE ? 'Elige un archivo.' : 'La subida falló (código ' . $err . '). Inténtalo de nuevo.', 's=contenido');
                    }
                    $size = (int) $f['size'];
                    if ($size > MediaRepository::maxBytes()) {
                        redirectWith('El archivo supera el máximo de ' . formatBytes(MediaRepository::maxBytes()) . '.', 's=contenido');
                    }
                    // LÍMITES del PLAN (2026-11-22): sin plan vigente no se suben archivos nuevos;
                    // con plan, hasta su cantidad de archivos y su almacenamiento
                    $limits = PlanRepository::limits($user);
                    if ($limits === null) {
                        $ps = PlanRepository::forUser($user);
                        redirectWith(($ps['state'] === 'expired' ? 'Tu plan está vencido' : 'No tienes un plan asignado')
                            . ': no puedes subir archivos nuevos. Pide al administrador que ' . ($ps['state'] === 'expired' ? 'lo renueve.' : 'te asigne uno.'), 's=contenido');
                    }
                    if (MediaRepository::countForUser($uid) >= $limits['files']) {
                        redirectWith("Llegaste al límite de tu plan: {$limits['files']} archivo(s). Borra alguno o pide al administrador un plan mayor.", 's=contenido');
                    }
                    if (MediaRepository::usedBytes($uid) + $size > $limits['bytes']) {
                        redirectWith('No queda espacio en tu plan: tu límite es ' . formatBytes($limits['bytes'])
                            . ' y usas ' . formatBytes(MediaRepository::usedBytes($uid)) . '. Borra algún archivo.', 's=contenido');
                    }
                    $mime = MediaRepository::detectMime((string) $f['tmp_name']);
                    if ($mime === null) {
                        redirectWith('Tipo de archivo no admitido. Usa imágenes JPG, PNG, WEBP o GIF, o videos MP4 (H.264) o WEBM.', 's=contenido');
                    }
                    // Video que las TV no pueden mostrar fluido (60 fps, 4K, HEVC...): se rechaza
                    $videoProblem = MediaRepository::videoProblem((string) $f['tmp_name'], $mime);
                    if ($videoProblem !== null) {
                        redirectWith('«' . $f['name'] . '» no se subió. ' . $videoProblem, 's=contenido');
                    }
                    MediaRepository::create($uid, (string) $f['tmp_name'], (string) $f['name'], $mime,
                        (int) param($_POST, 'seconds'), $all, $chosen, $starts, $ends);
                    redirectWith('«' . $f['name'] . '» subido. Tus dispositivos lo descargarán en su próxima consulta.' . $when(), 's=contenido');
                case 'media_update':
                    MediaRepository::update($mid, $uid, (int) param($_POST, 'seconds'), $all, $chosen, $starts, $ends);
                    redirectWith('Cambios guardados.' . $when(), 's=contenido');
                case 'media_up':
                case 'media_down':
                    MediaRepository::move($mid, $uid, $action === 'media_up' ? -1 : 1);
                    redirectWith('Orden cambiado.', 's=contenido');
                case 'media_pause':
                case 'media_resume':
                    // Pausar / activar uno de SUS archivos (como las tiras)
                    $on = $action === 'media_resume';
                    MediaRepository::setActive($mid, $uid, $on);
                    $name = (string) (MediaRepository::findForUser($mid, $uid)['original_name'] ?? '');
                    redirectWith($on ? "«{$name}» activado: tus pantallas lo mostrarán en su próxima consulta."
                        : "«{$name}» pausado: tus pantallas lo quitarán en su próxima consulta. No se borra.", 's=contenido');
                case 'media_delete':
                    MediaRepository::delete($mid, $uid);
                    redirectWith('Archivo eliminado. Tus dispositivos dejarán de mostrarlo en su próxima consulta.', 's=contenido');
                default:
                    redirectWith('Acción desconocida.', 's=contenido');
            }
        } else {
            redirectWith('Acción desconocida.');
        }
    }

    $flash = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    $mustChange = $user !== null && (int) $user['must_change_password'] === 1;
    $devices = $user !== null && !$mustChange ? DeviceRepository::search(null, '', (int) $user['id']) : [];
    // Buscador de la pestaña Equipos (MAC del case, nombre o ID): SOLO entre sus equipos.
    // $devices sigue completo (contadores y selectores de contenido/tiras).
    $deviceQuery = mb_substr(trim(param($_GET, 'q')), 0, 40);
    $shownDevices = $deviceQuery !== '' && $devices ? DeviceRepository::search(null, $deviceQuery, (int) $user['id']) : $devices;
    // Contenido para mirror.html
    $mediaList = $user !== null && !$mustChange ? MediaRepository::forUser((int) $user['id']) : [];
    $usedBytes = $user !== null && !$mustChange ? MediaRepository::usedBytes((int) $user['id']) : 0;
    // Tiras y banners de sus pantallas
    $overlays = $user !== null && !$mustChange ? OverlayRepository::all((int) $user['id']) : [];
} catch (Throwable $e) {
    error_log('smartpizarra cuenta: ' . $e->getMessage());
    http_response_code(500);
    $error = $setupError !== ''
        ? 'El servicio no está disponible en este momento. Inténtalo más tarde.'
        : 'Error del servidor. Inténtalo más tarde.';
    $flash = '';
    $user = null;
    $mustChange = false;
    $devices = [];
    $deviceQuery = '';
    $shownDevices = [];
    $mediaList = [];
    $usedBytes = 0;
    $overlays = [];
}

$statusText = ['pending' => 'Pendiente de autorizar', 'authorized' => 'Autorizado', 'revoked' => 'Revocado'];

/*
 * Secciones (pestañas) de la página: ?s=inicio | equipos | contenido | tiras | plan | mensajes | cuenta.
 * Con la contraseña temporal solo existe "cuenta" (cambio obligatorio).
 * "plan" (Mi plan, 2026-11-22): datos del plan, uso e historial de renovaciones (solo lectura).
 */
$sections = ['inicio' => 'Inicio', 'equipos' => 'Dispositivos', 'contenido' => 'Contenido', 'tiras' => 'Tiras y banners', 'plan' => 'Mi plan', 'mensajes' => 'Mensajes', 'cuenta' => 'Mi cuenta'];
$section = param($_GET, 's');
if (!isset($sections[$section])) {
    $section = 'inicio';
}
if ($mustChange) {
    $section = 'cuenta';
}
// Buzón: al abrir Mensajes se marcan como leídos los del administrador
$msgThread = [];
$unreadMsgs = 0;
if ($user !== null && !$mustChange) {
    try {
        if ($section === 'mensajes') {
            MessageRepository::markRead((int) $user['id'], 'user');
            $msgThread = MessageRepository::thread((int) $user['id']);
        }
        $unreadMsgs = MessageRepository::unreadForUser((int) $user['id']);
    } catch (Throwable $e) {
        error_log('smartpizarra cuenta (mensajes): ' . $e->getMessage());
    }
}
// Resumen para Inicio
$authorizedCount = 0;
$mediaOnCount = 0;
$onlineCount = 0;
foreach ($devices as $d) {
    $authorizedCount += $d['status'] === 'authorized' ? 1 : 0;
    $mediaOnCount += ($d['status'] === 'authorized' && DeviceRepository::mediaEnabled($d)) ? 1 : 0;
    $onlineCount += (!empty($d['online_at']) && time() - utcTs($d['online_at']) < $onlineTimeout) ? 1 : 0;
}
// PLAN del usuario (2026-11-22): estado, límites (archivos y espacio) e historial.
// Sin plan vigente: límites 0 (no puede subir; lo ya subido sigue ahí).
$planState = $user !== null ? PlanRepository::forUser($user) : ['state' => 'none', 'plan' => null, 'days' => 0, 'until' => null];
$planLimits = $user !== null ? PlanRepository::limits($user) : null;
$planHistory = $user !== null && $section === 'plan' ? PlanRepository::history((int) $user['id']) : [];
// Todos los planes disponibles con sus características y comentario (Mi plan; solo lectura)
$allPlans = $user !== null && $section === 'plan' ? PlanRepository::all() : [];
$quota = $planLimits['bytes'] ?? 0;
$maxFiles = $planLimits['files'] ?? 0;
$atFileLimit = count($mediaList) >= $maxFiles;
// Ligas que le asignó el administrador (solo lectura; llegan a sus TV)
$myLeagues = $user !== null && in_array($section, ['cuenta', 'equipos'], true) ? LeagueRepository::forUser((int) $user['id']) : [];
// Vigencia del contenido (la asigna el administrador en días)
$validity = $user !== null ? contentValidity($user['content_until'] ?? null) : ['state' => 'none', 'days' => 0, 'text' => ''];
if ($validity['state'] !== 'active') {
    $mediaOnCount = 0; // sin vigencia ninguna pantalla muestra el contenido
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $user !== null ? h($sections[$section]) . ' - ' : '' ?>Mi cuenta - Smart Pizarra</title>
  <style nonce="<?= h($nonce) ?>">
<?= baseStyles() ?>

    .block { margin-bottom: 12px; }
    .narrow { max-width: 440px; }
    .hint { color: var(--muted); font-size: .85rem; }
    .who { color: var(--muted); font-size: .9rem; }
    /* Pestañas */
    .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--line); margin-bottom: 16px; overflow-x: auto; }
    .tabs a { padding: 10px 14px; color: var(--muted); text-decoration: none; border-bottom: 3px solid transparent;
      white-space: nowrap; font-weight: 500; }
    .tabs a.on { color: var(--accent); border-bottom-color: var(--accent); }
    .tabs .count { font-size: .8rem; color: var(--muted); margin-left: 4px; }
    /* Móvil: las pestañas se reparten el ancho (si no caben, se desplazan) */
    @media (max-width: 480px) {
      .tabs { gap: 0; }
      .tabs a { flex: 1 1 auto; padding: 10px 6px; font-size: .92rem; text-align: center; }
      .tabs .count { margin-left: 2px; }
    }
    .section-head { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
    .section-head h2 { margin: 0; }
    /* Inicio: tarjetas de resumen */
    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 12px; }
    .stat { text-decoration: none; color: var(--text); }
    .stat .num { font-size: 1.8rem; font-weight: 700; line-height: 1.1; }
    .stat .lbl { color: var(--muted); font-size: .9rem; }
    .stat:hover { border-color: var(--accent); }
    /* Vigencia: color según estado */
    .validity .vnum { font-size: 1.6rem; font-weight: 700; }
    .validity.active { border-color: var(--ok); } .validity.active .vnum { color: var(--ok); }
    .validity.expired { border-color: var(--bad); } .validity.expired .vnum { color: var(--bad); }
    .validity.none .vnum { color: var(--muted); }
    progress { width: 100%; height: 10px; accent-color: var(--accent); }
    /* Contenido */
    .targets { border: 1px solid var(--line); border-radius: 8px; padding: 8px 12px; margin: 10px 0; }
    .targets legend { font-size: .9rem; color: var(--muted); padding: 0 4px; }
    label.inline { display: inline-flex; gap: 6px; align-items: center; margin: 4px 14px 4px 0; color: var(--text); font-size: .95rem; }
    .device-checks { margin-left: 4px; }
    .media { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-start; }
    .thumb { width: 160px; height: 90px; border-radius: 8px; background: #000; object-fit: contain; flex: 0 0 auto;
      display: flex; align-items: center; justify-content: center; color: #fff; font-size: .9rem; text-decoration: none; }
    .media-body { flex: 1 1 260px; min-width: 0; }
    .media-name { font-weight: 600; overflow-wrap: anywhere; }
    .pos { display: inline-block; min-width: 1.8em; text-align: center; border-radius: 999px; background: var(--bg);
      border: 1px solid var(--line); font-size: .85rem; margin-right: 6px; }
    details.edit { margin-top: 8px; }
    details.edit summary { cursor: pointer; color: var(--accent); font-size: .95rem; }
    details.edit[open] summary { margin-bottom: 4px; }
    .upload h2 { margin-bottom: 4px; }
    .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
    .row > div { flex: 1 1 200px; }
    input[type=number] { width: 100px; padding: 9px 10px; font: inherit; color: var(--text);
      background: var(--bg); border: 1px solid var(--line); border-radius: 8px; }
    input[type=file] { font: inherit; color: var(--text); width: 100%; max-width: 100%; overflow: hidden; }
    .row > div { min-width: 0; }
    .two { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; align-items: start; }
    dl.data { display: grid; grid-template-columns: max-content 1fr; gap: 6px 14px; margin: 8px 0; }
    dl.data dt { color: var(--muted); }
    dl.data dd { margin: 0; overflow-wrap: anywhere; }
    /* Mi plan: datos del plan e historial de renovaciones (tabla con scroll propio en móvil) */
    dl.plan-data { display: grid; grid-template-columns: max-content 1fr; gap: 6px 14px; margin: 8px 0 12px; }
    dl.plan-data dt { color: var(--muted); }
    dl.plan-data dd { margin: 0; overflow-wrap: anywhere; }
    .table-wrap { overflow-x: auto; }
    /* Planes disponibles: tarjetas en rejilla (1 columna en móvil); el plan del usuario resaltado */
    .plan-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-bottom: 12px; }
    .plan-card { display: flex; flex-direction: column; gap: 6px; }
    .plan-card.mine { border-color: var(--ok); box-shadow: 0 0 0 1px var(--ok) inset; }
    .plan-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
    .plan-name { font-size: 1.15rem; }
    .plan-price { font-size: 1.3rem; font-weight: 700; }
    .plan-features { margin: 0; padding-left: 18px; }
    .plan-features li { margin: 2px 0; }
    .plan-comment { margin: 4px 0 0; color: var(--muted); font-size: .9rem; overflow-wrap: anywhere; }
    table.history { width: 100%; border-collapse: collapse; font-size: .92rem; }
    table.history th, table.history td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line, rgba(127,127,127,.25)); white-space: nowrap; }
    table.history th { color: var(--muted); font-weight: 600; }
    /* Fechas del contenido (Permanente / Con fechas) */
    .dates { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 0 12px; }
    .dates[hidden] { display: none; }
    .dates .hint { grid-column: 1 / -1; margin: 6px 0 2px; }
    input[type=datetime-local] { padding: 9px 10px; font: inherit; color: var(--text); background: var(--bg);
      border: 1px solid var(--line); border-radius: 8px; color-scheme: light; cursor: pointer; flex: 1 1 auto; min-width: 0; }
    @media (prefers-color-scheme: dark) { input[type=datetime-local] { color-scheme: dark; } }
    .date-field { display: flex; gap: 4px; align-items: stretch; }
    .date-field button { padding: 6px 10px; flex: 0 0 auto; }
    /* Liga de cada equipo (pestaña Equipos) */
    .league-form label { margin-top: 8px; }
    .league-row { display: flex; gap: 6px; flex-wrap: wrap; }
    .league-row select { flex: 1 1 220px; min-width: 0; font: inherit; padding: 8px 10px; border-radius: 8px;
      border: 1px solid var(--line); background: var(--bg); color: var(--text); }
    /* Nombre del equipo (pestaña Equipos) */
    .label-form { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
    /* Buscador de equipos por MAC del case y la MAC en la tarjeta */
    .file-limit { margin: 4px 0 10px; }
    .file-limit.full { color: var(--bad); }
    .msg-notice { display: block; text-decoration: none; color: inherit; border-left: 4px solid var(--accent); }
    .device-search { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
    .device-search input[type=search] { flex: 1 1 240px; min-width: 0; padding: 8px 10px; }
    .case-mac { font-family: ui-monospace, Consolas, monospace; }
    .label-form input[type=text] { flex: 1 1 220px; min-width: 0; padding: 8px 10px; }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    .badge.off { color: var(--muted); }
<?= overlayStyles() ?>
  </style>
</head>
<body>
<main>
  <header>
    <div>
      <h1>Smart Pizarra · Mi cuenta</h1>
      <?php if ($user !== null): ?><div class="who"><?= h($user['company']) ?> · <?= h($user['email']) ?></div><?php endif; ?>
    </div>
    <?php if ($user !== null): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="logout"><button type="submit">Salir</button></form>
    <?php endif; ?>
  </header>

<?php if ($user === null): ?>
  <?php if ($flash): ?><div class="card flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="card error"><?= h($error) ?></div><?php endif; ?>
  <!-- Entrada con el email y la contraseña que entregó el administrador -->
  <form class="card narrow" method="post" autocomplete="on">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="login">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" autocomplete="username" required>
    <label for="password">Contraseña</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>
    <p><button class="primary" type="submit">Entrar</button></p>
    <p class="hint">¿Olvidaste tu contraseña? Pide al administrador que la restablezca.</p>
  </form>
<?php else: ?>

  <?php if (!$mustChange): ?>
    <!-- Pestañas (enlaces normales: funcionan sin JavaScript) -->
    <nav class="tabs" aria-label="Secciones">
      <?php foreach ($sections as $key => $text): ?>
        <a class="<?= $section === $key ? 'on' : '' ?>" href="?s=<?= h($key) ?>" <?= $section === $key ? 'aria-current="page"' : '' ?>>
          <?= h($text) ?><?php if ($key === 'equipos'): ?><span class="count"><?= count($devices) ?></span><?php elseif ($key === 'contenido'): ?><span class="count"><?= count($mediaList) ?></span><?php elseif ($key === 'tiras'): ?><span class="count"><?= count($overlays) ?></span><?php elseif ($key === 'mensajes' && $unreadMsgs): ?><span class="unread"><?= (int) $unreadMsgs ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
  <?php if ($flash): ?><div class="card flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="card error"><?= h($error) ?></div><?php endif; ?>

  <?php if ($section === 'inicio'): ?>
    <!-- ======================= INICIO: resumen ======================= -->
    <?php if ($unreadMsgs): ?>
      <!-- Aviso de mensajes nuevos del administrador -->
      <a class="card block msg-notice" href="?s=mensajes">Tienes <strong><?= (int) $unreadMsgs ?></strong> mensaje(s) nuevo(s) del administrador. Ver mensajes &rarr;</a>
    <?php endif; ?>
    <div class="stats">
      <a class="card stat" href="?s=equipos"><div class="num"><?= count($devices) ?></div><div class="lbl">Dispositivos</div></a>
      <a class="card stat" href="?s=equipos"><div class="num"><?= $authorizedCount ?></div><div class="lbl">Autorizados</div></a>
      <a class="card stat" href="?s=equipos"><div class="num"><?= $onlineCount ?></div><div class="lbl">En línea ahora</div></a>
      <a class="card stat" href="?s=contenido"><div class="num"><?= count($mediaList) ?><?= $planLimits !== null ? '/' . $maxFiles : '' ?></div><div class="lbl">Archivos de contenido</div></a>
    </div>
    <!-- Vigencia: hasta cuándo se muestra el contenido en las pantallas -->
    <section class="card block validity <?= h($validity['state']) ?>">
      <h2>Vigencia del contenido</h2>
      <div class="vnum"><?= $validity['state'] === 'active' ? (int) $validity['days'] . ($validity['days'] === 1 ? ' día' : ' días') : ($validity['state'] === 'expired' ? 'Vencida' : 'Sin vigencia') ?></div>
      <div class="meta"><?= $validity['text'] ?>.
        <?= $validity['state'] === 'active'
            ? 'Tus pantallas muestran tu contenido hasta esa fecha.'
            : 'Tus pantallas no muestran tu contenido. Para renovarla, contacta al administrador.' ?></div>
    </section>
    <!-- Plan: nombre, días que quedan y espacio usado (detalle e historial en "Mi plan") -->
    <section class="card block validity <?= h($planState['state']) ?>">
      <h2>Mi plan</h2>
      <?php if ($planState['plan'] === null): ?>
        <div class="vnum">Sin plan</div>
        <div class="meta">No puedes subir archivos nuevos hasta que el administrador te asigne un plan.</div>
      <?php else: $pl = $planState['plan']; ?>
        <div class="vnum"><?= h($pl['name']) ?> · <?= $planState['state'] === 'active' ? (int) $planState['days'] . ($planState['days'] === 1 ? ' día' : ' días') : 'Vencido' ?></div>
        <?php if ($quota > 0): ?>
          <progress value="<?= (int) $usedBytes ?>" max="<?= (int) $quota ?>"></progress>
          <div class="meta">Espacio: usado <?= h(formatBytes($usedBytes)) ?> de <?= h(formatBytes($quota)) ?></div>
        <?php else: ?>
          <div class="meta">Plan vencido: no puedes subir archivos nuevos. Pide al administrador que lo renueve.</div>
        <?php endif; ?>
      <?php endif; ?>
      <div class="actions"><a class="btn" href="?s=plan">Ver mi plan e historial</a></div>
    </section>
    <section class="card block">
      <h2>¿Qué muestran mis pantallas?</h2>
      <div class="meta">
        <?= $mediaOnCount ?> de <?= count($devices) ?> dispositivo(s) pueden mostrar tus imágenes y videos
        (autorizados, con el contenido habilitado por el administrador y con la vigencia activa).
      </div>
      <div class="actions">
        <a class="btn" href="?s=contenido">Subir o cambiar contenido</a>
        <a class="btn" href="?s=equipos">Ver mis dispositivos</a>
      </div>
    </section>

  <?php elseif ($section === 'equipos'): ?>
    <!-- ============ EQUIPOS (solo el nombre es editable) ============ -->
    <div class="section-head">
      <h2>Mis dispositivos</h2>
      <span class="hint">Puedes ponerle un nombre a cada dispositivo y una liga a los que no tienen el contenido habilitado. Autorizar, revocar o habilitar el contenido lo hace el administrador.</span>
    </div>
    <?php if (!$devices): ?>
      <div class="card empty block">Todavía no tienes dispositivos asignados.</div>
    <?php else: ?>
      <!-- Buscar uno de MIS equipos por la MAC que sale en su case (o por su nombre o ID) -->
      <form method="get" class="device-search" role="search">
        <input type="hidden" name="s" value="equipos">
        <label for="dq" class="sr">Buscar dispositivo</label>
        <input type="search" id="dq" name="q" value="<?= h($deviceQuery) ?>" maxlength="40"
               placeholder="Buscar por MAC (p. ej. E8:BB:3E:C8:FB:5A), S/N, nombre o ID" spellcheck="false">
        <button type="submit">Buscar</button>
        <?php if ($deviceQuery !== ''): ?><a class="btn" href="?s=equipos">Ver todos</a><?php endif; ?>
      </form>
      <?php if ($deviceQuery !== ''): ?>
        <p class="hint"><?= $shownDevices
            ? count($shownDevices) . ' dispositivo(s) coinciden con «' . h($deviceQuery) . '».'
            : 'Ninguno de tus dispositivos coincide con «' . h($deviceQuery) . '».' ?></p>
      <?php endif; ?>
    <?php endif; ?>
    <?php foreach ($shownDevices as $d): $st = (string) $d['status']; $mOn = DeviceRepository::mediaEnabled($d); ?>
      <section class="card device">
        <div class="device-head">
          <span>
            <?= $d['label'] ? '<span class="alias">' . h($d['label']) . '</span>' : '' ?>
            <span class="device-id"><?= h($d['device_id']) ?></span>
          </span>
          <span>
            <span class="badge <?= $mOn ? 'authorized' : 'pending' ?>"><?= $mOn ? 'Contenido habilitado' : 'Contenido no habilitado' ?></span>
            <span class="badge <?= h($st) ?>"><?= h($statusText[$st] ?? $st) ?></span>
          </span>
        </div>
        <div class="meta">
          <!-- MAC impresa en el case (la registra el administrador en el perfil del equipo) -->
          MAC: <?= !empty($d['profile_case_mac'])
            ? '<span class="case-mac">' . h($d['profile_case_mac']) . '</span>'
            : '<em>sin registrar</em> <span class="hint">(la registra el administrador)</span>' ?><br>
          <?php if (!empty($d['profile_serial'])): ?>
            <!-- S/N de la caja (del perfil que registra el administrador; se puede buscar por él) -->
            S/N: <span class="case-mac"><?= h($d['profile_serial']) ?></span><br>
          <?php endif; ?>
          Última conexión: <?= onlineTag($d['online_at'] ?? null, $onlineTimeout) ?><br>
          Registrado el <?= dateTag($d['created_at']) ?>
          · Arranques: <?= (int) ($d['boots'] ?? 0) ?>
          <?= !empty($d['last_boot_at']) ? '(último ' . dateTag($d['last_boot_at']) . ')' : '' ?>
          <?= $d['app_version'] ? ' · v' . h($d['app_version']) : '' ?>
          <?= $mOn ? '' : '<br><span class="hint">Para mostrar tus imágenes y videos aquí, pide al administrador que habilite el contenido.</span>' ?>
        </div>
        <!-- Nombre del equipo (vacío = sin nombre, se muestra solo el ID) -->
        <form method="post" class="label-form" autocomplete="off"><?= csrfField() ?>
          <input type="hidden" name="action" value="device_label">
          <input type="hidden" name="deviceId" value="<?= h($d['device_id']) ?>">
          <label for="lbl-<?= h($d['device_id']) ?>" class="sr">Nombre del dispositivo</label>
          <input type="text" id="lbl-<?= h($d['device_id']) ?>" name="label" value="<?= h((string) $d['label']) ?>"
                 maxlength="100" placeholder="Nombre del dispositivo (p. ej. Local Centro)">
          <button type="submit">Guardar nombre</button>
        </form>
        <!-- Liga de este equipo: "Sin liga" o UNA de mis ligas (llega a la TV en ≈ 1 min).
             Con el CONTENIDO HABILITADO no se muestra (2026-11-22, petición del usuario):
             la TV muestra imágenes/videos y el usuario no elige liga. -->
        <?php if ($mOn): ?>
        <?php elseif (!$myLeagues): ?>
          <p class="hint">Liga: sin liga (el administrador todavía no te asignó ligas).</p>
        <?php else: $devLeague = LeagueRepository::deviceLeague($d); $did = h($d['device_id']); ?>
          <form method="post" class="league-form"><?= csrfField() ?>
            <input type="hidden" name="action" value="device_league">
            <input type="hidden" name="deviceId" value="<?= $did ?>">
            <label for="lg-<?= $did ?>">Liga de este dispositivo</label>
            <div class="league-row">
              <select id="lg-<?= $did ?>" name="leagueId">
                <option value="">Sin liga</option>
                <?php $lastType = null; foreach ($myLeagues as $l): ?>
                  <?php if ($lastType !== (int) $l['type_id']): ?>
                    <?= $lastType !== null ? '</optgroup>' : '' ?><optgroup label="<?= h($l['type_name']) ?>">
                  <?php $lastType = (int) $l['type_id']; endif; ?>
                  <option value="<?= (int) $l['id'] ?>" <?= $devLeague && (int) $devLeague['id'] === (int) $l['id'] ? 'selected' : '' ?>>
                    <?= h($l['name'] . ' — ' . str_replace(',', ', ', $l['codes'])) ?></option>
                <?php endforeach; ?>
                <?= $lastType !== null ? '</optgroup>' : '' ?>
              </select>
              <button type="submit">Guardar liga</button>
            </div>
          </form>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>

  <?php elseif ($section === 'contenido'): ?>
    <!-- ============ CONTENIDO PARA LAS PANTALLAS (mirror.html) ============
         Cada dispositivo descarga UNA vez sus archivos y los muestra en
         carrusel, en este orden, desde su propia memoria. -->
    <form class="card block upload" method="post" enctype="multipart/form-data" id="uploadForm">
      <?= csrfField() ?>
      <h2>Subir un archivo</h2>
      <?php if ($validity['state'] !== 'active'): ?>
        <p class="hint"><strong><?= $validity['state'] === 'expired' ? 'Tu vigencia está vencida' : 'No tienes vigencia asignada' ?>:</strong>
          puedes preparar tu contenido, pero las pantallas no lo mostrarán hasta que el administrador la renueve.</p>
      <?php endif; ?>
      <p class="hint">Imágenes JPG, PNG, WEBP o GIF y videos MP4 (H.264) o WEBM de hasta <?= h(formatBytes(MediaRepository::maxBytes())) ?>.
        Los videos se reproducen sin sonido. <strong>Videos: 30 fps como máximo</strong>, H.264 y hasta 1920×1080
        (los de 60 fps o 4K se ven a saltos en las pantallas y no se aceptan).</p>
      <!-- Límites del PLAN (cantidad de archivos y espacio); sin plan vigente no se sube nada nuevo -->
      <?php if ($planLimits === null): ?>
        <p class="file-limit full"><strong><?= $planState['state'] === 'expired' ? 'Tu plan «' . h($planState['plan']['name']) . '» está vencido' : 'No tienes un plan asignado' ?>:</strong>
          no puedes subir archivos nuevos. Pide al administrador que <?= $planState['state'] === 'expired' ? 'lo renueve' : 'te asigne uno' ?>. <a href="?s=plan">Mi plan</a></p>
      <?php else: ?>
        <p class="file-limit <?= $atFileLimit ? 'full' : '' ?>">Plan <?= h($planState['plan']['name']) ?> · Archivos: <strong><?= count($mediaList) ?> de <?= $maxFiles ?></strong>
          · Espacio: <?= h(formatBytes($usedBytes)) ?> de <?= h(formatBytes($quota)) ?>
          <?= $atFileLimit ? '· Llegaste al límite de tu plan: borra alguno o pide al administrador un plan mayor.' : '· Puedes subir ' . ($maxFiles - count($mediaList)) . ' más.' ?></p>
      <?php endif; ?>
      <input type="hidden" name="action" value="media_upload">
      <div class="row">
        <div>
          <label for="file">Archivo</label>
          <input type="file" id="file" name="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" required <?= $atFileLimit ? 'disabled' : '' ?>>
        </div>
        <div>
          <label for="seconds">Segundos en pantalla (solo imágenes)</label>
          <input type="number" id="seconds" name="seconds" value="10" min="<?= MediaRepository::MIN_SECONDS ?>" max="<?= MediaRepository::MAX_SECONDS ?>">
        </div>
      </div>
      <?= targetFields($devices, true, [], 'new') ?>
      <?= scheduleFields(null, 'new-') ?>
      <button class="primary" type="submit" id="uploadBtn" <?= $atFileLimit ? 'disabled' : '' ?>>Subir</button>
    </form>

    <div class="section-head">
      <h2>Mis archivos (<?= count($mediaList) ?><?= $planLimits !== null ? ' de ' . $maxFiles : '' ?>)</h2>
      <span class="hint">Usado <?= h(formatBytes($usedBytes)) ?><?= $planLimits !== null ? ' de ' . h(formatBytes($quota)) : '' ?> · se muestran en este orden</span>
    </div>
    <?php if (!$mediaList): ?>
      <div class="card empty block">Todavía no has subido nada. Usa «Subir un archivo».</div>
    <?php endif; ?>
    <?php foreach ($mediaList as $i => $m): $mid = (int) $m['id']; $isImg = $m['kind'] === 'image'; ?>
      <section class="card device">
        <div class="media">
          <?php if ($isImg): ?>
            <a href="?file=<?= $mid ?>" target="_blank" rel="noopener"><img class="thumb" src="?thumb=<?= $mid ?>" alt="" loading="lazy"></a>
          <?php else: ?>
            <a class="thumb" href="?file=<?= $mid ?>" target="_blank" rel="noopener">&#9654; Video</a>
          <?php endif; ?>
          <div class="media-body">
            <div class="media-name"><span class="pos"><?= $i + 1 ?></span><?= h($m['original_name']) ?></div>
            <div class="meta">
              <?= $isImg ? 'Imagen · ' . (int) $m['seconds'] . ' s' : 'Video' ?> · <?= h(formatBytes((int) $m['size_bytes'])) ?>
              · Subido el <?= dateTag($m['created_at']) ?><br>
              Se muestra en: <?= (int) $m['all_devices'] === 1 ? 'todos mis dispositivos' : count($m['devices']) . ' dispositivo(s)' ?><br>
              <!-- Pausado, o su periodo (permanente o con fechas) -->
              <?= MediaRepository::isActive($m) ? scheduleTag($m) : '<span class="badge off">Pausado</span> <span class="hint">no se muestra en tus pantallas</span>' ?>
            </div>
            <div class="actions">
              <!-- Pausar / activar (como las tiras y banners) -->
              <form method="post"><?= csrfField() ?><input type="hidden" name="mediaId" value="<?= $mid ?>">
                <input type="hidden" name="action" value="<?= MediaRepository::isActive($m) ? 'media_pause' : 'media_resume' ?>">
                <button class="<?= MediaRepository::isActive($m) ? 'bad' : 'ok' ?>" type="submit"><?= MediaRepository::isActive($m) ? 'Pausar' : 'Activar' ?></button></form>
              <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="media_up"><input type="hidden" name="mediaId" value="<?= $mid ?>">
                <button type="submit" <?= $i === 0 ? 'disabled' : '' ?> title="Mostrar antes">&#9650;</button></form>
              <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="media_down"><input type="hidden" name="mediaId" value="<?= $mid ?>">
                <button type="submit" <?= $i === count($mediaList) - 1 ? 'disabled' : '' ?> title="Mostrar después">&#9660;</button></form>
              <form method="post" data-confirm="¿Eliminar «<?= h($m['original_name']) ?>»? Tus pantallas dejarán de mostrarlo."><?= csrfField() ?>
                <input type="hidden" name="action" value="media_delete"><input type="hidden" name="mediaId" value="<?= $mid ?>">
                <button class="bad" type="submit">Eliminar</button></form>
            </div>
            <!-- Edición plegada: duración y equipos -->
            <details class="edit">
              <summary>Editar duración, dispositivos y fechas</summary>
              <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="media_update"><input type="hidden" name="mediaId" value="<?= $mid ?>">
                <?php if ($isImg): ?>
                  <label for="s<?= $mid ?>">Segundos en pantalla</label>
                  <input type="number" id="s<?= $mid ?>" name="seconds" value="<?= (int) $m['seconds'] ?>" min="<?= MediaRepository::MIN_SECONDS ?>" max="<?= MediaRepository::MAX_SECONDS ?>">
                <?php else: ?>
                  <input type="hidden" name="seconds" value="<?= (int) $m['seconds'] ?>">
                <?php endif; ?>
                <?= targetFields($devices, (int) $m['all_devices'] === 1, $m['devices'], 'm' . $mid . '-') ?>
                <?= scheduleFields($m, 'm' . $mid . '-') ?>
                <button class="primary" type="submit">Guardar cambios</button>
              </form>
            </details>
          </div>
        </div>
      </section>
    <?php endforeach; ?>

  <?php elseif ($section === 'tiras'): ?>
    <!-- ============ TIRAS Y BANNERS (franja arriba/abajo de mirror.html) ============ -->
    <details class="card block" <?= !$overlays ? 'open' : '' ?>>
      <summary><strong>+ Nueva tira o banner</strong></summary>
      <form method="post" enctype="multipart/form-data" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="overlay_create">
        <p class="hint">Una franja con un mensaje (tira) o una imagen (banner) arriba o abajo de la pantalla, sobre tus
          imágenes y videos. Se ve en tus dispositivos que muestran contenido (autorizados, con el contenido habilitado y vigencia activa).</p>
        <?= overlayFields(null, 'new-') ?>
        <p class="hint">Se crea <strong>sin dispositivos</strong>: después, en la lista, usa «Asignar a dispositivos» para elegir
          en qué pantallas y durante cuánto tiempo (permanente o con fechas) se muestra.</p>
        <button class="primary" type="submit">Crear</button>
      </form>
    </details>

    <div class="section-head">
      <h2>Mis tiras y banners (<?= count($overlays) ?>)</h2>
      <span class="hint">Máx. <?= OverlayRepository::maxPerUser() ?> · en cada pantalla se ve una arriba y una abajo
        (la más concreta y reciente). Si el administrador pone una en la misma posición, se ve la suya.</span>
    </div>
    <?php if (!$overlays): ?>
      <div class="card empty block">Todavía no tienes tiras ni banners.</div>
    <?php endif; ?>
    <?php foreach ($overlays as $o): $oid = (int) $o['id']; $o['_img'] = $o['kind'] === 'image' ? '?ovfile=' . $oid : ''; ?>
      <section class="card device">
        <?= overlayPreview($o, $o['_img']) ?>
        <div class="device-head">
          <span class="media-name"><?= h($o['name']) ?></span>
          <?= overlayTag($o) ?>
        </div>
        <div class="meta">
          <?= h(overlaySummary($o)) ?><br>
          <?php if (!overlayAssigned($o)): ?>
            <span class="badge pending">Sin asignar</span> no se muestra en ninguna pantalla
          <?php else: ?>
            Asignada a: <?= $o['scope'] === 'all' ? 'todos mis dispositivos' : count($o['devices']) . ' dispositivo(s)' ?>
          <?php endif; ?>
        </div>
        <div class="actions">
          <form method="post"><?= csrfField() ?><input type="hidden" name="overlayId" value="<?= $oid ?>">
            <input type="hidden" name="action" value="<?= (int) $o['active'] === 1 ? 'overlay_pause' : 'overlay_resume' ?>">
            <button class="<?= (int) $o['active'] === 1 ? 'bad' : 'ok' ?>" type="submit"><?= (int) $o['active'] === 1 ? 'Pausar' : 'Activar' ?></button></form>
          <form method="post" data-confirm="¿Eliminar «<?= h($o['name']) ?>»? Tus pantallas dejarán de mostrarlo."><?= csrfField() ?>
            <input type="hidden" name="overlayId" value="<?= $oid ?>"><input type="hidden" name="action" value="overlay_delete">
            <button class="bad" type="submit">Eliminar</button></form>
        </div>
        <!-- Asignación a equipos (aparte de la edición) -->
        <details class="edit" <?= overlayAssigned($o) ? '' : 'open' ?>>
          <summary>Asignar a dispositivos</summary>
          <form method="post"><?= csrfField() ?>
            <input type="hidden" name="action" value="overlay_assign"><input type="hidden" name="overlayId" value="<?= $oid ?>">
            <?= overlayAssignFields($o, $devices) ?>
            <!-- Tiempo de visibilidad de esta asignación -->
            <?= scheduleFields($o, 'as' . $oid . '-') ?>
            <button class="primary" type="submit">Guardar asignación</button>
          </form>
        </details>
        <details class="edit">
          <summary>Editar</summary>
          <form method="post" autocomplete="off"><?= csrfField() ?>
            <input type="hidden" name="action" value="overlay_update"><input type="hidden" name="overlayId" value="<?= $oid ?>">
            <?= overlayFields($o, 'o' . $oid . '-') ?>
            <button class="primary" type="submit">Guardar cambios</button>
          </form>
        </details>
      </section>
    <?php endforeach; ?>

  <?php elseif ($section === 'plan'): ?>
    <!-- ============ MI PLAN (solo lectura; lo asigna y renueva el administrador) ============ -->
    <section class="card block validity <?= h($planState['state']) ?>">
      <h2>Mi plan</h2>
      <?php if ($planState['plan'] === null): ?>
        <div class="vnum">Sin plan</div>
        <div class="meta">No tienes un plan asignado: puedes ver y ordenar lo que ya subiste, pero no subir archivos nuevos.
          Pide al administrador que te asigne uno.</div>
      <?php else: $pl = $planState['plan']; ?>
        <div class="vnum"><?= h($pl['name']) ?></div>
        <dl class="plan-data">
          <dt>Estado</dt><dd><?= $planState['state'] === 'active'
              ? '<strong>Vigente</strong> · quedan ' . (int) $planState['days'] . ($planState['days'] === 1 ? ' día' : ' días')
              : '<strong>Vencido</strong>: pide al administrador que lo renueve' ?></dd>
          <dt><?= $planState['state'] === 'active' ? 'Vence el' : 'Venció el' ?></dt><dd><?= $planState['until'] ? dateTag($planState['until']) : '—' ?></dd>
          <dt>Duración</dt><dd><?= (int) $pl['days'] ?> días por periodo</dd>
          <dt>Cantidad de contenido</dt><dd><?= (int) $pl['max_files'] ?> archivo(s) · tienes <?= count($mediaList) ?></dd>
          <dt>Almacenamiento</dt><dd><?= h(PlanRepository::storageText((int) $pl['storage_mb'])) ?> · usas <?= h(formatBytes($usedBytes)) ?></dd>
          <dt>Costo</dt><dd><?= h(PlanRepository::priceText($pl)) ?> por periodo</dd>
        </dl>
        <?php if ($planState['state'] === 'active'): ?>
          <progress value="<?= (int) $usedBytes ?>" max="<?= (int) ((int) $pl['storage_mb'] * 1048576) ?>"></progress>
        <?php endif; ?>
      <?php endif; ?>
      <p class="hint">La vigencia del contenido en tus pantallas: <?= $validity['text'] ?>. Al renovar el plan se suman sus días.</p>
    </section>
    <!-- PLANES DISPONIBLES (2026-11-22): todos, con sus características y el comentario del
         administrador; el suyo va marcado. Para cambiar de plan, el usuario se lo pide al administrador. -->
    <?php if ($allPlans): ?>
      <div class="section-head">
        <h2>Planes disponibles</h2>
        <span class="hint">Para cambiar de plan, escríbele al administrador en <a href="?s=mensajes">Mensajes</a>.</span>
      </div>
      <div class="plan-grid">
        <?php foreach ($allPlans as $ap): $mine = $planState['plan'] !== null && (int) $planState['plan']['id'] === (int) $ap['id']; ?>
          <section class="card plan-card<?= $mine ? ' mine' : '' ?>">
            <div class="plan-head">
              <strong class="plan-name"><?= h($ap['name']) ?></strong>
              <?php if ($mine): ?><span class="badge authorized">Tu plan</span><?php endif; ?>
            </div>
            <div class="plan-price"><?= h(PlanRepository::priceText($ap)) ?> <span class="hint">/ <?= (int) $ap['days'] ?> días</span></div>
            <ul class="plan-features">
              <li><?= (int) $ap['max_files'] ?> archivo(s) de contenido</li>
              <li><?= h(PlanRepository::storageText((int) $ap['storage_mb'])) ?> de almacenamiento</li>
              <li><?= (int) $ap['days'] ?> días por periodo</li>
            </ul>
            <?php if (!empty($ap['comment'])): ?><p class="plan-comment"><?= nl2br(h((string) $ap['comment'])) ?></p><?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <!-- Historial de asignaciones y renovaciones (datos del plan en ese momento) -->
    <section class="card block">
      <h2>Historial de renovaciones</h2>
      <?php if (!$planHistory): ?>
        <p class="hint">Todavía no hay renovaciones.</p>
      <?php else: ?>
        <div class="table-wrap"><table class="history">
          <tr><th>Fecha</th><th>Movimiento</th><th>Plan</th><th>Días</th><th>Costo</th><th>Vigente hasta</th></tr>
          <?php foreach ($planHistory as $r): ?>
            <tr>
              <td><?= dateTag($r['created_at']) ?></td>
              <td><?= $r['kind'] === 'renew' ? 'Renovación' : 'Asignación' ?></td>
              <td><?= h($r['plan_name']) ?> <span class="hint">(<?= (int) $r['max_files'] ?> archivos · <?= h(PlanRepository::storageText((int) $r['storage_mb'])) ?>)</span></td>
              <td>+<?= (int) $r['days'] ?></td>
              <td><?= h(PlanRepository::priceText($r)) ?></td>
              <td><?= dateTag($r['plan_until']) ?></td>
            </tr>
          <?php endforeach; ?>
        </table></div>
      <?php endif; ?>
    </section>

  <?php elseif ($section === 'mensajes'): ?>
    <!-- ======================= MENSAJES con el administrador ======================= -->
    <div class="section-head">
      <h2>Mensajes</h2>
      <span class="hint">Tu conversación con el administrador.</span>
    </div>
    <section class="card block">
      <?= messageThread($msgThread, 'user', 'Administrador') ?>
      <form method="post" class="msg-form" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="msg_send">
        <?= messageField('msg-body', 'Escribe al administrador…') ?>
        <div class="row"><button class="primary" type="submit">Enviar</button>
          <span class="hint">Máximo <?= MessageRepository::MAX_BODY ?> caracteres.</span></div>
      </form>
    </section>

  <?php else: ?>
    <!-- ======================= MI CUENTA ======================= -->
    <div class="two">
      <?php if (!$mustChange): ?>
        <section class="card">
          <h2>Mis datos</h2>
          <dl class="data">
            <dt>Empresa</dt><dd><?= h($user['company']) ?></dd>
            <dt>Email</dt><dd><?= h($user['email']) ?></dd>
            <dt>Teléfono</dt><dd><?= h($user['phone']) ?></dd>
            <dt>Cliente desde</dt><dd><?= dateTag($user['created_at']) ?></dd>
            <dt>Vigencia</dt><dd><?= $validity['text'] ?></dd>
            <!-- Ligas asignadas por el administrador (opciones del selector de ligas de sus TV) -->
            <dt>Ligas</dt><dd><?php if (!$myLeagues): ?>Ninguna asignada (tus dispositivos quedan sin liga)<?php else: ?>
              <?php foreach ($myLeagues as $i => $l): ?><?= $i ? '<br>' : '' ?><strong><?= h($l['type_name']) ?></strong> · <?= h($l['name']) ?>
                <span class="hint"><?= h(str_replace(',', ', ', $l['codes'])) ?></span><?php endforeach; ?>
            <?php endif; ?></dd>
          </dl>
          <p class="hint">Para cambiar estos datos, contacta al administrador.</p>
        </section>
      <?php endif; ?>
      <!-- Cambio de contraseña (obligatorio si es la temporal) -->
      <form class="card" method="post" autocomplete="off">
        <?= csrfField() ?>
        <h2><?= $mustChange ? 'Elige tu contraseña' : 'Cambiar contraseña' ?></h2>
        <?php if ($mustChange): ?>
          <p class="hint">Estás usando una contraseña temporal. Cámbiala para entrar a tu cuenta.</p>
        <?php endif; ?>
        <input type="hidden" name="action" value="password">
        <!-- Campo oculto con el email: ayuda a los gestores de contraseñas -->
        <input type="email" name="username" value="<?= h($user['email']) ?>" autocomplete="username" hidden>
        <label for="current"><?= $mustChange ? 'Contraseña temporal' : 'Contraseña actual' ?></label>
        <input type="password" id="current" name="current" autocomplete="current-password" required>
        <label for="new">Contraseña nueva (mínimo <?= UserRepository::MIN_PASSWORD ?> caracteres)</label>
        <input type="password" id="new" name="new" autocomplete="new-password" minlength="<?= UserRepository::MIN_PASSWORD ?>" required>
        <label for="confirm">Repite la contraseña nueva</label>
        <input type="password" id="confirm" name="confirm" autocomplete="new-password" minlength="<?= UserRepository::MIN_PASSWORD ?>" required>
        <p><button class="primary" type="submit">Guardar contraseña</button></p>
      </form>
    </div>
  <?php endif; ?>

  <script nonce="<?= h($nonce) ?>"><?= timeScript($onlineTimeout) ?></script>
  <script nonce="<?= h($nonce) ?>"><?= overlayScript() ?></script>
  <script nonce="<?= h($nonce) ?>"><?= deviceFilterScript() ?></script>
  <script nonce="<?= h($nonce) ?>"><?= messageScript() ?></script>
  <script nonce="<?= h($nonce) ?>">
  (function () {
    "use strict";
    // Al subir: botón desactivado y aviso (un video grande tarda)
    var form = document.getElementById("uploadForm");
    if (form) {
      form.addEventListener("submit", function () {
        var b = document.getElementById("uploadBtn");
        b.disabled = true;
        b.textContent = "Subiendo… no cierres la página";
      });
    }

    /*
     * Fechas del contenido (permanente o con fechas). El servidor guarda UTC:
     *  - input[data-tz]: diferencia del navegador con UTC (min) para convertir lo escrito.
     *  - input[data-utc]: fecha guardada (segundos UTC) => valor en hora local.
     *  - fieldset[data-when]: las fechas solo se ven con "Con fechas".
     *  - 📅 abre el calendario nativo (showPicker); × borra la fecha.
     */
    function pad2(n) { return (n < 10 ? "0" : "") + n; }
    document.querySelectorAll("input[data-tz]").forEach(function (el) {
      el.value = String(new Date().getTimezoneOffset());
    });
    document.querySelectorAll("input[data-utc]").forEach(function (el) {
      var ts = +el.getAttribute("data-utc");
      if (!ts) return;
      var d = new Date(ts * 1000);
      el.value = d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate()) +
        "T" + pad2(d.getHours()) + ":" + pad2(d.getMinutes());
    });
    document.querySelectorAll("fieldset[data-when]").forEach(function (fs) {
      var dates = fs.querySelector("[data-dates]");
      function show() {
        var r = fs.querySelector("input[name=when]:checked");
        dates.hidden = !r || r.value !== "dates";
      }
      fs.querySelectorAll("input[name=when]").forEach(function (r) { r.addEventListener("change", show); });
      show();
    });
    function openPicker(input) {
      try {
        if (typeof input.showPicker === "function") { input.showPicker(); return; }
      } catch (e) { /* no permitido: se enfoca el campo */ }
      input.focus();
    }
    document.querySelectorAll("input[type=datetime-local]").forEach(function (input) {
      input.addEventListener("click", function () { openPicker(input); });
    });
    document.querySelectorAll("button[data-pick]").forEach(function (b) {
      b.addEventListener("click", function () {
        var input = document.getElementById(b.getAttribute("data-pick"));
        if (input) openPicker(input);
      });
    });
    document.querySelectorAll("button[data-clear]").forEach(function (b) {
      b.addEventListener("click", function () {
        var input = document.getElementById(b.getAttribute("data-clear"));
        if (input) input.value = "";
      });
    });
  })();
  </script>
<?php endif; ?>
</main>
</body>
</html>

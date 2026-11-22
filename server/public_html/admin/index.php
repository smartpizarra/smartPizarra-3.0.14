<?php
// Versión del archivo: 1.0.7 (2026-11-22)
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
 *    (cualquier consulta de las páginas de la TV o señal de la app nativa, api/ping.php).
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
 *  - ANUNCIOS (botón "Anuncios", ?view=ads, 2026-11-11): el administrador
 *    sube imágenes/videos que mirror.html intercala después de cada N
 *    archivos del usuario (o muestra solos si el equipo no tiene contenido
 *    propio, vigencia o permiso). Destino: todos los equipos, equipos de
 *    ciertos usuarios o equipos concretos; fechas opcionales (hora del
 *    navegador, se guardan en UTC), pausar/activar, orden, editar,
 *    eliminar, vista previa (?ad=ID) y descarga (&dl=1). Solo equipos
 *    AUTORIZADOS. Ver AdRepository (private/lib.php) y api/media.php.
 *
 *  - TIRAS Y BANNERS (botón "Tiras", ?view=overlays, 2026-11-11): franja
 *    ARRIBA o ABAJO de mirror.html, tira de texto (colores, fija o
 *    desplazándose) o banner de imagen; pausar,
 *    editar, eliminar; vista previa del banner (?ov=ID). Se CREAN SIN
 *    EQUIPOS y luego se ASIGNAN ("Asignar a equipos": sin asignar, todos
 *    los equipos, los de ciertos usuarios o equipos concretos + TIEMPO DE
 *    VISIBILIDAD: permanente o con fechas, como el contenido de /cuenta/;
 *    ov_assign). Insignia Permanente/En curso/Programada/Finalizada/Pausada. Tienen prioridad
 *    sobre las del usuario en la misma posición. Debajo, las de TODOS los
 *    usuarios (solo pausar/activar y eliminar: moderación).
 *    Ver OverlayRepository (private/lib.php) y api/media.php.
 *
 *  - GESTIÓN DE RUTAS (botón "Rutas", ?view=routes, 2026-11-12): URL de
 *    contenido que se construye POR PARTES (URL base + agent, pageIndex,
 *    timer, showParams, otherQ Y/N, pageSize; vista previa de la URL final)
 *    y se asigna a uno o varios USUARIOS (cada usuario tiene una sola: si
 *    se le asigna otra, cambia). Todos los equipos del usuario la heredan;
 *    "leagues" se rellena con los códigos de la liga de CADA equipo.
 *    Acciones route_create / route_update / route_users / route_delete.
 *    Ver RouteRepository (private/lib.php).
 *
 *  - LIGAS (botón "Ligas", ?view=leagues, 2026-11-11): TIPOS de liga
 *    (código, p. ej. "mlb", que la TV guarda en smartpizarra.league, y su
 *    nombre) y LIGAS (nombre + tipo + contenido = códigos "LIDOM,MLB,KO").
 *    Se ASIGNAN a los usuarios en su ficha (varias por usuario). Después
 *    el USUARIO, en /cuenta/, pone a cada uno de sus equipos "Sin liga" o
 *    UNA de ellas; la TV la recibe (LeagueRepository::devicePayload) y la
 *    guarda (sin liga => ""). La TV ya no tiene ligas fijas ni selector.
 *    LIGA DE UN EQUIPO DESDE EL ADMIN (acción device_league, 2026-11-12):
 *    en la tarjeta de cada equipo, si su usuario YA tiene una ruta y
 *    ligas asignadas, el administrador también puede elegir "Sin liga" o
 *    UNA de las ligas de ESE usuario (las mismas que ve el usuario en
 *    /cuenta/; setDeviceLeague rechaza cualquier otra). La tarjeta
 *    muestra además la URL final que recibe el equipo.
 *
 *  - PAUSAR EL CONTENIDO (2026-11-12): en Contenido (?view=media) cada
 *    archivo de un usuario tiene "Pausar" / "Activar" (acciones
 *    media_pause / media_resume), igual que el usuario en /cuenta/.
 *
 *  - LÍMITE DE ARCHIVOS POR USUARIO (2026-11-12): cuántos archivos de
 *    contenido puede subir cada usuario desde /cuenta/ (por defecto 5).
 *    Se cambia en su ficha (sección Contenido, acción user_max_files).
 *    La lista de usuarios muestra "N/límite archivo(s)".
 *
 *  - VIDEOS FLUIDOS (2026-11-13): un anuncio MP4 de más de 30 fps, nivel
 *    H.264 > 4.1, HEVC o mayor de 1920×1080 se RECHAZA al subirlo
 *    (MediaRepository::videoProblem), igual que el contenido de /cuenta/.
 *
 *  - MUCHOS EQUIPOS (2026-11-13): la lista de dispositivos va PAGINADA
 *    (DEVICES_PER_PAGE por página, ?p=N; antes tope de 500) y cada tarjeta
 *    ya no consulta la base: la liga sale de las ligas de su usuario (una
 *    consulta por usuario) y la URL de la ruta de las rutas leídas una vez.
 *    Arriba, el estado de la COLA DE DESCARGAS (DownloadQueue).
 *
 *  - BUZÓN DE MENSAJES (botón "Mensajes", ?view=messages, 2026-11-12): una
 *    conversación por usuario (MessageRepository). Lista de conversaciones
 *    (la más reciente arriba, con los mensajes sin leer) y, al abrir una
 *    (?view=messages&mu=ID), la conversación y el formulario para
 *    responder; abrirla marca como leídos los mensajes del usuario.
 *    "Nuevo mensaje" escribe a un usuario o a TODOS. El botón de la
 *    cabecera muestra cuántos mensajes de usuarios hay sin leer.
 *    Acciones msg_send y msg_delete (borra la conversación).
 *
 *  - BUSCADOR DE EQUIPOS AL ASIGNAR (2026-11-12): las listas de casillas de
 *    equipos de Anuncios y Tiras tienen una caja que filtra mientras se
 *    escribe (alias, alias del admin, ID, empresa, MAC o MAC del case;
 *    deviceFilterBox / deviceFilterScript de web.php). Las marcadas que
 *    quedan ocultas siguen marcadas.
 *
 *  - ALIAS Y NOTAS DEL ADMINISTRADOR (2026-11-12): en cada equipo, aparte
 *    del alias/nombre "label" (que también ve y edita el usuario), un ALIAS
 *    ADICIONAL y NOTAS que solo ve el administrador (acción admin_info,
 *    DeviceRepository::setAdminInfo). El buscador del panel encuentra el
 *    equipo por ese alias (search(..., admin: true)); /cuenta/ no.
 *
 *  - PERFILES DE EQUIPO (botón "Perfiles", ?view=profiles, 2026-11-12):
 *    ficha de la caja física = marca, modelo, MAC impresa en el CASE y
 *    fecha de instalación (todos obligatorios; ProfileRepository). Se crea
 *    (con o sin equipo) y se asigna a UN equipo (desde la vista o desde la
 *    tarjeta del equipo; la tarjeta propone el perfil cuya MAC del case
 *    coincide con la MAC que envía la TV). AUTORIZAR un equipo por PRIMERA
 *    vez exige perfil (botón desactivado y el servidor lo rechaza;
 *    "Autorizar todos" se salta esos equipos). El buscador de equipos
 *    encuentra el equipo por la MAC del case, la marca o el modelo.
 *
 *  - MAC DE CADA EQUIPO (2026-11-12): la envía loading.html y el servidor
 *    la asigna a UN solo equipo (DeviceRepository::assignMac). La tarjeta
 *    muestra la MAC, desde cuándo y, si la tenía otro equipo (la misma TV
 *    antes de restablecerla), cuál. El buscador también busca por MAC
 *    (sin importar ":", "-", "." ni mayúsculas; vale un trozo).
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

/** Dispositivos por página en la lista del panel (muchos equipos). */
const DEVICES_PER_PAGE = 50;

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

// localToUtc() (fecha del navegador -> UTC) está en private/web.php: la usan admin y cuenta.

/**
 * Datos de un anuncio desde el formulario (subir o editar). Si algo no es
 * válido redirige con el mensaje (vuelve a la vista de anuncios).
 * @return array{name:string,seconds:int,every:int,scope:string,active:bool,starts:?string,ends:?string,users:int[],devices:string[]}
 */
function adFormData(string $fallbackName): array
{
    $name = AdRepository::cleanName(param($_POST, 'name')) ?? AdRepository::cleanName($fallbackName) ?? 'Anuncio';
    $scope = param($_POST, 'scope');
    if (!in_array($scope, AdRepository::SCOPES, true)) {
        $scope = 'all';
    }
    $users = array_values(array_filter(array_map('intval', (array) ($_POST['users'] ?? [])), static fn($v) => $v > 0));
    $devices = array_values(array_filter(array_map(static fn($v) => strtoupper(trim((string) $v)), (array) ($_POST['devices'] ?? [])),
        static fn($v) => DeviceRepository::isValidId($v)));
    if ($scope === 'users' && !$users) {
        redirectWith('Marca al menos un usuario o elige "Todos los equipos".', 'view=ads');
    }
    if ($scope === 'devices' && !$devices) {
        redirectWith('Marca al menos un equipo o elige "Todos los equipos".', 'view=ads');
    }
    $tz = (int) param($_POST, 'tz');
    $starts = localToUtc(param($_POST, 'starts'), $tz);
    $ends = localToUtc(param($_POST, 'ends'), $tz);
    if ($starts === false || $ends === false) {
        redirectWith('Fecha no válida.', 'view=ads');
    }
    if ($starts !== null && $ends !== null && $ends <= $starts) {
        redirectWith('La fecha de fin debe ser posterior a la de inicio.', 'view=ads');
    }
    return [
        'name' => $name,
        'seconds' => (int) param($_POST, 'seconds'),
        'every' => (int) param($_POST, 'every'),
        'scope' => $scope,
        'active' => param($_POST, 'active') === '1',
        'starts' => $starts,
        'ends' => $ends,
        'users' => $users,
        'devices' => $devices,
    ];
}

/**
 * Campos comunes del formulario de un anuncio (nombre, duración, cada N,
 * fechas, activo y DESTINO: todos / usuarios / equipos).
 * @param array<string,mixed>|null $ad      Anuncio a editar (null = nuevo).
 * @param array<int,array>         $users   UserRepository::all().
 * @param array<int,array>         $devices Todos los equipos.
 */
function adFields(?array $ad, array $users, array $devices): string
{
    $p = $ad ? 'a' . (int) $ad['id'] . '-' : 'new-';
    $scope = $ad['scope'] ?? 'all';
    $chosenUsers = array_flip($ad['users'] ?? []);
    $chosenDevices = array_flip($ad['devices'] ?? []);
    $utc = static fn(?string $v) => $v ? (string) utcTs($v) : '';
    $html = '<input type="hidden" name="tz" value="0" data-tz>'
        . '<div class="user-grid">'
        . '<div><label for="' . $p . 'name">Nombre (solo para el panel)</label>'
        . '<input type="text" id="' . $p . 'name" name="name" maxlength="100" value="' . h((string) ($ad['name'] ?? '')) . '" placeholder="p. ej. Promo diciembre"></div>'
        . '<div><label for="' . $p . 'every">Mostrar después de cada</label>'
        . '<span class="inline-num"><input type="number" id="' . $p . 'every" name="every" min="' . AdRepository::MIN_EVERY . '" max="' . AdRepository::MAX_EVERY . '" value="' . (int) ($ad['every_n'] ?? 3) . '" required> archivos del usuario</span></div>'
        . '<div><label for="' . $p . 'seconds">Segundos en pantalla (imagen)</label>'
        . '<input type="number" id="' . $p . 'seconds" name="seconds" min="' . MediaRepository::MIN_SECONDS . '" max="' . MediaRepository::MAX_SECONDS . '" value="' . (int) ($ad['seconds'] ?? 10) . '" required></div>'
        . '</div><div class="user-grid">'
        // Fechas: campo + botón que despliega el calendario + botón para borrarla
        . '<div><label for="' . $p . 'starts">Desde (opcional)</label>'
        . '<span class="date-field"><input type="datetime-local" id="' . $p . 'starts" name="starts" data-utc="' . $utc($ad['starts_at'] ?? null) . '">'
        . '<button type="button" data-pick="' . $p . 'starts" title="Abrir calendario" aria-label="Abrir calendario">&#128197;</button>'
        . '<button type="button" data-clear="' . $p . 'starts" title="Quitar fecha" aria-label="Quitar fecha">&times;</button></span></div>'
        . '<div><label for="' . $p . 'ends">Hasta (opcional)</label>'
        . '<span class="date-field"><input type="datetime-local" id="' . $p . 'ends" name="ends" data-utc="' . $utc($ad['ends_at'] ?? null) . '">'
        . '<button type="button" data-pick="' . $p . 'ends" title="Abrir calendario" aria-label="Abrir calendario">&#128197;</button>'
        . '<button type="button" data-clear="' . $p . 'ends" title="Quitar fecha" aria-label="Quitar fecha">&times;</button></span></div>'
        . '<div><label>&nbsp;</label><label class="inline"><input type="checkbox" name="active" value="1"' . (!$ad || (int) $ad['active'] === 1 ? ' checked' : '') . '> Activo</label></div>'
        . '</div>';
    // Destino
    $html .= '<fieldset class="targets" data-scope><legend>Mostrar en</legend>';
    foreach (['all' => 'Todos los equipos', 'users' => 'Equipos de estos usuarios', 'devices' => 'Estos equipos'] as $k => $text) {
        $html .= '<label class="inline"><input type="radio" name="scope" value="' . $k . '"' . ($scope === $k ? ' checked' : '') . '> ' . $text . '</label>';
    }
    $html .= '<div class="pick" data-for="users"><span class="owner">Usuarios (también sus equipos futuros):</span>';
    if (!$users) {
        $html .= '<span class="owner">No hay usuarios.</span>';
    }
    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $html .= '<label class="inline"><input type="checkbox" name="users[]" value="' . $uid . '"' . (isset($chosenUsers[$uid]) ? ' checked' : '') . '> '
            . h($u['company']) . ' <span class="owner">(' . (int) $u['devices'] . ')</span></label>';
    }
    // Buscador de equipos (alias, alias del admin, ID, empresa, MAC y MAC del case)
    $html .= '</div><div class="pick" data-for="devices"><span class="owner">Equipos:</span>'
        . (count($devices) > 1 ? deviceFilterBox('Buscar equipo por alias, ID, empresa, MAC o MAC del case') : '');
    if (!$devices) {
        $html .= '<span class="owner">No hay equipos.</span>';
    }
    foreach ($devices as $d) {
        $id = (string) $d['device_id'];
        $html .= '<label class="inline"' . deviceSearchAttr([$d['label'], $d['admin_alias'] ?? null, $id, $d['user_company']], [$d['mac'] ?? null, $d['profile_case_mac'] ?? null])
            . '><input type="checkbox" name="devices[]" value="' . h($id) . '"' . (isset($chosenDevices[$id]) ? ' checked' : '') . '> '
            . ($d['label'] ? h($d['label']) . ' ' : '') . '<span class="mono">' . h($id) . '</span>'
            . ($d['user_company'] ? ' <span class="owner">(' . h($d['user_company']) . ')</span>' : '') . '</label>';
    }
    return $html . '</div></fieldset>';
}

/** Casilla ACTIVA de una tira/banner del administrador (crear / editar). */
function overlayAdminFields(?array $o): string
{
    return '<label class="inline"><input type="checkbox" name="active" value="1"' . (!$o || (int) $o['active'] === 1 ? ' checked' : '') . '> Activa</label>';
}

/**
 * "Tiempo de visibilidad" de una ASIGNACIÓN (formulario "Asignar a
 * equipos"): PERMANENTE (por defecto) o CON FECHAS (desde / hasta, al menos
 * una), igual que el contenido de los usuarios en /cuenta/.
 */
function overlayAdminWhen(array $o): string
{
    $p = 'as' . (int) $o['id'] . '-';
    $hasDates = !empty($o['starts_at']) || !empty($o['ends_at']);
    $utc = static fn(?string $v) => $v ? (string) utcTs($v) : '';
    $date = static fn(string $name, string $text, ?string $value) =>
        '<div><label for="' . $p . $name . '">' . $text . '</label>'
        . '<span class="date-field"><input type="datetime-local" id="' . $p . $name . '" name="' . $name . '" data-utc="' . $utc($value) . '">'
        . '<button type="button" data-pick="' . $p . $name . '" title="Abrir calendario" aria-label="Abrir calendario">&#128197;</button>'
        . '<button type="button" data-clear="' . $p . $name . '" title="Quitar fecha" aria-label="Quitar fecha">&times;</button></span></div>';
    return '<fieldset class="targets" data-when><legend>Tiempo de visibilidad</legend>'
        . '<input type="hidden" name="tz" value="0" data-tz>'
        . '<label class="inline"><input type="radio" name="when" value="always"' . ($hasDates ? '' : ' checked') . '> Permanente</label>'
        . '<label class="inline"><input type="radio" name="when" value="dates"' . ($hasDates ? ' checked' : '') . '> Con fechas</label>'
        . '<div class="user-grid" data-dates>'
        . $date('starts', 'Desde (opcional)', $o['starts_at'] ?? null)
        . $date('ends', 'Hasta (opcional)', $o['ends_at'] ?? null)
        . '</div>'
        . '<span class="owner">Fuera de esas fechas las pantallas no la muestran (se aplica en su próxima consulta, ≈ 1 min). Hora de este navegador.</span>'
        . '</fieldset>';
}

/**
 * Insignia y texto del periodo de una tira/banner (como el contenido de
 * /cuenta/): Pausada, Permanente, En curso, Programada o Finalizada.
 */
function overlayWhenTag(array $o): string
{
    if ((int) $o['active'] !== 1) {
        return '<span class="badge off">Pausada</span>';
    }
    $state = MediaRepository::state($o); // permanent | active | scheduled | ended
    [$class, $label] = [
        'permanent' => ['authorized', 'Permanente'],
        'active' => ['authorized', 'En curso'],
        'scheduled' => ['pending', 'Programada'],
        'ended' => ['revoked', 'Finalizada'],
    ][$state];
    return '<span class="badge ' . $class . '">' . $label . '</span>';
}

/** Texto del periodo: "se muestra siempre" o "desde … · hasta …". */
function overlayWhenText(array $o): string
{
    if (empty($o['starts_at']) && empty($o['ends_at'])) {
        return 'Permanente: se muestra siempre';
    }
    return (!empty($o['starts_at']) ? 'Desde ' . dateTag((string) $o['starts_at']) : 'Desde ya')
        . ' · ' . (!empty($o['ends_at']) ? 'hasta ' . dateTag((string) $o['ends_at']) : 'sin fecha de fin');
}

/**
 * Formulario "Asignar a equipos" de una tira/banner del administrador:
 * sin asignar, todos los equipos, los de ciertos usuarios (también sus
 * equipos futuros) o equipos concretos.
 */
function overlayAdminTargets(array $o, array $users, array $devices): string
{
    $scope = (string) $o['scope'];
    $chosenUsers = array_flip($o['users']);
    $chosenDevices = array_flip($o['devices']);
    $html = '<fieldset class="targets" data-scope><legend>Mostrar en</legend>';
    foreach (['none' => 'Sin asignar', 'all' => 'Todos los equipos', 'users' => 'Equipos de estos usuarios', 'devices' => 'Estos equipos'] as $k => $text) {
        $html .= '<label class="inline"><input type="radio" name="scope" value="' . $k . '"' . ($scope === $k ? ' checked' : '') . '> ' . $text . '</label>';
    }
    $html .= '<div class="pick" data-for="users"><span class="owner">Usuarios (también sus equipos futuros):</span>';
    if (!$users) {
        $html .= '<span class="owner">No hay usuarios.</span>';
    }
    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $html .= '<label class="inline"><input type="checkbox" name="users[]" value="' . $uid . '"' . (isset($chosenUsers[$uid]) ? ' checked' : '') . '> '
            . h($u['company']) . ' <span class="owner">(' . (int) $u['devices'] . ')</span></label>';
    }
    // Buscador de equipos (alias, alias del admin, ID, empresa, MAC y MAC del case)
    $html .= '</div><div class="pick" data-for="devices"><span class="owner">Equipos:</span>'
        . (count($devices) > 1 ? deviceFilterBox('Buscar equipo por alias, ID, empresa, MAC o MAC del case') : '');
    if (!$devices) {
        $html .= '<span class="owner">No hay equipos.</span>';
    }
    foreach ($devices as $d) {
        $id = (string) $d['device_id'];
        $html .= '<label class="inline"' . deviceSearchAttr([$d['label'], $d['admin_alias'] ?? null, $id, $d['user_company']], [$d['mac'] ?? null, $d['profile_case_mac'] ?? null])
            . '><input type="checkbox" name="devices[]" value="' . h($id) . '"' . (isset($chosenDevices[$id]) ? ' checked' : '') . '> '
            . ($d['label'] ? h($d['label']) . ' ' : '') . '<span class="mono">' . h($id) . '</span>'
            . ($d['user_company'] ? ' <span class="owner">(' . h($d['user_company']) . ')</span>' : '') . '</label>';
    }
    return $html . '</div></fieldset>';
}

/**
 * Asignación de una tira/banner del administrador desde el formulario
 * "Asignar a equipos". Si falta marcar algo redirige a ?view=overlays.
 * @return array{0:string,1:int[],2:string[]} [scope, usuarios, equipos]
 */
function overlayAdminTargetData(): array
{
    $scope = OverlayRepository::pick(param($_POST, 'scope'), OverlayRepository::SCOPES);
    $users = array_values(array_filter(array_map('intval', (array) ($_POST['users'] ?? [])), static fn($v) => $v > 0));
    $devices = array_values(array_filter(array_map(static fn($v) => strtoupper(trim((string) $v)), (array) ($_POST['devices'] ?? [])),
        static fn($v) => DeviceRepository::isValidId($v)));
    if ($scope === 'users' && !$users) {
        redirectWith('Marca al menos un usuario, o elige otra opción.', 'view=overlays');
    }
    if ($scope === 'devices' && !$devices) {
        redirectWith('Marca al menos un equipo, o elige otra opción.', 'view=overlays');
    }
    return [$scope, $users, $devices];
}

/**
 * Activa de una tira/banner del administrador desde el formulario (crear o
 * editar). Una NUEVA queda sin asignar y sin fechas (se eligen al asignar).
 * @return array{user:null,scope:string,active:bool,starts:?string,ends:?string,users:int[],devices:string[]}
 */
function overlayAdminData(): array
{
    return [
        'user' => null,
        'scope' => 'none', // se asigna después (ov_assign)
        'active' => param($_POST, 'active') === '1',
        'starts' => null,
        'ends' => null,
        'users' => [],
        'devices' => [],
    ];
}

/**
 * Tiempo de visibilidad del formulario "Asignar a equipos": "Permanente"
 * => [null, null]; "Con fechas" => al menos una y hasta > desde. Si algo no
 * es válido redirige a ?view=overlays.
 * @return array{0:?string,1:?string} [desde, hasta] en UTC.
 */
function overlayAdminWhenData(): array
{
    // "Permanente" (o formulario sin el campo) => sin fechas
    $starts = null;
    $ends = null;
    if (param($_POST, 'when') === 'dates') {
        $tz = (int) param($_POST, 'tz');
        $starts = localToUtc(param($_POST, 'starts'), $tz);
        $ends = localToUtc(param($_POST, 'ends'), $tz);
        if ($starts === false || $ends === false) {
            redirectWith('Fecha no válida.', 'view=overlays');
        }
        if ($starts === null && $ends === null) {
            redirectWith('Indica al menos una fecha (desde o hasta) o elige "Permanente".', 'view=overlays');
        }
    }
    if ($starts !== null && $ends !== null && $ends <= $starts) {
        redirectWith('La fecha de fin debe ser posterior a la de inicio.', 'view=overlays');
    }
    return [$starts, $ends];
}

/** Texto del destino de una tira/banner (del administrador o de un usuario). */
function overlayTargetText(array $o, array $usersById): string
{
    if (!overlayAssigned($o)) {
        return 'Sin asignar: no se muestra en ninguna pantalla';
    }
    if ($o['user_id'] !== null) {
        return $o['scope'] === 'devices' ? 'Equipos: ' . ($o['devices'] ? implode(', ', $o['devices']) : 'ninguno') : 'Todos los equipos del usuario';
    }
    return adTargetText($o, $usersById);
}

/**
 * Campos de una RUTA (crear o editar): nombre, URL base y cada parámetro
 * por separado, con la VISTA PREVIA de la URL final (la arma el JS igual
 * que RouteRepository::buildUrl; leagues = liga de cada equipo).
 * @param array<string,mixed>|null $r Ruta (null = nueva: valores por defecto).
 */
function routeFields(?array $r, string $p): string
{
    $v = $r ?? RouteRepository::DEFAULTS + ['name' => ''];
    $in = static fn(string $name, string $label, string $type, string $value, string $extra = '') =>
        '<div><label for="' . $p . $name . '">' . $label . '</label><input type="' . $type . '" id="' . $p . $name . '" name="' . $name
        . '" value="' . h($value) . '" data-route="' . $name . '"' . $extra . '></div>';
    $num = ' min="0" max="' . RouteRepository::MAX_NUMBER . '" step="1" required';
    return '<div class="route-form" data-route-form>'
        . $in('name', 'Nombre (solo para el panel)', 'text', (string) $v['name'], ' maxlength="100" placeholder="p. ej. Pizarra deportes" required')
        . $in('base_url', 'URL base (sin "?" ni parámetros)', 'url', (string) $v['base_url'], ' maxlength="500" required')
        . '<div class="user-grid">'
        . $in('agent', 'agent (texto)', 'text', (string) $v['agent'], ' maxlength="100"')
        . $in('page_index', 'pageIndex (número)', 'number', (string) $v['page_index'], $num)
        . $in('timer', 'timer (número)', 'number', (string) $v['timer'], $num)
        . $in('show_params', 'showParams (texto)', 'text', (string) $v['show_params'], ' maxlength="200"')
        . '<div><label for="' . $p . 'other_q">otherQ</label><select id="' . $p . 'other_q" name="other_q" data-route="other_q">'
        . '<option value="N"' . ($v['other_q'] === 'Y' ? '' : ' selected') . '>N</option>'
        . '<option value="Y"' . ($v['other_q'] === 'Y' ? ' selected' : '') . '>Y</option></select></div>'
        . $in('page_size', 'pageSize (número)', 'number', (string) $v['page_size'], $num)
        . '</div>'
        . '<label>URL final (leagues = la liga de cada equipo, que elige el usuario en /cuenta/; sin liga queda vacío)</label>'
        . '<code class="route-url" data-route-preview>' . h(RouteRepository::buildUrl($v, '')) . '</code>'
        . '</div>';
}

/**
 * Casillas de USUARIOS de una ruta. Un usuario tiene una sola ruta: si ya
 * tiene otra se indica (al guardar, pasa a esta).
 * @param array<int,array<string,mixed>> $users UserRepository::all().
 * @param array<int,string> $routeNames id => nombre de las rutas.
 */
function routeUsersFields(array $r, array $users, array $routeNames): string
{
    $html = '<fieldset class="targets"><legend>Usuarios con esta ruta (y todos sus equipos)</legend>';
    if (!$users) {
        $html .= '<span class="owner">No hay usuarios.</span>';
    }
    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $cur = (int) ($u['route_id'] ?? 0);
        $html .= '<label class="inline"><input type="checkbox" name="users[]" value="' . $uid . '"' . ($cur === (int) $r['id'] ? ' checked' : '') . '> '
            . h($u['company']) . ' <span class="owner">(' . (int) $u['devices'] . ' equipo(s)'
            . ($cur && $cur !== (int) $r['id'] ? ' · ahora: ' . h($routeNames[$cur] ?? '#' . $cur) : '') . ')</span></label>';
    }
    return $html . '</fieldset>';
}

/** Texto del destino de un anuncio para la lista. */
function adTargetText(array $ad, array $usersById): string
{
    if ($ad['scope'] === 'users') {
        $names = array_map(static fn($id) => $usersById[$id]['company'] ?? ('#' . $id), $ad['users']);
        return 'Usuarios: ' . ($names ? implode(', ', $names) : 'ninguno');
    }
    if ($ad['scope'] === 'devices') {
        return 'Equipos: ' . ($ad['devices'] ? implode(', ', $ad['devices']) : 'ninguno');
    }
    return 'Todos los equipos';
}

/**
 * Campos de una liga (crear o editar): nombre, tipo y contenido (códigos).
 * @param array<string,mixed>|null $l     Liga (null = nueva).
 * @param array<int,array<string,mixed>> $types Tipos de liga.
 * @param string $p Prefijo único para los id de los campos.
 */
function leagueFields(?array $l, array $types, string $p): string
{
    $opts = '';
    foreach ($types as $t) {
        $opts .= '<option value="' . (int) $t['id'] . '"' . ($l && (int) $l['type_id'] === (int) $t['id'] ? ' selected' : '') . '>'
            . h($t['name'] . ' (' . $t['code'] . ')') . '</option>';
    }
    return '<div class="user-grid">'
        . '<div><label for="' . $p . 'name">Nombre</label>'
        . '<input type="text" id="' . $p . 'name" name="name" maxlength="100" required value="' . h((string) ($l['name'] ?? '')) . '" placeholder="Béisbol del Caribe"></div>'
        . '<div><label for="' . $p . 'type">Tipo</label><select id="' . $p . 'type" name="typeId" required>' . $opts . '</select></div>'
        . '</div>'
        . '<label for="' . $p . 'codes">Contenido: códigos de la liga (separados por coma, espacio o línea)</label>'
        . '<textarea id="' . $p . 'codes" name="codes" rows="3" required placeholder="LIDOM, MLB, KO, PRO">'
        . h(str_replace(',', ', ', (string) ($l['codes'] ?? ''))) . '</textarea>'
        . '<p class="owner">Se guardan en MAYÚSCULAS y sin repetir (máx. ' . LeagueRepository::MAX_CODES . '). '
        . 'La TV del equipo que use esta liga guarda el código de su tipo y estos códigos.</p>';
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
    // Archivo de un usuario: vista previa (?file=ID), descarga (&dl=1) o miniatura (?thumb=ID), solo administrador
    if ($loggedIn && (isset($_GET['file']) || isset($_GET['thumb']))) {
        $thumb = isset($_GET['thumb']);
        $media = MediaRepository::find((int) param($_GET, $thumb ? 'thumb' : 'file'));
        if ($media === null) {
            http_response_code(404);
            exit('No encontrado');
        }
        $thumb ? MediaRepository::sendThumb($media) : MediaRepository::send($media, param($_GET, 'dl') === '1');
    }
    // Archivo de un anuncio: vista previa (?ad=ID), descarga (&dl=1) o miniatura (?adthumb=ID), solo administrador
    if ($loggedIn && (isset($_GET['ad']) || isset($_GET['adthumb']))) {
        $thumb = isset($_GET['adthumb']);
        $ad = AdRepository::find((int) param($_GET, $thumb ? 'adthumb' : 'ad'));
        if ($ad === null) {
            http_response_code(404);
            exit('No encontrado');
        }
        $thumb ? MediaRepository::sendThumb($ad) : MediaRepository::send($ad, param($_GET, 'dl') === '1');
    }

    // Imagen de un banner (del administrador o de un usuario): ?ov=ID
    if ($loggedIn && isset($_GET['ov'])) {
        $ov = OverlayRepository::find((int) param($_GET, 'ov'));
        if ($ov === null || $ov['kind'] !== 'image') {
            http_response_code(404);
            exit('No encontrado');
        }
        MediaRepository::send($ov, param($_GET, 'dl') === '1');
    }

    /* -----------------------------------------------------------------
       Acciones (POST)
       ----------------------------------------------------------------- */
    // Subida (anuncio) más grande que post_max_size de PHP: llega sin datos ni CSRF
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        redirectWith('El archivo es demasiado grande para el servidor (límite de PHP post_max_size: '
            . ini_get('post_max_size') . ').', $loggedIn ? 'view=ads' : '');
    }
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
        } elseif ($action === 'media_pause' || $action === 'media_resume') {
            /* ---- Pausar / activar un archivo de un usuario (vista ?view=media) ---- */
            $back = param($_POST, 'back') !== '' ? param($_POST, 'back') : 'view=media';
            $on = $action === 'media_resume';
            $mid = (int) param($_POST, 'mediaId');
            if (!MediaRepository::setActive($mid, null, $on)) {
                redirectWith('Ese archivo ya no existe.', $back);
            }
            $m = MediaRepository::find($mid) ?? [];
            redirectWith('«' . ($m['original_name'] ?? '') . '» ' . ($on
                ? 'activado: sus pantallas lo mostrarán en su próxima consulta.'
                : 'pausado: sus pantallas lo quitarán en su próxima consulta (no se borra).'), $back);
        } elseif (str_starts_with($action, 'league_')) {
            /* ---- LIGAS y TIPOS DE LIGA (vista ?view=leagues) ---- */
            $typeId = (int) param($_POST, 'typeId');
            $leagueId = (int) param($_POST, 'leagueId');
            switch ($action) {
                case 'league_type_create':
                    $code = LeagueRepository::cleanTypeCode(param($_POST, 'code'));
                    $name = LeagueRepository::cleanName(param($_POST, 'name'), 60);
                    if ($code === null) {
                        redirectWith('Código no válido: de 1 a 20 letras minúsculas, cifras, _ o - (p. ej. mlb). «todos» está reservado.', 'view=leagues');
                    }
                    if ($name === null) {
                        redirectWith('El nombre del tipo debe tener entre 1 y 60 caracteres.', 'view=leagues');
                    }
                    if (!LeagueRepository::createType($code, $name)) {
                        redirectWith("Ya existe un tipo con el código «{$code}».", 'view=leagues');
                    }
                    redirectWith("Tipo de liga «{$name}» ({$code}) creado.", 'view=leagues');
                case 'league_type_rename':
                    $name = LeagueRepository::cleanName(param($_POST, 'name'), 60);
                    if ($name === null || LeagueRepository::findType($typeId) === null) {
                        redirectWith('Nombre no válido o el tipo ya no existe.', 'view=leagues');
                    }
                    LeagueRepository::renameType($typeId, $name);
                    redirectWith("Tipo renombrado a «{$name}». Las TV lo verán en su próxima consulta.", 'view=leagues');
                case 'league_type_up':
                case 'league_type_down':
                    LeagueRepository::moveType($typeId, $action === 'league_type_up' ? -1 : 1);
                    redirectWith('Orden de los tipos cambiado.', 'view=leagues');
                case 'league_type_delete':
                    $type = LeagueRepository::findType($typeId);
                    if ($type === null) {
                        redirectWith('Ese tipo ya no existe.', 'view=leagues');
                    }
                    if (!LeagueRepository::deleteType($typeId)) {
                        redirectWith("«{$type['name']}» tiene ligas: elimínalas o cámbialas de tipo antes de borrarlo.", 'view=leagues');
                    }
                    redirectWith("Tipo «{$type['name']}» eliminado.", 'view=leagues');
                case 'league_create':
                case 'league_update':
                    // Liga = nombre + tipo + contenido (códigos)
                    if ($action === 'league_update' && LeagueRepository::find($leagueId) === null) {
                        redirectWith('Esa liga ya no existe.', 'view=leagues');
                    }
                    $name = LeagueRepository::cleanName(param($_POST, 'name'), 100);
                    if ($name === null) {
                        redirectWith('El nombre de la liga debe tener entre 1 y 100 caracteres.', 'view=leagues');
                    }
                    if (LeagueRepository::findType($typeId) === null) {
                        redirectWith('Elige un tipo de liga (créalo antes si no existe).', 'view=leagues');
                    }
                    [$codes, $bad] = LeagueRepository::cleanCodes(param($_POST, 'codes'));
                    if ($codes === null) {
                        redirectWith('Escribe al menos un código válido (letras, cifras o _; p. ej. LIDOM, MLB, KO).', 'view=leagues');
                    }
                    if ($action === 'league_create') {
                        LeagueRepository::create($typeId, $name, $codes);
                    } else {
                        LeagueRepository::update($leagueId, $typeId, $name, $codes);
                    }
                    redirectWith("Liga «{$name}» " . ($action === 'league_create' ? 'creada' : 'guardada') . ': ' . str_replace(',', ', ', $codes) . '.'
                        . ($bad ? ' Se ignoraron por no ser válidos: ' . implode(' ', array_slice($bad, 0, 10)) . '.' : '')
                        . ($action === 'league_update' ? ' Las TV de sus usuarios lo verán en su próxima consulta.' : ''), 'view=leagues');
                case 'league_delete':
                    $league = LeagueRepository::find($leagueId);
                    if ($league === null) {
                        redirectWith('Esa liga ya no existe.', 'view=leagues');
                    }
                    LeagueRepository::delete($leagueId);
                    redirectWith("Liga «{$league['name']}» eliminada (y quitada de sus usuarios).", 'view=leagues');
                default:
                    redirectWith('Acción desconocida.', 'view=leagues');
            }
        } elseif (str_starts_with($action, 'msg_')) {
            /* ---- BUZÓN DE MENSAJES (vista ?view=messages) ---- */
            $to = param($_POST, 'userId');
            switch ($action) {
                case 'msg_send':
                    $body = MessageRepository::cleanBody(param($_POST, 'body'));
                    if ($body === null) {
                        redirectWith('Escribe el mensaje (máximo ' . MessageRepository::MAX_BODY . ' caracteres).',
                            ctype_digit($to) ? 'view=messages&mu=' . (int) $to : 'view=messages');
                    }
                    if ($to === 'all') {
                        $n = MessageRepository::sendToAll($body);
                        redirectWith("Mensaje enviado a {$n} usuario(s).", 'view=messages');
                    }
                    $target = ctype_digit($to) ? UserRepository::find((int) $to) : null;
                    if ($target === null) {
                        redirectWith('Elige un usuario.', 'view=messages');
                    }
                    MessageRepository::send((int) $target['id'], 'admin', $body);
                    redirectWith("Mensaje enviado a «{$target['company']}».", 'view=messages&mu=' . (int) $target['id']);
                case 'msg_delete':
                    $target = ctype_digit($to) ? UserRepository::find((int) $to) : null;
                    if ($target === null) {
                        redirectWith('Ese usuario ya no existe.', 'view=messages');
                    }
                    $n = MessageRepository::deleteThread((int) $target['id']);
                    redirectWith("Conversación con «{$target['company']}» borrada ({$n} mensaje(s)).", 'view=messages');
                default:
                    redirectWith('Acción desconocida.', 'view=messages');
            }
        } elseif (str_starts_with($action, 'profile_')) {
            /* ---- PERFILES DE EQUIPO (vista ?view=profiles y tarjeta del equipo) ---- */
            $back = param($_POST, 'back') !== '' ? param($_POST, 'back') : 'view=profiles';
            $profileId = (int) param($_POST, 'profileId');
            $profile = $action === 'profile_create' ? null : ProfileRepository::find($profileId);
            if ($action !== 'profile_create' && $profile === null) {
                redirectWith('Ese perfil ya no existe.', $back);
            }
            // Equipo elegido ("" = sin equipo)
            $devRaw = strtoupper(trim(param($_POST, 'deviceId')));
            $devId = DeviceRepository::isValidId($devRaw) ? $devRaw : null;
            if ($devRaw !== '' && $devId === null) {
                redirectWith('ID de dispositivo inválido.', $back);
            }
            switch ($action) {
                case 'profile_create':
                case 'profile_update':
                    $data = ProfileRepository::clean($_POST);
                    if (is_string($data)) {
                        redirectWith($data, $back);
                    }
                    if (ProfileRepository::macTaken($data['case_mac'], $profileId)) {
                        redirectWith("Ya hay un perfil con la MAC del case {$data['case_mac']}: búscala y asígnala en lugar de crear otro.", $back);
                    }
                    if ($action === 'profile_update') {
                        ProfileRepository::update($profileId, $data);
                        redirectWith('Perfil «' . ProfileRepository::text($data) . '» guardado.', $back);
                    }
                    $newId = ProfileRepository::create($data);
                    if ($devId !== null && !ProfileRepository::assign($newId, $devId)) {
                        redirectWith('Perfil creado, pero el equipo ' . $devId . ' no existe: queda sin equipo.', $back);
                    }
                    redirectWith('Perfil «' . ProfileRepository::text($data) . '» creado'
                        . ($devId !== null ? " y asignado a $devId. Ya se puede autorizar." : ' sin equipo: asígnalo cuando quieras.'), $back);
                case 'profile_assign':
                    if (!ProfileRepository::assign($profileId, $devId)) {
                        redirectWith("El equipo $devRaw no existe.", $back);
                    }
                    $txt = ProfileRepository::text($profile);
                    redirectWith($devId === null ? "Perfil «{$txt}» sin equipo."
                        : "Perfil «{$txt}» asignado a $devId"
                          . (!empty($profile['device_id']) && $profile['device_id'] !== $devId ? " (antes en {$profile['device_id']})." : '.'), $back);
                case 'profile_delete':
                    ProfileRepository::delete($profileId);
                    redirectWith('Perfil «' . ProfileRepository::text($profile) . '» eliminado'
                        . (!empty($profile['device_id']) ? "; {$profile['device_id']} queda sin perfil." : '.'), $back);
                default:
                    redirectWith('Acción desconocida.', $back);
            }
        } elseif (str_starts_with($action, 'route_')) {
            /* ---- GESTIÓN DE RUTAS (vista ?view=routes) ---- */
            $routeId = (int) param($_POST, 'routeId');
            $route = $action === 'route_create' ? null : RouteRepository::find($routeId);
            if ($action !== 'route_create' && $route === null) {
                redirectWith('Esa ruta ya no existe.', 'view=routes');
            }
            switch ($action) {
                case 'route_create':
                case 'route_update':
                    $data = RouteRepository::clean($_POST);
                    if (is_string($data)) {
                        redirectWith($data, 'view=routes');
                    }
                    if ($action === 'route_create') {
                        RouteRepository::create($data);
                        redirectWith("Ruta «{$data['name']}» creada. Asígnala a uno o varios usuarios: sus equipos la recibirán en su próxima consulta.", 'view=routes');
                    }
                    RouteRepository::update($routeId, $data);
                    redirectWith("Ruta «{$data['name']}» guardada. Los equipos de sus usuarios reciben la URL nueva en su próxima consulta (≈ 1 min).", 'view=routes');
                case 'route_users':
                    $n = RouteRepository::setUsers($routeId, (array) ($_POST['users'] ?? []));
                    redirectWith("Ruta «{$route['name']}»: $n usuario(s). Todos sus equipos la reciben en su próxima consulta (≈ 1 min).", 'view=routes');
                case 'route_delete':
                    RouteRepository::delete($routeId);
                    redirectWith("Ruta «{$route['name']}» eliminada; sus usuarios quedan sin ruta.", 'view=routes');
                default:
                    redirectWith('Acción desconocida.', 'view=routes');
            }
        } elseif (str_starts_with($action, 'ov_')) {
            /* ---- TIRAS Y BANNERS (vista ?view=overlays) ---- */
            $oid = (int) param($_POST, 'overlayId');
            $ov = $action === 'ov_create' ? null : OverlayRepository::find($oid);
            if ($action !== 'ov_create' && $ov === null) {
                redirectWith('Esa tira o banner ya no existe.', 'view=overlays');
            }
            // Las de los usuarios: el administrador solo puede pausar/activar o eliminar
            if (($action === 'ov_update' || $action === 'ov_assign') && $ov['user_id'] !== null) {
                redirectWith('Las tiras de los usuarios solo se pueden pausar o eliminar.', 'view=overlays');
            }
            [$what, $g] = overlayNoun((string) ($ov['kind'] ?? param($_POST, 'kind')));
            $owner = $ov !== null && $ov['user_id'] !== null ? ' de ' . ($usersById[(int) $ov['user_id']]['company'] ?? 'un usuario') : '';
            switch ($action) {
                case 'ov_create':
                case 'ov_update':
                    $upload = null;
                    if ($action === 'ov_create' && param($_POST, 'kind') === 'image') {
                        $upload = overlayUpload();
                        if (is_string($upload)) {
                            redirectWith($upload, 'view=overlays');
                        }
                    }
                    $style = overlayStyleData($ov, $upload ? pathinfo($upload[1], PATHINFO_FILENAME) : '');
                    if (is_string($style)) {
                        redirectWith($style, 'view=overlays');
                    }
                    $data = $style + overlayAdminData();
                    [$what, $g] = overlayNoun($style['kind']);
                    if ($action === 'ov_create') {
                        OverlayRepository::create($data, $upload[0] ?? null, $upload[1] ?? '', $upload[2] ?? null);
                        redirectWith("$what «{$style['name']}» cread$g. Ahora usa «Asignar a equipos» para elegir dónde y durante cuánto tiempo se muestra.", 'view=overlays');
                    }
                    OverlayRepository::update($oid, $data);
                    redirectWith("$what «{$style['name']}» guardad$g. Las pantallas lo aplican en su próxima consulta (≈ 1 min).", 'view=overlays');
                case 'ov_assign':
                    [$scope, $aUsers, $aDevices] = overlayAdminTargetData();
                    // Tiempo de visibilidad de esta asignación (permanente o con fechas)
                    [$starts, $ends] = $scope === 'none' ? [null, null] : overlayAdminWhenData();
                    OverlayRepository::assign($oid, null, $scope, $aUsers, $aDevices, $starts, $ends);
                    $text = ['none' => 'sin asignar: no se mostrará en ninguna pantalla', 'all' => 'asignad' . $g . ' a todos los equipos',
                        'users' => 'asignad' . $g . ' a los equipos de ' . count($aUsers) . ' usuario(s)',
                        'devices' => 'asignad' . $g . ' a ' . count($aDevices) . ' equipo(s)'][$scope];
                    redirectWith("$what «{$ov['name']}» $text" . ($scope === 'none' ? '.'
                        : ($starts === null && $ends === null ? ' de forma permanente.' : ' con fechas.') . ' Se aplica en la próxima consulta (≈ 1 min).'), 'view=overlays');
                case 'ov_pause':
                case 'ov_resume':
                    OverlayRepository::setActive($oid, $action === 'ov_resume');
                    redirectWith("$what «{$ov['name']}»$owner " . ($action === 'ov_resume' ? "activad$g." : "pausad$g."), 'view=overlays');
                case 'ov_delete':
                    OverlayRepository::delete($oid);
                    redirectWith("$what «{$ov['name']}»$owner eliminad$g. Las pantallas lo quitarán en su próxima consulta.", 'view=overlays');
                default:
                    redirectWith('Acción desconocida.', 'view=overlays');
            }
        } elseif (str_starts_with($action, 'ad_')) {
            /* ---- ANUNCIOS (vista ?view=ads) ---- */
            $adId = (int) param($_POST, 'adId');
            $ad = $action === 'ad_upload' ? null : AdRepository::find($adId);
            if ($action !== 'ad_upload' && $ad === null) {
                redirectWith('Ese anuncio ya no existe.', 'view=ads');
            }
            switch ($action) {
                case 'ad_upload':
                    $f = $_FILES['file'] ?? null;
                    $err = is_array($f) && is_int($f['error'] ?? null) ? $f['error'] : UPLOAD_ERR_NO_FILE;
                    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                        redirectWith('El archivo supera el límite de PHP (upload_max_filesize: ' . ini_get('upload_max_filesize') . ').', 'view=ads');
                    }
                    if ($err !== UPLOAD_ERR_OK) {
                        redirectWith($err === UPLOAD_ERR_NO_FILE ? 'Elige un archivo.' : 'La subida falló (código ' . $err . '). Inténtalo de nuevo.', 'view=ads');
                    }
                    if ((int) $f['size'] > MediaRepository::maxBytes()) {
                        redirectWith('El archivo supera el máximo de ' . formatBytes(MediaRepository::maxBytes()) . '.', 'view=ads');
                    }
                    $mime = MediaRepository::detectMime((string) $f['tmp_name']);
                    if ($mime === null) {
                        redirectWith('Tipo de archivo no admitido. Usa imágenes JPG, PNG, WEBP o GIF, o videos MP4 (H.264) o WEBM.', 'view=ads');
                    }
                    // Video que las TV no pueden mostrar fluido (60 fps, 4K, HEVC...): se rechaza
                    $videoProblem = MediaRepository::videoProblem((string) $f['tmp_name'], $mime);
                    if ($videoProblem !== null) {
                        redirectWith('«' . $f['name'] . '» no se subió. ' . $videoProblem, 'view=ads');
                    }
                    $data = adFormData(pathinfo((string) $f['name'], PATHINFO_FILENAME));
                    AdRepository::create((string) $f['tmp_name'], (string) $f['name'], $mime, $data);
                    redirectWith("Anuncio «{$data['name']}» subido. Los equipos lo descargarán en su próxima consulta (≈ 1 min).", 'view=ads');
                case 'ad_update':
                    $data = adFormData((string) $ad['name']);
                    AdRepository::update($adId, $data);
                    redirectWith("Anuncio «{$data['name']}» guardado. Se aplica en la próxima consulta de los equipos.", 'view=ads');
                case 'ad_pause':
                case 'ad_resume':
                    AdRepository::setActive($adId, $action === 'ad_resume');
                    redirectWith($action === 'ad_resume' ? "Anuncio «{$ad['name']}» activado." : "Anuncio «{$ad['name']}» pausado.", 'view=ads');
                case 'ad_up':
                case 'ad_down':
                    AdRepository::move($adId, $action === 'ad_up' ? -1 : 1);
                    redirectWith('Orden de los anuncios cambiado.', 'view=ads');
                case 'ad_delete':
                    AdRepository::delete($adId);
                    redirectWith("Anuncio «{$ad['name']}» eliminado. Las pantallas dejarán de mostrarlo en su próxima consulta.", 'view=ads');
                default:
                    redirectWith('Acción desconocida.', 'view=ads');
            }
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
                case 'user_max_files':
                    // LÍMITE de archivos de contenido que puede subir el usuario
                    $raw = param($_POST, 'maxFiles');
                    if (!ctype_digit($raw) || !UserRepository::setMaxFiles($uid, (int) $raw)) {
                        redirectWith('Indica un número de archivos entre 0 y ' . UserRepository::MAX_FILES_LIMIT . '.', $back);
                    }
                    $max = (int) $raw;
                    $has = MediaRepository::countForUser($uid);
                    redirectWith("«{$user['company']}» puede subir hasta {$max} archivo(s); ahora tiene {$has}."
                        . ($has > $max ? ' No se borra nada: no podrá subir más hasta tener menos de ' . $max . '.' : ''), $back);
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
                    // (los que se autorizarían por PRIMERA vez sin perfil se saltan)
                    $n = DeviceRepository::authorizeAllForUser($uid);
                    $skipped = DeviceRepository::countNeedingProfile($uid);
                    redirectWith("Autorizados {$n} equipo(s) de «{$user['company']}». "
                        . 'Lo detectarán en su próxima consulta.'
                        . ($skipped ? " {$skipped} equipo(s) sin perfil no se autorizaron: asígnales uno primero." : ''), $back);
                case 'user_leagues':
                    // Ligas del usuario (casillas de su ficha); llegan a sus TV autorizadas
                    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['leagues'] ?? [])), static fn($v) => $v > 0));
                    LeagueRepository::setForUser($uid, $ids);
                    $ids = LeagueRepository::idsForUser($uid); // las que existen de verdad
                    redirectWith(($ids ? 'Ligas de «' . $user['company'] . '» guardadas (' . count($ids) . ').'
                            : '«' . $user['company'] . '» queda sin ligas: sus equipos quedan sin liga.')
                        . ' Sus equipos lo recibirán en su próxima consulta.', $back);
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
                    // La PRIMERA autorización exige que el equipo tenga PERFIL
                    $device = DeviceRepository::find($id);
                    if ($device !== null && DeviceRepository::needsProfile($device)) {
                        redirectWith("$id no se puede autorizar por primera vez sin perfil: asígnale uno (marca, modelo, MAC del case y fecha de instalación).", $back);
                    }
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
                    redirectWith("Alias de $id guardado (también lo ve el usuario).", $back);
                case 'admin_info':
                    // Alias adicional + notas: SOLO para el administrador
                    DeviceRepository::setAdminInfo($id, param($_POST, 'adminAlias'), param($_POST, 'adminNotes'));
                    redirectWith("Alias y notas del administrador de $id guardados (el usuario no los ve).", $back);
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
                case 'device_league':
                    // Liga del equipo: "Sin liga" o UNA de las ligas del USUARIO dueño
                    // del equipo, solo si ese usuario ya tiene ruta y ligas asignadas.
                    $device = DeviceRepository::find($id);
                    $owner = $device !== null && !empty($device['user_id']) ? UserRepository::find((int) $device['user_id']) : null;
                    if ($owner === null) {
                        redirectWith("$id no tiene usuario: asígnale uno antes de elegir su liga.", $back);
                    }
                    if (empty($owner['route_id']) || !LeagueRepository::idsForUser((int) $owner['id'])) {
                        redirectWith("«{$owner['company']}» todavía no tiene ruta y ligas asignadas.", $back);
                    }
                    $raw = param($_POST, 'leagueId');
                    $leagueId = ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null; // "" = Sin liga
                    // setDeviceLeague solo acepta ligas asignadas al usuario del equipo
                    if (!LeagueRepository::setDeviceLeague($id, $leagueId)) {
                        redirectWith("Esa liga no está asignada a «{$owner['company']}».", $back);
                    }
                    $league = $leagueId === null ? null : LeagueRepository::find($leagueId);
                    redirectWith(($league === null ? "$id queda sin liga." : "$id usará la liga «{$league['name']}».")
                        . ' La TV recibe su URL en la próxima consulta (≈ 1 min si está mostrando contenido).', $back);
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
    $view = in_array(param($_GET, 'view'), ['users', 'media', 'ads', 'overlays', 'routes', 'leagues', 'profiles', 'messages'], true) ? param($_GET, 'view') : 'list';
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
    // Lista PAGINADA (muchos equipos): ?p=N, DEVICES_PER_PAGE por página
    $devTotal = $loggedIn && $view === 'list' ? DeviceRepository::searchCount($filter === 'all' ? null : $filter, $q, $userFilter, true) : 0;
    $devPages = max(1, (int) ceil($devTotal / DEVICES_PER_PAGE));
    $devPage = min($devPages, max(1, (int) param($_GET, 'p')));
    $back = http_build_query(array_filter(['filter' => $filter, 'q' => $q, 'user' => $userParam, 'p' => $devPage > 1 ? $devPage : null]));
    $devices = $loggedIn && $view === 'list'
        ? DeviceRepository::search($filter === 'all' ? null : $filter, $q, $userFilter, true, DEVICES_PER_PAGE, ($devPage - 1) * DEVICES_PER_PAGE)
        : [];
    // Estado de la cola de descargas (recuadro de la lista)
    $queueStatus = $loggedIn && $view === 'list' ? DownloadQueue::status() : null;
    // Cantidades de las pestañas: con búsqueda, solo de los equipos que coinciden
    $counts = $loggedIn ? DeviceRepository::counts($userFilter, $view === 'list' ? $q : '', true) : [];
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
    // Anuncios (?view=ads): lista + todos los equipos para elegir destino
    $ads = $loggedIn && $view === 'ads' ? AdRepository::all() : [];
    $allDevices = $loggedIn && ($view === 'ads' || $view === 'overlays') ? DeviceRepository::search(null, '', null) : [];
    // Tiras y banners (?view=overlays): las del administrador y, aparte, las de los usuarios
    $overlaysAll = $loggedIn && $view === 'overlays' ? OverlayRepository::all(null, true) : [];
    // Buzón de mensajes: conversación abierta (?mu=ID; al abrirla se marcan
    // como leídos los mensajes del usuario) y no leídos para el botón
    $msgUser = null;
    $thread = [];
    $conversations = [];
    if ($loggedIn && $view === 'messages') {
        $mu = param($_GET, 'mu');
        $msgUser = ctype_digit($mu) ? ($usersById[(int) $mu] ?? null) : null;
        if ($msgUser !== null) {
            MessageRepository::markRead((int) $msgUser['id'], 'admin');
            $thread = MessageRepository::thread((int) $msgUser['id']);
        }
        $conversations = MessageRepository::conversations();
    }
    $unreadAdmin = $loggedIn ? MessageRepository::unreadForAdmin() : 0;
    // Perfiles de equipo: la vista ?view=profiles (con su búsqueda ?pq= y
    // ?device= para crear uno ya asignado) y los perfiles LIBRES para el
    // selector de la tarjeta de cada equipo
    $pq = mb_substr(trim(param($_GET, 'pq')), 0, 40);
    $profiles = $loggedIn && $view === 'profiles' ? ProfileRepository::all($pq) : [];
    $profileDevice = strtoupper(trim(param($_GET, 'device')));
    $profileDevice = DeviceRepository::isValidId($profileDevice) ? $profileDevice : '';
    $profileDevices = $loggedIn && $view === 'profiles' ? DeviceRepository::search(null, '', null) : [];
    usort($profileDevices, static fn($a, $b) => strcmp((string) $a['device_id'], (string) $b['device_id']));
    $freeProfiles = $loggedIn && $view === 'list' ? ProfileRepository::free() : [];
    // Rutas (?view=routes) y nombre de la ruta de cada usuario (ficha)
    // (también en la lista: URL final de cada tarjeta sin consultar por equipo)
    $routes = $loggedIn && ($view === 'routes' || $view === 'list' || $userCard) ? RouteRepository::all() : [];
    $routesById = array_column($routes, null, 'id');
    $routeNames = array_column($routes, 'name', 'id');
    usort($allDevices, static fn($a, $b) => strcmp((string) $a['user_company'] . $a['device_id'], (string) $b['user_company'] . $b['device_id']));
    // Ligas: vista ?view=leagues y sección "Ligas" de la ficha de un usuario
    $needLeagues = $loggedIn && ($view === 'leagues' || $userCard);
    $leagueTypes = $needLeagues ? LeagueRepository::types() : [];
    $leagues = $needLeagues ? LeagueRepository::leagues() : [];
    $userLeagueIds = $loggedIn && $userCard ? LeagueRepository::idsForUser((int) $userCard['id']) : [];
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
    // Al administrador con sesión se le muestra además el detalle técnico
    // (para no depender del registro de hPanel); sin sesión, solo el genérico.
    $error = $setupError !== ''
        ? 'Problema de instalación: ' . $setupError
        : 'Error del servidor. Revisa el registro de errores de PHP en hPanel.'
          . (!empty($loggedIn) ? ' Detalle: ' . mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 300) : '');
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
    $ads = [];
    $allDevices = [];
    $overlaysAll = [];
    $routes = [];
    $routeNames = [];
    $leagueTypes = [];
    $leagues = [];
    $userLeagueIds = [];
    $devTotal = 0;
    $devPages = 1;
    $devPage = 1;
    $queueStatus = null;
    $routesById = [];
    $pq = '';
    $profiles = [];
    $profileDevice = '';
    $profileDevices = [];
    $freeProfiles = [];
    $msgUser = null;
    $thread = [];
    $conversations = [];
    $unreadAdmin = 0;
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
    /* Cola de descargas y paginación (muchos equipos) */
    .queue-box { margin-bottom: 10px; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--line); font-size: .9rem; }
    .queue-box.busy { border-color: var(--warn); }
    .pager { display: flex; gap: 10px; align-items: center; justify-content: center; flex-wrap: wrap; margin: 14px 0; }
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
    .device-mac { font-family: ui-monospace, Consolas, monospace; } /* MAC del equipo */
    /* Alias y notas del administrador (solo panel) */
    .admin-alias { font-weight: 600; margin-right: 8px; padding: 1px 8px; border-radius: 999px;
      border: 1px dashed var(--line); color: var(--muted); }
    .admin-notes { margin-top: 6px; padding: 6px 10px; border-left: 3px solid var(--warn, #b45309);
      background: var(--bg); overflow-wrap: anywhere; }
    /* Buzón: lista de conversaciones */
    a.conv { display: block; color: inherit; text-decoration: none; margin-bottom: 8px; }
    a.conv:hover { border-color: var(--accent); }
    a.conv.conv-new { border-left: 4px solid var(--accent); }
    .conv-head { display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; }
    .conv-head .owner { margin-left: auto; }
    .conv-last { display: block; margin-top: 4px; overflow-wrap: anywhere; }
    /* Perfiles de equipo */
    .need-profile { color: var(--bad, #c0392b); }
    input[type=date] { padding: 9px 10px; font: inherit; color: var(--text); background: var(--bg);
      border: 1px solid var(--line); border-radius: 8px; width: 100%; color-scheme: light; }
    @media (prefers-color-scheme: dark) { input[type=date] { color-scheme: dark; } }
    .profile-search { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
    .profile-search input { flex: 1 1 220px; min-width: 0; }
    /* Liga de un equipo (tarjeta del equipo): etiqueta + selector en una fila */
    .league-form { align-items: center; flex-wrap: wrap; margin: 4px 0; }
    .league-form select { flex: 1 1 220px; min-width: 0; padding: 6px 8px; font: inherit; color: var(--text);
      background: var(--bg); border: 1px solid var(--line); border-radius: 8px; }
    .meta a { color: var(--accent); } /* enlaces legibles también en modo oscuro */
    /* Ligas (?view=leagues y ficha del usuario) */
    .type-row { display: flex; gap: 6px 8px; align-items: center; flex-wrap: wrap; padding: 8px 0; border-bottom: 1px solid var(--line); }
    .type-row form { margin: 0; }
    .type-code { font: 600 .95rem ui-monospace, Consolas, monospace; min-width: 5em; }
    .type-rename { display: flex; gap: 6px; flex: 1 1 220px; }
    .type-rename input { padding: 7px 10px; }
    .type-new { margin-top: 10px; }
    .group-title { margin: 16px 0 8px; font-size: 1rem; }
    .codes { display: flex; gap: 6px; flex-wrap: wrap; margin: 8px 0 4px; }
    .code-chip { font: .85rem ui-monospace, Consolas, monospace; padding: 2px 8px; border-radius: 6px;
      border: 1px solid var(--line); background: var(--bg); }
    .league-type-title { font-weight: 600; margin: 10px 0 2px; }
    .league-check { display: flex; gap: 8px; align-items: flex-start; margin: 4px 0; color: var(--text); font-size: .95rem; }
    .league-check input { margin-top: 4px; }
    textarea { width: 100%; padding: 10px 12px; font: inherit; color: var(--text); background: var(--bg);
      border: 1px solid var(--line); border-radius: 8px; resize: vertical; }
<?= overlayStyles() ?>
    /* Rutas (?view=routes): URL final en una caja que corta en cualquier punto */
    .route-url { display: block; padding: 8px 10px; margin: 6px 0; border-radius: 8px; background: var(--bg);
      border: 1px solid var(--line); font: .85rem/1.4 ui-monospace, Consolas, monospace; overflow-wrap: anywhere; word-break: break-all; }
    .route-form select { width: 100%; padding: 9px 10px; font: inherit; color: var(--text); background: var(--bg);
      border: 1px solid var(--line); border-radius: 8px; }
    input[type=url] { width: 100%; padding: 10px 12px; font: inherit; color: var(--text); background: var(--bg);
      border: 1px solid var(--line); border-radius: 8px; }
    .ov-list-title { margin: 18px 0 8px; }
    [data-dates][hidden] { display: none; } /* fechas ocultas con "Permanente" */
    /* Anuncios (?view=ads) */
    .hidden-tools { display: none; }
    .targets { border: 1px solid var(--line); border-radius: 8px; padding: 8px 12px; margin: 10px 0; }
    .targets legend { font-size: .9rem; color: var(--muted); padding: 0 4px; }
    label.inline { display: inline-flex; gap: 6px; align-items: center; margin: 4px 14px 4px 0; color: var(--text); font-size: .95rem; }
    .pick { margin-top: 6px; padding-top: 6px; border-top: 1px dashed var(--line); max-height: 220px; overflow-y: auto; }
    .pick > .owner:first-child { display: block; }
    .pick.hide { display: none; } /* JS oculta la lista que no corresponde al destino elegido */
    .mono { font-family: ui-monospace, Consolas, monospace; font-size: .9em; }
    .inline-num { display: flex; gap: 6px; align-items: center; color: var(--muted); font-size: .9rem; }
    input[type=number], input[type=datetime-local] { padding: 9px 10px; font: inherit; color: var(--text);
      background: var(--bg); border: 1px solid var(--line); border-radius: 8px; max-width: 100%; }
    input[type=number] { width: 100px; }
    /* Fechas: el calendario nativo toma los colores del tema (icono visible en modo oscuro) */
    input[type=datetime-local] { color-scheme: light; cursor: pointer; flex: 1 1 auto; min-width: 0; }
    @media (prefers-color-scheme: dark) { input[type=datetime-local] { color-scheme: dark; } }
    .date-field { display: flex; gap: 4px; align-items: stretch; }
    .date-field button { padding: 6px 10px; flex: 0 0 auto; }
    input[type=file] { font: inherit; color: var(--text); width: 100%; max-width: 100%; margin-bottom: 4px; }
    .pos { display: inline-block; min-width: 1.8em; text-align: center; border-radius: 999px; background: var(--bg);
      border: 1px solid var(--line); font-size: .85rem; margin-right: 6px; }
    details.edit { margin-top: 8px; }
    details.edit summary { cursor: pointer; color: var(--accent); font-size: .95rem; }
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
      <a class="btn <?= $view === 'ads' ? 'on' : '' ?>" href="?view=ads">Anuncios</a>
      <a class="btn <?= $view === 'overlays' ? 'on' : '' ?>" href="?view=overlays">Tiras</a>
      <a class="btn <?= $view === 'routes' ? 'on' : '' ?>" href="?view=routes">Rutas</a>
      <a class="btn <?= $view === 'leagues' ? 'on' : '' ?>" href="?view=leagues">Ligas</a>
      <a class="btn <?= $view === 'profiles' ? 'on' : '' ?>" href="?view=profiles">Perfiles</a>
      <!-- Buzón: número de mensajes de usuarios sin leer -->
      <a class="btn <?= $view === 'messages' ? 'on' : '' ?>" href="?view=messages">Mensajes<?= $unreadAdmin ? '<span class="unread">' . (int) $unreadAdmin . '</span>' : '' ?></a>
      <!-- Agente launcher de las TV (instalaciones remotas de APK): página propia agentes.php -->
      <a class="btn" href="agentes.php">Agentes</a>
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

  <!-- Buscador (oculto en Anuncios, Tiras, Rutas y Ligas: no aplica; se deja en la página porque el escáner lo usa) -->
  <form class="tools<?= in_array($view, ['ads', 'overlays', 'routes', 'leagues', 'profiles', 'messages'], true) ? ' hidden-tools' : '' ?>" method="get">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="<?= $view === 'users' ? 'Buscar usuario por empresa, email o teléfono' : 'Buscar por ID, alias, alias del admin, empresa, email, MAC o MAC del case' ?>" aria-label="Buscar">
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
        <div class="stat"><b><?= (int) $userCard['media'] ?>/<?= UserRepository::maxFiles($userCard) ?></b><span>Archivos</span></div>
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
      <div class="meta"><?= (int) $userCard['media'] ?> de <?= UserRepository::maxFiles($userCard) ?> archivo(s) subido(s) desde /cuenta/.</div>
      <!-- Límite de archivos que puede subir (por defecto 5) -->
      <form method="post" class="days-form"><?= csrfField() ?>
        <input type="hidden" name="action" value="user_max_files"><input type="hidden" name="userId" value="<?= $uid ?>">
        <input type="hidden" name="back" value="<?= h($userBack) ?>">
        <label for="maxf<?= $uid ?>">Límite de archivos</label>
        <input type="number" id="maxf<?= $uid ?>" name="maxFiles" value="<?= UserRepository::maxFiles($userCard) ?>" min="0" max="<?= UserRepository::MAX_FILES_LIMIT ?>" required>
        <button class="ok" type="submit">Guardar límite</button>
      </form>
      <div class="actions">
        <a class="btn" href="<?= h(panelUrl(['view' => 'media', 'user' => $uid])) ?>">Ver su contenido</a>
      </div>
    </section>

    <!-- Ruta del usuario (una): todos sus equipos la reciben; se gestiona en Rutas -->
    <section class="card section">
      <h2>Ruta</h2>
      <div class="meta">
        <?php if (!empty($userCard['route_id']) && isset($routeNames[(int) $userCard['route_id']])): ?>
          <strong><?= h($routeNames[(int) $userCard['route_id']]) ?></strong>: sus equipos reciben esta URL (con la liga de cada uno).
        <?php else: ?>
          Sin ruta.
        <?php endif; ?>
        <a href="?view=routes">Gestión de Rutas</a>
      </div>
    </section>

    <!-- Ligas asignadas (varias): sus TV autorizadas las reciben como opciones del selector de ligas -->
    <section class="card section">
      <h2>Ligas</h2>
      <?php if (!$leagues): ?>
        <div class="meta">Todavía no hay ligas. Créalas en <a href="?view=leagues">Ligas</a>.</div>
      <?php else: ?>
        <div class="meta"><?= count($userLeagueIds) ?> liga(s) asignada(s).
          El usuario elige en /cuenta/ cuál de ellas usa cada uno de sus equipos (o "Sin liga").
          Si le quitas una liga, sus equipos que la usaban quedan sin liga.</div>
        <form method="post"><?= csrfField() ?>
          <input type="hidden" name="action" value="user_leagues"><input type="hidden" name="userId" value="<?= $uid ?>">
          <input type="hidden" name="back" value="<?= h($userBack) ?>">
          <?php $lastType = null; foreach ($leagues as $l): ?>
            <?php if ($lastType !== (int) $l['type_id']): $lastType = (int) $l['type_id']; ?>
              <div class="league-type-title"><?= h($l['type_name']) ?> <span class="owner">(<?= h($l['type_code']) ?>)</span></div>
            <?php endif; ?>
            <label class="inline league-check">
              <input type="checkbox" name="leagues[]" value="<?= (int) $l['id'] ?>" <?= in_array((int) $l['id'], $userLeagueIds, true) ? 'checked' : '' ?>>
              <span><?= h($l['name']) ?> <span class="owner"><?= h(str_replace(',', ', ', $l['codes'])) ?></span></span>
            </label>
          <?php endforeach; ?>
          <div class="actions"><button class="primary" type="submit">Guardar ligas</button></div>
        </form>
      <?php endif; ?>
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
              <span class="owner"><?= (int) $u['devices'] ?> equipo(s) · <?= (int) $u['authorized'] ?> aut. · <?= (int) $u['media'] ?>/<?= UserRepository::maxFiles($u) ?> archivo(s)</span>
            </span>
            <span class="chev" aria-hidden="true">&rsaquo;</span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($view === 'ads' && !$historyDevice): ?>
    <!-- ================= ANUNCIOS DEL ADMINISTRADOR =================
         Imagen o video que mirror.html intercala EN ROTACIÓN, de uno en uno en
         el orden de esta lista (flechas), con sus N archivos del usuario antes
         de cada uno (o muestra solos si el equipo no tiene contenido propio).
         Lo ven los equipos AUTORIZADOS del destino elegido, aunque el
         usuario no tenga vigencia ni el contenido habilitado. -->
    <details class="card new-user" <?= !$ads ? 'open' : '' ?>>
      <summary><strong>+ Nuevo anuncio</strong></summary>
      <form method="post" enctype="multipart/form-data" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="ad_upload">
        <label for="ad-file">Imagen o video</label>
        <input type="file" id="ad-file" name="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" required>
        <span class="owner">JPG, PNG, WEBP, GIF, MP4 (H.264, máx. 30 fps y 1920×1080) o WEBM · máx. <?= h(formatBytes(MediaRepository::maxBytes())) ?>
          (límite de PHP: <?= h((string) ini_get('upload_max_filesize')) ?>)</span>
        <?= adFields(null, $users, $allDevices) ?>
        <button class="primary" type="submit">Subir anuncio</button>
        <span class="owner">Las fechas usan la hora de este navegador.</span>
      </form>
    </details>

    <?php if (!$ads): ?><div class="card empty">Todavía no hay anuncios.</div><?php else: ?>
      <!-- Rotación en orden (mirror.html): los anuncios salen de uno en uno en ESTE orden -->
      <p class="owner">Las pantallas muestran los anuncios <strong>de uno en uno en este orden</strong>; antes de cada uno pasan sus «cada N» archivos del usuario
        (p. ej. A cada 2 y B cada 3: 2 archivos, A, 3 archivos, B, y vuelta a empezar). Usa las flechas para cambiar el orden: las pantallas lo aplican en su próxima consulta (≈ 1 min).
        Los pausados, finalizados o que no van a un equipo no ocupan su turno.</p>
    <?php endif; ?>
    <?php
      $adStateText = ['active' => 'En curso', 'paused' => 'Pausado', 'scheduled' => 'Programado', 'ended' => 'Finalizado'];
      $adStateBadge = ['active' => 'authorized', 'paused' => 'off', 'scheduled' => 'pending', 'ended' => 'revoked'];
      foreach ($ads as $n => $a): $aid = (int) $a['id']; $isImg = $a['kind'] === 'image'; $st = AdRepository::state($a);
    ?>
      <section class="card device">
        <div class="media">
          <?php if ($isImg): ?>
            <a href="?ad=<?= $aid ?>" target="_blank" rel="noopener"><img class="thumb" src="?adthumb=<?= $aid ?>" alt="" loading="lazy"></a>
          <?php else: ?>
            <a class="thumb" href="?ad=<?= $aid ?>" target="_blank" rel="noopener">&#9654; Video</a>
          <?php endif; ?>
          <div class="media-body">
            <div class="user-head">
              <span class="media-name"><span class="pos"><?= $n + 1 ?></span><?= h($a['name']) ?></span>
              <span class="badge <?= $adStateBadge[$st] ?>"><?= $adStateText[$st] ?></span>
            </div>
            <div class="meta">
              <?= h(adTargetText($a, $usersById)) ?><br>
              Cada <?= (int) $a['every_n'] ?> archivo(s) del usuario
              · <?= $isImg ? (int) $a['seconds'] . ' s en pantalla' : 'Video' ?>
              · <?= h(formatBytes((int) $a['size_bytes'])) ?><br>
              <?= $a['starts_at'] ? 'Desde ' . dateTag($a['starts_at']) : 'Desde ya' ?>
              · <?= $a['ends_at'] ? 'hasta ' . dateTag($a['ends_at']) : 'sin fecha de fin' ?>
              · <span class="owner"><?= h($a['original_name']) ?></span>
            </div>
            <div class="actions">
              <form method="post"><?= csrfField() ?><input type="hidden" name="adId" value="<?= $aid ?>">
                <input type="hidden" name="action" value="<?= (int) $a['active'] === 1 ? 'ad_pause' : 'ad_resume' ?>">
                <button class="<?= (int) $a['active'] === 1 ? 'bad' : 'ok' ?>" type="submit"><?= (int) $a['active'] === 1 ? 'Pausar' : 'Activar' ?></button></form>
              <form method="post"><?= csrfField() ?><input type="hidden" name="adId" value="<?= $aid ?>">
                <input type="hidden" name="action" value="ad_up"><button type="submit" <?= $n === 0 ? 'disabled' : '' ?> aria-label="Subir">&#9650;</button></form>
              <form method="post"><?= csrfField() ?><input type="hidden" name="adId" value="<?= $aid ?>">
                <input type="hidden" name="action" value="ad_down"><button type="submit" <?= $n === count($ads) - 1 ? 'disabled' : '' ?> aria-label="Bajar">&#9660;</button></form>
              <a class="btn" href="?ad=<?= $aid ?>&amp;dl=1">Descargar</a>
              <form method="post" data-confirm="¿Eliminar el anuncio «<?= h($a['name']) ?>»? Las pantallas dejarán de mostrarlo."><?= csrfField() ?>
                <input type="hidden" name="adId" value="<?= $aid ?>"><input type="hidden" name="action" value="ad_delete">
                <button type="submit">Eliminar</button></form>
            </div>
            <!-- Editar (sin cambiar el archivo) -->
            <details class="edit">
              <summary>Editar destino, frecuencia y fechas</summary>
              <form method="post" autocomplete="off"><?= csrfField() ?>
                <input type="hidden" name="action" value="ad_update"><input type="hidden" name="adId" value="<?= $aid ?>">
                <?= adFields($a, $users, $allDevices) ?>
                <button class="primary" type="submit">Guardar cambios</button>
              </form>
            </details>
          </div>
        </div>
      </section>
    <?php endforeach; ?>

  <?php elseif ($view === 'overlays' && !$historyDevice):
    $ovAdmin = array_values(array_filter($overlaysAll, static fn($o) => $o['user_id'] === null));
    $ovUsers = array_values(array_filter($overlaysAll, static fn($o) => $o['user_id'] !== null));
  ?>
    <!-- ================= TIRAS Y BANNERS =================
         Franja arriba o abajo de mirror.html (texto o imagen). Las del
         administrador llegan a cualquier equipo AUTORIZADO del destino y
         tienen prioridad sobre las del usuario en la misma posición. -->
    <details class="card new-user" <?= !$ovAdmin ? 'open' : '' ?>>
      <summary><strong>+ Nueva tira o banner</strong></summary>
      <form method="post" enctype="multipart/form-data" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="ov_create">
        <?= overlayFields(null, 'ovnew-') ?>
        <?= overlayAdminFields(null) ?>
        <p class="owner">Se crea <strong>sin equipos</strong>: después, en la lista, usa «Asignar a equipos» para elegir
          dónde y durante cuánto tiempo (permanente o con fechas) se muestra.</p>
        <button class="primary" type="submit">Crear</button>
      </form>
    </details>

    <?php foreach ([['Del administrador', $ovAdmin, 'Todavía no hay tiras ni banners del administrador.'],
                    ['De los usuarios (solo pausar o eliminar)', $ovUsers, 'Ningún usuario ha creado tiras ni banners.']] as [$title, $list, $emptyText]): ?>
      <h2 class="ov-list-title"><?= h($title) ?> (<?= count($list) ?>)</h2>
      <?php if (!$list): ?><div class="card empty"><?= h($emptyText) ?></div><?php endif; ?>
      <?php foreach ($list as $o): $oid = (int) $o['id']; $o['_img'] = $o['kind'] === 'image' ? '?ov=' . $oid : ''; ?>
        <section class="card device">
          <?= overlayPreview($o, $o['_img']) ?>
          <div class="user-head">
            <span class="media-name"><?= h($o['name']) ?>
              <?= $o['user_id'] !== null ? '<span class="owner-tag">· ' . h((string) $o['user_company']) . '</span>' : '' ?></span>
            <?= overlayWhenTag($o) ?>
          </div>
          <div class="meta">
            <?= h(overlaySummary($o)) ?><br>
            <?= h(overlayTargetText($o, $usersById)) ?><br>
            <?= overlayWhenText($o) ?>
          </div>
          <div class="actions">
            <form method="post"><?= csrfField() ?><input type="hidden" name="overlayId" value="<?= $oid ?>">
              <input type="hidden" name="action" value="<?= (int) $o['active'] === 1 ? 'ov_pause' : 'ov_resume' ?>">
              <button class="<?= (int) $o['active'] === 1 ? 'bad' : 'ok' ?>" type="submit"><?= (int) $o['active'] === 1 ? 'Pausar' : 'Activar' ?></button></form>
            <form method="post" data-confirm="¿Eliminar «<?= h($o['name']) ?>»? Las pantallas dejarán de mostrarlo."><?= csrfField() ?>
              <input type="hidden" name="overlayId" value="<?= $oid ?>"><input type="hidden" name="action" value="ov_delete">
              <button type="submit">Eliminar</button></form>
          </div>
          <?php if ($o['user_id'] === null): ?>
            <!-- Asignación a equipos (aparte de la edición) -->
            <details class="edit" <?= overlayAssigned($o) ? '' : 'open' ?>>
              <summary>Asignar a equipos</summary>
              <form method="post"><?= csrfField() ?>
                <input type="hidden" name="action" value="ov_assign"><input type="hidden" name="overlayId" value="<?= $oid ?>">
                <?= overlayAdminTargets($o, $users, $allDevices) ?>
                <?= overlayAdminWhen($o) ?>
                <button class="primary" type="submit">Guardar asignación</button>
              </form>
            </details>
            <details class="edit">
              <summary>Editar</summary>
              <form method="post" autocomplete="off"><?= csrfField() ?>
                <input type="hidden" name="action" value="ov_update"><input type="hidden" name="overlayId" value="<?= $oid ?>">
                <?= overlayFields($o, 'o' . $oid . '-') ?>
                <?= overlayAdminFields($o) ?>
                <button class="primary" type="submit">Guardar cambios</button>
              </form>
            </details>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    <?php endforeach; ?>

  <?php elseif ($view === 'messages' && !$historyDevice): ?>
    <!-- ================= BUZÓN DE MENSAJES =================
         Una conversación por usuario. Abrir una marca como leídos sus mensajes. -->
    <h2 class="ov-list-title">Mensajes</h2>
    <?php if ($msgUser !== null): $mid = (int) $msgUser['id']; ?>
      <section class="card">
        <div class="user-head">
          <span class="media-name"><?= h($msgUser['company']) ?> <span class="owner">(<?= h($msgUser['email']) ?>)</span></span>
          <a class="btn" href="?view=messages">&larr; Todas las conversaciones</a>
        </div>
        <?= messageThread($thread, 'admin', (string) $msgUser['company']) ?>
        <form method="post" class="msg-form" autocomplete="off"><?= csrfField() ?>
          <input type="hidden" name="action" value="msg_send"><input type="hidden" name="userId" value="<?= $mid ?>">
          <?= messageField('msg-body', 'Escribe a ' . $msgUser['company'] . '…') ?>
          <div class="row">
            <button class="primary" type="submit">Enviar</button>
            <a class="btn" href="?view=users&amp;user=<?= $mid ?>">Ficha del usuario</a>
          </div>
        </form>
        <?php if ($thread): ?>
          <form method="post" class="actions" data-confirm="¿Borrar TODA la conversación con «<?= h($msgUser['company']) ?>»? No se puede deshacer."><?= csrfField() ?>
            <input type="hidden" name="action" value="msg_delete"><input type="hidden" name="userId" value="<?= $mid ?>">
            <button type="submit">Borrar conversación</button></form>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <details class="card new-user" <?= !$conversations ? 'open' : '' ?>>
        <summary><strong>+ Nuevo mensaje</strong></summary>
        <form method="post" class="msg-form" autocomplete="off"><?= csrfField() ?>
          <input type="hidden" name="action" value="msg_send">
          <label for="msg-to">Para</label>
          <select id="msg-to" name="userId" required>
            <option value="">Elegir usuario…</option>
            <option value="all">Todos los usuarios (<?= count($users) ?>)</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int) $u['id'] ?>"><?= h(userText($u)) ?></option>
            <?php endforeach; ?>
          </select>
          <?= messageField('msg-new') ?>
          <div class="row"><button class="primary" type="submit">Enviar</button>
            <span class="owner">"Todos los usuarios" deja el mensaje en la conversación de cada uno.</span></div>
        </form>
      </details>
      <?php if (!$conversations): ?><div class="card empty">Todavía no hay conversaciones.</div><?php endif; ?>
      <?php foreach ($conversations as $c): ?>
        <a class="card conv <?= $c['unread'] ? 'conv-new' : '' ?>" href="?view=messages&amp;mu=<?= (int) $c['id'] ?>">
          <span class="conv-head"><strong><?= h($c['company']) ?></strong>
            <?= $c['unread'] ? '<span class="unread">' . (int) $c['unread'] . '</span>' : '' ?>
            <span class="owner"><?= dateTag((string) $c['last_at']) ?></span></span>
          <span class="owner conv-last"><?= $c['last_sender'] === 'admin' ? 'Tú: ' : '' ?><?= h(mb_strimwidth(str_replace("\n", ' ', (string) $c['last_body']), 0, 120, '…')) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php elseif ($view === 'profiles' && !$historyDevice):
      // Campos de un perfil (crear o editar)
      $profileFields = static function (?array $pr, string $px): string {
          $v = $pr ?? ['brand' => '', 'model' => '', 'case_mac' => '', 'installed_on' => gmdate('Y-m-d')];
          return '<div class="user-grid">'
              . '<div><label for="' . $px . 'brand">Marca</label><input type="text" id="' . $px . 'brand" name="brand" maxlength="60" required value="' . h((string) $v['brand']) . '" placeholder="p. ej. Hisilicon"></div>'
              . '<div><label for="' . $px . 'model">Modelo</label><input type="text" id="' . $px . 'model" name="model" maxlength="60" required value="' . h((string) $v['model']) . '" placeholder="p. ej. TVBOX Hi3798"></div>'
              . '<div><label for="' . $px . 'mac">MAC que sale en el case</label><input type="text" id="' . $px . 'mac" name="case_mac" maxlength="17" required value="' . h((string) $v['case_mac']) . '" placeholder="E8:BB:3E:C8:FB:5A" autocapitalize="characters" spellcheck="false"></div>'
              . '<div><label for="' . $px . 'date">Fecha de instalación</label><input type="date" id="' . $px . 'date" name="installed_on" required value="' . h((string) $v['installed_on']) . '"></div>'
              . '</div>';
      };
      // Selector de equipo ("" = sin equipo)
      $deviceSelect = static function (string $px, ?string $current) use ($profileDevices): string {
          $o = '<label for="' . $px . 'dev">Equipo</label><select id="' . $px . 'dev" name="deviceId"><option value="">Sin equipo</option>';
          foreach ($profileDevices as $pd) {
              $o .= '<option value="' . h($pd['device_id']) . '"' . ($current === $pd['device_id'] ? ' selected' : '') . '>'
                  . h($pd['device_id'] . ($pd['label'] ? ' · ' . $pd['label'] : '') . ($pd['user_company'] ? ' · ' . $pd['user_company'] : '')
                    . (!empty($pd['profile_id']) ? ' (ya tiene perfil: se cambiará)' : '')) . '</option>';
          }
          return $o . '</select>';
      };
  ?>
    <!-- ================= PERFILES DE EQUIPO =================
         Ficha de la caja física: marca, modelo, MAC del case y fecha de
         instalación. Un perfil por equipo; la PRIMERA autorización la exige. -->
    <h2 class="ov-list-title">Perfiles de equipo</h2>
    <details class="card new-user" <?= !$profiles || $profileDevice !== '' ? 'open' : '' ?>>
      <summary><strong>+ Nuevo perfil</strong></summary>
      <form method="post" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="profile_create">
        <input type="hidden" name="back" value="<?= h($profileDevice !== '' ? http_build_query(['filter' => 'all', 'q' => $profileDevice]) : 'view=profiles') ?>">
        <?= $profileFields(null, 'pnew-') ?>
        <?= $deviceSelect('pnew-', $profileDevice !== '' ? $profileDevice : null) ?>
        <p><button class="primary" type="submit">Crear perfil</button>
          <span class="owner">Todos los campos son obligatorios. Para autorizar un equipo por primera vez, debe tener perfil.</span></p>
      </form>
    </details>
    <!-- Búsqueda de perfiles: marca, modelo, equipo o MAC del case (sin importar ":" o "-") -->
    <form class="profile-search" method="get">
      <input type="hidden" name="view" value="profiles">
      <input type="search" name="pq" value="<?= h($pq) ?>" placeholder="Buscar por MAC, marca, modelo o equipo" aria-label="Buscar perfil">
      <button type="submit">Buscar</button>
      <?php if ($pq !== ''): ?><a class="btn" href="?view=profiles">Ver todos</a><?php endif; ?>
    </form>
    <?php if (!$profiles): ?><div class="card empty"><?= $pq !== '' ? 'Ningún perfil coincide con «' . h($pq) . '».' : 'Todavía no hay perfiles.' ?></div><?php endif; ?>
    <?php foreach ($profiles as $pr): $prid = (int) $pr['id']; $pback = http_build_query(['view' => 'profiles', 'pq' => $pq]); ?>
      <section class="card device">
        <div class="user-head">
          <span class="media-name"><?= h($pr['brand'] . ' ' . $pr['model']) ?></span>
          <span class="badge <?= $pr['device_id'] ? 'authorized' : 'off' ?>"><?= $pr['device_id'] ? 'Asignado' : 'Sin equipo' ?></span>
        </div>
        <div class="meta">
          MAC: <span class="device-mac"><?= h($pr['case_mac']) ?></span>
          · Instalado el <?= h(date('d/m/Y', strtotime((string) $pr['installed_on']))) ?><br>
          <?php if ($pr['device_id']): ?>
            Equipo: <a href="./?<?= h(http_build_query(['filter' => 'all', 'q' => $pr['device_id']])) ?>"><?= h($pr['device_id']) ?></a>
            <?= $pr['device_label'] ? '· ' . h($pr['device_label']) : '' ?>
            <?= $pr['user_company'] ? '· <span class="owner">' . h($pr['user_company']) . '</span>' : '' ?>
            <?= $pr['device_mac'] ? '· MAC que envía la TV <span class="device-mac">' . h($pr['device_mac']) . '</span>' : '' ?>
          <?php else: ?>
            <em>Sin equipo asignado.</em>
          <?php endif; ?>
        </div>
        <div class="actions">
          <form method="post" data-confirm="¿Eliminar el perfil «<?= h(ProfileRepository::text($pr)) ?>»?<?= $pr['device_id'] ? ' ' . h($pr['device_id']) . ' quedará sin perfil.' : '' ?>"><?= csrfField() ?>
            <input type="hidden" name="action" value="profile_delete"><input type="hidden" name="profileId" value="<?= $prid ?>">
            <input type="hidden" name="back" value="<?= h($pback) ?>">
            <button type="submit">Eliminar</button></form>
        </div>
        <details class="edit" <?= $pr['device_id'] ? '' : 'open' ?>>
          <summary>Asignar a un equipo</summary>
          <form method="post"><?= csrfField() ?>
            <input type="hidden" name="action" value="profile_assign"><input type="hidden" name="profileId" value="<?= $prid ?>">
            <input type="hidden" name="back" value="<?= h($pback) ?>">
            <?= $deviceSelect('p' . $prid . '-', $pr['device_id']) ?>
            <span class="owner">Un equipo tiene un solo perfil. Si la TV se restableció y volvió con otro ID, asígnale aquí el equipo nuevo.</span><br>
            <button class="primary" type="submit">Guardar equipo</button>
          </form>
        </details>
        <details class="edit">
          <summary>Editar datos</summary>
          <form method="post" autocomplete="off"><?= csrfField() ?>
            <input type="hidden" name="action" value="profile_update"><input type="hidden" name="profileId" value="<?= $prid ?>">
            <input type="hidden" name="back" value="<?= h($pback) ?>">
            <?= $profileFields($pr, 'p' . $prid . '-e') ?>
            <button class="primary" type="submit">Guardar cambios</button>
          </form>
        </details>
      </section>
    <?php endforeach; ?>

  <?php elseif ($view === 'routes' && !$historyDevice): ?>
    <!-- ================= GESTIÓN DE RUTAS =================
         URL de contenido construida por partes; se asigna a usuarios y la
         heredan todos sus equipos (leagues = liga de cada equipo). -->
    <h2 class="ov-list-title">Gestión de Rutas</h2>
    <details class="card new-user" <?= !$routes ? 'open' : '' ?>>
      <summary><strong>+ Nueva ruta</strong></summary>
      <form method="post" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="route_create">
        <?= routeFields(null, 'rnew-') ?>
        <button class="primary" type="submit">Crear ruta</button>
      </form>
    </details>
    <?php if (!$routes): ?><div class="card empty">Todavía no hay rutas.</div><?php endif; ?>
    <?php foreach ($routes as $r): $rid = (int) $r['id']; ?>
      <section class="card device">
        <div class="user-head">
          <span class="media-name"><?= h($r['name']) ?></span>
          <span class="badge <?= $r['users'] ? 'authorized' : 'off' ?>"><?= count($r['users']) ?> usuario(s)</span>
        </div>
        <code class="route-url"><?= h(RouteRepository::buildUrl($r, '')) ?></code>
        <div class="meta">
          <?= $r['users'] ? 'Usuarios: ' . h(implode(', ', array_column($r['users'], 'company'))) : 'Sin usuarios: ningún equipo la recibe.' ?><br>
          leagues = la liga de cada equipo (la elige el usuario en /cuenta/). Actualizada <?= dateTag($r['updated_at']) ?>
        </div>
        <div class="actions">
          <form method="post" data-confirm="¿Eliminar la ruta «<?= h($r['name']) ?>»? Sus usuarios quedarán sin ruta."><?= csrfField() ?>
            <input type="hidden" name="action" value="route_delete"><input type="hidden" name="routeId" value="<?= $rid ?>">
            <button type="submit">Eliminar</button></form>
        </div>
        <details class="edit" <?= $r['users'] ? '' : 'open' ?>>
          <summary>Asignar a usuarios</summary>
          <form method="post"><?= csrfField() ?>
            <input type="hidden" name="action" value="route_users"><input type="hidden" name="routeId" value="<?= $rid ?>">
            <?= routeUsersFields($r, $users, $routeNames) ?>
            <span class="owner">Cada usuario tiene una sola ruta: si marcas uno que tiene otra, pasa a esta.</span><br>
            <button class="primary" type="submit">Guardar usuarios</button>
          </form>
        </details>
        <details class="edit">
          <summary>Editar parámetros</summary>
          <form method="post" autocomplete="off"><?= csrfField() ?>
            <input type="hidden" name="action" value="route_update"><input type="hidden" name="routeId" value="<?= $rid ?>">
            <?= routeFields($r, 'r' . $rid . '-') ?>
            <button class="primary" type="submit">Guardar cambios</button>
          </form>
        </details>
      </section>
    <?php endforeach; ?>

  <?php elseif ($view === 'leagues' && !$historyDevice): ?>
    <!-- ================= LIGAS Y TIPOS DE LIGA =================
         Tipo = opción del selector de ligas de la TV (código + nombre).
         Liga = nombre + tipo + contenido (códigos). Se asignan a los
         usuarios en su ficha; sus TV autorizadas las reciben. -->
    <section class="card section">
      <h2>Tipos de liga</h2>
      <div class="meta">El código es lo que guarda la TV como liga del equipo (minúsculas, p. ej. <code>mlb</code>)
        y no se puede cambiar; el nombre es el que se ve en las listas. El orden es el de las listas.</div>
      <?php if (!$leagueTypes): ?><p class="owner">Todavía no hay tipos. Crea el primero (p. ej. código <code>mlb</code>, nombre MLB).</p><?php endif; ?>
      <?php foreach ($leagueTypes as $n => $t): $tid = (int) $t['id']; ?>
        <div class="type-row">
          <span class="pos"><?= $n + 1 ?></span>
          <code class="type-code"><?= h($t['code']) ?></code>
          <form method="post" class="type-rename"><?= csrfField() ?>
            <input type="hidden" name="action" value="league_type_rename"><input type="hidden" name="typeId" value="<?= $tid ?>">
            <label for="tn<?= $tid ?>" class="sr">Nombre del tipo</label>
            <input type="text" id="tn<?= $tid ?>" name="name" value="<?= h($t['name']) ?>" maxlength="60" required>
            <button type="submit">Guardar</button></form>
          <span class="owner"><?= (int) $t['leagues'] ?> liga(s)</span>
          <form method="post"><?= csrfField() ?><input type="hidden" name="typeId" value="<?= $tid ?>">
            <input type="hidden" name="action" value="league_type_up"><button type="submit" <?= $n === 0 ? 'disabled' : '' ?> aria-label="Subir">&#9650;</button></form>
          <form method="post"><?= csrfField() ?><input type="hidden" name="typeId" value="<?= $tid ?>">
            <input type="hidden" name="action" value="league_type_down"><button type="submit" <?= $n === count($leagueTypes) - 1 ? 'disabled' : '' ?> aria-label="Bajar">&#9660;</button></form>
          <form method="post" data-confirm="¿Eliminar el tipo «<?= h($t['name']) ?>»?"><?= csrfField() ?>
            <input type="hidden" name="typeId" value="<?= $tid ?>"><input type="hidden" name="action" value="league_type_delete">
            <button type="submit" <?= (int) $t['leagues'] > 0 ? 'disabled title="Tiene ligas"' : '' ?>>Eliminar</button></form>
        </div>
      <?php endforeach; ?>
      <!-- Nuevo tipo -->
      <form method="post" class="type-new" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="league_type_create">
        <div class="user-grid">
          <div><label for="nt-code">Código (minúsculas)</label>
            <input type="text" id="nt-code" name="code" maxlength="20" required pattern="[a-zA-Z0-9_\-]{1,20}" placeholder="mlb"></div>
          <div><label for="nt-name">Nombre (texto del botón)</label>
            <input type="text" id="nt-name" name="name" maxlength="60" required placeholder="MLB"></div>
        </div>
        <button class="primary" type="submit">+ Crear tipo</button>
      </form>
    </section>

    <details class="card new-user" <?= $leagueTypes && !$leagues ? 'open' : '' ?>>
      <summary><strong>+ Nueva liga</strong></summary>
      <?php if (!$leagueTypes): ?>
        <p class="owner">Primero crea un tipo de liga.</p>
      <?php else: ?>
        <form method="post" autocomplete="off"><?= csrfField() ?>
          <input type="hidden" name="action" value="league_create">
          <?= leagueFields(null, $leagueTypes, 'nl-') ?>
          <button class="primary" type="submit">Crear liga</button>
        </form>
      <?php endif; ?>
    </details>

    <?php if (!$leagues): ?><div class="card empty">Todavía no hay ligas.</div><?php endif; ?>
    <?php $lastType = null; foreach ($leagues as $l): $lid = (int) $l['id']; ?>
      <?php if ($lastType !== (int) $l['type_id']): $lastType = (int) $l['type_id']; ?>
        <h2 class="group-title"><?= h($l['type_name']) ?> <span class="owner">(<?= h($l['type_code']) ?>)</span></h2>
      <?php endif; ?>
      <section class="card device">
        <div class="user-head">
          <span class="user-name"><?= h($l['name']) ?></span>
          <span class="owner"><?= count(explode(',', (string) $l['codes'])) ?> código(s)</span>
        </div>
        <div class="codes"><?php foreach (explode(',', (string) $l['codes']) as $c): ?><span class="code-chip"><?= h($c) ?></span><?php endforeach; ?></div>
        <div class="meta">
          Usuarios: <?php if (!$l['users']): ?><em>ninguno</em> (asígnala en la ficha de cada usuario)<?php else: ?>
            <?php foreach ($l['users'] as $i => $lu): ?><?= $i ? ', ' : '' ?><a href="<?= h(panelUrl(['view' => 'users', 'user' => $lu['id']])) ?>"><?= h($lu['company']) ?></a><?php endforeach; ?>
          <?php endif; ?>
          · Actualizada el <?= dateTag($l['updated_at']) ?>
        </div>
        <div class="actions">
          <form method="post" data-confirm="¿Eliminar la liga «<?= h($l['name']) ?>»? Se quitará a <?= count($l['users']) ?> usuario(s)."><?= csrfField() ?>
            <input type="hidden" name="leagueId" value="<?= $lid ?>"><input type="hidden" name="action" value="league_delete">
            <button type="submit">Eliminar</button></form>
        </div>
        <details class="edit">
          <summary>Editar nombre, tipo y contenido</summary>
          <form method="post" autocomplete="off"><?= csrfField() ?>
            <input type="hidden" name="action" value="league_update"><input type="hidden" name="leagueId" value="<?= $lid ?>">
            <?= leagueFields($l, $leagueTypes, 'l' . $lid . '-') ?>
            <button class="primary" type="submit">Guardar cambios</button>
          </form>
        </details>
      </section>
    <?php endforeach; ?>

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
            <a href="?file=<?= $mid ?>" target="_blank" rel="noopener"><img class="thumb" src="?thumb=<?= $mid ?>" alt="" loading="lazy"></a>
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
              · Subido el <?= dateTag($m['created_at']) ?><br>
              <!-- Periodo que eligió el usuario (permanente o con fechas) -->
              <?php $mState = MediaRepository::state($m); ?>
              <?php if (!MediaRepository::isActive($m)): ?><span class="badge off">Pausado</span><?php endif; ?>
              <span class="badge <?= ['permanent' => 'authorized', 'active' => 'authorized', 'scheduled' => 'pending', 'ended' => 'revoked'][$mState] ?>"><?=
                ['permanent' => 'Permanente', 'active' => 'En curso', 'scheduled' => 'Programado', 'ended' => 'Finalizado'][$mState] ?></span>
              <?php if ($mState !== 'permanent'): ?>
                <?= $m['starts_at'] ? 'desde ' . dateTag($m['starts_at']) : 'desde ya' ?>
                · <?= $m['ends_at'] ? 'hasta ' . dateTag($m['ends_at']) : 'sin fecha de fin' ?>
              <?php endif; ?>
            </div>
            <div class="actions">
              <a class="btn" href="?file=<?= $mid ?>" target="_blank" rel="noopener">Ver</a>
              <a class="btn" href="?file=<?= $mid ?>&amp;dl=1">Descargar</a>
              <!-- Pausar / activar (como las tiras y banners) -->
              <form method="post"><?= csrfField() ?><input type="hidden" name="mediaId" value="<?= $mid ?>">
                <input type="hidden" name="action" value="<?= MediaRepository::isActive($m) ? 'media_pause' : 'media_resume' ?>">
                <input type="hidden" name="back" value="<?= h($mediaBack) ?>">
                <button class="<?= MediaRepository::isActive($m) ? 'bad' : 'ok' ?>" type="submit"><?= MediaRepository::isActive($m) ? 'Pausar' : 'Activar' ?></button></form>
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

  <?php if ($queueStatus !== null): ?>
    <!-- COLA DE DESCARGAS: pantallas descargando contenido nuevo por turnos -->
    <div class="queue-box <?= $queueStatus['waiting'] ? 'busy' : '' ?>">
      <strong>Cola de descargas:</strong>
      <?php if (!$queueStatus['active'] && !$queueStatus['waiting']): ?>
        vacía <span class="owner">(hasta <?= (int) $queueStatus['slots'] ?> pantallas descargan a la vez)</span>
      <?php else: ?>
        <?= (int) $queueStatus['active'] ?> de <?= (int) $queueStatus['slots'] ?> descargando
        · <?= (int) $queueStatus['waiting'] ?> esperando turno
        · terminará en ≈ <?= max(1, (int) ceil($queueStatus['etaSeconds'] / 60)) ?> min
      <?php endif; ?>
    </div>
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
      <?= $q !== '' ? 'Ningún equipo coincide con «' . h($q) . '». Si es nuevo, abre en la TV la pantalla del código QR para que se registre.'
          // Salida directa: la búsqueda se mantiene al cambiar de pestaña
          . ' <a href="' . h(panelUrl(['filter' => $filter, 'user' => $userParam])) . '">Quitar la búsqueda</a>' : 'No hay equipos en esta lista.' ?>
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
          <!-- Alias del administrador (el usuario no lo ve) -->
          <?= !empty($d['admin_alias']) ? '<span class="admin-alias" title="Alias del administrador (el usuario no lo ve)">' . h($d['admin_alias']) . '</span>' : '' ?>
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
        <?php if ($d['user_id']):
            $ownerId = (int) $d['user_id'];
            $ownerRoute = !empty($usersById[$ownerId]['route_id']);
            // Ligas del usuario dueño (una consulta por usuario, no por equipo)
            $ownerLeagues = $leaguesByUser[$ownerId] ??= LeagueRepository::forUser($ownerId);
            // Liga del equipo: la suya si sigue siendo de su usuario (igual que
            // LeagueRepository::deviceLeague, pero sin consultar la base)
            $devLeague = null;
            foreach ($ownerLeagues as $ol) {
                if ((int) $ol['id'] === (int) ($d['league_id'] ?? 0)) { $devLeague = $ol; break; }
            }
        ?>
          <?php if ($ownerRoute && $ownerLeagues): $did = h($id); ?>
            <!-- Liga del equipo: el usuario ya tiene ruta y ligas => el admin también la elige
                 entre las ligas de ESE usuario (se guarda al cambiarla) -->
            <form method="post" class="user-form league-form"><?= csrfField() ?>
              <input type="hidden" name="action" value="device_league">
              <input type="hidden" name="deviceId" value="<?= $did ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
              <label for="lg-<?= $did ?>">Liga:</label>
              <select id="lg-<?= $did ?>" name="leagueId" data-autosubmit>
                <option value="">Sin liga</option>
                <?php $lastType = null; foreach ($ownerLeagues as $l): ?>
                  <?php if ($lastType !== (int) $l['type_id']): ?>
                    <?= $lastType !== null ? '</optgroup>' : '' ?><optgroup label="<?= h($l['type_name']) ?>">
                  <?php $lastType = (int) $l['type_id']; endif; ?>
                  <option value="<?= (int) $l['id'] ?>" <?= $devLeague && (int) $devLeague['id'] === (int) $l['id'] ? 'selected' : '' ?>>
                    <?= h($l['name'] . ' — ' . str_replace(',', ', ', $l['codes'])) ?></option>
                <?php endforeach; ?>
                <?= $lastType !== null ? '</optgroup>' : '' ?>
              </select>
              <noscript><button type="submit">Guardar liga</button></noscript>
            </form>
            <!-- URL final que recibe el equipo (ruta del usuario + leagues de esta liga) -->
            <!-- (igual que RouteRepository::urlForDevice, con las rutas ya leídas) -->
            <span class="route-url"><?= h(isset($routesById[(int) $usersById[$ownerId]['route_id']])
                ? RouteRepository::buildUrl($routesById[(int) $usersById[$ownerId]['route_id']], $devLeague ? (string) $devLeague['codes'] : '')
                : '') ?></span>
          <?php else: ?>
            <!-- Sin ruta o sin ligas en su usuario: solo se muestra la liga -->
            Liga: <?= $devLeague === null ? '<em>sin liga</em>'
              : h($devLeague['name']) . ' <span class="owner">(' . h($devLeague['type_name']) . ': '
                . h(str_replace(',', ', ', $devLeague['codes'])) . ')</span>' ?>
            <span class="owner">· <?= !$ownerRoute ? 'su usuario no tiene ruta' : 'su usuario no tiene ligas' ?>
              (asígnaselas en su ficha para poder elegir la liga aquí)</span><br>
          <?php endif; ?>
        <?php endif; ?>
        <!-- PERFIL (caja física): marca, modelo, MAC del case, instalación; sin perfil => selector de perfiles libres -->
        <?php if (!empty($d['profile_id'])): ?>
          Perfil: <strong><?= h($d['profile_brand'] . ' ' . $d['profile_model']) ?></strong>
          · MAC del case <span class="device-mac"><?= h($d['profile_case_mac']) ?></span>
          · instalado el <?= h(date('d/m/Y', strtotime((string) $d['profile_installed_on']))) ?>
          · <a href="./?<?= h(http_build_query(['view' => 'profiles', 'pq' => $d['profile_case_mac']])) ?>">editar</a><br>
        <?php else:
            // Propuesta: el perfil libre cuya MAC del case es la MAC que envía la TV
            $suggested = null;
            foreach ($freeProfiles as $fp) {
                if (!empty($d['mac']) && $fp['case_mac'] === $d['mac']) { $suggested = (int) $fp['id']; break; }
            }
        ?>
          <span class="<?= DeviceRepository::needsProfile($d) ? 'need-profile' : '' ?>">Perfil: <em>sin perfil</em><?= DeviceRepository::needsProfile($d) ? ' (obligatorio para autorizarlo por primera vez)' : '' ?></span>
          <form method="post" class="user-form league-form"><?= csrfField() ?>
            <input type="hidden" name="action" value="profile_assign">
            <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
            <?php if ($freeProfiles): ?>
              <select name="profileId" aria-label="Perfil del equipo" required>
                <option value="">Elegir un perfil libre…</option>
                <?php foreach ($freeProfiles as $fp): ?>
                  <option value="<?= (int) $fp['id'] ?>" <?= $suggested === (int) $fp['id'] ? 'selected' : '' ?>><?= h(ProfileRepository::text($fp)) ?><?= $suggested === (int) $fp['id'] ? ' (coincide con su MAC)' : '' ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit">Asignar perfil</button>
            <?php endif; ?>
            <a class="btn" href="./?<?= h(http_build_query(['view' => 'profiles', 'device' => $id])) ?>">Crear perfil</a>
          </form>
        <?php endif; ?>
        <!-- MAC (la envía loading.html; una MAC solo está en un equipo) y, si la tenía otro equipo, cuál -->
        MAC: <?= !empty($d['mac']) ? '<span class="device-mac">' . h($d['mac']) . '</span>'
            . ' <span class="owner">· desde ' . dateTag($d['mac_at']) . '</span>'
            // El equipo anterior: enlace si sigue en la base; si se eliminó, solo el ID (el
            // enlace llevaba a «Ningún equipo coincide»)
            . (!empty($d['mac_from']) ? ' <span class="owner">· antes en '
              . (!empty($d['mac_from_exists'])
                  ? '<a href="./?' . h(http_build_query(['filter' => 'all', 'q' => $d['mac_from']])) . '">' . h($d['mac_from']) . '</a> (equipo restablecido)'
                  : h($d['mac_from']) . ' (equipo restablecido, ya eliminado)')
              . '</span>' : '')
          : '<em>sin MAC</em>' ?><br>
        <!-- Última conexión: cualquier consulta de las páginas de la TV (device.php / media.php) o señal de la app nativa (ping.php) -->
        Última conexión: <?= onlineTag($d['online_at'] ?? null, $onlineTimeout) ?><br>
        Registrado el <?= dateTag($d['created_at']) ?>
        · Arranques: <?= (int) ($d['boots'] ?? 0) ?>
        <?= !empty($d['last_boot_at']) ? '(último ' . dateTag($d['last_boot_at']) . ')' : '' ?>
        · Última consulta <?= agoTag($d['last_seen_at']) ?>
        <?= $d['app_version'] ? ' · v' . h($d['app_version']) : '' ?>
        <?= $d['last_ip'] ? ' · IP ' . h($d['last_ip']) : '' ?>
        <?php if (!empty($d['admin_notes'])): ?>
          <!-- Notas del administrador (el usuario no las ve) -->
          <div class="admin-notes"><strong>Notas:</strong> <?= nl2br(h($d['admin_notes'])) ?></div>
        <?php endif; ?>
      </div>
      <div class="actions">
        <?php if ($st !== 'authorized'): $noProfile = DeviceRepository::needsProfile($d); ?>
          <!-- La PRIMERA autorización exige perfil (el servidor también lo comprueba) -->
          <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="authorize">
            <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
            <button class="ok" type="submit" <?= $noProfile ? 'disabled title="Asígnale un perfil primero"' : '' ?>><?= $noProfile ? 'Autorizar (falta perfil)' : 'Autorizar' ?></button></form>
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
          <input type="text" name="label" value="<?= h($d['label']) ?>" maxlength="100" placeholder="Alias que también ve el usuario (p. ej. Local Centro)" aria-label="Alias (lo ve el usuario)">
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
      <!-- Alias adicional y notas: SOLO el administrador (no salen en /cuenta/ ni en la TV) -->
      <details class="edit">
        <summary>Alias y notas del administrador<?= !empty($d['admin_alias']) || !empty($d['admin_notes']) ? '' : ' (vacío)' ?></summary>
        <form method="post" autocomplete="off"><?= csrfField() ?><input type="hidden" name="action" value="admin_info">
          <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <label for="aa-<?= h($id) ?>">Alias del administrador (se puede buscar por él)</label>
          <input type="text" id="aa-<?= h($id) ?>" name="adminAlias" value="<?= h((string) ($d['admin_alias'] ?? '')) ?>" maxlength="100" placeholder="p. ej. Caja 14 · Sucursal Norte">
          <label for="an-<?= h($id) ?>">Notas</label>
          <textarea id="an-<?= h($id) ?>" name="adminNotes" rows="3" maxlength="<?= DeviceRepository::ADMIN_NOTES_MAX ?>" placeholder="Notas internas: instalación, incidencias, contacto…"><?= h((string) ($d['admin_notes'] ?? '')) ?></textarea>
          <button class="primary" type="submit">Guardar alias y notas</button>
          <span class="owner">El usuario no ve este alias ni estas notas.</span>
        </form>
      </details>
    </section>
  <?php endforeach; ?>
  <?php if ($devPages > 1): ?>
    <!-- Paginación (muchos equipos) -->
    <nav class="pager">
      <?php if ($devPage > 1): ?>
        <a class="btn" href="<?= h(panelUrl(['filter' => $filter, 'q' => $q, 'user' => $userParam, 'p' => $devPage - 1])) ?>">&laquo; Anterior</a>
      <?php endif; ?>
      <span class="owner">Página <?= $devPage ?> de <?= $devPages ?> · <?= $devTotal ?> equipo(s)</span>
      <?php if ($devPage < $devPages): ?>
        <a class="btn" href="<?= h(panelUrl(['filter' => $filter, 'q' => $q, 'user' => $userParam, 'p' => $devPage + 1])) ?>">Siguiente &raquo;</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
  <?php endif; /* fin vista usuarios / historial / lista */ ?>

  <!-- Escáner: cámara trasera + lector de QR -->
  <div id="scanner" role="dialog" aria-label="Escanear código QR">
    <video id="video" playsinline muted></video>
    <p id="scanMsg">Apunta al código QR de la pantalla de la TV.</p>
    <button type="button" id="scanClose">Cerrar</button>
  </div>

  <!-- Fechas locales, "hace X", En línea/Desconectado y confirmaciones -->
  <script nonce="<?= h($nonce) ?>"><?= overlayScript() ?></script>
  <script nonce="<?= h($nonce) ?>"><?= deviceFilterScript() ?></script>
  <script nonce="<?= h($nonce) ?>"><?= messageScript() ?></script>
  <script nonce="<?= h($nonce) ?>">
  /* Rutas: URL final en vivo (mismo orden y codificación que RouteRepository::buildUrl) */
  (function () {
    "use strict";
    function enc(v) {
      return encodeURIComponent(v).replace(/[!'()*]/g, function (c) {
        return "%" + c.charCodeAt(0).toString(16).toUpperCase();
      }).replace(/%2C/g, ",");
    }
    document.querySelectorAll("[data-route-form]").forEach(function (form) {
      var out = form.querySelector("[data-route-preview]");
      function val(n) { var el = form.querySelector("[data-route=" + n + "]"); return el ? el.value.trim() : ""; }
      function num(n) { var v = parseInt(val(n), 10); return isNaN(v) || v < 0 ? 0 : v; }
      function update() {
        out.textContent = val("base_url") + "?agent=" + enc(val("agent")) + "&pageIndex=" + num("page_index") +
          "&timer=" + num("timer") + "&showParams=" + enc(val("show_params")) + "&otherQ=" + (val("other_q") === "Y" ? "Y" : "N") +
          "&pageSize=" + num("page_size") + "&leagues=";
      }
      form.addEventListener("input", update);
      form.addEventListener("change", update);
      update();
    });
  })();
  </script>
  <script nonce="<?= h($nonce) ?>"><?= timeScript($onlineTimeout) ?></script>
  <script nonce="<?= h($nonce) ?>">
  (function () {
    "use strict";
    // Selectores que se aplican al cambiarlos (filtro de usuario, usuario del equipo)
    document.querySelectorAll("select[data-autosubmit]").forEach(function (s) {
      s.addEventListener("change", function () { s.form.submit(); });
    });

    /*
     * Anuncios: el servidor guarda las fechas en UTC.
     *  - input[data-tz]: diferencia del navegador con UTC (minutos) para convertir lo escrito.
     *  - input[data-utc]: fecha guardada (segundos UTC) => valor en hora local.
     *  - fieldset[data-scope]: muestra solo la lista (usuarios/equipos) del destino elegido.
     */
    document.querySelectorAll("input[data-tz]").forEach(function (el) {
      el.value = String(new Date().getTimezoneOffset());
    });
    function pad2(n) { return (n < 10 ? "0" : "") + n; }
    document.querySelectorAll("input[data-utc]").forEach(function (el) {
      var ts = +el.getAttribute("data-utc");
      if (!ts) return;
      var d = new Date(ts * 1000);
      el.value = d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate()) +
        "T" + pad2(d.getHours()) + ":" + pad2(d.getMinutes());
    });
    /*
     * Calendario: al pulsar el campo o el botón 📅 se despliega el selector
     * nativo de fecha y hora (showPicker: Chrome/Edge 99+, Firefox 101+,
     * Safari 16+). Si el navegador no lo permite, se enfoca el campo, que
     * tiene su propio icono de calendario. El botón × borra la fecha.
     */
    function openPicker(input) {
      try {
        if (typeof input.showPicker === "function") { input.showPicker(); return; }
      } catch (e) { /* sin gesto del usuario o no permitido: se enfoca */ }
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

    // Tiras: "Cuándo se muestra" => las fechas solo se ven con "Con fechas"
    document.querySelectorAll("fieldset[data-when]").forEach(function (fs) {
      var dates = fs.querySelector("[data-dates]");
      function show() {
        var r = fs.querySelector("input[name=when]:checked");
        dates.hidden = !r || r.value !== "dates";
      }
      fs.querySelectorAll("input[name=when]").forEach(function (r) { r.addEventListener("change", show); });
      show();
    });

    document.querySelectorAll("fieldset[data-scope]").forEach(function (fs) {
      function show() {
        var r = fs.querySelector("input[name=scope]:checked");
        var scope = r ? r.value : "all";
        fs.querySelectorAll(".pick").forEach(function (p) {
          p.classList.toggle("hide", p.getAttribute("data-for") !== scope);
        });
      }
      fs.querySelectorAll("input[name=scope]").forEach(function (r) { r.addEventListener("change", show); });
      show();
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

<?php
// Versión del archivo: 1.0.17 (2026-11-23)
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
 *    (Hasta 2026-11-22 se cambiaba en su ficha, sección Contenido; ahora el
 *    límite lo da su PLAN: ver PLANES más abajo.)
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
 *  - PLANES DE CONTENIDO (botón "Planes", ?view=plans, 2026-11-22): crear,
 *    editar y borrar planes (nombre, días, archivos, almacenamiento, costo;
 *    acciones plan_*). En la ficha del usuario, sección Plan: asignar o
 *    cambiar (user_plan_assign), RENOVAR (user_plan_renew, solo aquí: el
 *    usuario queda renovado al momento), quitar (user_plan_remove) e
 *    historial. Asignar/renovar suman los días al plan y a la vigencia. La
 *    Vigencia se sigue cambiando a mano con plan o sin él.
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
 * Bloque de TARIFA Y CRÉDITOS de un usuario (2026-11-23; sustituye a la vigencia):
 * tarifa, saldo, gasto diario y formularios de recarga (+) / descuento (−) con nota.
 * @param array<string,mixed> $u    Fila de UserRepository::all() (credits, tariff_name).
 * @param string              $back Consulta a la que volver tras el cambio.
 */
function creditBlock(array $u, string $back): string
{
    $uid = (int) $u['id'];
    $tariff = TariffRepository::forUser($u);
    $daily = CreditRepository::dailyCost($u);
    $cur = $tariff['currency'] ?? '';
    $credits = (float) $u['credits'];
    $html = '<div class="validity"><div class="validity-head">'
        . '<span class="badge ' . ($credits > 0 ? 'authorized' : 'revoked') . '">' . t('Saldo: %s', h(TariffRepository::num($credits) . ($cur ? ' ' . $cur : ''))) . '</span> '
        . '<span class="owner">' . ($tariff ? t('Tarifa %s', h($tariff['name'])) : t('Sin tarifa'))
        . ' · ' . t('gasto actual %s al día en %d dispositivo(s)', h(TariffRepository::num($daily['cost'])), $daily['devices'])
        . ($daily['cost'] > 0 ? ' · ' . t('alcanza para unos %d día(s)', (int) floor($credits / $daily['cost'])) : '') . '</span></div>'
        . '<form method="post" class="days-form">' . csrfField()
        . '<input type="hidden" name="action" value="user_credits"><input type="hidden" name="userId" value="' . $uid . '">'
        . '<input type="hidden" name="back" value="' . h($back) . '">'
        . '<label for="cr' . $uid . '" class="sr">' . t('Importe') . '</label>'
        . '<input type="text" id="cr' . $uid . '" name="amount" inputmode="decimal" placeholder="10" required class="amount-input">'
        . '<input type="text" name="note" maxlength="255" placeholder="' . h(t('Nota (opcional)')) . '" class="note-input">'
        . '<button class="ok" type="submit" name="op" value="add">' . t('+ Recargar') . '</button>'
        . '<button class="bad" type="submit" name="op" value="sub"' . ($credits > 0 ? '' : ' disabled') . '>' . t('− Descontar') . '</button>'
        . '</form>';
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
    $name = AdRepository::cleanName(param($_POST, 'name')) ?? AdRepository::cleanName($fallbackName) ?? t('Anuncio');
    $scope = param($_POST, 'scope');
    if (!in_array($scope, AdRepository::SCOPES, true)) {
        $scope = 'all';
    }
    $users = array_values(array_filter(array_map('intval', (array) ($_POST['users'] ?? [])), static fn($v) => $v > 0));
    $devices = array_values(array_filter(array_map(static fn($v) => strtoupper(trim((string) $v)), (array) ($_POST['devices'] ?? [])),
        static fn($v) => DeviceRepository::isValidId($v)));
    if ($scope === 'users' && !$users) {
        redirectWith(t('Marca al menos un usuario o elige "Todos los equipos".'), 'view=ads');
    }
    if ($scope === 'devices' && !$devices) {
        redirectWith(t('Marca al menos un equipo o elige "Todos los equipos".'), 'view=ads');
    }
    $tz = (int) param($_POST, 'tz');
    $starts = localToUtc(param($_POST, 'starts'), $tz);
    $ends = localToUtc(param($_POST, 'ends'), $tz);
    if ($starts === false || $ends === false) {
        redirectWith(t('Fecha no válida.'), 'view=ads');
    }
    if ($starts !== null && $ends !== null && $ends <= $starts) {
        redirectWith(t('La fecha de fin debe ser posterior a la de inicio.'), 'view=ads');
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
        . '<div><label for="' . $p . 'name">' . t('Nombre (solo para el panel)') . '</label>'
        . '<input type="text" id="' . $p . 'name" name="name" maxlength="100" value="' . h((string) ($ad['name'] ?? '')) . '" placeholder="' . h(t('p. ej. Promo diciembre')) . '"></div>'
        . '<div><label for="' . $p . 'every">' . t('Mostrar después de cada') . '</label>'
        . '<span class="inline-num"><input type="number" id="' . $p . 'every" name="every" min="' . AdRepository::MIN_EVERY . '" max="' . AdRepository::MAX_EVERY . '" value="' . (int) ($ad['every_n'] ?? 3) . '" required> ' . t('archivos del usuario') . '</span></div>'
        . '<div><label for="' . $p . 'seconds">' . t('Segundos en pantalla (imagen)') . '</label>'
        . '<input type="number" id="' . $p . 'seconds" name="seconds" min="' . MediaRepository::MIN_SECONDS . '" max="' . MediaRepository::MAX_SECONDS . '" value="' . (int) ($ad['seconds'] ?? 10) . '" required></div>'
        . '</div><div class="user-grid">'
        // Fechas: campo + botón que despliega el calendario + botón para borrarla
        . '<div><label for="' . $p . 'starts">' . t('Desde (opcional)') . '</label>'
        . '<span class="date-field"><input type="datetime-local" id="' . $p . 'starts" name="starts" data-utc="' . $utc($ad['starts_at'] ?? null) . '">'
        . '<button type="button" data-pick="' . $p . 'starts" title="Abrir calendario" aria-label="Abrir calendario">&#128197;</button>'
        . '<button type="button" data-clear="' . $p . 'starts" title="' . h(t('Quitar fecha')) . '" aria-label="' . h(t('Quitar fecha')) . '">' . t('&times;') . '</button></span></div>'
        . '<div><label for="' . $p . 'ends">' . t('Hasta (opcional)') . '</label>'
        . '<span class="date-field"><input type="datetime-local" id="' . $p . 'ends" name="ends" data-utc="' . $utc($ad['ends_at'] ?? null) . '">'
        . '<button type="button" data-pick="' . $p . 'ends" title="Abrir calendario" aria-label="Abrir calendario">&#128197;</button>'
        . '<button type="button" data-clear="' . $p . 'ends" title="' . h(t('Quitar fecha')) . '" aria-label="' . h(t('Quitar fecha')) . '">' . t('&times;') . '</button></span></div>'
        . '<div><label>&nbsp;</label><label class="inline"><input type="checkbox" name="active" value="1"' . (!$ad || (int) $ad['active'] === 1 ? ' checked' : '') . '> Activo</label></div>'
        . '</div>';
    // Destino
    $html .= '<fieldset class="targets" data-scope><legend>' . t('Mostrar en') . '</legend>';
    foreach (['all' => t('Todos los equipos'), 'users' => t('Equipos de estos usuarios'), 'devices' => t('Estos equipos')] as $k => $text) {
        $html .= '<label class="inline"><input type="radio" name="scope" value="' . $k . '"' . ($scope === $k ? ' checked' : '') . '> ' . $text . '</label>';
    }
    $html .= '<div class="pick" data-for="users"><span class="owner">' . t('Usuarios (también sus equipos futuros):') . '</span>';
    if (!$users) {
        $html .= '<span class="owner">' . t('No hay usuarios.') . '</span>';
    }
    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $html .= '<label class="inline"><input type="checkbox" name="users[]" value="' . $uid . '"' . (isset($chosenUsers[$uid]) ? ' checked' : '') . '> '
            . h($u['company']) . ' <span class="owner">(' . (int) $u['devices'] . ')</span></label>';
    }
    // Buscador de equipos (alias, alias del admin, ID, empresa, MAC y MAC del case)
    $html .= '</div><div class="pick" data-for="devices"><span class="owner">' . t('Equipos:') . '</span>'
        . (count($devices) > 1 ? deviceFilterBox(t('Buscar equipo por alias, ID, empresa, MAC o MAC del case')) : '');
    if (!$devices) {
        $html .= '<span class="owner">' . t('No hay equipos.') . '</span>';
    }
    foreach ($devices as $d) {
        $id = (string) $d['device_id'];
        $html .= '<label class="inline"' . deviceSearchAttr([$d['label'], $d['admin_alias'] ?? null, $id, $d['user_company'], $d['profile_serial'] ?? null], [$d['mac'] ?? null, $d['profile_case_mac'] ?? null])
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
        . '<button type="button" data-clear="' . $p . $name . '" title="' . h(t('Quitar fecha')) . '" aria-label="' . h(t('Quitar fecha')) . '">' . t('&times;') . '</button></span></div>';
    return '<fieldset class="targets" data-when><legend>' . t('Tiempo de visibilidad') . '</legend>'
        . '<input type="hidden" name="tz" value="0" data-tz>'
        . '<label class="inline"><input type="radio" name="when" value="always"' . ($hasDates ? '' : ' checked') . '> Permanente</label>'
        . '<label class="inline"><input type="radio" name="when" value="dates"' . ($hasDates ? ' checked' : '') . '> ' . t('Con fechas') . '</label>'
        . '<div class="user-grid" data-dates>'
        . $date('starts', t('Desde (opcional)'), $o['starts_at'] ?? null)
        . $date('ends', t('Hasta (opcional)'), $o['ends_at'] ?? null)
        . '</div>'
        . '<span class="owner">' . t('Fuera de esas fechas las pantallas no la muestran (se aplica en su próxima consulta, ≈ 1 min). Hora de este navegador.') . '</span>'
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
        'active' => ['authorized', t('En curso')],
        'scheduled' => ['pending', 'Programada'],
        'ended' => ['revoked', 'Finalizada'],
    ][$state];
    return '<span class="badge ' . $class . '">' . $label . '</span>';
}

/** Texto del periodo: "se muestra siempre" o "desde … · hasta …". */
function overlayWhenText(array $o): string
{
    if (empty($o['starts_at']) && empty($o['ends_at'])) {
        return t('Permanente: se muestra siempre');
    }
    return (!empty($o['starts_at']) ? t('Desde') . ' ' . dateTag((string) $o['starts_at']) : t('Desde ya'))
        . ' · ' . (!empty($o['ends_at']) ? t('hasta') . ' ' . dateTag((string) $o['ends_at']) : t('sin fecha de fin'));
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
    $html = '<fieldset class="targets" data-scope><legend>' . t('Mostrar en') . '</legend>';
    foreach (['none' => t('Sin asignar'), 'all' => t('Todos los equipos'), 'users' => t('Equipos de estos usuarios'), 'devices' => t('Estos equipos')] as $k => $text) {
        $html .= '<label class="inline"><input type="radio" name="scope" value="' . $k . '"' . ($scope === $k ? ' checked' : '') . '> ' . $text . '</label>';
    }
    $html .= '<div class="pick" data-for="users"><span class="owner">' . t('Usuarios (también sus equipos futuros):') . '</span>';
    if (!$users) {
        $html .= '<span class="owner">' . t('No hay usuarios.') . '</span>';
    }
    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $html .= '<label class="inline"><input type="checkbox" name="users[]" value="' . $uid . '"' . (isset($chosenUsers[$uid]) ? ' checked' : '') . '> '
            . h($u['company']) . ' <span class="owner">(' . (int) $u['devices'] . ')</span></label>';
    }
    // Buscador de equipos (alias, alias del admin, ID, empresa, MAC y MAC del case)
    $html .= '</div><div class="pick" data-for="devices"><span class="owner">' . t('Equipos:') . '</span>'
        . (count($devices) > 1 ? deviceFilterBox(t('Buscar equipo por alias, ID, empresa, MAC o MAC del case')) : '');
    if (!$devices) {
        $html .= '<span class="owner">' . t('No hay equipos.') . '</span>';
    }
    foreach ($devices as $d) {
        $id = (string) $d['device_id'];
        $html .= '<label class="inline"' . deviceSearchAttr([$d['label'], $d['admin_alias'] ?? null, $id, $d['user_company'], $d['profile_serial'] ?? null], [$d['mac'] ?? null, $d['profile_case_mac'] ?? null])
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
        redirectWith(t('Marca al menos un usuario, o elige otra opción.'), 'view=overlays');
    }
    if ($scope === 'devices' && !$devices) {
        redirectWith(t('Marca al menos un equipo, o elige otra opción.'), 'view=overlays');
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
            redirectWith(t('Fecha no válida.'), 'view=overlays');
        }
        if ($starts === null && $ends === null) {
            redirectWith(t('Indica al menos una fecha (desde o hasta) o elige "Permanente".'), 'view=overlays');
        }
    }
    if ($starts !== null && $ends !== null && $ends <= $starts) {
        redirectWith(t('La fecha de fin debe ser posterior a la de inicio.'), 'view=overlays');
    }
    return [$starts, $ends];
}

/** Texto del destino de una tira/banner (del administrador o de un usuario). */
function overlayTargetText(array $o, array $usersById): string
{
    if (!overlayAssigned($o)) {
        return t('Sin asignar: no se muestra en ninguna pantalla');
    }
    if ($o['user_id'] !== null) {
        return $o['scope'] === 'devices' ? t('Equipos:') . ' ' . ($o['devices'] ? implode(', ', $o['devices']) : t('ninguno')) : t('Todos los equipos del usuario');
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
        . $in('name', t('Nombre (solo para el panel)'), 'text', (string) $v['name'], ' maxlength="100" placeholder="' . h(t('p. ej. Pizarra deportes')) . '" required')
        . $in('base_url', t('URL base (sin "?" ni parámetros)'), 'url', (string) $v['base_url'], ' maxlength="500" required')
        . '<div class="user-grid">'
        . $in('agent', 'agent (texto)', 'text', (string) $v['agent'], ' maxlength="100"')
        . $in('page_index', t('pageIndex (número)'), 'number', (string) $v['page_index'], $num)
        . $in('timer', t('timer (número)'), 'number', (string) $v['timer'], $num)
        . $in('show_params', 'showParams (texto)', 'text', (string) $v['show_params'], ' maxlength="200"')
        . '<div><label for="' . $p . 'other_q">otherQ</label><select id="' . $p . 'other_q" name="other_q" data-route="other_q">'
        . '<option value="N"' . ($v['other_q'] === 'Y' ? '' : ' selected') . '>N</option>'
        . '<option value="Y"' . ($v['other_q'] === 'Y' ? ' selected' : '') . '>Y</option></select></div>'
        . $in('page_size', t('pageSize (número)'), 'number', (string) $v['page_size'], $num)
        . '</div>'
        . '<label>' . t('URL final (leagues = la liga de cada equipo, que elige el usuario en /cuenta/; sin liga queda vacío)') . '</label>'
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
    $html = '<fieldset class="targets"><legend>' . t('Usuarios con esta ruta (y todos sus equipos)') . '</legend>';
    if (!$users) {
        $html .= '<span class="owner">' . t('No hay usuarios.') . '</span>';
    }
    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $cur = (int) ($u['route_id'] ?? 0);
        $html .= '<label class="inline"><input type="checkbox" name="users[]" value="' . $uid . '"' . ($cur === (int) $r['id'] ? ' checked' : '') . '> '
            . h($u['company']) . ' <span class="owner">(' . (int) $u['devices'] . ' ' . t('equipo(s)')
            . ($cur && $cur !== (int) $r['id'] ? ' · ahora: ' . h($routeNames[$cur] ?? '#' . $cur) : '') . ')</span></label>';
    }
    return $html . '</fieldset>';
}

/** Texto del destino de un anuncio para la lista. */
function adTargetText(array $ad, array $usersById): string
{
    if ($ad['scope'] === 'users') {
        $names = array_map(static fn($id) => $usersById[$id]['company'] ?? ('#' . $id), $ad['users']);
        return t('Usuarios:') . ' ' . ($names ? implode(', ', $names) : t('ninguno'));
    }
    if ($ad['scope'] === 'devices') {
        return t('Equipos:') . ' ' . ($ad['devices'] ? implode(', ', $ad['devices']) : t('ninguno'));
    }
    return t('Todos los equipos');
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
        . '<div><label for="' . $p . 'name">' . t('Nombre') . '</label>'
        . '<input type="text" id="' . $p . 'name" name="name" maxlength="100" required value="' . h((string) ($l['name'] ?? '')) . '" placeholder="' . h(t('Béisbol del Caribe')) . '"></div>'
        . '<div><label for="' . $p . 'type">Tipo</label><select id="' . $p . 'type" name="typeId" required>' . $opts . '</select></div>'
        . '</div>'
        . '<label for="' . $p . 'codes">' . t('Contenido: códigos de la liga (separados por coma, espacio o línea)') . '</label>'
        . '<textarea id="' . $p . 'codes" name="codes" rows="3" required placeholder="LIDOM, MLB, KO, PRO">'
        . h(str_replace(',', ', ', (string) ($l['codes'] ?? ''))) . '</textarea>'
        . '<p class="owner">' . t('Se guardan en MAYÚSCULAS y sin repetir (máx.') . ' ' . LeagueRepository::MAX_CODES . '). '
        . t('La TV del equipo que use esta liga guarda el código de su tipo y estos códigos.') . '</p>';
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
        $setupError = t('Falta la contraseña del panel: en private/config.php, admin_password_hash sigue diciendo CAMBIAR.') . ' '
            . t('Genera el hash como explica config.sample.php y pégalo entre comillas simples.');
    }
} catch (Throwable $e) {
    $config = [];
    $setupError = $e->getMessage();
}
$onlineTimeout = onlineTimeout($config);
$loggedIn = !empty($_SESSION['admin']);
// IDIOMA del panel (2026-11-22): ?lang= => guardado (sp_kv) => cookie => navegador; ver applyAdminLang() en web.php
applyAdminLang('./', $loggedIn);
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
        redirectWith(t('El archivo es demasiado grande para el servidor (límite de PHP post_max_size:') . ' '
            . ini_get('post_max_size') . ').', $loggedIn ? 'view=ads' : '');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = param($_POST, 'action');
        if (!hash_equals(csrfToken(), param($_POST, 'csrf'))) {
            redirectWith(t('La sesión caducó. Vuelve a intentarlo.'));
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
                    redirectWith(t('Sesión iniciada.'));
                }
                RateLimiter::hit($bucket, PHP_INT_MAX, $window);
                $error = t('Usuario o contraseña incorrectos.');
            }
        } elseif (!$loggedIn) {
            redirectWith(t('Inicia sesión.'));
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
                ? t('Ese archivo ya no existe.')
                : '«' . $gone['original_name'] . t('» de «') . $gone['user_company'] . t('» eliminado.') . ' '
                    . t('Sus pantallas dejarán de mostrarlo en su próxima consulta.'), $back);
        } elseif ($action === 'media_pause' || $action === 'media_resume') {
            /* ---- Pausar / activar un archivo de un usuario (vista ?view=media) ---- */
            $back = param($_POST, 'back') !== '' ? param($_POST, 'back') : 'view=media';
            $on = $action === 'media_resume';
            $mid = (int) param($_POST, 'mediaId');
            if (!MediaRepository::setActive($mid, null, $on)) {
                redirectWith(t('Ese archivo ya no existe.'), $back);
            }
            $m = MediaRepository::find($mid) ?? [];
            redirectWith('«' . ($m['original_name'] ?? '') . '» ' . ($on
                ? t('activado: sus pantallas lo mostrarán en su próxima consulta.')
                : t('pausado: sus pantallas lo quitarán en su próxima consulta (no se borra).')), $back);
        } elseif (str_starts_with($action, 'league_')) {
            /* ---- LIGAS y TIPOS DE LIGA (vista ?view=leagues) ---- */
            $typeId = (int) param($_POST, 'typeId');
            $leagueId = (int) param($_POST, 'leagueId');
            switch ($action) {
                case 'league_type_create':
                    $code = LeagueRepository::cleanTypeCode(param($_POST, 'code'));
                    $name = LeagueRepository::cleanName(param($_POST, 'name'), 60);
                    if ($code === null) {
                        redirectWith(t('Código no válido: de 1 a 20 letras minúsculas, cifras, _ o - (p. ej. mlb). «todos» está reservado.'), 'view=leagues');
                    }
                    if ($name === null) {
                        redirectWith(t('El nombre del tipo debe tener entre 1 y 60 caracteres.'), 'view=leagues');
                    }
                    if (!LeagueRepository::createType($code, $name)) {
                        redirectWith(t('Ya existe un tipo con el código «%s».', (string) $code), 'view=leagues');
                    }
                    redirectWith(t('Tipo de liga «%s» (%s) creado.', (string) $name, (string) $code), 'view=leagues');
                case 'league_type_rename':
                    $name = LeagueRepository::cleanName(param($_POST, 'name'), 60);
                    if ($name === null || LeagueRepository::findType($typeId) === null) {
                        redirectWith(t('Nombre no válido o el tipo ya no existe.'), 'view=leagues');
                    }
                    LeagueRepository::renameType($typeId, $name);
                    redirectWith(t('Tipo renombrado a «%s». Las TV lo verán en su próxima consulta.', (string) $name), 'view=leagues');
                case 'league_type_up':
                case 'league_type_down':
                    LeagueRepository::moveType($typeId, $action === 'league_type_up' ? -1 : 1);
                    redirectWith(t('Orden de los tipos cambiado.'), 'view=leagues');
                case 'league_type_delete':
                    $type = LeagueRepository::findType($typeId);
                    if ($type === null) {
                        redirectWith(t('Ese tipo ya no existe.'), 'view=leagues');
                    }
                    if (!LeagueRepository::deleteType($typeId)) {
                        redirectWith(t('«%s» tiene ligas: elimínalas o cámbialas de tipo antes de borrarlo.', (string) $type['name']), 'view=leagues');
                    }
                    redirectWith(t('Tipo «%s» eliminado.', (string) $type['name']), 'view=leagues');
                case 'league_create':
                case 'league_update':
                    // Liga = nombre + tipo + contenido (códigos)
                    if ($action === 'league_update' && LeagueRepository::find($leagueId) === null) {
                        redirectWith(t('Esa liga ya no existe.'), 'view=leagues');
                    }
                    $name = LeagueRepository::cleanName(param($_POST, 'name'), 100);
                    if ($name === null) {
                        redirectWith(t('El nombre de la liga debe tener entre 1 y 100 caracteres.'), 'view=leagues');
                    }
                    if (LeagueRepository::findType($typeId) === null) {
                        redirectWith(t('Elige un tipo de liga (créalo antes si no existe).'), 'view=leagues');
                    }
                    [$codes, $bad] = LeagueRepository::cleanCodes(param($_POST, 'codes'));
                    if ($codes === null) {
                        redirectWith(t('Escribe al menos un código válido (letras, cifras o _; p. ej. LIDOM, MLB, KO).'), 'view=leagues');
                    }
                    if ($action === 'league_create') {
                        LeagueRepository::create($typeId, $name, $codes);
                    } else {
                        LeagueRepository::update($leagueId, $typeId, $name, $codes);
                    }
                    redirectWith(t('Liga «%s» ', (string) $name) . ($action === 'league_create' ? 'creada' : 'guardada') . ': ' . str_replace(',', ', ', $codes) . '.'
                        . ($bad ? ' ' . t('Se ignoraron por no ser válidos:') . ' ' . implode(' ', array_slice($bad, 0, 10)) . '.' : '')
                        . ($action === 'league_update' ? ' ' . t('Las TV de sus usuarios lo verán en su próxima consulta.') : ''), 'view=leagues');
                case 'league_delete':
                    $league = LeagueRepository::find($leagueId);
                    if ($league === null) {
                        redirectWith(t('Esa liga ya no existe.'), 'view=leagues');
                    }
                    LeagueRepository::delete($leagueId);
                    redirectWith(t('Liga «%s» eliminada (y quitada de sus usuarios).', (string) $league['name']), 'view=leagues');
                default:
                    redirectWith(t('Acción desconocida.'), 'view=leagues');
            }
        } elseif (str_starts_with($action, 'msg_')) {
            /* ---- BUZÓN DE MENSAJES (vista ?view=messages) ---- */
            $to = param($_POST, 'userId');
            switch ($action) {
                case 'msg_send':
                    $body = MessageRepository::cleanBody(param($_POST, 'body'));
                    if ($body === null) {
                        redirectWith(t('Escribe el mensaje (máximo') . ' ' . MessageRepository::MAX_BODY . ' caracteres).',
                            ctype_digit($to) ? 'view=messages&mu=' . (int) $to : 'view=messages');
                    }
                    if ($to === 'all') {
                        $n = MessageRepository::sendToAll($body);
                        redirectWith(t('Mensaje enviado a %s usuario(s).', (string) $n), 'view=messages');
                    }
                    $target = ctype_digit($to) ? UserRepository::find((int) $to) : null;
                    if ($target === null) {
                        redirectWith(t('Elige un usuario.'), 'view=messages');
                    }
                    MessageRepository::send((int) $target['id'], 'admin', $body);
                    redirectWith(t('Mensaje enviado a «%s».', (string) $target['company']), 'view=messages&mu=' . (int) $target['id']);
                case 'msg_delete':
                    $target = ctype_digit($to) ? UserRepository::find((int) $to) : null;
                    if ($target === null) {
                        redirectWith(t('Ese usuario ya no existe.'), 'view=messages');
                    }
                    $n = MessageRepository::deleteThread((int) $target['id']);
                    redirectWith(t('Conversación con «%s» borrada (%s mensaje(s)).', (string) $target['company'], (string) $n), 'view=messages');
                default:
                    redirectWith(t('Acción desconocida.'), 'view=messages');
            }
        } elseif (str_starts_with($action, 'profile_')) {
            /* ---- PERFILES DE EQUIPO (vista ?view=profiles y tarjeta del equipo) ---- */
            $back = param($_POST, 'back') !== '' ? param($_POST, 'back') : 'view=profiles';
            $profileId = (int) param($_POST, 'profileId');
            $profile = $action === 'profile_create' ? null : ProfileRepository::find($profileId);
            if ($action !== 'profile_create' && $profile === null) {
                redirectWith(t('Ese perfil ya no existe.'), $back);
            }
            // Equipo elegido ("" = sin equipo)
            $devRaw = strtoupper(trim(param($_POST, 'deviceId')));
            $devId = DeviceRepository::isValidId($devRaw) ? $devRaw : null;
            if ($devRaw !== '' && $devId === null) {
                redirectWith(t('ID de dispositivo inválido.'), $back);
            }
            switch ($action) {
                case 'profile_create':
                case 'profile_update':
                    $data = ProfileRepository::clean($_POST);
                    if (is_string($data)) {
                        redirectWith($data, $back);
                    }
                    if (ProfileRepository::macTaken($data['case_mac'], $profileId)) {
                        redirectWith(t('Ya hay un perfil con la MAC Case %s: búscalo y asígnalo en lugar de crear otro.', (string) $data['case_mac']), $back);
                    }
                    // El S/N (opcional) tampoco se puede repetir
                    if (ProfileRepository::serialTaken($data['serial'], $profileId)) {
                        redirectWith(t('Ya hay un perfil con el S/N %s: búscalo y asígnalo en lugar de crear otro.', (string) $data['serial']), $back);
                    }
                    if ($action === 'profile_update') {
                        ProfileRepository::update($profileId, $data);
                        redirectWith(t('Perfil «') . ProfileRepository::text($data) . t('» guardado.'), $back);
                    }
                    $newId = ProfileRepository::create($data);
                    if ($devId !== null && !ProfileRepository::assign($newId, $devId)) {
                        redirectWith(t('Perfil creado, pero el equipo') . ' ' . $devId . ' ' . t('no existe: queda sin equipo.'), $back);
                    }
                    redirectWith(t('Perfil «') . ProfileRepository::text($data) . t('» creado')
                        . ($devId !== null ? t(' y asignado a %s. Ya se puede autorizar.', (string) $devId) : ' ' . t('sin equipo: asígnalo cuando quieras.')), $back);
                case 'profile_assign':
                    if (!ProfileRepository::assign($profileId, $devId)) {
                        redirectWith(t('El equipo %s no existe.', (string) $devRaw), $back);
                    }
                    $txt = ProfileRepository::text($profile);
                    redirectWith($devId === null ? t('Perfil «%s» sin equipo.', (string) $txt)
                        : t('Perfil «%s» asignado a %s', (string) $txt, (string) $devId)
                          . (!empty($profile['device_id']) && $profile['device_id'] !== $devId ? t(' (antes en %s).', (string) $profile['device_id']) : '.'), $back);
                case 'profile_delete':
                    ProfileRepository::delete($profileId);
                    redirectWith(t('Perfil «') . ProfileRepository::text($profile) . t('» eliminado')
                        . (!empty($profile['device_id']) ? t('; %s queda sin perfil.', (string) $profile['device_id']) : '.'), $back);
                default:
                    redirectWith(t('Acción desconocida.'), $back);
            }
        } elseif (str_starts_with($action, 'tariff_')) {
            /* ---- TARIFAS (vista ?view=tariffs, 2026-11-23; sustituyen a los planes) ---- */
            $tariffId = (int) param($_POST, 'tariffId');
            $tariff = $action === 'tariff_create' ? null : TariffRepository::find($tariffId);
            if ($action !== 'tariff_create' && $tariff === null) {
                redirectWith(t('Esa tarifa ya no existe.'), 'view=tariffs');
            }
            switch ($action) {
                case 'tariff_create':
                case 'tariff_update':
                    $data = TariffRepository::clean($_POST);
                    if (is_string($data)) {
                        redirectWith($data, 'view=tariffs');
                    }
                    if (TariffRepository::nameTaken($data['name'], $tariffId)) {
                        redirectWith(t('Ya hay una tarifa llamada «%s».', $data['name']), 'view=tariffs');
                    }
                    if ($action === 'tariff_create') {
                        TariffRepository::create($data);
                        redirectWith(t('Tarifa «%s» creada. Asígnala en la ficha de cada usuario.', $data['name']), 'view=tariffs');
                    }
                    TariffRepository::update($tariffId, $data);
                    redirectWith(t('Tarifa «%s» guardada. Los precios nuevos se aplican desde el próximo cobro diario.', $data['name']), 'view=tariffs');
                case 'tariff_delete':
                    TariffRepository::delete($tariffId);
                    redirectWith(t('Tarifa «%s» eliminada; sus usuarios quedan sin tarifa y su contenido se desactivará en el próximo cobro.', (string) $tariff['name']), 'view=tariffs');
                default:
                    redirectWith(t('Acción desconocida.'), 'view=tariffs');
            }
        } elseif (str_starts_with($action, 'plan_')) {
            /* ---- PLANES DE CONTENIDO (vista ?view=plans, 2026-11-22) ---- */
            $planId = (int) param($_POST, 'planId');
            $plan = $action === 'plan_create' ? null : PlanRepository::find($planId);
            if ($action !== 'plan_create' && $plan === null) {
                redirectWith(t('Ese plan ya no existe.'), 'view=plans');
            }
            switch ($action) {
                case 'plan_create':
                case 'plan_update':
                    $data = PlanRepository::clean($_POST);
                    if (is_string($data)) {
                        redirectWith($data, 'view=plans');
                    }
                    if (PlanRepository::nameTaken($data['name'], $planId)) {
                        redirectWith(t('Ya hay un plan llamado «%s».', (string) $data['name']), 'view=plans');
                    }
                    if ($action === 'plan_create') {
                        PlanRepository::create($data);
                        redirectWith(t('Plan «%s» creado. Asígnalo en la ficha de cada usuario.', (string) $data['name']), 'view=plans');
                    }
                    PlanRepository::update($planId, $data);
                    redirectWith(t('Plan «%s» guardado. Sus límites valen ya para quien lo tenga; el historial conserva los datos anteriores.', (string) $data['name']), 'view=plans');
                case 'plan_delete':
                    PlanRepository::delete($planId);
                    redirectWith(t('Plan «%s» eliminado; sus usuarios quedan sin plan (el historial se conserva).', (string) $plan['name']), 'view=plans');
                default:
                    redirectWith(t('Acción desconocida.'), 'view=plans');
            }
        } elseif (str_starts_with($action, 'route_')) {
            /* ---- GESTIÓN DE RUTAS (vista ?view=routes) ---- */
            $routeId = (int) param($_POST, 'routeId');
            $route = $action === 'route_create' ? null : RouteRepository::find($routeId);
            if ($action !== 'route_create' && $route === null) {
                redirectWith(t('Esa ruta ya no existe.'), 'view=routes');
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
                        redirectWith(t('Ruta «%s» creada. Asígnala a uno o varios usuarios: sus equipos la recibirán en su próxima consulta.', (string) $data['name']), 'view=routes');
                    }
                    RouteRepository::update($routeId, $data);
                    redirectWith(t('Ruta «%s» guardada. Los equipos de sus usuarios reciben la URL nueva en su próxima consulta (≈ 1 min).', (string) $data['name']), 'view=routes');
                case 'route_users':
                    $n = RouteRepository::setUsers($routeId, (array) ($_POST['users'] ?? []));
                    redirectWith(t('Ruta «%s»: %s usuario(s). Todos sus equipos la reciben en su próxima consulta (≈ 1 min).', (string) $route['name'], (string) $n), 'view=routes');
                case 'route_delete':
                    RouteRepository::delete($routeId);
                    redirectWith(t('Ruta «%s» eliminada; sus usuarios quedan sin ruta.', (string) $route['name']), 'view=routes');
                default:
                    redirectWith(t('Acción desconocida.'), 'view=routes');
            }
        } elseif (str_starts_with($action, 'ov_')) {
            /* ---- TIRAS Y BANNERS (vista ?view=overlays) ---- */
            $oid = (int) param($_POST, 'overlayId');
            $ov = $action === 'ov_create' ? null : OverlayRepository::find($oid);
            if ($action !== 'ov_create' && $ov === null) {
                redirectWith(t('Esa tira o banner ya no existe.'), 'view=overlays');
            }
            // Las de los usuarios: el administrador solo puede pausar/activar o eliminar
            if (($action === 'ov_update' || $action === 'ov_assign') && $ov['user_id'] !== null) {
                redirectWith(t('Las tiras de los usuarios solo se pueden pausar o eliminar.'), 'view=overlays');
            }
            [$what, $g] = overlayNoun((string) ($ov['kind'] ?? param($_POST, 'kind')));
            $owner = $ov !== null && $ov['user_id'] !== null ? ' ' . t('de') . ' ' . ($usersById[(int) $ov['user_id']]['company'] ?? t('un usuario')) : '';
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
                        redirectWith(t('%s «%s» cread%s. Ahora usa «Asignar a equipos» para elegir dónde y durante cuánto tiempo se muestra.', (string) $what, (string) $style['name'], (string) $g), 'view=overlays');
                    }
                    OverlayRepository::update($oid, $data);
                    redirectWith(t('%s «%s» guardad%s. Las pantallas lo aplican en su próxima consulta (≈ 1 min).', (string) $what, (string) $style['name'], (string) $g), 'view=overlays');
                case 'ov_assign':
                    [$scope, $aUsers, $aDevices] = overlayAdminTargetData();
                    // Tiempo de visibilidad de esta asignación (permanente o con fechas)
                    [$starts, $ends] = $scope === 'none' ? [null, null] : overlayAdminWhenData();
                    OverlayRepository::assign($oid, null, $scope, $aUsers, $aDevices, $starts, $ends);
                    $text = [
                        'none' => t('%1$s «%2$s» sin asignar: no se mostrará en ninguna pantalla', $what, (string) $ov['name']),
                        'all' => t('%1$s «%2$s» asignad%3$s a todos los equipos', $what, (string) $ov['name'], $g),
                        'users' => t('%1$s «%2$s» asignad%3$s a los equipos de %4$d usuario(s)', $what, (string) $ov['name'], $g, count($aUsers)),
                        'devices' => t('%1$s «%2$s» asignad%3$s a %4$d equipo(s)', $what, (string) $ov['name'], $g, count($aDevices)),
                    ][$scope];
                    redirectWith($text . ($scope === 'none' ? '.'
                        : ($starts === null && $ends === null ? ' ' . t('de forma permanente.') : ' ' . t('con fechas.')) . ' ' . t('Se aplica en la próxima consulta (≈ 1 min).')), 'view=overlays');
                case 'ov_pause':
                case 'ov_resume':
                    OverlayRepository::setActive($oid, $action === 'ov_resume');
                    redirectWith($action === 'ov_resume' ? t('%1$s «%2$s»%3$s activad%4$s.', $what, (string) $ov['name'], $owner, $g)
                        : t('%1$s «%2$s»%3$s pausad%4$s.', $what, (string) $ov['name'], $owner, $g), 'view=overlays');
                case 'ov_delete':
                    OverlayRepository::delete($oid);
                    redirectWith(t('%s «%s»%s eliminad%s. Las pantallas lo quitarán en su próxima consulta.', (string) $what, (string) $ov['name'], (string) $owner, (string) $g), 'view=overlays');
                default:
                    redirectWith(t('Acción desconocida.'), 'view=overlays');
            }
        } elseif (str_starts_with($action, 'ad_')) {
            /* ---- ANUNCIOS (vista ?view=ads) ---- */
            $adId = (int) param($_POST, 'adId');
            $ad = $action === 'ad_upload' ? null : AdRepository::find($adId);
            if ($action !== 'ad_upload' && $ad === null) {
                redirectWith(t('Ese anuncio ya no existe.'), 'view=ads');
            }
            switch ($action) {
                case 'ad_upload':
                    $f = $_FILES['file'] ?? null;
                    $err = is_array($f) && is_int($f['error'] ?? null) ? $f['error'] : UPLOAD_ERR_NO_FILE;
                    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                        redirectWith(t('El archivo supera el límite de PHP (upload_max_filesize:') . ' ' . ini_get('upload_max_filesize') . ').', 'view=ads');
                    }
                    if ($err !== UPLOAD_ERR_OK) {
                        redirectWith($err === UPLOAD_ERR_NO_FILE ? t('Elige un archivo.') : t('La subida falló (código') . ' ' . $err . t('). Inténtalo de nuevo.'), 'view=ads');
                    }
                    if ((int) $f['size'] > MediaRepository::maxBytes()) {
                        redirectWith(t('El archivo supera el máximo de') . ' ' . formatBytes(MediaRepository::maxBytes()) . '.', 'view=ads');
                    }
                    $mime = MediaRepository::detectMime((string) $f['tmp_name']);
                    if ($mime === null) {
                        redirectWith(t('Tipo de archivo no admitido. Usa imágenes JPG, PNG, WEBP o GIF, o videos MP4 (H.264) o WEBM.'), 'view=ads');
                    }
                    // Video que las TV no pueden mostrar fluido (60 fps, 4K, HEVC...): se rechaza
                    $videoProblem = MediaRepository::videoProblem((string) $f['tmp_name'], $mime);
                    if ($videoProblem !== null) {
                        redirectWith('«' . $f['name'] . t('» no se subió.') . ' ' . $videoProblem, 'view=ads');
                    }
                    $data = adFormData(pathinfo((string) $f['name'], PATHINFO_FILENAME));
                    AdRepository::create((string) $f['tmp_name'], (string) $f['name'], $mime, $data);
                    redirectWith(t('Anuncio «%s» subido. Los equipos lo descargarán en su próxima consulta (≈ 1 min).', (string) $data['name']), 'view=ads');
                case 'ad_update':
                    $data = adFormData((string) $ad['name']);
                    AdRepository::update($adId, $data);
                    redirectWith(t('Anuncio «%s» guardado. Se aplica en la próxima consulta de los equipos.', (string) $data['name']), 'view=ads');
                case 'ad_pause':
                case 'ad_resume':
                    AdRepository::setActive($adId, $action === 'ad_resume');
                    redirectWith($action === 'ad_resume' ? t('Anuncio «%s» activado.', (string) $ad['name']) : t('Anuncio «%s» pausado.', (string) $ad['name']), 'view=ads');
                case 'ad_up':
                case 'ad_down':
                    AdRepository::move($adId, $action === 'ad_up' ? -1 : 1);
                    redirectWith(t('Orden de los anuncios cambiado.'), 'view=ads');
                case 'ad_delete':
                    AdRepository::delete($adId);
                    redirectWith(t('Anuncio «%s» eliminado. Las pantallas dejarán de mostrarlo en su próxima consulta.', (string) $ad['name']), 'view=ads');
                default:
                    redirectWith(t('Acción desconocida.'), 'view=ads');
            }
        } elseif (str_starts_with($action, 'user_')) {
            /* ---- Acciones sobre USUARIOS (vista ?view=users) ---- */
            $uid = (int) param($_POST, 'userId');
            $user = $uid > 0 ? UserRepository::find($uid) : null;
            // Volver a la vista de usuarios o a la lista de equipos de ese usuario
            $back = param($_POST, 'back') !== '' ? param($_POST, 'back') : 'view=users';
            if ($action !== 'user_create' && $user === null) {
                redirectWith(t('Ese usuario no existe.'), 'view=users');
            }
            switch ($action) {
                case 'user_create':
                case 'user_update':
                    $email = UserRepository::cleanEmail(param($_POST, 'email'));
                    $phone = UserRepository::cleanPhone(param($_POST, 'phone'));
                    $company = UserRepository::cleanCompany(param($_POST, 'company'));
                    if ($email === null) {
                        redirectWith(t('El email no es válido.'), $back);
                    }
                    if ($phone === null) {
                        redirectWith(t('El teléfono no es válido (de 6 a 15 dígitos; se permiten +, espacios, guiones y paréntesis).'), $back);
                    }
                    if ($company === null) {
                        redirectWith(t('El nombre de la empresa debe tener entre 1 y 100 caracteres.'), $back);
                    }
                    if (UserRepository::emailTaken($email, $action === 'user_update' ? $uid : 0)) {
                        redirectWith(t('Ya existe un usuario con el email %s.', (string) $email), $back);
                    }
                    if ($action === 'user_update') {
                        UserRepository::update($uid, $email, $phone, $company);
                        redirectWith(t('Datos de «%s» guardados.', (string) $company), $back);
                    }
                    [$newId, $password] = UserRepository::create($email, $phone, $company);
                    // La contraseña temporal se muestra UNA sola vez (en la ficha del usuario nuevo)
                    $_SESSION['newPassword'] = ['email' => $email, 'company' => $company, 'password' => $password];
                    redirectWith(t('Usuario «%s» creado.', (string) $company), 'view=users&user=' . $newId);
                case 'user_reset':
                    $password = UserRepository::resetPassword($uid);
                    $_SESSION['newPassword'] = ['email' => $user['email'], 'company' => $user['company'], 'password' => $password];
                    redirectWith(t('Contraseña de «%s» restablecida; su sesión abierta se cierra.', (string) $user['company']), $back);
                case 'user_tariff':
                    // TARIFA del usuario ("" = sin tarifa: su contenido se desactiva en el próximo cobro)
                    $raw = param($_POST, 'tariffId');
                    $tid = ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null;
                    if (!TariffRepository::assign($uid, $tid)) {
                        redirectWith(t('Esa tarifa ya no existe.'), $back);
                    }
                    $tf = $tid === null ? null : TariffRepository::find($tid);
                    redirectWith($tf === null ? t('«%s» queda sin tarifa: no podrá subir archivos ni activar contenido.', (string) $user['company'])
                        : t('Tarifa «%s» asignada a «%s».', (string) $tf['name'], (string) $user['company']), $back);
                case 'user_credits':
                    // RECARGA (+) o DESCUENTO (−) de créditos, con su ID de transacción
                    $sub = param($_POST, 'op') === 'sub';
                    $res = CreditRepository::adminMove($uid, param($_POST, 'amount'), $sub, param($_POST, 'note'));
                    if (is_string($res)) {
                        redirectWith($res, $back);
                    }
                    redirectWith(($sub ? t('Descontados %s créditos a «%s»', TariffRepository::num(param($_POST, 'amount')), (string) $user['company'])
                        : t('Recargados %s créditos a «%s»', TariffRepository::num(param($_POST, 'amount')), (string) $user['company']))
                        . ' ' . t('(transacción %s). Saldo: %s.', $res['txn'], TariffRepository::num($res['balance'])), $back);
                case 'user_plan_assign':
                case 'user_plan_renew':
                    // PLAN (2026-11-22): asignar (o cambiar) y renovar suman los días del plan
                    // al plan y a la vigencia, y quedan en el historial que ve el usuario
                    $renew = $action === 'user_plan_renew';
                    $err = $renew ? PlanRepository::renew($uid) : PlanRepository::assign($uid, (int) param($_POST, 'planId'));
                    if ($err !== null) {
                        redirectWith($err, $back);
                    }
                    $fresh = UserRepository::find($uid) ?? $user;
                    $ps = PlanRepository::forUser($fresh);
                    redirectWith(($renew ? t('Plan renovado') : t('Plan asignado')) . ' ' . t('(transacción') . ' ' . PlanRepository::$lastTxnId . t(') a «%s»: «%s» +%s días,', (string) $user['company'], (string) $ps['plan']['name'], (string) $ps['plan']['days'])
                        . ' ' . t('vigente hasta el') . ' ' . gmdate('d/m/Y H:i', (int) strtotime($ps['until'] . ' UTC')) . ' UTC.'
                        . ' ' . t('La vigencia del contenido también suma esos días. Queda en su historial.'), $back);
                case 'user_plan_remove':
                    PlanRepository::removeFromUser($uid);
                    redirectWith(t('«%s» queda sin plan: no podrá subir archivos nuevos (lo ya subido y la vigencia no cambian).', (string) $user['company']), $back);
                case 'user_days':
                    // Sumar o restar días de vigencia (afecta a todos sus equipos)
                    $days = (int) param($_POST, 'days');
                    if ($days < 1 || $days > UserRepository::MAX_DAYS) {
                        redirectWith(t('Indica entre 1 y') . ' ' . UserRepository::MAX_DAYS . ' ' . t('días.'), $back);
                    }
                    $sub = param($_POST, 'op') === 'sub';
                    $new = UserRepository::addContentDays($uid, $sub ? -$days : $days);
                    if ($new === null) {
                        redirectWith(t('«%s» no tiene vigencia de la que restar días.', (string) $user['company']), $back);
                    }
                    $active = UserRepository::contentActive($new);
                    redirectWith(($sub ? t('Restados %s día(s) a «%s».', (string) $days, (string) $user['company']) : t('Sumados %s día(s) a «%s».', (string) $days, (string) $user['company'])) . ' '
                        . ($active
                            ? t('Vigente hasta el') . ' ' . gmdate('d/m/Y H:i', (int) strtotime($new . ' UTC')) . ' UTC.'
                            : t('La vigencia quedó VENCIDA: sus equipos dejan de mostrar el contenido.'))
                        . ' ' . t('Se aplica a todos sus equipos en su próxima consulta.'), $back);
                case 'user_clear_content':
                    UserRepository::clearContent($uid);
                    redirectWith(t('Vigencia de «%s» quitada: sus equipos dejan de mostrar el contenido.', (string) $user['company']), $back);
                case 'user_revoke_all':
                    $n = DeviceRepository::revokeAllForUser($uid);
                    redirectWith(t('Revocados %s equipo(s) de «%s». ', (string) $n, (string) $user['company'])
                        . t('Lo detectarán en su próxima consulta.'), $back);
                case 'user_authorize_all':
                    // Todos los equipos del usuario a "authorized" (los ya autorizados no cuentan)
                    // (los que se autorizarían por PRIMERA vez sin perfil se saltan)
                    $n = DeviceRepository::authorizeAllForUser($uid);
                    $skipped = DeviceRepository::countNeedingProfile($uid);
                    redirectWith(t('Autorizados %s equipo(s) de «%s». ', (string) $n, (string) $user['company'])
                        . t('Lo detectarán en su próxima consulta.')
                        . ($skipped ? t(' %s equipo(s) sin perfil no se autorizaron: asígnales uno primero.', (string) $skipped) : ''), $back);
                case 'user_leagues':
                    // Ligas del usuario (casillas de su ficha); llegan a sus TV autorizadas
                    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['leagues'] ?? [])), static fn($v) => $v > 0));
                    LeagueRepository::setForUser($uid, $ids);
                    $ids = LeagueRepository::idsForUser($uid); // las que existen de verdad
                    redirectWith(($ids ? t('Ligas de «') . $user['company'] . t('» guardadas (') . count($ids) . ').'
                            : '«' . $user['company'] . t('» queda sin ligas: sus equipos quedan sin liga.'))
                        . ' ' . t('Sus equipos lo recibirán en su próxima consulta.'), $back);
                case 'user_delete':
                    UserRepository::delete($uid);
                    redirectWith(t('Usuario «%s» eliminado; sus equipos quedan sin asignar con el estado que tenían.', (string) $user['company']), 'view=users');
                default:
                    redirectWith(t('Acción desconocida.'), 'view=users');
            }
        } else {
            $id = strtoupper(trim(param($_POST, 'deviceId')));
            $back = param($_POST, 'back');
            if (!DeviceRepository::isValidId($id)) {
                redirectWith(t('ID de dispositivo inválido.'), $back);
            }
            switch ($action) {
                case 'authorize':
                    // La PRIMERA autorización exige que el equipo tenga PERFIL
                    $device = DeviceRepository::find($id);
                    if ($device !== null && DeviceRepository::needsProfile($device)) {
                        redirectWith(t('%s no se puede autorizar por primera vez sin perfil: asígnale uno (marca, modelo, MAC del case y fecha de instalación).', (string) $id), $back);
                    }
                    DeviceRepository::setStatus($id, 'authorized');
                    redirectWith(t('%s autorizado. El equipo lo detectará en unos segundos.', (string) $id), $back);
                case 'revoke':
                    DeviceRepository::setStatus($id, 'revoked');
                    redirectWith("$id revocado.", $back);
                case 'pending':
                    DeviceRepository::setStatus($id, 'pending');
                    redirectWith(t('%s vuelve a pendiente.', (string) $id), $back);
                case 'label':
                    DeviceRepository::setLabel($id, trim(param($_POST, 'label')));
                    redirectWith(t('Alias de %s guardado (también lo ve el usuario).', (string) $id), $back);
                case 'admin_info':
                    // Alias adicional + notas: SOLO para el administrador
                    DeviceRepository::setAdminInfo($id, param($_POST, 'adminAlias'), param($_POST, 'adminNotes'));
                    redirectWith(t('Alias y notas del administrador de %s guardados (el usuario no los ve).', (string) $id), $back);
                case 'media_enable':
                case 'media_disable':
                    // Contenido del equipo (2026-11-23, tarifas): activarlo cobra el primer día a su usuario
                    // (igual que si lo activa él en /cuenta/); desactivarlo no devuelve lo pagado
                    if ($action === 'media_disable') {
                        CreditRepository::deactivate($id);
                        redirectWith(t('Contenido deshabilitado en %s: dejará de mostrarlo en su próxima consulta.', $id), $back);
                    }
                    $dev = DeviceRepository::find($id);
                    if ($dev === null || empty($dev['user_id'])) {
                        redirectWith(t('%s no tiene usuario: asígnale uno antes de activar su contenido.', $id), $back);
                    }
                    $res = CreditRepository::activate((int) $dev['user_id'], $id);
                    if (is_string($res)) {
                        redirectWith($res, $back);
                    }
                    redirectWith(t('Contenido activado en %s.', $id) . ' ' . ($res['charged'] > 0
                        ? t('Se cobraron %s créditos a su usuario por el primer día (transacción %s).', TariffRepository::num($res['charged']), (string) $res['txn'])
                        : t('El día ya estaba pagado: no se cobró nada.')), $back);
                case 'set_user':
                    // "" = sin asignar
                    $raw = param($_POST, 'userId');
                    $ok = DeviceRepository::setUser($id, $raw === '' ? null : (int) $raw);
                    redirectWith($ok ? t('Usuario de %s cambiado.', (string) $id) : t('Ese usuario no existe.'), $back);
                case 'device_league':
                    // Liga del equipo: "Sin liga" o UNA de las ligas del USUARIO dueño
                    // del equipo, solo si ese usuario ya tiene ruta y ligas asignadas.
                    $device = DeviceRepository::find($id);
                    $owner = $device !== null && !empty($device['user_id']) ? UserRepository::find((int) $device['user_id']) : null;
                    if ($owner === null) {
                        redirectWith(t('%s no tiene usuario: asígnale uno antes de elegir su liga.', (string) $id), $back);
                    }
                    if (empty($owner['route_id']) || !LeagueRepository::idsForUser((int) $owner['id'])) {
                        redirectWith(t('«%s» todavía no tiene ruta y ligas asignadas.', (string) $owner['company']), $back);
                    }
                    $raw = param($_POST, 'leagueId');
                    $leagueId = ctype_digit($raw) && (int) $raw > 0 ? (int) $raw : null; // "" = Sin liga
                    // setDeviceLeague solo acepta ligas asignadas al usuario del equipo
                    if (!LeagueRepository::setDeviceLeague($id, $leagueId)) {
                        redirectWith(t('Esa liga no está asignada a «%s».', (string) $owner['company']), $back);
                    }
                    $league = $leagueId === null ? null : LeagueRepository::find($leagueId);
                    redirectWith(($league === null ? t('%s queda sin liga.', (string) $id) : t('%s usará la liga «%s».', (string) $id, (string) $league['name']))
                        . ' ' . t('La TV recibe su URL en la próxima consulta (≈ 1 min si está mostrando contenido).'), $back);
                case 'take_over':
                    // TV restablecida: el equipo NUEVO toma todo lo del ANTERIOR (el de mac_from)
                    $oldId = strtoupper(trim(param($_POST, 'oldId')));
                    if (!DeviceRepository::isValidId($oldId)) {
                        redirectWith(t('ID del equipo anterior inválido.'), $back);
                    }
                    $deleteOld = param($_POST, 'deleteOld') === '1';
                    $err = DeviceRepository::takeOver($id, $oldId, $deleteOld);
                    if ($err !== null) {
                        redirectWith($err, $back);
                    }
                    // Volver mostrando el equipo nuevo (si estaba en "Pendientes" puede haber cambiado de pestaña)
                    redirectWith(t('%s tiene ahora los datos de %s (estado, usuario, alias, notas, liga, contenido, perfil, anuncios y tiras)', (string) $id, (string) $oldId)
                        . ($deleteOld ? t(' y %s se eliminó.', (string) $oldId) : t('; el anterior sigue en la lista.'))
                        . ' ' . t('La TV lo recibe en su próxima consulta.'), http_build_query(['filter' => 'all', 'q' => $id]));
                case 'delete':
                    DeviceRepository::delete($id);
                    redirectWith(t('%s eliminado. Si el equipo vuelve a consultar, se registrará como pendiente.', (string) $id), $back);
                default:
                    redirectWith(t('Acción desconocida.'), $back);
            }
        }
    }

    /* -----------------------------------------------------------------
       Datos para la página (GET)
       ----------------------------------------------------------------- */
    $filters = ['pending' => t('Pendientes'), 'authorized' => t('Autorizados'), 'revoked' => t('Revocados'), 'all' => t('Todos')];
    $filter = param($_GET, 'filter') ?: 'pending';
    if (!isset($filters[$filter])) {
        $filter = 'pending';
    }
    $q = mb_substr(trim(param($_GET, 'q')), 0, 40);
    // Vista: lista de equipos (por defecto), usuarios (?view=users) o contenido (?view=media)
    $view = in_array(param($_GET, 'view'), ['users', 'media', 'ads', 'overlays', 'routes', 'leagues', 'profiles', 'messages', 'plans', 'tariffs'], true) ? param($_GET, 'view') : 'list';
    // Usuarios (para el filtro, el selector de cada equipo y la vista de usuarios)
    // Cobro diario pendiente de todos (tarifas y créditos, 2026-11-23): antes de leer los saldos
    if ($loggedIn) {
        CreditRepository::settleAll();
    }
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
    $userStates = ['all' => t('Todos'), 'credit' => t('Con saldo'), 'nocredit' => t('Sin saldo'), 'notariff' => t('Sin tarifa'),
        'temp' => t('Contraseña temporal'), 'nodevices' => t('Sin equipos')];
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
            // Saldo y tarifa (2026-11-23, sustituyen a la vigencia)
            $tags = ['all', (float) $u['credits'] > 0 ? 'credit' : 'nocredit'];
            if (empty($u['tariff_id'])) {
                $tags[] = 'notariff';
            }
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
    // TARIFAS Y CRÉDITOS (2026-11-23): cobro diario pendiente de todos (barato si no hay nada vencido),
    // la vista ?view=tariffs (con la búsqueda global de movimientos) y la ficha del usuario
    $tariffs = $loggedIn && ($view === 'tariffs' || $userCard) ? TariffRepository::all() : [];
    $planFilterC = planHistoryParams();
    $userCredits = $loggedIn && $userCard
        ? CreditRepository::search((int) $userCard['id'], $planFilterC['tq'], $planFilterC['tf'], $planFilterC['tt'], $planFilterC['tz'], $planFilterC['td']) : [];
    $allCredits = $loggedIn && $view === 'tariffs'
        ? CreditRepository::search(null, $planFilterC['tq'], $planFilterC['tf'], $planFilterC['tt'], $planFilterC['tz'], $planFilterC['td']) : [];
    // PLANES (2026-11-22): la vista ?view=plans y el selector de la ficha del usuario; su plan e historial
    $plans = $loggedIn && ($view === 'plans' || $userCard) ? PlanRepository::all() : [];
    $userPlan = $loggedIn && $userCard ? PlanRepository::forUser($userCard) : null;
    // Historial con búsqueda (?tq= ID de transacción, ?tf= / ?tt= fechas): de un usuario en su ficha,
    // de TODOS en la vista Planes (sección "Buscar transacciones")
    $planFilter = planHistoryParams();
    $userPlanHistory = $loggedIn && $userCard
        ? PlanRepository::searchHistory((int) $userCard['id'], $planFilter['tq'], $planFilter['tf'], $planFilter['tt'], $planFilter['tz']) : [];
    $allPlanHistory = $loggedIn && $view === 'plans'
        ? PlanRepository::searchHistory(null, $planFilter['tq'], $planFilter['tf'], $planFilter['tt'], $planFilter['tz']) : [];
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
        ? t('Problema de instalación:') . ' ' . $setupError
        : t('Error del servidor. Revisa el registro de errores de PHP en hPanel.')
          . (!empty($loggedIn) ? ' Detalle: ' . mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 300) : '');
    $devices = [];
    $counts = [];
    $flash = '';
    $newPassword = null;
    $filters = ['pending' => t('Pendientes'), 'authorized' => t('Autorizados'), 'revoked' => t('Revocados'), 'all' => t('Todos')];
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
    $tariffs = [];
    $userCredits = [];
    $allCredits = [];
    $planFilterC = ['tq' => '', 'tf' => '', 'tt' => '', 'tz' => 0, 'td' => ''];
    $plans = [];
    $userPlan = null;
    $userPlanHistory = [];
    $allPlanHistory = [];
    $planFilter = ['tq' => '', 'tf' => '', 'tt' => '', 'tz' => 0];
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

$statusText = ['pending' => t('Pendiente'), 'authorized' => t('Autorizado'), 'revoked' => t('Revocado')];
// Usuario cuyos equipos se están viendo (?user=ID)
$focusUser = $userFilter ? ($usersById[$userFilter] ?? null) : null;
?>
<!doctype html>
<html lang="<?= h(I18n::lang()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= t('Dispositivos - Smart Pizarra') ?></title>
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
    /* Créditos: importe y nota de la recarga */
    .days-form .amount-input { width: 110px; } .days-form .note-input { flex: 1 1 180px; min-width: 0; width: auto; }
    /* Recuadro "Misma TV que SP-…" (tomar los datos del equipo anterior) */
    .takeover { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 6px 0; padding: 8px 10px;
      border: 1px solid var(--ok, #2e7d32); border-radius: 8px; }
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
    /* Búsqueda del historial de planes (ID de transacción y fechas) */
    .history-filter { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin: 8px 0 12px; }
    .history-filter label { display: flex; flex-direction: column; gap: 2px; font-size: .85rem; color: var(--muted); flex: 1 1 150px; min-width: 0; }
    code.txn { font-size: .85rem; white-space: nowrap; }
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
  <header><h1><?= t('Smart Pizarra · Dispositivos') ?></h1><?= langSwitch() ?></header>
  <?php if ($flash): ?><div class="card flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="card error"><?= h($error) ?></div><?php endif; ?>
  <form class="card" method="post" autocomplete="on">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="login">
    <label for="user"><?= t('Usuario') ?></label>
    <input type="text" id="user" name="user" autocomplete="username" required>
    <label for="password"><?= t('Contraseña') ?></label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>
    <p><button class="primary" type="submit"><?= t('Entrar') ?></button></p>
  </form>
<?php else: ?>
  <header>
    <h1><?= t('Smart Pizarra · Dispositivos') ?></h1>
    <nav class="header-nav">
      <!-- Vistas: equipos / usuarios -->
      <a class="btn <?= $view === 'list' && !$historyDevice ? 'on' : '' ?>" href="./"><?= t('Dispositivos') ?></a>
      <a class="btn <?= $view === 'users' ? 'on' : '' ?>" href="?view=users"><?= t('Usuarios') ?></a>
      <a class="btn <?= $view === 'media' ? 'on' : '' ?>" href="?view=media"><?= t('Contenido') ?></a>
      <a class="btn <?= $view === 'ads' ? 'on' : '' ?>" href="?view=ads"><?= t('Anuncios') ?></a>
      <a class="btn <?= $view === 'overlays' ? 'on' : '' ?>" href="?view=overlays"><?= t('Tiras') ?></a>
      <a class="btn <?= $view === 'routes' ? 'on' : '' ?>" href="?view=routes"><?= t('Rutas') ?></a>
      <a class="btn <?= $view === 'tariffs' ? 'on' : '' ?>" href="?view=tariffs"><?= t('Tarifas') ?></a>
      <a class="btn <?= $view === 'leagues' ? 'on' : '' ?>" href="?view=leagues"><?= t('Ligas') ?></a>
      <a class="btn <?= $view === 'profiles' ? 'on' : '' ?>" href="?view=profiles"><?= t('Perfiles') ?></a>
      <!-- Buzón: número de mensajes de usuarios sin leer -->
      <a class="btn <?= $view === 'messages' ? 'on' : '' ?>" href="?view=messages"><?= t('Mensajes') ?><?= $unreadAdmin ? '<span class="unread">' . (int) $unreadAdmin . '</span>' : '' ?></a>
      <!-- Agente launcher de las TV (instalaciones remotas de APK): página propia agentes.php -->
      <a class="btn" href="agentes.php"><?= t('Agentes') ?></a>
      <?= langSwitch() ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="logout"><button type="submit"><?= t('Salir') ?></button></form>
    </nav>
  </header>
  <?php if ($flash): ?><div class="card flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="card error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($newPassword): ?>
    <!-- Contraseña temporal: solo se ve ahora (en la base se guarda cifrada) -->
    <div class="card flash">
      <?= t('Contraseña temporal de') ?> <strong><?= h($newPassword['company']) ?></strong> (<?= h($newPassword['email']) ?>):<br>
      <span class="secret"><?= h($newPassword['password']) ?></span><br>
      <span class="owner"><?= t('Cópiala ahora y dásela al usuario: no se volverá a mostrar. Entra en') ?>
        <a href="../cuenta/">https://smartpizarra.cloud/cuenta/</a> <?= t('con su email y deberá cambiarla al entrar.') ?></span>
    </div>
  <?php endif; ?>

  <!-- Buscador (oculto en Anuncios, Tiras, Rutas y Ligas: no aplica; se deja en la página porque el escáner lo usa) -->
  <form class="tools<?= in_array($view, ['ads', 'overlays', 'routes', 'leagues', 'profiles', 'messages', 'plans', 'tariffs'], true) ? ' hidden-tools' : '' ?>" method="get">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="<?= $view === 'users' ? t('Buscar usuario por empresa, email o teléfono') : t('Buscar por ID, alias, alias del admin, empresa, email, MAC, MAC del case o S/N') ?>" aria-label="<?= h(t('Buscar')) ?>">
    <!-- Filtro por usuario (se aplica al cambiarlo) -->
    <select name="user" aria-label="<?= h(t('Usuario')) ?>" data-autosubmit>
      <option value=""><?= t('Todos los usuarios') ?></option>
      <option value="none" <?= $userFilter === 0 ? 'selected' : '' ?>><?= t('Sin asignar') ?></option>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int) $u['id'] ?>" <?= $userFilter === (int) $u['id'] ? 'selected' : '' ?>><?= h(userText($u)) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($view === 'list' && isset($_GET['filter'])): ?><input type="hidden" name="filter" value="<?= h($filter) ?>"><?php endif; ?>
    <?php if ($view === 'media' || $view === 'users'): ?><input type="hidden" name="view" value="<?= h($view) ?>"><?php endif; ?>
    <?php if ($view === 'users' && $userState !== 'all'): ?><input type="hidden" name="us" value="<?= h($userState) ?>"><?php endif; ?>
    <button type="submit"><?= t('Buscar') ?></button>
    <button type="button" id="scanBtn" class="primary"><?= t('Escanear QR') ?></button>
  </form>

  <?php if ($view === 'users' && !$historyDevice && $userCard): $uid = (int) $userCard['id']; $ut = TariffRepository::forUser($userCard); ?>
    <!-- ================= FICHA DE UN USUARIO (?view=users&user=ID) =================
         Todo lo de un usuario, separado por secciones. Las acciones vuelven aquí ($userBack). -->
    <section class="card user-card-head">
      <div class="user-head">
        <span>
          <a class="btn back" href="<?= h(panelUrl(['view' => 'users'])) ?>"><?= t('&larr; Usuarios') ?></a>
          <span class="user-name"><?= h($userCard['company']) ?></span>
        </span>
        <span>
          <?php if ((int) $userCard['must_change_password'] === 1): ?><span class="badge pending"><?= t('Contraseña temporal') ?></span><?php endif; ?>
          <span class="badge <?= (float) $userCard['credits'] > 0 ? 'authorized' : 'revoked' ?>"><?= t('Saldo: %s', h(TariffRepository::num($userCard['credits']))) ?></span>
        </span>
      </div>
      <div class="meta">
        <?= h($userCard['email']) ?> <?= t('· Tel.') ?> <?= h($userCard['phone']) ?><br>
        <?= t('Creado el') ?> <?= dateTag($userCard['created_at']) ?>
        <?= t('· Último acceso a /cuenta/:') ?> <?= $userCard['last_login_at'] ? dateTag($userCard['last_login_at']) : 'nunca' ?>
      </div>
      <!-- Resumen en cifras -->
      <div class="stats">
        <div class="stat"><b><?= (int) $userCard['devices'] ?></b><span><?= t('Equipos') ?></span></div>
        <div class="stat"><b><?= (int) $userCard['authorized'] ?></b><span><?= t('Autorizados') ?></span></div>
        <div class="stat"><b><?= (int) $userCard['media'] ?>/<?= $ut ? (int) $ut['max_files'] : '—' ?></b><span><?= t('Archivos') ?></span></div>
        <div class="stat"><b><?= h(TariffRepository::num($userCard['credits'])) ?></b><span><?= t('Créditos') ?></span></div>
      </div>
    </section>

    <!-- Equipos: ver y autorizar/revocar todos -->
    <section class="card section">
      <h2><?= t('Equipos') ?></h2>
      <div class="meta"><?= (int) $userCard['devices'] ?> <?= t('equipo(s) asignado(s),') ?> <?= (int) $userCard['authorized'] ?> <?= t('autorizado(s). Para asignar un equipo, búscalo en Dispositivos y elige este usuario en su selector.') ?></div>
      <div class="actions">
        <a class="btn" href="<?= h(panelUrl(['user' => $uid])) ?>"><?= t('Ver sus equipos') ?></a>
        <form method="post" data-confirm="<?= h(t('¿Autorizar TODOS los equipos de «')) ?><?= h($userCard['company']) ?>» (<?= (int) $userCard['devices'] ?>)?"><?= csrfField() ?>
          <input type="hidden" name="action" value="user_authorize_all"><input type="hidden" name="userId" value="<?= $uid ?>">
          <input type="hidden" name="back" value="<?= h($userBack) ?>">
          <button class="ok" type="submit" <?= (int) $userCard['authorized'] === (int) $userCard['devices'] ? 'disabled' : '' ?>><?= t('Autorizar todos') ?></button></form>
        <form method="post" data-confirm="<?= h(t('¿Revocar la autorización de TODOS los equipos de «')) ?><?= h($userCard['company']) ?>» (<?= (int) $userCard['devices'] ?>)?"><?= csrfField() ?>
          <input type="hidden" name="action" value="user_revoke_all"><input type="hidden" name="userId" value="<?= $uid ?>">
          <input type="hidden" name="back" value="<?= h($userBack) ?>">
          <button class="bad" type="submit" <?= (int) $userCard['devices'] === 0 ? 'disabled' : '' ?>><?= t('Revocar todos') ?></button></form>
      </div>
    </section>

    <!-- TARIFA Y CRÉDITOS del usuario (2026-11-23; sustituye a Plan y Vigencia).
         El usuario activa el contenido de cada dispositivo en /cuenta/ y se le cobra por día
         (archivos que muestra × precio de su tarifa); sin saldo se desactiva solo. -->
    <section class="card section">
      <h2><?= t('Tarifa y créditos') ?></h2>
      <?= creditBlock($userCard, $userBack) ?>
      <?php if ($tariffs): ?>
        <form method="post" class="days-form"><?= csrfField() ?>
          <input type="hidden" name="action" value="user_tariff"><input type="hidden" name="userId" value="<?= $uid ?>">
          <input type="hidden" name="back" value="<?= h($userBack) ?>">
          <label for="tf<?= $uid ?>"><?= t('Tarifa') ?></label>
          <select id="tf<?= $uid ?>" name="tariffId" data-autosubmit>
            <option value=""><?= t('Sin tarifa') ?></option>
            <?php foreach ($tariffs as $tf): ?>
              <option value="<?= (int) $tf['id'] ?>" <?= $ut && (int) $ut['id'] === (int) $tf['id'] ? 'selected' : '' ?>>
                <?= h($tf['name'] . ' · ' . t('imagen %s · video %s', TariffRepository::num($tf['image_price']), TariffRepository::num($tf['video_price'])) . ' ' . $tf['currency']
                    . ' · ' . $tf['max_files'] . ' ' . t('archivos') . ' · ' . PlanRepository::storageText((int) $tf['storage_mb'])) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button type="submit"><?= t('Guardar') ?></button></noscript>
        </form>
      <?php else: ?>
        <div class="meta"><?= t('Todavía no hay tarifas: créalas en') ?> <a href="?view=tariffs"><?= t('Tarifas') ?></a>.</div>
      <?php endif; ?>
      <!-- Movimientos de créditos (lo mismo que ve el usuario en /cuenta/), con ID de transacción y búsqueda -->
      <?php $crFiltering = $planFilterC['tq'] !== '' || $planFilterC['tf'] !== '' || $planFilterC['tt'] !== '' || $planFilterC['td'] !== ''; ?>
      <details class="edit" id="historial" <?= $crFiltering ? 'open' : '' ?>>
        <summary><?= t('Movimientos de créditos (%d)', count($userCredits)) ?></summary>
        <?= planHistoryFilter(['view' => 'users', 'user' => (string) $uid], $planFilterC, 'historial', true) ?>
        <?php if (!$userCredits): ?>
          <p class="owner"><?= $crFiltering ? t('Ninguna transacción coincide con la búsqueda.') : t('Todavía no hay movimientos.') ?></p>
        <?php else: ?>
          <?= creditHistoryTable($userCredits, false, 'boots') ?>
        <?php endif; ?>
      </details>
      <div class="actions">
        <a class="btn" href="<?= h(panelUrl(['view' => 'media', 'user' => $uid])) ?>"><?= t('Ver su contenido') ?></a>
      </div>
    </section>

    <!-- Ruta del usuario (una): todos sus equipos la reciben; se gestiona en Rutas -->
    <section class="card section">
      <h2><?= t('Ruta') ?></h2>
      <div class="meta">
        <?php if (!empty($userCard['route_id']) && isset($routeNames[(int) $userCard['route_id']])): ?>
          <strong><?= h($routeNames[(int) $userCard['route_id']]) ?></strong><?= t(': sus equipos reciben esta URL (con la liga de cada uno).') ?>
        <?php else: ?>
          <?= t('Sin ruta.') ?>
        <?php endif; ?>
        <a href="?view=routes"><?= t('Gestión de Rutas') ?></a>
      </div>
    </section>

    <!-- Ligas asignadas (varias): sus TV autorizadas las reciben como opciones del selector de ligas -->
    <section class="card section">
      <h2><?= t('Ligas') ?></h2>
      <?php if (!$leagues): ?>
        <div class="meta"><?= t('Todavía no hay ligas. Créalas en') ?> <a href="?view=leagues"><?= t('Ligas') ?></a>.</div>
      <?php else: ?>
        <div class="meta"><?= count($userLeagueIds) ?> <?= t('liga(s) asignada(s). El usuario elige en /cuenta/ cuál de ellas usa cada uno de sus equipos (o "Sin liga"). Si le quitas una liga, sus equipos que la usaban quedan sin liga.') ?></div>
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
          <div class="actions"><button class="primary" type="submit"><?= t('Guardar ligas') ?></button></div>
        </form>
      <?php endif; ?>
    </section>

    <!-- Datos de contacto (editar) -->
    <section class="card section">
      <h2><?= t('Datos') ?></h2>
      <form method="post" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="user_update"><input type="hidden" name="userId" value="<?= $uid ?>">
        <input type="hidden" name="back" value="<?= h($userBack) ?>">
        <div class="user-grid">
          <div><label for="c<?= $uid ?>"><?= t('Empresa') ?></label>
            <input type="text" id="c<?= $uid ?>" name="company" value="<?= h($userCard['company']) ?>" maxlength="100" required></div>
          <div><label for="e<?= $uid ?>">Email</label>
            <input type="email" id="e<?= $uid ?>" name="email" value="<?= h($userCard['email']) ?>" maxlength="190" required></div>
          <div><label for="p<?= $uid ?>"><?= t('Teléfono') ?></label>
            <input type="tel" id="p<?= $uid ?>" name="phone" value="<?= h($userCard['phone']) ?>" maxlength="30" required></div>
        </div>
        <button type="submit"><?= t('Guardar datos') ?></button>
      </form>
    </section>

    <!-- Acceso a /cuenta/ -->
    <section class="card section">
      <h2><?= t('Acceso') ?></h2>
      <div class="meta"><?= (int) $userCard['must_change_password'] === 1
          ? t('Todavía tiene la contraseña temporal: deberá cambiarla al entrar.')
          : t('Contraseña cambiada por el usuario.') ?></div>
      <form method="post" data-confirm="<?= h(t('¿Generar una contraseña nueva para «')) ?><?= h($userCard['company']) ?><?= h(t('»? La actual dejará de funcionar.')) ?>"><?= csrfField() ?>
        <input type="hidden" name="action" value="user_reset"><input type="hidden" name="userId" value="<?= $uid ?>">
        <input type="hidden" name="back" value="<?= h($userBack) ?>">
        <button type="submit"><?= t('Restablecer contraseña') ?></button></form>
    </section>

    <!-- Eliminar (separado para no pulsarlo por error) -->
    <section class="card section danger">
      <h2><?= t('Eliminar usuario') ?></h2>
      <div class="meta"><?= t('Sus') ?> <?= (int) $userCard['devices'] ?> <?= t('equipo(s) quedarán sin asignar CONSERVANDO su autorización; sus archivos se borran.') ?></div>
      <form method="post" data-confirm="<?= h(t('¿Eliminar el usuario «')) ?><?= h($userCard['company']) ?><?= h(t('»? Sus')) ?> <?= (int) $userCard['devices'] ?> <?= h(t('equipo(s) quedarán sin asignar CONSERVANDO su autorización.')) ?>"><?= csrfField() ?>
        <input type="hidden" name="action" value="user_delete"><input type="hidden" name="userId" value="<?= $uid ?>">
        <button class="bad" type="submit"><?= t('Eliminar usuario') ?></button></form>
    </section>

  <?php elseif ($view === 'users' && !$historyDevice): ?>
    <!-- ================= LISTA DE USUARIOS =================
         Una fila por usuario (resumen); todo lo demás está en su ficha.
         Datos requeridos al crear: email (para entrar en /cuenta/), teléfono y
         empresa. La contraseña la genera el sistema y la cambia el usuario. -->
    <details class="card new-user" <?= !$users ? 'open' : '' ?>>
      <summary><strong><?= t('+ Nuevo usuario') ?></strong></summary>
      <form method="post" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="user_create">
        <div class="user-grid">
          <div><label for="nu-company"><?= t('Empresa') ?></label>
            <input type="text" id="nu-company" name="company" maxlength="100" required></div>
          <div><label for="nu-email">Email</label>
            <input type="email" id="nu-email" name="email" maxlength="190" required></div>
          <div><label for="nu-phone"><?= t('Teléfono') ?></label>
            <input type="tel" id="nu-phone" name="phone" maxlength="30" required placeholder="+52 55 1234 5678"></div>
        </div>
        <button class="primary" type="submit"><?= t('Crear usuario') ?></button>
        <span class="owner"><?= t('Se generará una contraseña temporal para entregarle.') ?></span>
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
      <div class="card empty"><?= !$users ? t('Todavía no hay usuarios.') : ($q !== '' ? t('Ningún usuario coincide con «') . h($q) . '».' : t('No hay usuarios en esta lista.')) ?></div>
    <?php else: ?>
      <div class="card user-list">
        <?php foreach ($userList as $u): $uid = (int) $u['id']; ?>
          <!-- Fila: toda ella abre la ficha del usuario -->
          <a class="user-row" href="<?= h(panelUrl(['view' => 'users', 'user' => $uid])) ?>">
            <span class="user-main">
              <span class="user-name"><?= h($u['company']) ?></span>
              <span class="owner"><?= h($u['email']) ?> · <?= h($u['phone']) ?></span>
            </span>
            <span class="user-facts">
              <span class="badge <?= (float) $u['credits'] > 0 ? 'authorized' : 'revoked' ?>"><?= t('Saldo: %s', h(TariffRepository::num($u['credits']))) ?></span>
              <?php if ((int) $u['must_change_password'] === 1): ?><span class="badge pending"><?= t('Contraseña temporal') ?></span><?php endif; ?>
              <span class="owner"><?= (int) $u['devices'] ?> <?= t('equipo(s) ·') ?> <?= (int) $u['authorized'] ?> <?= t('aut. ·') ?> <?= (int) $u['media'] ?> <?= t('archivo(s) ·') ?> <?= !empty($u['tariff_name']) ? t('tarifa %s', h($u['tariff_name'])) : t('sin tarifa') ?></span>
            </span>
            <span class="chev" aria-hidden="true"><?= t('&rsaquo;') ?></span>
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
      <summary><strong><?= t('+ Nuevo anuncio') ?></strong></summary>
      <form method="post" enctype="multipart/form-data" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="ad_upload">
        <label for="ad-file"><?= t('Imagen o video') ?></label>
        <input type="file" id="ad-file" name="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" required>
        <span class="owner"><?= t('JPG, PNG, WEBP, GIF, MP4 (H.264, máx. 30 fps y 1920×1080) o WEBM · máx.') ?> <?= h(formatBytes(MediaRepository::maxBytes())) ?>
          <?= t('(límite de PHP:') ?> <?= h((string) ini_get('upload_max_filesize')) ?>)</span>
        <?= adFields(null, $users, $allDevices) ?>
        <button class="primary" type="submit"><?= t('Subir anuncio') ?></button>
        <span class="owner"><?= t('Las fechas usan la hora de este navegador.') ?></span>
      </form>
    </details>

    <?php if (!$ads): ?><div class="card empty"><?= t('Todavía no hay anuncios.') ?></div><?php else: ?>
      <!-- Rotación en orden (mirror.html): los anuncios salen de uno en uno en ESTE orden -->
      <p class="owner"><?= t('Las pantallas muestran los anuncios') ?> <strong><?= t('de uno en uno en este orden') ?></strong><?= t('; antes de cada uno pasan sus «cada N» archivos del usuario (p. ej. A cada 2 y B cada 3: 2 archivos, A, 3 archivos, B, y vuelta a empezar). Usa las flechas para cambiar el orden: las pantallas lo aplican en su próxima consulta (≈ 1 min). Los pausados, finalizados o que no van a un equipo no ocupan su turno.') ?></p>
    <?php endif; ?>
    <?php
      $adStateText = ['active' => t('En curso'), 'paused' => t('Pausado'), 'scheduled' => t('Programado'), 'ended' => t('Finalizado')];
      $adStateBadge = ['active' => 'authorized', 'paused' => 'off', 'scheduled' => 'pending', 'ended' => 'revoked'];
      foreach ($ads as $n => $a): $aid = (int) $a['id']; $isImg = $a['kind'] === 'image'; $st = AdRepository::state($a);
    ?>
      <section class="card device">
        <div class="media">
          <?php if ($isImg): ?>
            <a href="?ad=<?= $aid ?>" target="_blank" rel="noopener"><img class="thumb" src="?adthumb=<?= $aid ?>" alt="" loading="lazy"></a>
          <?php else: ?>
            <a class="thumb" href="?ad=<?= $aid ?>" target="_blank" rel="noopener"><?= t('&#9654; Video') ?></a>
          <?php endif; ?>
          <div class="media-body">
            <div class="user-head">
              <span class="media-name"><span class="pos"><?= $n + 1 ?></span><?= h($a['name']) ?></span>
              <span class="badge <?= $adStateBadge[$st] ?>"><?= $adStateText[$st] ?></span>
            </div>
            <div class="meta">
              <?= h(adTargetText($a, $usersById)) ?><br>
              <?= t('Cada') ?> <?= (int) $a['every_n'] ?> <?= t('archivo(s) del usuario ·') ?> <?= $isImg ? (int) $a['seconds'] . ' ' . t('s en pantalla') : 'Video' ?>
              · <?= h(formatBytes((int) $a['size_bytes'])) ?><br>
              <?= $a['starts_at'] ? t('Desde') . ' ' . dateTag($a['starts_at']) : t('Desde ya') ?>
              · <?= $a['ends_at'] ? t('hasta') . ' ' . dateTag($a['ends_at']) : t('sin fecha de fin') ?>
              · <span class="owner"><?= h($a['original_name']) ?></span>
            </div>
            <div class="actions">
              <form method="post"><?= csrfField() ?><input type="hidden" name="adId" value="<?= $aid ?>">
                <input type="hidden" name="action" value="<?= (int) $a['active'] === 1 ? 'ad_pause' : 'ad_resume' ?>">
                <button class="<?= (int) $a['active'] === 1 ? 'bad' : 'ok' ?>" type="submit"><?= (int) $a['active'] === 1 ? t('Pausar') : t('Activar') ?></button></form>
              <form method="post"><?= csrfField() ?><input type="hidden" name="adId" value="<?= $aid ?>">
                <input type="hidden" name="action" value="ad_up"><button type="submit" <?= $n === 0 ? 'disabled' : '' ?> aria-label="<?= h(t('Subir')) ?>">&#9650;</button></form>
              <form method="post"><?= csrfField() ?><input type="hidden" name="adId" value="<?= $aid ?>">
                <input type="hidden" name="action" value="ad_down"><button type="submit" <?= $n === count($ads) - 1 ? 'disabled' : '' ?> aria-label="<?= h(t('Bajar')) ?>">&#9660;</button></form>
              <a class="btn" href="?ad=<?= $aid ?>&amp;dl=1"><?= t('Descargar') ?></a>
              <form method="post" data-confirm="<?= h(t('¿Eliminar el anuncio «')) ?><?= h($a['name']) ?><?= h(t('»? Las pantallas dejarán de mostrarlo.')) ?>"><?= csrfField() ?>
                <input type="hidden" name="adId" value="<?= $aid ?>"><input type="hidden" name="action" value="ad_delete">
                <button type="submit"><?= t('Eliminar') ?></button></form>
            </div>
            <!-- Editar (sin cambiar el archivo) -->
            <details class="edit">
              <summary><?= t('Editar destino, frecuencia y fechas') ?></summary>
              <form method="post" autocomplete="off"><?= csrfField() ?>
                <input type="hidden" name="action" value="ad_update"><input type="hidden" name="adId" value="<?= $aid ?>">
                <?= adFields($a, $users, $allDevices) ?>
                <button class="primary" type="submit"><?= t('Guardar cambios') ?></button>
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
      <summary><strong><?= t('+ Nueva tira o banner') ?></strong></summary>
      <form method="post" enctype="multipart/form-data" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="ov_create">
        <?= overlayFields(null, 'ovnew-') ?>
        <?= overlayAdminFields(null) ?>
        <p class="owner"><?= t('Se crea') ?> <strong><?= t('sin equipos') ?></strong><?= t(': después, en la lista, usa «Asignar a equipos» para elegir dónde y durante cuánto tiempo (permanente o con fechas) se muestra.') ?></p>
        <button class="primary" type="submit"><?= t('Crear') ?></button>
      </form>
    </details>

    <?php foreach ([['Del administrador', $ovAdmin, t('Todavía no hay tiras ni banners del administrador.')],
                    [t('De los usuarios (solo pausar o eliminar)'), $ovUsers, t('Ningún usuario ha creado tiras ni banners.')]] as [$title, $list, $emptyText]): ?>
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
              <button class="<?= (int) $o['active'] === 1 ? 'bad' : 'ok' ?>" type="submit"><?= (int) $o['active'] === 1 ? t('Pausar') : t('Activar') ?></button></form>
            <form method="post" data-confirm="<?= h(t('¿Eliminar «')) ?><?= h($o['name']) ?><?= h(t('»? Las pantallas dejarán de mostrarlo.')) ?>"><?= csrfField() ?>
              <input type="hidden" name="overlayId" value="<?= $oid ?>"><input type="hidden" name="action" value="ov_delete">
              <button type="submit"><?= t('Eliminar') ?></button></form>
          </div>
          <?php if ($o['user_id'] === null): ?>
            <!-- Asignación a equipos (aparte de la edición) -->
            <details class="edit" <?= overlayAssigned($o) ? '' : 'open' ?>>
              <summary><?= t('Asignar a equipos') ?></summary>
              <form method="post"><?= csrfField() ?>
                <input type="hidden" name="action" value="ov_assign"><input type="hidden" name="overlayId" value="<?= $oid ?>">
                <?= overlayAdminTargets($o, $users, $allDevices) ?>
                <?= overlayAdminWhen($o) ?>
                <button class="primary" type="submit"><?= t('Guardar asignación') ?></button>
              </form>
            </details>
            <details class="edit">
              <summary><?= t('Editar') ?></summary>
              <form method="post" autocomplete="off"><?= csrfField() ?>
                <input type="hidden" name="action" value="ov_update"><input type="hidden" name="overlayId" value="<?= $oid ?>">
                <?= overlayFields($o, 'o' . $oid . '-') ?>
                <?= overlayAdminFields($o) ?>
                <button class="primary" type="submit"><?= t('Guardar cambios') ?></button>
              </form>
            </details>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    <?php endforeach; ?>

  <?php elseif ($view === 'messages' && !$historyDevice): ?>
    <!-- ================= BUZÓN DE MENSAJES =================
         Una conversación por usuario. Abrir una marca como leídos sus mensajes. -->
    <h2 class="ov-list-title"><?= t('Mensajes') ?></h2>
    <?php if ($msgUser !== null): $mid = (int) $msgUser['id']; ?>
      <section class="card">
        <div class="user-head">
          <span class="media-name"><?= h($msgUser['company']) ?> <span class="owner">(<?= h($msgUser['email']) ?>)</span></span>
          <a class="btn" href="?view=messages"><?= t('&larr; Todas las conversaciones') ?></a>
        </div>
        <?= messageThread($thread, 'admin', (string) $msgUser['company']) ?>
        <form method="post" class="msg-form" autocomplete="off"><?= csrfField() ?>
          <input type="hidden" name="action" value="msg_send"><input type="hidden" name="userId" value="<?= $mid ?>">
          <?= messageField('msg-body', 'Escribe a ' . $msgUser['company'] . '…') ?>
          <div class="row">
            <button class="primary" type="submit"><?= t('Enviar') ?></button>
            <a class="btn" href="?view=users&amp;user=<?= $mid ?>"><?= t('Ficha del usuario') ?></a>
          </div>
        </form>
        <?php if ($thread): ?>
          <form method="post" class="actions" data-confirm="<?= h(t('¿Borrar TODA la conversación con «')) ?><?= h($msgUser['company']) ?><?= h(t('»? No se puede deshacer.')) ?>"><?= csrfField() ?>
            <input type="hidden" name="action" value="msg_delete"><input type="hidden" name="userId" value="<?= $mid ?>">
            <button type="submit"><?= t('Borrar conversación') ?></button></form>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <details class="card new-user" <?= !$conversations ? 'open' : '' ?>>
        <summary><strong><?= t('+ Nuevo mensaje') ?></strong></summary>
        <form method="post" class="msg-form" autocomplete="off"><?= csrfField() ?>
          <input type="hidden" name="action" value="msg_send">
          <label for="msg-to"><?= t('Para') ?></label>
          <select id="msg-to" name="userId" required>
            <option value=""><?= t('Elegir usuario…') ?></option>
            <option value="all"><?= t('Todos los usuarios (') ?><?= count($users) ?>)</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int) $u['id'] ?>"><?= h(userText($u)) ?></option>
            <?php endforeach; ?>
          </select>
          <?= messageField('msg-new') ?>
          <div class="row"><button class="primary" type="submit"><?= t('Enviar') ?></button>
            <span class="owner"><?= t('"Todos los usuarios" deja el mensaje en la conversación de cada uno.') ?></span></div>
        </form>
      </details>
      <?php if (!$conversations): ?><div class="card empty"><?= t('Todavía no hay conversaciones.') ?></div><?php endif; ?>
      <?php foreach ($conversations as $c): ?>
        <a class="card conv <?= $c['unread'] ? 'conv-new' : '' ?>" href="?view=messages&amp;mu=<?= (int) $c['id'] ?>">
          <span class="conv-head"><strong><?= h($c['company']) ?></strong>
            <?= $c['unread'] ? '<span class="unread">' . (int) $c['unread'] . '</span>' : '' ?>
            <span class="owner"><?= dateTag((string) $c['last_at']) ?></span></span>
          <span class="owner conv-last"><?= $c['last_sender'] === 'admin' ? t('Tú:') . ' ' : '' ?><?= h(mb_strimwidth(str_replace("\n", ' ', (string) $c['last_body']), 0, 120, '…')) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php elseif ($view === 'profiles' && !$historyDevice):
      // Campos de un perfil (crear o editar)
      $profileFields = static function (?array $pr, string $px): string {
          $v = $pr ?? ['brand' => '', 'model' => '', 'case_mac' => '', 'serial' => '', 'installed_on' => gmdate('Y-m-d')];
          return '<div class="user-grid">'
              . '<div><label for="' . $px . 'brand">Marca</label><input type="text" id="' . $px . 'brand" name="brand" maxlength="60" required value="' . h((string) $v['brand']) . '" placeholder="' . h(t('p. ej. Hisilicon')) . '"></div>'
              . '<div><label for="' . $px . 'model">Modelo</label><input type="text" id="' . $px . 'model" name="model" maxlength="60" required value="' . h((string) $v['model']) . '" placeholder="' . h(t('p. ej. TVBOX Hi3798')) . '"></div>'
              // MAC Case: data-mac => el JS del pie pone los ":" solo (escrita a mano o con lector de
              // código de barras, con o sin separadores), pasa a mayúsculas y exige 12 caracteres hex
              . '<div><label for="' . $px . 'mac">MAC Case</label><input type="text" id="' . $px . 'mac" name="case_mac" maxlength="17" required data-mac value="' . h((string) $v['case_mac']) . '" placeholder="E8:BB:3E:C8:FB:5A" autocapitalize="characters" autocomplete="off" spellcheck="false" inputmode="text">'
              . '<small class="owner" data-mac-hint>' . t('12 caracteres (0-9, A-F); los «:» se ponen solos') . '</small></div>'
              // S/N de la caja (opcional, único; también se busca por él)
              . '<div><label for="' . $px . 'sn">S/N (opcional)</label><input type="text" id="' . $px . 'sn" name="serial" maxlength="60" data-upper value="' . h((string) ($v['serial'] ?? '')) . '" placeholder="' . h(t('Número de serie de la caja')) . '" autocapitalize="characters" spellcheck="false"></div>'
              . '<div><label for="' . $px . 'date">' . t('Fecha de instalación') . '</label><input type="date" id="' . $px . 'date" name="installed_on" required value="' . h((string) $v['installed_on']) . '"></div>'
              . '</div>';
      };
      // Selector de equipo ("" = sin equipo)
      $deviceSelect = static function (string $px, ?string $current) use ($profileDevices): string {
          $o = '<label for="' . $px . 'dev">' . t('Equipo') . '</label><select id="' . $px . 'dev" name="deviceId"><option value="">' . t('Sin equipo') . '</option>';
          foreach ($profileDevices as $pd) {
              $o .= '<option value="' . h($pd['device_id']) . '"' . ($current === $pd['device_id'] ? ' selected' : '') . '>'
                  . h($pd['device_id'] . ($pd['label'] ? ' · ' . $pd['label'] : '') . ($pd['user_company'] ? ' · ' . $pd['user_company'] : '')
                    . (!empty($pd['profile_id']) ? ' ' . t('(ya tiene perfil: se cambiará)') : '')) . '</option>';
          }
          return $o . '</select>';
      };
  ?>
    <!-- ================= PERFILES DE EQUIPO =================
         Ficha de la caja física: marca, modelo, MAC del case y fecha de
         instalación. Un perfil por equipo; la PRIMERA autorización la exige. -->
    <h2 class="ov-list-title"><?= t('Perfiles de equipo') ?></h2>
    <details class="card new-user" <?= !$profiles || $profileDevice !== '' ? 'open' : '' ?>>
      <summary><strong><?= t('+ Nuevo perfil') ?></strong></summary>
      <form method="post" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="profile_create">
        <input type="hidden" name="back" value="<?= h($profileDevice !== '' ? http_build_query(['filter' => 'all', 'q' => $profileDevice]) : 'view=profiles') ?>">
        <?= $profileFields(null, 'pnew-') ?>
        <?= $deviceSelect('pnew-', $profileDevice !== '' ? $profileDevice : null) ?>
        <p><button class="primary" type="submit"><?= t('Crear perfil') ?></button>
          <span class="owner"><?= t('Todos los campos son obligatorios salvo el S/N. Para autorizar un equipo por primera vez, debe tener perfil.') ?></span></p>
      </form>
    </details>
    <!-- Búsqueda de perfiles: marca, modelo, equipo, S/N o MAC del case (sin importar ":" o "-") -->
    <form class="profile-search" method="get">
      <input type="hidden" name="view" value="profiles">
      <input type="search" name="pq" value="<?= h($pq) ?>" placeholder="<?= h(t('Buscar por MAC, S/N, marca, modelo o equipo')) ?>" aria-label="<?= h(t('Buscar perfil')) ?>">
      <button type="submit"><?= t('Buscar') ?></button>
      <?php if ($pq !== ''): ?><a class="btn" href="?view=profiles"><?= t('Ver todos') ?></a><?php endif; ?>
    </form>
    <?php if (!$profiles): ?><div class="card empty"><?= $pq !== '' ? t('Ningún perfil coincide con «') . h($pq) . '».' : t('Todavía no hay perfiles.') ?></div><?php endif; ?>
    <?php foreach ($profiles as $pr): $prid = (int) $pr['id']; $pback = http_build_query(['view' => 'profiles', 'pq' => $pq]); ?>
      <section class="card device">
        <div class="user-head">
          <span class="media-name"><?= h($pr['brand'] . ' ' . $pr['model']) ?></span>
          <span class="badge <?= $pr['device_id'] ? 'authorized' : 'off' ?>"><?= $pr['device_id'] ? 'Asignado' : t('Sin equipo') ?></span>
        </div>
        <div class="meta">
          MAC: <span class="device-mac"><?= h($pr['case_mac']) ?></span>
          · S/N <?= !empty($pr['serial']) ? '<span class="device-mac">' . h($pr['serial']) . '</span>' : '<em>' . t('sin registrar') . '</em>' ?>
          <?= t('· Instalado el') ?> <?= h(date('d/m/Y', strtotime((string) $pr['installed_on']))) ?><br>
          <?php if ($pr['device_id']): ?>
            <?= t('Equipo:') ?> <a href="./?<?= h(http_build_query(['filter' => 'all', 'q' => $pr['device_id']])) ?>"><?= h($pr['device_id']) ?></a>
            <?= $pr['device_label'] ? '· ' . h($pr['device_label']) : '' ?>
            <?= $pr['user_company'] ? '· <span class="owner">' . h($pr['user_company']) . '</span>' : '' ?>
            <?= $pr['device_mac'] ? t('· MAC que envía la TV') . ' <span class="device-mac">' . h($pr['device_mac']) . '</span>' : '' ?>
          <?php else: ?>
            <em><?= t('Sin equipo asignado.') ?></em>
          <?php endif; ?>
        </div>
        <div class="actions">
          <form method="post" data-confirm="<?= h(t('¿Eliminar el perfil «')) ?><?= h(ProfileRepository::text($pr)) ?>»?<?= $pr['device_id'] ? ' ' . h($pr['device_id']) . ' ' . t('quedará sin perfil.') : '' ?>"><?= csrfField() ?>
            <input type="hidden" name="action" value="profile_delete"><input type="hidden" name="profileId" value="<?= $prid ?>">
            <input type="hidden" name="back" value="<?= h($pback) ?>">
            <button type="submit"><?= t('Eliminar') ?></button></form>
        </div>
        <details class="edit" <?= $pr['device_id'] ? '' : 'open' ?>>
          <summary><?= t('Asignar a un equipo') ?></summary>
          <form method="post"><?= csrfField() ?>
            <input type="hidden" name="action" value="profile_assign"><input type="hidden" name="profileId" value="<?= $prid ?>">
            <input type="hidden" name="back" value="<?= h($pback) ?>">
            <?= $deviceSelect('p' . $prid . '-', $pr['device_id']) ?>
            <span class="owner"><?= t('Un equipo tiene un solo perfil. Si la TV se restableció y volvió con otro ID, asígnale aquí el equipo nuevo.') ?></span><br>
            <button class="primary" type="submit"><?= t('Guardar equipo') ?></button>
          </form>
        </details>
        <details class="edit">
          <summary><?= t('Editar datos') ?></summary>
          <form method="post" autocomplete="off"><?= csrfField() ?>
            <input type="hidden" name="action" value="profile_update"><input type="hidden" name="profileId" value="<?= $prid ?>">
            <input type="hidden" name="back" value="<?= h($pback) ?>">
            <?= $profileFields($pr, 'p' . $prid . '-e') ?>
            <button class="primary" type="submit"><?= t('Guardar cambios') ?></button>
          </form>
        </details>
      </section>
    <?php endforeach; ?>

  <?php elseif ($view === 'tariffs' && !$historyDevice):
      // Campos de una tarifa (crear o editar); el almacenamiento se escribe en GB
      $tariffFields = static function (?array $tf, string $px): string {
          $v = $tf ?? ['name' => '', 'image_price' => '0.13', 'video_price' => '0.25', 'max_files' => 5, 'storage_mb' => 1024, 'currency' => 'USD', 'comment' => ''];
          return '<div class="user-grid">'
              . '<div><label for="' . $px . 'name">' . t('Nombre de la tarifa') . '</label><input type="text" id="' . $px . 'name" name="name" maxlength="60" required value="' . h((string) $v['name']) . '" placeholder="' . h(t('p. ej. Básica')) . '"></div>'
              . '<div><label for="' . $px . 'img">' . t('Precio por imagen (por dispositivo y día)') . '</label><input type="text" id="' . $px . 'img" name="image_price" inputmode="decimal" required value="' . h(TariffRepository::num($v['image_price'])) . '"></div>'
              . '<div><label for="' . $px . 'vid">' . t('Precio por video (por dispositivo y día)') . '</label><input type="text" id="' . $px . 'vid" name="video_price" inputmode="decimal" required value="' . h(TariffRepository::num($v['video_price'])) . '"></div>'
              . '<div><label for="' . $px . 'files">' . t('Cantidad de contenido (archivos)') . '</label><input type="number" id="' . $px . 'files" name="max_files" min="1" max="' . TariffRepository::MAX_FILES . '" required value="' . (int) $v['max_files'] . '"></div>'
              . '<div><label for="' . $px . 'gb">' . t('Almacenamiento (GB)') . '</label><input type="text" id="' . $px . 'gb" name="storage_gb" inputmode="decimal" required value="' . h(PlanRepository::storageGbValue((int) $v['storage_mb'])) . '"></div>'
              . '<div><label for="' . $px . 'cur">' . t('Moneda') . '</label><input type="text" id="' . $px . 'cur" name="currency" maxlength="3" required value="' . h((string) $v['currency']) . '" data-upper></div>'
              . '</div>'
              . '<label for="' . $px . 'cm">' . t('Comentario (opcional, lo ven los usuarios)') . '</label>'
              . '<textarea id="' . $px . 'cm" name="comment" rows="2" maxlength="500">' . h((string) ($v['comment'] ?? '')) . '</textarea>';
      };
  ?>
    <!-- ================= TARIFAS (2026-11-23; sustituyen a los planes) =================
         Precio en créditos por imagen y por video, por dispositivo y por día, más los límites.
         Se asignan en la ficha de cada usuario; el usuario las ve en /cuenta/ → Mis créditos. -->
    <h2 class="ov-list-title"><?= t('Tarifas') ?></h2>
    <details class="card new-user" <?= !$tariffs ? 'open' : '' ?>>
      <summary><strong><?= t('+ Nueva tarifa') ?></strong></summary>
      <form method="post" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="tariff_create">
        <?= $tariffFields(null, 'tfnew-') ?>
        <p><button class="primary" type="submit"><?= t('Crear tarifa') ?></button>
          <span class="owner"><?= t('Cada día se cobra, por cada dispositivo con el contenido activado, cada imagen y cada video que muestra.') ?></span></p>
      </form>
    </details>
    <?php if (!$tariffs): ?><div class="card empty"><?= t('Todavía no hay tarifas.') ?></div><?php endif; ?>
    <?php foreach ($tariffs as $tf): $tfid = (int) $tf['id']; ?>
      <section class="card device">
        <div class="user-head">
          <span class="media-name"><?= h($tf['name']) ?></span>
          <span class="badge <?= (int) $tf['users'] > 0 ? 'authorized' : 'off' ?>"><?= (int) $tf['users'] ?> <?= t('usuario(s)') ?></span>
        </div>
        <div class="meta">
          <?= t('imagen %s · video %s', h(TariffRepository::num($tf['image_price'])), h(TariffRepository::num($tf['video_price']))) ?> <?= h($tf['currency']) ?> <?= t('por dispositivo y día') ?>
          · <?= (int) $tf['max_files'] ?> <?= t('archivos') ?> · <?= h(PlanRepository::storageText((int) $tf['storage_mb'])) ?>
          <?php if (!empty($tf['comment'])): ?><div class="admin-notes"><?= nl2br(h((string) $tf['comment'])) ?></div><?php endif; ?>
        </div>
        <details class="edit">
          <summary><?= t('Editar') ?></summary>
          <form method="post" autocomplete="off"><?= csrfField() ?>
            <input type="hidden" name="action" value="tariff_update"><input type="hidden" name="tariffId" value="<?= $tfid ?>">
            <?= $tariffFields($tf, 'tf' . $tfid . '-') ?>
            <p><button class="primary" type="submit"><?= t('Guardar tarifa') ?></button>
              <span class="owner"><?= t('Los precios nuevos se aplican desde el próximo cobro diario.') ?></span></p>
          </form>
        </details>
        <div class="actions">
          <form method="post" data-confirm="<?= h(t('¿Eliminar la tarifa «%s»? Sus usuarios quedarán sin tarifa y su contenido se desactivará.', $tf['name'])) ?>"><?= csrfField() ?>
            <input type="hidden" name="action" value="tariff_delete"><input type="hidden" name="tariffId" value="<?= $tfid ?>">
            <button type="submit"><?= t('Eliminar') ?></button></form>
        </div>
      </section>
    <?php endforeach; ?>
    <!-- Movimientos de créditos de todos los usuarios (ID de transacción y fechas) -->
    <?php $crFiltering = $planFilterC['tq'] !== '' || $planFilterC['tf'] !== '' || $planFilterC['tt'] !== '' || $planFilterC['td'] !== ''; ?>
    <section class="card" id="historial">
      <h2><?= t('Buscar transacciones') ?></h2>
      <div class="meta"><?= t('Recargas, descuentos y cobros diarios de todos los usuarios, por ID de transacción o por fechas (máx. 500).') ?></div>
      <?= planHistoryFilter(['view' => 'tariffs'], $planFilterC, 'historial', true) ?>
      <?php if (!$allCredits): ?>
        <p class="owner"><?= $crFiltering ? t('Ninguna transacción coincide con la búsqueda.') : t('Todavía no hay transacciones.') ?></p>
      <?php else: ?>
        <?= creditHistoryTable($allCredits, true, 'boots') ?>
      <?php endif; ?>
    </section>

  <?php elseif ($view === 'plans' && !$historyDevice):
      // Campos de un plan (crear o editar); el almacenamiento se escribe en GB
      $planFields = static function (?array $pl, string $px): string {
          $v = $pl ?? ['name' => '', 'days' => 30, 'max_files' => 5, 'storage_mb' => 1024, 'price' => '10.00', 'currency' => 'USD', 'comment' => ''];
          return '<div class="user-grid">'
              . '<div><label for="' . $px . 'name">' . t('Nombre del plan') . '</label><input type="text" id="' . $px . 'name" name="name" maxlength="60" required value="' . h((string) $v['name']) . '" placeholder="' . h(t('p. ej. Lite')) . '"></div>'
              . '<div><label for="' . $px . 'days">' . t('Duración (días)') . '</label><input type="number" id="' . $px . 'days" name="days" min="1" max="' . PlanRepository::MAX_DAYS . '" required value="' . (int) $v['days'] . '"></div>'
              . '<div><label for="' . $px . 'files">' . t('Cantidad de contenido (archivos)') . '</label><input type="number" id="' . $px . 'files" name="max_files" min="1" max="' . PlanRepository::MAX_FILES . '" required value="' . (int) $v['max_files'] . '"></div>'
              . '<div><label for="' . $px . 'gb">Almacenamiento (GB)</label><input type="text" id="' . $px . 'gb" name="storage_gb" inputmode="decimal" required value="' . h(PlanRepository::storageGbValue((int) $v['storage_mb'])) . '" placeholder="1 o 0.5"></div>'
              . '<div><label for="' . $px . 'price">Costo</label><input type="text" id="' . $px . 'price" name="price" inputmode="decimal" required value="' . h(rtrim(rtrim((string) $v['price'], '0'), '.') ?: '0') . '" placeholder="10"></div>'
              . '<div><label for="' . $px . 'cur">Moneda</label><input type="text" id="' . $px . 'cur" name="currency" maxlength="3" required value="' . h((string) $v['currency']) . '" data-upper autocapitalize="characters"></div>'
              . '</div>'
              // Comentario (opcional): lo ven los usuarios en /cuenta/ → Mi plan, con todos los planes
              . '<label for="' . $px . 'cm">' . t('Comentario (opcional, lo ven los usuarios)') . '</label>'
              . '<textarea id="' . $px . 'cm" name="comment" rows="2" maxlength="500" placeholder="' . h(t('p. ej. Ideal para 1 pantalla con pocas promociones')) . '">' . h((string) ($v['comment'] ?? '')) . '</textarea>';
      };
  ?>
    <!-- ================= PLANES DE CONTENIDO (2026-11-22) =================
         Nombre, duración, cantidad de archivos, almacenamiento y costo. Se asignan y
         renuevan en la ficha de cada usuario; el usuario los ve en /cuenta/ → Mi plan. -->
    <h2 class="ov-list-title"><?= t('Planes') ?></h2>
    <details class="card new-user" <?= !$plans ? 'open' : '' ?>>
      <summary><strong><?= t('+ Nuevo plan') ?></strong></summary>
      <form method="post" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="plan_create">
        <?= $planFields(null, 'plnew-') ?>
        <p><button class="primary" type="submit"><?= t('Crear plan') ?></button>
          <span class="owner"><?= t('Asignar o renovar un plan suma sus días al plan y a la vigencia del contenido del usuario.') ?></span></p>
      </form>
    </details>
    <?php if (!$plans): ?><div class="card empty"><?= t('Todavía no hay planes.') ?></div><?php endif; ?>
    <?php foreach ($plans as $pl): $plid = (int) $pl['id']; ?>
      <section class="card device">
        <div class="user-head">
          <span class="media-name"><?= h($pl['name']) ?></span>
          <span class="badge <?= (int) $pl['users'] > 0 ? 'authorized' : 'off' ?>"><?= (int) $pl['users'] ?> <?= t('usuario(s)') ?></span>
        </div>
        <div class="meta">
          <?= (int) $pl['days'] ?> <?= t('días ·') ?> <?= (int) $pl['max_files'] ?> <?= t('archivos ·') ?> <?= h(PlanRepository::storageText((int) $pl['storage_mb'])) ?>
          · <strong><?= h(PlanRepository::priceText($pl)) ?></strong>
          <?php if (!empty($pl['comment'])): ?>
            <!-- Comentario del plan (lo ven los usuarios) -->
            <div class="admin-notes"><?= nl2br(h((string) $pl['comment'])) ?></div>
          <?php endif; ?>
        </div>
        <details class="edit">
          <summary><?= t('Editar') ?></summary>
          <form method="post" autocomplete="off"><?= csrfField() ?>
            <input type="hidden" name="action" value="plan_update"><input type="hidden" name="planId" value="<?= $plid ?>">
            <?= $planFields($pl, 'pl' . $plid . '-') ?>
            <p><button class="primary" type="submit"><?= t('Guardar plan') ?></button>
              <span class="owner"><?= t('Los límites nuevos valen ya para sus usuarios; el historial conserva los datos de cada renovación.') ?></span></p>
          </form>
        </details>
        <div class="actions">
          <form method="post" data-confirm="<?= h(t('¿Eliminar el plan «')) ?><?= h($pl['name']) ?>»?<?= (int) $pl['users'] > 0 ? ' ' . (int) $pl['users'] . ' ' . t('usuario(s) quedarán sin plan y no podrán subir archivos nuevos.') : '' ?>"><?= csrfField() ?>
            <input type="hidden" name="action" value="plan_delete"><input type="hidden" name="planId" value="<?= $plid ?>">
            <button type="submit"><?= t('Eliminar') ?></button></form>
        </div>
      </section>
    <?php endforeach; ?>
    <!-- BUSCAR TRANSACCIONES de todos los usuarios (ID de transacción y fechas), 2026-11-22 -->
    <?php $planFiltering = $planFilter['tq'] !== '' || $planFilter['tf'] !== '' || $planFilter['tt'] !== ''; ?>
    <section class="card" id="historial">
      <h2><?= t('Buscar transacciones') ?></h2>
      <div class="meta"><?= t('Asignaciones y renovaciones de todos los usuarios, por ID de transacción o por fechas (máx. 500).') ?></div>
      <?= planHistoryFilter(['view' => 'plans'], $planFilter) ?>
      <?php if (!$allPlanHistory): ?>
        <p class="owner"><?= $planFiltering ? t('Ninguna transacción coincide con la búsqueda.') : t('Todavía no hay transacciones.') ?></p>
      <?php else: ?>
        <p class="owner"><?= count($allPlanHistory) ?> <?= t('transacción(es)') ?><?= $planFiltering ? ' encontrada(s)' : '' ?>.</p>
        <?= planHistoryTable($allPlanHistory, true, 'boots') ?>
      <?php endif; ?>
    </section>

  <?php elseif ($view === 'routes' && !$historyDevice): ?>
    <!-- ================= GESTIÓN DE RUTAS =================
         URL de contenido construida por partes; se asigna a usuarios y la
         heredan todos sus equipos (leagues = liga de cada equipo). -->
    <h2 class="ov-list-title"><?= t('Gestión de Rutas') ?></h2>
    <details class="card new-user" <?= !$routes ? 'open' : '' ?>>
      <summary><strong><?= t('+ Nueva ruta') ?></strong></summary>
      <form method="post" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="route_create">
        <?= routeFields(null, 'rnew-') ?>
        <button class="primary" type="submit"><?= t('Crear ruta') ?></button>
      </form>
    </details>
    <?php if (!$routes): ?><div class="card empty"><?= t('Todavía no hay rutas.') ?></div><?php endif; ?>
    <?php foreach ($routes as $r): $rid = (int) $r['id']; ?>
      <section class="card device">
        <div class="user-head">
          <span class="media-name"><?= h($r['name']) ?></span>
          <span class="badge <?= $r['users'] ? 'authorized' : 'off' ?>"><?= count($r['users']) ?> <?= t('usuario(s)') ?></span>
        </div>
        <code class="route-url"><?= h(RouteRepository::buildUrl($r, '')) ?></code>
        <div class="meta">
          <?= $r['users'] ? t('Usuarios:') . ' ' . h(implode(', ', array_column($r['users'], 'company'))) : t('Sin usuarios: ningún equipo la recibe.') ?><br>
          <?= t('leagues = la liga de cada equipo (la elige el usuario en /cuenta/). Actualizada') ?> <?= dateTag($r['updated_at']) ?>
        </div>
        <div class="actions">
          <form method="post" data-confirm="<?= h(t('¿Eliminar la ruta «')) ?><?= h($r['name']) ?><?= h(t('»? Sus usuarios quedarán sin ruta.')) ?>"><?= csrfField() ?>
            <input type="hidden" name="action" value="route_delete"><input type="hidden" name="routeId" value="<?= $rid ?>">
            <button type="submit"><?= t('Eliminar') ?></button></form>
        </div>
        <details class="edit" <?= $r['users'] ? '' : 'open' ?>>
          <summary><?= t('Asignar a usuarios') ?></summary>
          <form method="post"><?= csrfField() ?>
            <input type="hidden" name="action" value="route_users"><input type="hidden" name="routeId" value="<?= $rid ?>">
            <?= routeUsersFields($r, $users, $routeNames) ?>
            <span class="owner"><?= t('Cada usuario tiene una sola ruta: si marcas uno que tiene otra, pasa a esta.') ?></span><br>
            <button class="primary" type="submit"><?= t('Guardar usuarios') ?></button>
          </form>
        </details>
        <details class="edit">
          <summary><?= t('Editar parámetros') ?></summary>
          <form method="post" autocomplete="off"><?= csrfField() ?>
            <input type="hidden" name="action" value="route_update"><input type="hidden" name="routeId" value="<?= $rid ?>">
            <?= routeFields($r, 'r' . $rid . '-') ?>
            <button class="primary" type="submit"><?= t('Guardar cambios') ?></button>
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
      <h2><?= t('Tipos de liga') ?></h2>
      <div class="meta"><?= t('El código es lo que guarda la TV como liga del equipo (minúsculas, p. ej.') ?> <code><?= t('mlb') ?></code><?= t(') y no se puede cambiar; el nombre es el que se ve en las listas. El orden es el de las listas.') ?></div>
      <?php if (!$leagueTypes): ?><p class="owner"><?= t('Todavía no hay tipos. Crea el primero (p. ej. código') ?> <code><?= t('mlb') ?></code><?= t(', nombre MLB).') ?></p><?php endif; ?>
      <?php foreach ($leagueTypes as $n => $t): $tid = (int) $t['id']; ?>
        <div class="type-row">
          <span class="pos"><?= $n + 1 ?></span>
          <code class="type-code"><?= h($t['code']) ?></code>
          <form method="post" class="type-rename"><?= csrfField() ?>
            <input type="hidden" name="action" value="league_type_rename"><input type="hidden" name="typeId" value="<?= $tid ?>">
            <label for="tn<?= $tid ?>" class="sr"><?= t('Nombre del tipo') ?></label>
            <input type="text" id="tn<?= $tid ?>" name="name" value="<?= h($t['name']) ?>" maxlength="60" required>
            <button type="submit"><?= t('Guardar') ?></button></form>
          <span class="owner"><?= (int) $t['leagues'] ?> <?= t('liga(s)') ?></span>
          <form method="post"><?= csrfField() ?><input type="hidden" name="typeId" value="<?= $tid ?>">
            <input type="hidden" name="action" value="league_type_up"><button type="submit" <?= $n === 0 ? 'disabled' : '' ?> aria-label="<?= h(t('Subir')) ?>">&#9650;</button></form>
          <form method="post"><?= csrfField() ?><input type="hidden" name="typeId" value="<?= $tid ?>">
            <input type="hidden" name="action" value="league_type_down"><button type="submit" <?= $n === count($leagueTypes) - 1 ? 'disabled' : '' ?> aria-label="<?= h(t('Bajar')) ?>">&#9660;</button></form>
          <form method="post" data-confirm="<?= h(t('¿Eliminar el tipo «')) ?><?= h($t['name']) ?>»?"><?= csrfField() ?>
            <input type="hidden" name="typeId" value="<?= $tid ?>"><input type="hidden" name="action" value="league_type_delete">
            <button type="submit" <?= (int) $t['leagues'] > 0 ? 'disabled title="' . h(t('Tiene ligas')) . '"' : '' ?>><?= t('Eliminar') ?></button></form>
        </div>
      <?php endforeach; ?>
      <!-- Nuevo tipo -->
      <form method="post" class="type-new" autocomplete="off"><?= csrfField() ?>
        <input type="hidden" name="action" value="league_type_create">
        <div class="user-grid">
          <div><label for="nt-code"><?= t('Código (minúsculas)') ?></label>
            <input type="text" id="nt-code" name="code" maxlength="20" required pattern="[a-zA-Z0-9_\-]{1,20}" placeholder="<?= h(t('mlb')) ?>"></div>
          <div><label for="nt-name"><?= t('Nombre (texto del botón)') ?></label>
            <input type="text" id="nt-name" name="name" maxlength="60" required placeholder="<?= h(t('MLB')) ?>"></div>
        </div>
        <button class="primary" type="submit"><?= t('+ Crear tipo') ?></button>
      </form>
    </section>

    <details class="card new-user" <?= $leagueTypes && !$leagues ? 'open' : '' ?>>
      <summary><strong><?= t('+ Nueva liga') ?></strong></summary>
      <?php if (!$leagueTypes): ?>
        <p class="owner"><?= t('Primero crea un tipo de liga.') ?></p>
      <?php else: ?>
        <form method="post" autocomplete="off"><?= csrfField() ?>
          <input type="hidden" name="action" value="league_create">
          <?= leagueFields(null, $leagueTypes, 'nl-') ?>
          <button class="primary" type="submit"><?= t('Crear liga') ?></button>
        </form>
      <?php endif; ?>
    </details>

    <?php if (!$leagues): ?><div class="card empty"><?= t('Todavía no hay ligas.') ?></div><?php endif; ?>
    <?php $lastType = null; foreach ($leagues as $l): $lid = (int) $l['id']; ?>
      <?php if ($lastType !== (int) $l['type_id']): $lastType = (int) $l['type_id']; ?>
        <h2 class="group-title"><?= h($l['type_name']) ?> <span class="owner">(<?= h($l['type_code']) ?>)</span></h2>
      <?php endif; ?>
      <section class="card device">
        <div class="user-head">
          <span class="user-name"><?= h($l['name']) ?></span>
          <span class="owner"><?= count(explode(',', (string) $l['codes'])) ?> <?= t('código(s)') ?></span>
        </div>
        <div class="codes"><?php foreach (explode(',', (string) $l['codes']) as $c): ?><span class="code-chip"><?= h($c) ?></span><?php endforeach; ?></div>
        <div class="meta">
          <?= t('Usuarios:') ?> <?php if (!$l['users']): ?><em><?= t('ninguno') ?></em> <?= t('(asígnala en la ficha de cada usuario)') ?><?php else: ?>
            <?php foreach ($l['users'] as $i => $lu): ?><?= $i ? ', ' : '' ?><a href="<?= h(panelUrl(['view' => 'users', 'user' => $lu['id']])) ?>"><?= h($lu['company']) ?></a><?php endforeach; ?>
          <?php endif; ?>
          <?= t('· Actualizada el') ?> <?= dateTag($l['updated_at']) ?>
        </div>
        <div class="actions">
          <form method="post" data-confirm="<?= h(t('¿Eliminar la liga «')) ?><?= h($l['name']) ?><?= h(t('»? Se quitará a')) ?> <?= count($l['users']) ?> <?= h(t('usuario(s).')) ?>"><?= csrfField() ?>
            <input type="hidden" name="leagueId" value="<?= $lid ?>"><input type="hidden" name="action" value="league_delete">
            <button type="submit"><?= t('Eliminar') ?></button></form>
        </div>
        <details class="edit">
          <summary><?= t('Editar nombre, tipo y contenido') ?></summary>
          <form method="post" autocomplete="off"><?= csrfField() ?>
            <input type="hidden" name="action" value="league_update"><input type="hidden" name="leagueId" value="<?= $lid ?>">
            <?= leagueFields($l, $leagueTypes, 'l' . $lid . '-') ?>
            <button class="primary" type="submit"><?= t('Guardar cambios') ?></button>
          </form>
        </details>
      </section>
    <?php endforeach; ?>

  <?php elseif ($view === 'media' && !$historyDevice): ?>
    <!-- ============ CONTENIDO SUBIDO POR LOS USUARIOS ============
         Solo ver, descargar o eliminar; ordenar/asignar lo hace su dueño. -->
    <section class="card focus-user">
      <div class="user-head">
        <span class="user-name"><?= t('Contenido de') ?> <?= $mediaUser !== null ? h($usersById[$mediaUser]['company'] ?? '') : t('todos los usuarios') ?></span>
        <span class="owner"><?= (int) $mediaTotals[0] ?> <?= t('archivo(s) ·') ?> <?= h(formatBytes((int) $mediaTotals[1])) ?></span>
      </div>
      <div class="meta"><?= t('Elige un usuario en el selector de arriba para ver solo su contenido. Al eliminar un archivo, las pantallas dejan de mostrarlo en su próxima consulta (≈ 1 min).') ?></div>
    </section>
    <?php if (!$mediaAll): ?><div class="card empty"><?= t('No hay archivos.') ?></div><?php endif; ?>
    <?php foreach ($mediaAll as $m): $mid = (int) $m['id']; $isImg = $m['kind'] === 'image'; ?>
      <section class="card device">
        <div class="media">
          <?php if ($isImg): ?>
            <a href="?file=<?= $mid ?>" target="_blank" rel="noopener"><img class="thumb" src="?thumb=<?= $mid ?>" alt="" loading="lazy"></a>
          <?php else: ?>
            <a class="thumb" href="?file=<?= $mid ?>" target="_blank" rel="noopener"><?= t('&#9654; Video') ?></a>
          <?php endif; ?>
          <div class="media-body">
            <div class="media-name"><?= h($m['original_name']) ?></div>
            <div class="meta">
              <?= t('Usuario:') ?> <a href="<?= h(panelUrl(['view' => 'media', 'user' => (int) $m['user_id']])) ?>"><?= h($m['user_company']) ?></a>
              <span class="owner">(<?= h($m['user_email']) ?>)</span><br>
              <?= $isImg ? 'Imagen · ' . (int) $m['seconds'] . ' ' . t('s en pantalla') : 'Video' ?>
              · <?= h(formatBytes((int) $m['size_bytes'])) ?> · <?= h($m['mime']) ?>
              <?= t('· Puesto') ?> <?= (int) $m['position'] ?><br>
              <?= t('Se muestra en:') ?> <?= (int) $m['all_devices'] === 1 ? t('todos sus equipos') : (int) $m['devices_count'] . ' ' . t('equipo(s) elegido(s)') ?>
              <?= t('· Subido el') ?> <?= dateTag($m['created_at']) ?><br>
              <!-- Periodo que eligió el usuario (permanente o con fechas) -->
              <?php $mState = MediaRepository::state($m); ?>
              <?php if (!MediaRepository::isActive($m)): ?><span class="badge off"><?= t('Pausado') ?></span><?php endif; ?>
              <span class="badge <?= ['permanent' => 'authorized', 'active' => 'authorized', 'scheduled' => 'pending', 'ended' => 'revoked'][$mState] ?>"><?=
                ['permanent' => t('Permanente'), 'active' => t('En curso'), 'scheduled' => t('Programado'), 'ended' => t('Finalizado')][$mState] ?></span>
              <?php if ($mState !== 'permanent'): ?>
                <?= $m['starts_at'] ? t('desde') . ' ' . dateTag($m['starts_at']) : t('desde ya') ?>
                · <?= $m['ends_at'] ? t('hasta') . ' ' . dateTag($m['ends_at']) : t('sin fecha de fin') ?>
              <?php endif; ?>
            </div>
            <div class="actions">
              <a class="btn" href="?file=<?= $mid ?>" target="_blank" rel="noopener"><?= t('Ver') ?></a>
              <a class="btn" href="?file=<?= $mid ?>&amp;dl=1"><?= t('Descargar') ?></a>
              <!-- Pausar / activar (como las tiras y banners) -->
              <form method="post"><?= csrfField() ?><input type="hidden" name="mediaId" value="<?= $mid ?>">
                <input type="hidden" name="action" value="<?= MediaRepository::isActive($m) ? 'media_pause' : 'media_resume' ?>">
                <input type="hidden" name="back" value="<?= h($mediaBack) ?>">
                <button class="<?= MediaRepository::isActive($m) ? 'bad' : 'ok' ?>" type="submit"><?= MediaRepository::isActive($m) ? t('Pausar') : t('Activar') ?></button></form>
              <form method="post" data-confirm="<?= h(t('¿Eliminar «')) ?><?= h($m['original_name']) ?><?= h(t('» de «')) ?><?= h($m['user_company']) ?><?= h(t('»? Se borra del servidor y sus pantallas dejarán de mostrarlo.')) ?>"><?= csrfField() ?>
                <input type="hidden" name="action" value="media_delete"><input type="hidden" name="mediaId" value="<?= $mid ?>">
                <input type="hidden" name="back" value="<?= h($mediaBack) ?>">
                <button class="bad" type="submit"><?= t('Eliminar') ?></button></form>
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
        <a class="btn" href="./?<?= h(http_build_query(['q' => $hid])) ?>"><?= t('Volver') ?></a>
      </div>
      <div class="meta">
        <?= t('Usuario:') ?> <?= $historyDevice['user_company'] ? h($historyDevice['user_company'] . ' — ' . $historyDevice['user_email']) : t('sin asignar') ?> <?= t('· Historial de arranques:') ?> <?= (int) $bootTotal ?> <?= t('en total') ?>
        <?= $bootTotal > count($boots) ? t('(se muestran los') . ' ' . count($boots) . ' ' . t('más recientes)') : '' ?>
        <?= t('· Registrado el') ?> <?= dateTag($historyDevice['created_at']) ?>
      </div>
      <?php if (!$boots): ?>
        <p class="empty"><?= t('Todavía no hay arranques registrados. Se anotan cada vez que el equipo abre la pantalla de carga.') ?></p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="boots">
            <thead><tr><th>#</th><th><?= t('Fecha y hora') ?></th><th><?= t('Hace') ?></th><th>IP</th><th><?= t('Versión') ?></th></tr></thead>
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
        <a class="btn" href="<?= h(panelUrl(['view' => 'users', 'user' => $fu])) ?>"><?= t('Ficha del usuario') ?></a>
      </div>
      <div class="meta"><?= h($focusUser['email']) ?> <?= t('· Tel.') ?> <?= h($focusUser['phone']) ?>
        · <?= (int) $focusUser['devices'] ?> <?= t('equipo(s),') ?> <?= (int) $focusUser['authorized'] ?> <?= t('autorizado(s)') ?></div>
      <?= creditBlock($focusUser, $back) ?>
      <!-- Todos a la vez; cada equipo se sigue pudiendo cambiar con sus botones de abajo -->
      <div class="actions">
        <form method="post" data-confirm="<?= h(t('¿Autorizar TODOS los equipos de «')) ?><?= h($focusUser['company']) ?>»?"><?= csrfField() ?>
          <input type="hidden" name="action" value="user_authorize_all"><input type="hidden" name="userId" value="<?= $fu ?>">
          <input type="hidden" name="back" value="<?= h($back) ?>">
          <button class="ok" type="submit" <?= (int) $focusUser['authorized'] === (int) $focusUser['devices'] ? 'disabled' : '' ?>><?= t('Autorizar todos sus equipos') ?></button></form>
        <form method="post" data-confirm="<?= h(t('¿Revocar la autorización de TODOS los equipos de «')) ?><?= h($focusUser['company']) ?>»?"><?= csrfField() ?>
          <input type="hidden" name="action" value="user_revoke_all"><input type="hidden" name="userId" value="<?= $fu ?>">
          <input type="hidden" name="back" value="<?= h($back) ?>">
          <button class="bad" type="submit" <?= (int) $focusUser['devices'] === 0 ? 'disabled' : '' ?>><?= t('Revocar todos sus equipos') ?></button></form>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($queueStatus !== null): ?>
    <!-- COLA DE DESCARGAS: pantallas descargando contenido nuevo por turnos -->
    <div class="queue-box <?= $queueStatus['waiting'] ? 'busy' : '' ?>">
      <strong><?= t('Cola de descargas:') ?></strong>
      <?php if (!$queueStatus['active'] && !$queueStatus['waiting']): ?>
        <?= t('vacía') ?> <span class="owner"><?= t('(hasta') ?> <?= (int) $queueStatus['slots'] ?> <?= t('pantallas descargan a la vez)') ?></span>
      <?php else: ?>
        <?= (int) $queueStatus['active'] ?> <?= t('de') ?> <?= (int) $queueStatus['slots'] ?> <?= t('descargando ·') ?> <?= (int) $queueStatus['waiting'] ?> <?= t('esperando turno · terminará en ≈') ?> <?= max(1, (int) ceil($queueStatus['etaSeconds'] / 60)) ?> <?= t('min') ?>
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
      <?= $q !== '' ? t('Ningún equipo coincide con «') . h($q) . t('». Si es nuevo, abre en la TV la pantalla del código QR para que se registre.')
          // Salida directa: la búsqueda se mantiene al cambiar de pestaña
          . ' <a href="' . h(panelUrl(['filter' => $filter, 'user' => $userParam])) . '">' . t('Quitar la búsqueda') . '</a>' : t('No hay equipos en esta lista.') ?>
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
          <?= !empty($d['admin_alias']) ? '<span class="admin-alias" title="' . h(t('Alias del administrador (el usuario no lo ve)')) . '">' . h($d['admin_alias']) . '</span>' : '' ?>
          <span class="device-id"><?= h($id) ?></span>
        </span>
        <span>
          <!-- Permiso de contenido (mirror.html): independiente de la autorización -->
          <span class="badge <?= $mediaOn ? 'authorized' : 'off' ?>"><?= $mediaOn ? t('Contenido habilitado') : t('Contenido no habilitado') ?></span>
          <span class="badge <?= h($st) ?>"><?= h($statusText[$st] ?? $st) ?></span>
        </span>
      </div>
      <div class="meta">
        <?= t('Usuario:') ?> <?= $d['user_company'] ? h($d['user_company']) . ' <span class="owner">(' . h($d['user_email']) . ')</span>'
          . (isset($usersById[(int) $d['user_id']]) ? ' · <span class="owner">' . t('saldo %s', h(TariffRepository::num($usersById[(int) $d['user_id']]['credits']))) . '</span>' : '')
          : '<em>' . t('sin asignar') . '</em>' ?><br>
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
              <label for="lg-<?= $did ?>"><?= t('Liga:') ?></label>
              <select id="lg-<?= $did ?>" name="leagueId" data-autosubmit>
                <option value=""><?= t('Sin liga') ?></option>
                <?php $lastType = null; foreach ($ownerLeagues as $l): ?>
                  <?php if ($lastType !== (int) $l['type_id']): ?>
                    <?= $lastType !== null ? '</optgroup>' : '' ?><optgroup label="<?= h($l['type_name']) ?>">
                  <?php $lastType = (int) $l['type_id']; endif; ?>
                  <option value="<?= (int) $l['id'] ?>" <?= $devLeague && (int) $devLeague['id'] === (int) $l['id'] ? 'selected' : '' ?>>
                    <?= h($l['name'] . ' — ' . str_replace(',', ', ', $l['codes'])) ?></option>
                <?php endforeach; ?>
                <?= $lastType !== null ? '</optgroup>' : '' ?>
              </select>
              <noscript><button type="submit"><?= t('Guardar liga') ?></button></noscript>
            </form>
            <!-- URL final que recibe el equipo (ruta del usuario + leagues de esta liga) -->
            <!-- (igual que RouteRepository::urlForDevice, con las rutas ya leídas) -->
            <span class="route-url"><?= h(isset($routesById[(int) $usersById[$ownerId]['route_id']])
                ? RouteRepository::buildUrl($routesById[(int) $usersById[$ownerId]['route_id']], $devLeague ? (string) $devLeague['codes'] : '')
                : '') ?></span>
          <?php else: ?>
            <!-- Sin ruta o sin ligas en su usuario: solo se muestra la liga -->
            <?= t('Liga:') ?> <?= $devLeague === null ? '<em>' . t('sin liga') . '</em>'
              : h($devLeague['name']) . ' <span class="owner">(' . h($devLeague['type_name']) . ': '
                . h(str_replace(',', ', ', $devLeague['codes'])) . ')</span>' ?>
            <span class="owner">· <?= !$ownerRoute ? t('su usuario no tiene ruta') : t('su usuario no tiene ligas') ?>
              <?= t('(asígnaselas en su ficha para poder elegir la liga aquí)') ?></span><br>
          <?php endif; ?>
        <?php endif; ?>
        <!-- PERFIL (caja física): marca, modelo, MAC del case, instalación; sin perfil => selector de perfiles libres -->
        <?php if (!empty($d['profile_id'])): ?>
          <?= t('Perfil:') ?> <strong><?= h($d['profile_brand'] . ' ' . $d['profile_model']) ?></strong>
          <?= t('· MAC Case') ?> <span class="device-mac"><?= h($d['profile_case_mac']) ?></span>
          <?= !empty($d['profile_serial']) ? '· S/N <span class="device-mac">' . h($d['profile_serial']) . '</span>' : '' ?>
          <?= t('· instalado el') ?> <?= h(date('d/m/Y', strtotime((string) $d['profile_installed_on']))) ?>
          · <a href="./?<?= h(http_build_query(['view' => 'profiles', 'pq' => $d['profile_case_mac']])) ?>"><?= t('editar') ?></a><br>
        <?php else:
            // Propuesta: el perfil libre cuya MAC del case es la MAC que envía la TV
            $suggested = null;
            foreach ($freeProfiles as $fp) {
                if (!empty($d['mac']) && $fp['case_mac'] === $d['mac']) { $suggested = (int) $fp['id']; break; }
            }
        ?>
          <span class="<?= DeviceRepository::needsProfile($d) ? 'need-profile' : '' ?>"><?= t('Perfil:') ?> <em><?= t('sin perfil') ?></em><?= DeviceRepository::needsProfile($d) ? ' ' . t('(obligatorio para autorizarlo por primera vez)') : '' ?></span>
          <form method="post" class="user-form league-form"><?= csrfField() ?>
            <input type="hidden" name="action" value="profile_assign">
            <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
            <?php if ($freeProfiles): ?>
              <select name="profileId" aria-label="<?= h(t('Perfil del equipo')) ?>" required>
                <option value=""><?= t('Elegir un perfil libre…') ?></option>
                <?php foreach ($freeProfiles as $fp): ?>
                  <option value="<?= (int) $fp['id'] ?>" <?= $suggested === (int) $fp['id'] ? 'selected' : '' ?>><?= h(ProfileRepository::text($fp)) ?><?= $suggested === (int) $fp['id'] ? ' ' . t('(coincide con su MAC)') : '' ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit"><?= t('Asignar perfil') ?></button>
            <?php endif; ?>
            <a class="btn" href="./?<?= h(http_build_query(['view' => 'profiles', 'device' => $id])) ?>"><?= t('Crear perfil') ?></a>
          </form>
        <?php endif; ?>
        <!-- MAC (la envía loading.html; una MAC solo está en un equipo) y, si la tenía otro equipo, cuál -->
        MAC: <?= !empty($d['mac']) ? '<span class="device-mac">' . h($d['mac']) . '</span>'
            . ' <span class="owner">' . t('· desde') . ' ' . dateTag($d['mac_at']) . '</span>'
            // El equipo anterior: enlace si sigue en la base; si se eliminó, solo el ID (el
            // enlace llevaba a «Ningún equipo coincide»)
            . (!empty($d['mac_from']) ? ' <span class="owner">' . t('· antes en') . ' '
              . (!empty($d['mac_from_exists'])
                  ? '<a href="./?' . h(http_build_query(['filter' => 'all', 'q' => $d['mac_from']])) . '">' . h($d['mac_from']) . '</a> ' . t('(equipo restablecido)')
                  : h($d['mac_from']) . ' ' . t('(equipo restablecido, ya eliminado)'))
              . '</span>' : '')
          : '<em>' . t('sin MAC') . '</em>' ?><br>
        <?php if (!empty($d['mac_from']) && !empty($d['mac_from_exists'])): $oldId = (string) $d['mac_from']; ?>
          <!-- TV RESTABLECIDA: el equipo anterior (misma MAC) sigue en la base => tomar todos sus datos -->
          <form method="post" class="takeover" data-confirm="<?= h(t('¿Pasar a')) ?> <?= h($id) ?> <?= h(t('todos los datos de')) ?> <?= h($oldId) ?> <?= h(t('(estado, usuario, alias, notas, liga, contenido, perfil, anuncios y tiras)?')) ?>"><?= csrfField() ?>
            <input type="hidden" name="action" value="take_over">
            <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="oldId" value="<?= h($oldId) ?>">
            <input type="hidden" name="back" value="<?= h($back) ?>">
            <strong><?= t('Misma TV que') ?> <?= h($oldId) ?>.</strong>
            <button class="ok" type="submit"><?= t('Tomar los datos de') ?> <?= h($oldId) ?></button>
            <label class="inline"><input type="checkbox" name="deleteOld" value="1" checked> <?= t('y eliminar') ?> <?= h($oldId) ?> <?= t('(ya no se usa)') ?></label>
          </form>
        <?php endif; ?>
        <!-- Última conexión: cualquier consulta de las páginas de la TV (device.php / media.php) o señal de la app nativa (ping.php) -->
        <?= t('Última conexión:') ?> <?= onlineTag($d['online_at'] ?? null, $onlineTimeout) ?><br>
        <?= t('Registrado el') ?> <?= dateTag($d['created_at']) ?>
        <?= t('· Arranques:') ?> <?= (int) ($d['boots'] ?? 0) ?>
        <?= !empty($d['last_boot_at']) ? t('(último') . ' ' . dateTag($d['last_boot_at']) . ')' : '' ?>
        <?= t('· Última consulta') ?> <?= agoTag($d['last_seen_at']) ?>
        <?= $d['app_version'] ? ' · v' . h($d['app_version']) : '' ?>
        <?= $d['last_ip'] ? ' · IP ' . h($d['last_ip']) : '' ?>
        <?php if (!empty($d['admin_notes'])): ?>
          <!-- Notas del administrador (el usuario no las ve) -->
          <div class="admin-notes"><strong><?= t('Notas:') ?></strong> <?= nl2br(h($d['admin_notes'])) ?></div>
        <?php endif; ?>
      </div>
      <div class="actions">
        <?php if ($st !== 'authorized'): $noProfile = DeviceRepository::needsProfile($d); ?>
          <!-- La PRIMERA autorización exige perfil (el servidor también lo comprueba) -->
          <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="authorize">
            <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
            <button class="ok" type="submit" <?= $noProfile ? 'disabled title="' . h(t('Asígnale un perfil primero')) . '"' : '' ?>><?= $noProfile ? t('Autorizar (falta perfil)') : t('Autorizar') ?></button></form>
        <?php endif; ?>
        <?php if ($st === 'authorized'): ?>
          <form method="post" data-confirm="<?= h(t('¿Revocar la autorización de')) ?> <?= h($id) ?>?"><?= csrfField() ?><input type="hidden" name="action" value="revoke">
            <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
            <button class="bad" type="submit"><?= t('Revocar') ?></button></form>
        <?php endif; ?>
        <!-- Habilitar/deshabilitar la función de contenido (imágenes/videos) -->
        <form method="post" <?= $mediaOn ? 'data-confirm="' . h(t('¿Deshabilitar el contenido en %s? Dejará de mostrar imágenes y videos.', $id)) . '"' : '' ?>><?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $mediaOn ? 'media_disable' : 'media_enable' ?>">
          <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <button class="<?= $mediaOn ? 'bad' : 'ok' ?>" type="submit"><?= $mediaOn ? t('Deshabilitar contenido') : t('Habilitar contenido') ?></button></form>
        <form method="post" class="label-form"><?= csrfField() ?><input type="hidden" name="action" value="label">
          <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <input type="text" name="label" value="<?= h($d['label']) ?>" maxlength="100" placeholder="<?= h(t('Alias que también ve el usuario (p. ej. Local Centro)')) ?>" aria-label="<?= h(t('Alias (lo ve el usuario)')) ?>">
          <button type="submit"><?= t('Guardar') ?></button></form>
        <!-- Usuario dueño del equipo (se guarda al cambiarlo) -->
        <form method="post" class="user-form"><?= csrfField() ?><input type="hidden" name="action" value="set_user">
          <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <select name="userId" aria-label="<?= h(t('Usuario del equipo')) ?>" data-autosubmit>
            <option value=""><?= t('Sin asignar') ?></option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) $d['user_id'] ? 'selected' : '' ?>><?= h(userText($u)) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button type="submit"><?= t('Asignar') ?></button></noscript></form>
        <form method="post" data-confirm="<?= h(t('¿Eliminar')) ?> <?= h($id) ?><?= h(t('? Si vuelve a conectarse quedará pendiente.')) ?>"><?= csrfField() ?><input type="hidden" name="action" value="delete">
          <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <button type="submit"><?= t('Eliminar') ?></button></form>
        <a class="btn" href="./?<?= h(http_build_query(['history' => $id])) ?>"><?= t('Historial') ?></a>
      </div>
      <!-- Alias adicional y notas: SOLO el administrador (no salen en /cuenta/ ni en la TV) -->
      <details class="edit">
        <summary><?= t('Alias y notas del administrador') ?><?= !empty($d['admin_alias']) || !empty($d['admin_notes']) ? '' : ' ' . t('(vacío)') ?></summary>
        <form method="post" autocomplete="off"><?= csrfField() ?><input type="hidden" name="action" value="admin_info">
          <input type="hidden" name="deviceId" value="<?= h($id) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
          <label for="aa-<?= h($id) ?>"><?= t('Alias del administrador (se puede buscar por él)') ?></label>
          <input type="text" id="aa-<?= h($id) ?>" name="adminAlias" value="<?= h((string) ($d['admin_alias'] ?? '')) ?>" maxlength="100" placeholder="<?= h(t('p. ej. Caja 14 · Sucursal Norte')) ?>">
          <label for="an-<?= h($id) ?>"><?= t('Notas') ?></label>
          <textarea id="an-<?= h($id) ?>" name="adminNotes" rows="3" maxlength="<?= DeviceRepository::ADMIN_NOTES_MAX ?>" placeholder="<?= h(t('Notas internas: instalación, incidencias, contacto…')) ?>"><?= h((string) ($d['admin_notes'] ?? '')) ?></textarea>
          <button class="primary" type="submit"><?= t('Guardar alias y notas') ?></button>
          <span class="owner"><?= t('El usuario no ve este alias ni estas notas.') ?></span>
        </form>
      </details>
    </section>
  <?php endforeach; ?>
  <?php if ($devPages > 1): ?>
    <!-- Paginación (muchos equipos) -->
    <nav class="pager">
      <?php if ($devPage > 1): ?>
        <a class="btn" href="<?= h(panelUrl(['filter' => $filter, 'q' => $q, 'user' => $userParam, 'p' => $devPage - 1])) ?>"><?= t('&laquo; Anterior') ?></a>
      <?php endif; ?>
      <span class="owner"><?= t('Página') ?> <?= $devPage ?> <?= t('de') ?> <?= $devPages ?> · <?= $devTotal ?> <?= t('equipo(s)') ?></span>
      <?php if ($devPage < $devPages): ?>
        <a class="btn" href="<?= h(panelUrl(['filter' => $filter, 'q' => $q, 'user' => $userParam, 'p' => $devPage + 1])) ?>"><?= t('Siguiente &raquo;') ?></a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
  <?php endif; /* fin vista usuarios / historial / lista */ ?>

  <!-- Escáner: cámara trasera + lector de QR -->
  <div id="scanner" role="dialog" aria-label="<?= h(t('Escanear código QR')) ?>">
    <video id="video" playsinline muted></video>
    <p id="scanMsg"><?= t('Apunta al código QR de la pantalla de la TV.') ?></p>
    <button type="button" id="scanClose"><?= t('Cerrar') ?></button>
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
  <script nonce="<?= h($nonce) ?>">
  /*
   * MAC Case del perfil (input[data-mac]), 2026-11-22:
   *  - Escrita a mano o con LECTOR DE CÓDIGO DE BARRAS, con o sin separadores
   *    ("e8bb3ec8fb5a", "E8-BB-3E-C8-FB-5A"...): se pasa a mayúsculas y se ponen
   *    los ":" solos cada 2 caracteres (E8:BB:3E:C8:FB:5A), sin mover el cursor.
   *  - Valida: solo 0-9 / A-F y exactamente 12 caracteres; si no, el formulario
   *    no se envía y el aviso dice qué falta. El servidor lo vuelve a comprobar.
   *  - El lector suele terminar con Enter: si la MAC está completa, pasa al
   *    siguiente campo en lugar de enviar el formulario; si no, muestra el aviso.
   */
  (function () {
    "use strict";
    document.querySelectorAll("input[data-mac]").forEach(function (input) {
      var hint = input.parentNode.querySelector("[data-mac-hint]");
      var HINT = hint ? hint.textContent : "";
      // Caracteres que se aceptan como separadores (se quitan antes de formatear)
      function strip(v) { return v.replace(/[\s:.\-]/g, "").toUpperCase(); }
      // Comprueba y explica; devuelve true si la MAC es válida
      function check() {
        var raw = strip(input.value), msg = "";
        if (raw === "") msg = input.required ? <?= json_encode(t('Escribe la MAC Case (12 caracteres).'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?> : "";
        else if (/[^0-9A-F]/.test(raw)) msg = <?= json_encode(t('La MAC Case solo puede tener 0-9 y A-F (hay: %s).'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>.replace("%s", raw.replace(/[0-9A-F]/g, ""));
        else if (raw.length !== 12) msg = <?= json_encode(t('La MAC Case debe tener 12 caracteres: llevas %s.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>.replace("%s", raw.length);
        input.setCustomValidity(msg);
        if (hint) {
          hint.textContent = msg || (raw.length === 12 ? <?= json_encode(t('MAC completa ✓'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?> : HINT);
          hint.style.color = msg && raw !== "" ? "var(--bad, #c0392b)" : "";
        }
        return msg === "";
      }
      function format() {
        var v = input.value, caret = input.selectionStart == null ? v.length : input.selectionStart;
        // Caracteres útiles (sin separadores) que hay antes del cursor
        var before = strip(v.slice(0, caret)).length;
        var raw = strip(v);
        // Con caracteres no hex no se reformatea (se ve lo escrito y el aviso dice cuál sobra)
        if (/^[0-9A-F]*$/.test(raw)) {
          raw = raw.slice(0, 12);
          var out = raw.replace(/(..)(?=.)/g, "$1:");
          if (out !== v) {
            input.value = out;
            // Cursor tras el mismo carácter útil (cada par previo añade un ":")
            var pos = before + Math.floor(Math.max(0, before - 1) / 2);
            if (before > 0 && before % 2 === 0 && before < raw.length) pos++;
            try { input.setSelectionRange(pos, pos); } catch (e) { /* tipo sin selección */ }
          }
        } else if (input.value !== input.value.toUpperCase()) {
          input.value = input.value.toUpperCase();
        }
        check();
      }
      input.addEventListener("input", format);
      // Pegar un texto con 12 hex y separadores raros: el maxlength (17) no debe cortarlo
      input.addEventListener("paste", function (e) {
        var text = (e.clipboardData || window.clipboardData).getData("text");
        if (text) { e.preventDefault(); input.value = text; format(); }
      });
      input.addEventListener("keydown", function (e) {
        if (e.key !== "Enter") return;
        e.preventDefault();
        format();
        if (!check()) { input.reportValidity(); return; }
        // Siguiente campo del formulario (el lector manda Enter al terminar)
        var fields = Array.prototype.filter.call(input.form.elements, function (el) {
          return !el.disabled && el.type !== "hidden" && el.offsetParent !== null;
        });
        var next = fields[fields.indexOf(input) + 1];
        if (next) next.focus();
      });
      format();
    });
    /*
     * S/N del perfil (input[data-upper]), 2026-11-22: se pasa a MAYÚSCULAS mientras
     * se escribe, se pega o lo teclea un lector de código de barras (el servidor
     * también lo guarda en mayúsculas). El cursor se queda donde estaba.
     */
    document.querySelectorAll("input[data-upper]").forEach(function (input) {
      function upper() {
        var v = input.value, up = v.toUpperCase();
        if (v === up) return;
        var a = input.selectionStart, b = input.selectionEnd;
        input.value = up;
        try { if (a != null) input.setSelectionRange(a, b); } catch (e) { /* tipo sin selección */ }
      }
      input.addEventListener("input", upper);
      upper();
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
        s.onload = resolve; s.onerror = function () { reject(new Error(<?= json_encode(t('No se pudo cargar el lector de QR'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>)); };
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
      if (!m) { msg.textContent = <?= json_encode(t('Ese QR no es de Smart Pizarra.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>; return false; }
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
        alert(<?= json_encode(t('Este navegador no permite usar la cámara. Escribe el ID en el buscador.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>);
        return;
      }
      msg.textContent = <?= json_encode(t('Apunta al código QR de la pantalla de la TV.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
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
          ? <?= json_encode(t('Permiso de cámara denegado. Actívalo en el navegador o escribe el ID.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>
          : <?= json_encode(t('No se pudo abrir la cámara:'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?> + " " + (e && e.message ? e.message : e);
      });
    });
    document.getElementById("scanClose").addEventListener("click", stop);
  })();
  </script>
<?php endif; ?>
</main>
</body>
</html>

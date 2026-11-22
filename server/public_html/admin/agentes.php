<?php
// Versión del archivo: 1.0.2 (2026-11-22)
/*
 * =====================================================================
 *  SMART PIZARRA - PANEL DEL AGENTE (launcher de gestión remota)
 *  URL: https://smartpizarra.cloud/admin/agentes.php
 * =====================================================================
 *  Misma sesión que el panel (admin/index.php): hay que iniciar sesión allí.
 *  Pestañas (?tab=):
 *    agents    equipos con el agente: autorizar, grupo, nombre, estado
 *    agent     ficha de un equipo (&id=AG-...): inventario, tareas, comandos
 *    groups    grupos: app principal, pantalla de mantenimiento, DESPLIEGUES
 *              (instalar/actualizar un APK o desinstalar, por fases %)
 *    apks      subir APK (package y versionCode se leen del archivo)
 *    settings  logo de la pantalla de mantenimiento, cola, clave de comandos
 * =====================================================================
 */

declare(strict_types=1);

require __DIR__ . '/../../private/lib.php';
require __DIR__ . '/../../private/web.php';
require __DIR__ . '/../../private/agent.php';

$nonce = startSecurePage('sp_admin', '/admin/');

/** Mensaje flash + volver a esta página (Post/Redirect/Get). */
function back(string $message, string $query = ''): never
{
    $_SESSION['flash'] = $message;
    header('Location: agentes.php' . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

if (empty($_SESSION['admin'])) {
    header('Location: ./', true, 303);
    exit;
}

$setupError = '';
try {
    AppConfig::get();
    $setupError = Database::diagnose() ?? agentDiagnose() ?? '';
} catch (Throwable $e) {
    $setupError = $e->getMessage();
}

$tab = param($_GET, 'tab') ?: 'agents';

/* ---------------- Logo (vista previa, solo administrador) ---------------- */
if ($setupError === '' && isset($_GET['logo'])) {
    $p = AgentFiles::logoPath();
    $info = is_file($p) ? @getimagesize($p) : false;
    if ($info === false) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $info['mime']);
    header('Cache-Control: no-store');
    readfile($p);
    exit;
}

/* --------------------------------- Acciones --------------------------------- */
if ($setupError === '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        back('El archivo es demasiado grande para PHP (upload_max_filesize / post_max_size: '
            . ini_get('post_max_size') . ').', 'tab=apks');
    }
    if (!hash_equals(csrfToken(), param($_POST, 'csrf'))) {
        back('La sesión caducó. Vuelve a intentarlo.');
    }
    $a = param($_POST, 'action');
    $ret = param($_POST, 'back');
    $id = strtoupper(param($_POST, 'id'));
    try {
        switch ($a) {
            case 'agent_status':
                AgentRepository::setStatus($id, param($_POST, 'status'));
                back('Estado actualizado.', $ret);
            case 'agent_group':
                back(AgentRepository::setGroup($id, (int) param($_POST, 'group')) ? 'Grupo cambiado.' : 'Ese grupo no existe.', $ret);
            case 'agent_label':
                AgentRepository::setLabel($id, param($_POST, 'label'));
                back('Nombre guardado.', $ret);
            case 'agent_rotation':
                $r = param($_POST, 'rotation');
                AgentRepository::setRotation($id, $r === '' ? null : (int) $r);
                back('Giro guardado.', $ret);
            case 'agent_delete':
                AgentRepository::delete($id);
                // Desde la lista vuelve a la misma página/filtro; desde la ficha, a la lista
                back('Equipo ' . $id . ' eliminado. Si sigue encendido volverá a aparecer como pendiente.',
                    str_starts_with($ret, 'tab=agents') ? $ret : 'tab=agents');
            case 'command':
                $name = param($_POST, 'name');
                $args = AgentCommands::cleanArgs($name, $_POST);
                if (is_string($args)) {
                    back($args, $ret);
                }
                $gid = (int) param($_POST, 'group');
                $err = AgentCommands::create($id !== '' ? $id : null, $id === '' ? $gid : null, $name, $args);
                back($err ?? 'Comando enviado: el equipo lo ejecuta en su próxima consulta.', $ret);
            case 'group_save':
                $d = AgentGroupRepository::clean($_POST);
                if (is_string($d)) {
                    back($d, 'tab=groups');
                }
                $gid = (int) param($_POST, 'group');
                $gid > 0 ? AgentGroupRepository::update($gid, $d) : AgentGroupRepository::create($d);
                back('Grupo guardado. Los equipos lo reciben en su próxima consulta.', 'tab=groups');
            case 'group_delete':
                AgentGroupRepository::delete((int) param($_POST, 'group'));
                back('Grupo borrado; sus equipos pasaron a General.', 'tab=groups');
            case 'dep_create':
                $err = AgentDeployments::create((int) param($_POST, 'group'), param($_POST, 'kind'),
                    (int) param($_POST, 'apk'), trim(param($_POST, 'package')), (int) param($_POST, 'percent'));
                back($err ?? 'Despliegue creado.', 'tab=groups');
            case 'dep_percent':
                AgentDeployments::setPercent((int) param($_POST, 'dep'), (int) param($_POST, 'percent'));
                back('Porcentaje actualizado.', 'tab=groups');
            case 'dep_pause':
            case 'dep_resume':
                AgentDeployments::setActive((int) param($_POST, 'dep'), $a === 'dep_resume');
                back($a === 'dep_resume' ? 'Despliegue reanudado.' : 'Despliegue en pausa.', 'tab=groups');
            case 'dep_retry':
                AgentDeployments::retry((int) param($_POST, 'dep'));
                back('Los equipos con fallo lo intentarán de nuevo.', 'tab=groups');
            case 'dep_delete':
                AgentDeployments::delete((int) param($_POST, 'dep'));
                back('Despliegue borrado (lo ya instalado se queda).', 'tab=groups');
            case 'apk_upload':
                $f = $_FILES['apk'] ?? null;
                if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    back('No llegó el archivo (error de subida ' . (int) ($f['error'] ?? 4) . ').', 'tab=apks');
                }
                $r = AgentApkRepository::create($f['tmp_name'], (string) $f['name'], param($_POST, 'label'));
                back(is_int($r) ? 'APK subido.' : $r, 'tab=apks');
            case 'apk_delete':
                back(AgentApkRepository::delete((int) param($_POST, 'apk')) ?? 'APK borrado.', 'tab=apks');
            case 'logo_upload':
                $f = $_FILES['logo'] ?? null;
                if (!is_array($f) || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) {
                    back('No llegó la imagen.', 'tab=settings');
                }
                back(AgentFiles::setLogo($f['tmp_name']) ?? 'Logo guardado.', 'tab=settings');
            case 'logo_delete':
                AgentFiles::setLogo(null);
                back('Logo quitado.', 'tab=settings');
            default:
                back('Acción desconocida.');
        }
    } catch (Throwable $e) {
        error_log('smartpizarra admin/agentes.php: ' . $e->getMessage());
        back('Error del servidor: ' . $e->getMessage(), $ret);
    }
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

/* --------------------------------- Vistas ---------------------------------- */

/** Texto de confirmación al eliminar un equipo (lista y ficha). */
const DELETE_AGENT_CONFIRM = '¿Eliminar este equipo del panel? Se borran su inventario y resultados. '
    . 'Si la TV sigue encendida con el agente, volverá a aparecer como Pendiente; para bloquearla usa Revocar.';

/** Formulario POST de un botón. */
function postButton(string $action, array $fields, string $label, string $class = '', string $confirm = ''): string
{
    $h = '<form method="post"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . csrfField()
        . '<input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($fields as $k => $v) {
        $h .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) $v) . '">';
    }
    return $h . '<button type="submit" class="' . h($class) . '">' . h($label) . '</button></form>';
}

/** ¿Conectado? Última consulta antes de 3 intervalos de su grupo. */
function agentOnline(array $a): string
{
    $poll = max(30, (int) ($a['g_poll'] ?? 300));
    return onlineTag((string) $a['last_seen_at'], $poll * 3 + 60);
}

function statusBadge(string $s): string
{
    $txt = ['pending' => 'Pendiente', 'authorized' => 'Autorizado', 'revoked' => 'Revocado'][$s] ?? $s;
    return '<span class="badge ' . h($s) . '">' . h($txt) . '</span>';
}

/** Opciones de giro (TV montadas en vertical). $withGroup = opción "la del grupo" (valor ""). */
function rotationOptions(?int $selected, bool $withGroup): string
{
    $o = $withGroup ? '<option value=""' . ($selected === null ? ' selected' : '') . '>La del grupo</option>' : '';
    foreach ([0 => 'Sin girar (horizontal)', 90 => '90° (vertical)', 180 => '180°', 270 => '270° (vertical)'] as $k => $v) {
        $o .= '<option value="' . $k . '"' . ($selected === $k ? ' selected' : '') . '>' . $v . '</option>';
    }
    return $o;
}

function commandForm(string $agentId, int $groupId, string $back): string
{
    $opts = '';
    foreach (AgentCommands::NAMES as $k => $v) {
        $opts .= '<option value="' . h($k) . '">' . h($v) . '</option>';
    }
    return '<form method="post" class="cmd">' . csrfField()
        . '<input type="hidden" name="action" value="command"><input type="hidden" name="id" value="' . h($agentId) . '">'
        . '<input type="hidden" name="group" value="' . $groupId . '"><input type="hidden" name="back" value="' . h($back) . '">'
        . '<select name="name">' . $opts . '</select>'
        . '<input type="text" name="package" placeholder="paquete (si aplica)">'
        . '<input type="text" name="w" placeholder="ancho" size="5"><input type="text" name="h" placeholder="alto" size="5">'
        . '<input type="text" name="density" placeholder="densidad" size="6"><input type="text" name="scale" placeholder="escala" size="5">'
        . '<button type="submit">Enviar comando</button></form>';
}

$groups = $setupError === '' ? AgentGroupRepository::all() : [];
$groupOptions = static function (int $selected) use ($groups): string {
    $o = '';
    foreach ($groups as $g) {
        $o .= '<option value="' . (int) $g['id'] . '"' . ((int) $g['id'] === $selected ? ' selected' : '') . '>' . h($g['name']) . '</option>';
    }
    return $o;
};

ob_start();
if ($setupError !== '') {
    echo '<div class="card error">' . h($setupError) . '</div>';
} elseif ($tab === 'agents') {
    $q = trim(param($_GET, 'q'));
    $st = param($_GET, 'status');
    $gf = (int) param($_GET, 'group');
    $page = max(1, (int) param($_GET, 'p'));
    $per = 50;
    $res = AgentRepository::search($q, $st, $gf, $per, ($page - 1) * $per);
    $c = AgentRepository::counts();
    $self = http_build_query(array_filter(['tab' => 'agents', 'q' => $q, 'status' => $st, 'group' => $gf ?: '', 'p' => $page > 1 ? $page : '']));
    echo '<div class="card"><p class="meta">Equipos: ' . $c['total'] . ' · Pendientes: ' . $c['pending'] . ' · Autorizados: '
        . $c['authorized'] . ' · Revocados: ' . $c['revoked'] . '</p>'
        . '<form method="get" class="filters"><input type="hidden" name="tab" value="agents">'
        . '<input type="search" name="q" value="' . h($q) . '" placeholder="Buscar por ID, nombre, modelo o IP">'
        . '<select name="status"><option value="">Todos</option>';
    foreach (['pending' => 'Pendientes', 'authorized' => 'Autorizados', 'revoked' => 'Revocados'] as $k => $v) {
        echo '<option value="' . $k . '"' . ($st === $k ? ' selected' : '') . '>' . $v . '</option>';
    }
    echo '</select><select name="group"><option value="0">Todos los grupos</option>' . $groupOptions($gf) . '</select>'
        . '<button type="submit">Filtrar</button></form></div>';
    if (!$res['rows']) {
        echo '<p class="empty">No hay equipos. Instala el agente en una TV y aparecerá aquí como pendiente.</p>';
    }
    foreach ($res['rows'] as $a) {
        $aid = (string) $a['agent_id'];
        echo '<div class="card device"><div class="device-head"><div>'
            . ($a['label'] !== '' ? '<span class="alias">' . h($a['label']) . '</span>' : '')
            . '<a class="device-id" href="?tab=agent&amp;id=' . h($aid) . '">' . h($aid) . '</a></div>'
            . statusBadge((string) $a['status']) . '</div>'
            . '<p class="meta">' . agentOnline($a) . ' · Grupo: ' . h($a['g_name']) . ' · ' . h(trim($a['maker'] . ' ' . $a['model']))
            . ' · Android API ' . (int) $a['sdk'] . ' · Modo: ' . h($a['privilege'] ?: '—') . ' · Agente ' . h($a['agent_version'] ?: '—')
            . ' · Estado: ' . h($a['state'] ?: '—') . ' · Libre: ' . ((int) $a['free_mb'] >= 0 ? (int) $a['free_mb'] . ' MB' : '—')
            . ((int) $a['temp_c'] >= 0 ? ' · ' . (int) $a['temp_c'] . ' °C' : '') . ' · IP ' . h($a['last_ip'])
            . ($a['last_error'] !== '' ? '<br><span class="warn">Último error: ' . h($a['last_error']) . '</span>' : '') . '</p>'
            . '<div class="actions">';
        if ($a['status'] !== 'authorized') {
            echo postButton('agent_status', ['id' => $aid, 'status' => 'authorized', 'back' => $self], 'Autorizar', 'ok');
        }
        if ($a['status'] !== 'revoked') {
            echo postButton('agent_status', ['id' => $aid, 'status' => 'revoked', 'back' => $self], 'Revocar', 'bad');
        }
        echo '<form method="post">' . csrfField() . '<input type="hidden" name="action" value="agent_group">'
            . '<input type="hidden" name="id" value="' . h($aid) . '"><input type="hidden" name="back" value="' . h($self) . '">'
            . '<select name="group">' . $groupOptions((int) $a['group_id']) . '</select><button type="submit">Cambiar grupo</button></form>'
            . '<a class="btn" href="?tab=agent&amp;id=' . h($aid) . '">Ficha</a>'
            // Eliminar desde la lista (vuelve a esta misma página/filtro). Borra el equipo con su
            // inventario, resultados y turno de descarga (ON DELETE CASCADE).
            . postButton('agent_delete', ['id' => $aid, 'back' => $self], 'Eliminar', 'bad', DELETE_AGENT_CONFIRM)
            . '</div></div>';
    }
    if ($res['total'] > $per) {
        $pages = (int) ceil($res['total'] / $per);
        echo '<p class="pager">';
        for ($i = 1; $i <= $pages; $i++) {
            $qs = http_build_query(array_filter(['tab' => 'agents', 'q' => $q, 'status' => $st, 'group' => $gf ?: '', 'p' => $i]));
            echo $i === $page ? '<strong>' . $i . '</strong> ' : '<a href="?' . h($qs) . '">' . $i . '</a> ';
        }
        echo '</p>';
    }
} elseif ($tab === 'agent') {
    $aid = strtoupper(param($_GET, 'id'));
    $a = AgentRepository::find($aid);
    if ($a === null) {
        echo '<p class="empty">Ese equipo no existe.</p>';
    } else {
        $self = 'tab=agent&id=' . $aid;
        echo '<div class="card"><div class="device-head"><span class="device-id">' . h($aid) . '</span>' . statusBadge((string) $a['status']) . '</div>'
            . '<p class="meta">' . agentOnline($a) . ' · Grupo ' . h($a['g_name']) . ' · ' . h(trim($a['maker'] . ' ' . $a['model']))
            . ' · API ' . (int) $a['sdk'] . ' · ' . h($a['abi']) . ' · Modo ' . h($a['privilege'] ?: '—') . ' · Agente ' . h($a['agent_version'])
            . ' · Encendido ' . intdiv((int) $a['uptime_s'], 3600) . ' h · Reinicios del servicio ' . (int) $a['restarts']
            . ' · Versión app principal ' . ((int) $a['main_version'] >= 0 ? (int) $a['main_version'] : 'no instalada')
            . ' · Alta ' . dateTag((string) $a['created_at']) . '</p>'
            . '<form method="post" class="row">' . csrfField() . '<input type="hidden" name="action" value="agent_label">'
            . '<input type="hidden" name="id" value="' . h($aid) . '"><input type="hidden" name="back" value="' . h($self) . '">'
            . '<input type="text" name="label" value="' . h($a['label']) . '" placeholder="Nombre del equipo (ej.: Barra local 3)">'
            . '<button type="submit">Guardar nombre</button></form>'
            . '<form method="post" class="row">' . csrfField() . '<input type="hidden" name="action" value="agent_rotation">'
            . '<input type="hidden" name="id" value="' . h($aid) . '"><input type="hidden" name="back" value="' . h($self) . '">'
            . '<label>Giro de las pantallas del agente <select name="rotation">'
            . rotationOptions($a['rotation'] === null ? null : (int) $a['rotation'], true) . '</select></label>'
            . '<button type="submit">Guardar giro</button></form>'
            . '<div class="actions">'
            . postButton('agent_status', ['id' => $aid, 'status' => 'authorized', 'back' => $self], 'Autorizar', 'ok')
            . postButton('agent_status', ['id' => $aid, 'status' => 'pending', 'back' => $self], 'Pendiente')
            . postButton('agent_status', ['id' => $aid, 'status' => 'revoked', 'back' => $self], 'Revocar', 'bad')
            . postButton('agent_delete', ['id' => $aid], 'Eliminar', 'bad', DELETE_AGENT_CONFIRM)
            . '</div></div>';

        echo '<div class="card"><h2>Comandos</h2>' . commandForm($aid, (int) $a['group_id'], $self) . '<table><tr><th>#</th><th>Comando</th><th>Resultado</th><th>Salida</th></tr>';
        foreach (AgentCommands::history($aid, (int) $a['group_id']) as $c) {
            echo '<tr><td>' . (int) $c['id'] . '</td><td>' . h($c['name']) . ' ' . h($c['args'] !== '{}' ? $c['args'] : '')
                . ($c['target_group'] !== null ? ' (grupo)' : '') . '</td><td>'
                . h($c['status'] === null ? (strtotime($c['expires_at'] . ' UTC') < time() ? 'caducado' : 'pendiente') : $c['status'] . ' ' . $c['code'])
                . '</td><td><code>' . h(mb_substr((string) $c['detail'], 0, 300)) . '</code></td></tr>';
        }
        echo '</table></div>';

        echo '<div class="card"><h2>Últimas tareas</h2><table><tr><th>Tarea</th><th>Resultado</th><th>Intentos</th><th>Fecha</th></tr>';
        foreach (AgentResults::recent($aid) as $r) {
            echo '<tr><td>' . h($r['kind'] . ' ' . $r['package'] . ($r['version_code'] ? ' v' . $r['version_code'] : '')) . '</td><td>'
                . h($r['status'] . ' ' . $r['code']) . '</td><td>' . (int) $r['attempts'] . '</td><td>' . dateTag((string) $r['reported_at']) . '</td></tr>';
        }
        echo '</table></div>';

        $inv = AgentResults::inventory($aid);
        echo '<div class="card"><h2>Apps instaladas (' . count($inv) . ')</h2><table><tr><th>Paquete</th><th>versionCode</th></tr>';
        foreach ($inv as $p) {
            echo '<tr><td>' . h($p['package']) . '</td><td>' . (int) $p['version_code'] . '</td></tr>';
        }
        echo '</table></div>';
    }
} elseif ($tab === 'groups') {
    $apks = AgentApkRepository::all();
    $apkOptions = '';
    // Paquetes de los APK subidos (sin repetir) y el del APK subido más reciente que no sea el propio agente
    $apkPackages = [];
    $newestApk = null;
    foreach ($apks as $p) {
        // data-package: el JS del pie rellena con él el campo "Paquete" del despliegue al elegir el APK
        $apkOptions .= '<option value="' . (int) $p['id'] . '" data-package="' . h($p['package']) . '">' . h($p['package'] . ' · v' . $p['version_code']
            . ($p['version_name'] !== '' ? ' (' . $p['version_name'] . ')' : '') . ($p['label'] !== '' ? ' · ' . $p['label'] : '')) . '</option>';
        $apkPackages[$p['package']] = true;
        if ($p['package'] !== AGENT_SELF_PACKAGE && ($newestApk === null || (int) $p['id'] > (int) $newestApk['id'])) {
            $newestApk = $p;
        }
    }
    // App principal sugerida para un grupo NUEVO: la del grupo General si está puesta;
    // si no, el paquete del último APK subido (que no sea el agente). Se puede cambiar.
    $defaultMain = '';
    foreach ($groups as $g) {
        if ((int) $g['id'] === 1 && (string) $g['main_package'] !== '') {
            $defaultMain = (string) $g['main_package'];
        }
    }
    if ($defaultMain === '' && $newestApk !== null) {
        $defaultMain = (string) $newestApk['package'];
    }
    // Lista de sugerencias (datalist) con los paquetes subidos y las apps principales de los grupos
    $mainSuggestions = $apkPackages;
    foreach ($groups as $g) {
        if ((string) $g['main_package'] !== '') {
            $mainSuggestions[(string) $g['main_package']] = true;
        }
    }
    unset($mainSuggestions[AGENT_SELF_PACKAGE]);
    $datalist = static function (string $id, array $packages): string {
        $o = '<datalist id="' . h($id) . '">';
        foreach ($packages as $pkg => $label) {
            $o .= '<option value="' . h((string) $pkg) . '"' . (is_string($label) ? ' label="' . h($label) . '"' : '') . '>';
        }
        return $o . '</datalist>';
    };
    echo $datalist('main-packages', $mainSuggestions);
    $groupForm = static function (?array $g) use ($defaultMain): string {
        $v = static fn($k, $d = '') => h((string) ($g[$k] ?? $d));
        return '<form method="post" class="grid">' . csrfField() . '<input type="hidden" name="action" value="group_save">'
            . '<input type="hidden" name="group" value="' . (int) ($g['id'] ?? 0) . '">'
            . '<label>Nombre<input type="text" name="name" value="' . $v('name') . '" required></label>'
            // Grupo nuevo: viene relleno con $defaultMain; al editar, el que tenga el grupo
            . '<label>App principal (paquete)<input type="text" name="main_package" list="main-packages" value="'
            . ($g === null ? h($defaultMain) : $v('main_package')) . '" placeholder="io.kodular.smartpizarra.smartPizarra_3_0_14"></label>'
            . '<label>Actividad (opcional)<input type="text" name="main_activity" value="' . $v('main_activity') . '"></label>'
            . '<label>Consulta cada (s)<input type="text" name="poll_seconds" value="' . $v('poll_seconds', '300') . '"></label>'
            . '<label>Texto de mantenimiento<input type="text" name="maintenance_text" value="' . $v('maintenance_text', 'Actualizando librerías del sistema...') . '"></label>'
            . '<label>Color de fondo<input type="color" name="brand_color" value="' . $v('brand_color', '#002B4F') . '"></label>'
            . '<label>Color del texto<input type="color" name="text_color" value="' . $v('text_color', '#D9DEE5') . '"></label>'
            . '<label>Actualizar solo en (HH:MM-HH:MM)<input type="text" name="install_window" value="' . $v('install_window') . '" placeholder="02:00-05:00 o vacío"></label>'
            . '<label>Giro de la pantalla<select name="rotation">' . rotationOptions((int) ($g['rotation'] ?? 0), false) . '</select></label>'
            . '<label class="check"><input type="checkbox" name="show_percent" value="1"' . (($g['show_percent'] ?? 1) ? ' checked' : '') . '> Mostrar porcentaje</label>'
            . '<label class="check"><input type="checkbox" name="foreground_downloads" value="1"' . (!empty($g['foreground_downloads']) ? ' checked' : '') . '> Pantalla también al descargar</label>'
            . '<button type="submit" class="primary">' . ($g ? 'Guardar grupo' : 'Crear grupo') . '</button></form>';
    };
    foreach ($groups as $g) {
        $gid = (int) $g['id'];
        echo '<div class="card"><div class="device-head"><h2>' . h($g['name']) . '</h2><span class="meta">' . (int) $g['agents'] . ' equipos · revisión ' . (int) $g['revision'] . '</span></div>'
            . '<p class="meta">App principal: ' . h($g['main_package'] ?: '— (sin configurar)') . '</p>'
            . '<details><summary>Editar grupo</summary>' . $groupForm($g)
            . ($gid > 1 ? postButton('group_delete', ['group' => $gid], 'Borrar grupo', 'bad', '¿Borrar el grupo? Sus equipos pasan a General.') : '')
            . '</details><h3>Despliegues</h3>';
        $deps = AgentDeployments::forGroup($gid);
        if (!$deps) {
            echo '<p class="meta">Sin despliegues. La app principal se instala sola con su última versión subida.</p>';
        }
        foreach ($deps as $d) {
            $did = (int) $d['id'];
            echo '<div class="dep' . ($d['active'] ? '' : ' paused') . '"><strong>' . ($d['action'] === 'install' ? 'Instalar ' : 'Desinstalar ')
                . h($d['package']) . '</strong>' . ($d['action'] === 'install' ? ' v' . (int) $d['version_code'] . ' (' . formatBytes((int) $d['size_bytes']) . ')' : '')
                . ' · ' . (int) $d['rollout_percent'] . ' % · ' . ($d['active'] ? 'activo' : 'en pausa')
                . ' · <span class="okc">' . (int) $d['ok'] . ' hechos</span> · <span class="badc">' . (int) $d['failed'] . ' fallidos</span>'
                . '<div class="actions"><form method="post">' . csrfField() . '<input type="hidden" name="action" value="dep_percent">'
                . '<input type="hidden" name="dep" value="' . $did . '"><select name="percent">';
            foreach ([1, 5, 10, 25, 50, 100] as $pc) {
                echo '<option value="' . $pc . '"' . ((int) $d['rollout_percent'] === $pc ? ' selected' : '') . '>' . $pc . ' %</option>';
            }
            echo '</select><button type="submit">Cambiar fase</button></form>'
                . postButton($d['active'] ? 'dep_pause' : 'dep_resume', ['dep' => $did], $d['active'] ? 'Pausar' : 'Reanudar')
                . postButton('dep_retry', ['dep' => $did], 'Reintentar fallidos')
                . postButton('dep_delete', ['dep' => $did], 'Borrar', 'bad', '¿Borrar el despliegue?') . '</div></div>';
        }
        // Sugerencias para desinstalar: apps instaladas en los equipos de este grupo (con en cuántos están)
        $installed = [];
        foreach (AgentResults::groupPackages($gid) as $r) {
            $installed[$r['package']] = 'en ' . (int) $r['agents'] . ' equipo' . ((int) $r['agents'] === 1 ? '' : 's');
        }
        echo $datalist('dep-packages-' . $gid, $installed + $apkPackages);
        // data-dep: el JS del pie rellena "Paquete" con el del APK elegido
        echo '<details><summary>Nuevo despliegue</summary><form method="post" class="grid" data-dep>' . csrfField()
            . '<input type="hidden" name="action" value="dep_create"><input type="hidden" name="group" value="' . $gid . '">'
            . '<label>Acción<select name="kind"><option value="install">Instalar / actualizar APK</option><option value="uninstall">Desinstalar paquete</option></select></label>'
            . '<label>APK (para instalar)<select name="apk"><option value="0">—</option>' . $apkOptions . '</select></label>'
            . '<label>Paquete<input type="text" name="package" list="dep-packages-' . $gid . '" placeholder="se rellena al elegir el APK"></label>'
            . '<label>Fase inicial<select name="percent"><option value="100">100 %</option><option value="1">1 %</option><option value="10">10 %</option><option value="50">50 %</option></select></label>'
            . '<button type="submit" class="primary">Crear despliegue</button></form></details>'
            . '<details><summary>Comando a todo el grupo</summary>' . commandForm('', $gid, 'tab=groups') . '</details></div>';
    }
    echo '<div class="card"><h2>Nuevo grupo</h2>' . $groupForm(null) . '</div>';
} elseif ($tab === 'apks') {
    echo '<div class="card"><h2>Subir APK</h2><form method="post" enctype="multipart/form-data" class="row">' . csrfField()
        . '<input type="hidden" name="action" value="apk_upload"><input type="file" name="apk" accept=".apk" required>'
        . '<input type="text" name="label" placeholder="Nota (opcional)"><button type="submit" class="primary">Subir</button></form>'
        . '<p class="meta">El paquete y el versionCode se leen del APK. Máximo ' . intdiv(AgentApkRepository::maxBytes(), 1048576)
        . ' MB (y lo que permita PHP: ' . h(ini_get('upload_max_filesize')) . '). Para actualizar una app, sube un APK con versionCode mayor y firmado con la MISMA clave.</p></div>';
    $list = AgentApkRepository::all();
    echo '<div class="card"><table><tr><th>Paquete</th><th>Versión</th><th>Tamaño</th><th>SHA-256</th><th></th></tr>';
    foreach ($list as $p) {
        echo '<tr><td>' . h($p['package']) . ($p['label'] !== '' ? '<br><span class="meta">' . h($p['label']) . '</span>' : '') . '</td><td>'
            . (int) $p['version_code'] . ($p['version_name'] !== '' ? ' (' . h($p['version_name']) . ')' : '') . '<br><span class="meta">min API ' . (int) $p['min_sdk'] . '</span></td><td>'
            . formatBytes((int) $p['size_bytes']) . '</td><td><code>' . h(substr($p['sha256'], 0, 16)) . '…</code></td><td>'
            . ((int) $p['used'] > 0 ? '<span class="meta">en uso</span>' : postButton('apk_delete', ['apk' => (int) $p['id']], 'Borrar', 'bad', '¿Borrar este APK?'))
            . '</td></tr>';
    }
    echo '</table></div>';
} elseif ($tab === 'settings') {
    $qs = AgentQueue::status();
    echo '<div class="card"><h2>Logo de la pantalla de mantenimiento</h2>'
        . (AgentFiles::logoSha() !== '' ? '<p><img class="logo" src="agentes.php?logo=1" alt="Logo"></p>' . postButton('logo_delete', [], 'Quitar logo', 'bad') : '<p class="meta">Sin logo (solo el texto).</p>')
        . '<form method="post" enctype="multipart/form-data" class="row">' . csrfField() . '<input type="hidden" name="action" value="logo_upload">'
        . '<input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required><button type="submit" class="primary">Subir logo</button></form>'
        . '<p class="meta">PNG, JPG o WebP de hasta 1 MB (recomendado 520x260 con fondo transparente).</p></div>'
        . '<div class="card"><h2>Cola de descargas</h2><p class="meta">Descargando: ' . $qs['active'] . ' de ' . $qs['slots']
        . ' huecos (máx. ' . $qs['perIp'] . ' por IP) · En espera: ' . $qs['waiting'] . ' · Turno medio: ' . AgentQueue::turnSeconds() . ' s.'
        . ' Ajustes en config.php: agent_download_slots, agent_slots_per_ip.</p></div>'
        . '<div class="card"><h2>Firma de comandos</h2><p class="meta">'
        . (AgentCommands::canSign() ? 'Clave privada presente: los comandos se firman.' : 'FALTA la clave privada (' . h(basename(AgentCommands::keyFile())) . ' en private/): no se pueden enviar comandos.')
        . '</p></div>';
}
$body = ob_get_clean();

$tabs = ['agents' => 'Equipos', 'groups' => 'Grupos y despliegues', 'apks' => 'APK', 'settings' => 'Ajustes'];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Agentes · Smart Pizarra</title>
<style nonce="<?= h($nonce) ?>">
<?= baseStyles() ?>
main { max-width: 1000px; }
nav.tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
nav.tabs a { padding: 8px 12px; border-radius: 8px; border: 1px solid var(--line); text-decoration: none; color: var(--text); background: var(--card); }
nav.tabs a.on { background: var(--accent); border-color: var(--accent); color: #fff; }
.card { margin-bottom: 12px; }
.filters, .row, .cmd { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 8px 0; }
.filters input[type=search] { flex: 1 1 260px; width: auto; }
.row input[type=text] { flex: 1 1 240px; width: auto; }
.cmd input[type=text] { width: auto; flex: 0 1 120px; }
select, input[type=file], input[type=color] { font: inherit; padding: 8px; border-radius: 8px; border: 1px solid var(--line); background: var(--bg); color: var(--text); }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 8px 12px; align-items: end; margin: 8px 0; }
.grid label { margin: 0; }
.grid label.check { display: flex; gap: 8px; align-items: center; color: var(--text); }
table { width: 100%; border-collapse: collapse; font-size: .9rem; }
th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line); vertical-align: top; overflow-wrap: anywhere; }
.dep { border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px; margin: 6px 0; }
.dep.paused { opacity: .6; }
.okc { color: var(--ok); } .badc, .warn { color: var(--bad); }
details { margin: 8px 0; } summary { cursor: pointer; color: var(--accent); }
img.logo { max-width: 260px; max-height: 130px; background: #002b4f; padding: 8px; border-radius: 8px; }
a.device-id { color: var(--text); }
.pager a, .pager strong { margin-right: 6px; }
@media (max-width: 600px) { th:nth-child(4), td:nth-child(4) { display: none; } }
</style>
</head>
<body>
<main>
<header><h1>Agentes (launcher)</h1><a class="btn" href="./">← Panel</a></header>
<nav class="tabs">
<?php foreach ($tabs as $k => $v): ?>
<a href="?tab=<?= h($k) ?>" class="<?= ($tab === $k || ($tab === 'agent' && $k === 'agents')) ? 'on' : '' ?>"><?= h($v) ?></a>
<?php endforeach; ?>
</nav>
<?php if ($flash !== ''): ?><div class="card flash"><?= h($flash) ?></div><?php endif; ?>
<?= $body ?>
</main>
<script nonce="<?= h($nonce) ?>">
// Confirmaciones (form[data-confirm]), fechas locales y "hace X" (web.php)
<?= timeScript(900) ?>
// Nuevo despliegue: al elegir un APK, "Paquete" se rellena con el suyo (y al cambiar a
// "Desinstalar" se conserva, por si se quiere quitar esa misma app). Con "Instalar", el
// servidor usa siempre el paquete del APK, así que el campo es solo informativo.
(function () {
  var forms = document.querySelectorAll('form[data-dep]');
  for (var i = 0; i < forms.length; i++) {
    (function (f) {
      var apk = f.querySelector('select[name=apk]'), kind = f.querySelector('select[name=kind]'), pkg = f.querySelector('input[name=package]');
      if (!apk || !kind || !pkg) return;
      // Copia el paquete del APK elegido (no pisa lo escrito a mano si el APK es "—")
      function fill() {
        var o = apk.options[apk.selectedIndex];
        var p = o ? o.getAttribute('data-package') : '';
        if (p) pkg.value = p;
      }
      apk.addEventListener('change', function () {
        // Elegir un APK implica instalar
        if (apk.value !== '0') kind.value = 'install';
        fill();
      });
      kind.addEventListener('change', function () {
        if (kind.value === 'install') fill();
      });
    })(forms[i]);
  }
})();
</script>
</body>
</html>

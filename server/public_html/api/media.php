<?php
/*
 * =====================================================================
 *  SMART PIZARRA - API DE CONTENIDO (imágenes y videos) PARA mirror.html
 *  URL: https://smartpizarra.cloud/api/media.php
 * =====================================================================
 *  mirror.html (en la TV) pregunta cada cierto tiempo qué debe mostrar,
 *  descarga UNA sola vez cada archivo nuevo, lo guarda en el propio
 *  equipo y lo reproduce desde ahí. Al servidor solo le llegan consultas
 *  pequeñas (con "rev" casi siempre responde "unchanged") y una descarga
 *  por archivo y equipo.
 *
 *  PETICIÓN (POST, application/x-www-form-urlencoded: sin verificación
 *  previa CORS desde el WebView)
 *    deviceId, secret   igual que api/device.php (localStorage del equipo)
 *    action=list        lista de lo que debe mostrar el equipo
 *      rev            (opcional) la "revision" que ya tiene el equipo
 *    action=file        descarga un archivo
 *      id             id del archivo (de la lista)
 *      ad=1           (opcional) el id es de un ANUNCIO (lista "ads")
 *
 *  ANUNCIOS DEL ADMINISTRADOR (2026-11-11): todo equipo AUTORIZADO recibe
 *  "ads" (aunque no tenga vigencia ni el permiso de contenido): imágenes o
 *  videos que mirror.html intercala después de cada "every" archivos del
 *  usuario (o muestra solos si no hay contenido del usuario). Destino:
 *  todos los equipos, los de ciertos usuarios o equipos concretos; con
 *  fechas opcionales y pausa (ver AdRepository en private/lib.php).
 *
 *  Solo reciben contenido los equipos AUTORIZADOS, con el permiso de
 *  CONTENIDO habilitado por el administrador (sp_devices.media_enabled) y
 *  cuyo USUARIO tiene la VIGENCIA activa (sp_users.content_until, días que
 *  suma/resta el administrador). Si falta algo, la lista llega vacía (con
 *  "authorized", "mediaEnabled" y "contentActive") para que el equipo deje
 *  de mostrarlo. Con la vigencia activa se envía "secondsLeft": el equipo
 *  deja de mostrar el contenido al vencer aunque no tenga internet.
 *  Los archivos son los del USUARIO dueño del equipo que van a todos sus
 *  equipos o a ese equipo en concreto (se eligen en /cuenta/).
 *
 *  RESPUESTAS
 *    list 200 {"ok":true,"status":"authorized","authorized":true,
 *              "revision":"<40 hex>","items":[{"id":7,"kind":"image|video",
 *              "mime":"image/jpeg","size":12345,"sha256":"<64 hex>","seconds":10}, ...]}
 *              (+ "ads":[{"id":3,"kind":..,"mime":..,"size":..,"sha256":..,"seconds":10,"every":3}, ...])
 *         200 {"ok":true,...,"revision":"...","unchanged":true}   si rev coincide (sin items ni ads)
 *         Autorizado: también "deviceLeague" (siempre, aunque sea "unchanged"):
 *              {"code","label","name","values"} o null ("Sin liga") — la liga del
 *              equipo, igual que api/device.php; mirror.html la guarda en localStorage.
 *         200 {"ok":true,"status":"pending|revoked","authorized":false,"mediaEnabled":…,"revision":"","items":[]}
 *         200 {"ok":true,"status":"authorized","authorized":true,"mediaEnabled":false,"revision":"<huella de ads>","items":[],"ads":[...]}
 *    file 200 el archivo (Content-Type del archivo, Content-Length)
 *    400 bad_request · 403 secret_mismatch | not_authorized | media_disabled | content_expired · 404 not_registered |
 *    not_found · 405 method · 429 rate_limited (descargas por equipo y hora) · 500 server
 */

declare(strict_types=1);

require __DIR__ . '/../../private/lib.php';

try {
    Http::cors();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Http::json(['ok' => false, 'error' => 'method'], 405);
    }

    $id = strtoupper(trim((string) ($_POST['deviceId'] ?? '')));
    $secret = strtolower(trim((string) ($_POST['secret'] ?? '')));
    $action = (string) ($_POST['action'] ?? 'list');
    if (!DeviceRepository::isValidId($id) || !DeviceRepository::isValidSecret($secret)
        || !in_array($action, ['list', 'file'], true)) {
        Http::json(['ok' => false, 'error' => 'bad_request'], 400);
    }

    // El equipo debe existir (lo registra api/device.php) y tener su secreto
    $device = DeviceRepository::find($id);
    if ($device === null) {
        Http::json(['ok' => false, 'error' => 'not_registered'], 404);
    }
    if (!DeviceRepository::secretMatches($device, $secret)) {
        Http::json(['ok' => false, 'error' => 'secret_mismatch'], 403);
    }
    $auth = DeviceRepository::authPayload($device);
    // Permiso de contenido (aparte de la autorización); solo lo da el administrador
    $mediaEnabled = DeviceRepository::mediaEnabled($device);
    // Vigencia del usuario dueño (días que asigna el administrador)
    $until = $device['user_content_until'] ?? null;
    $contentActive = UserRepository::contentActive($until);
    $allowed = $auth['authorized'] && $mediaEnabled && $contentActive;

    if ($action === 'file') {
        if (!$auth['authorized']) {
            Http::json(['ok' => false, 'error' => 'not_authorized'], 403);
        }
        // Anuncio: basta con estar autorizado (no exige vigencia ni permiso de contenido)
        $isAd = (string) ($_POST['ad'] ?? '') === '1';
        if ($isAd) {
            $perHour = (int) (AppConfig::get()['media_downloads_per_device_per_hour'] ?? 120);
            if (!RateLimiter::hit('media:' . $id, $perHour, 3600)) {
                Http::json(['ok' => false, 'error' => 'rate_limited'], 429);
            }
            $ad = AdRepository::findForDevice((int) ($_POST['id'] ?? 0), $id);
            if ($ad === null) {
                Http::json(['ok' => false, 'error' => 'not_found'], 404);
            }
            MediaRepository::send($ad);
        }
        if (!$mediaEnabled) {
            Http::json(['ok' => false, 'error' => 'media_disabled'], 403);
        }
        if (!$contentActive) {
            Http::json(['ok' => false, 'error' => 'content_expired'], 403);
        }
        // Límite de descargas por equipo y hora: protege el ancho de banda
        // si un equipo entra en bucle (lo normal es 1 descarga por archivo)
        $perHour = (int) (AppConfig::get()['media_downloads_per_device_per_hour'] ?? 120);
        if (!RateLimiter::hit('media:' . $id, $perHour, 3600)) {
            Http::json(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        $media = MediaRepository::findForDevice((int) ($_POST['id'] ?? 0), $id);
        if ($media === null) {
            Http::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        MediaRepository::send($media);
    }

    // action=list: anota la consulta (como api/device.php) y responde la lista
    DeviceRepository::touch($id, null, Http::clientIp());
    $items = [];
    if ($allowed) {
        foreach (MediaRepository::forDevice($id) as $m) {
            $items[] = [
                'id' => (int) $m['id'],
                'kind' => $m['kind'],
                'mime' => $m['mime'],
                'size' => (int) $m['size_bytes'],
                'sha256' => $m['sha256'],
                'seconds' => (int) $m['seconds'],
            ];
        }
    }
    // Anuncios: cualquier equipo autorizado (aunque no tenga contenido propio)
    $ads = [];
    if ($auth['authorized']) {
        foreach (AdRepository::forDevice($id) as $a) {
            $ads[] = [
                'id' => (int) $a['id'],
                'kind' => $a['kind'],
                'mime' => $a['mime'],
                'size' => (int) $a['size_bytes'],
                'sha256' => $a['sha256'],
                'seconds' => (int) $a['seconds'],
                'every' => (int) $a['every_n'],
            ];
        }
    }
    // revision: huella de la lista (contenido + anuncios); si el equipo ya la tiene no se reenvía.
    // Sin contenido permitido ni anuncios queda vacía (como antes).
    $revision = $allowed || $ads ? sha1(json_encode([$items, $ads])) : '';
    $out = ['ok' => true, 'deviceId' => $id] + $auth + [
        'mediaEnabled' => $mediaEnabled,
        'contentActive' => $contentActive,
        // Segundos de vigencia restantes (el equipo los cuenta con su propio reloj)
        'secondsLeft' => $contentActive ? max(0, (int) strtotime($until . ' UTC') - time()) : 0,
        'revision' => $revision,
    ];
    // Liga del equipo (la elige su usuario en /cuenta/), igual que
    // api/device.php: mirror.html la guarda en smartpizarra.league /
    // smartpizarra.leagues en cada consulta (≈ 1 min). Solo autorizados.
    if ($auth['authorized']) {
        $out['deviceLeague'] = LeagueRepository::devicePayload($device);
    }
    if ($revision !== '' && hash_equals($revision, (string) ($_POST['rev'] ?? ''))) {
        $out['unchanged'] = true;
    } else {
        $out['items'] = $items;
        $out['ads'] = $ads;
    }
    Http::json($out);
} catch (Throwable $e) {
    error_log('smartpizarra api/media.php: ' . $e->getMessage());
    Http::json(['ok' => false, 'error' => 'server'], 500);
}

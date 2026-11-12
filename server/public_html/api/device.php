<?php
/*
 * =====================================================================
 *  SMART PIZARRA - API DE DISPOSITIVOS
 *  URL: https://smartpizarra.cloud/api/device.php
 * =====================================================================
 *  La llaman loading, config, connection y fingerprint desde la TV box.
 *
 *  PETICIÓN (POST, application/x-www-form-urlencoded: así el WebView no
 *  hace la verificación previa CORS)
 *    deviceId  "SP-XXXX-XXXX-XXXX"   (localStorage smartpizarra.deviceId)
 *    secret    64 caracteres hex      (localStorage smartpizarra.deviceSecret)
 *    version   texto opcional         (versión de la app)
 *    event     opcional: "boot" (solo lo envía loading.html al arrancar)
 *
 *  QUÉ HACE
 *    - Equipo nuevo: lo registra como "pending" (límite por IP y hora).
 *    - Equipo conocido: comprueba el secreto y anota la consulta.
 *    - TODA consulta válida refresca la "última conexión" del panel
 *      (online_at) y la última consulta (last_seen_at), sea de la página
 *      que sea (api/media.php hace lo mismo con mirror.html).
 *    - event=boot (SOLO lo envía loading.html al arrancar): además anota
 *      el arranque en el historial (sp_boots, máx. boots_per_device_per_hour
 *      por equipo). Es lo ÚNICO que escribe en el historial: las consultas
 *      de config, connection y fingerprint no cuentan como arranques.
 *    - Devuelve su estado. Solo quien tiene el secreto puede consultarlo:
 *      conocer el ID (que se ve en pantalla y en el QR) no basta.
 *
 *  RESPUESTAS (JSON)
 *    200 {"ok":true,"deviceId":"SP-...","status":"pending|authorized|revoked",
 *         "authorized":false,"registered":true|false}
 *        El estado solo lo cambia el administrador (por equipo o con
 *        "Revocar todos" los equipos de un usuario).
 *        Si está AUTORIZADO añade "deviceLeague": la liga que el usuario
 *        eligió para este equipo en /cuenta/:
 *          {"code":"mlb","label":"MLB","name":"Caribe","values":"LIDOM,MLB,KO"}
 *        o null = "Sin liga". Las páginas guardan code en smartpizarra.league
 *        y values en smartpizarra.leagues ("" sin liga).
 *        Y "deviceRoute" (2026-11-12): la URL de la RUTA de su usuario
 *        (panel > Rutas) con leagues = códigos de la liga del equipo, o null
 *        = sin ruta. Las páginas la guardan en smartpizarra.routeUrl ("" sin ruta).
 *    400 {"ok":false,"error":"bad_request"}      datos con formato inválido
 *    403 {"ok":false,"error":"secret_mismatch"}  el ID ya existe con otro secreto
 *    405 {"ok":false,"error":"method"}           no es POST
 *    429 {"ok":false,"error":"rate_limited"}     demasiados equipos nuevos desde esa IP
 *    500 {"ok":false,"error":"server"}           error interno (ver registro de PHP)
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
    $version = trim((string) ($_POST['version'] ?? ''));
    $version = $version === '' ? null : mb_substr($version, 0, 20);
    // "boot" = arranque de loading.html (fingerprint.html no lo envía)
    $isBoot = ($_POST['event'] ?? '') === 'boot';

    if (!DeviceRepository::isValidId($id) || !DeviceRepository::isValidSecret($secret)) {
        Http::json(['ok' => false, 'error' => 'bad_request'], 400);
    }

    $config = AppConfig::get();
    $ip = Http::clientIp();
    $device = DeviceRepository::find($id);
    $registered = false;

    if ($device === null) {
        // Alta nueva: limitar por IP para que nadie llene la tabla
        if (!RateLimiter::hit('register:' . $ip, (int) $config['register_per_ip_per_hour'], 3600)) {
            Http::json(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        DeviceRepository::create($id, $secret, $version, $ip);
        $device = DeviceRepository::find($id);
        $registered = true;
    } elseif (!DeviceRepository::secretMatches($device, $secret)) {
        // Otro equipo (o alguien) usa este ID: no se revela su estado.
        // Se resuelve borrando el registro desde el panel.
        Http::json(['ok' => false, 'error' => 'secret_mismatch'], 403);
    } else {
        // Cualquier página: refresca la última conexión (online_at) y la consulta
        DeviceRepository::touch($id, $version, $ip);
    }

    if ($isBoot) {
        // Arranque de loading.html: SOLO él escribe en el historial
        // (con límite por equipo y hora). Un equipo recién creado ya tiene
        // online_at = ahora (create()).
        $perHour = (int) ($config['boots_per_device_per_hour'] ?? 30);
        if (RateLimiter::hit('boot:' . $id, $perHour, 3600)) {
            BootLog::record($id, $version, $ip, (int) ($config['boot_history_days'] ?? 365));
        }
    }

    // status/authorized: los que fijó el administrador en el panel
    $payload = array_merge(
        ['ok' => true, 'deviceId' => $id],
        DeviceRepository::authPayload($device),
        ['registered' => $registered]
    );
    // Liga del equipo (la elige su usuario en /cuenta/), solo a equipos AUTORIZADOS:
    // {code,label,name,values} o null = "Sin liga" (la TV guarda ""). Si no
    // está autorizado no se envía (la TV conserva lo que tenía).
    if ($payload['authorized']) {
        $payload['deviceLeague'] = LeagueRepository::devicePayload($device);
        // Ruta de su USUARIO (URL final con leagues = liga de este equipo) o null
        $payload['deviceRoute'] = RouteRepository::urlForDevice($device);
    }
    Http::json($payload);
} catch (Throwable $e) {
    error_log('smartpizarra api/device.php: ' . $e->getMessage());
    Http::json(['ok' => false, 'error' => 'server'], 500);
}

<?php
/*
 * =====================================================================
 *  SMART PIZARRA - API DE DISPOSITIVOS
 *  URL: https://smartpizarra.cloud/api/device.php
 * =====================================================================
 *  La llaman fingerprint.html y loading.html desde la TV box.
 *
 *  PETICIÓN (POST, application/x-www-form-urlencoded: así el WebView no
 *  hace la verificación previa CORS)
 *    deviceId  "SP-XXXX-XXXX-XXXX"   (localStorage smartpizarra.deviceId)
 *    secret    64 caracteres hex      (localStorage smartpizarra.deviceSecret)
 *    version   texto opcional         (versión de la app)
 *
 *  QUÉ HACE
 *    - Equipo nuevo: lo registra como "pending" (límite por IP y hora).
 *    - Equipo conocido: comprueba el secreto y anota la consulta.
 *    - Devuelve su estado. Solo quien tiene el secreto puede consultarlo:
 *      conocer el ID (que se ve en pantalla y en el QR) no basta.
 *
 *  RESPUESTAS (JSON)
 *    200 {"ok":true,"deviceId":"SP-...","status":"pending|authorized|revoked",
 *         "authorized":false,"registered":true|false}
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
        DeviceRepository::touch($id, $version, $ip);
    }

    Http::json([
        'ok' => true,
        'deviceId' => $id,
        'status' => $device['status'],
        'authorized' => $device['status'] === 'authorized',
        'registered' => $registered,
    ]);
} catch (Throwable $e) {
    error_log('smartpizarra api/device.php: ' . $e->getMessage());
    Http::json(['ok' => false, 'error' => 'server'], 500);
}

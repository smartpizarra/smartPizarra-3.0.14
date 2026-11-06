<?php
/*
 * =====================================================================
 *  SMART PIZARRA - SEÑAL "ONLINE" DE LA APP NATIVA
 *  URL: https://smartpizarra.cloud/api/ping.php
 * =====================================================================
 *  La llama SOLO la app nativa de Kodular (componente Web + Clock), cada
 *  cierto tiempo, para decir "este equipo está encendido y con internet".
 *  Las páginas .html NO la usan (ellas usan api/device.php).
 *  El panel muestra "En línea" o "Desconectado · hace X" con esta fecha.
 *
 *  PETICIÓN (POST). Acepta cualquiera de los dos formatos:
 *    a) application/x-www-form-urlencoded (Web.PostText + BuildRequestData)
 *         deviceId=SP-XXXX-XXXX-XXXX&secret=<64 hex>&version=3.0.14
 *    b) JSON  {"deviceId":"SP-...","secret":"...","version":"3.0.14"}
 *  deviceId y secret son los que loading.html envía a la app en su JSON
 *  ("deviceId" y "deviceSecret"). version es opcional.
 *
 *  QUÉ HACE
 *    - Solo equipos YA registrados (los registra device.php); no crea nada.
 *    - Comprueba el secreto y anota online_at = ahora (UTC).
 *    - Devuelve también el estado, por si la app quiere reaccionar a una
 *      revocación sin esperar al siguiente arranque.
 *
 *  RESPUESTAS (JSON)
 *    200 {"ok":true,"deviceId":"SP-...","status":"pending|authorized|revoked",
 *         "authorized":true|false,"serverTime":"2026-11-06T12:00:00Z"}
 *    400 {"ok":false,"error":"bad_request"}      faltan datos o formato inválido
 *    403 {"ok":false,"error":"secret_mismatch"}  el secreto no coincide
 *    404 {"ok":false,"error":"not_registered"}   el ID no está en la base
 *    405 {"ok":false,"error":"method"}           no es POST
 *    500 {"ok":false,"error":"server"}           error interno (ver registro de PHP)
 */

declare(strict_types=1);

require __DIR__ . '/../../private/lib.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Http::json(['ok' => false, 'error' => 'method'], 405);
    }

    // Datos: formulario o, si viene vacío, cuerpo JSON / urlencoded crudo
    // (Kodular puede enviar el texto sin Content-Type de formulario).
    $in = $_POST;
    if (!$in) {
        $raw = (string) file_get_contents('php://input', false, null, 0, 4096);
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $in = $json;
        } else {
            parse_str($raw, $in);
        }
    }

    $id = strtoupper(trim((string) ($in['deviceId'] ?? '')));
    $secret = strtolower(trim((string) ($in['secret'] ?? ($in['deviceSecret'] ?? ''))));
    $version = trim((string) ($in['version'] ?? ''));
    $version = $version === '' ? null : mb_substr($version, 0, 20);

    if (!DeviceRepository::isValidId($id) || !DeviceRepository::isValidSecret($secret)) {
        Http::json(['ok' => false, 'error' => 'bad_request'], 400);
    }

    $device = DeviceRepository::find($id);
    if ($device === null) {
        Http::json(['ok' => false, 'error' => 'not_registered'], 404);
    }
    if (!DeviceRepository::secretMatches($device, $secret)) {
        Http::json(['ok' => false, 'error' => 'secret_mismatch'], 403);
    }

    DeviceRepository::markOnline($id, $version, Http::clientIp());

    Http::json([
        'ok' => true,
        'deviceId' => $id,
        'status' => $device['status'],
        'authorized' => $device['status'] === 'authorized',
        'serverTime' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
} catch (Throwable $e) {
    error_log('smartpizarra api/ping.php: ' . $e->getMessage());
    Http::json(['ok' => false, 'error' => 'server'], 500);
}

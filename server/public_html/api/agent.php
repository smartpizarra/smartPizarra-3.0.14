<?php
// Versión del archivo: 1.0.0 (2026-11-16)
/*
 * =====================================================================
 *  SMART PIZARRA - API DEL AGENTE (launcher de gestión remota)
 *  URL: https://smartpizarra.cloud/api/agent.php?action=...
 * =====================================================================
 *  La llama la app nativa cloud.smartpizarra.agent (carpeta agent/).
 *  Autenticación en CADA petición (siempre HTTPS):
 *    X-Agent-Id: AG-XXXX-XXXX-XXXX     (lo genera el equipo)
 *    X-Agent-Secret: 64 hex            (lo genera el equipo; aquí solo su sha256)
 *
 *  ACCIONES
 *    POST heartbeat  JSON con el estado del equipo. Equipo nuevo => se registra
 *                    "pending" en el grupo 1 (límite register_per_ip_per_hour).
 *                    => {ok,status,next,etag,inventoryWanted,serverTime}
 *                    next = segundos hasta la siguiente consulta (lo decide el grupo).
 *    GET  manifest   estado deseado (If-None-Match => 304 si no cambió)
 *    POST inventory  {hash,packages:[{p,v}]}  => 204
 *    POST turn       pide turno de descarga => {granted,position,retryAfter}
 *    POST done       libera el turno => 204
 *    GET  file&v=ID  descarga un APK (exige turno activo; admite Range)
 *    GET  logo&h=SHA logo de la pantalla de mantenimiento
 *    POST results    {results:[{taskId,type,package,versionCode,status,code,detail,attempts}]} => {ok,saved}
 *
 *  ERRORES (JSON {ok:false,error}): 400 bad_request, 401 unauthorized,
 *  403 secret_mismatch, 404 not_found, 405 method, 429 rate_limited,
 *  503 busy (sin turno; Retry-After), 500 server.
 *  Solo los equipos AUTORIZADOS en /admin/agentes.php reciben tareas y descargas.
 * =====================================================================
 */

declare(strict_types=1);

require __DIR__ . '/../../private/lib.php';
require __DIR__ . '/../../private/agent.php';

/** Cuerpo JSON de la petición (máx. 512 KB). */
function agentBody(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 524288);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

function agentMethod(string $m): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $m) {
        Http::json(['ok' => false, 'error' => 'method'], 405);
    }
}

try {
    $action = (string) ($_GET['action'] ?? '');
    $id = strtoupper(trim((string) ($_SERVER['HTTP_X_AGENT_ID'] ?? '')));
    $secret = strtolower(trim((string) ($_SERVER['HTTP_X_AGENT_SECRET'] ?? '')));
    if (!AgentRepository::isValidId($id) || !AgentRepository::isValidSecret($secret)) {
        Http::json(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    $ip = Http::clientIp();
    $agent = AgentRepository::find($id);

    if ($agent === null) {
        // Solo el heartbeat da de alta un equipo nuevo (con límite por IP y hora)
        if ($action !== 'heartbeat') {
            Http::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        if (!RateLimiter::hit('agent_register:' . $ip, (int) AppConfig::get()['register_per_ip_per_hour'], 3600)) {
            Http::json(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        AgentRepository::create($id, $secret, $ip);
        $agent = AgentRepository::find($id);
    } elseif (!AgentRepository::secretMatches($agent, $secret)) {
        Http::json(['ok' => false, 'error' => 'secret_mismatch'], 403);
    }
    $authorized = $agent['status'] === 'authorized';

    switch ($action) {
        case 'heartbeat':
            agentMethod('POST');
            $in = agentBody();
            AgentRepository::heartbeat($id, $in, $ip);
            Http::json([
                'ok' => true,
                'status' => $agent['status'],
                'next' => max(30, min(3600, (int) $agent['g_poll'])),
                'etag' => AgentManifest::etag($agent),
                'inventoryWanted' => (string) ($in['inv'] ?? '') !== (string) $agent['inventory_hash'],
                'serverTime' => time(),
            ]);

        case 'manifest':
            agentMethod('GET');
            $etag = AgentManifest::etag($agent);
            header('ETag: "' . $etag . '"');
            if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), " \"") === $etag) {
                http_response_code(304);
                exit;
            }
            Http::json(AgentManifest::build($agent));

        case 'inventory':
            agentMethod('POST');
            $in = agentBody();
            AgentResults::saveInventory($id, (string) ($in['hash'] ?? ''), is_array($in['packages'] ?? null) ? $in['packages'] : []);
            http_response_code(204);
            exit;

        case 'turn':
            agentMethod('POST');
            if (!$authorized) {
                Http::json(['ok' => false, 'error' => 'unauthorized'], 401);
            }
            AgentRepository::touch($id, $ip);
            Http::json(['ok' => true] + AgentQueue::request($id, $ip));

        case 'done':
            agentMethod('POST');
            AgentQueue::release($id);
            http_response_code(204);
            exit;

        case 'file':
            agentMethod('GET');
            if (!$authorized) {
                Http::json(['ok' => false, 'error' => 'unauthorized'], 401);
            }
            $apk = AgentApkRepository::find((int) ($_GET['v'] ?? 0));
            if ($apk === null || !is_file(AgentApkRepository::path($apk))) {
                Http::json(['ok' => false, 'error' => 'not_found'], 404);
            }
            if (!AgentQueue::hasTurn($id)) {
                header('Retry-After: 60');
                Http::json(['ok' => false, 'error' => 'busy', 'retryAfter' => 60], 503);
            }
            AgentFiles::send(AgentApkRepository::path($apk), 'application/vnd.android.package-archive',
                preg_replace('/[^A-Za-z0-9._-]/', '_', $apk['package'] . '-' . $apk['version_code']) . '.apk');

        case 'logo':
            agentMethod('GET');
            $sha = AgentFiles::logoSha();
            if ($sha === '' || ($_GET['h'] ?? '') !== $sha || !is_file(AgentFiles::logoPath())) {
                Http::json(['ok' => false, 'error' => 'not_found'], 404);
            }
            AgentFiles::send(AgentFiles::logoPath(), 'application/octet-stream', 'logo.img');

        case 'results':
            agentMethod('POST');
            $in = agentBody();
            $saved = AgentResults::save($id, is_array($in['results'] ?? null) ? $in['results'] : []);
            Http::json(['ok' => true, 'saved' => $saved]);

        default:
            Http::json(['ok' => false, 'error' => 'bad_request'], 400);
    }
} catch (Throwable $e) {
    error_log('smartpizarra api/agent.php: ' . $e->getMessage());
    Http::json(['ok' => false, 'error' => 'server'], 500);
}

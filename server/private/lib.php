<?php
/*
 * =====================================================================
 *  SMART PIZARRA - CÓDIGO COMÚN DEL SERVIDOR
 * =====================================================================
 *  Lo usan public_html/api/device.php (los equipos) y
 *  public_html/admin/index.php (el panel). Va en private/, FUERA de
 *  public_html, junto a config.php.
 *
 *  Clases:
 *    AppConfig         Lee config.php (o el archivo de SP_CONFIG, para pruebas).
 *    Database          Conexión PDO a MySQL (consultas preparadas siempre).
 *    RateLimiter       Límite de peticiones por "cubeta" (IP + motivo) y ventana.
 *    DeviceRepository  Alta, consulta y cambio de estado de los equipos.
 *    Http              IP del cliente, respuestas JSON y CORS.
 */

declare(strict_types=1);

/** Configuración del servidor (array devuelto por config.php). */
final class AppConfig
{
    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    /** @return array<string,mixed> */
    public static function get(): array
    {
        if (self::$data === null) {
            // SP_CONFIG permite usar otro archivo en pruebas locales
            $file = getenv('SP_CONFIG') ?: __DIR__ . '/config.php';
            if (!is_file($file)) {
                throw new RuntimeException('Falta private/config.php (copiar de config.sample.php)');
            }
            self::$data = require $file;
        }
        return self::$data;
    }
}

/** Conexión única a la base de datos. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $db = AppConfig::get()['db'];
            self::$pdo = new PDO(
                'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4',
                $db['user'],
                $db['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            // Todas las fechas en UTC
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    /** @return string Fecha y hora actual UTC para columnas DATETIME. */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

/** Límite de peticiones: como máximo $max por $seconds en cada cubeta. */
final class RateLimiter
{
    /**
     * Cuenta un intento y dice si se pasó del límite.
     * @param string $bucket  Motivo + IP (p. ej. "register:1.2.3.4").
     * @return bool true si TODAVÍA está permitido (contando este intento).
     */
    public static function hit(string $bucket, int $max, int $seconds): bool
    {
        $pdo = Database::pdo();
        $now = time();
        $start = $now - ($now % $seconds); // ventanas fijas
        $pdo->prepare('DELETE FROM sp_rate_limits WHERE bucket = ? AND window_start < ?')
            ->execute([$bucket, $start]);
        $pdo->prepare(
            'INSERT INTO sp_rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1'
        )->execute([$bucket, $start]);
        $stmt = $pdo->prepare('SELECT hits FROM sp_rate_limits WHERE bucket = ?');
        $stmt->execute([$bucket]);
        return (int) $stmt->fetchColumn() <= $max;
    }

    /** @return int Intentos contados en la ventana actual. */
    public static function count(string $bucket, int $seconds): int
    {
        $now = time();
        $stmt = Database::pdo()->prepare(
            'SELECT hits FROM sp_rate_limits WHERE bucket = ? AND window_start >= ?'
        );
        $stmt->execute([$bucket, $now - ($now % $seconds)]);
        return (int) $stmt->fetchColumn();
    }

    /** Borra la cubeta (p. ej. tras un login correcto). */
    public static function reset(string $bucket): void
    {
        Database::pdo()->prepare('DELETE FROM sp_rate_limits WHERE bucket = ?')->execute([$bucket]);
    }
}

/** Equipos (tabla sp_devices). */
final class DeviceRepository
{
    /** Estados posibles. */
    public const STATUSES = ['pending', 'authorized', 'revoked'];

    /** Formato del ID que crea loading.html: SP-XXXX-XXXX-XXXX (hex). */
    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^SP-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', $id);
    }

    /** Secreto del equipo: 64 caracteres hex (32 bytes aleatorios). */
    public static function isValidSecret(string $secret): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', $secret);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_devices WHERE device_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Alta de un equipo nuevo en estado "pending". */
    public static function create(string $id, string $secret, ?string $version, string $ip): void
    {
        $now = Database::now();
        Database::pdo()->prepare(
            'INSERT INTO sp_devices (device_id, secret_hash, status, app_version, last_ip, created_at, last_seen_at)
             VALUES (?, ?, \'pending\', ?, ?, ?, ?)'
        )->execute([$id, hash('sha256', $secret), $version, $ip, $now, $now]);
    }

    /** true si el secreto coincide con el guardado (comparación en tiempo constante). */
    public static function secretMatches(array $device, string $secret): bool
    {
        return hash_equals($device['secret_hash'], hash('sha256', $secret));
    }

    /** Anota la última consulta del equipo. */
    public static function touch(string $id, ?string $version, string $ip): void
    {
        Database::pdo()->prepare(
            'UPDATE sp_devices SET last_seen_at = ?, app_version = COALESCE(?, app_version), last_ip = ?
             WHERE device_id = ?'
        )->execute([Database::now(), $version, $ip, $id]);
    }

    /**
     * Señal "online" de la app nativa Kodular (api/ping.php).
     * Solo toca online_at (y la IP/versión): last_seen_at sigue siendo la
     * última consulta de las páginas HTML.
     */
    public static function markOnline(string $id, ?string $version, string $ip): void
    {
        Database::pdo()->prepare(
            'UPDATE sp_devices SET online_at = ?, app_version = COALESCE(?, app_version), last_ip = ?
             WHERE device_id = ?'
        )->execute([Database::now(), $version, $ip, $id]);
    }

    /** Cambia el estado desde el panel. */
    public static function setStatus(string $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE sp_devices SET status = ?, status_at = ? WHERE device_id = ?'
        );
        $stmt->execute([$status, Database::now(), $id]);
        return $stmt->rowCount() > 0;
    }

    /** Guarda el alias del equipo ("" lo borra). Máx. 100 caracteres. */
    public static function setLabel(string $id, string $label): void
    {
        Database::pdo()->prepare('UPDATE sp_devices SET label = ? WHERE device_id = ?')
            ->execute([$label === '' ? null : mb_substr($label, 0, 100), $id]);
    }

    public static function delete(string $id): void
    {
        Database::pdo()->prepare('DELETE FROM sp_devices WHERE device_id = ?')->execute([$id]);
    }

    /**
     * Lista para el panel.
     * @param string|null $status Filtro de estado (null = todos).
     * @param string      $search Texto a buscar en el ID o el alias.
     * @return array<int,array<string,mixed>>
     */
    public static function search(?string $status, string $search): array
    {
        $where = [];
        $args = [];
        if ($status !== null) {
            $where[] = 'status = ?';
            $args[] = $status;
        }
        if ($search !== '') {
            $where[] = '(device_id LIKE ? OR label LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $args[] = $like;
            $args[] = $like;
        }
        $sql = 'SELECT * FROM sp_devices'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY (status = \'pending\') DESC, last_seen_at DESC LIMIT 500';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /** @return array<string,int> Cantidad de equipos por estado. */
    public static function counts(): array
    {
        $out = ['pending' => 0, 'authorized' => 0, 'revoked' => 0];
        foreach (Database::pdo()->query('SELECT status, COUNT(*) n FROM sp_devices GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }
}

/** Utilidades HTTP. */
final class Http
{
    /** IP del cliente (o la de la cabecera configurada si hay proxy). */
    public static function clientIp(): string
    {
        $header = AppConfig::get()['client_ip_header'] ?? null;
        $ip = $header && !empty($_SERVER[$header]) ? $_SERVER[$header] : ($_SERVER['REMOTE_ADDR'] ?? '');
        $ip = trim(explode(',', (string) $ip)[0]);
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /**
     * CORS para los equipos: solo los orígenes de allowed_origins.
     * Responde la verificación previa (OPTIONS) y termina.
     */
    public static function cors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && in_array($origin, AppConfig::get()['allowed_origins'], true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Max-Age: 86400');
        }
        header('Vary: Origin');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /** Envía una respuesta JSON y termina. */
    public static function json(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

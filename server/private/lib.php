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
 *    GroupRepository   Grupos de equipos (habilitar/deshabilitar por grupo).
 *    BootLog           Historial de arranques (cada ejecución de loading.html).
 *    Http              IP del cliente, respuestas JSON y CORS.
 */

declare(strict_types=1);

/** Configuración del servidor (array devuelto por config.php). */
final class AppConfig
{
    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    /**
     * Lee y valida config.php una sola vez.
     * Los mensajes de error son de diagnóstico: dicen QUÉ falla (archivo
     * ausente, error de sintaxis, clave que falta) pero nunca muestran
     * valores ni contraseñas. El panel los enseña para poder arreglarlo
     * sin acceso a los registros del servidor.
     * @return array<string,mixed>
     * @throws RuntimeException si falta el archivo o está mal escrito.
     */
    public static function get(): array
    {
        if (self::$data === null) {
            // SP_CONFIG permite usar otro archivo en pruebas locales
            $file = getenv('SP_CONFIG') ?: __DIR__ . '/config.php';
            if (!is_file($file)) {
                throw new RuntimeException(
                    'No existe private/config.php. Copia private/config.sample.php como config.php '
                    . '(en la MISMA carpeta private/, al lado de lib.php) y rellénalo.'
                );
            }
            try {
                $data = require $file;
            } catch (ParseError $e) {
                throw new RuntimeException(
                    'private/config.php tiene un error de escritura cerca de la línea ' . $e->getLine()
                    . ' (suele ser una comilla, una coma o un corchete que falta). '
                    . 'Pon los textos entre comillas simples \'...\'.'
                );
            }
            if (!is_array($data)) {
                throw new RuntimeException(
                    'private/config.php no devuelve la configuración: debe empezar por <?php y contener '
                    . '"return [ ... ];" como config.sample.php.'
                );
            }
            self::validate($data);
            self::$data = $data;
        }
        return self::$data;
    }

    /**
     * Comprueba que estén las claves obligatorias (sin mirar sus valores).
     * @param array<string,mixed> $data
     * @throws RuntimeException con la lista de claves que faltan.
     */
    private static function validate(array $data): void
    {
        $missing = [];
        foreach (['admin_user', 'admin_password_hash', 'allowed_origins', 'register_per_ip_per_hour',
                  'login_fails_per_ip', 'login_window_seconds'] as $key) {
            if (!array_key_exists($key, $data)) {
                $missing[] = $key;
            }
        }
        foreach (['host', 'name', 'user', 'password'] as $key) {
            if (!isset($data['db']) || !is_array($data['db']) || !array_key_exists($key, $data['db'])) {
                $missing[] = "db['$key']";
            }
        }
        if (isset($data['allowed_origins']) && !is_array($data['allowed_origins'])) {
            $missing[] = 'allowed_origins (debe ser una lista: [\'http://localhost\'])';
        }
        if ($missing) {
            throw new RuntimeException(
                'A private/config.php le faltan estas claves: ' . implode(', ', $missing)
                . '. Cópialas de config.sample.php.'
            );
        }
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

    /**
     * Diagnóstico para el panel: conecta y comprueba que existan las tablas
     * y columnas que usa este código. No muestra datos ni contraseñas.
     * @return string|null null si todo está bien; si no, qué falla y cómo arreglarlo.
     */
    public static function diagnose(): ?string
    {
        try {
            $pdo = self::pdo();
        } catch (PDOException $e) {
            // Códigos de MySQL/MariaDB más habituales al configurar Hostinger
            $code = (int) ($e->errorInfo[1] ?? 0);
            $msg = (string) $e->getMessage();
            if ($code === 1045 || str_contains($msg, '1045')) {
                return 'La base de datos rechaza el usuario o la contraseña de config.php (db[\'user\'] / db[\'password\']). '
                    . 'Compruébalos en hPanel > Bases de datos > Administración de MySQL.';
            }
            if ($code === 1049 || str_contains($msg, '1049')) {
                return 'No existe la base de datos indicada en config.php (db[\'name\']). '
                    . 'En Hostinger el nombre lleva el prefijo de la cuenta (u123456789_...).';
            }
            if ($code === 1044 || str_contains($msg, '1044')) {
                return 'El usuario de config.php no tiene permiso sobre esa base de datos. '
                    . 'En hPanel, asígnale la base al usuario.';
            }
            if (str_contains($msg, '2002') || str_contains($msg, '2005')) {
                return 'No se puede conectar con el servidor MySQL de config.php (db[\'host\']). En Hostinger suele ser \'localhost\'.';
            }
            if (str_contains($msg, 'could not find driver')) {
                return 'PHP no tiene activada la extensión pdo_mysql. Actívala en hPanel > Avanzado > Configuración de PHP > Extensiones.';
            }
            return 'No se pudo conectar con la base de datos (código ' . $code . '). Revisa db en config.php.';
        }

        // Tablas y columnas que necesita el código (schema.sql + migrations/)
        $tables = [];
        foreach ($pdo->query('SHOW TABLES') as $row) {
            $tables[] = (string) array_values($row)[0];
        }
        $missing = array_diff(['sp_devices', 'sp_rate_limits', 'sp_boots', 'sp_groups'], $tables);
        if (in_array('sp_devices', $missing, true)) {
            return 'Faltan las tablas: importa server/schema.sql en phpMyAdmin (pestaña Importar) sobre esta base.';
        }
        if (in_array('sp_boots', $missing, true)) {
            return 'Falta la tabla sp_boots: ejecuta en phpMyAdmin (pestaña SQL) server/migrations/2026-11-06-boots.sql.';
        }
        if ($missing) {
            return 'Faltan tablas (' . implode(', ', $missing) . '): ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-07-groups.sql.';
        }
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM sp_devices') as $row) {
            $cols[] = (string) $row['Field'];
        }
        if (!in_array('online_at', $cols, true)) {
            return 'Falta la columna online_at: ejecuta en phpMyAdmin (pestaña SQL) server/migrations/2026-11-06-online.sql.';
        }
        if (!in_array('group_id', $cols, true)) {
            return 'Falta la columna group_id: ejecuta en phpMyAdmin (pestaña SQL) server/migrations/2026-11-07-groups.sql.';
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sp_groups WHERE id = ?');
        $stmt->execute([GroupRepository::DEFAULT_ID]);
        if ((int) $stmt->fetchColumn() === 0) {
            return 'Falta el grupo por defecto (id 1): ejecuta en phpMyAdmin (pestaña SQL) '
                . "INSERT INTO sp_groups (id, name, enabled, created_at) VALUES (1, 'General', 1, UTC_TIMESTAMP());";
        }
        return null;
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

    /**
     * Columnas del equipo + las de su grupo:
     *   group_name     nombre del grupo
     *   group_enabled  1 habilitado | 0 deshabilitado
     */
    private const SELECT_WITH_GROUP = 'SELECT d.*, g.name AS group_name, g.enabled AS group_enabled
        FROM sp_devices d JOIN sp_groups g ON g.id = d.group_id';

    /** @return array<string,mixed>|null Equipo con los datos de su grupo. */
    public static function find(string $id): ?array
    {
        $stmt = Database::pdo()->prepare(self::SELECT_WITH_GROUP . ' WHERE d.device_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Alta de un equipo nuevo en estado "pending", en el grupo por defecto. */
    public static function create(string $id, string $secret, ?string $version, string $ip): void
    {
        $now = Database::now();
        Database::pdo()->prepare(
            'INSERT INTO sp_devices (device_id, secret_hash, status, group_id, app_version, last_ip, created_at, last_seen_at)
             VALUES (?, ?, \'pending\', ?, ?, ?, ?, ?)'
        )->execute([$id, hash('sha256', $secret), GroupRepository::DEFAULT_ID, $version, $ip, $now, $now]);
    }

    /**
     * Estado que se comunica al equipo: el suyo, salvo que esté autorizado
     * y su grupo deshabilitado; entonces se le trata como "revoked" SIN
     * cambiar su estado guardado (al rehabilitar el grupo vuelve a estar
     * autorizado).
     * @param array<string,mixed> $device Fila de find().
     */
    public static function effectiveStatus(array $device): string
    {
        if ($device['status'] === 'authorized' && !self::groupEnabled($device)) {
            return 'revoked';
        }
        return (string) $device['status'];
    }

    /** @param array<string,mixed> $device Fila de find()/search(). */
    public static function groupEnabled(array $device): bool
    {
        return (int) ($device['group_enabled'] ?? 1) === 1;
    }

    /**
     * Parte de la respuesta de api/device.php y api/ping.php sobre la
     * autorización. "reason":"group_disabled" si lo que la impide es el grupo.
     * @param array<string,mixed> $device Fila de find().
     * @return array<string,mixed>
     */
    public static function authPayload(array $device): array
    {
        $status = self::effectiveStatus($device);
        $out = [
            'status' => $status,
            'authorized' => $status === 'authorized',
            'group' => (string) ($device['group_name'] ?? ''),
            'groupEnabled' => self::groupEnabled($device),
        ];
        if ($device['status'] === 'authorized' && $status !== 'authorized') {
            $out['reason'] = 'group_disabled';
        }
        return $out;
    }

    /** Cambia el grupo del equipo. @return bool false si el grupo no existe. */
    public static function setGroup(string $id, int $groupId): bool
    {
        if (GroupRepository::find($groupId) === null) {
            return false;
        }
        Database::pdo()->prepare('UPDATE sp_devices SET group_id = ? WHERE device_id = ?')
            ->execute([$groupId, $id]);
        return true;
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

    /**
     * Arranque de loading.html (api/device.php con event=boot): además de la
     * consulta (last_seen_at) refresca online_at, que es la "última
     * conexión" que muestra el panel. Así el panel queda al día aunque la
     * app nativa todavía no haya enviado su señal a api/ping.php.
     */
    public static function markBoot(string $id, ?string $version, string $ip): void
    {
        $now = Database::now();
        Database::pdo()->prepare(
            'UPDATE sp_devices SET last_seen_at = ?, online_at = ?, app_version = COALESCE(?, app_version), last_ip = ?
             WHERE device_id = ?'
        )->execute([$now, $now, $version, $ip, $id]);
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
     * @param int|null    $groupId Filtro de grupo (null = todos).
     * @return array<int,array<string,mixed>> Filas con group_name/group_enabled.
     */
    public static function search(?string $status, string $search, ?int $groupId = null): array
    {
        $where = [];
        $args = [];
        if ($status !== null) {
            $where[] = 'd.status = ?';
            $args[] = $status;
        }
        if ($groupId !== null) {
            $where[] = 'd.group_id = ?';
            $args[] = $groupId;
        }
        if ($search !== '') {
            $where[] = '(d.device_id LIKE ? OR d.label LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $args[] = $like;
            $args[] = $like;
        }
        // boots / last_boot_at: resumen del historial de arranques (usa idx_device_time)
        $sql = 'SELECT d.*, g.name AS group_name, g.enabled AS group_enabled,
                  (SELECT COUNT(*) FROM sp_boots b WHERE b.device_id = d.device_id) AS boots,
                  (SELECT MAX(b.booted_at) FROM sp_boots b WHERE b.device_id = d.device_id) AS last_boot_at
                FROM sp_devices d JOIN sp_groups g ON g.id = d.group_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY (d.status = \'pending\') DESC, d.last_seen_at DESC LIMIT 500';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /**
     * @param int|null $groupId Contar solo ese grupo (null = todos).
     * @return array<string,int> Cantidad de equipos por estado (+ "all").
     */
    public static function counts(?int $groupId = null): array
    {
        $out = ['pending' => 0, 'authorized' => 0, 'revoked' => 0, 'all' => 0];
        $stmt = Database::pdo()->prepare(
            'SELECT status, COUNT(*) n FROM sp_devices'
            . ($groupId !== null ? ' WHERE group_id = ?' : '')
            . ' GROUP BY status'
        );
        $stmt->execute($groupId !== null ? [$groupId] : []);
        foreach ($stmt as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['all'] += (int) $r['n'];
        }
        return $out;
    }
}

/** Grupos de equipos (tabla sp_groups). */
final class GroupRepository
{
    /** Grupo por defecto: equipos nuevos y los de un grupo borrado. No se borra. */
    public const DEFAULT_ID = 1;

    /** @return string|null Nombre limpio (1-60 caracteres) o null si no vale. */
    public static function cleanName(string $name): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        return ($name === '' || mb_strlen($name) > 60) ? null : $name;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_groups WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Todos los grupos (General primero, luego por nombre) con contadores:
     * devices = equipos del grupo, authorized = de ellos, autorizados.
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        return Database::pdo()->query(
            'SELECT g.*,
               (SELECT COUNT(*) FROM sp_devices d WHERE d.group_id = g.id) AS devices,
               (SELECT COUNT(*) FROM sp_devices d WHERE d.group_id = g.id AND d.status = \'authorized\') AS authorized
             FROM sp_groups g
             ORDER BY (g.id = ' . self::DEFAULT_ID . ') DESC, g.name'
        )->fetchAll();
    }

    /** @return bool true si otro grupo (distinto de $exceptId) ya usa ese nombre. */
    public static function nameTaken(string $name, int $exceptId = 0): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM sp_groups WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $exceptId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Crea un grupo habilitado. @return int Su id. */
    public static function create(string $name): int
    {
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO sp_groups (name, enabled, created_at) VALUES (?, 1, ?)')
            ->execute([$name, Database::now()]);
        return (int) $pdo->lastInsertId();
    }

    public static function rename(int $id, string $name): void
    {
        Database::pdo()->prepare('UPDATE sp_groups SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    /** Habilita o deshabilita el grupo (afecta a todos sus equipos al momento). */
    public static function setEnabled(int $id, bool $enabled): void
    {
        Database::pdo()->prepare('UPDATE sp_groups SET enabled = ?, enabled_at = ? WHERE id = ?')
            ->execute([$enabled ? 1 : 0, Database::now(), $id]);
    }

    /**
     * Borra un grupo: sus equipos pasan al grupo por defecto conservando su
     * estado. El grupo por defecto no se puede borrar.
     * @return int Equipos movidos, o -1 si no se puede borrar.
     */
    public static function delete(int $id): int
    {
        if ($id === self::DEFAULT_ID) {
            return -1;
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE sp_devices SET group_id = ? WHERE group_id = ?');
            $stmt->execute([self::DEFAULT_ID, $id]);
            $moved = $stmt->rowCount();
            $pdo->prepare('DELETE FROM sp_groups WHERE id = ?')->execute([$id]);
            $pdo->commit();
            return $moved;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

/** Historial de arranques (tabla sp_boots). */
final class BootLog
{
    /**
     * Anota un arranque y purga los de ese equipo más viejos que $keepDays.
     * @param int $keepDays Días que se conservan (0 = para siempre).
     */
    public static function record(string $id, ?string $version, string $ip, int $keepDays): void
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO sp_boots (device_id, booted_at, ip, app_version) VALUES (?, ?, ?, ?)'
        )->execute([$id, Database::now(), $ip, $version]);
        if ($keepDays > 0) {
            $pdo->prepare('DELETE FROM sp_boots WHERE device_id = ? AND booted_at < ?')
                ->execute([$id, gmdate('Y-m-d H:i:s', time() - $keepDays * 86400)]);
        }
    }

    /**
     * Últimos arranques de un equipo, del más reciente al más antiguo.
     * @param int $limit Máximo de filas (1-1000).
     * @return array<int,array<string,mixed>>
     */
    public static function forDevice(string $id, int $limit = 300): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT booted_at, ip, app_version FROM sp_boots WHERE device_id = ?
             ORDER BY booted_at DESC, id DESC LIMIT ' . max(1, min(1000, $limit))
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    /** @return int Total de arranques guardados del equipo. */
    public static function count(string $id): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM sp_boots WHERE device_id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
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

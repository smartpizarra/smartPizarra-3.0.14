<?php
/*
 * =====================================================================
 *  SMART PIZARRA - CÓDIGO COMÚN DEL SERVIDOR
 * =====================================================================
 *  Lo usan public_html/api/device.php y api/ping.php (los equipos),
 *  public_html/admin/index.php (el panel) y public_html/cuenta/index.php
 *  (los usuarios). Va en private/, FUERA de public_html, junto a config.php.
 *
 *  Clases:
 *    AppConfig         Lee config.php (o el archivo de SP_CONFIG, para pruebas).
 *    Database          Conexión PDO a MySQL (consultas preparadas siempre).
 *    RateLimiter       Límite de peticiones por "cubeta" (IP + motivo) y ventana.
 *    DeviceRepository  Alta, consulta y cambio de estado de los equipos.
 *    UserRepository    Usuarios (empresas) dueños de equipos y sus contraseñas.
 *    MediaRepository   Imágenes y videos de los usuarios para mirror.html.
 *    LeagueRepository  Ligas y tipos de liga del administrador, asignadas a usuarios.
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
        $missing = array_diff(['sp_devices', 'sp_rate_limits', 'sp_boots', 'sp_users', 'sp_media', 'sp_media_devices'], $tables);
        if (in_array('sp_devices', $missing, true)) {
            return 'Faltan las tablas: importa server/schema.sql en phpMyAdmin (pestaña Importar) sobre esta base.';
        }
        if (in_array('sp_boots', $missing, true)) {
            return 'Falta la tabla sp_boots: ejecuta en phpMyAdmin (pestaña SQL) server/migrations/2026-11-06-boots.sql.';
        }
        $usersSql = 'ejecuta en phpMyAdmin (pestaña SQL) server/migrations/2026-11-08-users.sql.';
        if (in_array('sp_users', $missing, true)) {
            return 'Faltan tablas (' . implode(', ', $missing) . '): ' . $usersSql;
        }
        if ($missing) {
            return 'Faltan tablas (' . implode(', ', $missing) . '): ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-08-media.sql.';
        }
        $cols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM sp_devices') as $row) {
            $cols[] = (string) $row['Field'];
        }
        if (!in_array('online_at', $cols, true)) {
            return 'Falta la columna online_at: ejecuta en phpMyAdmin (pestaña SQL) server/migrations/2026-11-06-online.sql.';
        }
        if (!in_array('user_id', $cols, true)) {
            return 'Falta la columna user_id: ' . $usersSql;
        }
        if (!in_array('media_enabled', $cols, true)) {
            return 'Falta la columna media_enabled: ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-08-media-enabled.sql.';
        }
        $userCols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM sp_users') as $row) {
            $userCols[] = (string) $row['Field'];
        }
        if (!in_array('content_until', $userCols, true)) {
            return 'Falta la columna content_until (vigencia): ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-08-content-until.sql.';
        }
        // Anuncios del administrador
        $adsMissing = array_diff(['sp_ads', 'sp_ad_users', 'sp_ad_devices'], $tables);
        if ($adsMissing) {
            return 'Faltan tablas (' . implode(', ', $adsMissing) . '): ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-11-ads.sql.';
        }
        // Ligas del administrador
        $leaguesMissing = array_diff(['sp_league_types', 'sp_leagues', 'sp_user_leagues'], $tables);
        if ($leaguesMissing) {
            return 'Faltan tablas (' . implode(', ', $leaguesMissing) . '): ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-11-leagues.sql.';
        }
        // Liga de cada equipo (la elige el usuario en /cuenta/)
        if (!in_array('league_id', $cols, true)) {
            return 'Falta la liga de cada equipo (columna league_id): ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-11-device-leagues.sql.';
        }
        // Fechas del contenido de los usuarios (permanente o desde/hasta)
        $mediaCols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM sp_media') as $row) {
            $mediaCols[] = (string) $row['Field'];
        }
        if (!in_array('starts_at', $mediaCols, true) || !in_array('ends_at', $mediaCols, true)) {
            return 'Faltan las columnas de fechas del contenido: ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-11-media-dates.sql.';
        }
        // Tiras y banners de mirror.html (usuario y administrador)
        $overlaysMissing = array_diff(['sp_overlays', 'sp_overlay_users', 'sp_overlay_devices'], $tables);
        if ($overlaysMissing) {
            return 'Faltan tablas (' . implode(', ', $overlaysMissing) . '): ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-11-overlays.sql.';
        }
        // Ajuste de la imagen del banner (proporcional / estirada a todo el ancho)
        $ovCols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM sp_overlays') as $row) {
            $ovCols[] = (string) $row['Field'];
        }
        if (!in_array('image_fit', $ovCols, true)) {
            return 'Falta la columna image_fit (ajuste del banner): ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-11-overlays-fit.sql.';
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
     * Columnas del equipo + las de su usuario (NULL si no está asignado):
     *   user_company  nombre de la empresa
     *   user_email    email del usuario
     */
    private const SELECT_WITH_USER = 'SELECT d.*, u.company AS user_company, u.email AS user_email,
          u.content_until AS user_content_until
        FROM sp_devices d LEFT JOIN sp_users u ON u.id = d.user_id';

    /** @return array<string,mixed>|null Equipo con los datos de su usuario. */
    public static function find(string $id): ?array
    {
        $stmt = Database::pdo()->prepare(self::SELECT_WITH_USER . ' WHERE d.device_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Alta de un equipo nuevo en estado "pending", sin usuario asignado. */
    public static function create(string $id, string $secret, ?string $version, string $ip): void
    {
        $now = Database::now();
        Database::pdo()->prepare(
            'INSERT INTO sp_devices (device_id, secret_hash, status, app_version, last_ip, created_at, last_seen_at, online_at)
             VALUES (?, ?, \'pending\', ?, ?, ?, ?, ?)'
        )->execute([$id, hash('sha256', $secret), $version, $ip, $now, $now, $now]);
    }

    /**
     * Parte de la respuesta de api/device.php y api/ping.php sobre la
     * autorización. Solo el administrador cambia el estado (por equipo o
     * "revocar todos" los de un usuario), así que es el estado guardado.
     * @param array<string,mixed> $device Fila de find().
     * @return array<string,mixed>
     */
    public static function authPayload(array $device): array
    {
        $status = (string) $device['status'];
        return ['status' => $status, 'authorized' => $status === 'authorized'];
    }

    /**
     * Asigna el equipo a un usuario (null = sin asignar).
     * @return bool false si el usuario no existe.
     */
    public static function setUser(string $id, ?int $userId): bool
    {
        if ($userId !== null && UserRepository::find($userId) === null) {
            return false;
        }
        $current = self::find($id);
        Database::pdo()->prepare('UPDATE sp_devices SET user_id = ? WHERE device_id = ?')
            ->execute([$userId, $id]);
        // Otro dueño: la liga elegida por el anterior ya no vale => "Sin liga"
        if ($current !== null && (int) ($current['user_id'] ?? 0) !== (int) $userId) {
            LeagueRepository::setDeviceLeague($id, null);
        }
        return true;
    }

    /** true si el secreto coincide con el guardado (comparación en tiempo constante). */
    public static function secretMatches(array $device, string $secret): bool
    {
        return hash_equals($device['secret_hash'], hash('sha256', $secret));
    }

    /**
     * Anota una consulta del equipo: la hace CUALQUIER página que habla con
     * el servidor (loading, config, connection, fingerprint por api/device.php
     * y mirror por api/media.php). Refresca la consulta (last_seen_at) y la
     * "última conexión" del panel (online_at). NO escribe en el historial:
     * eso solo lo hace el arranque de loading.html (event=boot, BootLog).
     */
    public static function touch(string $id, ?string $version, string $ip): void
    {
        $now = Database::now();
        Database::pdo()->prepare(
            'UPDATE sp_devices SET last_seen_at = ?, online_at = ?, app_version = COALESCE(?, app_version), last_ip = ?
             WHERE device_id = ?'
        )->execute([$now, $now, $version, $ip, $id]);
    }

    /**
     * Señal "online" de la app nativa Kodular (api/ping.php).
     * Solo toca online_at (y la IP/versión): last_seen_at sigue siendo la
     * última consulta de las páginas HTML (que también refrescan online_at,
     * ver touch()).
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

    /**
     * REVOCAR TODOS los equipos de un usuario (botón del panel). Pasan a
     * "revoked" los que estaban autorizados o pendientes; cada equipo se
     * entera en su próxima consulta. Se vuelven a autorizar uno a uno.
     * @return int Equipos que cambiaron de estado.
     */
    public static function revokeAllForUser(int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE sp_devices SET status = \'revoked\', status_at = ?
             WHERE user_id = ? AND status <> \'revoked\''
        );
        $stmt->execute([Database::now(), $userId]);
        return $stmt->rowCount();
    }

    /**
     * AUTORIZAR TODOS los equipos de un usuario (botón del panel). Pasan a
     * "authorized" los que estaban pendientes o revocados; cada equipo se
     * entera en su próxima consulta. Se pueden revocar luego uno a uno.
     * @return int Equipos que cambiaron de estado.
     */
    public static function authorizeAllForUser(int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE sp_devices SET status = \'authorized\', status_at = ?
             WHERE user_id = ? AND status <> \'authorized\''
        );
        $stmt->execute([Database::now(), $userId]);
        return $stmt->rowCount();
    }

    /**
     * Permiso de CONTENIDO (imágenes/videos de mirror.html), aparte de la
     * autorización: solo lo cambia el administrador. Sin él, el equipo no
     * recibe la lista ni descarga archivos aunque esté autorizado.
     */
    public static function setMediaEnabled(string $id, bool $enabled): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE sp_devices SET media_enabled = ?, media_enabled_at = ? WHERE device_id = ?'
        );
        $stmt->execute([$enabled ? 1 : 0, Database::now(), $id]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string,mixed> $device Fila de find()/search(). */
    public static function mediaEnabled(array $device): bool
    {
        return (int) ($device['media_enabled'] ?? 0) === 1;
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
     * Lista para el panel (y para la página del usuario con $userId > 0).
     * @param string|null $status Filtro de estado (null = todos).
     * @param string      $search Texto a buscar en el ID, el alias o la empresa/email del usuario.
     * @param int|null    $userId Filtro de usuario: null = todos, 0 = sin asignar, >0 = ese usuario.
     * @return array<int,array<string,mixed>> Filas con user_company/user_email, boots y last_boot_at.
     */
    public static function search(?string $status, string $search, ?int $userId = null): array
    {
        $where = [];
        $args = [];
        if ($status !== null) {
            $where[] = 'd.status = ?';
            $args[] = $status;
        }
        if ($userId === 0) {
            $where[] = 'd.user_id IS NULL';
        } elseif ($userId !== null) {
            $where[] = 'd.user_id = ?';
            $args[] = $userId;
        }
        if ($search !== '') {
            $where[] = '(d.device_id LIKE ? OR d.label LIKE ? OR u.company LIKE ? OR u.email LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            array_push($args, $like, $like, $like, $like);
        }
        // boots / last_boot_at: resumen del historial de arranques (usa idx_device_time)
        $sql = 'SELECT d.*, u.company AS user_company, u.email AS user_email, u.content_until AS user_content_until,
                  (SELECT COUNT(*) FROM sp_boots b WHERE b.device_id = d.device_id) AS boots,
                  (SELECT MAX(b.booted_at) FROM sp_boots b WHERE b.device_id = d.device_id) AS last_boot_at
                FROM sp_devices d LEFT JOIN sp_users u ON u.id = d.user_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY (d.status = \'pending\') DESC, d.last_seen_at DESC LIMIT 500';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /**
     * @param int|null $userId Igual que en search(): null todos, 0 sin asignar, >0 ese usuario.
     * @return array<string,int> Cantidad de equipos por estado (+ "all").
     */
    public static function counts(?int $userId = null): array
    {
        $out = ['pending' => 0, 'authorized' => 0, 'revoked' => 0, 'all' => 0];
        $where = $userId === 0 ? ' WHERE user_id IS NULL' : ($userId !== null ? ' WHERE user_id = ?' : '');
        $stmt = Database::pdo()->prepare('SELECT status, COUNT(*) n FROM sp_devices' . $where . ' GROUP BY status');
        $stmt->execute($userId ? [$userId] : []);
        foreach ($stmt as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['all'] += (int) $r['n'];
        }
        return $out;
    }
}

/**
 * Usuarios (clientes) dueños de equipos (tabla sp_users).
 * Los crea el administrador con email, teléfono y empresa; el sistema
 * genera una contraseña temporal que el usuario debe cambiar al entrar
 * en /cuenta/. El usuario solo VE sus equipos: autorizar y revocar es
 * cosa del administrador.
 */
final class UserRepository
{
    /** Longitud mínima de la contraseña que elige el usuario. */
    public const MIN_PASSWORD = 8;

    /** @return string|null Email en minúsculas o null si no es válido (máx. 190). */
    public static function cleanEmail(string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        return (mb_strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
    }

    /** @return string|null Teléfono (dígitos, espacios, + - ( ) .; 6-15 dígitos) o null. */
    public static function cleanPhone(string $phone): ?string
    {
        $phone = trim((string) preg_replace('/\s+/', ' ', $phone));
        if (!preg_match('/^\+?[0-9 ().-]{6,30}$/', $phone)) {
            return null;
        }
        $digits = strlen((string) preg_replace('/\D/', '', $phone));
        return ($digits >= 6 && $digits <= 15) ? $phone : null;
    }

    /** @return string|null Nombre de la empresa limpio (1-100 caracteres) o null. */
    public static function cleanCompany(string $company): ?string
    {
        $company = trim((string) preg_replace('/\s+/u', ' ', $company));
        return ($company === '' || mb_strlen($company) > 100) ? null : $company;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_users WHERE email = ?');
        $stmt->execute([mb_strtolower(trim($email))]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Todos los usuarios (por empresa) con contadores:
     * devices = equipos asignados, authorized = de ellos, autorizados.
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        return Database::pdo()->query(
            'SELECT u.*,
               (SELECT COUNT(*) FROM sp_devices d WHERE d.user_id = u.id) AS devices,
               (SELECT COUNT(*) FROM sp_devices d WHERE d.user_id = u.id AND d.status = \'authorized\') AS authorized,
               (SELECT COUNT(*) FROM sp_media m WHERE m.user_id = u.id) AS media
             FROM sp_users u ORDER BY u.company, u.email'
        )->fetchAll();
    }

    /** @return bool true si otro usuario (distinto de $exceptId) ya usa ese email. */
    public static function emailTaken(string $email, int $exceptId = 0): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM sp_users WHERE email = ? AND id <> ?');
        $stmt->execute([$email, $exceptId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Contraseña temporal aleatoria de 12 caracteres, sin letras/números
     * que se confundan al dictarla (0/O, 1/l/I).
     */
    public static function generatePassword(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }

    /**
     * Crea un usuario con contraseña temporal (debe cambiarla al entrar).
     * @return array{0:int,1:string} [id, contraseña temporal en claro para dársela al usuario]
     */
    public static function create(string $email, string $phone, string $company): array
    {
        $password = self::generatePassword();
        $now = Database::now();
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO sp_users (email, phone, company, password_hash, must_change_password, created_at, password_changed_at)
             VALUES (?, ?, ?, ?, 1, ?, ?)'
        )->execute([$email, $phone, $company, password_hash($password, PASSWORD_DEFAULT), $now, $now]);
        return [(int) $pdo->lastInsertId(), $password];
    }

    /** Máximo de días que se suman o restan de una vez. */
    public const MAX_DAYS = 3650;

    /**
     * VIGENCIA: ¿el contenido de este usuario se puede mostrar ahora?
     * @param string|null $until content_until (UTC) del usuario.
     */
    public static function contentActive(?string $until): bool
    {
        return $until !== null && $until !== '' && strtotime($until . ' UTC') > time();
    }

    /**
     * Suma (días > 0) o resta (días < 0) días a la vigencia del usuario.
     *  - Sumar a una vigencia vencida o sin asignar cuenta desde AHORA.
     *  - Sumar a una vigente la alarga desde su fecha de fin.
     *  - Restar acorta la fecha de fin (puede quedar vencida). Sin vigencia
     *    asignada no hay nada que restar.
     * Se refleja en todos sus equipos en su próxima consulta.
     * @return string|null Nueva fecha de fin (UTC) o null si no había nada que restar.
     */
    public static function addContentDays(int $id, int $days): ?string
    {
        $user = self::find($id);
        if ($user === null || $days === 0) {
            return null;
        }
        $until = $user['content_until'] ? (int) strtotime($user['content_until'] . ' UTC') : 0;
        if ($days > 0) {
            $base = max(time(), $until);
        } elseif ($until === 0) {
            return null;
        } else {
            $base = $until;
        }
        $new = gmdate('Y-m-d H:i:s', $base + $days * 86400);
        Database::pdo()->prepare('UPDATE sp_users SET content_until = ?, content_changed_at = ? WHERE id = ?')
            ->execute([$new, Database::now(), $id]);
        return $new;
    }

    /** Quita la vigencia (sin fecha: sus equipos dejan de mostrar el contenido). */
    public static function clearContent(int $id): void
    {
        Database::pdo()->prepare('UPDATE sp_users SET content_until = NULL, content_changed_at = ? WHERE id = ?')
            ->execute([Database::now(), $id]);
    }

    /** Cambia email, teléfono y empresa (desde el panel). */
    public static function update(int $id, string $email, string $phone, string $company): void
    {
        Database::pdo()->prepare('UPDATE sp_users SET email = ?, phone = ?, company = ? WHERE id = ?')
            ->execute([$email, $phone, $company, $id]);
    }

    /**
     * Guarda una contraseña nueva. password_changed_at cambia, así que las
     * sesiones abiertas del usuario con la contraseña anterior se cierran.
     * @param bool $temporary true = la puso el administrador (obliga a cambiarla al entrar).
     */
    public static function setPassword(int $id, string $password, bool $temporary): void
    {
        Database::pdo()->prepare(
            'UPDATE sp_users SET password_hash = ?, must_change_password = ?, password_changed_at = ? WHERE id = ?'
        )->execute([password_hash($password, PASSWORD_DEFAULT), $temporary ? 1 : 0, Database::now(), $id]);
    }

    /** Restablece la contraseña (panel). @return string La nueva contraseña temporal. */
    public static function resetPassword(int $id): string
    {
        $password = self::generatePassword();
        self::setPassword($id, $password, true);
        return $password;
    }

    /** @return string|null Motivo por el que la contraseña nueva no vale, o null si vale. */
    public static function passwordProblem(string $password, string $email): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return 'La contraseña debe tener al menos ' . self::MIN_PASSWORD . ' caracteres.';
        }
        if (mb_strlen($password) > 200) {
            return 'La contraseña es demasiado larga.';
        }
        if (mb_strtolower($password) === mb_strtolower($email)) {
            return 'La contraseña no puede ser tu email.';
        }
        return null;
    }

    /**
     * Comprueba email + contraseña (login de /cuenta/).
     * @return array<string,mixed>|null El usuario, o null si no coinciden.
     */
    public static function verifyLogin(string $email, string $password): ?array
    {
        $user = self::findByEmail($email);
        if ($user === null) {
            // Mismo tiempo de respuesta exista o no el email
            password_verify($password, '$2y$10$try4q.NEPqMHJq1E2PTbq.nOlA/0lsyOZOZS7SR9yT.0wueRcm4u6');
            return null;
        }
        return password_verify($password, (string) $user['password_hash']) ? $user : null;
    }

    /** Anota el último acceso correcto a /cuenta/. */
    public static function markLogin(int $id): void
    {
        Database::pdo()->prepare('UPDATE sp_users SET last_login_at = ? WHERE id = ?')
            ->execute([Database::now(), $id]);
    }

    /**
     * Borra el usuario. Sus equipos quedan sin asignar CONSERVANDO su
     * estado (ON DELETE SET NULL); para cortarles el servicio antes se usa
     * "Revocar todos".
     */
    public static function delete(int $id): void
    {
        // Sus imágenes/videos (filas y ficheros) se borran con él
        MediaRepository::deleteAllForUser($id);
        // Ficheros de sus banners (las filas de sp_overlays se borran en cascada)
        OverlayRepository::deleteFilesForUser($id);
        Database::pdo()->prepare('DELETE FROM sp_users WHERE id = ?')->execute([$id]);
    }
}

/**
 * Imágenes y videos de los usuarios (tablas sp_media + sp_media_devices)
 * que mirror.html descarga UNA vez y reproduce desde el propio equipo.
 *
 * Archivos en private/media/ (o config 'media_dir'), fuera de public_html:
 * solo se entregan por api/media.php (equipo autorizado con su secreto) o
 * por /cuenta/ (su dueño con sesión). Cada archivo puede ir a TODOS los
 * equipos del usuario (all_devices = 1, también los que se le asignen
 * después) o solo a los marcados en sp_media_devices.
 */
final class MediaRepository
{
    /** Tipos admitidos: MIME => [tipo, extensión]. Videos: MP4 (H.264) o WebM. */
    public const TYPES = [
        'image/jpeg' => ['image', 'jpg'],
        'image/png' => ['image', 'png'],
        'image/webp' => ['image', 'webp'],
        'image/gif' => ['image', 'gif'],
        'video/mp4' => ['video', 'mp4'],
        'video/webm' => ['video', 'webm'],
    ];

    /** Límites de la duración de una imagen en pantalla (segundos). */
    public const MIN_SECONDS = 3;
    public const MAX_SECONDS = 3600;

    /** Carpeta de los archivos (se crea si falta). */
    public static function dir(): string
    {
        $dir = (string) (AppConfig::get()['media_dir'] ?? (__DIR__ . '/media'));
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear la carpeta de archivos: ' . $dir);
        }
        return $dir;
    }

    /** @return string Ruta completa de un archivo guardado. */
    public static function path(array $media): string
    {
        return self::dir() . '/' . basename((string) $media['stored_name']);
    }

    /** Tamaño máximo de un archivo (bytes), config 'media_max_mb' (200). */
    public static function maxBytes(): int
    {
        return max(1, (int) (AppConfig::get()['media_max_mb'] ?? 200)) * 1048576;
    }

    /** Espacio máximo por usuario (bytes), config 'media_user_quota_mb' (2048). */
    public static function quotaBytes(): int
    {
        return max(1, (int) (AppConfig::get()['media_user_quota_mb'] ?? 2048)) * 1048576;
    }

    /**
     * Detecta el tipo REAL del archivo por su contenido (no por el nombre).
     * Usa fileinfo si existe; si no, los primeros bytes.
     * @return string|null MIME admitido o null.
     */
    public static function detectMime(string $file): ?string
    {
        $mime = null;
        if (class_exists('finfo')) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: null;
        }
        if ($mime === null || !isset(self::TYPES[$mime])) {
            $head = (string) file_get_contents($file, false, null, 0, 16);
            if (str_starts_with($head, "\xFF\xD8\xFF")) {
                $mime = 'image/jpeg';
            } elseif (str_starts_with($head, "\x89PNG")) {
                $mime = 'image/png';
            } elseif (str_starts_with($head, 'GIF8')) {
                $mime = 'image/gif';
            } elseif (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
                $mime = 'image/webp';
            } elseif (substr($head, 4, 4) === 'ftyp') {
                $mime = 'video/mp4';
            } elseif (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
                $mime = 'video/webm';
            }
        }
        return isset(self::TYPES[$mime]) ? $mime : null;
    }

    /** @return array<string,mixed>|null Archivo de ESE usuario (null si no existe o es de otro). */
    public static function findForUser(int $id, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_media WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Archivos del usuario en orden, con la lista de equipos marcados.
     * @return array<int,array<string,mixed>> Filas + 'devices' (lista de device_id).
     */
    public static function forUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_media WHERE user_id = ? ORDER BY position, id');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return [];
        }
        $byId = [];
        foreach ($rows as $i => $r) {
            $rows[$i]['devices'] = [];
            $byId[(int) $r['id']] = $i;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT md.media_id, md.device_id FROM sp_media_devices md
             JOIN sp_media m ON m.id = md.media_id WHERE m.user_id = ?'
        );
        $stmt->execute([$userId]);
        foreach ($stmt as $r) {
            $rows[$byId[(int) $r['media_id']]]['devices'][] = $r['device_id'];
        }
        return $rows;
    }

    /** @return int Bytes que ocupan los archivos del usuario. */
    public static function usedBytes(int $userId): int
    {
        $stmt = Database::pdo()->prepare('SELECT COALESCE(SUM(size_bytes), 0) FROM sp_media WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Guarda un archivo subido (ya validado: tipo y tamaño) al final del carrusel.
     * @param string[] $deviceIds Equipos (solo si $allDevices es false).
     * @param string|null $startsAt Se muestra desde (UTC "Y-m-d H:i:s"); null = ya.
     * @param string|null $endsAt   Se muestra hasta (UTC); null = sin fin. Ambos null = permanente.
     * @return int Id del archivo.
     */
    public static function create(int $userId, string $tmpFile, string $originalName, string $mime,
                                  int $seconds, bool $allDevices, array $deviceIds,
                                  ?string $startsAt = null, ?string $endsAt = null): int
    {
        [$kind, $ext] = self::TYPES[$mime];
        // Nombre original: si no llega en UTF-8 (algunos clientes de Windows) se convierte
        $originalName = basename(str_replace('\\', '/', $originalName));
        if (!mb_check_encoding($originalName, 'UTF-8')) {
            $originalName = mb_convert_encoding($originalName, 'UTF-8', 'Windows-1252');
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $target = self::dir() . '/' . $stored;
        // move_uploaded_file solo con subidas reales; rename en pruebas por CLI
        $moved = is_uploaded_file($tmpFile) ? move_uploaded_file($tmpFile, $target) : rename($tmpFile, $target);
        if (!$moved) {
            throw new RuntimeException('No se pudo guardar el archivo en ' . self::dir());
        }
        @chmod($target, 0640);
        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            $next = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM sp_media WHERE user_id = ?');
            $next->execute([$userId]);
            $pdo->prepare(
                'INSERT INTO sp_media (user_id, kind, mime, original_name, stored_name, size_bytes, sha256,
                   seconds, position, all_devices, starts_at, ends_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId, $kind, $mime, mb_substr($originalName, 0, 190), $stored, filesize($target),
                hash_file('sha256', $target), self::cleanSeconds($seconds), (int) $next->fetchColumn(),
                $allDevices ? 1 : 0, $startsAt, $endsAt, Database::now(),
            ]);
            $id = (int) $pdo->lastInsertId();
            self::writeDevices($id, $userId, $allDevices, $deviceIds);
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($target);
            throw $e;
        }
    }

    /** Duración de imagen dentro de los límites. */
    public static function cleanSeconds(int $seconds): int
    {
        return max(self::MIN_SECONDS, min(self::MAX_SECONDS, $seconds));
    }

    /**
     * Estado de un archivo según sus fechas (como los anuncios):
     * 'permanent' (sin fechas) | 'active' (dentro del periodo) |
     * 'scheduled' (aún no empieza) | 'ended' (ya terminó).
     */
    public static function state(array $media): string
    {
        $now = time();
        if (!empty($media['ends_at']) && (int) strtotime($media['ends_at'] . ' UTC') <= $now) {
            return 'ended';
        }
        if (!empty($media['starts_at']) && (int) strtotime($media['starts_at'] . ' UTC') > $now) {
            return 'scheduled';
        }
        return empty($media['starts_at']) && empty($media['ends_at']) ? 'permanent' : 'active';
    }

    /**
     * Cambia duración, equipos y fechas de un archivo del usuario.
     * @param string[] $deviceIds Equipos marcados (se ignoran los que no son suyos).
     * @param string|null $startsAt / $endsAt Fechas UTC (null = sin esa fecha; ambas null = permanente).
     */
    public static function update(int $id, int $userId, int $seconds, bool $allDevices, array $deviceIds,
                                  ?string $startsAt = null, ?string $endsAt = null): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE sp_media SET seconds = ?, all_devices = ?, starts_at = ?, ends_at = ? WHERE id = ? AND user_id = ?')
                ->execute([self::cleanSeconds($seconds), $allDevices ? 1 : 0, $startsAt, $endsAt, $id, $userId]);
            self::writeDevices($id, $userId, $allDevices, $deviceIds);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Reescribe los equipos concretos de un archivo (solo los del usuario). */
    private static function writeDevices(int $id, int $userId, bool $allDevices, array $deviceIds): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM sp_media_devices WHERE media_id = ?')->execute([$id]);
        if ($allDevices || !$deviceIds) {
            return;
        }
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO sp_media_devices (media_id, device_id)
             SELECT ?, device_id FROM sp_devices WHERE device_id = ? AND user_id = ?'
        );
        foreach (array_unique($deviceIds) as $deviceId) {
            $insert->execute([$id, (string) $deviceId, $userId]);
        }
    }

    /**
     * Mueve un archivo un puesto antes (-1) o después (+1) en el carrusel.
     */
    public static function move(int $id, int $userId, int $direction): void
    {
        $list = array_map(static fn($r) => (int) $r['id'], self::forUser($userId));
        $i = array_search($id, $list, true);
        $j = $i === false ? false : $i + ($direction < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= count($list)) {
            return;
        }
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        $stmt = Database::pdo()->prepare('UPDATE sp_media SET position = ? WHERE id = ? AND user_id = ?');
        foreach ($list as $pos => $mediaId) {
            $stmt->execute([$pos + 1, $mediaId, $userId]);
        }
    }

    /** Borra un archivo del usuario (fila y fichero). Los equipos lo quitan en su próxima consulta. */
    public static function delete(int $id, int $userId): void
    {
        $media = self::findForUser($id, $userId);
        if ($media === null) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM sp_media WHERE id = ?')->execute([$id]);
        @unlink(self::path($media));
    }

    /** @return array<string,mixed>|null Archivo (de cualquier usuario) con empresa/email del dueño. */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT m.*, u.company AS user_company, u.email AS user_email
             FROM sp_media m JOIN sp_users u ON u.id = m.user_id WHERE m.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Archivos de TODOS los usuarios (o de uno) para el panel del
     * administrador: por empresa y orden de carrusel, con el dueño y los
     * equipos concretos (devices_count) si no van a todos.
     * @return array<int,array<string,mixed>>
     */
    public static function allForAdmin(?int $userId = null): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT m.*, u.company AS user_company, u.email AS user_email,
               (SELECT COUNT(*) FROM sp_media_devices md WHERE md.media_id = m.id) AS devices_count
             FROM sp_media m JOIN sp_users u ON u.id = m.user_id'
            . ($userId !== null ? ' WHERE m.user_id = ?' : '')
            . ' ORDER BY u.company, u.id, m.position, m.id LIMIT 1000'
        );
        $stmt->execute($userId !== null ? [$userId] : []);
        return $stmt->fetchAll();
    }

    /** @return array{0:int,1:int} [archivos, bytes] de todos los usuarios (o de uno). */
    public static function totals(?int $userId = null): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*), COALESCE(SUM(size_bytes), 0) FROM sp_media'
            . ($userId !== null ? ' WHERE user_id = ?' : '')
        );
        $stmt->execute($userId !== null ? [$userId] : []);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return [(int) $row[0], (int) $row[1]];
    }

    /**
     * Borra un archivo de cualquier usuario (administrador). Los equipos
     * lo quitan en su próxima consulta.
     * @return array<string,mixed>|null El archivo borrado (null si no existía).
     */
    public static function deleteById(int $id): ?array
    {
        $media = self::find($id);
        if ($media === null) {
            return null;
        }
        Database::pdo()->prepare('DELETE FROM sp_media WHERE id = ?')->execute([$id]);
        @unlink(self::path($media));
        return $media;
    }

    /** Borra TODOS los archivos de un usuario (al eliminar el usuario). */
    public static function deleteAllForUser(int $userId): void
    {
        foreach (self::forUser($userId) as $media) {
            @unlink(self::path($media));
        }
        Database::pdo()->prepare('DELETE FROM sp_media WHERE user_id = ?')->execute([$userId]);
    }

    /**
     * Lista que reproduce un equipo AHORA: archivos de SU usuario que van a
     * todos sus equipos o a él en concreto, permanentes o dentro de sus
     * fechas, en orden. Vacía si no tiene usuario. Al empezar o terminar
     * un periodo la lista cambia (y su revision), así el equipo lo añade
     * o lo quita en su siguiente consulta.
     * @return array<int,array<string,mixed>>
     */
    public static function forDevice(string $deviceId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT m.id, m.kind, m.mime, m.size_bytes, m.sha256, m.seconds
             FROM sp_media m JOIN sp_devices d ON d.user_id = m.user_id
             WHERE d.device_id = ?
               AND (m.all_devices = 1
                    OR EXISTS (SELECT 1 FROM sp_media_devices md WHERE md.media_id = m.id AND md.device_id = d.device_id))
               AND (m.starts_at IS NULL OR m.starts_at <= ?)
               AND (m.ends_at IS NULL OR m.ends_at > ?)
             ORDER BY m.position, m.id'
        );
        $now = Database::now();
        $stmt->execute([$deviceId, $now, $now]);
        return $stmt->fetchAll();
    }

    /**
     * Un archivo SOLO si le toca a ese equipo (para descargarlo).
     * @return array<string,mixed>|null
     */
    public static function findForDevice(int $id, string $deviceId): ?array
    {
        foreach (self::forDevice($deviceId) as $row) {
            if ((int) $row['id'] === $id) {
                $stmt = Database::pdo()->prepare('SELECT * FROM sp_media WHERE id = ?');
                $stmt->execute([$id]);
                return $stmt->fetch() ?: null;
            }
        }
        return null;
    }

    /**
     * Envía el archivo (cabeceras + contenido) y termina. Sin buffer de PHP
     * para no cargar videos grandes en memoria.
     */
    public static function send(array $media, bool $download = false): never
    {
        $path = self::path($media);
        if (!is_file($path)) {
            Http::json(['ok' => false, 'error' => 'missing_file'], 404);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $media['mime']);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=31536000, immutable');
        if ($download) {
            // Descarga con el nombre original (ASCII seguro + UTF-8 según RFC 6266)
            $name = (string) ($media['original_name'] ?? 'archivo');
            $ascii = preg_replace('/[^A-Za-z0-9._ -]/u', '_', $name) ?: 'archivo';
            header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        }
        readfile($path);
        exit;
    }
}

/**
 * ANUNCIOS del administrador (tablas sp_ads, sp_ad_users, sp_ad_devices).
 *
 * Imagen o video que mirror.html intercala después de cada "every_n"
 * archivos del usuario (si el equipo no tiene contenido propio, muestra
 * solo los anuncios). Destino (scope): all = todos los equipos, users =
 * los equipos de ciertos usuarios (también los que se les asignen
 * después), devices = equipos concretos. Solo lo reciben equipos
 * AUTORIZADOS (no exige vigencia ni permiso de contenido). Fechas
 * opcionales starts_at / ends_at (UTC) y pausa (active = 0).
 * Los ficheros van a la misma carpeta que el contenido de los usuarios
 * (MediaRepository::dir()) y se envían con MediaRepository::send().
 */
final class AdRepository
{
    public const SCOPES = ['all', 'users', 'devices'];
    /** Límites de "cada N archivos del usuario". */
    public const MIN_EVERY = 1;
    public const MAX_EVERY = 100;

    /** Cada N dentro de los límites. */
    public static function cleanEvery(int $n): int
    {
        return max(self::MIN_EVERY, min(self::MAX_EVERY, $n));
    }

    /** Nombre para el panel: sin caracteres de control, 1-100 caracteres (null si queda vacío). */
    public static function cleanName(string $name): ?string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'Windows-1252');
        }
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        return $name === '' ? null : mb_substr($name, 0, 100);
    }

    /**
     * Guarda un anuncio subido (ya validado: tipo y tamaño), al final de la lista.
     * @param array{name:string,seconds:int,every:int,scope:string,active:bool,starts:?string,ends:?string,users:int[],devices:string[]} $data
     * @return int Id del anuncio.
     */
    public static function create(string $tmpFile, string $originalName, string $mime, array $data): int
    {
        [$kind, $ext] = MediaRepository::TYPES[$mime];
        $originalName = basename(str_replace('\\', '/', $originalName));
        if (!mb_check_encoding($originalName, 'UTF-8')) {
            $originalName = mb_convert_encoding($originalName, 'UTF-8', 'Windows-1252');
        }
        $stored = 'ad-' . bin2hex(random_bytes(16)) . '.' . $ext;
        $target = MediaRepository::dir() . '/' . $stored;
        // move_uploaded_file solo con subidas reales; rename en pruebas por CLI
        $moved = is_uploaded_file($tmpFile) ? move_uploaded_file($tmpFile, $target) : rename($tmpFile, $target);
        if (!$moved) {
            throw new RuntimeException('No se pudo guardar el archivo en ' . MediaRepository::dir());
        }
        @chmod($target, 0640);
        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            $next = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM sp_ads')->fetchColumn();
            $now = Database::now();
            $pdo->prepare(
                'INSERT INTO sp_ads (name, kind, mime, original_name, stored_name, size_bytes, sha256, seconds,
                   every_n, scope, active, starts_at, ends_at, position, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $data['name'], $kind, $mime, mb_substr($originalName, 0, 190), $stored, filesize($target),
                hash_file('sha256', $target), MediaRepository::cleanSeconds($data['seconds']),
                self::cleanEvery($data['every']), $data['scope'], $data['active'] ? 1 : 0,
                $data['starts'], $data['ends'], $next, $now, $now,
            ]);
            $id = (int) $pdo->lastInsertId();
            self::writeTargets($id, $data['scope'], $data['users'], $data['devices']);
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($target);
            throw $e;
        }
    }

    /** Cambia los datos de un anuncio (no el archivo). Mismo $data que create(). */
    public static function update(int $id, array $data): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE sp_ads SET name = ?, seconds = ?, every_n = ?, scope = ?, active = ?, starts_at = ?,
                   ends_at = ?, updated_at = ? WHERE id = ?'
            )->execute([
                $data['name'], MediaRepository::cleanSeconds($data['seconds']), self::cleanEvery($data['every']),
                $data['scope'], $data['active'] ? 1 : 0, $data['starts'], $data['ends'], Database::now(), $id,
            ]);
            self::writeTargets($id, $data['scope'], $data['users'], $data['devices']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Reescribe usuarios/equipos destino (solo los del scope elegido y que existan). */
    private static function writeTargets(int $id, string $scope, array $users, array $devices): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM sp_ad_users WHERE ad_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM sp_ad_devices WHERE ad_id = ?')->execute([$id]);
        if ($scope === 'users') {
            $insert = $pdo->prepare('INSERT IGNORE INTO sp_ad_users (ad_id, user_id) SELECT ?, id FROM sp_users WHERE id = ?');
            foreach (array_unique(array_map('intval', $users)) as $uid) {
                $insert->execute([$id, $uid]);
            }
        } elseif ($scope === 'devices') {
            $insert = $pdo->prepare('INSERT IGNORE INTO sp_ad_devices (ad_id, device_id) SELECT ?, device_id FROM sp_devices WHERE device_id = ?');
            foreach (array_unique(array_map('strval', $devices)) as $did) {
                $insert->execute([$id, $did]);
            }
        }
    }

    /** Pausa (false) o reanuda (true) un anuncio. */
    public static function setActive(int $id, bool $active): void
    {
        Database::pdo()->prepare('UPDATE sp_ads SET active = ?, updated_at = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, Database::now(), $id]);
    }

    /** Mueve un anuncio un puesto antes (-1) o después (+1). */
    public static function move(int $id, int $direction): void
    {
        $list = array_map('intval', Database::pdo()->query('SELECT id FROM sp_ads ORDER BY position, id')->fetchAll(PDO::FETCH_COLUMN));
        $i = array_search($id, $list, true);
        $j = $i === false ? false : $i + ($direction < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= count($list)) {
            return;
        }
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        $stmt = Database::pdo()->prepare('UPDATE sp_ads SET position = ? WHERE id = ?');
        foreach ($list as $pos => $adId) {
            $stmt->execute([$pos + 1, $adId]);
        }
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_ads WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Borra un anuncio (fila, destinos y fichero). Los equipos lo quitan en su próxima consulta.
     * @return array<string,mixed>|null El anuncio borrado (null si no existía).
     */
    public static function delete(int $id): ?array
    {
        $ad = self::find($id);
        if ($ad === null) {
            return null;
        }
        Database::pdo()->prepare('DELETE FROM sp_ads WHERE id = ?')->execute([$id]);
        @unlink(MediaRepository::path($ad));
        return $ad;
    }

    /**
     * Todos los anuncios para el panel, en orden, con sus destinos:
     * 'users' (ids) y 'devices' (ids de equipo).
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        $pdo = Database::pdo();
        $rows = $pdo->query('SELECT * FROM sp_ads ORDER BY position, id')->fetchAll();
        $byId = [];
        foreach ($rows as $i => $r) {
            $rows[$i]['users'] = [];
            $rows[$i]['devices'] = [];
            $byId[(int) $r['id']] = $i;
        }
        foreach ($pdo->query('SELECT ad_id, user_id FROM sp_ad_users') as $r) {
            if (isset($byId[(int) $r['ad_id']])) {
                $rows[$byId[(int) $r['ad_id']]]['users'][] = (int) $r['user_id'];
            }
        }
        foreach ($pdo->query('SELECT ad_id, device_id FROM sp_ad_devices') as $r) {
            if (isset($byId[(int) $r['ad_id']])) {
                $rows[$byId[(int) $r['ad_id']]]['devices'][] = (string) $r['device_id'];
            }
        }
        return $rows;
    }

    /**
     * Estado de un anuncio para el panel: active (en curso), paused,
     * scheduled (empieza más adelante) o ended (ya terminó).
     */
    public static function state(array $ad): string
    {
        $now = time();
        if ((int) $ad['active'] !== 1) {
            return 'paused';
        }
        if (!empty($ad['ends_at']) && (int) strtotime($ad['ends_at'] . ' UTC') <= $now) {
            return 'ended';
        }
        if (!empty($ad['starts_at']) && (int) strtotime($ad['starts_at'] . ' UTC') > $now) {
            return 'scheduled';
        }
        return 'active';
    }

    /**
     * Anuncios que le tocan AHORA a un equipo (activos, dentro de fechas y
     * con destino todos / su usuario / él), en orden. La autorización la
     * comprueba quien llama.
     * @return array<int,array<string,mixed>>
     */
    public static function forDevice(string $deviceId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.id, a.kind, a.mime, a.size_bytes, a.sha256, a.seconds, a.every_n
             FROM sp_ads a JOIN sp_devices d ON d.device_id = ?
             WHERE a.active = 1
               AND (a.starts_at IS NULL OR a.starts_at <= ?)
               AND (a.ends_at IS NULL OR a.ends_at > ?)
               AND (a.scope = \'all\'
                    OR (a.scope = \'users\' AND EXISTS (SELECT 1 FROM sp_ad_users au WHERE au.ad_id = a.id AND au.user_id = d.user_id))
                    OR (a.scope = \'devices\' AND EXISTS (SELECT 1 FROM sp_ad_devices ad WHERE ad.ad_id = a.id AND ad.device_id = d.device_id)))
             ORDER BY a.position, a.id'
        );
        $now = Database::now();
        $stmt->execute([$deviceId, $now, $now]);
        return $stmt->fetchAll();
    }

    /** Un anuncio SOLO si le toca ahora a ese equipo (para descargarlo). */
    public static function findForDevice(int $id, string $deviceId): ?array
    {
        foreach (self::forDevice($deviceId) as $row) {
            if ((int) $row['id'] === $id) {
                return self::find($id);
            }
        }
        return null;
    }
}

/**
 * TIRAS (texto) y BANNERS (imagen) de mirror.html (tablas sp_overlays,
 * sp_overlay_users, sp_overlay_devices).
 *
 * Una franja horizontal ARRIBA o ABAJO de la pantalla de mirror.html:
 *  - text  = TIRA: mensaje con colores de fondo y letra, FIJA o
 *            DESPLAZÁNDOSE (velocidad lenta/normal/rápida).
 *  - image = BANNER: imagen (JPG/PNG/WEBP/GIF) que el equipo descarga UNA
 *            vez y guarda en su Cache Storage, como el contenido.
 * Tamaño de la franja: s / m / l (alto relativo a la pantalla).
 * Ajuste del BANNER (image_fit): contain = proporcional (si la franja llega
 * a su alto máximo la imagen queda centrada) | stretch = estirada en
 * horizontal hasta llenar todo el ancho.
 * Dueño:
 *  - USUARIO (user_id): destino "todos mis equipos" (scope all, también
 *    los futuros) o equipos concretos suyos (scope devices). Solo llega a
 *    sus equipos AUTORIZADOS con el contenido habilitado y vigencia activa
 *    (lo mismo que su contenido).
 *  - ADMINISTRADOR (user_id NULL): todos los equipos (all), los equipos
 *    de ciertos usuarios (users) o equipos concretos (devices). Llega a
 *    cualquier equipo AUTORIZADO (como los anuncios).
 * Fechas opcionales (UTC) y pausa (active = 0).
 * ASIGNACIÓN (2026-11-11): se CREAN SIN EQUIPOS (scope none = "sin
 * asignar", no se ve en ninguna pantalla) y después se ASIGNAN con
 * assign() (formulario "Asignar a equipos" de cada tira en los paneles).
 * El TIEMPO DE VISIBILIDAD (permanente o desde/hasta) se elige AL
 * ASIGNAR (assign(): destino + fechas); update() cambia solo el estilo y
 * la pausa.
 * En cada equipo se muestra como mucho UNA por posición (resolve()):
 *  1) la del administrador antes que la del usuario;
 *  2) la más concreta: equipo > usuario > todos;
 *  3) la editada más recientemente.
 * El fichero del banner va a MediaRepository::dir() con prefijo "ov-" y se
 * envía con MediaRepository::send().
 */
final class OverlayRepository
{
    public const KINDS = ['text', 'image'];
    public const PLACES = ['top', 'bottom'];
    public const SIZES = ['s', 'm', 'l'];
    public const MOTIONS = ['static', 'scroll'];
    public const SPEEDS = ['slow', 'normal', 'fast'];
    /** Ajuste de la imagen del banner: proporcional | estirada a todo el ancho. */
    public const FITS = ['contain', 'stretch'];
    /** Destinos: none = sin asignar (al crear); el usuario solo usa none / all / devices. */
    public const SCOPES = ['none', 'all', 'users', 'devices'];
    /** Máximo de caracteres del mensaje de una tira. */
    public const MAX_MESSAGE = 200;
    /** Imágenes admitidas para un banner (solo imágenes, nada de video). */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Tamaño máximo de la imagen de un banner (bytes), config 'overlay_max_mb' (5). */
    public static function maxBytes(): int
    {
        return max(1, (int) (AppConfig::get()['overlay_max_mb'] ?? 5)) * 1048576;
    }

    /** Máximo de tiras/banners por usuario, config 'overlays_per_user' (20). */
    public static function maxPerUser(): int
    {
        return max(1, (int) (AppConfig::get()['overlays_per_user'] ?? 20));
    }

    /** Texto de una línea: UTF-8 (convierte Windows-1252), sin caracteres de control, recortado. */
    public static function cleanText(string $text, int $max): ?string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** Color "#RRGGBB" en mayúsculas, o $fallback si no es válido. */
    public static function cleanColor(string $color, string $fallback): string
    {
        $color = strtoupper(trim($color));
        return preg_match('/^#[0-9A-F]{6}$/', $color) ? $color : $fallback;
    }

    /** Valor dentro de una lista, o el primero de la lista. */
    public static function pick(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : $allowed[0];
    }

    /**
     * Guarda una tira o un banner. Para un banner $tmpFile es la imagen ya
     * validada (tipo y tamaño); para una tira, null.
     * @param array{user:?int,name:string,kind:string,message:?string,bg:string,fg:string,motion:string,
     *              speed:string,size:string,place:string,scope:string,active:bool,starts:?string,
     *              ends:?string,users:int[],devices:string[]} $data
     * @return int Id.
     */
    public static function create(array $data, ?string $tmpFile = null, string $originalName = '', ?string $mime = null): int
    {
        $file = [null, null, null, null, null];
        $target = null;
        if ($data['kind'] === 'image') {
            if ($tmpFile === null || $mime === null || !in_array($mime, self::IMAGE_TYPES, true)) {
                throw new InvalidArgumentException('Banner sin imagen válida');
            }
            [, $ext] = MediaRepository::TYPES[$mime];
            $originalName = basename(str_replace('\\', '/', $originalName));
            if (!mb_check_encoding($originalName, 'UTF-8')) {
                $originalName = mb_convert_encoding($originalName, 'UTF-8', 'Windows-1252');
            }
            $stored = 'ov-' . bin2hex(random_bytes(16)) . '.' . $ext;
            $target = MediaRepository::dir() . '/' . $stored;
            // move_uploaded_file solo con subidas reales; rename en pruebas por CLI
            $moved = is_uploaded_file($tmpFile) ? move_uploaded_file($tmpFile, $target) : rename($tmpFile, $target);
            if (!$moved) {
                throw new RuntimeException('No se pudo guardar el archivo en ' . MediaRepository::dir());
            }
            @chmod($target, 0640);
            $file = [$mime, mb_substr($originalName, 0, 190), $stored, filesize($target), hash_file('sha256', $target)];
        }
        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            $now = Database::now();
            $pdo->prepare(
                'INSERT INTO sp_overlays (user_id, name, kind, message, bg_color, fg_color, motion, speed, band_size,
                   place, image_fit, mime, original_name, stored_name, size_bytes, sha256, scope, active, starts_at,
                   ends_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute(array_merge(
                [$data['user'], $data['name'], $data['kind'], $data['kind'] === 'text' ? $data['message'] : null,
                 $data['bg'], $data['fg'], $data['motion'], $data['speed'], $data['size'], $data['place'],
                 self::pick((string) ($data['fit'] ?? 'contain'), self::FITS)],
                $file,
                [$data['scope'], $data['active'] ? 1 : 0, $data['starts'], $data['ends'], $now, $now]
            ));
            $id = (int) $pdo->lastInsertId();
            self::writeTargets($id, $data['user'], $data['scope'], $data['users'], $data['devices']);
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($target !== null) {
                @unlink($target);
            }
            throw $e;
        }
    }

    /**
     * Cambia estilo y pausa (no la imagen, el tipo, el DESTINO ni las FECHAS:
     * eso es assign()). Claves de $data: las de create() salvo
     * user/scope/users/devices/starts/ends, que aquí no se usan.
     */
    public static function update(int $id, array $data): void
    {
        Database::pdo()->prepare(
            'UPDATE sp_overlays SET name = ?, message = IF(kind = \'text\', ?, NULL), bg_color = ?, fg_color = ?,
               motion = ?, speed = ?, band_size = ?, place = ?, image_fit = ?, active = ?, updated_at = ? WHERE id = ?'
        )->execute([
            $data['name'], $data['message'], $data['bg'], $data['fg'], $data['motion'], $data['speed'],
            $data['size'], $data['place'], self::pick((string) ($data['fit'] ?? 'contain'), self::FITS),
            $data['active'] ? 1 : 0, Database::now(), $id,
        ]);
    }

    /**
     * ASIGNA una tira/banner: none (sin asignar), all (todos los equipos;
     * los del usuario si es suya), users (equipos de ciertos usuarios; solo
     * del administrador) o devices (equipos concretos; si es de un usuario,
     * solo los SUYOS). Un usuario no puede usar "users" (pasa a none).
     * Con la asignación se guarda su TIEMPO DE VISIBILIDAD: $starts / $ends
     * (UTC; null = sin esa fecha; ambos null = permanente). Sin asignar => sin fechas.
     * @param ?int $owner Dueño de la tira (null = administrador).
     */
    public static function assign(int $id, ?int $owner, string $scope, array $users, array $devices,
                                  ?string $starts = null, ?string $ends = null): void
    {
        $scope = self::pick($scope, self::SCOPES);
        if ($owner !== null && $scope === 'users') {
            $scope = 'none';
        }
        if ($scope === 'none') {
            $starts = null;
            $ends = null;
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE sp_overlays SET scope = ?, starts_at = ?, ends_at = ?, updated_at = ? WHERE id = ?')
                ->execute([$scope, $starts, $ends, Database::now(), $id]);
            self::writeTargets($id, $owner, $scope, $users, $devices);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Reescribe usuarios/equipos destino. Las de un usuario solo pueden ir a
     * equipos SUYOS (y nunca a "usuarios").
     */
    private static function writeTargets(int $id, ?int $owner, string $scope, array $users, array $devices): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM sp_overlay_users WHERE overlay_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM sp_overlay_devices WHERE overlay_id = ?')->execute([$id]);
        if ($scope === 'users' && $owner === null) {
            $insert = $pdo->prepare('INSERT IGNORE INTO sp_overlay_users (overlay_id, user_id) SELECT ?, id FROM sp_users WHERE id = ?');
            foreach (array_unique(array_map('intval', $users)) as $uid) {
                $insert->execute([$id, $uid]);
            }
        } elseif ($scope === 'devices') {
            $insert = $owner === null
                ? $pdo->prepare('INSERT IGNORE INTO sp_overlay_devices (overlay_id, device_id) SELECT ?, device_id FROM sp_devices WHERE device_id = ?')
                : $pdo->prepare('INSERT IGNORE INTO sp_overlay_devices (overlay_id, device_id) SELECT ?, device_id FROM sp_devices WHERE device_id = ? AND user_id = ?');
            foreach (array_unique(array_map('strval', $devices)) as $did) {
                $insert->execute($owner === null ? [$id, $did] : [$id, $did, $owner]);
            }
        }
    }

    /** Pausa (false) o reanuda (true). */
    public static function setActive(int $id, bool $active): void
    {
        Database::pdo()->prepare('UPDATE sp_overlays SET active = ?, updated_at = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, Database::now(), $id]);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_overlays WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null Tira/banner de ESE usuario (null si no existe o es de otro). */
    public static function findForUser(int $id, int $userId): ?array
    {
        $row = self::find($id);
        return $row !== null && (int) ($row['user_id'] ?? 0) === $userId ? $row : null;
    }

    /**
     * Borra una tira/banner (fila, destinos y fichero). Los equipos la
     * quitan en su próxima consulta.
     * @return array<string,mixed>|null La borrada (null si no existía).
     */
    public static function delete(int $id): ?array
    {
        $o = self::find($id);
        if ($o === null) {
            return null;
        }
        Database::pdo()->prepare('DELETE FROM sp_overlays WHERE id = ?')->execute([$id]);
        if (!empty($o['stored_name'])) {
            @unlink(MediaRepository::path($o));
        }
        return $o;
    }

    /** Borra los ficheros de los banners de un usuario (las filas se van con él: ON DELETE CASCADE). */
    public static function deleteFilesForUser(int $userId): void
    {
        $stmt = Database::pdo()->prepare('SELECT stored_name FROM sp_overlays WHERE user_id = ? AND stored_name IS NOT NULL');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $o) {
            @unlink(MediaRepository::path($o));
        }
    }

    /** @return int Tiras/banners de un usuario. */
    public static function countForUser(int $userId): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM sp_overlays WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lista para un panel, con sus destinos ('users' ids, 'devices' ids):
     * $userId = las de ese usuario; null = las del administrador.
     * $withUsers = true (solo administrador): también las de TODOS los usuarios.
     * Orden: del administrador primero, luego por empresa; dentro, más recientes primero.
     * @return array<int,array<string,mixed>>
     */
    public static function all(?int $userId, bool $withUsers = false): array
    {
        $pdo = Database::pdo();
        $where = $userId !== null ? 'o.user_id = ?' : ($withUsers ? '1 = 1' : 'o.user_id IS NULL');
        $stmt = $pdo->prepare(
            'SELECT o.*, u.company AS user_company FROM sp_overlays o LEFT JOIN sp_users u ON u.id = o.user_id
             WHERE ' . $where . ' ORDER BY (o.user_id IS NOT NULL), u.company, o.updated_at DESC, o.id DESC LIMIT 1000'
        );
        $stmt->execute($userId !== null ? [$userId] : []);
        $rows = $stmt->fetchAll();
        $byId = [];
        foreach ($rows as $i => $r) {
            $rows[$i]['users'] = [];
            $rows[$i]['devices'] = [];
            $byId[(int) $r['id']] = $i;
        }
        if (!$byId) {
            return [];
        }
        $in = implode(',', array_keys($byId)); // solo enteros
        foreach ($pdo->query('SELECT overlay_id, user_id FROM sp_overlay_users WHERE overlay_id IN (' . $in . ')') as $r) {
            $rows[$byId[(int) $r['overlay_id']]]['users'][] = (int) $r['user_id'];
        }
        foreach ($pdo->query('SELECT overlay_id, device_id FROM sp_overlay_devices WHERE overlay_id IN (' . $in . ')') as $r) {
            $rows[$byId[(int) $r['overlay_id']]]['devices'][] = (string) $r['device_id'];
        }
        return $rows;
    }

    /** Estado para el panel: active (en curso) | paused | scheduled | ended (como los anuncios). */
    public static function state(array $o): string
    {
        return AdRepository::state($o);
    }

    /**
     * Lo que muestra AHORA un equipo: como mucho UNA por posición (ver la
     * cabecera de la clase). $userAllowed = el equipo puede mostrar el
     * contenido de su usuario (autorizado + permiso + vigencia); si no,
     * solo cuentan las del administrador. La autorización la comprueba quien llama.
     * @return array<string,array<string,mixed>> ['top' => fila, 'bottom' => fila] (las que haya).
     */
    public static function resolve(string $deviceId, bool $userAllowed): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT o.*,
               CASE o.scope WHEN \'devices\' THEN 3 WHEN \'users\' THEN 2 ELSE 1 END AS specificity
             FROM sp_overlays o JOIN sp_devices d ON d.device_id = ?
             WHERE o.active = 1
               AND (o.starts_at IS NULL OR o.starts_at <= ?)
               AND (o.ends_at IS NULL OR o.ends_at > ?)
               AND (
                 (o.user_id IS NULL AND (o.scope = \'all\'
                    OR (o.scope = \'users\' AND EXISTS (SELECT 1 FROM sp_overlay_users ou WHERE ou.overlay_id = o.id AND ou.user_id = d.user_id))
                    OR (o.scope = \'devices\' AND EXISTS (SELECT 1 FROM sp_overlay_devices od WHERE od.overlay_id = o.id AND od.device_id = d.device_id))))
                 OR (? = 1 AND o.user_id IS NOT NULL AND o.user_id = d.user_id AND (o.scope = \'all\'
                    OR (o.scope = \'devices\' AND EXISTS (SELECT 1 FROM sp_overlay_devices od2 WHERE od2.overlay_id = o.id AND od2.device_id = d.device_id))))
               )
             ORDER BY (o.user_id IS NULL) DESC, specificity DESC, o.updated_at DESC, o.id DESC'
        );
        $now = Database::now();
        $stmt->execute([$deviceId, $now, $now, $userAllowed ? 1 : 0]);
        $out = [];
        foreach ($stmt as $row) {
            if (!isset($out[$row['place']])) {
                $out[$row['place']] = $row;
            }
        }
        return $out;
    }

    /**
     * Lista para api/media.php (mirror.html): una entrada por posición.
     * @return array<int,array<string,mixed>>
     */
    public static function payload(string $deviceId, bool $userAllowed): array
    {
        $out = [];
        foreach (self::resolve($deviceId, $userAllowed) as $o) {
            $item = [
                'id' => (int) $o['id'],
                'place' => $o['place'],
                'owner' => $o['user_id'] === null ? 'admin' : 'user',
                'kind' => $o['kind'],
                'band' => $o['band_size'],
                'bg' => $o['bg_color'],
                // Segundos hasta el fin (null = sin fin): el equipo la quita a su hora aunque no tenga internet
                'endsIn' => $o['ends_at'] ? max(0, (int) strtotime($o['ends_at'] . ' UTC') - time()) : null,
            ];
            if ($o['kind'] === 'text') {
                $item += ['text' => (string) $o['message'], 'fg' => $o['fg_color'], 'motion' => $o['motion'], 'speed' => $o['speed']];
            } else {
                // fit: contain (proporcional) | stretch (estirada a todo el ancho)
                $item += ['mime' => $o['mime'], 'size' => (int) $o['size_bytes'], 'sha256' => $o['sha256'],
                    'fit' => $o['image_fit'] ?? 'contain'];
            }
            $out[] = $item;
        }
        return $out;
    }

    /** Un banner SOLO si se muestra ahora en ese equipo (para descargarlo). */
    public static function findForDevice(int $id, string $deviceId, bool $userAllowed): ?array
    {
        foreach (self::resolve($deviceId, $userAllowed) as $o) {
            if ((int) $o['id'] === $id && $o['kind'] === 'image') {
                return $o;
            }
        }
        return null;
    }
}

/**
 * LIGAS del administrador (tablas sp_league_types, sp_leagues, sp_user_leagues).
 *  - Tipo de liga: code (lo que guarda la TV en smartpizarra.league,
 *    p. ej. "mlb") + name.
 *  - Liga = nombre + tipo + contenido (lista de códigos "LIDOM,MLB,KO").
 *  - El administrador asigna 0..N ligas a cada usuario; el USUARIO elige
 *    para cada uno de sus equipos "Sin liga" o UNA de ellas
 *    (sp_devices.league_id). Sus equipos autorizados la reciben en
 *    api/device.php y api/media.php (devicePayload) y la guardan en
 *    smartpizarra.league (código del tipo) y smartpizarra.leagues
 *    (códigos); sin liga => "" en los dos.
 */
final class LeagueRepository
{
    /** Código reservado por la TV para "todas las ligas". */
    public const RESERVED_CODE = 'todos';
    /** Máximo de códigos por liga. */
    public const MAX_CODES = 100;

    /** Código de tipo válido (minúsculas, cifras, _ y -; 1-20) o null. */
    public static function cleanTypeCode(string $code): ?string
    {
        $code = strtolower(trim($code));
        return preg_match('/^[a-z0-9_-]{1,20}$/', $code) && $code !== self::RESERVED_CODE ? $code : null;
    }

    /** Nombre (1..$max caracteres) sin saltos de línea ni caracteres de control, o null. */
    public static function cleanName(string $name, int $max): ?string
    {
        // Si no llega en UTF-8 (algunos clientes de Windows) se convierte, para no perder acentos
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'Windows-1252');
        }
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        return $name !== '' && mb_strlen($name) <= $max ? $name : null;
    }

    /**
     * Contenido de una liga: códigos separados por coma, espacio o salto de
     * línea => "LIDOM,MLB,KO" (mayúsculas, sin repetir, en el orden escrito).
     * @return array{0:?string,1:string[]} [códigos o null si no hay ninguno válido, códigos rechazados]
     */
    public static function cleanCodes(string $text): array
    {
        $ok = [];
        $bad = [];
        foreach (preg_split('/[\s,;]+/u', strtoupper($text)) ?: [] as $c) {
            if ($c === '') {
                continue;
            }
            if (preg_match('/^[A-Z0-9_]{1,20}$/', $c)) {
                $ok[$c] = true;
            } else {
                $bad[] = $c;
            }
        }
        $codes = array_slice(array_keys($ok), 0, self::MAX_CODES);
        return [$codes ? implode(',', $codes) : null, $bad];
    }

    /* ------------------------------ Tipos ------------------------------ */

    /** @return array<int,array<string,mixed>> Tipos en orden, con 'leagues' = nº de ligas. */
    public static function types(): array
    {
        return Database::pdo()->query(
            'SELECT t.*, (SELECT COUNT(*) FROM sp_leagues l WHERE l.type_id = t.id) AS leagues
             FROM sp_league_types t ORDER BY t.position, t.id'
        )->fetchAll();
    }

    /** @return bool false si el código ya existe. */
    public static function createType(string $code, string $name): bool
    {
        $pdo = Database::pdo();
        $exists = $pdo->prepare('SELECT COUNT(*) FROM sp_league_types WHERE code = ?');
        $exists->execute([$code]);
        if ((int) $exists->fetchColumn() > 0) {
            return false;
        }
        $next = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM sp_league_types')->fetchColumn();
        $pdo->prepare('INSERT INTO sp_league_types (code, name, position, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$code, $name, $next, Database::now()]);
        return true;
    }

    /** Cambia el nombre (el código no cambia: es lo que guardan las TV). */
    public static function renameType(int $id, string $name): void
    {
        Database::pdo()->prepare('UPDATE sp_league_types SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    /** @return array<string,mixed>|null */
    public static function findType(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_league_types WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return bool false si el tipo tiene ligas (hay que borrarlas o cambiarlas de tipo antes). */
    public static function deleteType(int $id): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM sp_leagues WHERE type_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            return false;
        }
        Database::pdo()->prepare('DELETE FROM sp_league_types WHERE id = ?')->execute([$id]);
        return true;
    }

    /** Mueve un tipo un puesto antes (-1) o después (+1): orden de los botones en la TV. */
    public static function moveType(int $id, int $direction): void
    {
        $list = array_map(static fn($r) => (int) $r['id'], self::types());
        $i = array_search($id, $list, true);
        $j = $i === false ? false : $i + ($direction < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= count($list)) {
            return;
        }
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        $stmt = Database::pdo()->prepare('UPDATE sp_league_types SET position = ? WHERE id = ?');
        foreach ($list as $pos => $typeId) {
            $stmt->execute([$pos + 1, $typeId]);
        }
    }

    /* ------------------------------ Ligas ------------------------------ */

    /**
     * Ligas (por tipo y nombre) con su tipo y los usuarios que la tienen.
     * @return array<int,array<string,mixed>> Filas + type_code, type_name y 'users' (lista de [id, company]).
     */
    public static function leagues(): array
    {
        $rows = Database::pdo()->query(
            'SELECT l.*, t.code AS type_code, t.name AS type_name
             FROM sp_leagues l JOIN sp_league_types t ON t.id = l.type_id
             ORDER BY t.position, t.id, l.name, l.id'
        )->fetchAll();
        $byId = [];
        foreach ($rows as $i => $r) {
            $rows[$i]['users'] = [];
            $byId[(int) $r['id']] = $i;
        }
        $users = Database::pdo()->query(
            'SELECT ul.league_id, u.id, u.company FROM sp_user_leagues ul
             JOIN sp_users u ON u.id = ul.user_id ORDER BY u.company, u.id'
        );
        foreach ($users as $u) {
            if (isset($byId[(int) $u['league_id']])) {
                $rows[$byId[(int) $u['league_id']]]['users'][] = ['id' => (int) $u['id'], 'company' => (string) $u['company']];
            }
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_leagues WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return int Id de la liga nueva. */
    public static function create(int $typeId, string $name, string $codes): int
    {
        $now = Database::now();
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO sp_leagues (type_id, name, codes, position, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)')
            ->execute([$typeId, $name, $codes, $now, $now]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, int $typeId, string $name, string $codes): void
    {
        Database::pdo()->prepare('UPDATE sp_leagues SET type_id = ?, name = ?, codes = ?, updated_at = ? WHERE id = ?')
            ->execute([$typeId, $name, $codes, Database::now(), $id]);
    }

    /** Borra la liga (y sus asignaciones, por la FK). */
    public static function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM sp_leagues WHERE id = ?')->execute([$id]);
    }

    /* -------------------------- Asignaciones -------------------------- */

    /** @return int[] Ids de las ligas de un usuario. */
    public static function idsForUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare('SELECT league_id FROM sp_user_leagues WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Ligas de un usuario con su tipo (para /cuenta/, el panel y las TV).
     * @return array<int,array<string,mixed>>
     */
    public static function forUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.*, t.code AS type_code, t.name AS type_name
             FROM sp_user_leagues ul JOIN sp_leagues l ON l.id = ul.league_id
             JOIN sp_league_types t ON t.id = l.type_id
             WHERE ul.user_id = ? ORDER BY t.position, t.id, l.name, l.id'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Reemplaza las ligas de un usuario (las que no existen se ignoran).
     * Sus equipos que tenían una liga que ya no tiene pasan a "Sin liga".
     * @param int[] $leagueIds
     */
    public static function setForUser(int $userId, array $leagueIds): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM sp_user_leagues WHERE user_id = ?')->execute([$userId]);
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO sp_user_leagues (user_id, league_id) SELECT ?, id FROM sp_leagues WHERE id = ?'
            );
            foreach (array_unique(array_map('intval', $leagueIds)) as $leagueId) {
                $insert->execute([$userId, $leagueId]);
            }
            // Sus equipos con una liga que ya no tiene asignada => "Sin liga"
            $pdo->prepare(
                'UPDATE sp_devices d SET d.league_id = NULL
                 WHERE d.user_id = ? AND d.league_id IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM sp_user_leagues ul WHERE ul.user_id = d.user_id AND ul.league_id = d.league_id)'
            )->execute([$userId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /* ------------- Liga de cada equipo (la elige el usuario: una o ninguna) ------------- */

    /**
     * Pone la liga de un equipo (null = "Sin liga"). Solo vale una liga
     * asignada al usuario dueño del equipo.
     * @return bool false si la liga no es del usuario del equipo.
     */
    public static function setDeviceLeague(string $deviceId, ?int $leagueId): bool
    {
        $pdo = Database::pdo();
        if ($leagueId === null) {
            $pdo->prepare('UPDATE sp_devices SET league_id = NULL WHERE device_id = ?')->execute([$deviceId]);
            return true;
        }
        $stmt = $pdo->prepare(
            'UPDATE sp_devices d JOIN sp_user_leagues ul ON ul.user_id = d.user_id AND ul.league_id = ?
             SET d.league_id = ul.league_id WHERE d.device_id = ?'
        );
        $stmt->execute([$leagueId, $deviceId]);
        // rowCount = 0 también si ya la tenía: se comprueba de nuevo
        $check = $pdo->prepare('SELECT league_id FROM sp_devices WHERE device_id = ?');
        $check->execute([$deviceId]);
        return (int) $check->fetchColumn() === $leagueId;
    }

    /**
     * Liga que usa AHORA un equipo, con su tipo, o null ("Sin liga"). Si la
     * liga ya no es de su usuario (el administrador se la quitó) => null.
     * @return array<string,mixed>|null Fila de sp_leagues + type_code, type_name.
     */
    public static function deviceLeague(array $device): ?array
    {
        $leagueId = (int) ($device['league_id'] ?? 0);
        $userId = (int) ($device['user_id'] ?? 0);
        if ($leagueId <= 0 || $userId <= 0) {
            return null;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT l.*, t.code AS type_code, t.name AS type_name
             FROM sp_leagues l JOIN sp_league_types t ON t.id = l.type_id
             JOIN sp_user_leagues ul ON ul.league_id = l.id AND ul.user_id = ?
             WHERE l.id = ?'
        );
        $stmt->execute([$userId, $leagueId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Lo que recibe la TV (campo "deviceLeague" de api/device.php y
     * api/media.php): la liga del equipo o null = "Sin liga" (la TV deja
     * smartpizarra.league y smartpizarra.leagues en "").
     * @return array{code:string,label:string,name:string,values:string}|null
     *         code = código del tipo (smartpizarra.league), values = códigos (smartpizarra.leagues).
     */
    public static function devicePayload(array $device): ?array
    {
        $l = self::deviceLeague($device);
        return $l === null ? null : [
            'code' => (string) $l['type_code'],
            'label' => (string) $l['type_name'],
            'name' => (string) $l['name'],
            'values' => (string) $l['codes'],
        ];
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

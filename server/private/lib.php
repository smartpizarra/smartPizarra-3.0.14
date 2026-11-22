<?php
// Versión del archivo: 1.0.7 (2026-11-22)
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
        // Pausar el contenido (como las tiras y banners)
        if (!in_array('active', $mediaCols, true)) {
            return 'Falta la pausa del contenido (columna sp_media.active): ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-12-media-active.sql.';
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
        // Gestión de rutas (URL de contenido por usuario)
        if (!in_array('sp_routes', $tables, true) || !in_array('route_id', $userCols, true)) {
            return 'Falta la gestión de rutas (tabla sp_routes / columna sp_users.route_id): ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-12-routes.sql.';
        }
        // Dirección MAC de cada equipo (la envía loading.html)
        if (!in_array('mac', $cols, true) || !in_array('mac_from', $cols, true)) {
            return 'Falta la dirección MAC de los equipos (columnas sp_devices.mac / mac_at / mac_from): '
                . 'ejecuta en phpMyAdmin (pestaña SQL) server/migrations/2026-11-12-device-mac.sql.';
        }
        // Límite de archivos de contenido por usuario
        if (!in_array('max_files', $userCols, true)) {
            return 'Falta el límite de archivos por usuario (columna sp_users.max_files): ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-12-user-max-files.sql.';
        }
        // Cola de descargas con turnos (muchas pantallas)
        if (!in_array('sp_download_queue', $tables, true) || !in_array('sp_kv', $tables, true)) {
            return 'Falta la cola de descargas (tabla sp_download_queue): ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-13-download-queue.sql.';
        }
        // Buzón de mensajes administrador <-> usuario
        if (!in_array('sp_messages', $tables, true)) {
            return 'Falta el buzón de mensajes (tabla sp_messages): ejecuta en phpMyAdmin (pestaña SQL) '
                . 'server/migrations/2026-11-12-messages.sql.';
        }
        // Alias y notas del administrador por equipo
        if (!in_array('admin_alias', $cols, true) || !in_array('admin_notes', $cols, true)) {
            return 'Faltan el alias y las notas del administrador (columnas sp_devices.admin_alias / admin_notes): '
                . 'ejecuta en phpMyAdmin (pestaña SQL) server/migrations/2026-11-12-device-admin-notes.sql.';
        }
        // Perfiles de equipo (marca, modelo, MAC del case, instalación)
        if (!in_array('sp_device_profiles', $tables, true) || !in_array('first_authorized_at', $cols, true)) {
            return 'Faltan los perfiles de equipo (tabla sp_device_profiles / columna first_authorized_at): '
                . 'ejecuta en phpMyAdmin (pestaña SQL) server/migrations/2026-11-12-device-profiles.sql.';
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

    /** touch()/markOnline() solo escriben si la última conexión tiene más de estos segundos. */
    public const TOUCH_EVERY = 30;

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

    /* ------------------- DIRECCIÓN MAC (2026-11-12) -------------------
     * La lee la app (Kodular), la pasa a loading.html con setMac() y
     * loading.html la envía a api/device.php (event=boot o event=mac).
     * Una MAC está en UN SOLO equipo a la vez (índice UNIQUE sp_devices.mac):
     * si la envía otro equipo (p. ej. la misma TV tras un restablecimiento
     * de fábrica, que genera un ID nuevo), se le QUITA al anterior y se
     * asigna al nuevo; mac_from guarda el ID anterior. */

    /** MAC por defecto de loading.html ("sin MAC"): nunca se guarda. */
    public const NO_MAC = 'FF:FF:FF:FF:FF:FF';
    /** MACs que no identifican un equipo (sin dato / falsas de Android). */
    private const FAKE_MACS = [self::NO_MAC, '02:00:00:00:00:00', '00:00:00:00:00:00'];

    /**
     * Normaliza una MAC ("e8-bb-3e-c8-fb-5a", "e8bb.3ec8.fb5a"...) a
     * "E8:BB:3E:C8:FB:5A".
     * @return string|null null si no es válida o es una MAC "sin dato".
     */
    public static function cleanMac(string $value): ?string
    {
        $hex = strtoupper((string) preg_replace('/[\s:.\-]/', '', $value));
        if (!preg_match('/^[0-9A-F]{12}$/', $hex)) {
            return null;
        }
        $mac = implode(':', str_split($hex, 2));
        return in_array($mac, self::FAKE_MACS, true) ? null : $mac;
    }

    /**
     * Asigna una MAC (ya normalizada con cleanMac) a un equipo, quitándosela
     * a cualquier otro que la tuviera.
     * @return string|null ID del equipo que la tenía antes (se le quitó), o null.
     */
    public static function assignMac(string $id, string $mac): ?string
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT device_id FROM sp_devices WHERE mac = ? AND device_id <> ? FOR UPDATE');
            $stmt->execute([$mac, $id]);
            $previous = $stmt->fetchColumn();
            $previous = $previous === false ? null : (string) $previous;
            if ($previous !== null) {
                $pdo->prepare('UPDATE sp_devices SET mac = NULL, mac_at = NULL WHERE device_id = ?')->execute([$previous]);
            }
            // Solo si cambia (la misma MAC en cada arranque no toca nada)
            $pdo->prepare(
                'UPDATE sp_devices SET mac = ?, mac_at = ?, mac_from = ? WHERE device_id = ? AND (mac IS NULL OR mac <> ?)'
            )->execute([$mac, Database::now(), $previous, $id, $mac]);
            $pdo->commit();
            return $previous;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
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
        // MENOS ESCRITURAS (2026-11-13): con cientos de pantallas consultando cada
        // minuto, solo se escribe si pasaron TOUCH_EVERY s desde la anterior o si
        // cambió la versión o la IP ("última conexión" sigue siendo exacta al minuto).
        $now = Database::now();
        $limit = gmdate('Y-m-d H:i:s', time() - self::TOUCH_EVERY);
        Database::pdo()->prepare(
            'UPDATE sp_devices SET last_seen_at = ?, online_at = ?, app_version = COALESCE(?, app_version), last_ip = ?
             WHERE device_id = ?
               AND (last_seen_at < ? OR online_at IS NULL OR online_at < ? OR NOT (last_ip <=> ?)
                    OR (? IS NOT NULL AND NOT (app_version <=> ?)))'
        )->execute([$now, $now, $version, $ip, $id, $limit, $limit, $ip, $version, $version]);
    }

    /**
     * Señal "online" de la app nativa Kodular (api/ping.php).
     * Solo toca online_at (y la IP/versión): last_seen_at sigue siendo la
     * última consulta de las páginas HTML (que también refrescan online_at,
     * ver touch()).
     */
    public static function markOnline(string $id, ?string $version, string $ip): void
    {
        // Igual que touch(): solo si pasaron TOUCH_EVERY s o cambió la versión o la IP
        $limit = gmdate('Y-m-d H:i:s', time() - self::TOUCH_EVERY);
        Database::pdo()->prepare(
            'UPDATE sp_devices SET online_at = ?, app_version = COALESCE(?, app_version), last_ip = ?
             WHERE device_id = ?
               AND (online_at IS NULL OR online_at < ? OR NOT (last_ip <=> ?)
                    OR (? IS NOT NULL AND NOT (app_version <=> ?)))'
        )->execute([Database::now(), $version, $ip, $id, $limit, $ip, $version, $version]);
    }

    /** Cambia el estado desde el panel. */
    public static function setStatus(string $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $now = Database::now();
        if ($status === 'authorized') {
            // Autorizar: además se anota la PRIMERA autorización (first_authorized_at)
            $stmt = Database::pdo()->prepare(
                'UPDATE sp_devices SET status = \'authorized\', status_at = ?,
                   first_authorized_at = COALESCE(first_authorized_at, ?)
                 WHERE device_id = ?'
            );
            $stmt->execute([$now, $now, $id]);
        } else {
            // Revocar / pendiente: la consulta de siempre. (2026-11-12: la versión con
            // IF(? = 'authorized', ...) es la sospechosa del error al revocar en Hostinger:
            // sin parámetros dentro de IF, compatible con MySQL y MariaDB.)
            $stmt = Database::pdo()->prepare('UPDATE sp_devices SET status = ?, status_at = ? WHERE device_id = ?');
            $stmt->execute([$status, $now, $id]);
        }
        return $stmt->rowCount() > 0;
    }

    /**
     * ¿Necesita PERFIL para autorizarse? Sí si NUNCA se autorizó y no tiene
     * perfil asignado (ver ProfileRepository). Los ya autorizados alguna vez
     * se pueden volver a autorizar sin perfil.
     * @param array<string,mixed> $device Fila de sp_devices (con profile_id si viene de search()).
     */
    public static function needsProfile(array $device): bool
    {
        if (!empty($device['first_authorized_at'])) {
            return false;
        }
        if (array_key_exists('profile_id', $device)) {
            return empty($device['profile_id']);
        }
        return ProfileRepository::forDevice((string) $device['device_id']) === null;
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
        // Solo los que ya se autorizaron alguna vez o tienen PERFIL (la
        // primera autorización exige perfil); los demás se quedan como están.
        $now = Database::now();
        $stmt = Database::pdo()->prepare(
            'UPDATE sp_devices d SET d.status = \'authorized\', d.status_at = ?,
               d.first_authorized_at = COALESCE(d.first_authorized_at, ?)
             WHERE d.user_id = ? AND d.status <> \'authorized\'
               AND (d.first_authorized_at IS NOT NULL
                    OR EXISTS (SELECT 1 FROM sp_device_profiles p WHERE p.device_id = d.device_id))'
        );
        $stmt->execute([$now, $now, $userId]);
        return $stmt->rowCount();
    }

    /** @return int Equipos de un usuario sin autorizar que NO se pueden autorizar porque les falta perfil. */
    public static function countNeedingProfile(int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM sp_devices d
             WHERE d.user_id = ? AND d.status <> \'authorized\' AND d.first_authorized_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM sp_device_profiles p WHERE p.device_id = d.device_id)'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
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

    /** Máximo de caracteres de las notas del administrador. */
    public const ADMIN_NOTES_MAX = 2000;

    /**
     * ALIAS ADICIONAL y NOTAS del ADMINISTRADOR (2026-11-12). Solo los ve y
     * edita el panel del administrador: /cuenta/ y las TV nunca los muestran.
     * Vacío = NULL. El alias se limpia de saltos de línea (máx. 100); las
     * notas conservan los saltos de línea (máx. ADMIN_NOTES_MAX).
     */
    public static function setAdminInfo(string $id, string $alias, string $notes): void
    {
        $clean = static function (string $v): string {
            return mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
        };
        $alias = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $clean($alias))), 0, 100);
        $notes = str_replace(["\r\n", "\r"], "\n", $clean($notes));
        $notes = mb_substr(trim((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $notes)), 0, self::ADMIN_NOTES_MAX);
        Database::pdo()->prepare('UPDATE sp_devices SET admin_alias = ?, admin_notes = ? WHERE device_id = ?')
            ->execute([$alias === '' ? null : $alias, $notes === '' ? null : $notes, $id]);
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
    public static function search(?string $status, string $search, ?int $userId = null, bool $admin = false,
                                  int $limit = 500, int $offset = 0): array
    {
        // $admin = true (SOLO el panel del administrador): la búsqueda también
        // mira el ALIAS DEL ADMINISTRADOR. /cuenta/ llama con false: el usuario
        // no puede encontrar (ni adivinar) un equipo por ese alias.
        // $limit / $offset: PÁGINA de resultados (el panel muestra 50 por página).
        [$where, $args] = self::searchWhere($status, $search, $userId, $admin);
        // boots / last_boot_at: resumen del historial de arranques (usa idx_device_time)
        // profile_*: PERFIL del equipo (marca, modelo, MAC del case, instalación) o NULL
        $sql = 'SELECT d.*, u.company AS user_company, u.email AS user_email, u.content_until AS user_content_until,
                  p.id AS profile_id, p.brand AS profile_brand, p.model AS profile_model,
                  p.case_mac AS profile_case_mac, p.installed_on AS profile_installed_on,
                  -- mac_from_exists: el equipo anterior ("antes en SP-…") sigue en la base; si se
                  -- eliminó, el panel muestra el ID sin enlace (el enlace llevaba a una lista vacía)
                  (d.mac_from IS NOT NULL AND EXISTS (SELECT 1 FROM sp_devices o WHERE o.device_id = d.mac_from)) AS mac_from_exists,
                  (SELECT COUNT(*) FROM sp_boots b WHERE b.device_id = d.device_id) AS boots,
                  (SELECT MAX(b.booted_at) FROM sp_boots b WHERE b.device_id = d.device_id) AS last_boot_at
                FROM sp_devices d LEFT JOIN sp_users u ON u.id = d.user_id
                LEFT JOIN sp_device_profiles p ON p.device_id = d.device_id'
            . $where
            . ' ORDER BY (d.status = \'pending\') DESC, d.last_seen_at DESC, d.device_id'
            . ' LIMIT ' . max(1, min(5000, $limit)) . ' OFFSET ' . max(0, $offset);
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /** @return int Cuántos equipos cumplen los mismos filtros que search() (para paginar). */
    public static function searchCount(?string $status, string $search, ?int $userId = null, bool $admin = false): int
    {
        [$where, $args] = self::searchWhere($status, $search, $userId, $admin);
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM sp_devices d LEFT JOIN sp_users u ON u.id = d.user_id
             LEFT JOIN sp_device_profiles p ON p.device_id = d.device_id' . $where
        );
        $stmt->execute($args);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Filtros comunes de search() y searchCount().
     * @return array{0:string,1:array<int,mixed>} [" WHERE ..." o "", argumentos]
     */
    private static function searchWhere(?string $status, string $search, ?int $userId, bool $admin): array
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
            // También la MARCA y el MODELO de su perfil
            $match = '(d.device_id LIKE ? OR d.label LIKE ? OR u.company LIKE ? OR u.email LIKE ? OR p.brand LIKE ? OR p.model LIKE ?';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            array_push($args, $like, $like, $like, $like, $like, $like);
            if ($admin) {
                $match .= ' OR d.admin_alias LIKE ?';
                $args[] = $like;
            }
            // Búsqueda por MAC (la que envía la TV y la del CASE de su perfil):
            // sin importar separadores (":", "-", ".") ni mayúsculas; vale un
            // trozo ("FB:5A", "e8bb3e"...).
            $hex = strtoupper((string) preg_replace('/[\s:.\-]/', '', $search));
            if (strlen($hex) >= 2 && ctype_xdigit($hex)) {
                $match .= " OR REPLACE(d.mac, ':', '') LIKE ? OR REPLACE(p.case_mac, ':', '') LIKE ?";
                array_push($args, '%' . $hex . '%', '%' . $hex . '%');
            }
            $where[] = $match . ')';
        }
        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $args];
    }

    /**
     * @param int|null $userId Igual que en search(): null todos, 0 sin asignar, >0 ese usuario.
     * @return array<string,int> Cantidad de equipos por estado (+ "all").
     */
    public static function counts(?int $userId = null, string $search = '', bool $admin = false): array
    {
        $out = ['pending' => 0, 'authorized' => 0, 'revoked' => 0, 'all' => 0];
        if ($search !== '') {
            // Con BÚSQUEDA, las pestañas cuentan lo mismo que mostraría la lista
            // (antes contaban todos los equipos y la lista salía vacía con "Pendientes (2)")
            foreach (['pending', 'authorized', 'revoked'] as $st) {
                $out[$st] = self::searchCount($st, $search, $userId, $admin);
                $out['all'] += $out[$st];
            }
            return $out;
        }
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
    /** Límite de archivos por defecto de un usuario nuevo (columna sp_users.max_files). */
    public const DEFAULT_MAX_FILES = 5;
    /** Máximo que el administrador puede poner. */
    public const MAX_FILES_LIMIT = 1000;

    /** @return int Cuántos archivos de contenido puede subir el usuario (su límite). */
    public static function maxFiles(array $user): int
    {
        return isset($user['max_files']) ? (int) $user['max_files'] : self::DEFAULT_MAX_FILES;
    }

    /**
     * Cambia el LÍMITE de archivos de un usuario (lo ya subido no se toca).
     * @return bool false si el valor está fuera de 0..MAX_FILES_LIMIT.
     */
    public static function setMaxFiles(int $id, int $max): bool
    {
        if ($max < 0 || $max > self::MAX_FILES_LIMIT) {
            return false;
        }
        Database::pdo()->prepare('UPDATE sp_users SET max_files = ? WHERE id = ?')->execute([$max, $id]);
        return true;
    }

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

    /*
     * MINIATURAS (2026-11-17). Las listas del panel y de /cuenta/ mostraban cada imagen
     * con el ARCHIVO ORIGINAL (fotos de 4-12 MB para un recuadro de 160x90): con la
     * conexión lenta de la CDN la página se quedaba "cargando". Ahora usan una JPG
     * pequeña (máx. THUMB_W x THUMB_H) creada la primera vez que se pide y guardada en
     * media/thumbs/; al pulsar se sigue abriendo el original.
     */
    public const THUMB_W = 320;
    public const THUMB_H = 180;

    /** @return string Ruta de la miniatura (JPG) de un archivo guardado. */
    public static function thumbPath(array $media): string
    {
        return self::dir() . '/thumbs/' . pathinfo(basename((string) $media['stored_name']), PATHINFO_FILENAME) . '.jpg';
    }

    /** Borra el archivo y su miniatura (si la hay). */
    public static function deleteFiles(array $media): void
    {
        @unlink(self::path($media));
        @unlink(self::thumbPath($media));
    }

    /**
     * Envía la miniatura de una imagen (la crea si falta). Si no es imagen o no se puede
     * crear (sin GD, formato raro), envía el original como antes.
     */
    public static function sendThumb(array $media): never
    {
        $thumb = self::thumbPath($media);
        if (($media['kind'] ?? '') !== 'image' || (!is_file($thumb) && !self::makeThumb(self::path($media), $thumb))) {
            self::send($media);
        }
        self::releaseSession();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($thumb));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=31536000, immutable');
        readfile($thumb);
        exit;
    }

    /** Crea la miniatura JPG de $src en $dst (respeta el giro EXIF de las fotos del móvil). */
    private static function makeThumb(string $src, string $dst): bool
    {
        $img = self::openImage($src);
        if ($img === null) {
            return false;
        }
        $out = self::fitImage($img, self::THUMB_W, self::THUMB_H, true); // PNG transparente => fondo negro (como .thumb)
        imagedestroy($img);
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            imagedestroy($out);
            return false;
        }
        $ok = self::writeImage($out, 'image/jpeg', $dst, 78);
        imagedestroy($out);
        return $ok;
    }

    /*
     * IMÁGENES DEMASIADO GRANDES (2026-11-17). Los usuarios subían fotos de 5698x10121
     * (58 Mpx, 2-12 MB): la TV (1 GB de RAM, pantalla 720x1280) necesita ~230 MB para
     * mostrar cada una. Al subirlas se reducen a MAX_IMAGE_LONG x MAX_IMAGE_SHORT (lado
     * largo x lado corto: 1080x1920 en vertical, 1920x1080 en horizontal), con el mismo
     * formato y el giro EXIF ya aplicado. Los GIF no se tocan (pueden ser animados).
     */
    public const MAX_IMAGE_LONG = 1920;
    public const MAX_IMAGE_SHORT = 1080;

    /**
     * Reduce en su sitio una imagen guardada si supera MAX_IMAGE_LONG x MAX_IMAGE_SHORT.
     * Si no se puede (sin GD, formato raro) la deja como está: nunca bloquea una subida.
     * @return bool true si el archivo cambió (el llamador debe recalcular tamaño y sha256).
     */
    public static function shrinkImage(string $path, string $mime): bool
    {
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return false;
        }
        $info = @getimagesize($path);
        if ($info === false) {
            return false;
        }
        $long = max($info[0], $info[1]);
        $short = min($info[0], $info[1]);
        if ($long <= self::MAX_IMAGE_LONG && $short <= self::MAX_IMAGE_SHORT) {
            return false; // ya cabe: no se re-comprime (no se pierde calidad)
        }
        $img = self::openImage($path);
        if ($img === null) {
            return false;
        }
        $portrait = imagesy($img) >= imagesx($img); // tras aplicar el giro EXIF
        $out = $portrait
            ? self::fitImage($img, self::MAX_IMAGE_SHORT, self::MAX_IMAGE_LONG, false)
            : self::fitImage($img, self::MAX_IMAGE_LONG, self::MAX_IMAGE_SHORT, false);
        imagedestroy($img);
        $ok = self::writeImage($out, $mime, $path, 85);
        imagedestroy($out);
        if ($ok) {
            @chmod($path, 0640);
        }
        return $ok;
    }

    /**
     * Abre una imagen con GD y aplica el giro EXIF (fotos de móvil). null si no se puede.
     * Más de 100 Mpx no se abre: GD usa ~4-5 bytes por píxel (58 Mpx => ~250 MB, una vez).
     */
    private static function openImage(string $src): ?\GdImage
    {
        if (!function_exists('imagecreatefromstring') || !is_file($src)) {
            return null;
        }
        $info = @getimagesize($src);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 100_000_000) {
            return null;
        }
        $img = @imagecreatefromstring((string) file_get_contents($src));
        if ($img === false) {
            return null;
        }
        if (($info[2] ?? 0) === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $o = (int) ((@exif_read_data($src) ?: [])['Orientation'] ?? 1);
            $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($deg !== 0 && ($r = imagerotate($img, $deg, 0)) !== false) {
                imagedestroy($img);
                $img = $r;
            }
        }
        return $img;
    }

    /** Copia reducida (sin ampliar) que cabe en $maxW x $maxH. $blackBg: fondo negro; si no, conserva la transparencia. */
    private static function fitImage(\GdImage $img, int $maxW, int $maxH, bool $blackBg): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $k = min(1, $maxW / $w, $maxH / $h);
        $tw = max(1, (int) round($w * $k));
        $th = max(1, (int) round($h * $k));
        $out = imagecreatetruecolor($tw, $th);
        if ($blackBg) {
            imagefill($out, 0, 0, imagecolorallocate($out, 0, 0, 0));
        } else {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        }
        imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        return $out;
    }

    /** Escribe en un temporal y renombra (nunca queda un archivo a medias). */
    private static function writeImage(\GdImage $img, string $mime, string $dst, int $quality): bool
    {
        $tmp = $dst . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if ($mime === 'image/jpeg') {
            imageinterlace($img, true); // JPEG progresivo: se ve antes en conexiones lentas
        }
        $ok = match ($mime) {
            'image/png' => imagepng($img, $tmp, 6),
            'image/webp' => imagewebp($img, $tmp, $quality),
            default => imagejpeg($img, $tmp, $quality),
        };
        $ok = $ok && rename($tmp, $dst);
        if (!$ok) {
            @unlink($tmp);
        }
        return $ok;
    }

    /**
     * Libera la sesión antes de enviar un archivo: PHP la BLOQUEA durante toda la
     * petición, y una descarga lenta (video, foto grande) dejaba esperando cualquier
     * otra página de la misma sesión (el panel o /cuenta/ "se quedaban cargando").
     */
    private static function releaseSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
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

    /* ------------------- VIDEOS QUE LA TV PUEDE MOSTRAR (2026-11-13) -------------------
     * Medido en el TV box (Hi3798, WebView 101): los MP4 de 60 fps pierden el
     * 60-75 % de los fotogramas (se ven a saltos); los de 24-30 fps van fluidos.
     * Al SUBIR un MP4 se lee su cabecera (moov) y se RECHAZA si supera estos
     * límites. Sin ffmpeg: lectura directa de las cajas del MP4 (pocos KB).
     * Si la cabecera no se puede leer, el video se acepta (no se bloquea un
     * archivo bueno por un formato raro). Los WEBM no se comprueban. */

    /** Fotogramas por segundo máximos (30 con margen para 29,97 / 30,00x). */
    public const VIDEO_MAX_FPS = 30.5;
    /** Nivel H.264 máximo (41 = 4.1; 4.2 ya es para 1080p a 60 fps). */
    public const VIDEO_MAX_H264_LEVEL = 41;
    /** Resolución máxima: lado largo / lado corto (1920×1080, en vertical u horizontal). */
    public const VIDEO_MAX_LONG = 1920;
    public const VIDEO_MAX_SHORT = 1080;

    /**
     * Comprueba que un MP4 se pueda reproducir fluido en las TV.
     * @return string|null Motivo del rechazo (texto para el usuario) o null si vale.
     */
    public static function videoProblem(string $file, string $mime): ?string
    {
        if ($mime !== 'video/mp4') {
            return null;
        }
        $info = self::mp4Info($file);
        if ($info === null) {
            return null; // cabecera ilegible: no se bloquea
        }
        $problems = [];
        if ($info['codec'] === 'hevc') {
            $problems[] = 'está en H.265/HEVC (las pantallas no lo reproducen)';
        }
        if ($info['fps'] !== null && $info['fps'] > self::VIDEO_MAX_FPS) {
            $problems[] = 'es de ' . (int) round($info['fps']) . ' fotogramas por segundo (máximo 30)';
        }
        if ($info['codec'] === 'h264' && $info['level'] !== null && $info['level'] > self::VIDEO_MAX_H264_LEVEL) {
            $problems[] = 'usa el nivel H.264 ' . number_format($info['level'] / 10, 1) . ' (máximo 4.1)';
        }
        $long = max($info['width'], $info['height']);
        $short = min($info['width'], $info['height']);
        if ($long > self::VIDEO_MAX_LONG || $short > self::VIDEO_MAX_SHORT) {
            $problems[] = 'mide ' . $info['width'] . '×' . $info['height'] . ' (máximo 1920×1080)';
        }
        if (!$problems) {
            return null;
        }
        return 'Este video ' . implode(', ', $problems) . ': en las pantallas no se vería bien (a saltos o nada). '
            . 'Expórtalo a 30 fps, H.264 (nivel 4.0) y 1920×1080 como máximo, sin audio, y vuelve a subirlo '
            . '(p. ej. con HandBrake: Framerate 30 constante, Encoder Level 4.0).';
    }

    /**
     * Lee la pista de VIDEO de un MP4: códec, perfil/nivel H.264, tamaño y fps.
     * @return array{codec:string,profile:?int,level:?int,width:int,height:int,fps:?float}|null
     *         codec = h264 | hevc | otro; null si no se encuentra la cabecera.
     */
    public static function mp4Info(string $file): ?array
    {
        $f = @fopen($file, 'rb');
        if ($f === false) {
            return null;
        }
        $size = (int) filesize($file);
        $moov = null;
        $pos = 0;
        // Cajas del nivel superior hasta encontrar "moov" (puede estar al final)
        for ($n = 0; $n < 1000 && $pos + 8 <= $size; $n++) {
            fseek($f, $pos);
            $h = (string) fread($f, 8);
            if (strlen($h) < 8) {
                break;
            }
            $len = unpack('N', substr($h, 0, 4))[1];
            $type = substr($h, 4, 4);
            $hdr = 8;
            if ($len === 1) {
                $ext = unpack('N2', (string) fread($f, 8));
                $len = $ext[1] * 4294967296 + $ext[2];
                $hdr = 16;
            } elseif ($len === 0) {
                $len = $size - $pos;
            }
            if ($len < $hdr) {
                break;
            }
            if ($type === 'moov') {
                if ($len > 32 * 1048576) {
                    break; // cabecera absurda: no se lee
                }
                fseek($f, $pos + $hdr);
                $moov = (string) fread($f, $len - $hdr);
                break;
            }
            $pos += $len;
        }
        fclose($f);
        if ($moov === null) {
            return null;
        }
        foreach (self::mp4Boxes($moov) as [$type, $trak]) {
            if ($type === 'trak' && ($info = self::mp4VideoTrack($trak)) !== null) {
                return $info;
            }
        }
        return null;
    }

    /** @return array<int,array{0:string,1:string}> Cajas hijas [tipo, contenido] de un bloque. */
    private static function mp4Boxes(string $d): array
    {
        $out = [];
        $pos = 0;
        $len = strlen($d);
        while ($pos + 8 <= $len) {
            $size = unpack('N', substr($d, $pos, 4))[1];
            $type = substr($d, $pos + 4, 4);
            $hdr = 8;
            if ($size === 1 && $pos + 16 <= $len) {
                $ext = unpack('N2', substr($d, $pos + 8, 8));
                $size = $ext[1] * 4294967296 + $ext[2];
                $hdr = 16;
            } elseif ($size === 0) {
                $size = $len - $pos;
            }
            if ($size < $hdr || $pos + $size > $len) {
                break;
            }
            $out[] = [$type, substr($d, $pos + $hdr, $size - $hdr)];
            $pos += $size;
        }
        return $out;
    }

    /** @return string|null Contenido de la primera caja hija de ese tipo. */
    private static function mp4Child(string $d, string $type): ?string
    {
        foreach (self::mp4Boxes($d) as [$t, $c]) {
            if ($t === $type) {
                return $c;
            }
        }
        return null;
    }

    /** Datos de un "trak" si es la pista de VIDEO (hdlr = vide); si no, null. */
    private static function mp4VideoTrack(string $trak): ?array
    {
        $mdia = self::mp4Child($trak, 'mdia');
        $hdlr = $mdia !== null ? self::mp4Child($mdia, 'hdlr') : null;
        if ($hdlr === null || substr($hdlr, 8, 4) !== 'vide') {
            return null;
        }
        $info = ['codec' => 'otro', 'profile' => null, 'level' => null, 'width' => 0, 'height' => 0, 'fps' => null];
        // mdhd: escala de tiempo de la pista
        $timescale = 0;
        $mdhd = self::mp4Child($mdia, 'mdhd');
        if ($mdhd !== null && strlen($mdhd) >= 24) {
            $timescale = unpack('N', substr($mdhd, ord($mdhd[0]) === 1 ? 20 : 12, 4))[1];
        }
        $minf = self::mp4Child($mdia, 'minf');
        $stbl = $minf !== null ? self::mp4Child($minf, 'stbl') : null;
        if ($stbl === null) {
            return $info;
        }
        // stsd: códec (avc1/hvc1...), tamaño y avcC (perfil y nivel)
        $stsd = self::mp4Child($stbl, 'stsd');
        if ($stsd !== null && strlen($stsd) >= 8 + 8 + 78) {
            $entry = substr($stsd, 8);
            $fmt = substr($entry, 4, 4);
            $info['codec'] = in_array($fmt, ['avc1', 'avc3'], true) ? 'h264' : (in_array($fmt, ['hvc1', 'hev1'], true) ? 'hevc' : 'otro');
            $wh = unpack('n2', substr($entry, 8 + 24, 4));
            $info['width'] = $wh[1];
            $info['height'] = $wh[2];
            $avcC = self::mp4Child(substr($entry, 8 + 78), 'avcC');
            if ($avcC !== null && strlen($avcC) >= 4) {
                $info['profile'] = ord($avcC[1]);
                $info['level'] = ord($avcC[3]);
            }
        }
        // stts: fotogramas y duración => fps medios
        $stts = self::mp4Child($stbl, 'stts');
        if ($stts !== null && $timescale > 0 && strlen($stts) >= 8) {
            $count = unpack('N', substr($stts, 4, 4))[1];
            $frames = 0;
            $ticks = 0;
            for ($i = 0; $i < $count && 8 + $i * 8 + 8 <= strlen($stts); $i++) {
                $e = unpack('N2', substr($stts, 8 + $i * 8, 8));
                $frames += $e[1];
                $ticks += $e[1] * $e[2];
            }
            if ($frames > 0 && $ticks > 0) {
                $info['fps'] = round($frames * $timescale / $ticks, 2);
            }
        }
        return $info;
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
     * PAUSA (false) o ACTIVA (true) un archivo. Pausado no se envía a las
     * pantallas (forDevice) pero se conserva con su orden, equipos y fechas.
     * @param int|null $userId Dueño (desde /cuenta/: solo sus archivos); null = el administrador.
     * @return bool false si el archivo no existe (o no es de ese usuario).
     */
    public static function setActive(int $id, ?int $userId, bool $active): bool
    {
        $sql = 'UPDATE sp_media SET active = ? WHERE id = ?' . ($userId !== null ? ' AND user_id = ?' : '');
        $args = $userId !== null ? [$active ? 1 : 0, $id, $userId] : [$active ? 1 : 0, $id];
        Database::pdo()->prepare($sql)->execute($args);
        $check = Database::pdo()->prepare('SELECT COUNT(*) FROM sp_media WHERE id = ?' . ($userId !== null ? ' AND user_id = ?' : ''));
        $check->execute($userId !== null ? [$id, $userId] : [$id]);
        return (int) $check->fetchColumn() > 0;
    }

    /** @return bool true si el archivo está activo (no pausado). */
    public static function isActive(array $media): bool
    {
        return (int) ($media['active'] ?? 1) === 1;
    }

    /** @return int Archivos de contenido que tiene subidos un usuario (para su límite, UserRepository::maxFiles). */
    public static function countForUser(int $userId): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM sp_media WHERE user_id = ?');
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
        if ($kind === 'image') {
            self::shrinkImage($target, $mime); // fotos enormes => máx. 1080x1920 (tamaño y sha256 se calculan después)
        }
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
        self::deleteFiles($media); // archivo + miniatura
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
        self::deleteFiles($media); // archivo + miniatura
        return $media;
    }

    /** Borra TODOS los archivos de un usuario (al eliminar el usuario). */
    public static function deleteAllForUser(int $userId): void
    {
        foreach (self::forUser($userId) as $media) {
            self::deleteFiles($media); // archivo + miniatura
        }
        Database::pdo()->prepare('DELETE FROM sp_media WHERE user_id = ?')->execute([$userId]);
    }

    /**
     * Lista que reproduce un equipo AHORA: archivos ACTIVOS (no pausados) de
     * SU usuario que van a todos sus equipos o a él en concreto, permanentes
     * o dentro de sus fechas, en orden. Vacía si no tiene usuario. Al empezar o terminar
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
               AND m.active = 1
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
        self::releaseSession(); // la descarga no bloquea las demás páginas de esta sesión
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
        if ($kind === 'image') {
            MediaRepository::shrinkImage($target, $mime); // fotos enormes => máx. 1080x1920
        }
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
        MediaRepository::deleteFiles($ad); // archivo + miniatura
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
            MediaRepository::shrinkImage($target, $mime); // imagen enorme => máx. 1080x1920 (antes de tamaño y sha256)
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
            MediaRepository::deleteFiles($o); // archivo + miniatura
        }
        return $o;
    }

    /** Borra los ficheros de los banners de un usuario (las filas se van con él: ON DELETE CASCADE). */
    public static function deleteFilesForUser(int $userId): void
    {
        $stmt = Database::pdo()->prepare('SELECT stored_name FROM sp_overlays WHERE user_id = ? AND stored_name IS NOT NULL');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $o) {
            MediaRepository::deleteFiles($o); // archivo + miniatura
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
 *  - Tipo de liga: code (p. ej. "mlb") + name.
 *  - Liga = nombre + tipo + contenido (lista de códigos "LIDOM,MLB,KO").
 *  - El administrador asigna 0..N ligas a cada usuario; el USUARIO elige
 *    para cada uno de sus equipos "Sin liga" o UNA de ellas
 *    (sp_devices.league_id). Desde 2026-11-12 la TV ya NO recibe la liga
 *    aparte: sus códigos van DENTRO de la URL de la ruta del usuario
 *    (RouteRepository::urlForDevice, parámetro leagues; sin liga => "").
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
     * Liga del equipo para armar su URL (RouteRepository::urlForDevice usa
     * "values" en el parámetro leagues) o null = "Sin liga".
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

/**
 * GESTIÓN DE RUTAS (tabla sp_routes + sp_users.route_id), 2026-11-12.
 *
 * Una ruta es una URL de contenido que el administrador construye POR
 * PARTES (nunca la escribe completa): URL base + agent, pageIndex, timer,
 * showParams, otherQ (Y/N) y pageSize. La URL final se arma con buildUrl()
 * en este orden fijo, y el último parámetro, leagues, se rellena con los
 * códigos de la LIGA DEL EQUIPO (la elige el usuario en /cuenta/; sin liga
 * => "leagues=" vacío). Ej.:
 *   https://sport.grupoazar.do/PizarrasV2?agent=df&pageIndex=0&timer=60&showParams=&otherQ=N&pageSize=14&leagues=NBA
 * Cada USUARIO tiene como mucho UNA ruta (sp_users.route_id); una ruta se
 * asigna a uno o varios usuarios (setUsers) y TODOS sus equipos la heredan.
 * api/device.php y api/media.php envían a cada equipo autorizado
 * "deviceRoute" (urlForDevice: su URL final, o null) y la TV la guarda en
 * localStorage (smartpizarra.routeUrl; "" = sin ruta).
 */
final class RouteRepository
{
    /** URL base por defecto y valores iniciales del formulario. */
    public const DEFAULTS = [
        'base_url' => 'https://sport.grupoazar.do/PizarrasV2',
        'agent' => 'df',
        'page_index' => 0,
        'timer' => 60,
        'show_params' => '',
        'other_q' => 'N',
        'page_size' => 14,
    ];
    /** Límite de los parámetros numéricos. */
    public const MAX_NUMBER = 100000;

    /**
     * Valida y normaliza los datos de una ruta.
     * @param array<string,string> $in Campos del formulario (name, base_url, agent, page_index, timer, show_params, other_q, page_size).
     * @return array<string,mixed>|string Datos limpios o el texto del error.
     */
    public static function clean(array $in): array|string
    {
        $text = static function (string $v, int $max): string {
            if (!mb_check_encoding($v, 'UTF-8')) {
                $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
            }
            return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v)), 0, $max);
        };
        $name = $text((string) ($in['name'] ?? ''), 100);
        if ($name === '') {
            return 'Escribe un nombre para la ruta.';
        }
        $base = trim((string) ($in['base_url'] ?? ''));
        $parts = parse_url($base);
        if ($base === '' || strlen($base) > 500 || $parts === false || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\s"\'<>]/', $base)) {
            return 'La URL base debe empezar por https:// (o http://) y no llevar parámetros ("?..."): p. ej. ' . self::DEFAULTS['base_url'];
        }
        $out = ['name' => $name, 'base_url' => $base];
        foreach (['page_index' => 'pageIndex', 'timer' => 'timer', 'page_size' => 'pageSize'] as $k => $label) {
            $v = trim((string) ($in[$k] ?? ''));
            if (!preg_match('/^\d{1,6}$/', $v) || (int) $v > self::MAX_NUMBER) {
                return "«{$label}» debe ser un número entero entre 0 y " . self::MAX_NUMBER . '.';
            }
            $out[$k] = (int) $v;
        }
        $out['agent'] = $text((string) ($in['agent'] ?? ''), 100);
        $out['show_params'] = $text((string) ($in['show_params'] ?? ''), 200);
        $out['other_q'] = strtoupper(trim((string) ($in['other_q'] ?? ''))) === 'Y' ? 'Y' : 'N';
        return $out;
    }

    /** Valor de un parámetro en la URL: codificado, pero las comas quedan tal cual ("LIDOM,MLB"). */
    private static function enc(string $v): string
    {
        return str_replace('%2C', ',', rawurlencode($v));
    }

    /**
     * URL final de una ruta, con "leagues" = $leagues (códigos de la liga del
     * equipo, "" = sin liga). Orden fijo de los parámetros.
     */
    public static function buildUrl(array $r, string $leagues): string
    {
        return $r['base_url'] . '?agent=' . self::enc((string) $r['agent'])
            . '&pageIndex=' . (int) $r['page_index']
            . '&timer=' . (int) $r['timer']
            . '&showParams=' . self::enc((string) $r['show_params'])
            . '&otherQ=' . ($r['other_q'] === 'Y' ? 'Y' : 'N')
            . '&pageSize=' . (int) $r['page_size']
            . '&leagues=' . self::enc($leagues);
    }

    /**
     * Todas las rutas con sus usuarios ('users' = [[id, company], ...]).
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        $pdo = Database::pdo();
        $rows = $pdo->query('SELECT * FROM sp_routes ORDER BY name, id')->fetchAll();
        $byId = [];
        foreach ($rows as $i => $r) {
            $rows[$i]['users'] = [];
            $byId[(int) $r['id']] = $i;
        }
        foreach ($pdo->query('SELECT id, company, route_id FROM sp_users WHERE route_id IS NOT NULL ORDER BY company') as $u) {
            if (isset($byId[(int) $u['route_id']])) {
                $rows[$byId[(int) $u['route_id']]]['users'][] = ['id' => (int) $u['id'], 'company' => (string) $u['company']];
            }
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_routes WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Crea una ruta (datos de clean()). @return int Id. */
    public static function create(array $d): int
    {
        $now = Database::now();
        Database::pdo()->prepare(
            'INSERT INTO sp_routes (name, base_url, agent, page_index, timer, show_params, other_q, page_size, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$d['name'], $d['base_url'], $d['agent'], $d['page_index'], $d['timer'], $d['show_params'], $d['other_q'], $d['page_size'], $now, $now]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** Cambia una ruta (datos de clean()). Los equipos la reciben en su próxima consulta. */
    public static function update(int $id, array $d): void
    {
        Database::pdo()->prepare(
            'UPDATE sp_routes SET name = ?, base_url = ?, agent = ?, page_index = ?, timer = ?, show_params = ?, other_q = ?,
               page_size = ?, updated_at = ? WHERE id = ?'
        )->execute([$d['name'], $d['base_url'], $d['agent'], $d['page_index'], $d['timer'], $d['show_params'], $d['other_q'], $d['page_size'], Database::now(), $id]);
    }

    /** Borra una ruta; sus usuarios quedan sin ruta (ON DELETE SET NULL). */
    public static function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM sp_routes WHERE id = ?')->execute([$id]);
    }

    /**
     * Usuarios de una ruta: los marcados pasan a tener ESTA ruta (si tenían
     * otra, la cambian: un usuario tiene una sola); los que la tenían y ya no
     * están marcados quedan sin ruta.
     * @param int[] $userIds
     * @return int Usuarios que quedan con la ruta.
     */
    public static function setUsers(int $routeId, array $userIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($v) => $v > 0)));
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("UPDATE sp_users SET route_id = NULL WHERE route_id = ? AND id NOT IN ($in)")->execute(array_merge([$routeId], $ids));
                $pdo->prepare("UPDATE sp_users SET route_id = ? WHERE id IN ($in)")->execute(array_merge([$routeId], $ids));
            } else {
                $pdo->prepare('UPDATE sp_users SET route_id = NULL WHERE route_id = ?')->execute([$routeId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sp_users WHERE route_id = ?');
        $stmt->execute([$routeId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed>|null Ruta del USUARIO dueño de un equipo (o null). */
    public static function forDevice(array $device): ?array
    {
        if (empty($device['user_id'])) {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT r.* FROM sp_routes r JOIN sp_users u ON u.route_id = r.id WHERE u.id = ?');
        $stmt->execute([(int) $device['user_id']]);
        return $stmt->fetch() ?: null;
    }

    /**
     * URL final para un equipo: la ruta de su usuario con leagues = códigos
     * de la liga del equipo ("" sin liga). null = su usuario no tiene ruta.
     */
    public static function urlForDevice(array $device): ?string
    {
        $r = self::forDevice($device);
        if ($r === null) {
            return null;
        }
        $league = LeagueRepository::devicePayload($device);
        return self::buildUrl($r, $league === null ? '' : (string) $league['values']);
    }
}

/**
 * PERFILES DE EQUIPO (tabla sp_device_profiles), 2026-11-12.
 *
 * Ficha de la CAJA FÍSICA que crea el administrador (botón "Perfiles"):
 * marca, modelo, MAC impresa en el case y fecha de instalación (todos
 * obligatorios). Cada perfil se asigna a UN equipo (sp_device_profiles.
 * device_id, único) y cada equipo tiene como mucho uno. Para AUTORIZAR un
 * equipo por PRIMERA vez (sp_devices.first_authorized_at NULL) debe tener
 * perfil (DeviceRepository::needsProfile). Si la TV se restablece y vuelve
 * con un ID nuevo, se busca su perfil por la MAC del case y se reasigna al
 * equipo nuevo. El buscador de equipos busca también por estos datos.
 * La MAC del case NO tiene por qué ser igual a la que envía la TV
 * (sp_devices.mac: cable o wifi): se guardan aparte.
 */
final class ProfileRepository
{
    /**
     * Valida y normaliza los datos del formulario.
     * @param array<string,mixed> $in brand, model, case_mac, installed_on (AAAA-MM-DD).
     * @return array{brand:string,model:string,case_mac:string,installed_on:string}|string Datos o texto del error.
     */
    public static function clean(array $in): array|string
    {
        $text = static function (mixed $v): string {
            $v = (string) $v;
            if (!mb_check_encoding($v, 'UTF-8')) {
                $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
            }
            return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v)), 0, 60);
        };
        $brand = $text($in['brand'] ?? '');
        $model = $text($in['model'] ?? '');
        if ($brand === '' || $model === '') {
            return 'La marca y el modelo son obligatorios.';
        }
        $mac = DeviceRepository::cleanMac(mb_substr((string) ($in['case_mac'] ?? ''), 0, 40));
        if ($mac === null) {
            return 'Escribe la MAC del case completa (12 dígitos hexadecimales, p. ej. E8:BB:3E:C8:FB:5A).';
        }
        $date = (string) ($in['installed_on'] ?? '');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date || (int) $d->format('Y') < 2000) {
            return 'Escribe la fecha de instalación.';
        }
        if ($d > new DateTimeImmutable('tomorrow')) {
            return 'La fecha de instalación no puede ser futura.';
        }
        return ['brand' => $brand, 'model' => $model, 'case_mac' => $mac, 'installed_on' => $date];
    }

    /**
     * Perfiles con su equipo (y el usuario del equipo). $search filtra por
     * marca, modelo, ID/alias del equipo o MAC del case (sin separadores, trozos).
     * @return array<int,array<string,mixed>>
     */
    public static function all(string $search = ''): array
    {
        $where = '';
        $args = [];
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where = ' WHERE (p.brand LIKE ? OR p.model LIKE ? OR p.device_id LIKE ? OR d.label LIKE ?';
            array_push($args, $like, $like, $like, $like);
            $hex = strtoupper((string) preg_replace('/[\s:.\-]/', '', $search));
            if (strlen($hex) >= 2 && ctype_xdigit($hex)) {
                $where .= " OR REPLACE(p.case_mac, ':', '') LIKE ?";
                $args[] = '%' . $hex . '%';
            }
            $where .= ')';
        }
        $stmt = Database::pdo()->prepare(
            'SELECT p.*, d.label AS device_label, d.status AS device_status, d.mac AS device_mac, u.company AS user_company
             FROM sp_device_profiles p
             LEFT JOIN sp_devices d ON d.device_id = p.device_id
             LEFT JOIN sp_users u ON u.id = d.user_id' . $where
            . ' ORDER BY (p.device_id IS NULL) DESC, p.installed_on DESC, p.id DESC LIMIT 500'
        );
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_device_profiles WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null Perfil asignado a un equipo. */
    public static function forDevice(string $deviceId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_device_profiles WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> Perfiles SIN equipo (para asignar desde la tarjeta del equipo). */
    public static function free(): array
    {
        return Database::pdo()->query(
            'SELECT * FROM sp_device_profiles WHERE device_id IS NULL ORDER BY brand, model, case_mac'
        )->fetchAll();
    }

    /** @return bool true si otro perfil (distinto de $exceptId) ya usa esa MAC del case. */
    public static function macTaken(string $caseMac, int $exceptId = 0): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM sp_device_profiles WHERE case_mac = ? AND id <> ?');
        $stmt->execute([$caseMac, $exceptId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array{brand:string,model:string,case_mac:string,installed_on:string} $d Datos de clean(). */
    public static function create(array $d): int
    {
        $now = Database::now();
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO sp_device_profiles (brand, model, case_mac, installed_on, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$d['brand'], $d['model'], $d['case_mac'], $d['installed_on'], $now, $now]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array{brand:string,model:string,case_mac:string,installed_on:string} $d Datos de clean(). */
    public static function update(int $id, array $d): void
    {
        Database::pdo()->prepare(
            'UPDATE sp_device_profiles SET brand = ?, model = ?, case_mac = ?, installed_on = ?, updated_at = ? WHERE id = ?'
        )->execute([$d['brand'], $d['model'], $d['case_mac'], $d['installed_on'], Database::now(), $id]);
    }

    /**
     * Asigna el perfil a un equipo (null = dejarlo libre). Si el equipo ya
     * tenía otro perfil, ese queda libre; si este perfil estaba en otro
     * equipo, pasa al nuevo (p. ej. la TV restablecida con ID nuevo).
     * @return bool false si el equipo no existe.
     */
    public static function assign(int $id, ?string $deviceId): bool
    {
        if ($deviceId !== null && DeviceRepository::find($deviceId) === null) {
            return false;
        }
        $pdo = Database::pdo();
        $now = Database::now();
        $pdo->beginTransaction();
        try {
            if ($deviceId !== null) {
                $pdo->prepare('UPDATE sp_device_profiles SET device_id = NULL, updated_at = ? WHERE device_id = ? AND id <> ?')
                    ->execute([$now, $deviceId, $id]);
            }
            $pdo->prepare('UPDATE sp_device_profiles SET device_id = ?, updated_at = ? WHERE id = ?')
                ->execute([$deviceId, $now, $id]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM sp_device_profiles WHERE id = ?')->execute([$id]);
    }

    /** @return string "Marca Modelo · case E8:BB:..." para mensajes y selectores. */
    public static function text(array $p): string
    {
        return $p['brand'] . ' ' . $p['model'] . ' · case ' . $p['case_mac'];
    }
}

/**
 * BUZÓN DE MENSAJES administrador <-> usuario (tabla sp_messages), 2026-11-12.
 *
 * Una CONVERSACIÓN por usuario (sp_messages.user_id). Cada mensaje lo
 * escribe el administrador (sender = 'admin') o el usuario ('user').
 * read_at = cuándo lo leyó el DESTINATARIO (NULL = sin leer): se marca al
 * abrir la conversación (markRead). El administrador puede escribir a un
 * usuario o a TODOS a la vez (sendToAll: un mensaje en cada conversación).
 * Borrar el usuario borra su conversación (FK ON DELETE CASCADE).
 */
final class MessageRepository
{
    /** Máximo de caracteres por mensaje. */
    public const MAX_BODY = 2000;
    /** Mensajes que muestra una conversación (los más recientes). */
    public const THREAD_LIMIT = 200;
    /** Límite de mensajes que puede enviar un USUARIO por hora (anti-abuso). */
    public const USER_PER_HOUR = 30;

    /**
     * Limpia el texto: UTF-8, saltos de línea normalizados, sin otros
     * caracteres de control, sin espacios al principio/fin.
     * @return string|null null si queda vacío o pasa de MAX_BODY.
     */
    public static function cleanBody(string $raw): ?string
    {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $body = trim((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $raw));
        if ($body === '' || mb_strlen($body) > self::MAX_BODY) {
            return null;
        }
        return $body;
    }

    /** Envía un mensaje en la conversación de un usuario. @return int id del mensaje. */
    public static function send(int $userId, string $sender, string $body): int
    {
        $sender = $sender === 'admin' ? 'admin' : 'user';
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO sp_messages (user_id, sender, body, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $sender, $body, Database::now()]);
        return (int) $pdo->lastInsertId();
    }

    /** El administrador escribe a TODOS los usuarios. @return int Usuarios a los que llegó. */
    public static function sendToAll(string $body): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO sp_messages (user_id, sender, body, created_at) SELECT id, \'admin\', ?, ? FROM sp_users'
        );
        $stmt->execute([$body, Database::now()]);
        return $stmt->rowCount();
    }

    /** @return array<int,array<string,mixed>> Conversación de un usuario (de la más antigua a la más reciente). */
    public static function thread(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM (SELECT * FROM sp_messages WHERE user_id = ? ORDER BY id DESC LIMIT ' . self::THREAD_LIMIT . ') t ORDER BY id'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Marca como leídos los mensajes que RECIBIÓ $reader en esa conversación
     * ('admin' lee los del usuario; 'user' lee los del administrador).
     */
    public static function markRead(int $userId, string $reader): void
    {
        $from = $reader === 'admin' ? 'user' : 'admin';
        Database::pdo()->prepare('UPDATE sp_messages SET read_at = ? WHERE user_id = ? AND sender = ? AND read_at IS NULL')
            ->execute([Database::now(), $userId, $from]);
    }

    /** @return int Mensajes del administrador que el usuario no ha leído. */
    public static function unreadForUser(int $userId): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM sp_messages WHERE user_id = ? AND sender = \'admin\' AND read_at IS NULL');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return int Mensajes de usuarios que el administrador no ha leído (todas las conversaciones). */
    public static function unreadForAdmin(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM sp_messages WHERE sender = \'user\' AND read_at IS NULL')->fetchColumn();
    }

    /**
     * Conversaciones con al menos un mensaje, la más reciente primero, con
     * el último mensaje y cuántos del usuario están sin leer.
     * @return array<int,array<string,mixed>>
     */
    public static function conversations(): array
    {
        return Database::pdo()->query(
            'SELECT u.id, u.company, u.email, m.body AS last_body, m.sender AS last_sender, m.created_at AS last_at,
               (SELECT COUNT(*) FROM sp_messages x WHERE x.user_id = u.id AND x.sender = \'user\' AND x.read_at IS NULL) AS unread
             FROM sp_users u
             JOIN sp_messages m ON m.id = (SELECT MAX(y.id) FROM sp_messages y WHERE y.user_id = u.id)
             ORDER BY m.id DESC'
        )->fetchAll();
    }

    /** Borra toda la conversación de un usuario. @return int Mensajes borrados. */
    public static function deleteThread(int $userId): int
    {
        $stmt = Database::pdo()->prepare('DELETE FROM sp_messages WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }
}

/**
 * COLA DE DESCARGAS CON TURNOS (tabla sp_download_queue), 2026-11-13.
 *
 * Cuando hay contenido nuevo, cientos de pantallas querrían descargarlo en
 * el mismo minuto y cada descarga ocupa un proceso PHP (readfile) mientras
 * dura: en un hosting compartido eso agota los procesos ("503 Resource
 * Limit"). Con la cola, como mucho slots() pantallas descargan A LA VEZ;
 * las demás esperan su turno por ORDEN DE LLEGADA:
 *  - mirror.html pide turno (api/media.php action=turn). Si hay hueco =>
 *    "granted": descarga TODO lo que le falte y lo libera (action=done).
 *    Si no => su posición y cuántos segundos esperar antes de volver a
 *    preguntar (retryAfter, proporcional a su posición: la 400 vuelve
 *    mucho más tarde que la 15; así esperar no carga el servidor).
 *  - Cada descarga (action=file) comprueba el turno: sin turno => 503 "busy"
 *    + Retry-After (también protege de versiones viejas de mirror.html,
 *    que entran en la cola automáticamente).
 *  - En cuanto UNO termina entra el siguiente (no por tandas cerradas).
 *  - Turnos abandonados: en espera sin preguntar WAIT_TTL o activo sin
 *    descargar ACTIVE_TTL => se borran (la pantalla apagada no bloquea).
 * Un bloqueo con nombre de MySQL (GET_LOCK) evita que dos peticiones a la
 * vez den más turnos de la cuenta. Ajustes en config.php:
 * download_slots (por defecto 10) y download_turn_seconds (duración de un
 * turno mientras la cola no ha aprendido la real; por defecto 60). La
 * duración REAL se aprende sola (media móvil en sp_kv "dl_turn_avg").
 */
final class DownloadQueue
{
    /** Pantallas descargando a la vez si config.php no dice otra cosa. */
    public const DEFAULT_SLOTS = 10;
    /** En espera sin volver a preguntar durante este tiempo (s) => fuera de la cola. */
    public const WAIT_TTL = 1200;
    /** Con turno sin pedir ningún archivo durante este tiempo (s) => turno liberado. */
    public const ACTIVE_TTL = 600;
    /** Límites de la espera que se indica a la pantalla (s). */
    public const MIN_WAIT = 15;
    public const MAX_WAIT = 600;

    /** @return int Pantallas que pueden descargar a la vez (config download_slots, 1..200). */
    public static function slots(): int
    {
        return max(1, min(200, (int) (AppConfig::get()['download_slots'] ?? self::DEFAULT_SLOTS)));
    }

    /**
     * Duración media de un turno (s), para calcular las esperas. Se APRENDE de
     * las descargas reales (media móvil en sp_kv "dl_turn_avg", ver release());
     * mientras no hay datos, la de config.php (download_turn_seconds, 60).
     */
    public static function turnSeconds(): int
    {
        static $cached = null;
        if ($cached === null) {
            $stmt = Database::pdo()->prepare('SELECT v FROM sp_kv WHERE k = ?');
            $stmt->execute(['dl_turn_avg']);
            $learned = $stmt->fetchColumn();
            $cached = $learned !== false
                ? (int) round((float) $learned)
                : (int) (AppConfig::get()['download_turn_seconds'] ?? 60);
        }
        return max(5, min(3600, $cached));
    }

    /**
     * Pide (o confirma) el turno de un equipo: lo mete en la cola si no
     * estaba, purga los abandonados y da turno por orden de llegada hasta
     * llenar los huecos.
     * @return array{granted:bool,position:int,waiting:int,active:int,slots:int,retryAfter:int}
     *         position = puesto en la espera (0 si tiene turno); retryAfter = s hasta volver a preguntar.
     */
    public static function request(string $deviceId): array
    {
        $pdo = Database::pdo();
        $locked = (int) $pdo->query("SELECT GET_LOCK('sp_download_queue', 5)")->fetchColumn() === 1;
        try {
            $t = time();
            $now = gmdate('Y-m-d H:i:s', $t);
            $slots = self::slots();
            // 1) Turnos abandonados fuera
            $pdo->prepare(
                'DELETE FROM sp_download_queue
                 WHERE (state = \'waiting\' AND seen_at < ?) OR (state = \'active\' AND seen_at < ?)'
            )->execute([gmdate('Y-m-d H:i:s', $t - self::WAIT_TTL), gmdate('Y-m-d H:i:s', $t - self::ACTIVE_TTL)]);
            // 2) Entra en la cola (si ya estaba, solo "sigue aquí": conserva su puesto)
            $pdo->prepare(
                'INSERT INTO sp_download_queue (device_id, state, created_at, seen_at) VALUES (?, \'waiting\', ?, ?)
                 ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at)'
            )->execute([$deviceId, $now, $now]);
            // 3) Huecos libres => turno a los primeros en llegar
            $active = (int) $pdo->query('SELECT COUNT(*) FROM sp_download_queue WHERE state = \'active\'')->fetchColumn();
            $free = $slots - $active;
            if ($free > 0) {
                $pdo->prepare(
                    'UPDATE sp_download_queue SET state = \'active\', granted_at = ?, seen_at = ?
                     WHERE state = \'waiting\' ORDER BY id LIMIT ' . $free
                )->execute([$now, $now]);
            }
            // 4) Respuesta para este equipo
            $stmt = $pdo->prepare('SELECT id, state FROM sp_download_queue WHERE device_id = ?');
            $stmt->execute([$deviceId]);
            $row = $stmt->fetch() ?: ['id' => 0, 'state' => 'waiting'];
            $counts = self::counts();
            if ($row['state'] === 'active') {
                return ['granted' => true, 'position' => 0, 'waiting' => $counts['waiting'],
                        'active' => $counts['active'], 'slots' => $slots, 'retryAfter' => 0];
            }
            $pos = $pdo->prepare('SELECT COUNT(*) FROM sp_download_queue WHERE state = \'waiting\' AND id <= ?');
            $pos->execute([(int) $row['id']]);
            $position = max(1, (int) $pos->fetchColumn());
            // Espera PROPORCIONAL al puesto exacto: delante tiene position pantallas
            // que se atienden de slots en slots, cada turno ~turnSeconds. Los
            // primeros vuelven pronto (mín. MIN_WAIT) para no dejar huecos vacíos;
            // los del final, tarde (máx. MAX_WAIT): esperar no carga el servidor.
            $wait = (int) round($position / $slots * self::turnSeconds());
            return ['granted' => false, 'position' => $position, 'waiting' => $counts['waiting'],
                    'active' => $counts['active'], 'slots' => $slots,
                    'retryAfter' => max(self::MIN_WAIT, min(self::MAX_WAIT, $wait))];
        } finally {
            if ($locked) {
                $pdo->query("SELECT RELEASE_LOCK('sp_download_queue')");
            }
        }
    }

    /**
     * Libera el turno (o la espera) de un equipo: al terminar sus descargas.
     * Si tenía turno, su duración actualiza la media aprendida (dl_turn_avg:
     * 80 % la anterior + 20 % la nueva) para que las esperas se ajusten solas
     * a la conexión y al tamaño de los archivos.
     */
    public static function release(string $deviceId): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT state, granted_at FROM sp_download_queue WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        $row = $stmt->fetch();
        $pdo->prepare('DELETE FROM sp_download_queue WHERE device_id = ?')->execute([$deviceId]);
        if ($row && $row['state'] === 'active' && !empty($row['granted_at'])) {
            $took = max(1, min(3600, time() - (int) strtotime($row['granted_at'] . ' UTC')));
            $pdo->prepare(
                'INSERT INTO sp_kv (k, v, updated_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE v = ROUND(0.8 * v + 0.2 * VALUES(v), 2), updated_at = VALUES(updated_at)'
            )->execute(['dl_turn_avg', (string) $took, Database::now()]);
        }
    }

    /** @return array{active:int,waiting:int} Turnos en curso y en espera (sin contar los ya caducados). */
    public static function counts(): array
    {
        $t = time();
        $stmt = Database::pdo()->prepare(
            'SELECT state, COUNT(*) n FROM sp_download_queue
             WHERE (state = \'waiting\' AND seen_at >= ?) OR (state = \'active\' AND seen_at >= ?)
             GROUP BY state'
        );
        $stmt->execute([gmdate('Y-m-d H:i:s', $t - self::WAIT_TTL), gmdate('Y-m-d H:i:s', $t - self::ACTIVE_TTL)]);
        $out = ['active' => 0, 'waiting' => 0];
        foreach ($stmt as $r) {
            $out[$r['state']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Resumen para el panel del administrador.
     * @return array{active:int,waiting:int,slots:int,etaSeconds:int} etaSeconds = tiempo estimado hasta vaciar la cola.
     */
    public static function status(): array
    {
        $c = self::counts();
        $slots = self::slots();
        return $c + ['slots' => $slots,
            'etaSeconds' => (int) ceil(($c['waiting'] + $c['active']) / $slots) * self::turnSeconds()];
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

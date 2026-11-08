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
        $missing = array_diff(['sp_devices', 'sp_rate_limits', 'sp_boots', 'sp_users'], $tables);
        if (in_array('sp_devices', $missing, true)) {
            return 'Faltan las tablas: importa server/schema.sql en phpMyAdmin (pestaña Importar) sobre esta base.';
        }
        if (in_array('sp_boots', $missing, true)) {
            return 'Falta la tabla sp_boots: ejecuta en phpMyAdmin (pestaña SQL) server/migrations/2026-11-06-boots.sql.';
        }
        $usersSql = 'ejecuta en phpMyAdmin (pestaña SQL) server/migrations/2026-11-08-users.sql.';
        if ($missing) {
            return 'Faltan tablas (' . implode(', ', $missing) . '): ' . $usersSql;
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
    private const SELECT_WITH_USER = 'SELECT d.*, u.company AS user_company, u.email AS user_email
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
            'INSERT INTO sp_devices (device_id, secret_hash, status, app_version, last_ip, created_at, last_seen_at)
             VALUES (?, ?, \'pending\', ?, ?, ?, ?)'
        )->execute([$id, hash('sha256', $secret), $version, $ip, $now, $now]);
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
        Database::pdo()->prepare('UPDATE sp_devices SET user_id = ? WHERE device_id = ?')
            ->execute([$userId, $id]);
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
        $sql = 'SELECT d.*, u.company AS user_company, u.email AS user_email,
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
               (SELECT COUNT(*) FROM sp_devices d WHERE d.user_id = u.id AND d.status = \'authorized\') AS authorized
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
        Database::pdo()->prepare('DELETE FROM sp_users WHERE id = ?')->execute([$id]);
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

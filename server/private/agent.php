<?php
// Versión del archivo: 1.0.0 (2026-11-16)
/*
 * =====================================================================
 *  SMART PIZARRA - AGENTE DE GESTIÓN REMOTA (código del servidor)
 * =====================================================================
 *  Lo usan public_html/api/agent.php (los equipos) y
 *  public_html/admin/agentes.php (el panel). Requiere lib.php antes.
 *  Tablas: server/migrations/2026-11-16-agent.sql.
 *
 *  Clases:
 *    AgentRepository       equipos con el agente (alta, heartbeat, estado, grupo)
 *    AgentGroupRepository  grupos: app principal + ajustes; revision => ETag
 *    AgentApkRepository    APK subidos (lee package/versionCode del APK)
 *    ApkInfo               lector del AndroidManifest.xml binario de un APK
 *    AgentDeployments      instalar / actualizar / desinstalar por grupo y por fases
 *    AgentCommands         comandos FIRMADOS (ECDSA P-256) con lista blanca
 *    AgentManifest         estado deseado de un equipo + su ETag
 *    AgentQueue            cola de descargas (como DownloadQueue + límite por IP)
 *    AgentResults          resultados de las tareas e inventario
 *    AgentFiles            carpeta de APK y logo; envío con Range
 * =====================================================================
 */

declare(strict_types=1);

/** Ajustes del agente en config.php (con valores por defecto). */
final class AgentConfig
{
    public static function get(string $key, mixed $default): mixed
    {
        return AppConfig::get()[$key] ?? $default;
    }

    /** Segundos entre consultas si el grupo no dice otra cosa. */
    public static function pollSeconds(): int
    {
        return max(30, min(3600, (int) self::get('agent_poll_seconds', 300)));
    }
}

/** Equipos con el agente (tabla sp_agents). */
final class AgentRepository
{
    public const STATUSES = ['pending', 'authorized', 'revoked'];

    /** ID que genera el agente: AG-XXXX-XXXX-XXXX (hex). */
    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^AG-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', $id);
    }

    public static function isValidSecret(string $s): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', $s);
    }

    /** @return array<string,mixed>|null Equipo + datos de su grupo (g_*). */
    public static function find(string $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.*, g.name AS g_name, g.revision AS g_revision, g.poll_seconds AS g_poll
             FROM sp_agents a JOIN sp_agent_groups g ON g.id = a.group_id WHERE a.agent_id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $id, string $secret, string $ip): void
    {
        $now = Database::now();
        Database::pdo()->prepare(
            'INSERT INTO sp_agents (agent_id, secret_hash, status, group_id, last_ip, created_at, last_seen_at)
             VALUES (?, ?, \'pending\', 1, ?, ?, ?)'
        )->execute([$id, hash('sha256', $secret), $ip, $now, $now]);
    }

    public static function secretMatches(array $a, string $secret): bool
    {
        return hash_equals((string) $a['secret_hash'], hash('sha256', $secret));
    }

    /** Datos del heartbeat (todo recortado y con tipos fijos). */
    public static function heartbeat(string $id, array $in, string $ip): void
    {
        $s = static fn($k, $n) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $n);
        $i = static fn($k, $d = -1) => is_numeric($in[$k] ?? null) ? (int) $in[$k] : $d;
        $priv = in_array($in['privilege'] ?? '', ['owner', 'system', 'root', 'adb', 'interactive'], true) ? $in['privilege'] : '';
        Database::pdo()->prepare(
            'UPDATE sp_agents SET model = ?, maker = ?, sdk = ?, abi = ?, privilege = ?, agent_version = ?, state = ?,
               free_mb = ?, temp_c = ?, uptime_s = ?, main_version = ?, restarts = ?, last_error = ?, last_ip = ?, last_seen_at = ?
             WHERE agent_id = ?'
        )->execute([
            $s('model', 80), $s('maker', 80), max(0, min(99, $i('sdk', 0))), $s('abi', 20), $priv,
            $s('agent', 20), $s('state', 16), max(-1, min(2147483647, $i('freeMb'))), max(-1, min(200, $i('temp'))),
            max(0, min(2147483647, $i('uptime', 0))), $i('mainVersion'), max(0, min(2147483647, $i('restarts', 0))),
            $s('lastError', 200), $ip, Database::now(), $id,
        ]);
    }

    /** Solo "sigue vivo" (turnos y descargas). */
    public static function touch(string $id, string $ip): void
    {
        Database::pdo()->prepare('UPDATE sp_agents SET last_seen_at = ?, last_ip = ? WHERE agent_id = ?')
            ->execute([Database::now(), $ip, $id]);
    }

    public static function setStatus(string $id, string $status): void
    {
        if (in_array($status, self::STATUSES, true)) {
            Database::pdo()->prepare('UPDATE sp_agents SET status = ? WHERE agent_id = ?')->execute([$status, $id]);
        }
    }

    public static function setGroup(string $id, int $groupId): bool
    {
        if (AgentGroupRepository::find($groupId) === null) {
            return false;
        }
        Database::pdo()->prepare('UPDATE sp_agents SET group_id = ? WHERE agent_id = ?')->execute([$groupId, $id]);
        return true;
    }

    public static function setLabel(string $id, string $label): void
    {
        Database::pdo()->prepare('UPDATE sp_agents SET label = ? WHERE agent_id = ?')
            ->execute([mb_substr(trim($label), 0, 80), $id]);
    }

    /** Giro propio del equipo (null = el de su grupo). Cambia su ETag (cmd_rev). */
    public static function setRotation(string $id, ?int $deg): void
    {
        $deg = in_array($deg, [0, 90, 180, 270], true) ? $deg : null;
        Database::pdo()->prepare('UPDATE sp_agents SET rotation = ?, cmd_rev = cmd_rev + 1 WHERE agent_id = ?')->execute([$deg, $id]);
    }

    public static function delete(string $id): void
    {
        Database::pdo()->prepare('DELETE FROM sp_agents WHERE agent_id = ?')->execute([$id]);
    }

    public static function bumpCommands(string $id): void
    {
        Database::pdo()->prepare('UPDATE sp_agents SET cmd_rev = cmd_rev + 1 WHERE agent_id = ?')->execute([$id]);
    }

    /**
     * Lista paginada para el panel.
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public static function search(string $q, string $status, int $groupId, int $limit, int $offset): array
    {
        $where = [];
        $args = [];
        if ($q !== '') {
            $where[] = '(a.agent_id LIKE ? OR a.label LIKE ? OR a.model LIKE ? OR a.last_ip LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            array_push($args, $like, $like, $like, $like);
        }
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'a.status = ?';
            $args[] = $status;
        }
        if ($groupId > 0) {
            $where[] = 'a.group_id = ?';
            $args[] = $groupId;
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = Database::pdo();
        $c = $pdo->prepare('SELECT COUNT(*) FROM sp_agents a' . $w);
        $c->execute($args);
        $stmt = $pdo->prepare(
            'SELECT a.*, g.name AS g_name FROM sp_agents a JOIN sp_agent_groups g ON g.id = a.group_id' . $w
            . ' ORDER BY (a.status = \'pending\') DESC, a.last_seen_at DESC LIMIT ' . max(1, min(200, $limit))
            . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($args);
        return ['rows' => $stmt->fetchAll(), 'total' => (int) $c->fetchColumn()];
    }

    /** @return array<string,int> equipos por estado + total. */
    public static function counts(): array
    {
        $out = ['pending' => 0, 'authorized' => 0, 'revoked' => 0, 'total' => 0];
        foreach (Database::pdo()->query('SELECT status, COUNT(*) n FROM sp_agents GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['total'] += (int) $r['n'];
        }
        return $out;
    }
}

/** Grupos de equipos (tabla sp_agent_groups). */
final class AgentGroupRepository
{
    /** @return array<int,array<string,mixed>> todos con el número de equipos. */
    public static function all(): array
    {
        return Database::pdo()->query(
            'SELECT g.*, (SELECT COUNT(*) FROM sp_agents a WHERE a.group_id = g.id) AS agents
             FROM sp_agent_groups g ORDER BY g.id'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_agent_groups WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Valida los datos del formulario del panel.
     * @return array<string,mixed>|string datos limpios o el error para mostrar.
     */
    public static function clean(array $in): array|string
    {
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
        if ($name === '') {
            return 'El grupo necesita un nombre.';
        }
        $pkg = trim((string) ($in['main_package'] ?? ''));
        if ($pkg !== '' && !AgentApkRepository::isValidPackage($pkg)) {
            return 'El paquete de la app principal no es válido (ej.: io.kodular.smartpizarra.smartPizarra_3_0_14).';
        }
        $act = trim((string) ($in['main_activity'] ?? ''));
        if ($act !== '' && !preg_match('/^[a-zA-Z0-9_.$]{1,200}$/', $act)) {
            return 'La actividad no es válida.';
        }
        $win = trim((string) ($in['install_window'] ?? ''));
        if ($win !== '' && !preg_match('/^([01]?\d|2[0-3]):[0-5]\d-([01]?\d|2[0-3]):[0-5]\d$/', $win)) {
            return 'La ventana de instalación debe ser como 02:00-05:00 (o vacía).';
        }
        $color = static fn($v, $d) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $v) ? strtoupper((string) $v) : $d;
        $text = mb_substr(trim((string) ($in['maintenance_text'] ?? '')), 0, 120);
        return [
            'name' => $name,
            'main_package' => $pkg === '' ? null : $pkg,
            'main_activity' => $act === '' ? null : $act,
            'poll_seconds' => max(30, min(3600, (int) ($in['poll_seconds'] ?? 300))),
            'maintenance_text' => $text === '' ? 'Actualizando librerías del sistema...' : $text,
            'brand_color' => $color($in['brand_color'] ?? '', '#002B4F'),
            'text_color' => $color($in['text_color'] ?? '', '#D9DEE5'),
            'show_percent' => !empty($in['show_percent']) ? 1 : 0,
            'install_window' => $win,
            'foreground_downloads' => !empty($in['foreground_downloads']) ? 1 : 0,
            'rotation' => in_array((int) ($in['rotation'] ?? 0), [0, 90, 180, 270], true) ? (int) $in['rotation'] : 0,
        ];
    }

    public static function create(array $d): int
    {
        $now = Database::now();
        Database::pdo()->prepare(
            'INSERT INTO sp_agent_groups (name, main_package, main_activity, poll_seconds, maintenance_text, brand_color,
               text_color, show_percent, install_window, foreground_downloads, rotation, revision, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
        )->execute([$d['name'], $d['main_package'], $d['main_activity'], $d['poll_seconds'], $d['maintenance_text'],
            $d['brand_color'], $d['text_color'], $d['show_percent'], $d['install_window'], $d['foreground_downloads'],
            $d['rotation'], $now, $now]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, array $d): void
    {
        Database::pdo()->prepare(
            'UPDATE sp_agent_groups SET name = ?, main_package = ?, main_activity = ?, poll_seconds = ?, maintenance_text = ?,
               brand_color = ?, text_color = ?, show_percent = ?, install_window = ?, foreground_downloads = ?, rotation = ?,
               revision = revision + 1, updated_at = ? WHERE id = ?'
        )->execute([$d['name'], $d['main_package'], $d['main_activity'], $d['poll_seconds'], $d['maintenance_text'],
            $d['brand_color'], $d['text_color'], $d['show_percent'], $d['install_window'], $d['foreground_downloads'],
            $d['rotation'], Database::now(), $id]);
    }

    /** Borra un grupo (no el 1): sus equipos pasan al grupo 1. */
    public static function delete(int $id): void
    {
        if ($id <= 1) {
            return;
        }
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE sp_agents SET group_id = 1 WHERE group_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM sp_agent_groups WHERE id = ?')->execute([$id]);
        self::bump(1);
    }

    /** Algo del grupo cambió => ETag nuevo => sus equipos descargan el manifiesto. */
    public static function bump(int $id): void
    {
        Database::pdo()->prepare('UPDATE sp_agent_groups SET revision = revision + 1, updated_at = ? WHERE id = ?')
            ->execute([Database::now(), $id]);
    }

    public static function bumpAll(): void
    {
        Database::pdo()->prepare('UPDATE sp_agent_groups SET revision = revision + 1, updated_at = ?')->execute([Database::now()]);
    }
}

/** Carpeta de archivos del agente (private/agent: APK y logo) y envío con Range. */
final class AgentFiles
{
    public static function dir(string $sub = ''): string
    {
        $base = (string) AgentConfig::get('agent_dir', __DIR__ . '/agent');
        $d = rtrim($base, '/\\') . ($sub !== '' ? '/' . $sub : '');
        if (!is_dir($d) && !mkdir($d, 0750, true) && !is_dir($d)) {
            throw new RuntimeException('No se pudo crear la carpeta ' . $d);
        }
        return $d;
    }

    /**
     * Envía un archivo con soporte de Range (descargas reanudables tras un corte).
     * Lee en bloques de 256 KB: no carga el archivo en memoria.
     */
    public static function send(string $path, string $mime, string $downloadName): never
    {
        $size = filesize($path);
        if ($size === false) {
            http_response_code(404);
            exit;
        }
        $start = 0;
        $end = $size - 1;
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if ($range !== '' && preg_match('/^bytes=(\d+)-(\d*)$/', $range, $m)) {
            $start = (int) $m[1];
            if ($m[2] !== '') {
                $end = min($end, (int) $m[2]);
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        } else {
            http_response_code(200);
        }
        @set_time_limit(0);
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($end - $start + 1));
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $fh = fopen($path, 'rb');
        fseek($fh, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fh) && !connection_aborted()) {
            $chunk = fread($fh, (int) min(262144, $left));
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            $left -= strlen($chunk);
            flush();
        }
        fclose($fh);
        exit;
    }

    /** Logo de la pantalla de mantenimiento (uno para todos): sha256 o '' si no hay. */
    public static function logoSha(): string
    {
        $stmt = Database::pdo()->prepare('SELECT v FROM sp_kv WHERE k = ?');
        $stmt->execute(['agent_logo']);
        $v = $stmt->fetchColumn();
        return $v === false ? '' : (string) $v;
    }

    public static function logoPath(): string
    {
        return self::dir() . '/logo.img';
    }

    /** Guarda un logo (PNG/JPEG/WebP, máx. 1 MB) o lo quita ($tmp = null). */
    public static function setLogo(?string $tmp): ?string
    {
        if ($tmp === null) {
            @unlink(self::logoPath());
            Database::pdo()->prepare('DELETE FROM sp_kv WHERE k = ?')->execute(['agent_logo']);
            AgentGroupRepository::bumpAll();
            return null;
        }
        $info = @getimagesize($tmp);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return 'El logo debe ser una imagen PNG, JPG o WebP.';
        }
        if (filesize($tmp) > 1048576) {
            return 'El logo no puede pasar de 1 MB.';
        }
        if (!move_uploaded_file($tmp, self::logoPath()) && !copy($tmp, self::logoPath())) {
            return 'No se pudo guardar el logo.';
        }
        Database::pdo()->prepare(
            'INSERT INTO sp_kv (k, v, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)'
        )->execute(['agent_logo', hash_file('sha256', self::logoPath()), Database::now()]);
        AgentGroupRepository::bumpAll();
        return null;
    }
}

/**
 * Lector mínimo del AndroidManifest.xml BINARIO de un APK (formato AXML):
 * package, versionCode, versionName y minSdkVersion. Sin librerías.
 */
final class ApkInfo
{
    /** IDs de recurso de los atributos (por si el nombre no está en la tabla de cadenas). */
    private const ATTR_VERSION_CODE = 0x0101021b;
    private const ATTR_VERSION_NAME = 0x0101021c;
    private const ATTR_MIN_SDK = 0x0101020c;

    /** @return array{package:string,versionCode:int,versionName:string,minSdk:int}|null */
    public static function read(string $apk): ?array
    {
        $xml = self::manifestBytes($apk);
        return $xml === null ? null : self::parse($xml);
    }

    /** Extrae AndroidManifest.xml del ZIP (ZipArchive o lector propio si no está la extensión). */
    private static function manifestBytes(string $apk): ?string
    {
        if (class_exists('ZipArchive')) {
            $z = new ZipArchive();
            if ($z->open($apk) === true) {
                $data = $z->getFromName('AndroidManifest.xml');
                $z->close();
                return $data === false ? null : $data;
            }
            return null;
        }
        // Lector propio: busca la entrada en el directorio central
        $fh = fopen($apk, 'rb');
        if (!$fh) {
            return null;
        }
        $size = filesize($apk);
        $tail = min($size, 65557);
        fseek($fh, $size - $tail);
        $buf = fread($fh, $tail);
        $eocd = strrpos($buf, "PK\x05\x06");
        if ($eocd === false) {
            fclose($fh);
            return null;
        }
        $e = unpack('vdisk/vcd_disk/ventries_disk/ventries/Vcd_size/Vcd_off', substr($buf, $eocd + 4, 16));
        fseek($fh, $e['cd_off']);
        $cd = fread($fh, $e['cd_size']);
        $p = 0;
        for ($i = 0; $i < $e['entries'] && $p + 46 <= strlen($cd); $i++) {
            $h = unpack('Vsig/vver/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Voff', substr($cd, $p, 46));
            $name = substr($cd, $p + 46, $h['nlen']);
            if ($name === 'AndroidManifest.xml') {
                fseek($fh, $h['off']);
                $lh = unpack('Vsig/vver/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', fread($fh, 30));
                fseek($fh, $h['off'] + 30 + $lh['nlen'] + $lh['elen']);
                $raw = fread($fh, $h['csize']);
                fclose($fh);
                if ($h['method'] === 0) {
                    return $raw;
                }
                if ($h['method'] === 8) {
                    $out = @gzinflate($raw);
                    return $out === false ? null : $out;
                }
                return null;
            }
            $p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
        }
        fclose($fh);
        return null;
    }

    private static function u16(string $d, int $o): int
    {
        return unpack('v', substr($d, $o, 2))[1];
    }

    private static function u32(string $d, int $o): int
    {
        return unpack('V', substr($d, $o, 4))[1];
    }

    /** @return array{package:string,versionCode:int,versionName:string,minSdk:int}|null */
    public static function parse(string $d): ?array
    {
        $len = strlen($d);
        if ($len < 8 || self::u16($d, 0) !== 0x0003) {
            return null;
        }
        $strings = [];
        $resIds = [];
        $out = ['package' => '', 'versionCode' => 0, 'versionName' => '', 'minSdk' => 0];
        $p = self::u16($d, 2);
        while ($p + 8 <= $len) {
            $type = self::u16($d, $p);
            $hsize = self::u16($d, $p + 2);
            $csize = self::u32($d, $p + 4);
            if ($csize < 8) {
                break;
            }
            if ($type === 0x0001) { // tabla de cadenas
                $count = self::u32($d, $p + 8);
                $flags = self::u32($d, $p + 16);
                $sStart = self::u32($d, $p + 20);
                $utf8 = ($flags & 0x100) !== 0;
                for ($i = 0; $i < $count; $i++) {
                    $o = $p + $sStart + self::u32($d, $p + $hsize + $i * 4);
                    if ($utf8) {
                        $n = ord($d[$o]);
                        $o += ($n & 0x80) ? 2 : 1;
                        $bl = ord($d[$o]);
                        if ($bl & 0x80) {
                            $bl = (($bl & 0x7f) << 8) | ord($d[$o + 1]);
                            $o += 2;
                        } else {
                            $o += 1;
                        }
                        $strings[$i] = substr($d, $o, $bl);
                    } else {
                        $n = self::u16($d, $o);
                        $o += 2;
                        if ($n & 0x8000) {
                            $n = (($n & 0x7fff) << 16) | self::u16($d, $o);
                            $o += 2;
                        }
                        $strings[$i] = mb_convert_encoding(substr($d, $o, $n * 2), 'UTF-8', 'UTF-16LE');
                    }
                }
            } elseif ($type === 0x0180) { // ids de recurso de los atributos
                $n = intdiv($csize - $hsize, 4);
                for ($i = 0; $i < $n; $i++) {
                    $resIds[$i] = self::u32($d, $p + $hsize + $i * 4);
                }
            } elseif ($type === 0x0102) { // etiqueta de inicio
                $name = $strings[self::u32($d, $p + 20)] ?? '';
                $aStart = self::u16($d, $p + 24);
                $aSize = self::u16($d, $p + 26);
                $aCount = self::u16($d, $p + 28);
                if ($name === 'manifest' || $name === 'uses-sdk') {
                    for ($i = 0; $i < $aCount; $i++) {
                        $a = $p + 16 + $aStart + $i * $aSize;
                        $nameIdx = self::u32($d, $a + 4);
                        $attr = $strings[$nameIdx] ?? '';
                        $rid = $resIds[$nameIdx] ?? 0;
                        $raw = self::u32($d, $a + 8);
                        $dtype = ord($d[$a + 15]);
                        $data = self::u32($d, $a + 16);
                        $str = $raw !== 0xFFFFFFFF ? ($strings[$raw] ?? '') : ($dtype === 0x03 ? ($strings[$data] ?? '') : '');
                        if ($name === 'manifest' && $attr === 'package') {
                            $out['package'] = $str;
                        } elseif ($name === 'manifest' && ($attr === 'versionCode' || $rid === self::ATTR_VERSION_CODE)) {
                            $out['versionCode'] = $dtype >= 0x10 && $dtype <= 0x1f ? $data : (int) $str;
                        } elseif ($name === 'manifest' && ($attr === 'versionName' || $rid === self::ATTR_VERSION_NAME)) {
                            $out['versionName'] = $str !== '' ? $str : (string) $data;
                        } elseif ($name === 'uses-sdk' && ($attr === 'minSdkVersion' || $rid === self::ATTR_MIN_SDK)) {
                            $out['minSdk'] = $dtype >= 0x10 && $dtype <= 0x1f ? $data : (int) $str;
                        }
                    }
                    if ($name === 'uses-sdk') {
                        break;
                    }
                }
            }
            $p += $csize;
        }
        return $out['package'] !== '' && $out['versionCode'] > 0 ? $out : null;
    }
}

/** APK subidos al panel (tabla sp_agent_apks, archivos en private/agent/apk). */
final class AgentApkRepository
{
    public static function isValidPackage(string $p): bool
    {
        return strlen($p) <= 150 && (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z0-9_]+)+$/', $p);
    }

    public static function maxBytes(): int
    {
        return (int) AgentConfig::get('agent_apk_max_mb', 300) * 1048576;
    }

    /**
     * Guarda un APK subido. Lee package y versionCode del propio APK.
     * @return int|string id del APK o el error para mostrar.
     */
    public static function create(string $tmp, string $originalName, string $label): int|string
    {
        $size = (int) filesize($tmp);
        if ($size <= 0) {
            return 'El archivo está vacío.';
        }
        if ($size > self::maxBytes()) {
            return 'El APK pasa del máximo (' . intdiv(self::maxBytes(), 1048576) . ' MB, config agent_apk_max_mb).';
        }
        $info = ApkInfo::read($tmp);
        if ($info === null) {
            return 'No parece un APK válido (no se pudo leer su AndroidManifest.xml).';
        }
        if (!self::isValidPackage($info['package'])) {
            return 'El paquete del APK no es válido.';
        }
        $dup = Database::pdo()->prepare('SELECT id FROM sp_agent_apks WHERE package = ? AND version_code = ?');
        $dup->execute([$info['package'], $info['versionCode']]);
        if ($dup->fetchColumn() !== false) {
            return 'Ya existe ' . $info['package'] . ' versión ' . $info['versionCode'] . '. Sube un APK con versionCode mayor.';
        }
        $sha = hash_file('sha256', $tmp);
        $file = $sha . '.apk';
        $dest = AgentFiles::dir('apk') . '/' . $file;
        if (!move_uploaded_file($tmp, $dest) && !copy($tmp, $dest)) {
            return 'No se pudo guardar el APK en el servidor.';
        }
        Database::pdo()->prepare(
            'INSERT INTO sp_agent_apks (package, version_code, version_name, min_sdk, label, sha256, size_bytes, file_name, original_name, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$info['package'], $info['versionCode'], mb_substr($info['versionName'], 0, 60), $info['minSdk'],
            mb_substr(trim($label), 0, 80), $sha, $size, $file, mb_substr($originalName, 0, 190), Database::now()]);
        $id = (int) Database::pdo()->lastInsertId();
        // Una versión nueva puede ser la de la app principal de algún grupo
        AgentGroupRepository::bumpAll();
        return $id;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_agent_apks WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> todos (con cuántos despliegues lo usan). */
    public static function all(): array
    {
        return Database::pdo()->query(
            'SELECT p.*, (SELECT COUNT(*) FROM sp_agent_deployments d WHERE d.apk_id = p.id) AS used
             FROM sp_agent_apks p ORDER BY p.package, p.version_code DESC'
        )->fetchAll();
    }

    /** Última versión subida de un paquete (para la app principal automática). */
    public static function latest(string $package): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_agent_apks WHERE package = ? ORDER BY version_code DESC LIMIT 1');
        $stmt->execute([$package]);
        return $stmt->fetch() ?: null;
    }

    public static function path(array $apk): string
    {
        return AgentFiles::dir('apk') . '/' . basename((string) $apk['file_name']);
    }

    /** @return string|null error si un despliegue lo usa. */
    public static function delete(int $id): ?string
    {
        $apk = self::find($id);
        if ($apk === null) {
            return null;
        }
        $u = Database::pdo()->prepare('SELECT COUNT(*) FROM sp_agent_deployments WHERE apk_id = ?');
        $u->execute([$id]);
        if ((int) $u->fetchColumn() > 0) {
            return 'Ese APK lo usa un despliegue: bórralo primero.';
        }
        Database::pdo()->prepare('DELETE FROM sp_agent_apks WHERE id = ?')->execute([$id]);
        @unlink(self::path($apk));
        AgentGroupRepository::bumpAll();
        return null;
    }
}

/** Despliegues por grupo (tabla sp_agent_deployments). */
final class AgentDeployments
{
    /** @return array<int,array<string,mixed>> del grupo, con datos del APK y resultados. */
    public static function forGroup(int $groupId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT d.*, p.version_code, p.version_name, p.size_bytes,
               (SELECT COUNT(*) FROM sp_agent_results r WHERE r.deployment_id = d.id AND r.status = \'done\') AS ok,
               (SELECT COUNT(*) FROM sp_agent_results r WHERE r.deployment_id = d.id AND r.status = \'failed\') AS failed
             FROM sp_agent_deployments d LEFT JOIN sp_agent_apks p ON p.id = d.apk_id
             WHERE d.group_id = ? ORDER BY d.id DESC'
        );
        $stmt->execute([$groupId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sp_agent_deployments WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return string|null error */
    public static function create(int $groupId, string $action, int $apkId, string $package, int $percent): ?string
    {
        if (AgentGroupRepository::find($groupId) === null) {
            return 'El grupo no existe.';
        }
        $percent = max(1, min(100, $percent));
        if ($action === 'install') {
            $apk = AgentApkRepository::find($apkId);
            if ($apk === null) {
                return 'Elige un APK.';
            }
            $package = (string) $apk['package'];
        } elseif ($action === 'uninstall') {
            if (!AgentApkRepository::isValidPackage($package)) {
                return 'Escribe un nombre de paquete válido para desinstalar.';
            }
            $apkId = 0;
        } else {
            return 'Acción no válida.';
        }
        $pdo = Database::pdo();
        // Un paquete, un despliegue activo por grupo: el nuevo sustituye al anterior
        $pdo->prepare('UPDATE sp_agent_deployments SET active = 0 WHERE group_id = ? AND package = ?')->execute([$groupId, $package]);
        $pdo->prepare(
            'INSERT INTO sp_agent_deployments (group_id, action, package, apk_id, rollout_percent, active, created_at)
             VALUES (?, ?, ?, ?, ?, 1, ?)'
        )->execute([$groupId, $action, $package, $apkId ?: null, $percent, Database::now()]);
        AgentGroupRepository::bump($groupId);
        return null;
    }

    public static function setPercent(int $id, int $percent): void
    {
        $d = self::find($id);
        if ($d === null) {
            return;
        }
        Database::pdo()->prepare('UPDATE sp_agent_deployments SET rollout_percent = ? WHERE id = ?')
            ->execute([max(1, min(100, $percent)), $id]);
        AgentGroupRepository::bump((int) $d['group_id']);
    }

    public static function setActive(int $id, bool $active): void
    {
        $d = self::find($id);
        if ($d === null) {
            return;
        }
        Database::pdo()->prepare('UPDATE sp_agent_deployments SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
        AgentGroupRepository::bump((int) $d['group_id']);
    }

    /** "Reintentar fallidos": tareas con otro ID => los equipos vuelven a intentarlo. */
    public static function retry(int $id): void
    {
        $d = self::find($id);
        if ($d === null) {
            return;
        }
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE sp_agent_deployments SET retry = retry + 1 WHERE id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM sp_agent_results WHERE deployment_id = ? AND status = \'failed\'')->execute([$id]);
        AgentGroupRepository::bump((int) $d['group_id']);
    }

    public static function delete(int $id): void
    {
        $d = self::find($id);
        if ($d === null) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM sp_agent_deployments WHERE id = ?')->execute([$id]);
        AgentGroupRepository::bump((int) $d['group_id']);
    }

    /** ¿Este equipo entra en el % del despliegue? Siempre los mismos (hash estable). */
    public static function inRollout(string $agentId, int $depId, int $percent): bool
    {
        return $percent >= 100 || (crc32($agentId . ':' . $depId) % 100) < $percent;
    }
}

/**
 * Comandos remotos FIRMADOS. La firma (ECDSA P-256 + SHA-256, formato DER en base64)
 * cubre "id|target|name|args|exp"; el agente la comprueba con la clave pública que lleva
 * dentro. La clave privada: config agent_command_key_file (por defecto
 * private/agent_command_key.pem, fuera de git y de public_html).
 */
final class AgentCommands
{
    /** Lista blanca (la misma que Commands.kt del agente) y sus argumentos. */
    public const NAMES = [
        'reboot' => 'Reiniciar el equipo',
        'set_resolution' => 'Cambiar resolución (w, h, density)',
        'reset_resolution' => 'Resolución original',
        'clear_app_data' => 'Borrar datos de una app (package)',
        'force_stop' => 'Forzar cierre de una app (package)',
        'set_animations' => 'Animaciones del sistema (scale 0, 0.5 o 1)',
        'collect_diagnostics' => 'Diagnóstico (memoria, temperatura, espacio)',
        'restart_main_app' => 'Reiniciar la app principal',
    ];

    public static function keyFile(): string
    {
        return (string) AgentConfig::get('agent_command_key_file', __DIR__ . '/agent_command_key.pem');
    }

    public static function canSign(): bool
    {
        return function_exists('openssl_sign') && is_readable(self::keyFile());
    }

    /**
     * Valida los argumentos de cada comando.
     * @return string|array<string,mixed> args limpios o el error.
     */
    public static function cleanArgs(string $name, array $in): array|string
    {
        $pkg = static fn() => AgentApkRepository::isValidPackage((string) ($in['package'] ?? ''));
        switch ($name) {
            case 'set_resolution':
                $w = (int) ($in['w'] ?? 0);
                $h = (int) ($in['h'] ?? 0);
                $d = (int) ($in['density'] ?? 0);
                if ($w < 320 || $w > 3840 || $h < 240 || $h > 2160 || ($d !== 0 && ($d < 80 || $d > 640))) {
                    return 'Resolución no válida (ancho 320-3840, alto 240-2160, densidad 80-640 o vacía).';
                }
                return $d ? ['w' => $w, 'h' => $h, 'density' => $d] : ['w' => $w, 'h' => $h];
            case 'clear_app_data':
            case 'force_stop':
                return $pkg() ? ['package' => (string) $in['package']] : 'Escribe un nombre de paquete válido.';
            case 'set_animations':
                $s = (string) ($in['scale'] ?? '');
                return in_array($s, ['0', '0.5', '1'], true) ? ['scale' => (float) $s] : 'La escala debe ser 0, 0.5 o 1.';
            default:
                return isset(self::NAMES[$name]) ? [] : 'Comando no válido.';
        }
    }

    /** @return string|null error */
    public static function create(?string $agentId, ?int $groupId, string $name, array $args, int $ttlHours = 24): ?string
    {
        if (!isset(self::NAMES[$name])) {
            return 'Comando no válido.';
        }
        if (!self::canSign()) {
            return 'Falta la clave para firmar comandos (' . basename(self::keyFile()) . ' en private/). Ver server/README.md.';
        }
        $argsJson = $args ? json_encode($args, JSON_UNESCAPED_SLASHES) : '{}';
        $exp = time() + max(1, min(168, $ttlHours)) * 3600;
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO sp_agent_commands (target_agent, target_group, name, args, signature, created_at, expires_at)
                 VALUES (?, ?, ?, ?, \'\', ?, ?)'
            )->execute([$agentId, $groupId, $name, $argsJson, Database::now(), gmdate('Y-m-d H:i:s', $exp)]);
            $id = (int) $pdo->lastInsertId();
            $target = $agentId !== null ? 'a:' . $agentId : 'g:' . $groupId;
            $key = openssl_pkey_get_private((string) file_get_contents(self::keyFile()));
            if ($key === false || !openssl_sign("$id|$target|$name|$argsJson|$exp", $sig, $key, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('No se pudo firmar el comando (clave no válida).');
            }
            $pdo->prepare('UPDATE sp_agent_commands SET signature = ? WHERE id = ?')->execute([base64_encode($sig), $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return $e->getMessage();
        }
        if ($agentId !== null) {
            AgentRepository::bumpCommands($agentId);
        } else {
            AgentGroupRepository::bump((int) $groupId);
        }
        return null;
    }

    /** Comandos vigentes de un equipo que aún no ha ejecutado. */
    public static function pendingFor(array $agent): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT c.* FROM sp_agent_commands c
             WHERE (c.target_agent = ? OR c.target_group = ?) AND c.expires_at > ?
               AND NOT EXISTS (SELECT 1 FROM sp_agent_results r WHERE r.agent_id = ? AND r.command_id = c.id)
             ORDER BY c.id LIMIT 20'
        );
        $stmt->execute([$agent['agent_id'], (int) $agent['group_id'], Database::now(), $agent['agent_id']]);
        return $stmt->fetchAll();
    }

    /** Últimos comandos de un equipo con su resultado (panel). */
    public static function history(string $agentId, int $groupId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT c.*, r.status, r.code, r.detail, r.reported_at FROM sp_agent_commands c
             LEFT JOIN sp_agent_results r ON r.agent_id = ? AND r.command_id = c.id
             WHERE c.target_agent = ? OR c.target_group = ? ORDER BY c.id DESC LIMIT 20'
        );
        $stmt->execute([$agentId, $agentId, $groupId]);
        return $stmt->fetchAll();
    }
}

/** Estado deseado de un equipo (manifiesto) y su ETag. */
final class AgentManifest
{
    /**
     * ETag barato (sin calcular el manifiesto): cambia si cambia el grupo, su revisión,
     * el estado del equipo, sus comandos o el formato del manifiesto.
     */
    public static function etag(array $agent): string
    {
        return 'v1-g' . $agent['group_id'] . '-r' . $agent['g_revision'] . '-' . substr((string) $agent['status'], 0, 1)
            . '-c' . $agent['cmd_rev'];
    }

    /** @return array<string,mixed> */
    public static function build(array $agent): array
    {
        $group = AgentGroupRepository::find((int) $agent['group_id']) ?? [];
        $authorized = $agent['status'] === 'authorized';
        $apps = [];
        $uninstall = [];
        $commands = [];
        if ($authorized) {
            $stmt = Database::pdo()->prepare(
                'SELECT d.*, p.version_code, p.sha256, p.size_bytes FROM sp_agent_deployments d
                 LEFT JOIN sp_agent_apks p ON p.id = d.apk_id WHERE d.group_id = ? AND d.active = 1 ORDER BY d.id'
            );
            $stmt->execute([(int) $agent['group_id']]);
            $deployed = [];
            foreach ($stmt as $d) {
                $deployed[$d['package']] = true;
                if (!AgentDeployments::inRollout((string) $agent['agent_id'], (int) $d['id'], (int) $d['rollout_percent'])) {
                    continue;
                }
                if ($d['action'] === 'install' && $d['sha256'] !== null) {
                    $apps[] = ['taskId' => 'd' . $d['id'] . 'r' . $d['retry'] . ':' . $d['package'] . ':' . $d['version_code'],
                        'package' => $d['package'], 'versionCode' => (int) $d['version_code'], 'versionId' => (int) $d['apk_id'],
                        'sha256' => $d['sha256'], 'size' => (int) $d['size_bytes']];
                } elseif ($d['action'] === 'uninstall') {
                    $uninstall[] = ['taskId' => 'u' . $d['id'] . 'r' . $d['retry'] . ':' . $d['package'], 'package' => $d['package']];
                }
            }
            // App principal sin despliegue propio: su última versión subida se instala sola
            $main = (string) ($group['main_package'] ?? '');
            if ($main !== '' && !isset($deployed[$main]) && ($apk = AgentApkRepository::latest($main)) !== null) {
                $apps[] = ['taskId' => 'm' . $agent['group_id'] . ':' . $main . ':' . $apk['version_code'],
                    'package' => $main, 'versionCode' => (int) $apk['version_code'], 'versionId' => (int) $apk['id'],
                    'sha256' => $apk['sha256'], 'size' => (int) $apk['size_bytes']];
            }
            foreach (AgentCommands::pendingFor($agent) as $c) {
                $commands[] = ['id' => (int) $c['id'],
                    'target' => $c['target_agent'] !== null ? 'a:' . $c['target_agent'] : 'g:' . $c['target_group'],
                    'name' => $c['name'], 'args' => $c['args'],
                    'exp' => (int) strtotime($c['expires_at'] . ' UTC'), 'sig' => $c['signature']];
            }
        }
        return [
            'etag' => self::etag($agent),
            'status' => $agent['status'],
            'agentId' => $agent['agent_id'],
            'groupId' => (int) $agent['group_id'],
            'serverTime' => time(),
            'mainApp' => !empty($group['main_package'])
                ? ['package' => $group['main_package'], 'activity' => $group['main_activity']] : null,
            'apps' => $apps,
            'uninstall' => $uninstall,
            'commands' => $commands,
            'params' => [
                'pollSeconds' => (int) ($group['poll_seconds'] ?? AgentConfig::pollSeconds()),
                'maintenanceText' => (string) ($group['maintenance_text'] ?? ''),
                'brandColor' => (string) ($group['brand_color'] ?? '#002B4F'),
                'textColor' => (string) ($group['text_color'] ?? '#D9DEE5'),
                'showPercent' => (bool) ($group['show_percent'] ?? true),
                'installWindow' => (string) ($group['install_window'] ?? ''),
                'foregroundDownloads' => (bool) ($group['foreground_downloads'] ?? false),
                // Giro de las pantallas del agente: el del equipo o, si no tiene, el del grupo
                'rotation' => $agent['rotation'] !== null ? (int) $agent['rotation'] : (int) ($group['rotation'] ?? 0),
                'logoSha256' => AgentFiles::logoSha(),
            ],
        ];
    }
}

/**
 * COLA DE DESCARGAS DEL AGENTE: el mismo algoritmo que DownloadQueue (lib.php) con su
 * propia tabla (sp_agent_queue) y un límite más: como mucho agent_slots_per_ip equipos
 * de la MISMA IP pública descargando a la vez (no saturar la conexión de un local).
 */
final class AgentQueue
{
    public const WAIT_TTL = 1200;
    public const ACTIVE_TTL = 600;
    public const MIN_WAIT = 15;
    public const MAX_WAIT = 600;

    public static function slots(): int
    {
        return max(1, min(500, (int) AgentConfig::get('agent_download_slots', 10)));
    }

    public static function perIp(): int
    {
        return max(1, min(50, (int) AgentConfig::get('agent_slots_per_ip', 2)));
    }

    public static function turnSeconds(): int
    {
        $stmt = Database::pdo()->prepare('SELECT v FROM sp_kv WHERE k = ?');
        $stmt->execute(['agent_turn_avg']);
        $v = $stmt->fetchColumn();
        return max(5, min(3600, $v !== false ? (int) round((float) $v) : (int) AgentConfig::get('agent_turn_seconds', 120)));
    }

    /** @return array{granted:bool,position:int,retryAfter:int} */
    public static function request(string $agentId, string $ip): array
    {
        $pdo = Database::pdo();
        $locked = (int) $pdo->query("SELECT GET_LOCK('sp_agent_queue', 5)")->fetchColumn() === 1;
        try {
            $t = time();
            $now = gmdate('Y-m-d H:i:s', $t);
            $pdo->prepare(
                'DELETE FROM sp_agent_queue WHERE (state = \'waiting\' AND seen_at < ?) OR (state = \'active\' AND seen_at < ?)'
            )->execute([gmdate('Y-m-d H:i:s', $t - self::WAIT_TTL), gmdate('Y-m-d H:i:s', $t - self::ACTIVE_TTL)]);
            $pdo->prepare(
                'INSERT INTO sp_agent_queue (agent_id, site_ip, state, created_at, seen_at) VALUES (?, ?, \'waiting\', ?, ?)
                 ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at), site_ip = VALUES(site_ip)'
            )->execute([$agentId, $ip, $now, $now]);
            // Huecos libres => turno por orden de llegada, saltando las IP que ya llenaron su cupo
            $free = self::slots() - (int) $pdo->query('SELECT COUNT(*) FROM sp_agent_queue WHERE state = \'active\'')->fetchColumn();
            if ($free > 0) {
                $perIp = [];
                foreach ($pdo->query('SELECT site_ip, COUNT(*) n FROM sp_agent_queue WHERE state = \'active\' GROUP BY site_ip') as $r) {
                    $perIp[$r['site_ip']] = (int) $r['n'];
                }
                $grant = $pdo->prepare('UPDATE sp_agent_queue SET state = \'active\', granted_at = ?, seen_at = ? WHERE id = ?');
                foreach ($pdo->query('SELECT id, site_ip FROM sp_agent_queue WHERE state = \'waiting\' ORDER BY id LIMIT 500') as $w) {
                    if ($free <= 0) {
                        break;
                    }
                    if (($perIp[$w['site_ip']] ?? 0) >= self::perIp()) {
                        continue;
                    }
                    $grant->execute([$now, $now, $w['id']]);
                    $perIp[$w['site_ip']] = ($perIp[$w['site_ip']] ?? 0) + 1;
                    $free--;
                }
            }
            $stmt = $pdo->prepare('SELECT id, state FROM sp_agent_queue WHERE agent_id = ?');
            $stmt->execute([$agentId]);
            $row = $stmt->fetch() ?: ['id' => 0, 'state' => 'waiting'];
            if ($row['state'] === 'active') {
                return ['granted' => true, 'position' => 0, 'retryAfter' => 0];
            }
            $pos = $pdo->prepare('SELECT COUNT(*) FROM sp_agent_queue WHERE state = \'waiting\' AND id <= ?');
            $pos->execute([(int) $row['id']]);
            $position = max(1, (int) $pos->fetchColumn());
            $wait = (int) round($position / self::slots() * self::turnSeconds());
            return ['granted' => false, 'position' => $position,
                'retryAfter' => max(self::MIN_WAIT, min(self::MAX_WAIT, $wait))];
        } finally {
            if ($locked) {
                $pdo->query("SELECT RELEASE_LOCK('sp_agent_queue')");
            }
        }
    }

    /** ¿Tiene turno activo? (lo exige cada descarga) Refresca su "sigue aquí". */
    public static function hasTurn(string $agentId): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT state FROM sp_agent_queue WHERE agent_id = ?');
        $stmt->execute([$agentId]);
        if ($stmt->fetchColumn() !== 'active') {
            return false;
        }
        $pdo->prepare('UPDATE sp_agent_queue SET seen_at = ? WHERE agent_id = ?')->execute([Database::now(), $agentId]);
        return true;
    }

    /** Libera el turno y aprende su duración (media móvil 80/20). */
    public static function release(string $agentId): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT state, granted_at FROM sp_agent_queue WHERE agent_id = ?');
        $stmt->execute([$agentId]);
        $row = $stmt->fetch();
        $pdo->prepare('DELETE FROM sp_agent_queue WHERE agent_id = ?')->execute([$agentId]);
        if ($row && $row['state'] === 'active' && !empty($row['granted_at'])) {
            $took = max(1, min(3600, time() - (int) strtotime($row['granted_at'] . ' UTC')));
            $pdo->prepare(
                'INSERT INTO sp_kv (k, v, updated_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE v = ROUND(0.8 * v + 0.2 * VALUES(v), 2), updated_at = VALUES(updated_at)'
            )->execute(['agent_turn_avg', (string) $took, Database::now()]);
        }
    }

    /** @return array{active:int,waiting:int,slots:int,perIp:int} */
    public static function status(): array
    {
        $out = ['active' => 0, 'waiting' => 0, 'slots' => self::slots(), 'perIp' => self::perIp()];
        foreach (Database::pdo()->query('SELECT state, COUNT(*) n FROM sp_agent_queue GROUP BY state') as $r) {
            $out[$r['state']] = (int) $r['n'];
        }
        return $out;
    }
}

/** Resultados de las tareas e inventario de cada equipo. */
final class AgentResults
{
    /**
     * Guarda hasta 50 resultados (idempotente: repetir el mismo taskId lo actualiza).
     * @param array<int,mixed> $results
     */
    public static function save(string $agentId, array $results): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO sp_agent_results (agent_id, task_id, deployment_id, command_id, kind, package, version_code, status, code, detail, attempts, reported_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), code = VALUES(code), detail = VALUES(detail),
               attempts = VALUES(attempts), reported_at = VALUES(reported_at)'
        );
        $n = 0;
        foreach (array_slice($results, 0, 50) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $task = mb_substr((string) ($r['taskId'] ?? ''), 0, 200);
            if ($task === '' || !preg_match('/^[a-z0-9:._A-Z-]+$/', $task)) {
                continue;
            }
            $dep = preg_match('/^[du](\d+)r\d+:/', $task, $m) ? (int) $m[1] : null;
            $cmd = preg_match('/^c(\d+)$/', $task, $m) ? (int) $m[1] : null;
            $status = ($r['status'] ?? '') === 'done' ? 'done' : 'failed';
            $kind = in_array($r['type'] ?? '', ['install', 'uninstall', 'command'], true) ? $r['type'] : '';
            $stmt->execute([$agentId, $task, $dep, $cmd, $kind, mb_substr((string) ($r['package'] ?? ''), 0, 150),
                (int) ($r['versionCode'] ?? 0), $status, mb_substr((string) ($r['code'] ?? ''), 0, 60),
                mb_substr((string) ($r['detail'] ?? ''), 0, 4096), max(0, min(999, (int) ($r['attempts'] ?? 0))), Database::now()]);
            $n++;
        }
        return $n;
    }

    /** Sustituye el inventario de un equipo. */
    public static function saveInventory(string $agentId, string $hash, array $packages): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM sp_agent_packages WHERE agent_id = ?')->execute([$agentId]);
            $ins = $pdo->prepare('INSERT IGNORE INTO sp_agent_packages (agent_id, package, version_code) VALUES (?, ?, ?)');
            foreach (array_slice($packages, 0, 1000) as $p) {
                $name = (string) ($p['p'] ?? '');
                if (AgentApkRepository::isValidPackage($name)) {
                    $ins->execute([$agentId, $name, (int) ($p['v'] ?? 0)]);
                }
            }
            $pdo->prepare('UPDATE sp_agents SET inventory_hash = ? WHERE agent_id = ?')
                ->execute([preg_match('/^[0-9a-f]{1,16}$/', $hash) ? $hash : '', $agentId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function inventory(string $agentId): array
    {
        $stmt = Database::pdo()->prepare('SELECT package, version_code FROM sp_agent_packages WHERE agent_id = ? ORDER BY package');
        $stmt->execute([$agentId]);
        return $stmt->fetchAll();
    }

    public static function recent(string $agentId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM sp_agent_results WHERE agent_id = ? AND kind <> \'command\' ORDER BY reported_at DESC LIMIT 30'
        );
        $stmt->execute([$agentId]);
        return $stmt->fetchAll();
    }
}

/** Comprueba que estén las tablas del agente (para el panel). */
function agentDiagnose(): ?string
{
    try {
        $pdo = Database::pdo();
        foreach (['sp_agents', 'sp_agent_groups', 'sp_agent_apks', 'sp_agent_deployments', 'sp_agent_packages',
                     'sp_agent_results', 'sp_agent_commands', 'sp_agent_queue', 'sp_kv'] as $t) {
            $pdo->query("SELECT 1 FROM $t LIMIT 1");
        }
    } catch (Throwable $e) {
        return 'Faltan las tablas del agente: ejecuta server/migrations/2026-11-16-agent.sql en phpMyAdmin.';
    }
    return null;
}

<?php
/*
 * =====================================================================
 *  SMART PIZARRA - CUENTA DEL USUARIO (EMPRESA)
 *  URL: https://smartpizarra.cloud/cuenta/
 * =====================================================================
 *  - El usuario lo crea el administrador en el panel (admin/, Usuarios)
 *    con email, teléfono y empresa, y le entrega una contraseña temporal.
 *  - Entra aquí con su email y esa contraseña; la primera vez (o tras un
 *    "Restablecer contraseña" del administrador) DEBE cambiarla.
 *  - Ve sus datos y sus equipos (alias, ID, estado, última conexión),
 *    SOLO LECTURA: autorizar y revocar equipos lo hace el administrador.
 *  - Puede cambiar su contraseña cuando quiera (pide la actual).
 *
 *  Seguridad: sesión propia (cookie "sp_user" solo en /cuenta/), token
 *  CSRF, límite de intentos de login por IP (mismos valores que el panel),
 *  contraseñas con password_hash. Si el administrador restablece la
 *  contraseña o borra el usuario, la sesión abierta se cierra sola.
 */

declare(strict_types=1);

require __DIR__ . '/../../private/lib.php';
require __DIR__ . '/../../private/web.php';

$nonce = startSecurePage('sp_user', '/cuenta/');

/**
 * Huella de la contraseña guardada: cambia cada vez que se cambia la
 * contraseña, así una sesión abierta con la anterior deja de valer.
 * @param array<string,mixed> $user
 */
function passwordStamp(array $user): string
{
    return hash('sha256', (string) $user['password_hash']);
}

/** Tamaño legible: 1.5 MB, 820 KB... */
function formatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
}

/**
 * Equipos marcados en un formulario de contenido: "all" = todos (también
 * los futuros) o la lista de casillas "devices[]".
 * @return array{0:bool,1:string[]} [todos, equipos]
 */
function targetDevices(): array
{
    $all = param($_POST, 'target') !== 'some';
    $list = isset($_POST['devices']) && is_array($_POST['devices'])
        ? array_values(array_filter($_POST['devices'], 'is_string'))
        : [];
    return [$all, $list];
}

/**
 * Selector de equipos de un archivo: "Todos mis equipos" (también los que
 * se asignen después) o casillas de equipos concretos.
 * @param array<int,array<string,mixed>> $devices Equipos del usuario.
 * @param string[] $selected device_id marcados.
 * @param string $uid Sufijo único para los id de los campos.
 */
function targetFields(array $devices, bool $all, array $selected, string $uid): string
{
    $html = '<fieldset class="targets"><legend>Mostrar en</legend>'
        . '<label class="inline"><input type="radio" name="target" value="all"' . ($all ? ' checked' : '') . '> Todos mis equipos</label>'
        . '<label class="inline"><input type="radio" name="target" value="some"' . ($all ? '' : ' checked') . '> Solo en:</label>'
        . '<div class="device-checks">';
    if (!$devices) {
        $html .= '<span class="hint">Todavía no tienes equipos asignados.</span>';
    }
    foreach ($devices as $d) {
        $id = (string) $d['device_id'];
        $html .= '<label class="inline"><input type="checkbox" name="devices[]" value="' . h($id) . '" id="d-' . h($uid . $id) . '"'
            . (in_array($id, $selected, true) ? ' checked' : '') . '> '
            . h($d['label'] ? $d['label'] . ' (' . $id . ')' : $id)
            . (DeviceRepository::mediaEnabled($d) ? '' : ' <span class="hint">(contenido no habilitado)</span>') . '</label>';
    }
    return $html . '</div></fieldset>';
}

/** Cierra la sesión del usuario. */
function logoutUser(): void
{
    $_SESSION = [];
    session_destroy();
}

$error = '';
$user = null;
$setupError = '';
try {
    $config = AppConfig::get();
    $setupError = Database::diagnose() ?? '';
} catch (Throwable $e) {
    $config = [];
    $setupError = $e->getMessage();
}
$onlineTimeout = onlineTimeout($config);

try {
    if ($setupError !== '') {
        throw new RuntimeException($setupError);
    }

    // Usuario de la sesión (si fue borrado o cambió su contraseña en otro lado => fuera)
    if (!empty($_SESSION['uid'])) {
        $user = UserRepository::find((int) $_SESSION['uid']);
        if ($user === null || !hash_equals((string) ($_SESSION['pwd'] ?? ''), passwordStamp($user))) {
            logoutUser();
            session_start();
            session_regenerate_id(true);
            $user = null;
            $_SESSION['flash'] = 'Tu sesión se cerró. Vuelve a entrar.';
        }
    }

    // Vista previa de un archivo propio (?file=ID): solo su dueño con sesión
    if ($user !== null && isset($_GET['file'])) {
        $media = MediaRepository::findForUser((int) param($_GET, 'file'), (int) $user['id']);
        if ($media === null) {
            http_response_code(404);
            exit('No encontrado');
        }
        MediaRepository::send($media);
    }

    /* -----------------------------------------------------------------
       Acciones (POST)
       ----------------------------------------------------------------- */
    // Subida más grande que post_max_size de PHP: llega sin datos ni CSRF
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        redirectWith('El archivo es demasiado grande para el servidor (límite de PHP post_max_size: '
            . ini_get('post_max_size') . ').');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = param($_POST, 'action');
        if (!hash_equals(csrfToken(), param($_POST, 'csrf'))) {
            redirectWith('La sesión caducó. Vuelve a intentarlo.');
        }

        if ($action === 'login') {
            $bucket = 'ulogin:' . Http::clientIp();
            $window = (int) $config['login_window_seconds'];
            if (RateLimiter::count($bucket, $window) >= (int) $config['login_fails_per_ip']) {
                $error = 'Demasiados intentos. Espera unos minutos.';
            } else {
                $found = UserRepository::verifyLogin(param($_POST, 'email'), param($_POST, 'password'));
                if ($found !== null) {
                    RateLimiter::reset($bucket);
                    session_regenerate_id(true);
                    $_SESSION['uid'] = (int) $found['id'];
                    $_SESSION['pwd'] = passwordStamp($found);
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));
                    UserRepository::markLogin((int) $found['id']);
                    redirectWith((int) $found['must_change_password'] === 1
                        ? 'Bienvenido. Antes de continuar, elige tu propia contraseña.'
                        : 'Sesión iniciada.');
                }
                RateLimiter::hit($bucket, PHP_INT_MAX, $window);
                $error = 'Email o contraseña incorrectos.';
            }
        } elseif ($user === null) {
            redirectWith('Inicia sesión.');
        } elseif ($action === 'logout') {
            logoutUser();
            header('Location: ./', true, 303);
            exit;
        } elseif ($action === 'password') {
            // Cambio de contraseña: pide la actual (también la temporal)
            $current = param($_POST, 'current');
            $new = param($_POST, 'new');
            if (!password_verify($current, (string) $user['password_hash'])) {
                redirectWith('La contraseña actual no es correcta.');
            }
            if ($new !== param($_POST, 'confirm')) {
                redirectWith('La contraseña nueva y su confirmación no coinciden.');
            }
            $problem = UserRepository::passwordProblem($new, (string) $user['email']);
            if ($problem === null && hash_equals($current, $new)) {
                $problem = 'La contraseña nueva debe ser distinta de la actual.';
            }
            if ($problem !== null) {
                redirectWith($problem);
            }
            UserRepository::setPassword((int) $user['id'], $new, false);
            // La sesión actual sigue abierta con la contraseña nueva
            session_regenerate_id(true);
            $_SESSION['pwd'] = passwordStamp(UserRepository::find((int) $user['id']) ?? []);
            redirectWith('Contraseña cambiada.');
        } elseif (str_starts_with($action, 'media_')) {
            /* ---- Contenido para mirror.html (imágenes y videos) ---- */
            if ((int) $user['must_change_password'] === 1) {
                redirectWith('Primero elige tu contraseña.');
            }
            $uid = (int) $user['id'];
            $mid = (int) param($_POST, 'mediaId');
            if ($action !== 'media_upload' && MediaRepository::findForUser($mid, $uid) === null) {
                redirectWith('Ese archivo no existe.');
            }
            [$all, $chosen] = targetDevices();
            if (in_array($action, ['media_upload', 'media_update'], true) && !$all && !$chosen) {
                redirectWith('Marca al menos un equipo o elige "Todos mis equipos".');
            }
            switch ($action) {
                case 'media_upload':
                    $f = $_FILES['file'] ?? null;
                    $err = is_array($f) && is_int($f['error'] ?? null) ? $f['error'] : UPLOAD_ERR_NO_FILE;
                    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                        redirectWith('El archivo supera el límite de PHP (upload_max_filesize: ' . ini_get('upload_max_filesize') . ').');
                    }
                    if ($err !== UPLOAD_ERR_OK) {
                        redirectWith($err === UPLOAD_ERR_NO_FILE ? 'Elige un archivo.' : 'La subida falló (código ' . $err . '). Inténtalo de nuevo.');
                    }
                    $size = (int) $f['size'];
                    if ($size > MediaRepository::maxBytes()) {
                        redirectWith('El archivo supera el máximo de ' . formatBytes(MediaRepository::maxBytes()) . '.');
                    }
                    if (MediaRepository::usedBytes($uid) + $size > MediaRepository::quotaBytes()) {
                        redirectWith('No queda espacio: tu límite es ' . formatBytes(MediaRepository::quotaBytes())
                            . '. Borra algún archivo.');
                    }
                    $mime = MediaRepository::detectMime((string) $f['tmp_name']);
                    if ($mime === null) {
                        redirectWith('Tipo de archivo no admitido. Usa imágenes JPG, PNG, WEBP o GIF, o videos MP4 (H.264) o WEBM.');
                    }
                    MediaRepository::create($uid, (string) $f['tmp_name'], (string) $f['name'], $mime,
                        (int) param($_POST, 'seconds'), $all, $chosen);
                    redirectWith('«' . $f['name'] . '» subido. Tus equipos lo descargarán en su próxima consulta.');
                case 'media_update':
                    MediaRepository::update($mid, $uid, (int) param($_POST, 'seconds'), $all, $chosen);
                    redirectWith('Cambios guardados.');
                case 'media_up':
                case 'media_down':
                    MediaRepository::move($mid, $uid, $action === 'media_up' ? -1 : 1);
                    redirectWith('Orden cambiado.');
                case 'media_delete':
                    MediaRepository::delete($mid, $uid);
                    redirectWith('Archivo eliminado. Tus equipos dejarán de mostrarlo en su próxima consulta.');
                default:
                    redirectWith('Acción desconocida.');
            }
        } else {
            redirectWith('Acción desconocida.');
        }
    }

    $flash = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    $mustChange = $user !== null && (int) $user['must_change_password'] === 1;
    $devices = $user !== null && !$mustChange ? DeviceRepository::search(null, '', (int) $user['id']) : [];
    // Contenido para mirror.html
    $mediaList = $user !== null && !$mustChange ? MediaRepository::forUser((int) $user['id']) : [];
    $usedBytes = $user !== null && !$mustChange ? MediaRepository::usedBytes((int) $user['id']) : 0;
} catch (Throwable $e) {
    error_log('smartpizarra cuenta: ' . $e->getMessage());
    http_response_code(500);
    $error = $setupError !== ''
        ? 'El servicio no está disponible en este momento. Inténtalo más tarde.'
        : 'Error del servidor. Inténtalo más tarde.';
    $flash = '';
    $user = null;
    $mustChange = false;
    $devices = [];
    $mediaList = [];
    $usedBytes = 0;
}

$statusText = ['pending' => 'Pendiente de autorizar', 'authorized' => 'Autorizado', 'revoked' => 'Revocado'];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Mi cuenta - Smart Pizarra</title>
  <style nonce="<?= h($nonce) ?>">
<?= baseStyles() ?>

    main { max-width: 720px; }
    .block { margin-bottom: 12px; }
    .narrow { max-width: 420px; }
    .hint { color: var(--muted); font-size: .85rem; }
    /* Contenido (mirror.html) */
    .targets { border: 1px solid var(--line); border-radius: 8px; padding: 8px 12px; margin: 10px 0; }
    .targets legend { font-size: .9rem; color: var(--muted); padding: 0 4px; }
    label.inline { display: inline-flex; gap: 6px; align-items: center; margin: 4px 14px 4px 0; color: var(--text); font-size: .95rem; }
    .device-checks { margin-left: 4px; }
    .media { display: flex; gap: 12px; flex-wrap: wrap; }
    .thumb { width: 160px; height: 90px; border-radius: 8px; background: #000; object-fit: contain; flex: 0 0 auto;
      display: flex; align-items: center; justify-content: center; color: #fff; font-size: .9rem; text-decoration: none; }
    .media-body { flex: 1 1 260px; min-width: 0; }
    .media-name { font-weight: 600; overflow-wrap: anywhere; }
    input[type=number] { width: 90px; padding: 7px 10px; font: inherit; color: var(--text);
      background: var(--bg); border: 1px solid var(--line); border-radius: 8px; }
    input[type=file] { font: inherit; color: var(--text); max-width: 100%; }
  </style>
</head>
<body>
<main>
  <header>
    <h1>Smart Pizarra · Mi cuenta</h1>
    <?php if ($user !== null): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="logout"><button type="submit">Salir</button></form>
    <?php endif; ?>
  </header>
  <?php if ($flash): ?><div class="card flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="card error"><?= h($error) ?></div><?php endif; ?>

<?php if ($user === null): ?>
  <!-- Entrada con el email y la contraseña que entregó el administrador -->
  <form class="card narrow" method="post" autocomplete="on">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="login">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" autocomplete="username" required>
    <label for="password">Contraseña</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>
    <p><button class="primary" type="submit">Entrar</button></p>
    <p class="hint">¿Olvidaste tu contraseña? Pide al administrador que la restablezca.</p>
  </form>
<?php else: ?>

  <?php if (!$mustChange): ?>
    <!-- Datos del usuario (los cambia el administrador) -->
    <section class="card block">
      <h2><?= h($user['company']) ?></h2>
      <div class="meta">
        <?= h($user['email']) ?> · Tel. <?= h($user['phone']) ?><br>
        <span class="hint">Para cambiar estos datos, autorizar o revocar un equipo, contacta al administrador.</span>
      </div>
    </section>

    <!-- Equipos del usuario: solo lectura -->
    <h2>Mis equipos (<?= count($devices) ?>)</h2>
    <?php if (!$devices): ?>
      <div class="card empty block">Todavía no tienes equipos asignados.</div>
    <?php endif; ?>
    <?php foreach ($devices as $d): $st = (string) $d['status']; ?>
      <section class="card device">
        <div class="device-head">
          <span>
            <?= $d['label'] ? '<span class="alias">' . h($d['label']) . '</span>' : '' ?>
            <span class="device-id"><?= h($d['device_id']) ?></span>
          </span>
          <span class="badge <?= h($st) ?>"><?= h($statusText[$st] ?? $st) ?></span>
        </div>
        <div class="meta">
          Última conexión: <?= onlineTag($d['online_at'] ?? null, $onlineTimeout) ?><br>
          <!-- Permiso de contenido: lo habilita el administrador -->
          Contenido (imágenes y videos): <?= DeviceRepository::mediaEnabled($d) ? 'habilitado' : '<strong>no habilitado</strong> — pídeselo al administrador' ?><br>
          Registrado el <?= dateTag($d['created_at']) ?>
          · Arranques: <?= (int) ($d['boots'] ?? 0) ?>
          <?= !empty($d['last_boot_at']) ? '(último ' . dateTag($d['last_boot_at']) . ')' : '' ?>
          <?= $d['app_version'] ? ' · v' . h($d['app_version']) : '' ?>
        </div>
      </section>
    <?php endforeach; ?>

    <!-- ============ CONTENIDO PARA LAS PANTALLAS (mirror.html) ============
         Cada equipo descarga UNA vez sus archivos y los muestra en
         carrusel, en este orden, desde su propia memoria. -->
    <h2>Contenido de mis pantallas (<?= count($mediaList) ?>)</h2>
    <p class="hint">Usado <?= h(formatBytes($usedBytes)) ?> de <?= h(formatBytes(MediaRepository::quotaBytes())) ?>.
      Imágenes JPG, PNG, WEBP o GIF y videos MP4 (H.264) o WEBM de hasta <?= h(formatBytes(MediaRepository::maxBytes())) ?>.
      Los videos se reproducen sin sonido. Los equipos se actualizan solos en un minuto aproximadamente.
      Solo lo muestran los equipos autorizados con el contenido habilitado por el administrador.</p>
    <form class="card block" method="post" enctype="multipart/form-data" id="uploadForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="media_upload">
      <label for="file">Archivo</label>
      <input type="file" id="file" name="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" required>
      <label for="seconds">Segundos en pantalla (solo imágenes; los videos duran lo suyo)</label>
      <input type="number" id="seconds" name="seconds" value="10" min="<?= MediaRepository::MIN_SECONDS ?>" max="<?= MediaRepository::MAX_SECONDS ?>">
      <?= targetFields($devices, true, [], 'new') ?>
      <button class="primary" type="submit" id="uploadBtn">Subir</button>
    </form>

    <?php foreach ($mediaList as $i => $m): $mid = (int) $m['id']; $isImg = $m['kind'] === 'image'; ?>
      <section class="card device">
        <div class="media">
          <?php if ($isImg): ?>
            <img class="thumb" src="?file=<?= $mid ?>" alt="" loading="lazy">
          <?php else: ?>
            <a class="thumb" href="?file=<?= $mid ?>" target="_blank" rel="noopener">&#9654; Video</a>
          <?php endif; ?>
          <div class="media-body">
            <div class="media-name"><?= ($i + 1) ?>. <?= h($m['original_name']) ?></div>
            <div class="meta"><?= $isImg ? 'Imagen' : 'Video' ?> · <?= h(formatBytes((int) $m['size_bytes'])) ?>
              · Subido el <?= dateTag($m['created_at']) ?></div>
            <form method="post">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="media_update"><input type="hidden" name="mediaId" value="<?= $mid ?>">
              <?php if ($isImg): ?>
                <label for="s<?= $mid ?>">Segundos en pantalla</label>
                <input type="number" id="s<?= $mid ?>" name="seconds" value="<?= (int) $m['seconds'] ?>" min="<?= MediaRepository::MIN_SECONDS ?>" max="<?= MediaRepository::MAX_SECONDS ?>">
              <?php else: ?>
                <input type="hidden" name="seconds" value="<?= (int) $m['seconds'] ?>">
              <?php endif; ?>
              <?= targetFields($devices, (int) $m['all_devices'] === 1, $m['devices'], 'm' . $mid . '-') ?>
              <button type="submit">Guardar</button>
            </form>
          </div>
        </div>
        <div class="actions">
          <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="media_up"><input type="hidden" name="mediaId" value="<?= $mid ?>">
            <button type="submit" <?= $i === 0 ? 'disabled' : '' ?>>&#9650; Antes</button></form>
          <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="media_down"><input type="hidden" name="mediaId" value="<?= $mid ?>">
            <button type="submit" <?= $i === count($mediaList) - 1 ? 'disabled' : '' ?>>&#9660; Después</button></form>
          <form method="post" data-confirm="¿Eliminar «<?= h($m['original_name']) ?>»? Tus pantallas dejarán de mostrarlo."><?= csrfField() ?>
            <input type="hidden" name="action" value="media_delete"><input type="hidden" name="mediaId" value="<?= $mid ?>">
            <button class="bad" type="submit">Eliminar</button></form>
        </div>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- Cambio de contraseña (obligatorio si es la temporal) -->
  <form class="card block narrow" method="post" autocomplete="off">
    <?= csrfField() ?>
    <h2><?= $mustChange ? 'Elige tu contraseña' : 'Cambiar contraseña' ?></h2>
    <?php if ($mustChange): ?>
      <p class="hint">Estás usando una contraseña temporal. Cámbiala para ver tus equipos.</p>
    <?php endif; ?>
    <input type="hidden" name="action" value="password">
    <!-- Campo oculto con el email: ayuda a los gestores de contraseñas -->
    <input type="email" name="username" value="<?= h($user['email']) ?>" autocomplete="username" hidden>
    <label for="current"><?= $mustChange ? 'Contraseña temporal' : 'Contraseña actual' ?></label>
    <input type="password" id="current" name="current" autocomplete="current-password" required>
    <label for="new">Contraseña nueva (mínimo <?= UserRepository::MIN_PASSWORD ?> caracteres)</label>
    <input type="password" id="new" name="new" autocomplete="new-password" minlength="<?= UserRepository::MIN_PASSWORD ?>" required>
    <label for="confirm">Repite la contraseña nueva</label>
    <input type="password" id="confirm" name="confirm" autocomplete="new-password" minlength="<?= UserRepository::MIN_PASSWORD ?>" required>
    <p><button class="primary" type="submit">Guardar contraseña</button></p>
  </form>

  <script nonce="<?= h($nonce) ?>"><?= timeScript($onlineTimeout) ?></script>
  <script nonce="<?= h($nonce) ?>">
  (function () {
    "use strict";
    // Al subir: botón desactivado y aviso (un video grande tarda)
    var form = document.getElementById("uploadForm");
    if (form) {
      form.addEventListener("submit", function () {
        var b = document.getElementById("uploadBtn");
        b.disabled = true;
        b.textContent = "Subiendo… no cierres la página";
      });
    }
  })();
  </script>
<?php endif; ?>
</main>
</body>
</html>

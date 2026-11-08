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

    /* -----------------------------------------------------------------
       Acciones (POST)
       ----------------------------------------------------------------- */
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
        } else {
            redirectWith('Acción desconocida.');
        }
    }

    $flash = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    $mustChange = $user !== null && (int) $user['must_change_password'] === 1;
    $devices = $user !== null && !$mustChange ? DeviceRepository::search(null, '', (int) $user['id']) : [];
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
          Registrado el <?= dateTag($d['created_at']) ?>
          · Arranques: <?= (int) ($d['boots'] ?? 0) ?>
          <?= !empty($d['last_boot_at']) ? '(último ' . dateTag($d['last_boot_at']) . ')' : '' ?>
          <?= $d['app_version'] ? ' · v' . h($d['app_version']) : '' ?>
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
<?php endif; ?>
</main>
</body>
</html>

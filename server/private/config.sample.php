<?php
/*
 * =====================================================================
 *  SMART PIZARRA - CONFIGURACIÓN DEL SERVIDOR (PLANTILLA)
 * =====================================================================
 *  1. Copia este archivo como "config.php" en la MISMA carpeta (private/).
 *  2. Rellena los datos. config.php NO se sube a git (.gitignore).
 *  3. La carpeta private/ debe quedar FUERA de public_html (ver README.md).
 */

return [
    // Base de datos (hPanel > Bases de datos > Administración de MySQL)
    'db' => [
        'host'     => 'localhost',
        'name'     => 'u000000000_smartpizarra',
        'user'     => 'u000000000_spadmin',
        'password' => 'CAMBIAR',
    ],

    // Administrador del panel admin/.
    // admin_password_hash: genera el hash en tu PC con XAMPP (no pongas la
    // contraseña en claro aquí):
    //   C:\xampp\php\php.exe -r "echo password_hash('TU-CONTRASEÑA', PASSWORD_DEFAULT);"
    // y pega el resultado (empieza por $2y$). Mientras diga CAMBIAR, el
    // panel no deja entrar a nadie.
    'admin_user'          => 'admin',
    'admin_password_hash' => 'CAMBIAR',

    // Orígenes desde los que los equipos pueden llamar a api/device.php.
    // El WebViewer de Kodular carga las páginas desde http://localhost.
    'allowed_origins' => ['http://localhost'],

    // Límites anti-abuso
    'register_per_ip_per_hour' => 30, // equipos NUEVOS por IP y hora
    'login_fails_per_ip'       => 10, // intentos fallidos de login por IP...
    'login_window_seconds'     => 900, // ...en esta ventana (15 min)

    // Señal "online" de la app nativa Kodular (api/ping.php).
    // El panel muestra "En línea" si la última señal llegó hace menos de
    // estos segundos; si no, "Desconectado · hace X". Ponlo en unas 2-3
    // veces el intervalo del Clock de la app (p. ej. Clock 60 s => 180).
    'online_timeout_seconds' => 180,

    // Historial de arranques (cada vez que se ejecuta loading.html).
    // boot_history_days: días que se guardan por equipo (0 = para siempre).
    // boots_per_device_per_hour: máximo de arranques anotados por equipo y
    // hora (si la app se reinicia en bucle no llena la tabla; la "última
    // conexión" se sigue actualizando igual).
    'boot_history_days'         => 365,
    'boots_per_device_per_hour' => 30,

    // Si el sitio va detrás de un proxy/CDN que pone la IP real en una
    // cabecera, indícala aquí (p. ej. 'HTTP_CF_CONNECTING_IP'). Si no, null.
    'client_ip_header' => null,
];

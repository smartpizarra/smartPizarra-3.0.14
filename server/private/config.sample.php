<?php
// Versión del archivo: 1.0.1 (2026-11-13)
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
    // Equipos NUEVOS por IP y hora. Con instalaciones grandes en un mismo
    // local (todos con la misma IP pública) súbelo: 200 permite instalar
    // 200 TV por hora desde una misma red (2026-11-13; antes 30).
    'register_per_ip_per_hour' => 200,
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

    // Imágenes y videos que los usuarios suben en /cuenta/ para mirror.html.
    // media_dir: carpeta de los archivos (por defecto private/media, se crea
    //   sola; debe quedar FUERA de public_html).
    // media_max_mb: tamaño máximo de UN archivo. Además PHP debe permitirlo:
    //   hPanel > Avanzado > Configuración de PHP > upload_max_filesize y
    //   post_max_size (un poco mayor).
    // media_user_quota_mb: espacio total por usuario.
    // media_downloads_per_device_per_hour: descargas máximas por equipo y
    //   hora (cada equipo descarga cada archivo UNA vez; el límite solo
    //   frena un equipo que entre en bucle).
    // Si faltan, se usan estos valores.
    'media_max_mb'                        => 200,
    'media_user_quota_mb'                 => 2048,
    'media_downloads_per_device_per_hour' => 120,
    // Tiras y banners de mirror.html: tamaño máximo de la imagen de un banner
    // y máximo de tiras/banners por usuario. Si faltan, se usan estos valores.
    'overlay_max_mb'    => 5,

    // COLA DE DESCARGAS (2026-11-13). Cuando hay contenido nuevo, como mucho
    // download_slots pantallas lo descargan A LA VEZ; las demás esperan su
    // turno por orden de llegada (cada descarga ocupa un proceso PHP: con
    // demasiadas a la vez el hosting responde "503 Resource Limit").
    // Súbelo si en hPanel > Uso de recursos ves margen; bájalo si aparecen 503.
    // download_turn_seconds: lo que tarda una pantalla en descargar su
    // contenido nuevo, solo hasta que la cola aprende la duración REAL (se
    // ajusta sola con cada descarga). Sirve para decir a cada una cuánto esperar.
    // Si faltan, se usan estos valores.
    'download_slots'        => 10,
    'download_turn_seconds' => 60,
    // AGENTE (launcher de las TV, 2026-11-16; panel /admin/agentes.php, API api/agent.php).
    // agent_poll_seconds: consulta por defecto (cada grupo tiene la suya).
    // agent_download_slots: equipos descargando APK a la vez; agent_slots_per_ip:
    //   máximo a la vez desde la MISMA IP pública (no saturar la conexión de un local).
    // agent_turn_seconds: duración de un turno hasta que la cola aprende la real.
    // agent_apk_max_mb: tamaño máximo de un APK subido (y lo que permita PHP).
    // agent_command_key_file: clave PRIVADA EC P-256 que firma los comandos remotos
    //   (la pública va dentro del APK: agent/keys/command_public.pem). Créala con
    //   OpenSSL (ver server/README.md) y súbela a private/ (NUNCA a public_html ni a git).
    // agent_dir: carpeta de APK y logo (por defecto private/agent, se crea sola).
    'agent_poll_seconds'     => 300,
    'agent_download_slots'   => 10,
    'agent_slots_per_ip'     => 2,
    'agent_turn_seconds'     => 120,
    'agent_apk_max_mb'       => 300,
    // 'agent_command_key_file' => __DIR__ . '/agent_command_key.pem',
    // 'agent_dir'              => __DIR__ . '/agent',
    'overlays_per_user' => 20,
    'online_timeout_seconds' => 180,
    'boot_history_days' => 365,
     'boots_per_device_per_hour' => 30,
];

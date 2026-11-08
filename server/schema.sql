-- =====================================================================
--  SMART PIZARRA - BASE DE DATOS DE DISPOSITIVOS (MySQL / MariaDB)
--  Importar UNA vez en phpMyAdmin (hPanel de Hostinger > Bases de datos
--  > phpMyAdmin > pestaña "Importar"), sobre la base creada para esto.
--  Se puede volver a importar: solo crea lo que falte.
-- =====================================================================

-- Usuarios (empresas) dueños de equipos. Los crea el administrador en
-- el panel (email, teléfono, empresa); entran en /cuenta/ con su email
-- y cambian su contraseña. Solo VEN sus equipos: autorizar y revocar
-- (también "revocar todos" los de un usuario) lo hace el administrador.
CREATE TABLE IF NOT EXISTS sp_users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email                VARCHAR(190) NOT NULL,          -- email de acceso (único, minúsculas)
  phone                VARCHAR(30)  NOT NULL,          -- teléfono de contacto
  company              VARCHAR(100) NOT NULL,          -- nombre de la empresa
  password_hash        VARCHAR(255) NOT NULL,          -- password_hash() de PHP (nunca la contraseña)
  must_change_password TINYINT(1)   NOT NULL DEFAULT 1, -- 1 = contraseña temporal del administrador
  created_at           DATETIME     NOT NULL,          -- alta (UTC)
  password_changed_at  DATETIME     NULL,              -- último cambio de contraseña (UTC)
  last_login_at        DATETIME     NULL,              -- último acceso a /cuenta/ (UTC)
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un registro por TV box. La crea/actualiza api/device.php y la
-- autoriza o revoca el panel admin/.
CREATE TABLE IF NOT EXISTS sp_devices (
  device_id     VARCHAR(32)  NOT NULL,          -- "SP-XXXX-XXXX-XXXX" (localStorage smartpizarra.deviceId)
  secret_hash   CHAR(64)     NOT NULL,          -- SHA-256 del secreto del equipo (nunca el secreto)
  status        VARCHAR(10)  NOT NULL DEFAULT 'pending', -- pending | authorized | revoked
  user_id       INT UNSIGNED NULL,              -- usuario dueño (sp_users.id); NULL = sin asignar
  label         VARCHAR(100) NULL,              -- ALIAS libre puesto desde el panel
  app_version   VARCHAR(20)  NULL,              -- versión que informó el equipo
  last_ip       VARCHAR(45)  NULL,              -- última IP pública vista
  created_at    DATETIME     NOT NULL,          -- fecha de registro en la base (UTC)
  last_seen_at  DATETIME     NOT NULL,          -- última consulta de las páginas HTML (UTC)
  online_at     DATETIME     NULL,              -- última señal "online" de la app nativa Kodular (api/ping.php, UTC)
  status_at     DATETIME     NULL,              -- último cambio de estado desde el panel (UTC)
  PRIMARY KEY (device_id),
  KEY idx_status (status),
  KEY idx_last_seen (last_seen_at),
  KEY idx_online (online_at),
  KEY idx_user (user_id),
  CONSTRAINT fk_devices_user FOREIGN KEY (user_id)
    REFERENCES sp_users (id) ON DELETE SET NULL -- borrar el usuario deja sus equipos sin asignar
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contadores para limitar abusos (registros nuevos e intentos de login
-- por IP). Las filas viejas se borran solas al usarse.
CREATE TABLE IF NOT EXISTS sp_rate_limits (
  bucket        VARCHAR(100) NOT NULL,          -- p. ej. "register:1.2.3.4" o "login:1.2.3.4"
  window_start  INT UNSIGNED NOT NULL,          -- inicio de la ventana (unix, segundos)
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial de arranques: una fila cada vez que loading.html se ejecuta en
-- un equipo (api/device.php con event=boot). Se borra junto con el equipo
-- (ON DELETE CASCADE) y las filas más antiguas que boot_history_days
-- (config.php) se purgan solas al registrar un arranque nuevo.
CREATE TABLE IF NOT EXISTS sp_boots (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id     VARCHAR(32)  NOT NULL,          -- equipo (sp_devices.device_id)
  booted_at     DATETIME     NOT NULL,          -- momento del arranque (UTC, hora del servidor)
  ip            VARCHAR(45)  NULL,              -- IP pública desde la que arrancó
  app_version   VARCHAR(20)  NULL,              -- versión que informó en ese arranque
  PRIMARY KEY (id),
  KEY idx_device_time (device_id, booted_at),
  CONSTRAINT fk_boots_device FOREIGN KEY (device_id)
    REFERENCES sp_devices (device_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Archivos que sube cada usuario en /cuenta/. El archivo en sí se guarda
-- en private/media/ (fuera de public_html) con el nombre stored_name.
CREATE TABLE IF NOT EXISTS sp_media (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,          -- dueño (sp_users.id)
  kind          VARCHAR(5)   NOT NULL,          -- image | video
  mime          VARCHAR(40)  NOT NULL,          -- image/jpeg, video/mp4, ...
  original_name VARCHAR(190) NOT NULL,          -- nombre con el que se subió (solo para mostrar)
  stored_name   VARCHAR(80)  NOT NULL,          -- nombre en private/media/ (aleatorio + extensión)
  size_bytes    BIGINT UNSIGNED NOT NULL,       -- tamaño del archivo
  sha256        CHAR(64)     NOT NULL,          -- huella: si cambia, el equipo lo vuelve a descargar
  seconds       SMALLINT UNSIGNED NOT NULL DEFAULT 10, -- duración en pantalla (imágenes; los videos duran lo suyo)
  position      INT UNSIGNED NOT NULL DEFAULT 0, -- orden del carrusel (menor = antes)
  all_devices   TINYINT(1)   NOT NULL DEFAULT 1, -- 1 = en TODOS los equipos del usuario (también los futuros)
  created_at    DATETIME     NOT NULL,          -- subida (UTC)
  PRIMARY KEY (id),
  UNIQUE KEY uq_media_stored (stored_name),
  KEY idx_media_user (user_id, position),
  CONSTRAINT fk_media_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equipos concretos de un archivo (solo cuenta si all_devices = 0).
CREATE TABLE IF NOT EXISTS sp_media_devices (
  media_id      INT UNSIGNED NOT NULL,
  device_id     VARCHAR(32)  NOT NULL,
  PRIMARY KEY (media_id, device_id),
  KEY idx_md_device (device_id),
  CONSTRAINT fk_md_media FOREIGN KEY (media_id) REFERENCES sp_media (id) ON DELETE CASCADE,
  CONSTRAINT fk_md_device FOREIGN KEY (device_id) REFERENCES sp_devices (device_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

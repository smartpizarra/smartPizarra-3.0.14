-- =====================================================================
--  SMART PIZARRA - BASE DE DATOS DE DISPOSITIVOS (MySQL / MariaDB)
--  Importar UNA vez en phpMyAdmin (hPanel de Hostinger > Bases de datos
--  > phpMyAdmin > pestaña "Importar"), sobre la base creada para esto.
--  Se puede volver a importar: solo crea lo que falte.
-- =====================================================================

-- Grupos de equipos. Todo equipo pertenece a UN grupo. Deshabilitar un
-- grupo quita la autorización a todos sus equipos a la vez (sin tocar el
-- estado de cada uno: al volver a habilitarlo recuperan el que tenían).
-- El grupo 1 ("General") es el grupo por defecto de los equipos nuevos y
-- no se puede borrar (sí renombrar y deshabilitar).
CREATE TABLE IF NOT EXISTS sp_groups (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(60)  NOT NULL,          -- nombre visible (único)
  enabled       TINYINT(1)   NOT NULL DEFAULT 1, -- 1 habilitado | 0 deshabilitado
  created_at    DATETIME     NOT NULL,          -- fecha de creación (UTC)
  enabled_at    DATETIME     NULL,              -- último cambio habilitado/deshabilitado (UTC)
  PRIMARY KEY (id),
  UNIQUE KEY uq_group_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grupo por defecto (id 1). INSERT IGNORE: no falla si ya existe.
INSERT IGNORE INTO sp_groups (id, name, enabled, created_at) VALUES (1, 'General', 1, UTC_TIMESTAMP());

-- Un registro por TV box. La crea/actualiza api/device.php y la
-- autoriza o revoca el panel admin/.
CREATE TABLE IF NOT EXISTS sp_devices (
  device_id     VARCHAR(32)  NOT NULL,          -- "SP-XXXX-XXXX-XXXX" (localStorage smartpizarra.deviceId)
  secret_hash   CHAR(64)     NOT NULL,          -- SHA-256 del secreto del equipo (nunca el secreto)
  status        VARCHAR(10)  NOT NULL DEFAULT 'pending', -- pending | authorized | revoked
  group_id      INT UNSIGNED NOT NULL DEFAULT 1, -- grupo (sp_groups.id); 1 = General
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
  KEY idx_group (group_id),
  CONSTRAINT fk_devices_group FOREIGN KEY (group_id) REFERENCES sp_groups (id)
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

-- =====================================================================
--  SMART PIZARRA - BASE DE DATOS DE DISPOSITIVOS (MySQL / MariaDB)
--  Importar UNA vez en phpMyAdmin (hPanel de Hostinger > Bases de datos
--  > phpMyAdmin > pestaña "Importar"), sobre la base creada para esto.
--  Se puede volver a importar: solo crea lo que falte.
-- =====================================================================

-- Un registro por TV box. La crea/actualiza api/device.php y la
-- autoriza o revoca el panel admin/.
CREATE TABLE IF NOT EXISTS sp_devices (
  device_id     VARCHAR(32)  NOT NULL,          -- "SP-XXXX-XXXX-XXXX" (localStorage smartpizarra.deviceId)
  secret_hash   CHAR(64)     NOT NULL,          -- SHA-256 del secreto del equipo (nunca el secreto)
  status        VARCHAR(10)  NOT NULL DEFAULT 'pending', -- pending | authorized | revoked
  label         VARCHAR(100) NULL,              -- nombre libre puesto desde el panel
  app_version   VARCHAR(20)  NULL,              -- versión que informó el equipo
  last_ip       VARCHAR(45)  NULL,              -- última IP pública vista
  created_at    DATETIME     NOT NULL,          -- primer registro (UTC)
  last_seen_at  DATETIME     NOT NULL,          -- última consulta del equipo (UTC)
  status_at     DATETIME     NULL,              -- último cambio de estado desde el panel (UTC)
  PRIMARY KEY (device_id),
  KEY idx_status (status),
  KEY idx_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contadores para limitar abusos (registros nuevos e intentos de login
-- por IP). Las filas viejas se borran solas al usarse.
CREATE TABLE IF NOT EXISTS sp_rate_limits (
  bucket        VARCHAR(100) NOT NULL,          -- p. ej. "register:1.2.3.4" o "login:1.2.3.4"
  window_start  INT UNSIGNED NOT NULL,          -- inicio de la ventana (unix, segundos)
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

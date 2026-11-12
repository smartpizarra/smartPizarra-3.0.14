-- Versión del archivo: 1.0.0 (2026-11-12)
-- =====================================================================
--  MIGRACIÓN 2026-11-06: historial de arranques (loading.html)
--  Solo si la base YA existía (si importas schema.sql desde cero, la
--  tabla ya viene incluida). Aplicar DESPUÉS de 2026-11-06-online.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Es segura de repetir (CREATE TABLE IF NOT EXISTS).
-- =====================================================================
CREATE TABLE IF NOT EXISTS sp_boots (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id     VARCHAR(32)  NOT NULL,          -- equipo (sp_devices.device_id)
  booted_at     DATETIME     NOT NULL,          -- momento del arranque (UTC)
  ip            VARCHAR(45)  NULL,              -- IP pública desde la que arrancó
  app_version   VARCHAR(20)  NULL,              -- versión que informó en ese arranque
  PRIMARY KEY (id),
  KEY idx_device_time (device_id, booted_at),
  CONSTRAINT fk_boots_device FOREIGN KEY (device_id)
    REFERENCES sp_devices (device_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  MIGRACIÓN 2026-11-07: grupos de dispositivos
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-06-online.sql y
--  2026-11-06-boots.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Todos los equipos existentes quedan en el grupo "General" (id 1).
--  Si dice "Duplicate column name 'group_id'", ya estaba aplicada.
-- =====================================================================
CREATE TABLE IF NOT EXISTS sp_groups (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(60)  NOT NULL,          -- nombre visible (único)
  enabled       TINYINT(1)   NOT NULL DEFAULT 1, -- 1 habilitado | 0 deshabilitado
  created_at    DATETIME     NOT NULL,          -- fecha de creación (UTC)
  enabled_at    DATETIME     NULL,              -- último cambio habilitado/deshabilitado (UTC)
  PRIMARY KEY (id),
  UNIQUE KEY uq_group_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sp_groups (id, name, enabled, created_at) VALUES (1, 'General', 1, UTC_TIMESTAMP());

ALTER TABLE sp_devices
  ADD COLUMN group_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER status, -- grupo (sp_groups.id); 1 = General
  ADD KEY idx_group (group_id),
  ADD CONSTRAINT fk_devices_group FOREIGN KEY (group_id) REFERENCES sp_groups (id);

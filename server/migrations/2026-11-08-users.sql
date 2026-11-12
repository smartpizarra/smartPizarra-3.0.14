-- Versión del archivo: 1.0.0 (2026-11-12)
-- =====================================================================
--  MIGRACIÓN 2026-11-08: usuarios dueños de equipos
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-06-online.sql y
--  2026-11-06-boots.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Los equipos existentes quedan "sin asignar" (user_id NULL) con su estado.
--  Si dice "Duplicate column name 'user_id'", ya estaba aplicada.
-- =====================================================================
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

ALTER TABLE sp_devices
  ADD COLUMN user_id INT UNSIGNED NULL AFTER status, -- usuario dueño (sp_users.id); NULL = sin asignar
  ADD KEY idx_user (user_id),
  ADD CONSTRAINT fk_devices_user FOREIGN KEY (user_id)
    REFERENCES sp_users (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
--  SOLO si llegaste a ejecutar la migración de GRUPOS (2026-11-07,
--  ya retirada): quita sus restos. Quita los "-- " del principio de las
--  3 líneas y ejecútalas aparte.
-- ---------------------------------------------------------------------
-- ALTER TABLE sp_devices DROP FOREIGN KEY fk_devices_group;
-- ALTER TABLE sp_devices DROP COLUMN group_id;
-- DROP TABLE sp_groups;

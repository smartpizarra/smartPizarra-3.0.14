-- =====================================================================
--  MIGRACIÓN 2026-11-12: PERFILES DE EQUIPO
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-12-device-mac.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Si dice "Duplicate column name 'first_authorized_at'", ya estaba.
--
--  Un PERFIL es la ficha de la caja física: marca, modelo, MAC impresa
--  en el case y fecha de instalación (todos obligatorios). El
--  administrador los crea en el panel (botón "Perfiles") y asigna cada
--  uno a UN equipo. Para AUTORIZAR un equipo por PRIMERA vez, ese equipo
--  debe tener perfil. El buscador de equipos también busca por la MAC
--  del case, la marca y el modelo.
-- =====================================================================
CREATE TABLE IF NOT EXISTS sp_device_profiles (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  brand        VARCHAR(60)  NOT NULL,                 -- marca (p. ej. "Hisilicon")
  model        VARCHAR(60)  NOT NULL,                 -- modelo (p. ej. "TVBOX Hi3798")
  case_mac     VARCHAR(17)  NOT NULL,                 -- MAC impresa en el case "E8:BB:3E:C8:FB:5A" (única)
  installed_on DATE         NOT NULL,                 -- fecha de instalación
  device_id    VARCHAR(32)  NULL,                     -- equipo al que está asignado (uno a la vez); NULL = libre
  created_at   DATETIME     NOT NULL,                 -- UTC
  updated_at   DATETIME     NOT NULL,                 -- UTC
  PRIMARY KEY (id),
  UNIQUE KEY uq_profile_case_mac (case_mac),          -- una caja física = un perfil
  UNIQUE KEY uq_profile_device (device_id),           -- un equipo tiene como mucho un perfil
  CONSTRAINT fk_profile_device FOREIGN KEY (device_id) REFERENCES sp_devices (device_id)
    ON DELETE SET NULL ON UPDATE CASCADE              -- borrar el equipo deja el perfil libre
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Primera autorización de cada equipo (NULL = nunca autorizado).
ALTER TABLE sp_devices ADD COLUMN first_authorized_at DATETIME NULL DEFAULT NULL;

-- Los equipos que YA están autorizados (o revocados) no necesitan perfil
-- para volver a autorizarse: se toma como primera autorización la fecha
-- de su último cambio de estado (o la de alta).
UPDATE sp_devices SET first_authorized_at = COALESCE(status_at, created_at)
 WHERE status IN ('authorized', 'revoked');

-- =====================================================================
--  MIGRACIÓN 2026-11-11: LIGAS POR EQUIPO (las elige el usuario)
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-11-leagues.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Si dice "Duplicate column name 'leagues_all'", ya estaba aplicada.
--
--  El usuario, en /cuenta/ (pestaña Equipos), elige qué ligas de las que
--  le asignó el administrador usa cada uno de sus equipos:
--    leagues_all = 1  => TODAS las ligas de su usuario (por defecto; así
--                        quedan todos los equipos existentes)
--    leagues_all = 0  => solo las de sp_device_leagues (que sigan siendo
--                        de su usuario; si no queda ninguna => todas)
--  Al cambiar el equipo de usuario vuelve a "todas".
-- =====================================================================
ALTER TABLE sp_devices
  ADD COLUMN leagues_all TINYINT(1) NOT NULL DEFAULT 1;  -- 1 = todas las ligas de su usuario

CREATE TABLE IF NOT EXISTS sp_device_leagues (
  device_id   VARCHAR(32)  NOT NULL,
  league_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (device_id, league_id),
  KEY idx_dl_league (league_id),
  CONSTRAINT fk_dl_device FOREIGN KEY (device_id) REFERENCES sp_devices (device_id) ON DELETE CASCADE,
  CONSTRAINT fk_dl_league FOREIGN KEY (league_id) REFERENCES sp_leagues (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

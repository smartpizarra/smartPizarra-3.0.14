-- Versión del archivo: 1.0.0 (2026-11-12)
-- =====================================================================
--  MIGRACIÓN 2026-11-11: UNA LIGA POR EQUIPO (la elige el usuario)
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-11-leagues.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Si dice "Duplicate column name 'league_id'", ya estaba aplicada.
--
--  El usuario, en /cuenta/ (pestaña Equipos), elige para cada uno de sus
--  equipos "Sin liga" (NULL) o UNA de las ligas que le asignó el
--  administrador. La TV la recibe (api/device.php y api/media.php) y la
--  guarda en su localStorage: smartpizarra.league = código del tipo
--  (p. ej. "mlb") y smartpizarra.leagues = códigos ("LIDOM,MLB"); sin
--  liga, los dos quedan "" (vacío). Todos los equipos empiezan sin liga.
--  Si el administrador le quita esa liga al usuario, o el equipo cambia
--  de usuario, el equipo vuelve a "Sin liga".
-- =====================================================================
ALTER TABLE sp_devices
  ADD COLUMN league_id INT UNSIGNED NULL DEFAULT NULL,   -- liga del equipo (sp_leagues.id); NULL = sin liga
  ADD KEY idx_device_league (league_id),
  ADD CONSTRAINT fk_device_league FOREIGN KEY (league_id) REFERENCES sp_leagues (id) ON DELETE SET NULL;

-- Solo si llegaste a ejecutar la versión ANTERIOR de esta migración
-- (varias ligas por equipo), quita sus restos quitando los "-- ":
-- DROP TABLE IF EXISTS sp_device_leagues;
-- ALTER TABLE sp_devices DROP COLUMN leagues_all;

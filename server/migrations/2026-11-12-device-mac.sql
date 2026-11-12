-- Versión del archivo: 1.0.0 (2026-11-12)
-- =====================================================================
--  MIGRACIÓN 2026-11-12: DIRECCIÓN MAC DE CADA EQUIPO
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). phpMyAdmin > tu base > pestaña "SQL" > pegar y
--  ejecutar UNA vez. Si dice "Duplicate column name 'mac'", ya estaba.
--
--  La app (Kodular) lee la MAC y se la pasa a loading.html con setMac();
--  loading.html la envía a api/device.php. Una MAC solo puede estar en UN
--  equipo: si llega desde otro (la misma TV tras restablecerla de
--  fábrica, con un ID nuevo), se le quita al anterior y pasa al nuevo
--  (mac_from = ID anterior). El panel busca equipos también por MAC.
-- =====================================================================
ALTER TABLE sp_devices
  ADD COLUMN mac      VARCHAR(17) NULL DEFAULT NULL,  -- "E8:BB:3E:C8:FB:5A"
  ADD COLUMN mac_at   DATETIME    NULL DEFAULT NULL,  -- cuándo se le asignó (UTC)
  ADD COLUMN mac_from VARCHAR(32) NULL DEFAULT NULL,  -- equipo que la tenía antes
  ADD UNIQUE KEY uq_device_mac (mac);                 -- nunca dos equipos con la misma MAC

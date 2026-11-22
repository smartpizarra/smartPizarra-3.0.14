-- Versión del archivo: 1.0.0 (2026-11-22)
-- =====================================================================
--  MIGRACIÓN 2026-11-22: S/N (NÚMERO DE SERIE) EN EL PERFIL DEL EQUIPO
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-12-device-profiles.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Si dice "Duplicate column name 'serial'", ya estaba.
--
--  S/N impreso en la caja física. OPCIONAL (los perfiles que ya existen
--  quedan sin él) y ÚNICO cuando se escribe (NULL se puede repetir).
--  El panel lo muestra en el perfil y en la tarjeta del equipo, y los
--  buscadores de equipos y de perfiles buscan también por él.
-- =====================================================================
ALTER TABLE sp_device_profiles
  ADD COLUMN serial VARCHAR(60) NULL DEFAULT NULL AFTER case_mac,   -- S/N de la caja (NULL = sin registrar)
  ADD UNIQUE KEY uq_profile_serial (serial);                         -- un S/N = una caja física

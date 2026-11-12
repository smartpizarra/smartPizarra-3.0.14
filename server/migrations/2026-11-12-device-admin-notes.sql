-- Versión del archivo: 1.0.0 (2026-11-12)
-- =====================================================================
--  MIGRACIÓN 2026-11-12: ALIAS Y NOTAS DEL ADMINISTRADOR POR EQUIPO
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). phpMyAdmin > tu base > pestaña "SQL" > pegar y
--  ejecutar UNA vez. Si dice "Duplicate column name 'admin_alias'", ya estaba.
--
--  admin_alias: alias ADICIONAL que solo ve el administrador (aparte del
--  nombre/alias "label", que también ve y edita el usuario). El buscador
--  del panel encuentra el equipo por él; el de /cuenta/ NO.
--  admin_notes: notas internas del administrador (el usuario no las ve).
-- =====================================================================
ALTER TABLE sp_devices
  ADD COLUMN admin_alias VARCHAR(100) NULL DEFAULT NULL,
  ADD COLUMN admin_notes TEXT         NULL DEFAULT NULL;

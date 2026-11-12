-- Versión del archivo: 1.0.0 (2026-11-12)
-- =====================================================================
--  MIGRACIÓN 2026-11-11: FECHAS en el contenido de los usuarios
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-11-ads.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Si dice "Duplicate column name 'starts_at'", ya estaba aplicada.
--
--  Cada imagen/video que sube el usuario en /cuenta/ puede ser
--  PERMANENTE (sin fechas, NULL) o mostrarse solo entre starts_at y
--  ends_at (UTC, cualquiera de las dos opcional), igual que los anuncios
--  del administrador. Los archivos existentes quedan permanentes.
-- =====================================================================
ALTER TABLE sp_media
  ADD COLUMN starts_at DATETIME NULL DEFAULT NULL AFTER all_devices,  -- se muestra desde (UTC); NULL = ya
  ADD COLUMN ends_at   DATETIME NULL DEFAULT NULL AFTER starts_at;    -- se muestra hasta (UTC, sin incluir); NULL = sin fin

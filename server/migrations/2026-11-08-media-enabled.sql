-- Versión del archivo: 1.0.0 (2026-11-12)
-- =====================================================================
--  MIGRACIÓN 2026-11-08 (3): permiso de CONTENIDO por equipo
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-08-media.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Todos los equipos quedan SIN el permiso: el administrador lo habilita
--  uno a uno en el panel. Si dice "Duplicate column name 'media_enabled'",
--  ya estaba aplicada.
-- =====================================================================
ALTER TABLE sp_devices
  ADD COLUMN media_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status, -- 1 = puede mostrar imágenes/videos (mirror.html)
  ADD COLUMN media_enabled_at DATETIME NULL AFTER media_enabled;      -- último cambio del permiso (UTC)

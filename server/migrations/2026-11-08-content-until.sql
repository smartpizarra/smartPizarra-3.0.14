-- Versión del archivo: 1.0.0 (2026-11-12)
-- =====================================================================
--  MIGRACIÓN 2026-11-08 (4): VIGENCIA del contenido por usuario
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-08-media-enabled.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Los usuarios existentes quedan SIN vigencia: sus equipos no muestran
--  contenido hasta que el administrador les sume días en el panel.
--  Si dice "Duplicate column name 'content_until'", ya estaba aplicada.
-- =====================================================================
ALTER TABLE sp_users
  ADD COLUMN content_until DATETIME NULL AFTER last_login_at, -- el contenido se muestra hasta esta fecha (UTC); NULL = sin vigencia
  ADD COLUMN content_changed_at DATETIME NULL AFTER content_until; -- último cambio de la vigencia (UTC)

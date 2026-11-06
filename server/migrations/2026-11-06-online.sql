-- =====================================================================
--  MIGRACIÓN 2026-11-06: señal "online" de la app nativa Kodular
--  Solo si la tabla sp_devices YA existía antes de esta fecha (si importas
--  schema.sql desde cero, la columna ya viene incluida).
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Si dice "Duplicate column name 'online_at'", ya estaba aplicada.
-- =====================================================================
ALTER TABLE sp_devices
  ADD COLUMN online_at DATETIME NULL AFTER last_seen_at, -- última señal de la app nativa (UTC)
  ADD KEY idx_online (online_at);

-- Versión del archivo: 1.0.0 (2026-11-22)
-- =====================================================================
--  MIGRACIÓN 2026-11-22: COMENTARIO DE LOS PLANES
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-22-plans.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Si dice "Duplicate column name 'comment'", ya estaba.
--
--  Texto libre del administrador para cada plan (para quién es, qué
--  incluye...). El usuario lo ve en /cuenta/ → Mi plan, junto a todos
--  los planes disponibles y sus características. Opcional.
-- =====================================================================
ALTER TABLE sp_plans
  ADD COLUMN comment VARCHAR(500) NULL DEFAULT NULL AFTER currency;   -- comentario visible para los usuarios (NULL = sin comentario)

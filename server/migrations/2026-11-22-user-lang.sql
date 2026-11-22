-- Versión del archivo: 1.0.0 (2026-11-22)
-- =====================================================================
--  MIGRACIÓN 2026-11-22: IDIOMA DEL PANEL DE CADA USUARIO
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). phpMyAdmin > tu base > pestaña "SQL" > pegar y
--  ejecutar UNA vez. Si dice "Duplicate column name 'lang'", ya estaba.
--
--  Idioma que el usuario elige en /cuenta/ (selector ES / EN de la
--  cabecera): 'es' o 'en'. NULL = aún no eligió (se usa el idioma de su
--  navegador).
-- =====================================================================
ALTER TABLE sp_users
  ADD COLUMN lang CHAR(2) NULL DEFAULT NULL;   -- idioma de /cuenta/: es | en (NULL = el del navegador)

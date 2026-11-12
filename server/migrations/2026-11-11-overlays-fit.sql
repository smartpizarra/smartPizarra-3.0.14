-- =====================================================================
--  MIGRACIÓN 2026-11-11: AJUSTE DE LA IMAGEN DEL BANNER
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-11-overlays.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Si dice "Duplicate column name 'image_fit'", ya estaba aplicada.
--
--  image_fit = contain  => proporcional: no se deforma; si la imagen no es
--                          lo bastante ancha queda centrada en la franja.
--              stretch  => estirada en horizontal hasta llenar todo el
--                          ancho de la pantalla (se deforma).
--  Se elige en el panel (administrador y /cuenta/) al subir o editar el banner.
-- =====================================================================
ALTER TABLE sp_overlays
  ADD COLUMN image_fit VARCHAR(8) NOT NULL DEFAULT 'contain' AFTER band_size;

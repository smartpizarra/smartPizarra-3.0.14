-- =====================================================================
--  MIGRACIÓN 2026-11-23: NOMBRE DEL DISPOSITIVO EN LOS MOVIMIENTOS DE CRÉDITOS
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-23-tariffs.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Si dice "Duplicate column name 'device_name'", ya estaba.
--
--  Cada cobro guarda el NOMBRE que tenía el dispositivo en ese momento
--  (así el historial lo conserva aunque luego se renombre o se elimine).
--  Los movimientos que ya existían reciben el nombre actual del equipo.
-- =====================================================================
ALTER TABLE sp_credit_moves
  ADD COLUMN device_name VARCHAR(100) NULL DEFAULT NULL AFTER device_id;   -- nombre del equipo al cobrar (NULL = sin nombre)

UPDATE sp_credit_moves m JOIN sp_devices d ON d.device_id = m.device_id
   SET m.device_name = d.label
 WHERE m.device_name IS NULL AND d.label IS NOT NULL AND d.label <> '';

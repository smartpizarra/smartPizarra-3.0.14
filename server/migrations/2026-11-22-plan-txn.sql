-- Versión del archivo: 1.0.0 (2026-11-22)
-- =====================================================================
--  MIGRACIÓN 2026-11-22: ID DE TRANSACCIÓN EN EL HISTORIAL DE PLANES
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-22-plans.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Si dice "Duplicate column name 'txn_id'", ya estaba.
--
--  Cada asignación o renovación de plan tiene un ID de transacción
--  único, p. ej. "TX-20261122-4F7A2C" (fecha + 6 caracteres). Lo ven el
--  administrador y el usuario, y los dos pueden buscar por él y por
--  fechas en el historial. Las líneas que ya existían reciben uno
--  calculado con su fecha y su número.
-- =====================================================================
ALTER TABLE sp_plan_renewals
  ADD COLUMN txn_id VARCHAR(20) NULL DEFAULT NULL AFTER id;     -- ID de la transacción (único)

UPDATE sp_plan_renewals
   SET txn_id = CONCAT('TX-', DATE_FORMAT(created_at, '%Y%m%d'), '-', LPAD(UPPER(HEX(id)), 6, '0'))
 WHERE txn_id IS NULL;

ALTER TABLE sp_plan_renewals
  ADD UNIQUE KEY uq_renewal_txn (txn_id),
  ADD KEY idx_renewal_date (created_at);                        -- búsqueda por fechas

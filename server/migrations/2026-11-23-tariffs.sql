-- Versión del archivo: 1.0.0 (2026-11-22)
-- =====================================================================
--  MIGRACIÓN 2026-11-23: TARIFAS Y CRÉDITOS (sustituyen a planes y vigencia)
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-22-plans.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Si dice "Duplicate column name 'tariff_id'", ya estaba.
--
--  El administrador crea TARIFAS (precio por imagen y por video al día,
--  máximo de archivos, almacenamiento, moneda y comentario) y asigna una a
--  cada usuario. Cada usuario tiene un saldo de CRÉDITOS que el
--  administrador recarga o descuenta. El USUARIO activa el contenido de
--  cada dispositivo: al activarlo se cobra el primer día (archivos que
--  muestra ese dispositivo × precio de la tarifa) y después cada 24 h
--  mientras siga activo (sp_devices.media_paid_until). Si no alcanza, el
--  contenido de ese dispositivo se desactiva solo. Cada recarga, ajuste y
--  cobro queda en sp_credit_moves con su ID de transacción.
--  Los planes y la vigencia (sp_plans, sp_plan_renewals, content_until)
--  se conservan en la base pero los paneles ya no los usan.
-- =====================================================================
CREATE TABLE IF NOT EXISTS sp_tariffs (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name         VARCHAR(60)   NOT NULL,                 -- nombre de la tarifa (único)
  image_price  DECIMAL(10,4) NOT NULL DEFAULT 0,       -- créditos por imagen, por dispositivo y por día
  video_price  DECIMAL(10,4) NOT NULL DEFAULT 0,       -- créditos por video, por dispositivo y por día
  max_files    INT UNSIGNED  NOT NULL,                 -- máximo de archivos de contenido
  storage_mb   INT UNSIGNED  NOT NULL,                 -- almacenamiento en MB
  currency     CHAR(3)       NOT NULL DEFAULT 'USD',   -- moneda de los créditos
  comment      VARCHAR(500)  NULL DEFAULT NULL,        -- comentario visible para los usuarios
  created_at   DATETIME      NOT NULL,                 -- UTC
  updated_at   DATETIME      NOT NULL,                 -- UTC
  PRIMARY KEY (id),
  UNIQUE KEY uq_tariff_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sp_users
  ADD COLUMN tariff_id INT UNSIGNED  NULL DEFAULT NULL,      -- sp_tariffs.id (NULL = sin tarifa)
  ADD COLUMN credits   DECIMAL(12,4) NOT NULL DEFAULT 0,     -- saldo de créditos
  ADD KEY idx_user_tariff (tariff_id),
  ADD CONSTRAINT fk_user_tariff FOREIGN KEY (tariff_id) REFERENCES sp_tariffs (id) ON DELETE SET NULL;

-- Hasta cuándo está pagado el contenido de cada dispositivo (UTC; NULL = nunca)
ALTER TABLE sp_devices
  ADD COLUMN media_paid_until DATETIME NULL DEFAULT NULL;

-- Movimientos de créditos: recargas y ajustes del administrador, cobros diarios y desactivaciones
CREATE TABLE IF NOT EXISTS sp_credit_moves (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  txn_id        VARCHAR(20)   NOT NULL,               -- ID de transacción "TX-AAAAMMDD-XXXXXX" (único)
  user_id       INT UNSIGNED  NOT NULL,
  kind          VARCHAR(10)   NOT NULL,               -- topup (recarga) | adjust (descuento manual) | charge (cobro diario) | off (desactivado por saldo)
  amount        DECIMAL(12,4) NOT NULL,               -- + recarga / - descuento o cobro / 0 desactivación
  balance_after DECIMAL(12,4) NOT NULL,               -- saldo después del movimiento
  device_id     VARCHAR(32)   NULL DEFAULT NULL,      -- equipo del cobro (sin FK: el historial se conserva)
  images        INT UNSIGNED  NOT NULL DEFAULT 0,     -- imágenes cobradas
  videos        INT UNSIGNED  NOT NULL DEFAULT 0,     -- videos cobrados
  note          VARCHAR(255)  NULL DEFAULT NULL,      -- nota del administrador o detalle
  created_at    DATETIME      NOT NULL,               -- UTC
  PRIMARY KEY (id),
  UNIQUE KEY uq_credit_txn (txn_id),
  KEY idx_credit_user (user_id, id),
  KEY idx_credit_date (created_at),
  CONSTRAINT fk_credit_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

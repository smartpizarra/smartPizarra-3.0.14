-- =====================================================================
--  MIGRACIÓN 2026-11-22: PLANES DE CONTENIDO
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). phpMyAdmin > tu base > pestaña "SQL" > pegar y
--  ejecutar UNA vez. Si dice "Duplicate column name 'plan_id'", ya estaba.
--
--  El administrador crea PLANES (nombre, días, cantidad de archivos,
--  almacenamiento y costo) y asigna uno a cada usuario. Asignar o
--  RENOVAR (botón del panel) suma los días del plan a la fecha de fin
--  del plan (plan_until) y a la VIGENCIA del contenido (content_until),
--  y deja una línea en el HISTORIAL (sp_plan_renewals), que el usuario
--  ve en /cuenta/. Sin plan vigente el usuario no puede subir archivos
--  nuevos (lo ya subido no se borra). La vigencia se sigue pudiendo
--  cambiar a mano, con plan o sin él.
-- =====================================================================
CREATE TABLE IF NOT EXISTS sp_plans (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name        VARCHAR(60)   NOT NULL,                 -- nombre del plan (p. ej. "Lite"); único
  days        INT UNSIGNED  NOT NULL,                 -- duración en días (se suman al asignar/renovar)
  max_files   INT UNSIGNED  NOT NULL,                 -- cantidad de archivos de contenido
  storage_mb  INT UNSIGNED  NOT NULL,                 -- almacenamiento en MB (1 GB = 1024 MB)
  price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,    -- costo del periodo
  currency    CHAR(3)       NOT NULL DEFAULT 'USD',   -- moneda del costo
  created_at  DATETIME      NOT NULL,                 -- UTC
  updated_at  DATETIME      NOT NULL,                 -- UTC
  PRIMARY KEY (id),
  UNIQUE KEY uq_plan_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plan de cada usuario y hasta cuándo está pagado (NULL = sin plan)
ALTER TABLE sp_users
  ADD COLUMN plan_id    INT UNSIGNED NULL DEFAULT NULL,   -- sp_plans.id; borrar el plan deja al usuario sin plan
  ADD COLUMN plan_until DATETIME     NULL DEFAULT NULL,   -- fin del periodo del plan (UTC)
  ADD KEY idx_user_plan (plan_id),
  ADD CONSTRAINT fk_user_plan FOREIGN KEY (plan_id) REFERENCES sp_plans (id) ON DELETE SET NULL;

-- HISTORIAL de asignaciones y renovaciones (con los datos del plan en ese momento)
CREATE TABLE IF NOT EXISTS sp_plan_renewals (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED  NOT NULL,               -- usuario
  plan_id       INT UNSIGNED  NULL,                   -- plan (NULL si luego se borró; quedan sus datos abajo)
  kind          VARCHAR(8)    NOT NULL,               -- assign (asignación) | renew (renovación)
  plan_name     VARCHAR(60)   NOT NULL,               -- copia de los datos del plan en ese momento
  days          INT UNSIGNED  NOT NULL,
  max_files     INT UNSIGNED  NOT NULL,
  storage_mb    INT UNSIGNED  NOT NULL,
  price         DECIMAL(10,2) NOT NULL,
  currency      CHAR(3)       NOT NULL,
  plan_until    DATETIME      NOT NULL,               -- nuevo fin del plan tras esta renovación (UTC)
  content_until DATETIME      NULL,                   -- nueva vigencia del contenido tras esta renovación (UTC)
  created_at    DATETIME      NOT NULL,               -- cuándo se renovó (UTC)
  PRIMARY KEY (id),
  KEY idx_renewal_user (user_id, id),
  CONSTRAINT fk_renewal_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_renewal_plan FOREIGN KEY (plan_id) REFERENCES sp_plans (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

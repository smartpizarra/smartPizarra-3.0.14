-- =====================================================================
--  MIGRACIÓN 2026-11-11: ANUNCIOS del administrador
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de las migraciones del 2026-11-08.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Si dice "Table 'sp_ads' already exists", ya estaba aplicada.
--
--  Un anuncio es una imagen o un video que sube el administrador y que
--  mirror.html intercala cada "every_n" archivos del usuario. Destino:
--  todos los equipos (scope=all), los equipos de ciertos usuarios
--  (scope=users, tabla sp_ad_users) o equipos concretos (scope=devices,
--  tabla sp_ad_devices). Solo lo muestran equipos AUTORIZADOS (no exige
--  vigencia ni permiso de contenido). Fechas opcionales (UTC).
-- =====================================================================
CREATE TABLE IF NOT EXISTS sp_ads (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(100) NOT NULL,          -- nombre para el panel (p. ej. "Promo diciembre")
  kind          VARCHAR(5)   NOT NULL,          -- image | video
  mime          VARCHAR(40)  NOT NULL,
  original_name VARCHAR(190) NOT NULL,          -- nombre con el que se subió
  stored_name   VARCHAR(80)  NOT NULL,          -- nombre en private/media/ (aleatorio + extensión)
  size_bytes    BIGINT UNSIGNED NOT NULL,
  sha256        CHAR(64)     NOT NULL,          -- huella: si cambia, el equipo lo vuelve a descargar
  seconds       SMALLINT UNSIGNED NOT NULL DEFAULT 10, -- duración en pantalla (imágenes)
  every_n       SMALLINT UNSIGNED NOT NULL DEFAULT 3,  -- se muestra después de cada N archivos del usuario
  scope         VARCHAR(8)   NOT NULL DEFAULT 'all',   -- all | users | devices
  active        TINYINT(1)   NOT NULL DEFAULT 1,       -- 0 = pausado
  starts_at     DATETIME     NULL,              -- desde (UTC); NULL = ya
  ends_at       DATETIME     NULL,              -- hasta (UTC); NULL = sin fin
  position      INT UNSIGNED NOT NULL DEFAULT 0, -- orden entre anuncios
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ads_stored (stored_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuarios destino (scope = users): todos sus equipos, también los futuros.
CREATE TABLE IF NOT EXISTS sp_ad_users (
  ad_id         INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (ad_id, user_id),
  KEY idx_adu_user (user_id),
  CONSTRAINT fk_adu_ad FOREIGN KEY (ad_id) REFERENCES sp_ads (id) ON DELETE CASCADE,
  CONSTRAINT fk_adu_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equipos destino (scope = devices).
CREATE TABLE IF NOT EXISTS sp_ad_devices (
  ad_id         INT UNSIGNED NOT NULL,
  device_id     VARCHAR(32)  NOT NULL,
  PRIMARY KEY (ad_id, device_id),
  KEY idx_add_device (device_id),
  CONSTRAINT fk_add_ad FOREIGN KEY (ad_id) REFERENCES sp_ads (id) ON DELETE CASCADE,
  CONSTRAINT fk_add_device FOREIGN KEY (device_id) REFERENCES sp_devices (device_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

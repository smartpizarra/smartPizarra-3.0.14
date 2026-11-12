-- =====================================================================
--  MIGRACIÓN 2026-11-11: TIRAS (texto) y BANNERS (imagen) en mirror.html
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-11-ads.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Si dice "Table 'sp_overlays' already exists", ya estaba aplicada.
--
--  Una franja horizontal ARRIBA o ABAJO de la pantalla de mirror.html:
--    - kind = text  => TIRA: mensaje con colores de fondo/letra, fija o
--                      desplazándose (velocidad lenta/normal/rápida).
--    - kind = image => BANNER: imagen (se descarga UNA vez en el equipo).
--  La crea el USUARIO en /cuenta/ (user_id = el usuario; destino: todos
--  sus equipos o algunos) o el ADMINISTRADOR en el panel (user_id NULL;
--  destino: todos los equipos, los de ciertos usuarios o equipos
--  concretos). Fechas opcionales (UTC) y pausa (active = 0).
--  En cada equipo se muestra como mucho UNA por posición: primero la del
--  administrador, luego la más concreta (equipo > usuario > todos) y,
--  a igualdad, la editada más recientemente (OverlayRepository).
-- =====================================================================
CREATE TABLE IF NOT EXISTS sp_overlays (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NULL DEFAULT NULL,        -- dueño (sp_users.id); NULL = del administrador
  name          VARCHAR(100) NOT NULL,                 -- nombre para el panel
  kind          VARCHAR(5)   NOT NULL,                 -- text (tira) | image (banner)
  message       VARCHAR(300) NULL DEFAULT NULL,        -- texto de la tira (kind = text)
  bg_color      CHAR(7)      NOT NULL DEFAULT '#000000', -- fondo (#RRGGBB)
  fg_color      CHAR(7)      NOT NULL DEFAULT '#FFFFFF', -- letra (#RRGGBB; solo tiras)
  motion        VARCHAR(6)   NOT NULL DEFAULT 'static',  -- static (fija) | scroll (desplazándose)
  speed         VARCHAR(6)   NOT NULL DEFAULT 'normal',  -- slow | normal | fast (solo scroll)
  band_size     CHAR(1)      NOT NULL DEFAULT 'm',       -- alto de la franja: s | m | l
  image_fit     VARCHAR(8)   NOT NULL DEFAULT 'contain', -- banner: contain (proporcional, centrada) | stretch (estirada a todo el ancho)
  place         VARCHAR(6)   NOT NULL DEFAULT 'bottom',  -- top (arriba) | bottom (abajo)
  mime          VARCHAR(40)  NULL DEFAULT NULL,        -- imagen del banner (kind = image)
  original_name VARCHAR(190) NULL DEFAULT NULL,
  stored_name   VARCHAR(80)  NULL DEFAULT NULL,        -- nombre en private/media/ (ov-aleatorio.ext)
  size_bytes    BIGINT UNSIGNED NULL DEFAULT NULL,
  sha256        CHAR(64)     NULL DEFAULT NULL,        -- huella: si cambia, el equipo la vuelve a descargar
  scope         VARCHAR(8)   NOT NULL DEFAULT 'none',  -- none (sin asignar) | all | users (solo admin) | devices
  active        TINYINT(1)   NOT NULL DEFAULT 1,       -- 0 = pausada
  starts_at     DATETIME     NULL DEFAULT NULL,        -- desde (UTC); NULL = ya
  ends_at       DATETIME     NULL DEFAULT NULL,        -- hasta (UTC, sin incluir); NULL = sin fin
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_overlay_stored (stored_name),
  KEY idx_overlay_user (user_id),
  CONSTRAINT fk_overlay_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuarios destino (solo tiras del administrador con scope = users): todos sus equipos, también los futuros.
CREATE TABLE IF NOT EXISTS sp_overlay_users (
  overlay_id    INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  PRIMARY KEY (overlay_id, user_id),
  KEY idx_ovu_user (user_id),
  CONSTRAINT fk_ovu_overlay FOREIGN KEY (overlay_id) REFERENCES sp_overlays (id) ON DELETE CASCADE,
  CONSTRAINT fk_ovu_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equipos destino (scope = devices; las del usuario solo pueden ser equipos suyos).
CREATE TABLE IF NOT EXISTS sp_overlay_devices (
  overlay_id    INT UNSIGNED NOT NULL,
  device_id     VARCHAR(32)  NOT NULL,
  PRIMARY KEY (overlay_id, device_id),
  KEY idx_ovd_device (device_id),
  CONSTRAINT fk_ovd_overlay FOREIGN KEY (overlay_id) REFERENCES sp_overlays (id) ON DELETE CASCADE,
  CONSTRAINT fk_ovd_device FOREIGN KEY (device_id) REFERENCES sp_devices (device_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

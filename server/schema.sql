-- Versión del archivo: 1.0.2 (2026-11-12)
-- =====================================================================
--  SMART PIZARRA - BASE DE DATOS DE DISPOSITIVOS (MySQL / MariaDB)
--  Importar UNA vez en phpMyAdmin (hPanel de Hostinger > Bases de datos
--  > phpMyAdmin > pestaña "Importar"), sobre la base creada para esto.
--  Se puede volver a importar: solo crea lo que falte.
-- =====================================================================

-- Usuarios (empresas) dueños de equipos. Los crea el administrador en
-- el panel (email, teléfono, empresa); entran en /cuenta/ con su email
-- y cambian su contraseña. Solo VEN sus equipos: autorizar y revocar
-- (también "revocar todos" los de un usuario) lo hace el administrador.
CREATE TABLE IF NOT EXISTS sp_users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email                VARCHAR(190) NOT NULL,          -- email de acceso (único, minúsculas)
  phone                VARCHAR(30)  NOT NULL,          -- teléfono de contacto
  company              VARCHAR(100) NOT NULL,          -- nombre de la empresa
  password_hash        VARCHAR(255) NOT NULL,          -- password_hash() de PHP (nunca la contraseña)
  must_change_password TINYINT(1)   NOT NULL DEFAULT 1, -- 1 = contraseña temporal del administrador
  created_at           DATETIME     NOT NULL,          -- alta (UTC)
  password_changed_at  DATETIME     NULL,              -- último cambio de contraseña (UTC)
  last_login_at        DATETIME     NULL,              -- último acceso a /cuenta/ (UTC)
  content_until        DATETIME     NULL,              -- VIGENCIA: el contenido se muestra hasta esta fecha (UTC); NULL = sin vigencia
  content_changed_at   DATETIME     NULL,              -- último cambio de la vigencia (UTC)
  max_files            INT UNSIGNED NOT NULL DEFAULT 5,   -- LÍMITE de archivos de contenido que puede subir (lo cambia el administrador)
  route_id             INT UNSIGNED NULL,              -- RUTA del usuario (sp_routes.id; NULL = sin ruta). FK al final
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un registro por TV box. La crea/actualiza api/device.php y la
-- autoriza o revoca el panel admin/.
CREATE TABLE IF NOT EXISTS sp_devices (
  device_id     VARCHAR(32)  NOT NULL,          -- "SP-XXXX-XXXX-XXXX" (localStorage smartpizarra.deviceId)
  secret_hash   CHAR(64)     NOT NULL,          -- SHA-256 del secreto del equipo (nunca el secreto)
  status        VARCHAR(10)  NOT NULL DEFAULT 'pending', -- pending | authorized | revoked
  media_enabled TINYINT(1)   NOT NULL DEFAULT 0, -- 1 = el administrador le permite mostrar imágenes/videos (mirror.html)
  media_enabled_at DATETIME  NULL,              -- último cambio de ese permiso (UTC)
  league_id     INT UNSIGNED NULL,              -- liga del equipo (sp_leagues.id, la elige el usuario); NULL = sin liga. FK al final del archivo
  user_id       INT UNSIGNED NULL,              -- usuario dueño (sp_users.id); NULL = sin asignar
  label         VARCHAR(100) NULL,              -- ALIAS libre puesto desde el panel
  app_version   VARCHAR(20)  NULL,              -- versión que informó el equipo
  last_ip       VARCHAR(45)  NULL,              -- última IP pública vista
  created_at    DATETIME     NOT NULL,          -- fecha de registro en la base (UTC)
  last_seen_at  DATETIME     NOT NULL,          -- última consulta de las páginas HTML (UTC)
  online_at     DATETIME     NULL,              -- última señal "online" de la app nativa Kodular (api/ping.php, UTC)
  status_at     DATETIME     NULL,              -- último cambio de estado desde el panel (UTC)
  mac           VARCHAR(17)  NULL,              -- MAC "E8:BB:3E:C8:FB:5A" (la envía loading.html); UNA por equipo, nunca repetida
  mac_at        DATETIME     NULL,              -- cuándo se le asignó esa MAC (UTC)
  mac_from      VARCHAR(32)  NULL,              -- equipo que tenía antes esa MAC (p. ej. la misma TV antes de restablecerla)
  first_authorized_at DATETIME NULL,            -- PRIMERA autorización (UTC); NULL = nunca autorizado => para autorizarlo necesita PERFIL
  admin_alias   VARCHAR(100) NULL,              -- alias ADICIONAL solo para el administrador (el usuario no lo ve); se busca por él
  admin_notes   TEXT         NULL,              -- notas internas del administrador (el usuario no las ve)
  PRIMARY KEY (device_id),
  UNIQUE KEY uq_device_mac (mac),               -- una MAC solo puede estar en un equipo (NULL se puede repetir)
  KEY idx_status (status),
  KEY idx_last_seen (last_seen_at),
  KEY idx_online (online_at),
  KEY idx_user (user_id),
  CONSTRAINT fk_devices_user FOREIGN KEY (user_id)
    REFERENCES sp_users (id) ON DELETE SET NULL -- borrar el usuario deja sus equipos sin asignar
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contadores para limitar abusos (registros nuevos e intentos de login
-- por IP). Las filas viejas se borran solas al usarse.
CREATE TABLE IF NOT EXISTS sp_rate_limits (
  bucket        VARCHAR(100) NOT NULL,          -- p. ej. "register:1.2.3.4" o "login:1.2.3.4"
  window_start  INT UNSIGNED NOT NULL,          -- inicio de la ventana (unix, segundos)
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial de arranques: una fila cada vez que loading.html se ejecuta en
-- un equipo (api/device.php con event=boot). Se borra junto con el equipo
-- (ON DELETE CASCADE) y las filas más antiguas que boot_history_days
-- (config.php) se purgan solas al registrar un arranque nuevo.
CREATE TABLE IF NOT EXISTS sp_boots (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id     VARCHAR(32)  NOT NULL,          -- equipo (sp_devices.device_id)
  booted_at     DATETIME     NOT NULL,          -- momento del arranque (UTC, hora del servidor)
  ip            VARCHAR(45)  NULL,              -- IP pública desde la que arrancó
  app_version   VARCHAR(20)  NULL,              -- versión que informó en ese arranque
  PRIMARY KEY (id),
  KEY idx_device_time (device_id, booted_at),
  CONSTRAINT fk_boots_device FOREIGN KEY (device_id)
    REFERENCES sp_devices (device_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Archivos que sube cada usuario en /cuenta/. El archivo en sí se guarda
-- en private/media/ (fuera de public_html) con el nombre stored_name.
CREATE TABLE IF NOT EXISTS sp_media (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,          -- dueño (sp_users.id)
  kind          VARCHAR(5)   NOT NULL,          -- image | video
  mime          VARCHAR(40)  NOT NULL,          -- image/jpeg, video/mp4, ...
  original_name VARCHAR(190) NOT NULL,          -- nombre con el que se subió (solo para mostrar)
  stored_name   VARCHAR(80)  NOT NULL,          -- nombre en private/media/ (aleatorio + extensión)
  size_bytes    BIGINT UNSIGNED NOT NULL,       -- tamaño del archivo
  sha256        CHAR(64)     NOT NULL,          -- huella: si cambia, el equipo lo vuelve a descargar
  seconds       SMALLINT UNSIGNED NOT NULL DEFAULT 10, -- duración en pantalla (imágenes; los videos duran lo suyo)
  position      INT UNSIGNED NOT NULL DEFAULT 0, -- orden del carrusel (menor = antes)
  all_devices   TINYINT(1)   NOT NULL DEFAULT 1, -- 1 = en TODOS los equipos del usuario (también los futuros)
  starts_at     DATETIME     NULL DEFAULT NULL, -- se muestra desde (UTC); NULL = ya (permanente si ends_at también es NULL)
  ends_at       DATETIME     NULL DEFAULT NULL, -- se muestra hasta (UTC, sin incluir); NULL = sin fin
  active        TINYINT(1)   NOT NULL DEFAULT 1, -- 0 = PAUSADO (no se envía a las pantallas; se conserva)
  created_at    DATETIME     NOT NULL,          -- subida (UTC)
  PRIMARY KEY (id),
  UNIQUE KEY uq_media_stored (stored_name),
  KEY idx_media_user (user_id, position),
  CONSTRAINT fk_media_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equipos concretos de un archivo (solo cuenta si all_devices = 0).
CREATE TABLE IF NOT EXISTS sp_media_devices (
  media_id      INT UNSIGNED NOT NULL,
  device_id     VARCHAR(32)  NOT NULL,
  PRIMARY KEY (media_id, device_id),
  KEY idx_md_device (device_id),
  CONSTRAINT fk_md_media FOREIGN KEY (media_id) REFERENCES sp_media (id) ON DELETE CASCADE,
  CONSTRAINT fk_md_device FOREIGN KEY (device_id) REFERENCES sp_devices (device_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ANUNCIOS del administrador (ver migrations/2026-11-11-ads.sql para los detalles).
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

-- Ligas: tipos (opciones del selector de la TV), ligas (nombre + tipo +
-- códigos) y ligas asignadas a cada usuario. Ver migrations/2026-11-11-leagues.sql.
CREATE TABLE IF NOT EXISTS sp_league_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(20)  NOT NULL,          -- id de la opción en la TV (minúsculas: mlb, nba, nflnhl...)
  name        VARCHAR(60)  NOT NULL,          -- texto del botón en la TV (MLB, NBA, NFL & NHL...)
  position    INT UNSIGNED NOT NULL DEFAULT 0, -- orden de los botones
  created_at  DATETIME     NOT NULL,          -- UTC
  PRIMARY KEY (id),
  UNIQUE KEY uq_league_type_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sp_leagues (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_id     INT UNSIGNED NOT NULL,          -- sp_league_types.id (no se puede borrar un tipo con ligas)
  name        VARCHAR(100) NOT NULL,          -- nombre para el panel (p. ej. "Béisbol del Caribe")
  codes       VARCHAR(1000) NOT NULL,         -- contenido: códigos separados por coma (LIDOM,MLB,KO)
  position    INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL,          -- UTC
  updated_at  DATETIME     NOT NULL,          -- UTC
  PRIMARY KEY (id),
  KEY idx_league_type (type_id, position),
  CONSTRAINT fk_league_type FOREIGN KEY (type_id) REFERENCES sp_league_types (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ligas asignadas a cada usuario (varias por usuario).
CREATE TABLE IF NOT EXISTS sp_user_leagues (
  user_id     INT UNSIGNED NOT NULL,
  league_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, league_id),
  KEY idx_ul_league (league_id),
  CONSTRAINT fk_ul_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_ul_league FOREIGN KEY (league_id) REFERENCES sp_leagues (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- TIRAS (texto) y BANNERS (imagen) de mirror.html, del usuario o del administrador.
-- Ver migrations/2026-11-11-overlays.sql para los detalles.
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


-- Liga de cada equipo (sp_devices.league_id): FK aquí porque sp_leagues se crea después de sp_devices.
ALTER TABLE sp_devices
  ADD KEY idx_device_league (league_id),
  ADD CONSTRAINT fk_device_league FOREIGN KEY (league_id) REFERENCES sp_leagues (id) ON DELETE SET NULL;

-- RUTAS (URL de contenido por usuario). Ver migrations/2026-11-12-routes.sql.
CREATE TABLE IF NOT EXISTS sp_routes (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(100) NOT NULL,                 -- nombre para el panel (p. ej. "Pizarra deportes")
  base_url     VARCHAR(500) NOT NULL,                 -- https://sport.grupoazar.do/PizarrasV2 (sin "?")
  agent        VARCHAR(100) NOT NULL DEFAULT '',      -- agent (texto)
  page_index   INT UNSIGNED NOT NULL DEFAULT 0,       -- pageIndex (número)
  timer        INT UNSIGNED NOT NULL DEFAULT 60,      -- timer (número, segundos)
  show_params  VARCHAR(200) NOT NULL DEFAULT '',      -- showParams (texto)
  other_q      CHAR(1)      NOT NULL DEFAULT 'N',     -- otherQ: Y | N
  page_size    INT UNSIGNED NOT NULL DEFAULT 14,      -- pageSize (número)
  created_at   DATETIME     NOT NULL,                 -- UTC
  updated_at   DATETIME     NOT NULL,                 -- UTC
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ruta de cada usuario (sp_users.route_id): FK aquí porque sp_routes se crea después de sp_users.
ALTER TABLE sp_users
  ADD KEY idx_user_route (route_id),
  ADD CONSTRAINT fk_user_route FOREIGN KEY (route_id) REFERENCES sp_routes (id) ON DELETE SET NULL;

-- PERFILES DE EQUIPO (caja física: marca, modelo, MAC del case, fecha de
-- instalación). Ver migrations/2026-11-12-device-profiles.sql.
CREATE TABLE IF NOT EXISTS sp_device_profiles (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  brand        VARCHAR(60)  NOT NULL,                 -- marca (p. ej. "Hisilicon")
  model        VARCHAR(60)  NOT NULL,                 -- modelo (p. ej. "TVBOX Hi3798")
  case_mac     VARCHAR(17)  NOT NULL,                 -- MAC impresa en el case "E8:BB:3E:C8:FB:5A" (única)
  installed_on DATE         NOT NULL,                 -- fecha de instalación
  device_id    VARCHAR(32)  NULL,                     -- equipo al que está asignado (uno a la vez); NULL = libre
  created_at   DATETIME     NOT NULL,                 -- UTC
  updated_at   DATETIME     NOT NULL,                 -- UTC
  PRIMARY KEY (id),
  UNIQUE KEY uq_profile_case_mac (case_mac),          -- una caja física = un perfil
  UNIQUE KEY uq_profile_device (device_id),           -- un equipo tiene como mucho un perfil
  CONSTRAINT fk_profile_device FOREIGN KEY (device_id) REFERENCES sp_devices (device_id)
    ON DELETE SET NULL ON UPDATE CASCADE              -- borrar el equipo deja el perfil libre
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- BUZÓN DE MENSAJES administrador <-> usuario. Ver migrations/2026-11-12-messages.sql.
CREATE TABLE IF NOT EXISTS sp_messages (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,                 -- conversación: el usuario (sp_users.id)
  sender      VARCHAR(5)   NOT NULL,                 -- quién lo escribió: admin | user
  body        TEXT         NOT NULL,                 -- texto (máx. 2000 caracteres, con saltos de línea)
  created_at  DATETIME     NOT NULL,                 -- UTC
  read_at     DATETIME     NULL DEFAULT NULL,        -- cuándo lo leyó el DESTINATARIO (UTC); NULL = sin leer
  PRIMARY KEY (id),
  KEY idx_msg_thread (user_id, id),
  KEY idx_msg_unread (sender, read_at),
  CONSTRAINT fk_msg_user FOREIGN KEY (user_id) REFERENCES sp_users (id) ON DELETE CASCADE -- borrar el usuario borra su conversación
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

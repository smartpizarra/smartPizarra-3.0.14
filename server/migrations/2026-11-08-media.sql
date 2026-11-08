-- =====================================================================
--  MIGRACIÓN 2026-11-08 (2): imágenes y videos de los usuarios (mirror.html)
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-08-users.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Se puede repetir sin riesgo (CREATE TABLE IF NOT EXISTS).
-- =====================================================================

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

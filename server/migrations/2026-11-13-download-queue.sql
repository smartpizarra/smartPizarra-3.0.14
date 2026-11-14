-- Versión del archivo: 1.0.0 (2026-11-13)
-- =====================================================================
--  MIGRACIÓN 2026-11-13: COLA DE DESCARGAS CON TURNOS
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). phpMyAdmin > tu base > pestaña "SQL" > pegar y
--  ejecutar UNA vez (CREATE TABLE IF NOT EXISTS: repetirlo no hace nada).
--
--  Con muchas pantallas, el contenido nuevo se descarga POR TURNOS: como
--  mucho "download_slots" (config.php, por defecto 10) a la vez y el resto
--  espera por orden de llegada (DownloadQueue en private/lib.php).
-- =====================================================================
CREATE TABLE IF NOT EXISTS sp_download_queue (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, -- orden de llegada (= número de turno)
  device_id   VARCHAR(32)  NOT NULL,                 -- un equipo, un turno como mucho
  state       VARCHAR(7)   NOT NULL DEFAULT 'waiting', -- waiting (esperando) | active (descargando)
  created_at  DATETIME     NOT NULL,                 -- cuándo pidió turno (UTC)
  granted_at  DATETIME     NULL DEFAULT NULL,        -- cuándo le tocó (UTC)
  seen_at     DATETIME     NOT NULL,                 -- última señal ("sigo aquí" / descarga), UTC
  PRIMARY KEY (id),
  UNIQUE KEY uq_queue_device (device_id),
  KEY idx_queue_state (state, id),
  CONSTRAINT fk_queue_device FOREIGN KEY (device_id) REFERENCES sp_devices (device_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valores pequeños del sistema: la cola guarda aquí lo que tarda DE VERDAD un
-- turno (media móvil) para calcular las esperas.
CREATE TABLE IF NOT EXISTS sp_kv (
  k           VARCHAR(40)  NOT NULL,                 -- clave (p. ej. dl_turn_avg = duración media aprendida de un turno, s)
  v           VARCHAR(255) NOT NULL,
  updated_at  DATETIME     NOT NULL,                 -- UTC
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

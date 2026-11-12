-- Versión del archivo: 1.0.0 (2026-11-12)
-- =====================================================================
--  MIGRACIÓN 2026-11-12: BUZÓN DE MENSAJES (administrador <-> usuario)
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). phpMyAdmin > tu base > pestaña "SQL" > pegar y
--  ejecutar UNA vez (CREATE TABLE IF NOT EXISTS: repetirlo no hace nada).
--
--  Una conversación por usuario. El administrador escribe desde el panel
--  (botón "Mensajes": a un usuario o a todos) y el usuario desde /cuenta/
--  (pestaña "Mensajes"). read_at marca cuándo lo leyó el destinatario.
-- =====================================================================
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

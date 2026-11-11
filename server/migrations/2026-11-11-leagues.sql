-- =====================================================================
--  MIGRACIÓN 2026-11-11: LIGAS y TIPOS DE LIGA (administrador)
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de 2026-11-11-media-dates.sql.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Usa CREATE TABLE IF NOT EXISTS: ejecutarla otra vez no cambia nada.
--
--  Tipo de liga: lo que en la TV es una OPCIÓN del selector de ligas
--  (code = id guardado en smartpizarra.league, p. ej. "mlb", "nba").
--  Liga: nombre + tipo + CONTENIDO (lista de códigos, p. ej.
--  "LIDOM,MLB,KO"). El administrador asigna ligas a cada usuario; sus
--  equipos autorizados reciben en api/device.php las opciones de su
--  usuario (por tipo, uniendo los códigos de sus ligas de ese tipo).
-- =====================================================================
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

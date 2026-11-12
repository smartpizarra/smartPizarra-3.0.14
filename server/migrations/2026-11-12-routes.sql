-- =====================================================================
--  MIGRACIÓN 2026-11-12: GESTIÓN DE RUTAS (URL de contenido por usuario)
--  Solo si la base YA existía (si importas schema.sql desde cero, ya
--  viene incluido). Aplicar DESPUÉS de las migraciones del 2026-11-11.
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez.
--  Si dice "Duplicate column name 'route_id'", ya estaba aplicada.
--
--  Una RUTA es una URL que el administrador construye por partes en el
--  panel (botón "Rutas"): URL base + agent, pageIndex, timer, showParams,
--  otherQ (Y/N), pageSize. El parámetro "leagues" NO se guarda: se rellena
--  en cada equipo con los códigos de SU liga (la elige el usuario en
--  /cuenta/; sin liga => "leagues=" vacío).
--  Cada usuario tiene como mucho UNA ruta (sp_users.route_id); una ruta
--  puede asignarse a varios usuarios. Todos los equipos de un usuario la
--  heredan: api/device.php y api/media.php envían "deviceRoute" (la URL
--  final) y la TV la guarda en localStorage (smartpizarra.routeUrl).
-- =====================================================================
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

-- Ruta de cada usuario (NULL = sin ruta). Al borrar la ruta, sus usuarios quedan sin ruta.
ALTER TABLE sp_users
  ADD COLUMN route_id INT UNSIGNED NULL DEFAULT NULL,
  ADD KEY idx_user_route (route_id),
  ADD CONSTRAINT fk_user_route FOREIGN KEY (route_id) REFERENCES sp_routes (id) ON DELETE SET NULL;

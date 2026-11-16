-- Versión del archivo: 1.0.0 (2026-11-16)
-- =====================================================================
--  MIGRACIÓN 2026-11-16: AGENTE DE GESTIÓN REMOTA (launcher de las TV box)
--  phpMyAdmin > tu base > pestaña "SQL" > pegar y ejecutar UNA vez
--  (CREATE TABLE IF NOT EXISTS / INSERT IGNORE: repetirlo no hace nada).
--
--  El agente (APK cloud.smartpizarra.agent, carpeta agent/ del proyecto)
--  consulta public_html/api/agent.php. El administrador lo gestiona en
--  /admin/agentes.php. Modelo DECLARATIVO: el servidor guarda el estado
--  deseado POR GRUPO (app principal + despliegues) y cada equipo informa
--  lo que tiene; publicar una versión para miles de TV es UNA fila.
-- =====================================================================

-- Grupos de equipos: app principal y ajustes de la pantalla de mantenimiento.
-- revision sube con cada cambio del grupo (o de sus despliegues): forma parte
-- del ETag del manifiesto, así los equipos solo lo descargan cuando cambia.
CREATE TABLE IF NOT EXISTS sp_agent_groups (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                 VARCHAR(80)  NOT NULL,
  main_package         VARCHAR(150) NULL DEFAULT NULL,   -- app que se lanza sin tareas (p. ej. io.kodular...)
  main_activity        VARCHAR(200) NULL DEFAULT NULL,   -- actividad concreta (opcional)
  poll_seconds         SMALLINT UNSIGNED NOT NULL DEFAULT 300, -- cada cuánto consulta el equipo
  maintenance_text     VARCHAR(120) NOT NULL DEFAULT 'Actualizando librerías del sistema...',
  brand_color          CHAR(7)      NOT NULL DEFAULT '#002B4F',
  text_color           CHAR(7)      NOT NULL DEFAULT '#D9DEE5',
  show_percent         TINYINT(1)   NOT NULL DEFAULT 1,
  install_window       VARCHAR(11)  NOT NULL DEFAULT '',  -- "02:00-05:00" (hora del equipo) o "" = siempre
  foreground_downloads TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 = pantalla de mantenimiento también al descargar
  rotation             SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- giro de las pantallas del agente: 0, 90, 180, 270 (TV en vertical)
  revision             INT UNSIGNED NOT NULL DEFAULT 1,
  created_at           DATETIME     NOT NULL,
  updated_at           DATETIME     NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grupo 1 "General": ahí entran los equipos nuevos
INSERT IGNORE INTO sp_agent_groups (id, name, created_at, updated_at)
VALUES (1, 'General', UTC_TIMESTAMP(), UTC_TIMESTAMP());

-- Equipos con el agente. El ID ("AG-XXXX-XXXX-XXXX") y el secreto los genera
-- el equipo; aquí solo se guarda el sha256 del secreto (como sp_devices).
CREATE TABLE IF NOT EXISTS sp_agents (
  agent_id        VARCHAR(32)  NOT NULL,
  secret_hash     CHAR(64)     NOT NULL,
  status          VARCHAR(10)  NOT NULL DEFAULT 'pending', -- pending | authorized | revoked
  group_id        INT UNSIGNED NOT NULL DEFAULT 1,
  label           VARCHAR(80)  NOT NULL DEFAULT '',
  rotation        SMALLINT UNSIGNED NULL DEFAULT NULL, -- giro propio del equipo (NULL = el del grupo)
  model           VARCHAR(80)  NOT NULL DEFAULT '',
  maker           VARCHAR(80)  NOT NULL DEFAULT '',
  sdk             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  abi             VARCHAR(20)  NOT NULL DEFAULT '',
  privilege       VARCHAR(12)  NOT NULL DEFAULT '',    -- owner | system | root | interactive
  agent_version   VARCHAR(20)  NOT NULL DEFAULT '',
  inventory_hash  VARCHAR(16)  NOT NULL DEFAULT '',    -- hash del inventario que tiene el servidor
  state           VARCHAR(16)  NOT NULL DEFAULT '',    -- idle | working | waiting_turn | downloading | installing
  free_mb         INT          NOT NULL DEFAULT -1,
  temp_c          SMALLINT     NOT NULL DEFAULT -1,
  uptime_s        INT UNSIGNED NOT NULL DEFAULT 0,
  main_version    BIGINT       NOT NULL DEFAULT -1,    -- versionCode instalado de la app principal
  restarts        INT UNSIGNED NOT NULL DEFAULT 0,
  last_error      VARCHAR(200) NOT NULL DEFAULT '',
  last_ip         VARCHAR(45)  NOT NULL DEFAULT '',
  cmd_rev         INT UNSIGNED NOT NULL DEFAULT 0,     -- sube al enviarle un comando (parte del ETag)
  created_at      DATETIME     NOT NULL,
  last_seen_at    DATETIME     NOT NULL,
  PRIMARY KEY (agent_id),
  KEY idx_agents_group (group_id),
  KEY idx_agents_status (status),
  CONSTRAINT fk_agents_group FOREIGN KEY (group_id) REFERENCES sp_agent_groups (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- APK subidos al panel (archivo en private/agent/apk/). package + version_code
-- se leen del propio APK (AndroidManifest.xml binario).
CREATE TABLE IF NOT EXISTS sp_agent_apks (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  package        VARCHAR(150) NOT NULL,
  version_code   BIGINT       NOT NULL,
  version_name   VARCHAR(60)  NOT NULL DEFAULT '',
  min_sdk        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  label          VARCHAR(80)  NOT NULL DEFAULT '',
  sha256         CHAR(64)     NOT NULL,
  size_bytes     BIGINT UNSIGNED NOT NULL,
  file_name      VARCHAR(80)  NOT NULL,
  original_name  VARCHAR(190) NOT NULL DEFAULT '',
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_apk_version (package, version_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Despliegues de un grupo: instalar/actualizar un APK o desinstalar un paquete.
-- rollout_percent: solo ese % de equipos del grupo (siempre los mismos).
-- retry: el panel lo sube con "Reintentar fallidos" (cambia el ID de la tarea).
CREATE TABLE IF NOT EXISTS sp_agent_deployments (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id         INT UNSIGNED NOT NULL,
  action           VARCHAR(10)  NOT NULL,                -- install | uninstall
  package          VARCHAR(150) NOT NULL,
  apk_id           INT UNSIGNED NULL DEFAULT NULL,       -- solo install
  rollout_percent  TINYINT UNSIGNED NOT NULL DEFAULT 100,
  retry            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  active           TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_dep_group (group_id, active),
  CONSTRAINT fk_dep_group FOREIGN KEY (group_id) REFERENCES sp_agent_groups (id) ON DELETE CASCADE,
  CONSTRAINT fk_dep_apk FOREIGN KEY (apk_id) REFERENCES sp_agent_apks (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventario informado por cada equipo (apps del usuario y de sistema actualizadas)
CREATE TABLE IF NOT EXISTS sp_agent_packages (
  agent_id      VARCHAR(32)  NOT NULL,
  package       VARCHAR(150) NOT NULL,
  version_code  BIGINT       NOT NULL,
  PRIMARY KEY (agent_id, package),
  CONSTRAINT fk_pkg_agent FOREIGN KEY (agent_id) REFERENCES sp_agents (agent_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resultado de cada tarea en cada equipo (idempotente por agent_id + task_id)
CREATE TABLE IF NOT EXISTS sp_agent_results (
  agent_id       VARCHAR(32)  NOT NULL,
  task_id        VARCHAR(200) NOT NULL,
  deployment_id  INT UNSIGNED NULL DEFAULT NULL,
  command_id     INT UNSIGNED NULL DEFAULT NULL,
  kind           VARCHAR(10)  NOT NULL DEFAULT '',     -- install | uninstall | command
  package        VARCHAR(150) NOT NULL DEFAULT '',
  version_code   BIGINT       NOT NULL DEFAULT 0,
  status         VARCHAR(8)   NOT NULL,                -- done | failed
  code           VARCHAR(60)  NOT NULL DEFAULT '',
  detail         TEXT         NULL,
  attempts       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  reported_at    DATETIME     NOT NULL,
  PRIMARY KEY (agent_id, task_id),
  KEY idx_res_dep (deployment_id, status),
  KEY idx_res_cmd (command_id),
  CONSTRAINT fk_res_agent FOREIGN KEY (agent_id) REFERENCES sp_agents (agent_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comandos FIRMADOS (lista blanca del agente) para un equipo o un grupo
CREATE TABLE IF NOT EXISTS sp_agent_commands (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_agent  VARCHAR(32)  NULL DEFAULT NULL,
  target_group  INT UNSIGNED NULL DEFAULT NULL,
  name          VARCHAR(40)  NOT NULL,
  args          VARCHAR(500) NOT NULL DEFAULT '{}',
  signature     VARCHAR(200) NOT NULL,                 -- ECDSA P-256 base64 de "id|target|name|args|exp"
  created_at    DATETIME     NOT NULL,
  expires_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_cmd_agent (target_agent, expires_at),
  KEY idx_cmd_group (target_group, expires_at),
  CONSTRAINT fk_cmd_agent FOREIGN KEY (target_agent) REFERENCES sp_agents (agent_id) ON DELETE CASCADE,
  CONSTRAINT fk_cmd_group FOREIGN KEY (target_group) REFERENCES sp_agent_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cola de descargas del agente: MISMO algoritmo que sp_download_queue (orden de
-- llegada, N huecos, espera proporcional al puesto) + límite por IP pública
-- (site_ip): varias TV del mismo local no saturan su conexión.
CREATE TABLE IF NOT EXISTS sp_agent_queue (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id    VARCHAR(32)  NOT NULL,
  site_ip     VARCHAR(45)  NOT NULL DEFAULT '',
  state       VARCHAR(7)   NOT NULL DEFAULT 'waiting',  -- waiting | active
  created_at  DATETIME     NOT NULL,
  granted_at  DATETIME     NULL DEFAULT NULL,
  seen_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_aqueue_agent (agent_id),
  KEY idx_aqueue_state (state, id),
  CONSTRAINT fk_aqueue_agent FOREIGN KEY (agent_id) REFERENCES sp_agents (agent_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sp_kv (ya existe desde 2026-11-13) guarda además: agent_turn_avg (duración
-- media aprendida de un turno del agente) y agent_logo (sha256 del logo).
CREATE TABLE IF NOT EXISTS sp_kv (
  k           VARCHAR(40)  NOT NULL,
  v           VARCHAR(255) NOT NULL,
  updated_at  DATETIME     NOT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

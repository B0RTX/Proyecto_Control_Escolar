-- =====================================================================
--  SISTEMA ESCOLAR (versión normalizada, 3FN)  -  MariaDB 11 (Docker)
-- =====================================================================
-- Adaptado para la inicialización automática de la imagen oficial de MariaDB.
-- Cambios respecto al respaldo original:
--   * Se eliminó  DROP DATABASE IF EXISTS ...  (peligroso: borraba la BD completa).
--   * Se eliminó  CREATE DATABASE ...  y  USE ...  : la base la crea Docker Compose
--     (variable MARIADB_DATABASE) y la imagen ejecuta este script sobre ella.
--   * El resto (tablas, vistas, triggers, admin inicial) se conserva sin cambios.
-- Este script solo se ejecuta cuando el volumen de datos está vacío (primer arranque).
-- Para importarlo a mano en una BD vacía:
--   docker compose exec -T servidor-bd mariadb -uroot -p<ROOT_PASS> sistema_escolar < Database/01_sistema_escolar.sql
-- =====================================================================

-- =====================================================================
-- 1. PROFESORES
-- =====================================================================
CREATE TABLE profesores (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  nombre           VARCHAR(80)  NOT NULL,
  apellido_paterno VARCHAR(60)  NOT NULL,
  apellido_materno VARCHAR(60)  NULL,
  email            VARCHAR(100) NULL UNIQUE,
  telefono         VARCHAR(20)  NULL,
  activo           TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================================
-- 2. CATÁLOGOS ACADÉMICOS
-- =====================================================================
CREATE TABLE ciclos_escolares (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(20) NOT NULL UNIQUE,     -- ej. '2025-2026'
  fecha_inicio DATE NOT NULL,
  fecha_fin    DATE NOT NULL,
  activo       TINYINT(1) NOT NULL DEFAULT 0,
  CHECK (fecha_fin > fecha_inicio)
) ENGINE=InnoDB;

CREATE TABLE periodos (                          -- parciales / bimestres / trimestres
  id           INT AUTO_INCREMENT PRIMARY KEY,
  ciclo_id     INT NOT NULL,
  numero       TINYINT NOT NULL,
  nombre       VARCHAR(30) NOT NULL,
  fecha_inicio DATE NULL,
  fecha_fin    DATE NULL,
  UNIQUE KEY uq_periodo (ciclo_id, numero),
  CONSTRAINT fk_periodo_ciclo FOREIGN KEY (ciclo_id)
    REFERENCES ciclos_escolares(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE grados (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(30) NOT NULL UNIQUE            -- '1°', '2°', '3°'
) ENGINE=InnoDB;

CREATE TABLE grupos (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  ciclo_id INT NOT NULL,
  grado_id INT NOT NULL,
  letra    CHAR(1) NOT NULL,                    -- 'A', 'B'
  UNIQUE KEY uq_grupo (ciclo_id, grado_id, letra),
  CONSTRAINT fk_grupo_ciclo FOREIGN KEY (ciclo_id) REFERENCES ciclos_escolares(id),
  CONSTRAINT fk_grupo_grado FOREIGN KEY (grado_id) REFERENCES grados(id)
) ENGINE=InnoDB;

CREATE TABLE materias (                          -- catálogo puro (sin profesor)
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- Carga académica: qué materia se imparte a qué grupo y quién la da
CREATE TABLE asignaciones (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  grupo_id    INT NOT NULL,
  materia_id  INT NOT NULL,
  profesor_id INT NULL,
  UNIQUE KEY uq_asignacion (grupo_id, materia_id),
  CONSTRAINT fk_asig_grupo    FOREIGN KEY (grupo_id)    REFERENCES grupos(id)    ON DELETE CASCADE,
  CONSTRAINT fk_asig_materia  FOREIGN KEY (materia_id)  REFERENCES materias(id)  ON DELETE CASCADE,
  CONSTRAINT fk_asig_profesor FOREIGN KEY (profesor_id) REFERENCES profesores(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
-- 3. ALUMNOS
-- =====================================================================
CREATE TABLE alumnos (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  matricula        VARCHAR(20)  NOT NULL UNIQUE,
  nombre           VARCHAR(80)  NOT NULL,
  apellido_paterno VARCHAR(60)  NOT NULL,
  apellido_materno VARCHAR(60)  NOT NULL,          -- obligatorio en alumnos
  email            VARCHAR(100) NULL UNIQUE,
  fecha_nac        DATE NULL,
  activo           TINYINT(1) NOT NULL DEFAULT 1,
  created_at       TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Un alumno se inscribe a un grupo en un ciclo (conserva el historial)
CREATE TABLE inscripciones (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  alumno_id INT NOT NULL,
  grupo_id  INT NOT NULL,
  estatus   ENUM('activo','baja','egresado') NOT NULL DEFAULT 'activo',
  fecha_inscripcion DATE NOT NULL DEFAULT (CURRENT_DATE),
  UNIQUE KEY uq_inscripcion (alumno_id, grupo_id),
  CONSTRAINT fk_insc_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE,
  CONSTRAINT fk_insc_grupo  FOREIGN KEY (grupo_id)  REFERENCES grupos(id)  ON DELETE RESTRICT
) ENGINE=InnoDB;

-- =====================================================================
-- 4. CALIFICACIONES Y ASISTENCIAS
-- =====================================================================
CREATE TABLE calificaciones (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  inscripcion_id INT NOT NULL,
  asignacion_id  INT NOT NULL,
  periodo_id     INT NOT NULL,
  calificacion   DECIMAL(4,1) NOT NULL,
  UNIQUE KEY uq_calif (inscripcion_id, asignacion_id, periodo_id),
  CONSTRAINT chk_calif CHECK (calificacion BETWEEN 0 AND 10),
  CONSTRAINT fk_cal_insc FOREIGN KEY (inscripcion_id) REFERENCES inscripciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_cal_asig FOREIGN KEY (asignacion_id)  REFERENCES asignaciones(id)  ON DELETE CASCADE,
  CONSTRAINT fk_cal_per  FOREIGN KEY (periodo_id)     REFERENCES periodos(id)      ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE asistencias (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  inscripcion_id INT NOT NULL,
  asignacion_id  INT NOT NULL,
  fecha          DATE NOT NULL,
  estatus        ENUM('presente','falta','retardo','justificada') NOT NULL DEFAULT 'presente',
  UNIQUE KEY uq_asistencia (inscripcion_id, asignacion_id, fecha),
  CONSTRAINT fk_as_insc FOREIGN KEY (inscripcion_id) REFERENCES inscripciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_as_asig FOREIGN KEY (asignacion_id)  REFERENCES asignaciones(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- 5. ACCESO AL SISTEMA WEB (alumnos, profesores y administradores)
-- =====================================================================
-- Una sola tabla de cuentas. El tipo de cuenta define a qué persona se enlaza:
--   alumno   -> alumno_id   (ve solo SUS calificaciones y asistencias)
--   profesor -> profesor_id (ve y captura SOLO sus asignaciones)
--   admin    -> sin enlace  (acceso total)
CREATE TABLE usuarios (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  usuario               VARCHAR(50)  NOT NULL UNIQUE,   -- la matrícula (asignada automáticamente)
  password              VARCHAR(255) NOT NULL,          -- SOLO hash Argon2id o bcrypt (con sal incluida); nunca texto plano
  rol                   ENUM('admin','profesor','alumno') NOT NULL,   -- Administrador, Profesor, Alumno
  alumno_id             INT NULL UNIQUE,                -- una cuenta por alumno
  profesor_id           INT NULL UNIQUE,                -- una cuenta por profesor
  nombre_admin          VARCHAR(100) NULL,              -- solo para rol admin
  activo                TINYINT(1)   NOT NULL DEFAULT 1,
  debe_cambiar_password TINYINT(1)   NOT NULL DEFAULT 1,
  intentos_fallidos     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta       DATETIME NULL,
  ultimo_acceso         DATETIME NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_usuario_alumno   FOREIGN KEY (alumno_id)   REFERENCES alumnos(id)   ON DELETE CASCADE,
  CONSTRAINT fk_usuario_profesor FOREIGN KEY (profesor_id) REFERENCES profesores(id) ON DELETE CASCADE,
  -- Rechaza cualquier contraseña que NO tenga formato de hash bcrypt ($2a$/$2b$/$2y$) o Argon2id.
  -- '!' = cuenta bloqueada sin contraseña asignada (no es un hash, nadie puede entrar con ella).
  CONSTRAINT chk_password_hash CHECK (
       LEFT(password, 4) IN ('$2a$','$2b$','$2y$')
    OR LEFT(password, 10) = '$argon2id$'
    OR password = '!'
  ),
  -- Garantiza que cada rol esté enlazado correctamente
  CONSTRAINT chk_usuario_rol CHECK (
       (rol = 'alumno'   AND alumno_id IS NOT NULL AND profesor_id IS NULL     AND nombre_admin IS NULL)
    OR (rol = 'profesor' AND profesor_id IS NOT NULL AND alumno_id IS NULL     AND nombre_admin IS NULL)
    OR (rol = 'admin'    AND alumno_id IS NULL AND profesor_id IS NULL         AND nombre_admin IS NOT NULL)
  )
) ENGINE=InnoDB;

-- Recuperación de contraseña / tokens de un solo uso
CREATE TABLE tokens_recuperacion (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,            -- guarda el hash SHA-256 del token, no el token
  expira_en  DATETIME NOT NULL,
  usado      TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tok_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Bitácora de accesos y de cambios importantes (auditoría)
CREATE TABLE bitacora (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NULL,
  accion     VARCHAR(50)  NOT NULL,               -- LOGIN, LOGOUT, LOGIN_FALLIDO, EDITAR_CALIFICACION...
  tabla      VARCHAR(50)  NULL,
  registro_id INT NULL,
  detalle    VARCHAR(500) NULL,
  ip         VARCHAR(45)  NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bit_usuario (usuario_id, created_at),
  CONSTRAINT fk_bit_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Sesiones activas: se guarda el HASH del token de sesión, así un robo de la BD
-- no permite suplantar a nadie. Se pueden revocar desde el panel de admin.
CREATE TABLE sesiones (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,            -- SHA-256 del token que recibe el navegador
  ip         VARCHAR(45)  NULL,
  user_agent VARCHAR(255) NULL,
  expira_en  DATETIME NOT NULL,
  revocada   TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ses_usuario (usuario_id, expira_en),
  CONSTRAINT fk_ses_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Historial de hashes anteriores para impedir reutilizar contraseñas viejas
CREATE TABLE password_historial (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ph_usuario (usuario_id, created_at),
  CONSTRAINT chk_ph_hash CHECK (
       LEFT(password_hash, 4) IN ('$2a$','$2b$','$2y$')
    OR LEFT(password_hash, 10) = '$argon2id$'
  ),
  CONSTRAINT fk_ph_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- VISTAS PARA CADA ROL ----------
-- Alumno: sus calificaciones (filtra en la app con WHERE usuario_id = ?)
CREATE VIEW vw_calificaciones_alumno AS
SELECT u.id AS usuario_id, al.id AS alumno_id, al.matricula,
       CONCAT(al.nombre,' ',al.apellido_paterno) AS alumno,
       ce.nombre AS ciclo, CONCAT(gr.nombre, g.letra) AS grupo,
       m.nombre AS materia, p.numero AS periodo, c.calificacion
FROM calificaciones c
JOIN inscripciones i     ON i.id = c.inscripcion_id
JOIN alumnos al          ON al.id = i.alumno_id
JOIN usuarios u          ON u.alumno_id = al.id
JOIN asignaciones a      ON a.id = c.asignacion_id
JOIN materias m          ON m.id = a.materia_id
JOIN grupos g            ON g.id = a.grupo_id
JOIN grados gr           ON gr.id = g.grado_id
JOIN ciclos_escolares ce ON ce.id = g.ciclo_id
JOIN periodos p          ON p.id = c.periodo_id;

-- Profesor: sus grupos y materias (filtra con WHERE usuario_id = ?)
CREATE VIEW vw_asignaciones_profesor AS
SELECT u.id AS usuario_id, a.id AS asignacion_id, ce.nombre AS ciclo,
       CONCAT(gr.nombre, g.letra) AS grupo, m.nombre AS materia
FROM asignaciones a
JOIN usuarios u          ON u.profesor_id = a.profesor_id
JOIN materias m          ON m.id = a.materia_id
JOIN grupos g            ON g.id = a.grupo_id
JOIN grados gr           ON gr.id = g.grado_id
JOIN ciclos_escolares ce ON ce.id = g.ciclo_id;

-- Índices útiles para consultas frecuentes
CREATE INDEX idx_alumnos_apellido ON alumnos (apellido_paterno, nombre);
CREATE INDEX idx_insc_grupo       ON inscripciones (grupo_id);
CREATE INDEX idx_asig_profesor    ON asignaciones (profesor_id);

-- =====================================================================
-- NUMERACIÓN AUTOMÁTICA DE MATRÍCULAS (consecutivas por rol)
--   alumnos: 10000, 10001, 10002...   profesores: 50000, 50001...   administradores: 1000, 1001...
-- La web toma el número con un UPDATE atómico, así no se repiten aunque haya altas simultáneas.
-- =====================================================================
CREATE TABLE secuencias (
  tipo      ENUM('admin','profesor','alumno') NOT NULL PRIMARY KEY,
  siguiente INT UNSIGNED NOT NULL                 -- próximo número que se asignará
) ENGINE=InnoDB;

INSERT INTO secuencias (tipo, siguiente) VALUES
  ('admin',    1001),     -- el 1000 es el administrador inicial de este script
  ('profesor', 50000),
  ('alumno',   10000);

-- =====================================================================
-- USUARIO ADMINISTRADOR INICIAL
-- =====================================================================
-- usuario (matrícula): 1000
-- contraseña inicial:  Huevito123?
-- Se guarda únicamente el hash bcrypt (costo 12), nunca la contraseña.
-- debe_cambiar_password = 1: en el primer inicio de sesión el sistema pide cambiarla
-- (la nueva debe tener mínimo 10 caracteres, con letras y números, y no puede ser la misma).
-- Si prefieres conservar Huevito123? como contraseña definitiva, cambia ese 1 por 0.
INSERT INTO usuarios (usuario, password, rol, nombre_admin, debe_cambiar_password)
VALUES ('1000',
        '$2y$12$TVMiTc62lKyOXts2chEoSuYYppCvbMoy3BS6b7edmAMTWHUrFz6SW',
        'admin', 'Administrador', 1);

-- =====================================================================
-- 6. SEGURIDAD EN LA BASE DE DATOS
-- =====================================================================
-- Los triggers se crean al final, después de insertar el admin inicial.
-- Tu aplicación debe ejecutar al inicio de cada petición:  SET @app_usuario_id = <id del usuario logueado>;

-- ---------- 6.1 AUDITORÍA AUTOMÁTICA DE CALIFICACIONES ----------
CREATE TRIGGER trg_calif_insert AFTER INSERT ON calificaciones FOR EACH ROW
  INSERT INTO bitacora (usuario_id, accion, tabla, registro_id, detalle)
  VALUES (@app_usuario_id, 'CALIFICACION_NUEVA', 'calificaciones', NEW.id,
          CONCAT('valor=', NEW.calificacion));

CREATE TRIGGER trg_calif_update AFTER UPDATE ON calificaciones FOR EACH ROW
  INSERT INTO bitacora (usuario_id, accion, tabla, registro_id, detalle)
  SELECT @app_usuario_id, 'CALIFICACION_EDITADA', 'calificaciones', NEW.id,
         CONCAT('antes=', OLD.calificacion, ' despues=', NEW.calificacion)
  FROM DUAL WHERE OLD.calificacion <> NEW.calificacion;

CREATE TRIGGER trg_calif_delete AFTER DELETE ON calificaciones FOR EACH ROW
  INSERT INTO bitacora (usuario_id, accion, tabla, registro_id, detalle)
  VALUES (@app_usuario_id, 'CALIFICACION_ELIMINADA', 'calificaciones', OLD.id,
          CONCAT('valor=', OLD.calificacion));

-- ---------- 6.2 BITÁCORA INMUTABLE (nadie puede borrar ni alterar el rastro) ----------
CREATE TRIGGER trg_bitacora_no_update BEFORE UPDATE ON bitacora FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bitacora es de solo lectura';

CREATE TRIGGER trg_bitacora_no_delete BEFORE DELETE ON bitacora FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bitacora no se puede borrar';

-- ---------- 6.3 USUARIOS DE MYSQL CON MÍNIMOS PRIVILEGIOS ----------
-- Ejecuta esto como root/DBA, cambiando las contraseñas por valores largos y aleatorios.
-- Tu web NUNCA debe conectarse como root. Si la web es vulnerada, el atacante solo
-- tendrá los permisos de este usuario: no podrá borrar tablas, ni la base, ni leer archivos.
--
-- CREATE USER 'app_web'@'localhost' IDENTIFIED BY 'CAMBIAR_POR_CONTRASENA_LARGA_Y_ALEATORIA'
--   REQUIRE SSL
--   WITH MAX_USER_CONNECTIONS 50;
-- GRANT SELECT, INSERT, UPDATE, DELETE ON sistema_escolar.* TO 'app_web'@'localhost';
-- (sin DROP, ALTER, CREATE, INDEX, FILE, GRANT OPTION, SUPER)
--
-- Usuario de solo lectura para reportes/respaldos:
-- CREATE USER 'app_reportes'@'localhost' IDENTIFIED BY 'OTRA_CONTRASENA_LARGA' REQUIRE SSL;
-- GRANT SELECT ON sistema_escolar.vw_calificaciones_alumno  TO 'app_reportes'@'localhost';
-- GRANT SELECT ON sistema_escolar.vw_asignaciones_profesor  TO 'app_reportes'@'localhost';
--
-- FLUSH PRIVILEGES;

-- ---------- 6.4 CIFRADO EN REPOSO (opcional, requiere keyring configurado en MySQL) ----------
-- ALTER TABLE usuarios            ENCRYPTION = 'Y';
-- ALTER TABLE sesiones            ENCRYPTION = 'Y';
-- ALTER TABLE tokens_recuperacion ENCRYPTION = 'Y';
-- ALTER TABLE alumnos             ENCRYPTION = 'Y';

-- =====================================================================
-- OPCIONAL: restablecer SOLO la contraseña del administrador (sin borrar datos)
-- Ejecútalo en una base ya existente en lugar de importar todo el script:
--
-- UPDATE usuarios
--    SET password = '$2y$12$TVMiTc62lKyOXts2chEoSuYYppCvbMoy3BS6b7edmAMTWHUrFz6SW',
--        debe_cambiar_password = 1, intentos_fallidos = 0, bloqueado_hasta = NULL
--  WHERE usuario = '1000' AND rol = 'admin';
-- =====================================================================

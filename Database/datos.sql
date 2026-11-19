-- =====================================================================
--  DATOS DE PRUEBA  -  Sistema Escolar Nexus
-- =====================================================================
-- Se ejecuta automáticamente DESPUÉS de 01_sistema_escolar.sql (la imagen de
-- MariaDB corre los archivos de /docker-entrypoint-initdb.d en orden alfabético
-- y solo cuando el volumen de datos está vacío).
--
-- Contenido:
--   * 10 profesores   (matrículas 50000 a 50009, una materia cada uno)
--   * 1 ciclo escolar '2026-2026' (activo) con 2 semestres
--   * 3 grados, 6 grupos (1°A, 1°B, 2°A, 2°B, 3°A, 3°B) y 10 materias
--   * 40 alumnos      (matrículas 10000 a 10039) inscritos en los grupos
--   * Calificaciones de los 2 semestres para todos los alumnos y materias
--
-- Acceso: usuario = matrícula, contraseña temporal = la misma matrícula
-- (el sistema obliga a cambiarla en el primer ingreso).
-- Administrador (del script de esquema): usuario 1000 / Huevito123?
-- =====================================================================
SET NAMES utf8mb4;
SET @app_usuario_id = 1;   -- las calificaciones quedan en la bitácora a nombre del administrador

-- ---------------------------------------------------------------------
-- 1. Ciclo escolar y semestres
-- ---------------------------------------------------------------------
INSERT INTO ciclos_escolares (id, nombre, fecha_inicio, fecha_fin, activo)
VALUES (1, '2026-2026', '2026-01-01', '2026-12-31', 1);

INSERT INTO periodos (id, ciclo_id, numero, nombre, fecha_inicio, fecha_fin) VALUES
  (1, 1, 1, 'Semestre 1', '2026-01-01', '2026-06-30'),
  (2, 1, 2, 'Semestre 2', '2026-07-01', '2026-12-31');

-- ---------------------------------------------------------------------
-- 2. Grados, grupos y materias
-- ---------------------------------------------------------------------
INSERT INTO grados (id, nombre) VALUES (1, '1°'), (2, '2°'), (3, '3°');

INSERT INTO grupos (id, ciclo_id, grado_id, letra) VALUES
  (1, 1, 1, 'A'),
  (2, 1, 1, 'B'),
  (3, 1, 2, 'A'),
  (4, 1, 2, 'B'),
  (5, 1, 3, 'A'),
  (6, 1, 3, 'B');

INSERT INTO materias (id, nombre) VALUES
  (1, 'Matemáticas'),
  (2, 'Español'),
  (3, 'Inglés'),
  (4, 'Ciencias Naturales'),
  (5, 'Historia'),
  (6, 'Geografía'),
  (7, 'Educación Física'),
  (8, 'Artes'),
  (9, 'Informática'),
  (10, 'Formación Cívica y Ética');

-- ---------------------------------------------------------------------
-- 3. Profesores (10)
-- ---------------------------------------------------------------------
INSERT INTO profesores (id, nombre, apellido_paterno, apellido_materno, email, telefono) VALUES
  (1, 'Roberto', 'Hernández', 'Martínez', '50000@profesor.edu.mx', '222 555 1000'),
  (2, 'Laura', 'García', 'López', '50001@profesor.edu.mx', '222 555 1037'),
  (3, 'Carlos', 'Ramírez', 'Sánchez', '50002@profesor.edu.mx', '222 555 1074'),
  (4, 'María', 'González', 'Pérez', '50003@profesor.edu.mx', '222 555 1111'),
  (5, 'Javier', 'Torres', 'Flores', '50004@profesor.edu.mx', '222 555 1148'),
  (6, 'Patricia', 'Rivera', 'Gómez', '50005@profesor.edu.mx', '222 555 1185'),
  (7, 'Fernando', 'Morales', 'Cruz', '50006@profesor.edu.mx', '222 555 1222'),
  (8, 'Gabriela', 'Ortiz', 'Reyes', '50007@profesor.edu.mx', '222 555 1259'),
  (9, 'Miguel', 'Jiménez', 'Vargas', '50008@profesor.edu.mx', '222 555 1296'),
  (10, 'Sofía', 'Castillo', 'Mendoza', '50009@profesor.edu.mx', '222 555 1333');

-- ---------------------------------------------------------------------
-- 4. Alumnos (40)
-- ---------------------------------------------------------------------
INSERT INTO alumnos (id, matricula, nombre, apellido_paterno, apellido_materno, email, fecha_nac) VALUES
  (1, '10000', 'Juan Carlos', 'Pérez', 'Luna', '10000@alumno.edu.mx', '2011-01-01'),
  (2, '10001', 'Ana Sofía', 'Aguilar', 'Montes', '10001@alumno.edu.mx', '2012-06-12'),
  (3, '10002', 'Luis Ángel', 'Cortés', 'Cabrera', '10002@alumno.edu.mx', '2013-11-23'),
  (4, '10003', 'María Fernanda', 'López', 'Pacheco', '10003@alumno.edu.mx', '2014-04-06'),
  (5, '10004', 'José Eduardo', 'Guerrero', 'Valdez', '10004@alumno.edu.mx', '2011-09-17'),
  (6, '10005', 'Valentina', 'Ibarra', 'Carrillo', '10005@alumno.edu.mx', '2012-02-28'),
  (7, '10006', 'Diego', 'Ruiz', 'Rangel', '10006@alumno.edu.mx', '2013-07-11'),
  (8, '10007', 'Camila', 'Ramos', 'Ríos', '10007@alumno.edu.mx', '2014-12-22'),
  (9, '10008', 'Emiliano', 'Campos', 'Delgado', '10008@alumno.edu.mx', '2011-05-05'),
  (10, '10009', 'Regina', 'Díaz', 'Ávila', '10009@alumno.edu.mx', '2012-10-16'),
  (11, '10010', 'Santiago', 'Núñez', 'Domínguez', '10010@alumno.edu.mx', '2013-03-27'),
  (12, '10011', 'Ximena', 'Silva', 'Bautista', '10011@alumno.edu.mx', '2014-08-10'),
  (13, '10012', 'Mateo', 'Vázquez', 'Mejía', '10012@alumno.edu.mx', '2011-01-21'),
  (14, '10013', 'Renata', 'Salazar', 'Zamora', '10013@alumno.edu.mx', '2012-06-04'),
  (15, '10014', 'Sebastián', 'Fuentes', 'Peña', '10014@alumno.edu.mx', '2013-11-15'),
  (16, '10015', 'Paola', 'Herrera', 'Paredes', '10015@alumno.edu.mx', '2014-04-26'),
  (17, '10016', 'Leonardo', 'Rojas', 'Estrada', '10016@alumno.edu.mx', '2011-09-09'),
  (18, '10017', 'Daniela', 'Soto', 'Lara', '10017@alumno.edu.mx', '2012-02-20'),
  (19, '10018', 'Alejandro', 'Medina', 'Espinoza', '10018@alumno.edu.mx', '2013-07-03'),
  (20, '10019', 'Fátima', 'Navarro', 'Villanueva', '10019@alumno.edu.mx', '2014-12-14'),
  (21, '10020', 'Iker', 'Pérez', 'Ríos', '10020@alumno.edu.mx', '2011-05-25'),
  (22, '10021', 'Andrea', 'Aguilar', 'Delgado', '10021@alumno.edu.mx', '2012-10-08'),
  (23, '10022', 'Gael', 'Cortés', 'Ávila', '10022@alumno.edu.mx', '2013-03-19'),
  (24, '10023', 'Mariana', 'López', 'Domínguez', '10023@alumno.edu.mx', '2014-08-02'),
  (25, '10024', 'Rodrigo', 'Guerrero', 'Bautista', '10024@alumno.edu.mx', '2011-01-13'),
  (26, '10025', 'Isabella', 'Ibarra', 'Mejía', '10025@alumno.edu.mx', '2012-06-24'),
  (27, '10026', 'Adrián', 'Ruiz', 'Zamora', '10026@alumno.edu.mx', '2013-11-07'),
  (28, '10027', 'Natalia', 'Ramos', 'Peña', '10027@alumno.edu.mx', '2014-04-18'),
  (29, '10028', 'Axel', 'Campos', 'Paredes', '10028@alumno.edu.mx', '2012-09-01'),
  (30, '10029', 'Jimena', 'Díaz', 'Estrada', '10029@alumno.edu.mx', '2013-02-12'),
  (31, '10030', 'Erick', 'Núñez', 'Lara', '10030@alumno.edu.mx', '2011-07-23'),
  (32, '10031', 'Karla', 'Silva', 'Espinoza', '10031@alumno.edu.mx', '2012-12-06'),
  (33, '10032', 'Bruno', 'Vázquez', 'Villanueva', '10032@alumno.edu.mx', '2013-05-17'),
  (34, '10033', 'Lucía', 'Salazar', 'Luna', '10033@alumno.edu.mx', '2011-10-28'),
  (35, '10034', 'Héctor', 'Fuentes', 'Montes', '10034@alumno.edu.mx', '2012-03-11'),
  (36, '10035', 'Ariadna', 'Herrera', 'Cabrera', '10035@alumno.edu.mx', '2013-08-22'),
  (37, '10036', 'Óscar', 'Rojas', 'Pacheco', '10036@alumno.edu.mx', '2011-01-05'),
  (38, '10037', 'Melissa', 'Soto', 'Valdez', '10037@alumno.edu.mx', '2012-06-16'),
  (39, '10038', 'Tomás', 'Medina', 'Carrillo', '10038@alumno.edu.mx', '2013-11-27'),
  (40, '10039', 'Abigail', 'Navarro', 'Rangel', '10039@alumno.edu.mx', '2011-04-10');

-- ---------------------------------------------------------------------
-- 5. Cuentas de acceso (usuario = matrícula, contraseña inicial = matrícula)
-- ---------------------------------------------------------------------
INSERT INTO usuarios (usuario, password, rol, alumno_id, profesor_id, debe_cambiar_password) VALUES
  ('50000', '$2b$12$kSB129UIhuurWcWU5mn5Ee9RhzJcVJ5qdwoSvXo/G5N5yzh/Z3Cn6', 'profesor', NULL, 1, 1),
  ('50001', '$2b$12$SWa.dEq9jVE1CI.IylVF9.tJl9bJW6lILDOZ2Pjl8znYH9cmzDzf6', 'profesor', NULL, 2, 1),
  ('50002', '$2b$12$bmMz0NgyNh3UMzE6dM1ddeBtzvhFc2BN471clEr6NSNsz5HSolZKq', 'profesor', NULL, 3, 1),
  ('50003', '$2b$12$J/puJT9erlmLlrjvLbarIe7ZvobaO0OBGRVkqx5tweirsozHceUGm', 'profesor', NULL, 4, 1),
  ('50004', '$2b$12$VsCGN4X3rT6qYRlrQruwH.FSBtsQ3QAJq4qkAKM2T7wDRwPQrCgIO', 'profesor', NULL, 5, 1),
  ('50005', '$2b$12$Vs2vq8/SdgTVUTS5.AEyM.L6tVx3sLbJwAt4O.MT.dh0rrxVVuUya', 'profesor', NULL, 6, 1),
  ('50006', '$2b$12$.eGzDuJ/FokVPQSWuJHv2uy1XJIDzNhBu7mJQJNPKDQTVe/eWUQ2G', 'profesor', NULL, 7, 1),
  ('50007', '$2b$12$0EyefyU1qZFIcym5KtVVH./ngyGfa3ZDL2nG3LQvRroztcGHDaKqu', 'profesor', NULL, 8, 1),
  ('50008', '$2b$12$ZMXsHSKDobnb34DLLI5.N.hL/MfkCYEdfDPwV6PRA67kVvbS1VZ2C', 'profesor', NULL, 9, 1),
  ('50009', '$2b$12$Wn7bvETJMNvExL.5hKMGtOdC4y0o5/dpdu9nUi.4/eFoxegfT4XWS', 'profesor', NULL, 10, 1),
  ('10000', '$2b$12$iVsmuWwaZhhBDNe89WZyW.iA85o.GX7OqPXJSDds52Qs9M7xGGVly', 'alumno', 1, NULL, 1),
  ('10001', '$2b$12$Onxrv9ICWosKHzJREjijluYEDoD6JXC2NGwQLBT.uW.Jb3xJkECbS', 'alumno', 2, NULL, 1),
  ('10002', '$2b$12$ol0gSkM1QUcuNUzXnMJ/keDPFwpp7sWbNBjYtKXhm.y8ZzHwGDp0m', 'alumno', 3, NULL, 1),
  ('10003', '$2b$12$K6IbRZcmKNyjboX7clBQUettAHChJc3/8uPbQrAopiKYpKqROG8E.', 'alumno', 4, NULL, 1),
  ('10004', '$2b$12$/v22h.URw9d8Vt928qczU.3wZJAloI8C59vKin9RxZq18Kpsi/2Aa', 'alumno', 5, NULL, 1),
  ('10005', '$2b$12$JqmpDBG56BQA71PMHxCHTu5tKaLciAqRi4IpQL3KCBvw6WTqCaswC', 'alumno', 6, NULL, 1),
  ('10006', '$2b$12$hELFSTDwWmLhwtrv2O1tyek87AvIVZC.Xbpo5zzT.gtKD8TCgYKe6', 'alumno', 7, NULL, 1),
  ('10007', '$2b$12$XokkgOxY3Osr/Qxl5iqgFOwyLeCdfrSFXU8jdT210pkrTgPwodueO', 'alumno', 8, NULL, 1),
  ('10008', '$2b$12$5ek25Al8VftWXCIamUQPku2OzWFbPL.gMJM8atfGvt9StbArWkatS', 'alumno', 9, NULL, 1),
  ('10009', '$2b$12$0ZqvFx8ivhe1JI95M3jFWewnAt1vzOcbuHBPY19R8.XkgR.A.61F2', 'alumno', 10, NULL, 1),
  ('10010', '$2b$12$.b.pGP.CjpxKiQ1VmZE0s.GhPHVJq6IuRNQtfL51OweZBgKfAv6gS', 'alumno', 11, NULL, 1),
  ('10011', '$2b$12$0CWw33kGGpUJ8Mih5yqBKuurKRVnlS9RAD42DrcanS2O7A98tVGzi', 'alumno', 12, NULL, 1),
  ('10012', '$2b$12$7vd1hs7NuD1n8OZALLczP.91u6Fh4z1m8IX.rQUTdh/YBHE6tAk4q', 'alumno', 13, NULL, 1),
  ('10013', '$2b$12$lr4r16OWnxQJmCBLbSzajO1got2AjUdSTf.QrU3Irjm3WDJLirZOS', 'alumno', 14, NULL, 1),
  ('10014', '$2b$12$n/n7S521sfxbs07AZBodpeJs1dnJTVjupqkOv5b6KmqL2GO2TlE3G', 'alumno', 15, NULL, 1),
  ('10015', '$2b$12$U1LmB4U18CFM/zvsFN7iZOtQWkrI7MGDYElBqqznktxYYQAbR1fy6', 'alumno', 16, NULL, 1),
  ('10016', '$2b$12$0KAS1KJLjqeb4cgJ0vCBEe6MX/KqMSWr2nSpgtXjwZmELRoeXPMqe', 'alumno', 17, NULL, 1),
  ('10017', '$2b$12$N1UJl.YX2RRMzFmk2Zt6U.PoHf8cP25Lwmj1BgMx06BlhIarPA1qO', 'alumno', 18, NULL, 1),
  ('10018', '$2b$12$owz2ulXEy9a2DDUW4.AYv.lDUrGXmvzH.OLTxjnYc959ZZs9Z4zOS', 'alumno', 19, NULL, 1),
  ('10019', '$2b$12$/7f9P2hUXXtH.gHcI6sSyOeJ.jXMkUuZ7t7C8FkIpYVM9r8SicaUa', 'alumno', 20, NULL, 1),
  ('10020', '$2b$12$4ENgj1b.bAy1q/1QmiTufuUvAJ1gKTzvO4zlIticbl8.nBYHLfhUe', 'alumno', 21, NULL, 1),
  ('10021', '$2b$12$4NA0KocvncCEktVr2ZdDL.rWOEp01L.Onb1qUAMC15IMu6V0DPziO', 'alumno', 22, NULL, 1),
  ('10022', '$2b$12$AbdSNYUB7Yt5pNxKV3L44uxpwirN25ywH9KceYJtUS5j0hQdv5HKS', 'alumno', 23, NULL, 1),
  ('10023', '$2b$12$dhSv96.k8n0huqhHvo8BIuoIhfM52.8r5Jv9TVMLJIfwBiDM7jqIm', 'alumno', 24, NULL, 1),
  ('10024', '$2b$12$egk1Q1JFYnKPNBqfLQON1.RUK54X1yKdimaz07jZkImuFxaANSVIm', 'alumno', 25, NULL, 1),
  ('10025', '$2b$12$yuwGcd1CwSUW43Pk.7WySunEpMfp0cGbYFTo6ihWN0NMrXVTu4aLu', 'alumno', 26, NULL, 1),
  ('10026', '$2b$12$nkPRrZZl3sIWlDNWD6IlcOhyPc9O1JPKdZG2qTBEBu1QodP0ELo2q', 'alumno', 27, NULL, 1),
  ('10027', '$2b$12$d.OvqfuJbl6nYKfa1qaTduKe9tVLipRzlI5/7A1n.R6pYoLPIxiwm', 'alumno', 28, NULL, 1),
  ('10028', '$2b$12$4YkcJmf6FQpeAzcI0P8p/.nBJcC8jGQuZLx2Xir/efdEv4qQ.2LAG', 'alumno', 29, NULL, 1),
  ('10029', '$2b$12$sTeW3JtpzadzwGdCEgddTuFCMZYHk24q2O4ZrC2M.x7wfkxSKNV8G', 'alumno', 30, NULL, 1),
  ('10030', '$2b$12$X2IYvKRrn9hh8a.Nthwtqe0T1XhwaCwfzM3CXMTs9HY0y40FOjZlG', 'alumno', 31, NULL, 1),
  ('10031', '$2b$12$j6SY.Ij/59yB0JA16hJHOObTvNfOyCq8PkbI2dUs828zXj.pt8kGu', 'alumno', 32, NULL, 1),
  ('10032', '$2b$12$AXdPnAmSwE0Tr7XsFZHDuudQ5IzAbefyM9BP/istecFDT/gwDXQM6', 'alumno', 33, NULL, 1),
  ('10033', '$2b$12$bS1f/t2VJR9b1bgpOI2arul5qtpF0zF06CWFh1Cj/E5G9H8VRmR2u', 'alumno', 34, NULL, 1),
  ('10034', '$2b$12$hwIJBf9GPylIZvjOyE2pP.Q/oEE/zIltml/zN7krg6Viq7VJ8/Vse', 'alumno', 35, NULL, 1),
  ('10035', '$2b$12$UgzIZsKAcB3qYbZbijUKfusXoLuRJ4LIWE/0GU5XiRsYaFmy3dccO', 'alumno', 36, NULL, 1),
  ('10036', '$2b$12$OykvLwV5cqcpBviWc.D41u2EdZhhtjPxrXA66VmYQDyCDrnUheD66', 'alumno', 37, NULL, 1),
  ('10037', '$2b$12$Qiq4uc0e15F3yY5gT99GneAkFSm6Tq3kL/n3iJVyJIHxpxcSKyQta', 'alumno', 38, NULL, 1),
  ('10038', '$2b$12$D/ngM89I/nm7iHFG6kP.4eARr5aelZWoGN0xikIO9lgwuGJlGsFL6', 'alumno', 39, NULL, 1),
  ('10039', '$2b$12$xSURN4VgeasiregJaoxwh.8jC4.AowZtaMwbf5J6fgBxwDBIpVkzO', 'alumno', 40, NULL, 1);

-- Las secuencias continúan después de los datos cargados
UPDATE secuencias SET siguiente = 50010 WHERE tipo = 'profesor';
UPDATE secuencias SET siguiente = 10040 WHERE tipo = 'alumno';

-- ---------------------------------------------------------------------
-- 6. Carga académica: cada profesor imparte su materia en los 6 grupos
-- ---------------------------------------------------------------------
INSERT INTO asignaciones (grupo_id, materia_id, profesor_id)
SELECT g.id, m.id, m.id
FROM grupos g CROSS JOIN materias m
WHERE g.ciclo_id = 1;

-- ---------------------------------------------------------------------
-- 7. Inscripciones de los 40 alumnos a sus grupos
-- ---------------------------------------------------------------------
INSERT INTO inscripciones (alumno_id, grupo_id, estatus, fecha_inscripcion) VALUES
  (1, 1, 'activo', '2026-01-05'),
  (2, 1, 'activo', '2026-01-05'),
  (3, 1, 'activo', '2026-01-05'),
  (4, 1, 'activo', '2026-01-05'),
  (5, 1, 'activo', '2026-01-05'),
  (6, 1, 'activo', '2026-01-05'),
  (7, 1, 'activo', '2026-01-05'),
  (8, 2, 'activo', '2026-01-05'),
  (9, 2, 'activo', '2026-01-05'),
  (10, 2, 'activo', '2026-01-05'),
  (11, 2, 'activo', '2026-01-05'),
  (12, 2, 'activo', '2026-01-05'),
  (13, 2, 'activo', '2026-01-05'),
  (14, 2, 'activo', '2026-01-05'),
  (15, 3, 'activo', '2026-01-05'),
  (16, 3, 'activo', '2026-01-05'),
  (17, 3, 'activo', '2026-01-05'),
  (18, 3, 'activo', '2026-01-05'),
  (19, 3, 'activo', '2026-01-05'),
  (20, 3, 'activo', '2026-01-05'),
  (21, 3, 'activo', '2026-01-05'),
  (22, 4, 'activo', '2026-01-05'),
  (23, 4, 'activo', '2026-01-05'),
  (24, 4, 'activo', '2026-01-05'),
  (25, 4, 'activo', '2026-01-05'),
  (26, 4, 'activo', '2026-01-05'),
  (27, 4, 'activo', '2026-01-05'),
  (28, 4, 'activo', '2026-01-05'),
  (29, 5, 'activo', '2026-01-05'),
  (30, 5, 'activo', '2026-01-05'),
  (31, 5, 'activo', '2026-01-05'),
  (32, 5, 'activo', '2026-01-05'),
  (33, 5, 'activo', '2026-01-05'),
  (34, 5, 'activo', '2026-01-05'),
  (35, 6, 'activo', '2026-01-05'),
  (36, 6, 'activo', '2026-01-05'),
  (37, 6, 'activo', '2026-01-05'),
  (38, 6, 'activo', '2026-01-05'),
  (39, 6, 'activo', '2026-01-05'),
  (40, 6, 'activo', '2026-01-05');

-- ---------------------------------------------------------------------
-- 8. Calificaciones de los 2 semestres (todas las materias de cada alumno)
--    Valores entre 5.0 y 10.0 con un decimal, repetibles (derivados de CRC32).
-- ---------------------------------------------------------------------
INSERT INTO calificaciones (inscripcion_id, asignacion_id, periodo_id, calificacion)
SELECT i.id, a.id, p.id,
       ROUND(5 + (CRC32(CONCAT(i.id, '-', a.id, '-', p.id)) % 51) / 10, 1)
FROM inscripciones i
JOIN asignaciones a ON a.grupo_id = i.grupo_id
JOIN periodos p     ON p.ciclo_id = 1;

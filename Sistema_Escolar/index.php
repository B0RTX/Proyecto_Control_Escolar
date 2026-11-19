<?php
require 'includes/auth.php';
$uid = $USR['id'];

require 'includes/header.php';
?>
<div class="card">
  <h2 style="margin:0">Bienvenido(a), <?= e($USR['nombre']) ?></h2>
  <p class="muted" style="margin:4px 0 0">Rol: <b><?= e(rol_texto($USR['rol'])) ?></b></p>
</div>
<?php

/* =================== ADMIN =================== */
if ($USR['rol'] === 'admin'):
    // Todos los totales en una sola consulta
    $t = $conn->query("SELECT (SELECT COUNT(*) FROM alumnos)    alumnos,
                              (SELECT COUNT(*) FROM profesores) profesores,
                              (SELECT COUNT(*) FROM materias)   materias,
                              (SELECT COUNT(*) FROM usuarios)   usuarios,
                              (SELECT ROUND(AVG(calificacion),1) FROM calificaciones) prom,
                              (SELECT COUNT(*) FROM calificaciones WHERE calificacion < 6) reprob")->fetch_assoc();
    $prom   = $t['prom'] ?? '-';
    $reprob = (int)$t['reprob'];
    $cicloAct = $conn->query("SELECT id, nombre FROM ciclos_escolares WHERE activo = 1 LIMIT 1")->fetch_assoc();
    $ciclo    = $cicloAct['nombre'] ?? null;
    $cicloId  = (int)($cicloAct['id'] ?? 0);

    $st = $conn->prepare("SELECT g.id, CONCAT(gr.nombre, g.letra) grupo, COUNT(DISTINCT i.alumno_id) alumnos, ROUND(AVG(c.calificacion),1) prom
                          FROM grupos g
                          JOIN grados gr ON gr.id = g.grado_id
                          LEFT JOIN inscripciones i ON i.grupo_id = g.id
                          LEFT JOIN calificaciones c ON c.inscripcion_id = i.id
                          WHERE g.ciclo_id = ?
                          GROUP BY g.id, gr.nombre, g.letra ORDER BY gr.nombre, g.letra");
    $st->bind_param("i", $cicloId);
    $st->execute();
    $porGrupo = $st->get_result();
?>
<h3>Resumen general<?= $ciclo ? ' · Ciclo ' . e($ciclo) : '' ?></h3>
<?php if (!$ciclo): ?>
  <p class="error">Aún no hay un ciclo escolar activo. Crea el ciclo, sus periodos, grados y grupos para comenzar.</p>
<?php endif; ?>
<div class="stats">
  <a class="card" href="alumnos.php"><b><?= (int)$t['alumnos'] ?></b>Alumnos</a>
  <a class="card" href="profesores.php"><b><?= (int)$t['profesores'] ?></b>Profesores</a>
  <a class="card" href="materias.php"><b><?= (int)$t['materias'] ?></b>Materias</a>
  <a class="card" href="usuarios.php"><b><?= (int)$t['usuarios'] ?></b>Usuarios</a>
  <a class="card" href="calificaciones.php"><b><?= e($prom) ?></b>Promedio general</a>
  <a class="card" href="calificaciones.php"><b class="error"><?= $reprob ?></b>Notas reprobatorias</a>
</div>
<div class="card">
  <h3>Promedio por grupo (ciclo activo)</h3>
  <div class="tabla-wrap"><table>
    <tr><th>Grupo</th><th>Alumnos</th><th>Promedio</th></tr>
    <?php foreach ($porGrupo as $g): ?>
      <tr data-href="alumnos.php?grupo=<?= (int)$g['id'] ?>"><td><a class="discreto" href="alumnos.php?grupo=<?= (int)$g['id'] ?>"><?= e($g['grupo']) ?></a></td><td><?= (int)$g['alumnos'] ?></td><td><?= e($g['prom'] ?? '-') ?></td></tr>
    <?php endforeach; ?>
  </table></div>
</div>

<?php
/* =================== PROFESOR =================== */
elseif ($USR['rol'] === 'profesor'):
    $st = $conn->prepare("SELECT asignacion_id, ciclo, grupo, materia FROM vw_asignaciones_profesor WHERE usuario_id = ? ORDER BY ciclo DESC, grupo, materia");
    $st->bind_param("i", $uid);
    $st->execute();
    $mis = $st->get_result();
?>
<div class="card">
  <h3>Mis grupos y materias</h3>
  <div class="tabla-wrap"><table>
    <tr><th>Ciclo</th><th>Grupo</th><th>Materia</th></tr>
    <?php if ($mis->num_rows === 0): ?>
      <tr><td colspan="3" class="muted">Todavía no tienes materias asignadas.</td></tr>
    <?php endif; ?>
    <?php foreach ($mis as $m): ?>
      <tr data-href="calificaciones.php?asignacion=<?= (int)$m['asignacion_id'] ?>"><td><a class="discreto" href="calificaciones.php?asignacion=<?= (int)$m['asignacion_id'] ?>"><?= e($m['ciclo']) ?></a></td><td><?= e($m['grupo']) ?></td><td><?= e($m['materia']) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
</div>

<?php
/* =================== ALUMNO =================== */
else:
    $st = $conn->prepare("SELECT ciclo, grupo, materia, periodo, calificacion FROM vw_calificaciones_alumno
                          WHERE usuario_id = ? ORDER BY ciclo DESC, materia, periodo");
    $st->bind_param("i", $uid);
    $st->execute();
    $notas = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $prom = $notas ? round(array_sum(array_column($notas, 'calificacion')) / count($notas), 1) : null;
?>
<div class="card">
  <h3>Mis calificaciones</h3>
  <?php if ($notas): ?>
  <div class="tabla-wrap"><table>
    <tr><th>Ciclo</th><th>Grupo</th><th>Materia</th><th>Periodo</th><th>Calificación</th></tr>
    <?php foreach ($notas as $n): ?>
      <tr data-href="boleta.php">
        <td><a class="discreto" href="boleta.php"><?= e($n['ciclo']) ?></a></td><td><?= e($n['grupo']) ?></td><td><?= e($n['materia']) ?></td>
        <td><?= (int)$n['periodo'] ?></td>
        <td class="<?= $n['calificacion'] >= 6 ? 'ok' : 'error' ?>"><b><?= e($n['calificacion']) ?></b></td>
      </tr>
    <?php endforeach; ?>
    <tr><th colspan="4">Promedio general</th><th class="<?= $prom >= 6 ? 'ok' : 'error' ?>"><?= e($prom) ?></th></tr>
  </table></div>
  <?php else: ?>
    <p class="muted">Aún no tienes calificaciones registradas.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
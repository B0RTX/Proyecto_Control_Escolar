<?php
require 'includes/auth.php';
requiere_rol('alumno');                    // solo lectura: el alumno ve SU información
$aid = (int)$USR['alumno_id'];

$st = $conn->prepare("SELECT * FROM alumnos WHERE id = ?");
$st->bind_param("i", $aid); $st->execute();
$yo = $st->get_result()->fetch_assoc();

// Todas mis inscripciones (historial)
$st = $conn->prepare("SELECT i.id, i.estatus, g.id grupo_id, gr.nombre grado, g.letra, c.id ciclo_id, c.nombre ciclo, c.activo
                      FROM inscripciones i JOIN grupos g ON g.id = i.grupo_id JOIN grados gr ON gr.id = g.grado_id
                      JOIN ciclos_escolares c ON c.id = g.ciclo_id
                      WHERE i.alumno_id = ? ORDER BY c.fecha_inicio DESC");
$st->bind_param("i", $aid); $st->execute();
$insc = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$sel = (int)($_GET['ciclo'] ?? 0);
$actual = null;
foreach ($insc as $i) if ($sel ? (int)$i['ciclo_id'] === $sel : (bool)$i['activo']) { $actual = $i; break; }
if (!$actual && $insc) $actual = $insc[0];

$materias = [];
if ($actual) {
    $st = $conn->prepare("SELECT m.nombre materia, TRIM(CONCAT_WS(' ', p.nombre, p.apellido_paterno, p.apellido_materno)) profesor
                          FROM asignaciones a JOIN materias m ON m.id = a.materia_id
                          LEFT JOIN profesores p ON p.id = a.profesor_id
                          WHERE a.grupo_id = ? ORDER BY m.nombre");
    $st->bind_param("i", $actual['grupo_id']); $st->execute();
    $materias = $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

require 'includes/header.php';
?>
<h2>Mi grupo y mis materias</h2>
<div class="dos">
  <div class="card">
    <h3>Mis datos</h3>
    <p><b>Nombre:</b> <?= e(trim($yo['nombre'] . ' ' . $yo['apellido_paterno'] . ' ' . $yo['apellido_materno'])) ?><br>
       <b>Matrícula:</b> <?= e($yo['matricula']) ?><br>
       <b>Correo:</b> <?= e($yo['email'] ?? '-') ?><br>
       <b>Fecha de nacimiento:</b> <?= e($yo['fecha_nac'] ?? '-') ?></p>
    <p class="muted">Si algún dato es incorrecto, pide a control escolar que lo corrija.</p>
  </div>
  <div class="card">
    <h3>Grado y grupo</h3>
    <?php if ($actual): ?>
      <p style="font-size:1.6rem;margin:0"><b><?= e($actual['grado'] . $actual['letra']) ?></b></p>
      <p class="muted">Ciclo <?= e($actual['ciclo']) ?> · Estatus: <?= e($actual['estatus']) ?></p>
    <?php else: ?>
      <p class="muted">Todavía no estás inscrito en ningún grupo.</p>
    <?php endif; ?>
    <?php if (count($insc) > 1): ?>
      <p class="muted">Otros ciclos:
        <?php foreach ($insc as $i): ?><a href="?ciclo=<?= $i['ciclo_id'] ?>"><?= e($i['ciclo'] . ' (' . $i['grado'] . $i['letra'] . ')') ?></a> &nbsp;<?php endforeach; ?></p>
    <?php endif; ?>
  </div>
</div>

<?php if ($actual): ?>
<div class="card">
  <h3>Mis materias</h3>
  <div class="tabla-wrap"><table>
    <tr><th>Materia</th><th>Profesor</th></tr>
    <?php if (!$materias): ?><tr><td colspan="2" class="muted">Tu grupo aún no tiene materias asignadas.</td></tr><?php endif; ?>
    <?php foreach ($materias as $m): ?>
      <tr><td><?= e($m['materia']) ?></td><td><?= $m['profesor'] ? e($m['profesor']) : '<span class="muted">Por asignar</span>' ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <p><a class="btn" href="boleta.php?ciclo=<?= (int)$actual['ciclo_id'] ?>">Ver mis calificaciones</a></p>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>

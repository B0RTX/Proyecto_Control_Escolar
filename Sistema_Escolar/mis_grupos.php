<?php
require 'includes/auth.php';
requiere_rol('profesor');                 // vista de solo lectura del profesor

$st = $conn->prepare("SELECT DISTINCT c.id, c.nombre, c.activo, c.fecha_inicio
                      FROM asignaciones a
                      JOIN grupos g ON g.id = a.grupo_id
                      JOIN ciclos_escolares c ON c.id = g.ciclo_id
                      WHERE a.profesor_id = ?
                      ORDER BY c.fecha_inicio DESC");
$st->bind_param("i", $USR['profesor_id']);
$st->execute();
$ciclos = $st->get_result()->fetch_all(MYSQLI_ASSOC);


$cicloSel = (int)($_GET['ciclo'] ?? 0);
if (!$cicloSel) {
    foreach ($ciclos as $c) if ($c['activo']) $cicloSel = (int)$c['id'];
    if (!$cicloSel && $ciclos) $cicloSel = (int)$ciclos[0]['id'];
}
$mis = $cicloSel ? asignaciones_visibles($cicloSel) : [];
$esCicloActivo = $cicloSel && $cicloSel === ciclo_activo();

$asigId = (int)($_GET['asignacion'] ?? 0);
$det = $asigId ? asignacion_permitida($asigId) : null;      // valida que sea suya
$alumnos = $det ? alumnos_del_grupo((int)$det['grupo_id']) : [];

require 'includes/header.php';
?>
<h2>Mis grupos y materias</h2>
<?php if (!$ciclos): ?>
  <p class="muted">Todavía no tienes materias asignadas. Pide al administrador que te las asigne.</p>
<?php else: ?>
<form method="get" autocomplete="off" class="buscar">
  <select name="ciclo" data-autosubmit>
    <?php foreach ($ciclos as $c): ?><option value="<?= $c['id'] ?>" <?= (int)$c['id'] === $cicloSel ? 'selected' : '' ?>>Ciclo <?= e($c['nombre']) ?><?= $c['activo'] ? ' (activo)' : '' ?></option><?php endforeach; ?>
  </select>
</form>
<div class="tabla-wrap"><table>
  <tr><th>Grupo</th><th>Materia</th><th>Alumnos</th><th>Acciones</th></tr>
  <?php if (!$mis): ?><tr><td colspan="4" class="muted">No tienes materias asignadas en este ciclo.</td></tr><?php endif; ?>
  <?php foreach ($mis as $m): ?>
  <tr>
    <td><b><?= e($m['grupo']) ?></b></td><td><?= e($m['materia']) ?></td><td><?= (int)$m['alumnos'] ?></td>
    <td>
      <a class="btn sm gris" href="?ciclo=<?= $cicloSel ?>&asignacion=<?= $m['id'] ?>">Ver alumnos</a>
      <a class="btn sm" href="calificaciones.php?ciclo=<?= $cicloSel ?>&asignacion=<?= $m['id'] ?>">Calificaciones</a>
      <?php if ($esCicloActivo): ?><a class="btn sm" href="asistencias.php?asignacion=<?= $m['id'] ?>">Pasar lista</a><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table></div>

<?php if ($det): ?>
<div class="card">
  <h3>Alumnos de <?= e($det['grupo']) ?> · <?= e($det['materia']) ?></h3>
  <div class="tabla-wrap"><table>
    <tr><th>#</th><th>Matrícula</th><th>Alumno</th><th>Correo</th></tr>
    <?php foreach ($alumnos as $i => $al): ?>
      <tr><td><?= $i + 1 ?></td><td><?= e($al['matricula']) ?></td><td><?= e($al['nombre']) ?></td><td><?= e($al['email']) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; endif; ?>
<?php require 'includes/footer.php'; ?>

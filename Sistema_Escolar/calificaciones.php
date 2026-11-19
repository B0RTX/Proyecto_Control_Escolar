<?php
require 'includes/auth.php';
requiere_rol('admin', 'profesor');     // el alumno NO puede capturar; solo consulta su boleta
$esAdmin = $USR['rol'] === 'admin';

/* ---------- Guardar lote (crear / actualizar) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $asigId = (int)($_POST['asignacion_id'] ?? 0);
    $perId  = (int)($_POST['periodo_id'] ?? 0);
    $asig   = asignacion_permitida($asigId);          // el profesor solo accede a SUS asignaciones
    if (!$asig) {
        flash("No tienes permiso sobre esa materia y grupo.", 'error');
        redirigir('calificaciones.php');
    }
    $dest = "calificaciones.php?ciclo={$asig['ciclo_id']}&grupo={$asig['grupo_id']}&asignacion=$asigId&periodo=$perId";

    if (!$esAdmin && !$asig['ciclo_activo']) {
        flash("Solo puedes modificar calificaciones del ciclo activo.", 'error');
        redirigir($dest);
    }
    $st = $conn->prepare("SELECT 1 FROM periodos WHERE id = ? AND ciclo_id = ?");
    $st->bind_param("ii", $perId, $asig['ciclo_id']); $st->execute();
    if (!$st->get_result()->fetch_row()) {
        flash("El periodo no pertenece a ese ciclo.", 'error');
        redirigir($dest);
    }

    $validas = array_column(alumnos_del_grupo((int)$asig['grupo_id']), 'inscripcion_id');
    $cal = $_POST['cal'] ?? [];
    if (!is_array($cal)) $cal = [];
    $guardadas = $invalidas = 0;

    try {
        $conn->begin_transaction();
        $up = $conn->prepare("INSERT INTO calificaciones (inscripcion_id, asignacion_id, periodo_id, calificacion)
                              VALUES (?,?,?,?)
                              ON DUPLICATE KEY UPDATE calificacion = VALUES(calificacion)");
        foreach ($validas as $iid) {
            $iid = (int)$iid;
            $v = $cal[$iid] ?? '';
            $v = is_string($v) ? str_replace(',', '.', trim($v)) : '';
            if ($v === '') continue;                       // vacío = no se registra ni se cambia
            if (!is_numeric($v) || $v < 0 || $v > 10) { $invalidas++; continue; }
            $nota = round((float)$v, 1);
            $up->bind_param("iiid", $iid, $asigId, $perId, $nota); $up->execute();
            $guardadas++;
        }
        $conn->commit();
        flash("Calificaciones guardadas: $guardadas.");
        if ($invalidas) flash("$invalidas valor(es) fuera de rango (0 a 10) no se guardaron.", 'error');
    } catch (mysqli_sql_exception $ex) {
        $conn->rollback();
        error_log($ex->getMessage());
        flash("No se pudieron guardar las calificaciones.", 'error');
    }
    redirigir($dest);
}

/* ---------- Selección: ciclo → grupo → materia → periodo ---------- */
$ciclos = $conn->query("SELECT id, nombre, activo FROM ciclos_escolares ORDER BY fecha_inicio DESC")->fetch_all(MYSQLI_ASSOC);

$asigId = (int)($_GET['asignacion'] ?? 0);
$asig = $asigId ? asignacion_permitida($asigId) : null;      // valida que el profesor solo vea las suyas

$cicloSel = (int)($_GET['ciclo'] ?? 0);
if (!$cicloSel && $asig) $cicloSel = (int)$asig['ciclo_id'];  // enlaces que solo traen la materia
if (!$cicloSel) $cicloSel = ciclo_activo();
if (!$cicloSel && $ciclos) $cicloSel = (int)$ciclos[0]['id'];
if ($asig && (int)$asig['ciclo_id'] !== $cicloSel) $asig = null;

$asignaciones = $cicloSel ? asignaciones_visibles($cicloSel) : [];

// Grupos (sin repetir) y, del grupo elegido, sus materias
$grupos = [];
foreach ($asignaciones as $a) $grupos[(int)$a['grupo_id']] = $a['grupo'];
$grupoSel = (int)($_GET['grupo'] ?? 0);
if (!$grupoSel && $asig) $grupoSel = (int)$asig['grupo_id'];
if (!isset($grupos[$grupoSel])) $grupoSel = 0;
if ($asig && (int)$asig['grupo_id'] !== $grupoSel) $asig = null;
$asigId = (int)($asig['id'] ?? 0);
$materias = array_values(array_filter($asignaciones, fn($a) => (int)$a['grupo_id'] === $grupoSel));

$periodos = [];
if ($cicloSel) {
    $st = $conn->prepare("SELECT id, numero, nombre FROM periodos WHERE ciclo_id = ? ORDER BY numero");
    $st->bind_param("i", $cicloSel); $st->execute();
    $periodos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
}
$perId = (int)($_GET['periodo'] ?? 0);
if (!in_array($perId, array_map('intval', array_column($periodos, 'id')), true)) $perId = $periodos ? (int)$periodos[0]['id'] : 0;

$alumnos = $matriz = [];
$puedeEditar = false;
if ($asig && $perId) {
    $puedeEditar = $esAdmin || $asig['ciclo_activo'];
    $st = $conn->prepare("SELECT i.id inscripcion_id, a.matricula,
                                 CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombre) nombre,
                                 c.calificacion
                          FROM inscripciones i JOIN alumnos a ON a.id = i.alumno_id
                          LEFT JOIN calificaciones c ON c.inscripcion_id = i.id AND c.asignacion_id = ? AND c.periodo_id = ?
                          WHERE i.grupo_id = ? AND i.estatus = 'activo'
                          ORDER BY LENGTH(a.matricula), a.matricula");
    $st->bind_param("iii", $asigId, $perId, $asig['grupo_id']); $st->execute();
    $alumnos = $st->get_result()->fetch_all(MYSQLI_ASSOC);

    $st = $conn->prepare("SELECT c.inscripcion_id, p.numero, c.calificacion FROM calificaciones c
                          JOIN periodos p ON p.id = c.periodo_id WHERE c.asignacion_id = ?");
    $st->bind_param("i", $asigId); $st->execute();
    foreach ($st->get_result() as $r) $matriz[$r['inscripcion_id']][$r['numero']] = (float)$r['calificacion'];
}
$notasPeriodo = array_filter(array_column($alumnos, 'calificacion'), fn($v) => $v !== null);
$promPeriodo = $notasPeriodo ? round(array_sum($notasPeriodo) / count($notasPeriodo), 1) : null;

require 'includes/header.php';
?>
<h2>Calificaciones</h2>
<p class="muted"><?= $esAdmin ? 'Como administrador puedes capturar y corregir cualquier grupo.' : 'Aquí aparecen solo los grupos y materias que tienes asignados.' ?></p>

<form method="get" autocomplete="off" class="buscar">
  <select name="ciclo" data-autosubmit data-limpiar="grupo,asignacion">
    <?php foreach ($ciclos as $c): ?>
      <option value="<?= $c['id'] ?>" <?= (int)$c['id'] === $cicloSel ? 'selected' : '' ?>>Ciclo <?= e($c['nombre']) ?><?= $c['activo'] ? ' (activo)' : '' ?></option>
    <?php endforeach; ?>
  </select>
  <select name="grupo" data-autosubmit data-limpiar="asignacion">
    <option value="">-- Grupo --</option>
    <?php foreach ($grupos as $gid => $gnombre): ?>
      <option value="<?= $gid ?>" <?= $gid === $grupoSel ? 'selected' : '' ?>><?= e($gnombre) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="asignacion" data-autosubmit <?= $grupoSel ? '' : 'disabled' ?>>
    <option value="">-- Materia --</option>
    <?php foreach ($materias as $m): ?>
      <option value="<?= $m['id'] ?>" <?= $asigId === (int)$m['id'] ? 'selected' : '' ?>>
        <?= e($m['materia'] . ($esAdmin ? ' (' . ($m['profesor'] ?: 'sin profesor') . ')' : '')) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="periodo" data-autosubmit>
    <?php foreach ($periodos as $p): ?><option value="<?= $p['id'] ?>" <?= $perId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?>
  </select>
</form>

<?php if (!$asignaciones): ?>
  <p class="muted"><?= $esAdmin ? 'No hay asignaciones en este ciclo (créalas en Asignaciones).' : 'No tienes materias asignadas en este ciclo.' ?></p>
<?php elseif (!$asig): ?>
  <p class="muted">Elige un grupo y una materia para ver o capturar calificaciones.</p>
<?php elseif ($perId): ?>
<div class="card">
  <h3><?= e($asig['grupo'] . ' · ' . $asig['materia']) ?></h3>
  <?php if (!$puedeEditar): ?><p class="error">Ciclo cerrado: solo puedes consultar estas calificaciones.</p><?php endif; ?>
  <?php if (!$alumnos): ?>
    <p class="muted">Este grupo no tiene alumnos inscritos.</p>
  <?php else: ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="asignacion_id" value="<?= $asigId ?>"><input type="hidden" name="periodo_id" value="<?= $perId ?>">
    <div class="tabla-wrap"><table>
      <tr><th>Matrícula</th><th>Alumno</th><th>Calificación (0-10)</th></tr>
      <?php foreach ($alumnos as $al): $iid = (int)$al['inscripcion_id']; ?>
      <tr>
        <td><?= e($al['matricula']) ?></td><td><?= e($al['nombre']) ?></td>
        <td><input type="number" step="0.1" min="0" max="10" name="cal[<?= $iid ?>]" style="width:100px"
                   value="<?= e($al['calificacion']) ?>" <?= $puedeEditar ? '' : 'disabled' ?>></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <p class="muted">Promedio del periodo: <b><?= $promPeriodo !== null ? e($promPeriodo) : '-' ?></b>
       · Deja vacío para no registrar una calificación.</p>
    <?php if ($puedeEditar): ?><button>Guardar calificaciones</button><?php endif; ?>
  </form>
  <?php endif; ?>
</div>

<?php if ($alumnos): ?>
<div class="card">
  <h3>Resumen de todos los periodos</h3>
  <div class="tabla-wrap"><table>
    <tr><th>Matrícula</th><th>Alumno</th><?php foreach ($periodos as $p): ?><th><?= e($p['nombre']) ?></th><?php endforeach; ?><th>Promedio</th></tr>
    <?php foreach ($alumnos as $al):
        $fila = $matriz[$al['inscripcion_id']] ?? [];
        $pr = $fila ? round(array_sum($fila) / count($fila), 1) : null; ?>
    <tr><td><?= e($al['matricula']) ?></td><td><?= e($al['nombre']) ?></td>
      <?php foreach ($periodos as $p): $v = $fila[$p['numero']] ?? null; ?>
        <td class="<?= $v === null ? '' : ($v >= 6 ? 'ok' : 'error') ?>"><?= $v === null ? '-' : e($v) ?></td>
      <?php endforeach; ?>
      <td class="<?= $pr === null ? '' : ($pr >= 6 ? 'ok' : 'error') ?>"><b><?= $pr === null ? '-' : e($pr) ?></b></td></tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>

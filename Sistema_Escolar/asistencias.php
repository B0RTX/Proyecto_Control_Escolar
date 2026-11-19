<?php
require 'includes/auth.php';
$ESTADOS = ['presente' => 'Presente', 'falta' => 'Falta', 'retardo' => 'Retardo', 'justificada' => 'Justificada'];

/* =================== ALUMNO: solo consulta de SUS asistencias =================== */
if ($USR['rol'] === 'alumno') {
    $ciclo = ciclo_activo();
    $aid = (int)$USR['alumno_id'];
    $st = $conn->prepare("SELECT m.nombre materia, COUNT(*) total,
                                 SUM(s.estatus='presente') presentes, SUM(s.estatus='falta') faltas,
                                 SUM(s.estatus='retardo') retardos, SUM(s.estatus='justificada') justificadas
                          FROM asistencias s
                          JOIN inscripciones i ON i.id = s.inscripcion_id
                          JOIN asignaciones a ON a.id = s.asignacion_id
                          JOIN materias m ON m.id = a.materia_id JOIN grupos g ON g.id = a.grupo_id
                          WHERE i.alumno_id = ? AND g.ciclo_id = ? GROUP BY m.id, m.nombre ORDER BY m.nombre");
    $st->bind_param("ii", $aid, $ciclo); $st->execute();
    $resumen = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st = $conn->prepare("SELECT s.fecha, s.estatus, m.nombre materia
                          FROM asistencias s JOIN inscripciones i ON i.id = s.inscripcion_id
                          JOIN asignaciones a ON a.id = s.asignacion_id JOIN materias m ON m.id = a.materia_id
                          JOIN grupos g ON g.id = a.grupo_id
                          WHERE i.alumno_id = ? AND g.ciclo_id = ? AND s.estatus <> 'presente'
                          ORDER BY s.fecha DESC LIMIT 30");
    $st->bind_param("ii", $aid, $ciclo); $st->execute();
    $incid = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    require 'includes/header.php';
?>
<h2>Mis asistencias</h2>
<div class="card"><h3>Resumen por materia (ciclo activo)</h3>
  <div class="tabla-wrap"><table>
    <tr><th>Materia</th><th>Clases</th><th>Presentes</th><th>Faltas</th><th>Retardos</th><th>Justificadas</th></tr>
    <?php if (!$resumen): ?><tr><td colspan="6" class="muted">Todavía no hay asistencias registradas.</td></tr><?php endif; ?>
    <?php foreach ($resumen as $r): ?>
    <tr><td><?= e($r['materia']) ?></td><td><?= (int)$r['total'] ?></td><td class="ok"><?= (int)$r['presentes'] ?></td>
        <td class="<?= $r['faltas'] ? 'error' : '' ?>"><?= (int)$r['faltas'] ?></td><td><?= (int)$r['retardos'] ?></td><td><?= (int)$r['justificadas'] ?></td></tr>
    <?php endforeach; ?>
  </table></div></div>
<?php if ($incid): ?>
<div class="card"><h3>Últimas incidencias</h3>
  <div class="tabla-wrap"><table><tr><th>Fecha</th><th>Materia</th><th>Estatus</th></tr>
    <?php foreach ($incid as $r): ?><tr><td><?= e($r['fecha']) ?></td><td><?= e($r['materia']) ?></td><td><?= e($ESTADOS[$r['estatus']] ?? $r['estatus']) ?></td></tr><?php endforeach; ?>
  </table></div></div>
<?php endif; ?>
<?php require 'includes/footer.php'; exit; }

/* =================== ADMIN / PROFESOR: pasar lista =================== */
requiere_rol('admin', 'profesor');
$ciclo = ciclo_activo();                    // la asistencia diaria se toma en el ciclo activo

function fecha_valida(string $f): bool {
    $d = DateTime::createFromFormat('Y-m-d', $f);
    return $d && $d->format('Y-m-d') === $f;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a      = $_POST['accion'] ?? '';
    $asigId = (int)($_POST['asignacion_id'] ?? 0);
    $fecha  = $_POST['fecha'] ?? '';
    $asig   = asignacion_permitida($asigId);
    $dest   = "asistencias.php?asignacion=$asigId&fecha=" . urlencode($fecha);

    if (!$asig || !$asig['ciclo_activo']) {
        flash("No tienes permiso sobre esa materia y grupo, o el ciclo no está activo.", 'error');
        redirigir('asistencias.php');
    }
    if (!fecha_valida($fecha)) { flash("La fecha no es válida.", 'error'); redirigir($dest); }

    if ($a === 'guardar') {
        $validas = array_column(alumnos_del_grupo((int)$asig['grupo_id']), 'inscripcion_id');
        $est = $_POST['est'] ?? [];
        try {
            $conn->begin_transaction();
            $up = $conn->prepare("INSERT INTO asistencias (inscripcion_id, asignacion_id, fecha, estatus) VALUES (?,?,?,?)
                                  ON DUPLICATE KEY UPDATE estatus = VALUES(estatus)");
            $n = 0;
            foreach ($validas as $iid) {
                $iid = (int)$iid;
                $e = $est[$iid] ?? '';
                if (!isset($ESTADOS[$e])) continue;
                $up->bind_param("iiss", $iid, $asigId, $fecha, $e); $up->execute(); $n++;
            }
            $conn->commit();
            bitacora('ASISTENCIA_GUARDADA', 'asignaciones', $asigId, "fecha=$fecha alumnos=$n");
            flash("Lista del $fecha guardada ($n alumnos).");
        } catch (mysqli_sql_exception $ex) {
            $conn->rollback(); error_log($ex->getMessage());
            flash("No se pudo guardar la lista.", 'error');
        }
    } elseif ($a === 'borrar_dia') {
        $st = $conn->prepare("DELETE FROM asistencias WHERE asignacion_id = ? AND fecha = ?");
        $st->bind_param("is", $asigId, $fecha); $st->execute();
        bitacora('ASISTENCIA_ELIMINADA', 'asignaciones', $asigId, "fecha=$fecha");
        flash("Se eliminó la lista del $fecha.");
    }
    redirigir($dest);
}

$asignaciones = $ciclo ? asignaciones_visibles($ciclo) : [];
$asigId = (int)($_GET['asignacion'] ?? 0);
$asig = $asigId ? asignacion_permitida($asigId) : null;
if ($asig && (int)$asig['ciclo_id'] !== $ciclo) $asig = null;

// Grupo y materia se eligen por separado: primero el grupo y luego sus materias
$grupos = [];
foreach ($asignaciones as $a) $grupos[(int)$a['grupo_id']] = $a['grupo'];
$grupoSel = (int)($_GET['grupo'] ?? 0);
if (!$grupoSel && $asig) $grupoSel = (int)$asig['grupo_id'];
if (!isset($grupos[$grupoSel])) $grupoSel = 0;
if ($asig && (int)$asig['grupo_id'] !== $grupoSel) $asig = null;
$asigId = (int)($asig['id'] ?? 0);
$materias = array_values(array_filter($asignaciones, fn($a) => (int)$a['grupo_id'] === $grupoSel));
$fecha = $_GET['fecha'] ?? date('Y-m-d');
if (!fecha_valida($fecha)) $fecha = date('Y-m-d');

$alumnos = [];
$hayLista = false;
if ($asig) {
    $st = $conn->prepare("SELECT i.id inscripcion_id, a.matricula,
                                 CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombre) nombre, s.estatus,
                                 (SELECT COUNT(*) FROM asistencias x WHERE x.inscripcion_id = i.id AND x.asignacion_id = ? AND x.estatus = 'falta') faltas
                          FROM inscripciones i JOIN alumnos a ON a.id = i.alumno_id
                          LEFT JOIN asistencias s ON s.inscripcion_id = i.id AND s.asignacion_id = ? AND s.fecha = ?
                          WHERE i.grupo_id = ? AND i.estatus = 'activo'
                          ORDER BY LENGTH(a.matricula), a.matricula");
    $st->bind_param("iisi", $asigId, $asigId, $fecha, $asig['grupo_id']); $st->execute();
    $alumnos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($alumnos as $al) if ($al['estatus'] !== null) $hayLista = true;
}

require 'includes/header.php';
?>
<h2>Asistencias</h2>
<?php if (!$ciclo): ?><p class="error">No hay un ciclo activo.</p><?php endif; ?>
<form method="get" autocomplete="off" class="buscar">
  <select name="grupo" data-autosubmit data-limpiar="asignacion"><option value="">-- Grupo --</option>
    <?php foreach ($grupos as $gid => $gnombre): ?>
      <option value="<?= $gid ?>" <?= $gid === $grupoSel ? 'selected' : '' ?>><?= e($gnombre) ?></option>
    <?php endforeach; ?></select>
  <select name="asignacion" data-autosubmit <?= $grupoSel ? '' : 'disabled' ?>><option value="">-- Materia --</option>
    <?php foreach ($materias as $m): ?>
      <option value="<?= $m['id'] ?>" <?= $asigId === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['materia']) ?></option>
    <?php endforeach; ?></select>
  <input type="date" name="fecha" value="<?= e($fecha) ?>" style="width:auto" data-autosubmit>
</form>
<?php if (!$asig): ?><p class="muted">Elige un grupo y una materia para pasar lista.</p><?php endif; ?>

<?php if ($asig): ?>
<div class="card">
  <h3><?= e($asig['grupo'] . ' · ' . $asig['materia']) ?> — <?= e($fecha) ?></h3>
  <?php if (!$alumnos): ?><p class="muted">Este grupo no tiene alumnos inscritos.</p><?php else: ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="asignacion_id" value="<?= $asigId ?>"><input type="hidden" name="fecha" value="<?= e($fecha) ?>">
    <div class="tabla-wrap"><table>
      <tr><th>Matrícula</th><th>Alumno</th><th>Asistencia</th><th>Faltas acumuladas</th></tr>
      <?php foreach ($alumnos as $al): $actual = $al['estatus'] ?? 'presente'; ?>
      <tr><td><?= e($al['matricula']) ?></td><td><?= e($al['nombre']) ?></td>
        <td><select name="est[<?= (int)$al['inscripcion_id'] ?>]" style="width:auto">
          <?php foreach ($ESTADOS as $k => $t): ?><option value="<?= $k ?>" <?= $actual === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
        </select></td>
        <td class="<?= $al['faltas'] ? 'error' : '' ?>"><?= (int)$al['faltas'] ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <button><?= $hayLista ? 'Actualizar lista' : 'Guardar lista' ?></button>
  </form>
  <?php if ($hayLista): ?>
    <p><?= btn_post('borrar_dia', ['asignacion_id' => $asigId, 'fecha' => $fecha], 'Eliminar la lista de este día', 'btn red sm', '¿Eliminar todos los registros de asistencia de este día?') ?></p>
  <?php endif; endif; ?>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>

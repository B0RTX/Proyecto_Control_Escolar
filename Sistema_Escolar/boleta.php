<?php
require 'includes/auth.php';
requiere_rol('admin', 'alumno');
$esAdmin = $USR['rol'] === 'admin';

// El alumno SOLO puede ver su propia boleta (el id sale de la sesión, nunca de la URL)
$alumnoId = $esAdmin ? (int)($_GET['alumno'] ?? 0) : (int)$USR['alumno_id'];

$alumno = null; $insc = []; $actual = null; $periodos = []; $filas = []; $promGen = null;
if ($alumnoId) {
    $st = $conn->prepare("SELECT * FROM alumnos WHERE id = ?");
    $st->bind_param("i", $alumnoId); $st->execute();
    $alumno = $st->get_result()->fetch_assoc();
}
if ($alumno) {
    $st = $conn->prepare("SELECT i.id, g.id grupo_id, gr.id grado_id, CONCAT(gr.nombre, g.letra) grupo, c.id ciclo_id, c.nombre ciclo, c.activo
                          FROM inscripciones i JOIN grupos g ON g.id = i.grupo_id JOIN grados gr ON gr.id = g.grado_id
                          JOIN ciclos_escolares c ON c.id = g.ciclo_id WHERE i.alumno_id = ? ORDER BY c.fecha_inicio DESC");
    $st->bind_param("i", $alumnoId); $st->execute();
    $insc = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $sel = (int)($_GET['ciclo'] ?? 0);
    foreach ($insc as $i) if ($sel ? (int)$i['ciclo_id'] === $sel : (bool)$i['activo']) { $actual = $i; break; }
    if (!$actual && $insc) $actual = $insc[0];
}

/* ---------- Búsqueda del administrador: ciclo → grado → grupo → alumno ---------- */
$ciclos = $gradosSel = $gruposSel = $alumnosSel = [];
$cicloSel = $gradoSel = $grupoSel = 0;
if ($esAdmin) {
    $ciclos = $conn->query("SELECT id, nombre, activo FROM ciclos_escolares ORDER BY fecha_inicio DESC")->fetch_all(MYSQLI_ASSOC);
    $cicloSel = (int)($_GET['ciclo'] ?? 0) ?: ciclo_activo();
    $gradoSel = (int)($_GET['grado'] ?? 0);
    $grupoSel = (int)($_GET['grupo'] ?? 0);
    if ($actual) {                       // con un alumno abierto, los filtros muestran su ciclo, grado y grupo
        $cicloSel = (int)$actual['ciclo_id'];
        $gradoSel = (int)$actual['grado_id'];
        $grupoSel = (int)$actual['grupo_id'];
    }
    if (!$cicloSel && $ciclos) $cicloSel = (int)$ciclos[0]['id'];

    $st = $conn->prepare("SELECT DISTINCT gr.id, gr.nombre FROM grupos g JOIN grados gr ON gr.id = g.grado_id
                          WHERE g.ciclo_id = ? ORDER BY gr.nombre");
    $st->bind_param("i", $cicloSel); $st->execute();
    $gradosSel = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    if ($gradoSel) {
        $st = $conn->prepare("SELECT g.id, CONCAT(gr.nombre, g.letra) nombre FROM grupos g JOIN grados gr ON gr.id = g.grado_id
                              WHERE g.ciclo_id = ? AND g.grado_id = ? ORDER BY g.letra");
        $st->bind_param("ii", $cicloSel, $gradoSel); $st->execute();
        $gruposSel = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    if ($grupoSel) {
        $st = $conn->prepare("SELECT a.id, a.matricula, CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombre) n
                              FROM inscripciones i JOIN alumnos a ON a.id = i.alumno_id
                              JOIN grupos g ON g.id = i.grupo_id
                              WHERE g.id = ? AND g.ciclo_id = ? AND g.grado_id = ?
                              ORDER BY LENGTH(a.matricula), a.matricula");
        $st->bind_param("iii", $grupoSel, $cicloSel, $gradoSel); $st->execute();
        $alumnosSel = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

if ($actual) {
    $st = $conn->prepare("SELECT numero, nombre FROM periodos WHERE ciclo_id = ? ORDER BY numero");
    $st->bind_param("i", $actual['ciclo_id']); $st->execute();
    $periodos = $st->get_result()->fetch_all(MYSQLI_ASSOC);

    $st = $conn->prepare("SELECT a.id asig, m.nombre materia, TRIM(CONCAT_WS(' ', p.nombre, p.apellido_paterno)) profesor,
                                 per.numero, c.calificacion
                          FROM asignaciones a JOIN materias m ON m.id = a.materia_id
                          LEFT JOIN profesores p ON p.id = a.profesor_id
                          LEFT JOIN calificaciones c ON c.asignacion_id = a.id AND c.inscripcion_id = ?
                          LEFT JOIN periodos per ON per.id = c.periodo_id
                          WHERE a.grupo_id = ? ORDER BY m.nombre, per.numero");
    $st->bind_param("ii", $actual['id'], $actual['grupo_id']); $st->execute();
    foreach ($st->get_result() as $r) {
        $k = $r['asig'];
        if (!isset($filas[$k])) $filas[$k] = ['materia' => $r['materia'], 'profesor' => $r['profesor'], 'notas' => []];
        if ($r['numero'] !== null) $filas[$k]['notas'][(int)$r['numero']] = (float)$r['calificacion'];
    }
    $proms = [];
    foreach ($filas as &$f) {
        $f['prom'] = $f['notas'] ? round(array_sum($f['notas']) / count($f['notas']), 1) : null;
        if ($f['prom'] !== null) $proms[] = $f['prom'];
    }
    unset($f);
    $promGen = $proms ? round(array_sum($proms) / count($proms), 1) : null;
}

require 'includes/header.php';
?>
<h2>Boleta de calificaciones</h2>
<?php if ($esAdmin): ?>
<form method="get" autocomplete="off" class="buscar noprint">
  <select name="ciclo" data-autosubmit data-limpiar="grado,grupo,alumno">
    <?php foreach ($ciclos as $c): ?><option value="<?= $c['id'] ?>" <?= (int)$c['id'] === $cicloSel ? 'selected' : '' ?>>Ciclo <?= e($c['nombre']) ?><?= $c['activo'] ? ' (activo)' : '' ?></option><?php endforeach; ?>
  </select>
  <select name="grado" data-autosubmit data-limpiar="grupo,alumno"><option value="">-- Grado --</option>
    <?php foreach ($gradosSel as $g): ?><option value="<?= $g['id'] ?>" <?= $gradoSel === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option><?php endforeach; ?>
  </select>
  <select name="grupo" data-autosubmit data-limpiar="alumno" <?= $gradoSel ? '' : 'disabled' ?>><option value="">-- Grupo --</option>
    <?php foreach ($gruposSel as $g): ?><option value="<?= $g['id'] ?>" <?= $grupoSel === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option><?php endforeach; ?>
  </select>
  <select name="alumno" data-autosubmit <?= $grupoSel ? '' : 'disabled' ?>><option value="">-- Alumno --</option>
    <?php foreach ($alumnosSel as $a): ?><option value="<?= $a['id'] ?>" <?= $alumnoId === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['matricula'] . ' - ' . $a['n']) ?></option><?php endforeach; ?>
  </select>
</form>
<?php if (!$alumnoId): ?><p class="muted noprint">Elige el grado, el grupo y el alumno para ver su boleta.</p><?php endif; ?>
<?php endif; ?>

<?php if ($alumno): ?>
<div class="card">
  <h3><?= e(trim($alumno['nombre'] . ' ' . $alumno['apellido_paterno'] . ' ' . $alumno['apellido_materno'])) ?></h3>
  <p><b>Matrícula:</b> <?= e($alumno['matricula']) ?>
    <?php if ($actual): ?> &nbsp;|&nbsp; <b>Grupo:</b> <?= e($actual['grupo']) ?> &nbsp;|&nbsp; <b>Ciclo:</b> <?= e($actual['ciclo']) ?><?php endif; ?></p>
  <?php if (count($insc) > 1): ?>
  <p class="noprint muted">Ver otro ciclo:
    <?php foreach ($insc as $i): ?><a href="?<?= $esAdmin ? 'alumno=' . $alumnoId . '&' : '' ?>ciclo=<?= $i['ciclo_id'] ?>"><?= e($i['ciclo']) ?></a> &nbsp;<?php endforeach; ?></p>
  <?php endif; ?>

  <?php if (!$actual): ?>
    <p class="muted">Este alumno todavía no está inscrito en ningún grupo.</p>
  <?php elseif (!$filas): ?>
    <p class="muted">Su grupo aún no tiene materias asignadas.</p>
  <?php else: ?>
  <div class="tabla-wrap"><table>
    <tr><th>Materia</th><th>Profesor</th><?php foreach ($periodos as $p): ?><th><?= e($p['nombre']) ?></th><?php endforeach; ?><th>Promedio</th></tr>
    <?php foreach ($filas as $f): ?>
    <tr><td><?= e($f['materia']) ?></td><td><?= $f['profesor'] ? e($f['profesor']) : '<span class="muted">Por asignar</span>' ?></td>
      <?php foreach ($periodos as $p): $v = $f['notas'][(int)$p['numero']] ?? null; ?>
        <td class="<?= $v === null ? '' : ($v >= 6 ? 'ok' : 'error') ?>"><?= $v === null ? '-' : e($v) ?></td>
      <?php endforeach; ?>
      <td class="<?= $f['prom'] === null ? '' : ($f['prom'] >= 6 ? 'ok' : 'error') ?>"><b><?= $f['prom'] === null ? '-' : e($f['prom']) ?></b></td></tr>
    <?php endforeach; ?>
    <tr><th colspan="<?= 2 + count($periodos) ?>">Promedio general</th>
      <th class="<?= $promGen !== null && $promGen >= 6 ? 'ok' : 'error' ?>"><?= $promGen === null ? '-' : e($promGen) . ' — ' . ($promGen >= 6 ? 'Aprobado' : 'Reprobado') ?></th></tr>
  </table></div>
  <p class="noprint"><button type="button" class="gris" data-imprimir>🖨 Imprimir boleta</button></p>
  <?php endif; ?>
</div>
<?php elseif ($alumnoId): ?>
  <p class="error">Alumno no encontrado.</p>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>

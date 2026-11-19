<?php
require 'includes/auth.php';
requiere_rol('admin');

$ciclos = $conn->query("SELECT id, nombre, activo FROM ciclos_escolares ORDER BY fecha_inicio DESC")->fetch_all(MYSQLI_ASSOC);
$sel = (int)($_GET['ciclo'] ?? 0) ?: ciclo_activo();
if (!$sel && $ciclos) $sel = (int)$ciclos[0]['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a    = $_POST['accion'] ?? '';
    $cid  = (int)($_POST['ciclo_id'] ?? 0);
    $dest = 'asignaciones.php' . ($cid ? "?ciclo=$cid" : '');
    $prof = ($_POST['profesor_id'] ?? '') !== '' ? (int)$_POST['profesor_id'] : null;

    if ($a === 'borrar') {
        $id = (int)($_POST['id'] ?? 0);
        ejecutar(function () use ($conn, $id) {
            $st = $conn->prepare("DELETE FROM asignaciones WHERE id = ?");
            $st->bind_param("i", $id); $st->execute();
        }, "Asignación eliminada (y sus calificaciones).");
        redirigir($dest);
    }
    if ($a === 'guardar') {
        $gid = (int)($_POST['grupo_id'] ?? 0);
        $mid = (int)($_POST['materia_id'] ?? 0);
        if (!$gid || !$mid) {
            flash("Elige el grupo y la materia.", 'error');
        } else {
            ejecutar(function () use ($conn, $gid, $mid, $prof) {
                $st = $conn->prepare("INSERT INTO asignaciones (grupo_id, materia_id, profesor_id) VALUES (?,?,?)
                                      ON DUPLICATE KEY UPDATE profesor_id = VALUES(profesor_id)");
                $st->bind_param("iii", $gid, $mid, $prof); $st->execute();
            }, "Asignación guardada (si ya existía, se actualizó el profesor).");
        }
        redirigir($dest);
    }
    if ($a === 'masiva') {
        $grado = (int)($_POST['grado_id'] ?? 0);
        $mid   = (int)($_POST['materia_id'] ?? 0);
        if (!$cid || !$grado || !$mid) {
            flash("Elige el grado y la materia.", 'error');
        } else {
            ejecutar(function () use ($conn, $cid, $grado, $mid, $prof) {
                $st = $conn->prepare("INSERT IGNORE INTO asignaciones (grupo_id, materia_id, profesor_id)
                                      SELECT g.id, ?, ? FROM grupos g WHERE g.ciclo_id = ? AND g.grado_id = ?");
                $st->bind_param("iiii", $mid, $prof, $cid, $grado); $st->execute();
                return "Materia asignada a " . $st->affected_rows . " grupo(s) nuevo(s).";
            }, '');
        }
        redirigir($dest);
    }
    redirigir($dest);
}

$ed = ['id' => 0, 'grupo_id' => 0, 'materia_id' => 0, 'profesor_id' => 0];
if (isset($_GET['ed'])) {
    $eid = (int)$_GET['ed'];
    $st = $conn->prepare("SELECT id, grupo_id, materia_id, IFNULL(profesor_id, 0) profesor_id FROM asignaciones WHERE id = ?");
    $st->bind_param("i", $eid); $st->execute();
    $ed = $st->get_result()->fetch_assoc() ?: $ed;
}
$grupos = $materias = $profes = $grados = $lista = [];
if ($sel) {
    $st = $conn->prepare("SELECT g.id, g.grado_id, CONCAT(gr.nombre, g.letra) nombre FROM grupos g
                          JOIN grados gr ON gr.id = g.grado_id WHERE g.ciclo_id = ? ORDER BY gr.nombre, g.letra");
    $st->bind_param("i", $sel); $st->execute();
    $grupos = $st->get_result()->fetch_all(MYSQLI_ASSOC);

    $grupoF = (int)($_GET['grupo'] ?? 0);
    $st = $conn->prepare("SELECT a.id, CONCAT(gr.nombre, g.letra) grupo, m.nombre materia,
                                 TRIM(CONCAT_WS(' ', p.nombre, p.apellido_paterno)) profesor
                          FROM asignaciones a
                          JOIN grupos g ON g.id = a.grupo_id JOIN grados gr ON gr.id = g.grado_id
                          JOIN materias m ON m.id = a.materia_id LEFT JOIN profesores p ON p.id = a.profesor_id
                          WHERE g.ciclo_id = ? AND (? = 0 OR g.id = ?) ORDER BY gr.nombre, g.letra, m.nombre");
    $st->bind_param("iii", $sel, $grupoF, $grupoF); $st->execute();
    $lista = $st->get_result()->fetch_all(MYSQLI_ASSOC);
}
$materias = $conn->query("SELECT id, nombre FROM materias ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
$profes   = $conn->query("SELECT id, TRIM(CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno)) n FROM profesores WHERE activo = 1 ORDER BY apellido_paterno, nombre")->fetch_all(MYSQLI_ASSOC);
$grados   = $conn->query("SELECT id, nombre FROM grados ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

require 'includes/header.php';
?>
<h2>Asignaciones (carga académica)</h2>
<p class="muted">Aquí defines qué materia se imparte a cada grupo y quién la da. Es lo que permite a los profesores capturar calificaciones.</p>

<form method="get" autocomplete="off" class="buscar">
  <select name="ciclo" data-autosubmit>
    <?php foreach ($ciclos as $c): ?>
      <option value="<?= $c['id'] ?>" <?= (int)$c['id'] === $sel ? 'selected' : '' ?>>Ciclo <?= e($c['nombre']) ?><?= $c['activo'] ? ' (activo)' : '' ?></option>
    <?php endforeach; ?>
  </select>
</form>

<?php if (!$ciclos || !$grupos || !$materias): ?>
  <p class="error">Antes de asignar necesitas: un ciclo con grupos (<a href="ciclos.php">Ciclos y grupos</a>) y materias (<a href="materias.php">Materias</a>).</p>
<?php else: ?>
<div class="dos">
  <div class="card">
    <h3><?= $ed['id'] ? 'Editar asignación (cambiar profesor)' : 'Asignar a un grupo' ?></h3>
    <form method="post" autocomplete="off" class="grid">
      <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="ciclo_id" value="<?= $sel ?>">
      <div><label>Grupo *</label><select name="grupo_id" required><option value="">--</option>
        <?php foreach ($grupos as $g): ?><option value="<?= $g['id'] ?>" <?= (int)$g['id'] === (int)$ed['grupo_id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option><?php endforeach; ?></select></div>
      <div><label>Materia *</label><select name="materia_id" required><option value="">--</option>
        <?php foreach ($materias as $m): ?><option value="<?= $m['id'] ?>" <?= (int)$m['id'] === (int)$ed['materia_id'] ? 'selected' : '' ?>><?= e($m['nombre']) ?></option><?php endforeach; ?></select></div>
      <div><label>Profesor</label><select name="profesor_id"><option value="">-- Sin asignar --</option>
        <?php foreach ($profes as $p): ?><option value="<?= $p['id'] ?>" <?= (int)$p['id'] === (int)$ed['profesor_id'] ? 'selected' : '' ?>><?= e($p['n']) ?></option><?php endforeach; ?></select></div>
      <div><button><?= $ed['id'] ? 'Actualizar asignación' : 'Guardar' ?></button>
        <?php if ($ed['id']): ?><a class="btn gris" href="asignaciones.php?ciclo=<?= $sel ?>">Cancelar</a><?php endif; ?></div>
    </form>
  </div>
  <div class="card">
    <h3>Asignar a todos los grupos de un grado</h3>
    <form method="post" autocomplete="off" class="grid">
      <?= csrf_field() ?><input type="hidden" name="accion" value="masiva"><input type="hidden" name="ciclo_id" value="<?= $sel ?>">
      <div><label>Grado *</label><select name="grado_id" required><option value="">--</option>
        <?php foreach ($grados as $g): ?><option value="<?= $g['id'] ?>"><?= e($g['nombre']) ?></option><?php endforeach; ?></select></div>
      <div><label>Materia *</label><select name="materia_id" required><option value="">--</option>
        <?php foreach ($materias as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['nombre']) ?></option><?php endforeach; ?></select></div>
      <div><label>Profesor</label><select name="profesor_id"><option value="">-- Sin asignar --</option>
        <?php foreach ($profes as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['n']) ?></option><?php endforeach; ?></select></div>
      <div><button>Asignar a todos</button></div>
    </form>
    <p class="muted">No modifica los grupos que ya tengan esa materia.</p>
  </div>
</div>

<form method="get" autocomplete="off" class="buscar">
  <input type="hidden" name="ciclo" value="<?= $sel ?>">
  <select name="grupo" data-autosubmit>
    <option value="0">Todos los grupos</option>
    <?php foreach ($grupos as $g): ?><option value="<?= $g['id'] ?>" <?= (int)($_GET['grupo'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option><?php endforeach; ?>
  </select>
</form>
<div class="tabla-wrap"><table>
  <tr><th>Grupo</th><th>Materia</th><th>Profesor</th><th></th></tr>
  <?php if (!$lista): ?><tr><td colspan="4" class="muted">No hay asignaciones todavía.</td></tr><?php endif; ?>
  <?php foreach ($lista as $r): ?>
  <tr><td><b><?= e($r['grupo']) ?></b></td><td><?= e($r['materia']) ?></td>
      <td><?= $r['profesor'] ? e($r['profesor']) : '<span class="error">Sin asignar</span>' ?></td>
      <td><a class="btn sm" href="?ciclo=<?= $sel ?>&ed=<?= $r['id'] ?>">Editar</a> <?= btn_post('borrar', ['id' => $r['id'], 'ciclo_id' => $sel], 'Quitar', 'btn red sm', '¿Quitar esta materia del grupo y sus calificaciones?') ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>

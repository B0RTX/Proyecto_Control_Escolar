<?php
require 'includes/auth.php';
requiere_rol('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a  = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($a === 'borrar') {
        ejecutar(function () use ($conn, $id) {
            $st = $conn->prepare("DELETE FROM materias WHERE id = ?");
            $st->bind_param("i", $id); $st->execute();
        }, "Materia eliminada (y sus asignaciones a grupos).");
        redirigir('materias.php');
    }
    if ($a === 'guardar') {
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre === '') {
            flash("El nombre de la materia es obligatorio.", 'error');
        } else {
            $ok = ejecutar(function () use ($conn, $id, $nombre) {
                if ($id) {
                    $st = $conn->prepare("UPDATE materias SET nombre = ? WHERE id = ?");
                    $st->bind_param("si", $nombre, $id);
                } else {
                    $st = $conn->prepare("INSERT INTO materias (nombre) VALUES (?)");
                    $st->bind_param("s", $nombre);
                }
                $st->execute();
            }, $id ? "Materia actualizada." : "Materia agregada.");
            if ($ok) redirigir('materias.php');
        }
        redirigir('materias.php' . ($id ? "?edit=$id" : ''));
    }
}

$ed = ['id' => 0, 'nombre' => ''];
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $st = $conn->prepare("SELECT * FROM materias WHERE id = ?");
    $st->bind_param("i", $eid); $st->execute();
    $ed = $st->get_result()->fetch_assoc() ?: $ed;
}

$q = trim($_GET['q'] ?? '');
$like = "%$q%";
$st = $conn->prepare("SELECT COUNT(*) c FROM materias WHERE nombre LIKE ?");
$st->bind_param("s", $like); $st->execute();
$total = $st->get_result()->fetch_assoc()['c'];
$por = 15;
[$pag, $paginas, $offset] = paginar($total, $por);
$st = $conn->prepare("SELECT m.*, (SELECT COUNT(*) FROM asignaciones a WHERE a.materia_id = m.id) grupos
                      FROM materias m WHERE m.nombre LIKE ? ORDER BY m.nombre LIMIT ? OFFSET ?");
$st->bind_param("sii", $like, $por, $offset); $st->execute();
$lista = $st->get_result();

require 'includes/header.php';
?>
<h2>Materias</h2>
<div class="card">
  <h3><?= $ed['id'] ? 'Editar materia' : 'Agregar materia' ?></h3>
  <form method="post" autocomplete="off" class="grid">
    <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>">
    <div><label>Nombre de la materia *</label><input name="nombre" value="<?= e($ed['nombre']) ?>" required></div>
    <div><button><?= $ed['id'] ? 'Actualizar' : 'Agregar materia' ?></button>
      <?php if ($ed['id']): ?><a class="btn gris" href="materias.php">Cancelar</a><?php endif; ?></div>
  </form>
  <p class="muted">La materia es solo el catálogo. El profesor y el grupo se asignan en <a href="asignaciones.php">Asignaciones</a>.</p>
</div>

<form method="get" autocomplete="off" class="buscar">
  <input name="q" placeholder="Buscar materia" value="<?= e($q) ?>">
  <button>Buscar</button><a class="btn gris" href="materias.php">Limpiar</a>
</form>
<p class="muted"><?= $total ?> materia(s)</p>
<div class="tabla-wrap"><table>
  <tr><th>Materia</th><th>Grupos donde se imparte</th><th>Acciones</th></tr>
  <?php while ($r = $lista->fetch_assoc()): ?>
  <tr><td><?= e($r['nombre']) ?></td><td><?= (int)$r['grupos'] ?></td>
    <td><a class="btn sm" href="?edit=<?= $r['id'] ?>">Editar</a>
      <?= btn_post('borrar', ['id' => $r['id']], 'Borrar', 'btn red sm', '¿Eliminar esta materia y sus asignaciones?') ?></td></tr>
  <?php endwhile; ?>
</table></div>
<?= render_paginas($pag, $paginas) ?>
<?php require 'includes/footer.php'; ?>

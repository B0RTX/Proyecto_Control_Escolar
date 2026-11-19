<?php
require 'includes/auth.php';
requiere_rol('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a  = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($a === 'borrar') {
        $ok = ejecutar(function () use ($conn, $id) {
            $st = $conn->prepare("DELETE FROM profesores WHERE id = ?");
            $st->bind_param("i", $id); $st->execute();
        }, "Profesor eliminado (sus materias quedaron sin profesor y su cuenta fue borrada).");
        if ($ok) bitacora('PROFESOR_ELIMINADO', 'profesores', $id);
        redirigir('profesores.php');
    }

    // Solo para profesores que, por algún motivo, no tengan cuenta
    if ($a === 'crear_cuenta') {
        $st = $conn->prepare("SELECT email FROM profesores WHERE id = ?");
        $st->bind_param("i", $id); $st->execute();
        $email = $st->get_result()->fetch_assoc()['email'] ?? '';
        $usuario = $email !== '' ? strstr($email, '@', true) : '';
        if (!$usuario) {
            flash("El profesor no tiene un correo institucional válido.", 'error');
        } else {
            ejecutar(function () use ($usuario, $id) {
                crear_cuenta('profesor', $usuario, null, $id);
                return "Cuenta creada · Usuario: $usuario · Contraseña temporal: la misma matrícula (deberá cambiarla al entrar).";
            }, '');
        }
        redirigir('profesores.php');
    }

    if ($a === 'guardar') {
        $nombre = trim($_POST['nombre'] ?? '');
        $ap     = trim($_POST['apellido_paterno'] ?? '');
        $am     = trim($_POST['apellido_materno'] ?? '') ?: null;
        $tel    = trim($_POST['telefono'] ?? '') ?: null;

        if ($nombre === '' || $ap === '') {
            flash("El nombre y el apellido paterno son obligatorios.", 'error');
        } else {
            $ok = ejecutar(function () use ($conn, $id, $nombre, $ap, $am, $tel) {
                if ($id) {
                    $st = $conn->prepare("UPDATE profesores SET nombre=?, apellido_paterno=?, apellido_materno=?, telefono=? WHERE id=?");
                    $st->bind_param("ssssi", $nombre, $ap, $am, $tel, $id);
                    $st->execute();
                    return "Profesor actualizado.";
                }
                $conn->begin_transaction();
                try {
                    // El sistema asigna la matrícula y el correo institucional, y crea la cuenta
                    $clave = siguiente_clave('profesor');
                    $email = $clave . '@' . DOMINIO_PROFESOR;
                    $st = $conn->prepare("INSERT INTO profesores (nombre, apellido_paterno, apellido_materno, email, telefono) VALUES (?,?,?,?,?)");
                    $st->bind_param("sssss", $nombre, $ap, $am, $email, $tel);
                    $st->execute();
                    $pid = $conn->insert_id;
                    crear_cuenta('profesor', $clave, null, $pid);
                    $conn->commit();
                    return "Profesor agregado · Matrícula y usuario: $clave · Correo: $email · Contraseña temporal: la misma matrícula (deberá cambiarla al entrar).";
                } catch (Throwable $t) { $conn->rollback(); throw $t; }
            }, '');
            if ($ok) redirigir('profesores.php');
        }
        redirigir('profesores.php' . ($id ? "?edit=$id" : ''));
    }
}

$ed = ['id' => 0, 'nombre' => '', 'apellido_paterno' => '', 'apellido_materno' => '', 'email' => '', 'telefono' => ''];
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $st = $conn->prepare("SELECT * FROM profesores WHERE id = ?");
    $st->bind_param("i", $eid); $st->execute();
    $ed = $st->get_result()->fetch_assoc() ?: $ed;
}

$q = trim($_GET['q'] ?? '');
$like = "%$q%";
$where = "(CONCAT_WS(' ', p.nombre, p.apellido_paterno, p.apellido_materno) LIKE ? OR p.email LIKE ? OR p.telefono LIKE ?)";
$st = $conn->prepare("SELECT COUNT(*) c FROM profesores p WHERE $where");
$st->bind_param("sss", $like, $like, $like); $st->execute();
$total = $st->get_result()->fetch_assoc()['c'];
$por = 10;
[$pag, $paginas, $offset] = paginar($total, $por);

$st = $conn->prepare("SELECT p.*,
        (SELECT COUNT(*) FROM asignaciones a WHERE a.profesor_id = p.id) asig,
        (SELECT COUNT(*) FROM usuarios u WHERE u.profesor_id = p.id) cuenta
      FROM profesores p WHERE $where ORDER BY (p.email IS NULL), CAST(SUBSTRING_INDEX(p.email, '@', 1) AS UNSIGNED), p.id LIMIT ? OFFSET ?");
$st->bind_param("sssii", $like, $like, $like, $por, $offset); $st->execute();
$lista = $st->get_result();

require 'includes/header.php';
?>
<h2>Profesores</h2>
<div class="card">
  <h3><?= $ed['id'] ? 'Editar profesor' : 'Agregar profesor' ?></h3>
  <form method="post" autocomplete="off" class="grid">
    <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>">
    <?php if ($ed['id']): ?>
      <div><label>Matrícula (automática)</label><input value="<?= e(strstr((string)$ed['email'], '@', true) ?: '') ?>" disabled></div>
      <div><label>Correo institucional</label><input value="<?= e($ed['email']) ?>" disabled></div>
    <?php endif; ?>
    <div><label>Nombre(s) *</label><input name="nombre" value="<?= e($ed['nombre']) ?>" required></div>
    <div><label>Apellido paterno *</label><input name="apellido_paterno" value="<?= e($ed['apellido_paterno']) ?>" required></div>
    <div><label>Apellido materno</label><input name="apellido_materno" value="<?= e($ed['apellido_materno']) ?>"></div>
    <div><label>Teléfono</label><input name="telefono" value="<?= e($ed['telefono']) ?>"></div>
    <div><button><?= $ed['id'] ? 'Actualizar' : 'Agregar profesor' ?></button>
      <?php if ($ed['id']): ?><a class="btn gris" href="profesores.php">Cancelar</a><?php endif; ?></div>
  </form>
  <?php if (!$ed['id']): ?>
    <p class="muted">Al guardar, el sistema asigna la <b>matrícula</b>, crea el <b>correo</b> (matrícula@<?= e(DOMINIO_PROFESOR) ?>) y la <b>cuenta de acceso</b>. La contraseña temporal es la misma matrícula y el profesor deberá cambiarla al entrar.</p>
  <?php endif; ?>
</div>

<form method="get" autocomplete="off" class="buscar">
  <input name="q" placeholder="Buscar por nombre, correo o teléfono" value="<?= e($q) ?>">
  <button>Buscar</button><a class="btn gris" href="profesores.php">Limpiar</a>
</form>
<p class="muted"><?= $total ?> profesor(es)</p>
<div class="tabla-wrap"><table>
  <tr><th>Matrícula</th><th>Nombre</th><th>Correo institucional</th><th>Teléfono</th><th>Materias</th><th>Cuenta</th><th>Acciones</th></tr>
  <?php while ($r = $lista->fetch_assoc()): ?>
  <tr>
    <td><?= e(strstr((string)$r['email'], '@', true) ?: '-') ?></td>
    <td><?= e(trim($r['nombre'] . ' ' . $r['apellido_paterno'] . ' ' . $r['apellido_materno'])) ?></td>
    <td><?= e($r['email']) ?></td><td><?= e($r['telefono']) ?></td><td><?= (int)$r['asig'] ?></td>
    <td><?= $r['cuenta'] ? '<span class="ok">Sí</span>' : '<span class="muted">No</span>' ?></td>
    <td>
      <a class="btn sm" href="?edit=<?= $r['id'] ?>">Editar</a>
      <?php if (!$r['cuenta'] && $r['email']) echo btn_post('crear_cuenta', ['id' => $r['id']], 'Crear cuenta'); ?>
      <?= btn_post('borrar', ['id' => $r['id']], 'Borrar', 'btn red sm', '¿Eliminar este profesor y su cuenta?') ?>
    </td>
  </tr>
  <?php endwhile; ?>
</table></div>
<?= render_paginas($pag, $paginas) ?>
<?php require 'includes/footer.php'; ?>

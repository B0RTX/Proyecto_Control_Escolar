<?php
require 'includes/auth.php';
requiere_rol('admin');
$ciclo = ciclo_activo();

/* ---------- Acciones POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a  = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($a === 'borrar') {
        $ok = ejecutar(function () use ($conn, $id) {
            $st = $conn->prepare("DELETE FROM alumnos WHERE id = ?");
            $st->bind_param("i", $id); $st->execute();
        }, "Alumno eliminado (con su inscripción, calificaciones y cuenta).");
        if ($ok) bitacora('ALUMNO_ELIMINADO', 'alumnos', $id);
        redirigir('alumnos.php');
    }

    // Solo para alumnos que, por algún motivo, no tengan cuenta
    if ($a === 'crear_cuenta') {
        $st = $conn->prepare("SELECT matricula FROM alumnos WHERE id = ?");
        $st->bind_param("i", $id); $st->execute();
        $mat = $st->get_result()->fetch_assoc()['matricula'] ?? '';
        if ($mat !== '') {
            ejecutar(function () use ($mat, $id) {
                crear_cuenta('alumno', $mat, $id, null);
                return "Cuenta creada · Usuario: $mat · Contraseña temporal: la misma matrícula (deberá cambiarla al entrar).";
            }, '');
        }
        redirigir('alumnos.php');
    }

    if ($a === 'guardar') {
        $nom   = trim($_POST['nombre'] ?? '');
        $ap    = trim($_POST['apellido_paterno'] ?? '');
        $am    = trim($_POST['apellido_materno'] ?? '');
        $fecha = ($_POST['fecha_nac'] ?? '') ?: null;
        $grupo = (int)($_POST['grupo_id'] ?? 0);

        if ($nom === '' || $ap === '' || $am === '') {
            flash("El nombre y los apellidos paterno y materno son obligatorios.", 'error');
        } else {
            $ok = ejecutar(function () use ($conn, $id, $nom, $ap, $am, $fecha, $grupo) {
                $conn->begin_transaction();
                try {
                    if ($id) {
                        $st = $conn->prepare("UPDATE alumnos SET nombre=?, apellido_paterno=?, apellido_materno=?, fecha_nac=? WHERE id=?");
                        $st->bind_param("ssssi", $nom, $ap, $am, $fecha, $id);
                        $st->execute();
                        $aid = $id;
                        $msg = "Alumno actualizado.";
                    } else {
                        // El sistema asigna matrícula y correo institucional, y crea la cuenta
                        $mat   = siguiente_clave('alumno');
                        $email = $mat . '@' . DOMINIO_ALUMNO;
                        $st = $conn->prepare("INSERT INTO alumnos (matricula, nombre, apellido_paterno, apellido_materno, email, fecha_nac) VALUES (?,?,?,?,?,?)");
                        $st->bind_param("ssssss", $mat, $nom, $ap, $am, $email, $fecha);
                        $st->execute();
                        $aid = $conn->insert_id;
                        crear_cuenta('alumno', $mat, $aid, null);
                        $msg = "Alumno agregado · Matrícula y usuario: $mat · Correo: $email · Contraseña temporal: la misma matrícula (deberá cambiarla al entrar).";
                    }
                    // Inscripción: una por ciclo (si ya tenía grupo en ese ciclo, se cambia)
                    if ($grupo) {
                        $st = $conn->prepare("SELECT i.id FROM inscripciones i JOIN grupos g ON g.id = i.grupo_id
                                              WHERE i.alumno_id = ? AND g.ciclo_id = (SELECT ciclo_id FROM grupos WHERE id = ?)");
                        $st->bind_param("ii", $aid, $grupo); $st->execute();
                        $ins = $st->get_result()->fetch_assoc();
                        if ($ins) {
                            $st = $conn->prepare("UPDATE inscripciones SET grupo_id = ? WHERE id = ?");
                            $st->bind_param("ii", $grupo, $ins['id']);
                        } else {
                            $st = $conn->prepare("INSERT INTO inscripciones (alumno_id, grupo_id) VALUES (?,?)");
                            $st->bind_param("ii", $aid, $grupo);
                        }
                        $st->execute();
                    }
                    $conn->commit();
                    return $msg;
                } catch (Throwable $t) { $conn->rollback(); throw $t; }
            }, '');
            if ($ok) redirigir('alumnos.php');
        }
        redirigir('alumnos.php' . ($id ? "?edit=$id" : ''));
    }
}

/* ---------- Registro a editar ---------- */
$ed = ['id' => 0, 'matricula' => '', 'nombre' => '', 'apellido_paterno' => '', 'apellido_materno' => '', 'email' => '', 'fecha_nac' => '', 'grupo_id' => 0];
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $st = $conn->prepare("SELECT a.*, (SELECT i.grupo_id FROM inscripciones i JOIN grupos g ON g.id = i.grupo_id
                                       WHERE i.alumno_id = a.id AND g.ciclo_id = ? LIMIT 1) grupo_id
                          FROM alumnos a WHERE a.id = ?");
    $st->bind_param("ii", $ciclo, $eid); $st->execute();
    $ed = $st->get_result()->fetch_assoc() ?: $ed;
}

$st = $conn->prepare("SELECT g.id, CONCAT(gr.nombre, g.letra) nombre FROM grupos g
                      JOIN grados gr ON gr.id = g.grado_id WHERE g.ciclo_id = ? ORDER BY gr.nombre, g.letra");
$st->bind_param("i", $ciclo); $st->execute();
$grupos = $st->get_result()->fetch_all(MYSQLI_ASSOC);

/* ---------- Listado ---------- */
$q = trim($_GET['q'] ?? '');
$grupoF = (int)($_GET['grupo'] ?? 0);
$like = "%$q%";
$base = "FROM alumnos a
         LEFT JOIN inscripciones i ON i.alumno_id = a.id AND i.grupo_id IN (SELECT id FROM grupos WHERE ciclo_id = ?)
         LEFT JOIN grupos g ON g.id = i.grupo_id
         LEFT JOIN grados gr ON gr.id = g.grado_id
         WHERE (a.matricula LIKE ? OR CONCAT_WS(' ', a.nombre, a.apellido_paterno, a.apellido_materno) LIKE ? OR a.email LIKE ?)
           AND (? = 0 OR i.grupo_id = ?)";
$st = $conn->prepare("SELECT COUNT(*) c $base");
$st->bind_param("isssii", $ciclo, $like, $like, $like, $grupoF, $grupoF); $st->execute();
$total = $st->get_result()->fetch_assoc()['c'];
$por = 10;
[$pag, $paginas, $offset] = paginar($total, $por);
$st = $conn->prepare("SELECT a.*, CONCAT(gr.nombre, g.letra) grupo,
                             (SELECT COUNT(*) FROM usuarios u WHERE u.alumno_id = a.id) cuenta
                      $base ORDER BY (g.id IS NULL), gr.nombre, g.letra, LENGTH(a.matricula), a.matricula LIMIT ? OFFSET ?");
$st->bind_param("isssiiii", $ciclo, $like, $like, $like, $grupoF, $grupoF, $por, $offset); $st->execute();
$lista = $st->get_result();

require 'includes/header.php';
?>
<h2>Alumnos</h2>
<?php if (!$ciclo): ?>
  <p class="error">No hay ciclo activo, así que no se puede inscribir a nadie en un grupo. Configúralo en <a href="ciclos.php">Ciclos y grupos</a>.</p>
<?php endif; ?>

<div class="card">
  <h3><?= $ed['id'] ? 'Editar alumno' : 'Agregar alumno' ?></h3>
  <form method="post" autocomplete="off" class="grid">
    <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>">
    <?php if ($ed['id']): ?>
      <div><label>Matrícula (automática)</label><input value="<?= e($ed['matricula']) ?>" disabled></div>
      <div><label>Correo institucional</label><input value="<?= e($ed['email']) ?>" disabled></div>
    <?php endif; ?>
    <div><label>Nombre(s) *</label><input name="nombre" value="<?= e($ed['nombre']) ?>" required></div>
    <div><label>Apellido paterno *</label><input name="apellido_paterno" value="<?= e($ed['apellido_paterno']) ?>" required></div>
    <div><label>Apellido materno *</label><input name="apellido_materno" value="<?= e($ed['apellido_materno']) ?>" required></div>
    <div><label>Fecha de nacimiento</label><input type="date" name="fecha_nac" value="<?= e($ed['fecha_nac']) ?>"></div>
    <div><label>Grupo (ciclo activo)</label>
      <select name="grupo_id"><option value="0"><?= $ed['id'] ? '-- Sin cambio --' : '-- Sin grupo --' ?></option>
        <?php foreach ($grupos as $g): ?>
          <option value="<?= $g['id'] ?>" <?= (int)$g['id'] === (int)$ed['grupo_id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option>
        <?php endforeach; ?></select></div>
    <div><button><?= $ed['id'] ? 'Actualizar' : 'Agregar alumno' ?></button>
      <?php if ($ed['id']): ?><a class="btn gris" href="alumnos.php">Cancelar</a><?php endif; ?></div>
  </form>
  <?php if (!$ed['id']): ?>
    <p class="muted">Al guardar, el sistema asigna la <b>matrícula</b>, crea el <b>correo</b> (matrícula@<?= e(DOMINIO_ALUMNO) ?>) y la <b>cuenta de acceso</b>. La contraseña temporal es la misma matrícula y el alumno deberá cambiarla al entrar.</p>
  <?php endif; ?>
</div>

<form method="get" autocomplete="off" class="buscar">
  <input name="q" placeholder="Buscar por matrícula, nombre o correo" value="<?= e($q) ?>">
  <select name="grupo"><option value="0">Todos los grupos</option>
    <?php foreach ($grupos as $g): ?><option value="<?= $g['id'] ?>" <?= $grupoF === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option><?php endforeach; ?></select>
  <button>Buscar</button><a class="btn gris" href="alumnos.php">Limpiar</a>
</form>
<p class="muted"><?= $total ?> alumno(s)</p>
<div class="tabla-wrap"><table>
  <tr><th>Matrícula</th><th>Nombre</th><th>Correo</th><th>Grupo</th><th>Cuenta</th><th>Acciones</th></tr>
  <?php while ($r = $lista->fetch_assoc()):
      $nombre = trim($r['apellido_paterno'] . ' ' . $r['apellido_materno'] . ' ' . $r['nombre']); ?>
  <tr>
    <td><?= e($r['matricula']) ?></td>
    <td><a href="boleta.php?alumno=<?= (int)$r['id'] ?>"><?= e($nombre) ?></a></td>
    <td><?= e($r['email']) ?></td>
    <td><?= $r['grupo'] ? e($r['grupo']) : '<span class="muted">-</span>' ?></td>
    <td><?= $r['cuenta'] ? '<span class="ok">Sí</span>' : '<span class="muted">No</span>' ?></td>
    <td>
      <a class="btn sm" href="?edit=<?= $r['id'] ?>">Editar</a>
      <?php if (!$r['cuenta']) echo btn_post('crear_cuenta', ['id' => $r['id']], 'Crear cuenta'); ?>
      <?= btn_post('borrar', ['id' => $r['id']], 'Borrar', 'btn red sm', '¿Eliminar este alumno con sus calificaciones y cuenta?') ?>
    </td>
  </tr>
  <?php endwhile; ?>
</table></div>
<?= render_paginas($pag, $paginas) ?>
<?php require 'includes/footer.php'; ?>

<?php
require 'includes/auth.php';
requiere_rol('admin');

function revocar_sesiones(int $uid): void {
    global $conn;
    $st = $conn->prepare("UPDATE sesiones SET revocada = 1 WHERE usuario_id = ?");
    $st->bind_param("i", $uid); $st->execute();
}
function datos_usuario(int $id): ?array {
    global $conn;
    $st = $conn->prepare("SELECT id, usuario, rol FROM usuarios WHERE id = ?");
    $st->bind_param("i", $id); $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a  = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $yo = (int)$USR['id'];
    $u  = $id ? datos_usuario($id) : null;

    /* ----- CREAR administrador / ACTUALIZAR nombre ----- */
    if ($a === 'guardar') {
        $nombre = trim($_POST['nombre_admin'] ?? '');
        if ($nombre === '') {
            flash("El nombre del administrador es obligatorio.", 'error');
        } elseif ($id && (!$u || $u['rol'] !== 'admin')) {
            flash("Solo se puede editar el nombre de cuentas de administrador.", 'error');
        } else {
            ejecutar(function () use ($conn, $id, $nombre, $yo) {
                if ($id) {
                    $st = $conn->prepare("UPDATE usuarios SET nombre_admin = ? WHERE id = ? AND rol = 'admin'");
                    $st->bind_param("si", $nombre, $id); $st->execute();
                    if ($id === $yo) $_SESSION['nombre'] = $nombre;
                    bitacora('USUARIO_EDITADO', 'usuarios', $id);
                    return "Administrador actualizado.";
                }
                $conn->begin_transaction();
                try {
                    $mat = siguiente_clave('admin');
                    crear_cuenta('admin', $mat, null, null, $nombre);
                    $conn->commit();
                    return "Administrador creado · Matrícula y usuario: $mat · Contraseña temporal: la misma matrícula (deberá cambiarla al entrar).";
                } catch (Throwable $t) { $conn->rollback(); throw $t; }
            }, '');
        }
        redirigir('usuarios.php');
    }

    if (!$u) { flash("Usuario no encontrado.", 'error'); redirigir('usuarios.php'); }

    /* ----- ACTIVAR / DESACTIVAR ----- */
    if ($a === 'estado') {
        $v = (int)($_POST['valor'] ?? 0) ? 1 : 0;
        if ($id === $yo) {
            flash("No puedes desactivar tu propia cuenta.", 'error');
        } else {
            $st = $conn->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
            $st->bind_param("ii", $v, $id); $st->execute();
            if (!$v) revocar_sesiones($id);
            bitacora($v ? 'USUARIO_ACTIVADO' : 'USUARIO_DESACTIVADO', 'usuarios', $id);
            flash($v ? "Cuenta activada." : "Cuenta desactivada y sesiones cerradas.");
        }
    }
    /* ----- RESTABLECER CONTRASEÑA ----- */
    elseif ($a === 'reset') {
        if ($id === $yo) {
            flash("Para tu propia cuenta usa «Cambiar contraseña» en el menú.", 'error');
        } else {
            $hash = hash_password($u['usuario']);      // contraseña temporal = la matrícula
            $st = $conn->prepare("UPDATE usuarios SET password = ?, debe_cambiar_password = 1, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?");
            $st->bind_param("si", $hash, $id); $st->execute();
            revocar_sesiones($id);
            bitacora('PASSWORD_RESTABLECIDA', 'usuarios', $id);
            flash("Contraseña restablecida · Usuario: {$u['usuario']} · Contraseña temporal: la misma matrícula (deberá cambiarla al entrar).");
        }
    }
    elseif ($a === 'desbloquear') {
        $st = $conn->prepare("UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?");
        $st->bind_param("i", $id); $st->execute();
        bitacora('USUARIO_DESBLOQUEADO', 'usuarios', $id);
        flash("Cuenta desbloqueada.");
    }
    elseif ($a === 'cerrar_sesiones') {
        revocar_sesiones($id);
        bitacora('SESIONES_CERRADAS', 'usuarios', $id);
        flash("Se cerraron todas las sesiones de ese usuario.");
    }
    /* ----- BORRAR ----- */
    elseif ($a === 'borrar') {
        $st = $conn->prepare("SELECT COUNT(*) c FROM usuarios WHERE rol = 'admin' AND activo = 1 AND id <> ?");
        $st->bind_param("i", $id); $st->execute();
        $otros = (int)$st->get_result()->fetch_assoc()['c'];
        if ($id === $yo) {
            flash("No puedes eliminar tu propia cuenta.", 'error');
        } elseif ($u['rol'] === 'admin' && $otros === 0) {
            flash("No puedes eliminar al último administrador activo.", 'error');
        } else {
            $st = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
            $st->bind_param("i", $id); $st->execute();
            bitacora('USUARIO_ELIMINADO', 'usuarios', $id, "usuario={$u['usuario']}");
            flash("Cuenta eliminada (la persona sigue registrada, solo se quitó su acceso).");
        }
    }
    redirigir('usuarios.php');
}

/* ---------- Editar ---------- */
$ed = ['id' => 0, 'usuario' => '', 'nombre_admin' => '', 'rol' => 'admin'];
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $st = $conn->prepare("SELECT id, usuario, nombre_admin, rol FROM usuarios WHERE id = ?");
    $st->bind_param("i", $eid); $st->execute();
    $ed = $st->get_result()->fetch_assoc() ?: $ed;
}

/* ---------- Listado ---------- */
$q = trim($_GET['q'] ?? '');
$rolF = $_GET['rol'] ?? '';
if (!in_array($rolF, ['admin', 'profesor', 'alumno'], true)) $rolF = '';
$like = "%$q%";
$base = "FROM usuarios u LEFT JOIN alumnos al ON al.id = u.alumno_id LEFT JOIN profesores pr ON pr.id = u.profesor_id
         WHERE (u.usuario LIKE ? OR u.nombre_admin LIKE ? OR CONCAT_WS(' ', al.nombre, al.apellido_paterno) LIKE ? OR CONCAT_WS(' ', pr.nombre, pr.apellido_paterno) LIKE ?)
           AND (? = '' OR u.rol = ?)";
$st = $conn->prepare("SELECT COUNT(*) c $base");
$st->bind_param("ssssss", $like, $like, $like, $like, $rolF, $rolF); $st->execute();
$total = $st->get_result()->fetch_assoc()['c'];
$por = 15;
[$pag, $paginas, $offset] = paginar($total, $por);
$st = $conn->prepare("SELECT u.id, u.usuario, u.rol, u.activo, u.ultimo_acceso, u.debe_cambiar_password,
        CASE u.rol WHEN 'alumno' THEN CONCAT_WS(' ', al.nombre, al.apellido_paterno)
                   WHEN 'profesor' THEN CONCAT_WS(' ', pr.nombre, pr.apellido_paterno) ELSE u.nombre_admin END persona,
        (u.bloqueado_hasta IS NOT NULL AND u.bloqueado_hasta > NOW()) bloqueada,
        (SELECT COUNT(*) FROM sesiones s WHERE s.usuario_id = u.id AND s.revocada = 0 AND s.expira_en > NOW()) ses
        $base ORDER BY u.rol, u.usuario LIMIT ? OFFSET ?");
$st->bind_param("ssssssii", $like, $like, $like, $like, $rolF, $rolF, $por, $offset); $st->execute();
$lista = $st->get_result();

require 'includes/header.php';
?>
<h2>Usuarios del sistema</h2>
<p class="muted">Las cuentas de alumnos y profesores se crean solas al registrarlos (su usuario es su matrícula, asignada automáticamente). Aquí administras todas las cuentas y creas administradores.</p>

<div class="card">
  <h3><?= $ed['id'] ? 'Editar cuenta' : 'Nuevo administrador' ?></h3>
  <form method="post" class="grid" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>">
    <?php if ($ed['id']): ?><div><label>Matrícula / usuario</label><input value="<?= e($ed['usuario']) ?>" disabled></div><?php endif; ?>
    <?php if (!$ed['id'] || $ed['rol'] === 'admin'): ?>
    <div><label>Nombre del administrador *</label><input name="nombre_admin" value="<?= e($ed['nombre_admin']) ?>" required></div>
    <?php endif; ?>
    <div><button><?= $ed['id'] ? 'Actualizar' : 'Crear administrador' ?></button>
      <?php if ($ed['id']): ?><a class="btn gris" href="usuarios.php">Cancelar</a><?php endif; ?></div>
  </form>
  <?php if (!$ed['id']): ?><p class="muted">La matrícula se asigna sola (desde la 1000) y la contraseña temporal es esa misma matrícula; deberá cambiarla al entrar.</p><?php endif; ?>
</div>

<form method="get" autocomplete="off" class="buscar">
  <input name="q" placeholder="Buscar por usuario o nombre" value="<?= e($q) ?>">
  <select name="rol"><option value="">Todos los roles</option>
    <?php foreach (['admin' => 'Administradores', 'profesor' => 'Profesores', 'alumno' => 'Alumnos'] as $k => $t): ?>
      <option value="<?= $k ?>" <?= $rolF === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select>
  <button>Buscar</button><a class="btn gris" href="usuarios.php">Limpiar</a>
</form>
<p class="muted"><?= $total ?> cuenta(s)</p>
<div class="tabla-wrap"><table>
  <tr><th>Usuario</th><th>Persona</th><th>Rol</th><th>Estado</th><th>Último acceso</th><th>Sesiones</th><th>Acciones</th></tr>
  <?php while ($r = $lista->fetch_assoc()): $esYo = (int)$r['id'] === (int)$USR['id']; ?>
  <tr>
    <td><?= e($r['usuario']) ?><?= $esYo ? ' <span class="muted">(tú)</span>' : '' ?></td>
    <td><?= e($r['persona']) ?></td><td><?= e(rol_texto($r['rol'])) ?></td>
    <td>
      <?= $r['activo'] ? '<span class="ok">Activa</span>' : '<span class="error">Inactiva</span>' ?>
      <?= $r['bloqueada'] ? ' · <span class="error">Bloqueada</span>' : '' ?>
      <?= $r['debe_cambiar_password'] ? ' · <span class="muted">Contraseña temporal</span>' : '' ?>
    </td>
    <td><?= e($r['ultimo_acceso'] ?? 'Nunca') ?></td><td><?= (int)$r['ses'] ?></td>
    <td>
      <?php if ($r['rol'] === 'admin'): ?><a class="btn sm" href="?edit=<?= $r['id'] ?>">Editar</a><?php endif; ?>
      <?php if (!$esYo): ?>
        <?= btn_post('reset', ['id' => $r['id']], 'Restablecer contraseña', 'btn sm', '¿Generar una nueva contraseña temporal para esta cuenta?') ?>
        <?= btn_post('estado', ['id' => $r['id'], 'valor' => $r['activo'] ? 0 : 1], $r['activo'] ? 'Desactivar' : 'Activar', 'btn gris sm') ?>
      <?php endif; ?>
      <?php if ($r['bloqueada']) echo btn_post('desbloquear', ['id' => $r['id']], 'Desbloquear'); ?>
      <?php if ($r['ses']) echo btn_post('cerrar_sesiones', ['id' => $r['id']], 'Cerrar sesiones', 'btn gris sm'); ?>
      <?php if (!$esYo) echo btn_post('borrar', ['id' => $r['id']], 'Borrar', 'btn red sm', '¿Eliminar esta cuenta de acceso?'); ?>
    </td>
  </tr>
  <?php endwhile; ?>
</table></div>
<?= render_paginas($pag, $paginas) ?>
<?php require 'includes/footer.php'; ?>

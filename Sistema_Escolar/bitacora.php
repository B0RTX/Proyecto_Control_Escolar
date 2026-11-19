<?php
require 'includes/auth.php';
requiere_rol('admin');                     // solo lectura: la bitácora no se puede editar ni borrar

$q = trim($_GET['q'] ?? '');
$fechaOk = fn($f) => is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) ? $f : '';
$desde = $fechaOk($_GET['desde'] ?? '');
$hasta = $fechaOk($_GET['hasta'] ?? '');
$like = "%$q%";
$base = "FROM bitacora b LEFT JOIN usuarios u ON u.id = b.usuario_id
         WHERE (b.accion LIKE ? OR b.detalle LIKE ? OR u.usuario LIKE ?)
           AND (? = '' OR b.created_at >= ?)
           AND (? = '' OR b.created_at < DATE_ADD(?, INTERVAL 1 DAY))";
$st = $conn->prepare("SELECT COUNT(*) c $base");
$st->bind_param("sssssss", $like, $like, $like, $desde, $desde, $hasta, $hasta); $st->execute();
$total = $st->get_result()->fetch_assoc()['c'];
$por = 25;
[$pag, $paginas, $offset] = paginar($total, $por);
$st = $conn->prepare("SELECT b.*, u.usuario $base ORDER BY b.id DESC LIMIT ? OFFSET ?");
$st->bind_param("sssssssii", $like, $like, $like, $desde, $desde, $hasta, $hasta, $por, $offset); $st->execute();
$lista = $st->get_result();

require 'includes/header.php';
?>
<h2>Bitácora de actividad</h2>
<p class="muted">Registro de accesos y cambios importantes. Es de solo lectura: ni siquiera un administrador puede modificarla o borrarla.</p>
<form method="get" autocomplete="off" class="buscar">
  <input name="q" placeholder="Buscar por acción, detalle o usuario" value="<?= e($q) ?>">
  <input type="date" name="desde" value="<?= e($desde) ?>" style="width:auto"> <input type="date" name="hasta" value="<?= e($hasta) ?>" style="width:auto">
  <button>Filtrar</button><a class="btn gris" href="bitacora.php">Limpiar</a>
</form>
<p class="muted"><?= $total ?> registro(s)</p>
<div class="tabla-wrap"><table>
  <tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Tabla</th><th>Reg.</th><th>Detalle</th><th>IP</th></tr>
  <?php while ($r = $lista->fetch_assoc()): ?>
  <tr><td><?= e($r['created_at']) ?></td><td><?= e($r['usuario'] ?? '—') ?></td><td><b><?= e($r['accion']) ?></b></td>
      <td><?= e($r['tabla']) ?></td><td><?= e($r['registro_id']) ?></td><td><?= e($r['detalle']) ?></td><td><?= e($r['ip']) ?></td></tr>
  <?php endwhile; ?>
</table></div>
<?= render_paginas($pag, $paginas) ?>
<?php require 'includes/footer.php'; ?>

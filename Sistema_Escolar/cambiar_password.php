<?php
define('PERMITE_SIN_CAMBIO', true);   // esta página debe poder abrirse aunque la contraseña sea temporal
require 'includes/auth.php';
$forzado = (bool)$info['debe_cambiar_password'];   // primer ingreso con la contraseña temporal

// El cambio obligatorio vive en cambiar_password.php?primer=1. Si ya se cambió y la persona regresa
// con el botón «Atrás», se le manda al inicio en lugar de mostrarle otra vez ese formulario.
if (!$forzado && isset($_GET['primer']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    redirigir('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $actual = $_POST['actual'] ?? '';
    $nueva  = $_POST['nueva'] ?? '';
    $conf   = $_POST['confirmar'] ?? '';

    $st = $conn->prepare("SELECT password, usuario FROM usuarios WHERE id = ?");
    $st->bind_param("i", $USR['id']);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $hashActual = $fila['password'];

    // En el primer ingreso no se pide la actual: la persona acaba de entrar con ella
    if (!$forzado && !password_verify($actual, $hashActual)) {
        flash("La contraseña actual no es correcta.", 'error');
    } elseif ($nueva !== $conf) {
        flash("La confirmación no coincide con la nueva contraseña.", 'error');
    } elseif (strlen($nueva) < 10 || !preg_match('/[A-Za-z]/', $nueva) || !preg_match('/\d/', $nueva)) {
        flash("La nueva contraseña debe tener al menos 10 caracteres, con letras y números.", 'error');
    } elseif (strcasecmp($nueva, $fila['usuario']) === 0) {
        flash("La contraseña no puede ser igual a tu matrícula.", 'error');
    } else {
        // No reutilizar la actual ni las últimas 5
        $repetida = password_verify($nueva, $hashActual);
        if (!$repetida) {
            $st = $conn->prepare("SELECT password_hash FROM password_historial WHERE usuario_id = ? ORDER BY id DESC LIMIT 5");
            $st->bind_param("i", $USR['id']);
            $st->execute();
            foreach ($st->get_result() as $h) {
                if (password_verify($nueva, $h['password_hash'])) { $repetida = true; break; }
            }
        }
        if ($repetida) {
            flash("No puedes reutilizar una contraseña reciente.", 'error');
        } else {
            $nuevoHash = hash_password($nueva);
            try {
                $conn->begin_transaction();
                $st = $conn->prepare("UPDATE usuarios SET password = ?, debe_cambiar_password = 0 WHERE id = ?");
                $st->bind_param("si", $nuevoHash, $USR['id']);
                $st->execute();
                $st = $conn->prepare("INSERT INTO password_historial (usuario_id, password_hash) VALUES (?, ?)");
                $st->bind_param("is", $USR['id'], $nuevoHash);
                $st->execute();
                // Cierra las demás sesiones abiertas de este usuario
                $th = hash('sha256', $_SESSION['tok']);
                $st = $conn->prepare("UPDATE sesiones SET revocada = 1 WHERE usuario_id = ? AND token_hash <> ?");
                $st->bind_param("is", $USR['id'], $th);
                $st->execute();
                $conn->commit();
                bitacora('CAMBIO_PASSWORD', 'usuarios', $USR['id']);
                flash("Contraseña actualizada correctamente.");
                redirigir('index.php');
            } catch (mysqli_sql_exception $ex) {
                $conn->rollback();
                error_log($ex->getMessage());
                flash("No se pudo cambiar la contraseña.", 'error');
            }
        }
    }
    redirigir('cambiar_password.php');
}

require 'includes/header.php';
?>
<h2>Cambiar contraseña</h2>
<div class="card">
  <?php if ($forzado): ?>
    <p><b>Primer ingreso:</b> tu contraseña temporal es tu misma matrícula. Crea ahora una contraseña propia para continuar.</p>
  <?php endif; ?>
  <form method="post" class="grid">
    <?= csrf_field() ?>
    <?php if (!$forzado): ?>
      <div><label>Contraseña actual *</label><input type="password" name="actual" required autocomplete="current-password"></div>
    <?php endif; ?>
    <div><label>Nueva contraseña * (mín. 10, letras y números)</label><input type="password" name="nueva" required minlength="10" autocomplete="new-password"></div>
    <div><label>Confirmar nueva contraseña *</label><input type="password" name="confirmar" required minlength="10" autocomplete="new-password"></div>
    <div><button>Cambiar contraseña</button></div>
  </form>
</div>
<?php require 'includes/footer.php'; ?>

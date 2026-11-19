<?php
require_once 'config/db.php';

if (!empty($_SESSION['uid'])) redirigir('index.php');

$MAX_INTENTOS = 3;      // intentos fallidos antes de bloquear
$BLOQUEO_MIN  = 1;     // minutos de bloqueo
$error = '';
if (isset($_GET['expirada'])) $error = 'Tu sesión expiró. Inicia sesión de nuevo.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $usuario = trim($_POST['usuario'] ?? '');
    // Si escriben el correo institucional (123456@alumno.edu.mx), se usa solo el número
    if (preg_match('/^(\d+)@(alumno|profesor)\.edu\.mx$/i', $usuario, $m)) $usuario = $m[1];
    $pass    = $_POST['password'] ?? '';

    $st = $conn->prepare("SELECT id, password, rol, activo, intentos_fallidos, alumno_id, profesor_id, nombre_admin,
                                 (bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()) AS bloqueada
                          FROM usuarios WHERE usuario = ?");
    $st->bind_param("s", $usuario);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();

    if ($u && $u['bloqueada']) {
        bitacora('LOGIN_BLOQUEADO', 'usuarios', (int)$u['id'], null, (int)$u['id']);
        $error = 'Cuenta bloqueada temporalmente por intentos fallidos. Intenta más tarde.';

    } elseif ($u && $u['activo'] && password_verify($pass, $u['password'])) {
        // ---- Acceso correcto ----
        $uid = (int)$u['id'];
        $st = $conn->prepare("UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW() WHERE id = ?");
        $st->bind_param("i", $uid);
        $st->execute();

        // Nombre para mostrar según el rol
        $nombre = $u['nombre_admin'] ?? 'Usuario';
        if ($u['rol'] === 'alumno') {
            $q = $conn->prepare("SELECT CONCAT(nombre, ' ', apellido_paterno) n FROM alumnos WHERE id = ?");
            $q->bind_param("i", $u['alumno_id']);
            $q->execute();
            $nombre = $q->get_result()->fetch_assoc()['n'] ?? $nombre;
        } elseif ($u['rol'] === 'profesor') {
            $q = $conn->prepare("SELECT CONCAT(nombre, ' ', apellido_paterno) n FROM profesores WHERE id = ?");
            $q->bind_param("i", $u['profesor_id']);
            $q->execute();
            $nombre = $q->get_result()->fetch_assoc()['n'] ?? $nombre;
        }

        // Sesión: en la BD solo se guarda el HASH del token
        session_regenerate_id(true);
        unset($_SESSION['csrf']);          // token CSRF nuevo para la sesión ya autenticada
        $token = bin2hex(random_bytes(32));
        $th    = hash('sha256', $token);
        $ip    = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua    = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $st = $conn->prepare("INSERT INTO sesiones (usuario_id, token_hash, ip, user_agent, expira_en)
                              VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 8 HOUR))");
        $st->bind_param("isss", $uid, $th, $ip, $ua);
        $st->execute();

        $_SESSION['uid']    = $uid;
        $_SESSION['tok']    = $token;
        $_SESSION['nombre'] = $nombre;
        $_SESSION['last']   = time();
        bitacora('LOGIN', 'usuarios', $uid, null, $uid);
        redirigir('index.php');

    } else {
        // ---- Acceso fallido (mensaje genérico: no revela si el usuario existe) ----
        if ($u) {
            $uid = (int)$u['id'];
            $st = $conn->prepare("UPDATE usuarios SET
                bloqueado_hasta   = IF(intentos_fallidos + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), bloqueado_hasta),
                intentos_fallidos = IF(intentos_fallidos + 1 >= ?, 0, intentos_fallidos + 1)
                WHERE id = ?");
            $st->bind_param("iiii", $MAX_INTENTOS, $BLOQUEO_MIN, $MAX_INTENTOS, $uid);
            $st->execute();
            bitacora('LOGIN_FALLIDO', 'usuarios', $uid, null, $uid);
        } else {
            bitacora('LOGIN_FALLIDO', null, null, 'usuario inexistente');
        }
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - Sistema Escolar</title>
<link rel="icon" type="image/png" href="img/Sis.png">
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
</head>
<body>
<div class="card login">
  <h2>Iniciar sesión</h2>
  <p style="text-align:center"><img src="img/usuario.png" alt="Usuario" class="avatar-login"></p>
  <?php if ($error) echo "<p class='error'>" . e($error) . "</p>"; ?>
  <form method="post">
    <?= csrf_field() ?>
    <p>
        <span class="campo-icono">
            <img src="img/icono-usuario.png" alt="" class="icono">
            <input name="usuario" placeholder="Matrícula" required autofocus autocomplete="username">
        </span>
    </p>
    <p>
        <span class="campo-icono">
            <img src="img/icono-clave.png" alt="" class="icono">
            <input type="password" name="password" placeholder="Contraseña" required autocomplete="current-password">
        </span>
    </p>
    <button>Entrar</button>
  </form>
</div>
<script src="<?= asset('js/app.js') ?>" defer></script>
</body>
</html>

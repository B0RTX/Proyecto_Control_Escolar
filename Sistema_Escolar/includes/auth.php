<?php
// Se incluye al inicio de CADA página protegida.
require_once __DIR__ . '/../config/db.php';

function cerrar_y_salir(string $motivo = ''): void {
    global $conn;
    if (!empty($_SESSION['tok'])) {          // también se revoca en la BD
        $th = hash('sha256', $_SESSION['tok']);
        $st = $conn->prepare("UPDATE sesiones SET revocada = 1 WHERE token_hash = ?");
        $st->bind_param("s", $th);
        $st->execute();
    }
    destruir_sesion();
    redirigir('login.php' . ($motivo ? "?$motivo=1" : ''));
}

if (empty($_SESSION['uid']) || empty($_SESSION['tok'])) {
    redirigir('login.php');
}

// Expiración por inactividad (30 minutos)
if (time() - ($_SESSION['last'] ?? 0) > 1800) {
    cerrar_y_salir('expirada');
}
$_SESSION['last'] = time();

// La sesión debe existir en la BD, no estar revocada ni vencida, y la cuenta seguir activa
$tokHash = hash('sha256', $_SESSION['tok']);
$uid = (int)$_SESSION['uid'];
$st = $conn->prepare("SELECT u.rol, u.alumno_id, u.profesor_id, u.debe_cambiar_password
                      FROM sesiones s JOIN usuarios u ON u.id = s.usuario_id
                      WHERE s.token_hash = ? AND s.usuario_id = ? AND s.revocada = 0
                        AND s.expira_en > NOW() AND u.activo = 1");
$st->bind_param("si", $tokHash, $uid);
$st->execute();
$info = $st->get_result()->fetch_assoc();
if (!$info) cerrar_y_salir('expirada');

$USR = [
    'id'          => $uid,
    'rol'         => $info['rol'],
    'alumno_id'   => $info['alumno_id']   !== null ? (int)$info['alumno_id']   : null,
    'profesor_id' => $info['profesor_id'] !== null ? (int)$info['profesor_id'] : null,
    'nombre'      => $_SESSION['nombre'] ?? '',
];

// Para que los triggers de auditoría sepan quién hizo el cambio
$conn->query("SET @app_usuario_id = " . $uid);

// Obliga a cambiar la contraseña temporal
if ($info['debe_cambiar_password'] && !defined('PERMITE_SIN_CAMBIO')) {
    redirigir('cambiar_password.php?primer=1');
}

/* ---------- Control de acceso por rol ---------- */
function requiere_rol(string ...$roles): void {
    global $USR;
    if (!in_array($USR['rol'], $roles, true)) {
        http_response_code(403);
        exit('<h2>Acceso denegado</h2><p>No tienes permiso para ver esta página.</p><p><a href="index.php">Volver al inicio</a></p>');
    }
}

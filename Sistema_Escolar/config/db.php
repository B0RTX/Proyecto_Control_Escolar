<?php
// ---------- Conexión a la base de datos ----------
// XAMPP (local): root sin contraseña. En Docker/producción define DB_HOST, DB_USER, DB_PASS y DB_NAME
// como variables de entorno y se usan en lugar de estos valores (usa un usuario con mínimos privilegios, NUNCA root).
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST = getenv('DB_HOST') ?: 'db';
$DB_USER = getenv('DB_USER') ?: 'nexus_user';
$DB_PASS = getenv('DB_PASS') ?: 'Nexus_2026';
$DB_NAME = getenv('DB_NAME') ?: 'sistema_escolar';

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $ex) {
    error_log('Error de conexión BD: ' . $ex->getMessage());   // el detalle va al log, no a la pantalla
    http_response_code(500);
    exit('No se pudo conectar con el sistema. Intenta más tarde.');
}

// ---------- Cabeceras de seguridad ----------
// Ninguna página se guarda en caché: el botón «Atrás» vuelve a pedirla al servidor (y exige sesión).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// ---------- Sesión segura ----------
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');     // no acepta IDs de sesión inventados por un atacante
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),   // activa HTTPS en producción
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/../includes/funciones.php';

<?php
require_once 'config/db.php';

if (!empty($_SESSION['tok'])) {
    $th = hash('sha256', $_SESSION['tok']);
    $st = $conn->prepare("UPDATE sesiones SET revocada = 1 WHERE token_hash = ?");
    $st->bind_param("s", $th);
    $st->execute();
    bitacora('LOGOUT');
}
destruir_sesion();
redirigir('login.php');

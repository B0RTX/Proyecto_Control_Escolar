<?php
define('PERMITE_SIN_CAMBIO', true);   // se puede usar aunque la contraseña sea temporal
require 'includes/auth.php';

$carpeta = __DIR__ . '/uploads/perfiles';
$destino = $carpeta . '/' . (int)$USR['id'] . '.jpg';

/* ---------- Subir o quitar foto ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Regresa a la página donde estabas
    $pagina = basename(parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH) ?: '');
    $vuelta = preg_match('/^[a-z_]+\.php$/', $pagina) && is_file(__DIR__ . '/' . $pagina) ? $pagina : 'index.php';

    if (($_POST['accion'] ?? '') === 'quitar') {
        if (is_file($destino)) unlink($destino);
        bitacora('FOTO_PERFIL_QUITADA');
        flash('Tu foto de perfil se eliminó.');
        redirigir($vuelta);
    }

    $f = $_FILES['foto'] ?? null;
    $lectores = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
        IMAGETYPE_GIF  => 'imagecreatefromgif',
    ];

    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 8 * 1024 * 1024) {
        flash('No se pudo subir la imagen (máximo 8 MB).', 'error');
    } elseif (!function_exists('imagecreatetruecolor')) {
        flash('Falta activar la extensión GD en php.ini (extension=gd).', 'error');
    } else {
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !isset($lectores[$info[2]]) || !function_exists($lectores[$info[2]])) {
            flash('Formato no válido. Usa JPG, PNG, WEBP o GIF.', 'error');
        } elseif ($info[0] * $info[1] > 16000000) {
            flash('La imagen es demasiado grande en dimensiones. Usa una más pequeña.', 'error');
        } else {
            $origen = $lectores[$info[2]]($f['tmp_name']);
            if (!$origen) {
                flash('No se pudo leer la imagen.', 'error');
            } else {
                // Corrige la orientación de fotos del celular
                if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                    $o = (@exif_read_data($f['tmp_name']))['Orientation'] ?? 1;
                    $giro = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
                    if ($giro && ($rotada = imagerotate($origen, $giro, 0))) $origen = $rotada;
                }
                // Recorte cuadrado centrado a 300x300
                $w = imagesx($origen); $h = imagesy($origen); $lado = min($w, $h);
                $lienzo = imagecreatetruecolor(300, 300);
                imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
                imagecopyresampled($lienzo, $origen, 0, 0, intdiv($w - $lado, 2), intdiv($h - $lado, 2), 300, 300, $lado, $lado);

                if (!is_dir($carpeta)) mkdir($carpeta, 0755, true);
                $ht = __DIR__ . '/uploads/.htaccess';
                if (!is_file($ht)) file_put_contents($ht, "Require all denied\n");   // las fotos solo se ven desde el sistema

                if (imagejpeg($lienzo, $destino, 88)) { bitacora('FOTO_PERFIL_ACTUALIZADA'); flash('Foto de perfil actualizada.'); }
                else flash('No se pudo guardar la imagen.', 'error');
                // (imagedestroy ya no hace falta: PHP libera las imágenes solo y la función quedó obsoleta)
            }
        }
    }
    redirigir($vuelta);
}

/* ---------- Mostrar mi foto ---------- */
if (!is_file($destino)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($destino));
header('Cache-Control: private, max-age=86400');
readfile($destino);

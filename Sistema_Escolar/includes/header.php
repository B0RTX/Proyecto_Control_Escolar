<?php
// Menú por secciones. Cada opción solo aparece si el rol tiene permiso.
$secciones = [
    'Principal' => [
        'index.php'          => ['🏠', 'Inicio',          ['admin', 'profesor', 'alumno']],
    ],
    'Mi espacio' => [
        'mis_grupos.php'     => ['📚', 'Mis grupos',      ['profesor']],
        'mi_grupo.php'       => ['🎒', 'Mi grupo y materias', ['alumno']],
    ],
    'Académico' => [
        'calificaciones.php' => ['✏️', 'Calificaciones',  ['admin', 'profesor']],
        'asistencias.php'    => ['📅', 'Asistencias',     ['admin', 'profesor', 'alumno']],
        'boleta.php'         => ['📄', 'Boleta',          ['admin', 'alumno']],
    ],
    'Administración' => [
        'alumnos.php'        => ['🎓', 'Alumnos',         ['admin']],
        'profesores.php'     => ['👩‍🏫', 'Profesores',      ['admin']],
        'materias.php'       => ['📘', 'Materias',        ['admin']],
        'ciclos.php'         => ['🗓️', 'Ciclos y grupos', ['admin']],
        'asignaciones.php'   => ['🔗', 'Asignaciones',    ['admin']],
    ],
    'Sistema' => [
        'usuarios.php'       => ['🔑', 'Usuarios',        ['admin']],
        'bitacora.php'       => ['🧾', 'Bitácora',        ['admin']],
    ],
];
$paginaActual = basename($_SERVER['SCRIPT_NAME']);
$inicial = mb_strtoupper(mb_substr($USR['nombre'] ?: 'U', 0, 1));

// Título de la pestaña: sale del propio menú
$tituloPagina = $paginaActual === 'cambiar_password.php' ? 'Cambiar contraseña' : '';
foreach ($secciones as $items) {
    if (isset($items[$paginaActual])) { $tituloPagina = $items[$paginaActual][1]; break; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($tituloPagina ? "$tituloPagina · Sistema Escolar" : 'Sistema Escolar') ?></title>
<link rel="icon" type="image/png" href="img/Sis.png">
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
<script src="<?= asset('js/app.js') ?>" defer></script>
</head>
<body>

<header class="topbar noprint">
  <button type="button" id="menuBtn" class="menu-btn" aria-label="Abrir menú" aria-expanded="false" aria-controls="drawer">
    <span></span><span></span><span></span>
  </button>
  <a href="index.php" class="brand" title="Ir al inicio">Sistema Escolar</a>
</header>

<div id="overlay" class="overlay noprint"></div>

<aside id="drawer" class="drawer noprint" aria-label="Menú principal">
  <div class="drawer-user">
    <?php $fp = __DIR__ . '/../uploads/perfiles/' . (int)$USR['id'] . '.jpg'; $tieneFoto = is_file($fp); ?>
    <form method="post" action="foto.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <label for="inputFoto" class="foto-perfil" title="Cambiar foto de perfil">
        <?php if ($tieneFoto): ?><img src="foto.php?v=<?= filemtime($fp) ?>" alt="Foto de perfil"><?php else: ?><span><?= e($inicial) ?></span><?php endif; ?>
      </label>
      <input type="file" id="inputFoto" name="foto" accept="image/*" hidden data-autosubmit>
    </form>
    <small><?= e(rol_texto($USR['rol'])) ?></small>

    <div class="foto-acciones">
      <label for="inputFoto"><?= $tieneFoto ? 'Cambiar foto' : 'Subir foto' ?></label>
      <?php if ($tieneFoto): ?>
      <form method="post" action="foto.php" data-confirm="¿Quitar tu foto de perfil?">
        <?= csrf_field() ?><input type="hidden" name="accion" value="quitar">
        <button type="submit">Quitar foto</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <nav class="drawer-nav">
    <?php foreach ($secciones as $titSec => $items):
        $visibles = array_filter($items, fn($it) => in_array($USR['rol'], $it[2], true));
        if (!$visibles) continue; ?>
      <h4><?= e($titSec) ?></h4>
      <?php foreach ($visibles as $archivo => [$icono, $texto]): ?>
        <a href="<?= $archivo ?>" <?= $paginaActual === $archivo ? 'class="activo" aria-current="page"' : '' ?>><span><?= $icono ?></span><?= e($texto) ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <div class="drawer-cuenta">
    <h4>Mi cuenta</h4>
    <a href="cambiar_password.php" <?= $paginaActual === 'cambiar_password.php' ? 'class="activo"' : '' ?>><span>🔒</span>Cambiar contraseña</a>
    <a href="logout.php" class="salir"><span>🚪</span>Cerrar sesión</a>
  </div>
</aside>

<main class="container">
<?php mostrar_flash(); ?>

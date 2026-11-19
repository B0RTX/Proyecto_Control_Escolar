<?php
require 'includes/auth.php';
requiere_rol('admin');


/* ---------- Acciones POST (crear, actualizar, borrar) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a    = $_POST['accion'] ?? '';
    $cid  = (int)($_POST['ciclo_id'] ?? 0);
    $id   = (int)($_POST['id'] ?? 0);
    $dest = 'ciclos.php' . ($cid ? "?ciclo=$cid" : '');

    /* ----- CICLOS ----- */
    if ($a === 'ciclo_guardar') {
        /* ----- EDITAR un ciclo existente (nombre y fechas a mano) ----- */
        if ($id) {
            $nombre = trim($_POST['nombre'] ?? '');
            $ini = $_POST['fecha_inicio'] ?? '';
            $fin = $_POST['fecha_fin'] ?? '';
            if ($nombre === '' || $ini === '' || $fin === '') {
                flash("Completa el nombre y las fechas del ciclo.", 'error');
            } elseif ($fin <= $ini) {
                flash("La fecha de fin debe ser posterior a la de inicio.", 'error');
            } else {
                ejecutar(function () use ($conn, $id, $nombre, $ini, $fin) {
                    $st = $conn->prepare("UPDATE ciclos_escolares SET nombre=?, fecha_inicio=?, fecha_fin=? WHERE id=?");
                    $st->bind_param("sssi", $nombre, $ini, $fin, $id); $st->execute();
                }, "Ciclo actualizado.");
                redirigir("ciclos.php?ciclo=$id");
            }
            redirigir("ciclos.php?edit_ciclo=$id");
        }

        /* ----- CREAR un ciclo: solo se elige el año y el tipo de periodo ----- */
        $anio   = (int)($_POST['anio_inicio'] ?? 0);
        $tipo   = $_POST['tipo'] ?? '';
        $copiar = (int)($_POST['copiar_de'] ?? 0);
        [$anioMin, $anioMax] = rango_anios_ciclo();

        if (!isset(TIPOS_PERIODO[$tipo]) || $anio < $anioMin || $anio > $anioMax) {
            flash("Elige un ciclo y un tipo de periodo de la lista.", 'error');
            redirigir('ciclos.php');
        }
        $nombre = $anio . '-' . ($anio + 1);
        $st = $conn->prepare("SELECT 1 FROM ciclos_escolares WHERE nombre = ?");
        $st->bind_param("s", $nombre); $st->execute();
        if ($st->get_result()->fetch_row()) {
            flash("El ciclo $nombre ya existe.", 'error');
            redirigir('ciclos.php');
        }

        [$etiqueta, $n] = TIPOS_PERIODO[$tipo];
        $primero = $conn->query("SELECT COUNT(*) c FROM ciclos_escolares")->fetch_assoc()['c'] == 0 ? 1 : 0;
        // El ciclo va del 1 de septiembre al 31 de agosto; los periodos reparten esos 12 meses por igual.
        $inicio = new DateTimeImmutable("$anio-09-01");
        $fin    = $inicio->add(new DateInterval('P1Y'))->sub(new DateInterval('P1D'));
        $meses  = intdiv(12, $n);

        ejecutar(function () use ($conn, $nombre, $inicio, $fin, $etiqueta, $n, $meses, $primero, $copiar) {
            $conn->begin_transaction();
            try {
                $ini = $inicio->format('Y-m-d'); $fn = $fin->format('Y-m-d');
                $st = $conn->prepare("INSERT INTO ciclos_escolares (nombre, fecha_inicio, fecha_fin, activo) VALUES (?,?,?,?)");
                $st->bind_param("sssi", $nombre, $ini, $fn, $primero); $st->execute();
                $nid = $conn->insert_id;

                $st = $conn->prepare("INSERT INTO periodos (ciclo_id, numero, nombre, fecha_inicio, fecha_fin) VALUES (?,?,?,?,?)");
                for ($i = 1; $i <= $n; $i++) {
                    $pNom = "$etiqueta $i";
                    $pIni = $inicio->add(new DateInterval('P' . (($i - 1) * $meses) . 'M'))->format('Y-m-d');
                    $pFin = $inicio->add(new DateInterval('P' . ($i * $meses) . 'M'))->sub(new DateInterval('P1D'))->format('Y-m-d');
                    $st->bind_param("iisss", $nid, $i, $pNom, $pIni, $pFin); $st->execute();
                }

                $copiados = 0;
                if ($copiar) {
                    $st = $conn->prepare("INSERT INTO grupos (ciclo_id, grado_id, letra) SELECT ?, grado_id, letra FROM grupos WHERE ciclo_id = ?");
                    $st->bind_param("ii", $nid, $copiar); $st->execute();
                    $copiados = $st->affected_rows;
                }
                $conn->commit();
                return "Ciclo $nombre creado con $n " . strtolower($etiqueta) . "(s)."
                     . ($copiados ? " Se copiaron $copiados grupo(s)." : '')
                     . ($primero ? " Quedó como ciclo activo." : '');
            } catch (Throwable $t) { $conn->rollback(); throw $t; }
        }, '');
        redirigir('ciclos.php');
    }
    if ($a === 'ciclo_activar') {
        $ok = ejecutar(function () use ($conn, $id) {
            $st = $conn->prepare("UPDATE ciclos_escolares SET activo = (id = ?)");
            $st->bind_param("i", $id); $st->execute();
        }, "Ciclo activado.");
        if ($ok) bitacora('CICLO_ACTIVADO', 'ciclos_escolares', $id);
        redirigir("ciclos.php?ciclo=$id");
    }
    if ($a === 'ciclo_borrar') {
        $ok = ejecutar(function () use ($conn, $id) {
            $st = $conn->prepare("DELETE FROM ciclos_escolares WHERE id = ?");
            $st->bind_param("i", $id); $st->execute();
        }, "Ciclo eliminado.");
        if ($ok) bitacora('CICLO_ELIMINADO', 'ciclos_escolares', $id);
        redirigir('ciclos.php');
    }

    /* ----- PERIODOS ----- */
    if ($a === 'periodo_guardar') {
        $num = (int)($_POST['numero'] ?? 0);
        $nom = trim($_POST['nombre'] ?? '');
        $ini = ($_POST['fecha_inicio'] ?? '') ?: null;
        $fin = ($_POST['fecha_fin'] ?? '') ?: null;
        if (!$cid || $num < 1 || $nom === '') {
            flash("Indica el número y el nombre del periodo.", 'error');
        } else {
            ejecutar(function () use ($conn, $id, $cid, $num, $nom, $ini, $fin) {
                if ($id) {
                    $st = $conn->prepare("UPDATE periodos SET numero=?, nombre=?, fecha_inicio=?, fecha_fin=? WHERE id=? AND ciclo_id=?");
                    $st->bind_param("isssii", $num, $nom, $ini, $fin, $id, $cid);
                } else {
                    $st = $conn->prepare("INSERT INTO periodos (ciclo_id, numero, nombre, fecha_inicio, fecha_fin) VALUES (?,?,?,?,?)");
                    $st->bind_param("iisss", $cid, $num, $nom, $ini, $fin);
                }
                $st->execute();
            }, $id ? "Periodo actualizado." : "Periodo agregado.");
        }
        redirigir($dest);
    }
    if ($a === 'periodo_borrar') {
        ejecutar(function () use ($conn, $id) {
            $st = $conn->prepare("DELETE FROM periodos WHERE id = ?");
            $st->bind_param("i", $id); $st->execute();
        }, "Periodo eliminado (y sus calificaciones).");
        redirigir($dest);
    }

    /* ----- GRADOS ----- */
    if ($a === 'grado_guardar') {
        $nom = trim($_POST['nombre'] ?? '');
        if ($nom === '') flash("Escribe el nombre del grado.", 'error');
        else ejecutar(function () use ($conn, $id, $nom) {
            if ($id) {
                $st = $conn->prepare("UPDATE grados SET nombre = ? WHERE id = ?");
                $st->bind_param("si", $nom, $id);
            } else {
                $st = $conn->prepare("INSERT INTO grados (nombre) VALUES (?)");
                $st->bind_param("s", $nom);
            }
            $st->execute();
        }, $id ? "Grado actualizado." : "Grado agregado.");
        redirigir($dest);
    }
    if ($a === 'grados_base') {
        ejecutar(function () use ($conn) {
            $conn->query("INSERT IGNORE INTO grados (nombre) VALUES ('1°'),('2°'),('3°')");
        }, "Grados 1°, 2° y 3° creados.");
        redirigir($dest);
    }
    if ($a === 'grado_borrar') {
        ejecutar(function () use ($conn, $id) {
            $st = $conn->prepare("DELETE FROM grados WHERE id = ?");
            $st->bind_param("i", $id); $st->execute();
        }, "Grado eliminado.");
        redirigir($dest);
    }

    /* ----- GRUPOS ----- */
    if ($a === 'grupo_guardar') {
        $gid = (int)($_POST['grado_id'] ?? 0);
        $letras = preg_split('/[\s,;]+/', strtoupper(trim($_POST['letras'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $validas = array_values(array_unique(array_filter($letras, fn($l) => preg_match('/^[A-Z]$/', $l))));
        if (!$cid || !$gid || !$validas) {
            flash("Elige el grado y escribe al menos una letra (ej. A, B, C).", 'error');
        } else {
            ejecutar(function () use ($conn, $id, $cid, $gid, $validas) {
                if ($id) {
                    $l = $validas[0];
                    $st = $conn->prepare("UPDATE grupos SET grado_id = ?, letra = ? WHERE id = ? AND ciclo_id = ?");
                    $st->bind_param("isii", $gid, $l, $id, $cid); $st->execute();
                    return "Grupo actualizado.";
                }
                $st = $conn->prepare("INSERT IGNORE INTO grupos (ciclo_id, grado_id, letra) VALUES (?,?,?)");
                foreach ($validas as $l) { $st->bind_param("iis", $cid, $gid, $l); $st->execute(); }
                return "Grupo(s) creado(s).";
            }, '');
        }
        redirigir($dest);
    }
    if ($a === 'grupo_borrar') {
        ejecutar(function () use ($conn, $id) {
            $st = $conn->prepare("DELETE FROM grupos WHERE id = ?");
            $st->bind_param("i", $id); $st->execute();
        }, "Grupo eliminado.");
        redirigir($dest);
    }
    redirigir($dest);
}

/* ---------- Datos para mostrar ---------- */
$ciclos = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM grupos g WHERE g.ciclo_id = c.id) ng
                        FROM ciclos_escolares c ORDER BY c.fecha_inicio DESC")->fetch_all(MYSQLI_ASSOC);
$sel = (int)($_GET['ciclo'] ?? 0);
if (!$sel) {
    foreach ($ciclos as $c) if ($c['activo']) $sel = (int)$c['id'];
    if (!$sel && $ciclos) $sel = (int)$ciclos[0]['id'];
}
$grados = $conn->query("SELECT * FROM grados ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

function fila(string $sql, int $id, array $vacio): array {
    global $conn;
    if (!$id) return $vacio;
    $st = $conn->prepare($sql); $st->bind_param("i", $id); $st->execute();
    return $st->get_result()->fetch_assoc() ?: $vacio;
}
$eC = fila("SELECT * FROM ciclos_escolares WHERE id = ?", (int)($_GET['edit_ciclo'] ?? 0), ['id' => 0, 'nombre' => '', 'fecha_inicio' => '', 'fecha_fin' => '']);
$eP = fila("SELECT * FROM periodos WHERE id = ?", (int)($_GET['edit_periodo'] ?? 0), ['id' => 0, 'numero' => '', 'nombre' => '', 'fecha_inicio' => '', 'fecha_fin' => '']);
$eG = fila("SELECT * FROM grados WHERE id = ?", (int)($_GET['edit_grado'] ?? 0), ['id' => 0, 'nombre' => '']);
$eU = fila("SELECT * FROM grupos WHERE id = ?", (int)($_GET['edit_grupo'] ?? 0), ['id' => 0, 'grado_id' => 0, 'letra' => '']);

$periodos = $grupos = [];
$nomSel = '';
foreach ($ciclos as $c) if ((int)$c['id'] === $sel) $nomSel = $c['nombre'];
if ($sel) {
    $st = $conn->prepare("SELECT * FROM periodos WHERE ciclo_id = ? ORDER BY numero");
    $st->bind_param("i", $sel); $st->execute();
    $periodos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st = $conn->prepare("SELECT g.id, CONCAT(gr.nombre, g.letra) nombre,
                                 (SELECT COUNT(*) FROM inscripciones i WHERE i.grupo_id = g.id) alumnos
                          FROM grupos g JOIN grados gr ON gr.id = g.grado_id
                          WHERE g.ciclo_id = ? ORDER BY gr.nombre, g.letra");
    $st->bind_param("i", $sel); $st->execute();
    $grupos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

require 'includes/header.php';
?>
<h2>Ciclos escolares, grados y grupos</h2>

<div class="card">
  <h3>1. Ciclos escolares</h3>
  <?php if ($eC['id']): ?>
  <form method="post" autocomplete="off" class="grid">
    <?= csrf_field() ?><input type="hidden" name="accion" value="ciclo_guardar"><input type="hidden" name="id" value="<?= (int)$eC['id'] ?>">
    <div><label>Nombre *</label><input name="nombre" value="<?= e($eC['nombre']) ?>" required></div>
    <div><label>Inicio *</label><input type="date" name="fecha_inicio" value="<?= e($eC['fecha_inicio']) ?>" required></div>
    <div><label>Fin *</label><input type="date" name="fecha_fin" value="<?= e($eC['fecha_fin']) ?>" required></div>
    <div><button>Actualizar ciclo</button> <a class="btn gris" href="ciclos.php?ciclo=<?= $sel ?>">Cancelar</a></div>
  </form>
  <?php else:
    // Ciclos que aún no existen, dentro del rango permitido (el nombre es AAAA-AAAA+1)
    [$anioMin, $anioMax] = rango_anios_ciclo();
    $existentes = array_column($ciclos, 'nombre');
    $anioBase = (int)date('n') >= 8 ? (int)date('Y') : (int)date('Y') - 1;   // el ciclo en curso
    $opciones = []; $anioSug = 0;
    for ($y = $anioMin; $y <= $anioMax; $y++) {
        if (in_array($y . '-' . ($y + 1), $existentes, true)) continue;
        $opciones[] = $y;
        if (!$anioSug && $y >= $anioBase) $anioSug = $y;
    }
    if (!$anioSug && $opciones) $anioSug = $opciones[0];
    $conGrupos = array_values(array_filter($ciclos, fn($c) => (int)$c['ng'] > 0));
  ?>
  <?php if (!$opciones): ?>
    <p class="muted">Ya están creados todos los ciclos disponibles (<?= $anioMin ?>-<?= $anioMax + 1 ?>).</p>
  <?php else: ?>
  <form method="post" autocomplete="off" class="grid">
    <?= csrf_field() ?><input type="hidden" name="accion" value="ciclo_guardar"><input type="hidden" name="id" value="0">
    <div><label>Ciclo escolar *</label>
      <select name="anio_inicio" required>
        <?php foreach ($opciones as $y): ?><option value="<?= $y ?>" <?= $y === $anioSug ? 'selected' : '' ?>><?= $y ?>-<?= $y + 1 ?></option><?php endforeach; ?>
      </select></div>
    <div><label>Tipo de periodo *</label>
      <select name="tipo" required>
        <?php foreach (TIPOS_PERIODO as $k => [$et, $n]): ?><option value="<?= e($k) ?>" <?= $k === 'semestre' ? 'selected' : '' ?>><?= e($et) ?>s (<?= $n ?> por ciclo)</option><?php endforeach; ?>
      </select></div>
    <div><label>Copiar grupos de</label>
      <select name="copiar_de">
        <option value="0">No copiar</option>
        <?php foreach ($conGrupos as $i => $c): ?><option value="<?= (int)$c['id'] ?>" <?= $i === 0 ? 'selected' : '' ?>>Ciclo <?= e($c['nombre']) ?> (<?= (int)$c['ng'] ?> grupos)</option><?php endforeach; ?>
      </select></div>
    <div><button>Crear ciclo</button></div>
  </form>
  <p class="muted">Al crearlo, el sistema genera el nombre, las fechas (del 1 de septiembre al 31 de agosto) y los periodos con sus fechas. Si necesitas otras fechas, usa «Editar» en el ciclo o en cada periodo.</p>
  <?php endif; endif; ?>
  <div class="tabla-wrap"><table>
    <tr><th>Ciclo</th><th>Inicio</th><th>Fin</th><th>Grupos</th><th>Estado</th><th>Acciones</th></tr>
    <?php if (!$ciclos): ?><tr><td colspan="6" class="muted">Aún no hay ciclos. Crea el primero.</td></tr><?php endif; ?>
    <?php foreach ($ciclos as $c): ?>
    <tr>
      <td><a href="?ciclo=<?= $c['id'] ?>"><b><?= e($c['nombre']) ?></b></a><?= (int)$c['id'] === $sel ? ' 👈' : '' ?></td>
      <td><?= e($c['fecha_inicio']) ?></td><td><?= e($c['fecha_fin']) ?></td><td><?= (int)$c['ng'] ?></td>
      <td><?= $c['activo'] ? '<span class="ok"><b>Activo</b></span>' : '<span class="muted">Inactivo</span>' ?></td>
      <td>
        <a class="btn sm" href="?ciclo=<?= $sel ?>&edit_ciclo=<?= $c['id'] ?>">Editar</a>
        <?php if (!$c['activo']) echo btn_post('ciclo_activar', ['id' => $c['id']], 'Activar'); ?>
        <?= btn_post('ciclo_borrar', ['id' => $c['id']], 'Borrar', 'btn red sm', '¿Eliminar este ciclo con sus periodos?') ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted">Solo un ciclo puede estar activo; es el que usan alumnos, asignaciones, calificaciones y asistencias.</p>
</div>

<?php if ($sel): ?>
<div class="card">
  <h3>2. Periodos del ciclo <?= e($nomSel) ?></h3>
  <form method="post" autocomplete="off" class="grid">
    <?= csrf_field() ?><input type="hidden" name="accion" value="periodo_guardar"><input type="hidden" name="ciclo_id" value="<?= $sel ?>"><input type="hidden" name="id" value="<?= (int)$eP['id'] ?>">
    <div><label>Número *</label><input type="number" name="numero" min="1" value="<?= e($eP['id'] ? $eP['numero'] : count($periodos) + 1) ?>" required></div>
    <div><label>Nombre *</label><input name="nombre" value="<?= e($eP['nombre']) ?>" placeholder="Periodo <?= count($periodos) + 1 ?>" required></div>
    <div><label>Inicio</label><input type="date" name="fecha_inicio" value="<?= e($eP['fecha_inicio']) ?>"></div>
    <div><label>Fin</label><input type="date" name="fecha_fin" value="<?= e($eP['fecha_fin']) ?>"></div>
    <div><button><?= $eP['id'] ? 'Actualizar periodo' : 'Agregar periodo' ?></button>
      <?php if ($eP['id']): ?><a class="btn gris" href="ciclos.php?ciclo=<?= $sel ?>">Cancelar</a><?php endif; ?></div>
  </form>
  <div class="tabla-wrap"><table>
    <tr><th>#</th><th>Nombre</th><th>Inicio</th><th>Fin</th><th>Acciones</th></tr>
    <?php foreach ($periodos as $p): ?>
    <tr><td><?= (int)$p['numero'] ?></td><td><?= e($p['nombre']) ?></td><td><?= e($p['fecha_inicio']) ?></td><td><?= e($p['fecha_fin']) ?></td>
        <td><a class="btn sm" href="?ciclo=<?= $sel ?>&edit_periodo=<?= $p['id'] ?>">Editar</a>
          <?= btn_post('periodo_borrar', ['id' => $p['id'], 'ciclo_id' => $sel], 'Borrar', 'btn red sm', '¿Eliminar periodo y sus calificaciones?') ?></td></tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>

<div class="card">
  <h3>3. Grados</h3>
  <?php if (!$grados): ?>
    <?= btn_post('grados_base', ['ciclo_id' => $sel], 'Crear 1°, 2° y 3°', 'btn') ?>
    <p class="muted">o agrégalos manualmente:</p>
  <?php endif; ?>
  <form method="post" autocomplete="off" class="grid">
    <?= csrf_field() ?><input type="hidden" name="accion" value="grado_guardar"><input type="hidden" name="ciclo_id" value="<?= $sel ?>"><input type="hidden" name="id" value="<?= (int)$eG['id'] ?>">
    <div><label>Nombre del grado *</label><input name="nombre" value="<?= e($eG['nombre']) ?>" placeholder="4°" required></div>
    <div><button><?= $eG['id'] ? 'Actualizar grado' : 'Agregar grado' ?></button>
      <?php if ($eG['id']): ?><a class="btn gris" href="ciclos.php?ciclo=<?= $sel ?>">Cancelar</a><?php endif; ?></div>
  </form>
  <div class="tabla-wrap"><table>
    <tr><th>Grado</th><th>Acciones</th></tr>
    <?php foreach ($grados as $g): ?>
    <tr><td><b><?= e($g['nombre']) ?></b></td>
        <td><a class="btn sm" href="?ciclo=<?= $sel ?>&edit_grado=<?= $g['id'] ?>">Editar</a>
          <?= btn_post('grado_borrar', ['id' => $g['id'], 'ciclo_id' => $sel], 'Borrar', 'btn red sm', '¿Eliminar este grado?') ?></td></tr>
    <?php endforeach; ?>
  </table></div>
</div>

<?php if ($sel && $grados): ?>
<div class="card">
  <h3>4. Grupos del ciclo <?= e($nomSel) ?></h3>
  <form method="post" autocomplete="off" class="grid">
    <?= csrf_field() ?><input type="hidden" name="accion" value="grupo_guardar"><input type="hidden" name="ciclo_id" value="<?= $sel ?>"><input type="hidden" name="id" value="<?= (int)$eU['id'] ?>">
    <div><label>Grado *</label>
      <select name="grado_id" required><option value="">-- Grado --</option>
        <?php foreach ($grados as $g): ?><option value="<?= $g['id'] ?>" <?= (int)$g['id'] === (int)$eU['grado_id'] ? 'selected' : '' ?>><?= e($g['nombre']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label><?= $eU['id'] ? 'Grupo *' : 'Grupos * (separados por coma)' ?></label>
      <input name="letras" value="<?= e($eU['letra']) ?>" placeholder="A, B, C" required></div>
    <div><button><?= $eU['id'] ? 'Actualizar grupo' : 'Crear grupo(s)' ?></button>
      <?php if ($eU['id']): ?><a class="btn gris" href="ciclos.php?ciclo=<?= $sel ?>">Cancelar</a><?php endif; ?></div>
  </form>
  <div class="tabla-wrap"><table>
    <tr><th>Grupo</th><th>Alumnos inscritos</th><th>Acciones</th></tr>
    <?php if (!$grupos): ?><tr><td colspan="3" class="muted">Este ciclo todavía no tiene grupos.</td></tr><?php endif; ?>
    <?php foreach ($grupos as $g): ?>
    <tr><td><b><?= e($g['nombre']) ?></b></td><td><?= (int)$g['alumnos'] ?></td>
        <td><a class="btn sm" href="?ciclo=<?= $sel ?>&edit_grupo=<?= $g['id'] ?>">Editar</a>
          <?= btn_post('grupo_borrar', ['id' => $g['id'], 'ciclo_id' => $sel], 'Borrar', 'btn red sm', '¿Eliminar este grupo?') ?></td></tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>

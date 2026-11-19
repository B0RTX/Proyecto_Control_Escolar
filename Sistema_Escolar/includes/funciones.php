<?php
/* ---------- Escape de salida (evita XSS) ---------- */
function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* ---------- Ruta de un recurso estático con versión (el navegador lo recarga solo cuando cambia) ---------- */
function asset(string $ruta): string {
    $f = __DIR__ . '/../' . $ruta;
    return $ruta . '?v=' . (is_file($f) ? filemtime($f) : 1);
}

/* ---------- CSRF ---------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
function csrf_check(): void {
    $esperado = $_SESSION['csrf'] ?? '';
    $recibido = $_POST['csrf'] ?? '';
    // Con ambos vacíos hash_equals('', '') da true y se saltaría la protección: se exige un token real.
    if ($esperado === '' || !is_string($recibido) || !hash_equals($esperado, $recibido)) {
        http_response_code(419);
        exit('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
    }
}

/* ---------- Mensajes flash ---------- */
function flash(string $msg, string $tipo = 'ok'): void {
    $_SESSION['flash'][] = [$msg, $tipo];
}
function mostrar_flash(): void {
    foreach ($_SESSION['flash'] ?? [] as [$m, $t]) {
        echo '<p class="' . e($t) . '">' . e($m) . '</p>';
    }
    unset($_SESSION['flash']);
}

/* ---------- Cierra la sesión por completo (datos + cookie) ---------- */
function destruir_sesion(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $c = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000, 'path' => $c['path'], 'domain' => $c['domain'],
            'secure'   => $c['secure'],   'httponly' => $c['httponly'], 'samesite' => $c['samesite'] ?: 'Lax',
        ]);
    }
    session_destroy();
}

function redirigir(string $url): void {
    header("Location: $url");
    exit;
}

/* ---------- Paginación ---------- */
function paginar(int $total, int $por): array {
    $paginas = max(1, (int)ceil($total / $por));
    $pag = min(max(1, (int)($_GET['pag'] ?? 1)), $paginas);
    return [$pag, $paginas, ($pag - 1) * $por];
}
function render_paginas(int $pag, int $paginas): string {
    if ($paginas <= 1) return '';
    $q = $_GET;
    // Solo primera, última y las vecinas de la actual (con muchos registros no se pintan cientos de enlaces)
    $mostrar = array_unique(array_merge([1, $paginas], range(max(1, $pag - 2), min($paginas, $pag + 2))));
    sort($mostrar);
    $out = '<nav class="paginas" aria-label="Paginación">';
    $previo = 0;
    foreach ($mostrar as $i) {
        if ($i - $previo > 1) $out .= '<span class="puntos">…</span>';
        $q['pag'] = $i;
        $out .= $i === $pag
            ? "<b aria-current=\"page\">$i</b>"
            : '<a href="?' . e(http_build_query($q)) . '">' . $i . '</a>';
        $previo = $i;
    }
    return $out . '</nav>';
}

/* ---------- Contraseñas: SOLO hash (Argon2id, o bcrypt si no está disponible) ---------- */
function hash_password(string $p): string {
    return defined('PASSWORD_ARGON2ID')
        ? password_hash($p, PASSWORD_ARGON2ID)
        : password_hash($p, PASSWORD_BCRYPT, ['cost' => 12]);
}

/* ---------- Bitácora (auditoría) ---------- */
function bitacora(string $accion, ?string $tabla = null, ?int $registro = null, ?string $detalle = null, ?int $uid = null): void {
    global $conn;
    $uid = $uid ?? ($_SESSION['uid'] ?? null);
    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
    try {
        $st = $conn->prepare("INSERT INTO bitacora (usuario_id, accion, tabla, registro_id, detalle, ip) VALUES (?,?,?,?,?,?)");
        $st->bind_param("ississ", $uid, $accion, $tabla, $registro, $detalle, $ip);
        $st->execute();
    } catch (mysqli_sql_exception $ex) {
        error_log('Bitácora: ' . $ex->getMessage());
    }
}

/* ---------- Ciclo escolar activo (0 si no hay) ---------- */
function ciclo_activo(): int {
    global $conn;
    return (int)($conn->query("SELECT id FROM ciclos_escolares WHERE activo = 1 LIMIT 1")->fetch_assoc()['id'] ?? 0);
}

/* ---------- Ciclos escolares estilo universidad ----------
   Tipo de periodo => [nombre en singular, cuántos caben en un ciclo de 12 meses] */
const TIPOS_PERIODO = [
    'semestre'     => ['Semestre', 2],
    'cuatrimestre' => ['Cuatrimestre', 3],
    'trimestre'    => ['Trimestre', 4],
    'bimestre'     => ['Bimestre', 6],
];
/* Años de inicio que se pueden elegir al crear un ciclo: del año pasado a 6 años adelante */
function rango_anios_ciclo(): array {
    $y = (int)date('Y');
    return [$y - 1, $y + 6];
}

/* ---------- Ejecuta una operación y traduce los errores de MySQL a mensajes claros ----------
   Si la función devuelve un texto, ese texto se usa como mensaje de éxito. */
function ejecutar(callable $fn, string $ok, string $errMsg = 'No se pudo completar la operación.'): bool {
    try {
        $r = $fn();
        flash(is_string($r) && $r !== '' ? $r : $ok);
        return true;
    // OJO con el orden: mysqli_sql_exception hereda de RuntimeException, así que va PRIMERO.
    } catch (mysqli_sql_exception $ex) {
        $c = $ex->getCode();
        if ($c == 1062)      flash('Ya existe un registro con esos datos.', 'error');
        elseif ($c == 1451)  flash('No se puede eliminar: tiene información relacionada.', 'error');
        elseif ($c == 3819)  flash('Los datos no son válidos.', 'error');
        else { error_log('SQL: ' . $ex->getMessage()); flash($errMsg, 'error'); }
        return false;
    } catch (RuntimeException $ex) {
        error_log($ex->getMessage());
        flash('No se pudo asignar la matrícula. Revisa que exista la tabla «secuencias» (ejecuta la migración).', 'error');
        return false;
    }
}

/* ---------- Botón que envía un POST (borrar, activar, etc.) ---------- */
function btn_post(string $accion, array $campos, string $texto, string $clase = 'btn sm', string $confirmar = ''): string {
    $h = '<form method="post" class="inline"'
       . ($confirmar ? ' data-confirm="' . e($confirmar) . '"' : '') . '>'
       . csrf_field() . '<input type="hidden" name="accion" value="' . e($accion) . '">';
    foreach ($campos as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    return $h . '<button class="' . e($clase) . '">' . e($texto) . '</button></form>';
}

/* ---------- Crea una cuenta de acceso ----------
   Usuario = matrícula. Contraseña temporal = la misma matrícula (se guarda su HASH).
   La cuenta queda marcada para obligar a cambiarla en el primer ingreso. */
function crear_cuenta(string $rol, string $usuario, ?int $alumnoId, ?int $profesorId, ?string $nombreAdmin = null): void {
    global $conn;
    $hash = hash_password($usuario);
    $st = $conn->prepare("INSERT INTO usuarios (usuario, password, rol, alumno_id, profesor_id, nombre_admin, debe_cambiar_password)
                          VALUES (?,?,?,?,?,?,1)");
    $st->bind_param("sssiis", $usuario, $hash, $rol, $alumnoId, $profesorId, $nombreAdmin);
    $st->execute();
    bitacora('CUENTA_CREADA', 'usuarios', (int)$conn->insert_id, "rol=$rol usuario=$usuario");
}

/* =====================================================================
   PERMISOS POR ASIGNACIÓN (grupo + materia)
   - admin: puede ver y modificar todas
   - profesor: SOLO las que tiene asignadas
   ===================================================================== */
function asignacion_permitida(int $id): ?array {
    global $conn, $USR;
    $sql = "SELECT a.id, a.grupo_id, a.profesor_id, g.ciclo_id, c.activo ciclo_activo,
                   CONCAT(gr.nombre, g.letra) grupo, m.nombre materia
            FROM asignaciones a
            JOIN grupos g ON g.id = a.grupo_id JOIN grados gr ON gr.id = g.grado_id
            JOIN ciclos_escolares c ON c.id = g.ciclo_id JOIN materias m ON m.id = a.materia_id
            WHERE a.id = ?";
    if ($USR['rol'] === 'profesor') {
        $st = $conn->prepare($sql . " AND a.profesor_id = ?");
        $st->bind_param("ii", $id, $USR['profesor_id']);
    } elseif ($USR['rol'] === 'admin') {
        $st = $conn->prepare($sql);
        $st->bind_param("i", $id);
    } else {
        return null;
    }
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

function asignaciones_visibles(int $ciclo): array {
    global $conn, $USR;
    $sql = "SELECT a.id, a.grupo_id, CONCAT(gr.nombre, g.letra) grupo, m.nombre materia,
                   TRIM(CONCAT_WS(' ', p.nombre, p.apellido_paterno)) profesor,
                   (SELECT COUNT(*) FROM inscripciones i WHERE i.grupo_id = a.grupo_id AND i.estatus = 'activo') alumnos
            FROM asignaciones a
            JOIN grupos g ON g.id = a.grupo_id JOIN grados gr ON gr.id = g.grado_id
            JOIN materias m ON m.id = a.materia_id LEFT JOIN profesores p ON p.id = a.profesor_id
            WHERE g.ciclo_id = ?";
    if ($USR['rol'] === 'profesor') {
        $st = $conn->prepare($sql . " AND a.profesor_id = ? ORDER BY gr.nombre, g.letra, m.nombre");
        $st->bind_param("ii", $ciclo, $USR['profesor_id']);
    } else {
        $st = $conn->prepare($sql . " ORDER BY gr.nombre, g.letra, m.nombre");
        $st->bind_param("i", $ciclo);
    }
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/* Alumnos inscritos (activos) en un grupo */
function alumnos_del_grupo(int $grupoId): array {
    global $conn;
    $st = $conn->prepare("SELECT i.id inscripcion_id, a.id alumno_id, a.matricula, a.email,
                                 CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombre) nombre
                          FROM inscripciones i JOIN alumnos a ON a.id = i.alumno_id
                          WHERE i.grupo_id = ? AND i.estatus = 'activo'
                          ORDER BY LENGTH(a.matricula), a.matricula");
    $st->bind_param("i", $grupoId);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/* =====================================================================
   CLAVES AUTOMÁTICAS Y CORREOS INSTITUCIONALES
   alumno:   123456 -> 123456@alumno.edu.mx   (usuario = 123456)
   profesor: 654321 -> 654321@profesor.edu.mx (usuario = 654321)
   ===================================================================== */
const DOMINIO_ALUMNO   = 'alumno.edu.mx';
const DOMINIO_PROFESOR = 'profesor.edu.mx';

function rol_texto(string $rol): string {
    return ['admin' => 'Administrador', 'profesor' => 'Profesor', 'alumno' => 'Alumno'][$rol] ?? ucfirst($rol);
}

function clave_existe(string $c): bool {
    global $conn;
    $correoProf = $c . '@' . DOMINIO_PROFESOR;
    $st = $conn->prepare("SELECT (EXISTS(SELECT 1 FROM alumnos WHERE matricula = ?)
                              OR EXISTS(SELECT 1 FROM usuarios WHERE usuario = ?)
                              OR EXISTS(SELECT 1 FROM profesores WHERE email = ?)) e");
    $st->bind_param("sss", $c, $c, $correoProf);
    $st->execute();
    return (bool)$st->get_result()->fetch_assoc()['e'];
}

/* Siguiente matrícula consecutiva por rol (alumno: 10000, 10001... | profesor: 50000... | admin: 1000...)
   El contador vive en la tabla `secuencias`. LAST_INSERT_ID(expr) lo incrementa de forma atómica,
   así que dos altas simultáneas nunca reciben el mismo número. Si la transacción que la pidió
   falla (ROLLBACK), el número se devuelve y no quedan huecos. */
function siguiente_clave(string $tipo): string {
    global $conn;
    for ($i = 0; $i < 1000; $i++) {
        $st = $conn->prepare("UPDATE secuencias SET siguiente = LAST_INSERT_ID(siguiente) + 1 WHERE tipo = ?");
        $st->bind_param("s", $tipo);
        $st->execute();
        if ($st->affected_rows === 0) {
            throw new RuntimeException("No existe la secuencia '$tipo' en la tabla secuencias.");
        }
        $v = (string)$conn->query("SELECT LAST_INSERT_ID() v")->fetch_assoc()['v'];
        if (!clave_existe($v)) return $v;     // por si ya hubiera un registro con ese número
    }
    throw new RuntimeException('No se pudo asignar una matrícula.');
}

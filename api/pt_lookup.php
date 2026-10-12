<?php
/**
 * api/pt_lookup.php - Consulta rapida de anteproyectos PT COES asociados.
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(45);

if (!isAuth()) { jsonOut(['status'=>'error','msg'=>'No autorizado'], 401); }

$input = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (json_decode(file_get_contents('php://input'), true) ?? $_POST)
    : $_GET;

$nombre = trim((string)($input['nombre'] ?? ''));
$empresa = trim((string)($input['empresa'] ?? ''));
$codigo = trim((string)($input['codigo'] ?? ''));
$departamento = trim((string)($input['departamento'] ?? $input['region'] ?? ''));
$tipo = in_array(($input['tipo'] ?? 'generic'), ['set','line','generic'], true) ? $input['tipo'] : 'generic';

if (!$nombre && !$codigo && !$empresa) {
    jsonOut(['status'=>'error','msg'=>'Nombre, codigo o empresa requerido']);
}
if (!is_file(PT_COES_ANALYZER)) {
    jsonOut(['status'=>'unavailable','message'=>'Analizador PT COES no instalado.']);
}

$cmd = escapeshellarg(PYTHON_BIN).' '.escapeshellarg(PT_COES_ANALYZER)
    .' --nombre '.escapeshellarg($nombre)
    .' --empresa '.escapeshellarg($empresa)
    .' --codigo '.escapeshellarg($codigo)
    .' --departamento '.escapeshellarg($departamento)
    .' --tipo '.escapeshellarg($tipo)
    .' --pt-dir '.escapeshellarg(PT_COES_DIR)
    .' --limit 6 2>&1';
$out = @shell_exec($cmd);
$dec = $out ? json_decode(trim($out), true) : null;
if (!is_array($dec) || !isset($dec['status'])) {
    jsonOut(['status'=>'unavailable','message'=>'Respuesta PT COES no valida.','raw'=>substr((string)$out, 0, 300)]);
}
jsonOut($dec);

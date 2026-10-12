<?php
/**
 * api/analyze.php — Análisis de disponibilidad de carga
 * GET/POST ?nombre=SE&excel_file=archivo (opcional)
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(600);

if (!isAuth()) { jsonOut(['status'=>'error','msg'=>'No autorizado'], 401); }

// ── Eliminar Excel ──
$deleteAction = $_POST['action'] ?? ($_GET['action'] ?? '');
if ($deleteAction === 'del_excel') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok'=>false,'msg'=>'Metodo no permitido'], 405);
    requireCsrf();
    if (!isAdmin()) jsonOut(['ok'=>false,'msg'=>'Solo administrador'], 403);
    $f = basename($_POST['file'] ?? '');
    if (!$f || !preg_match('/^[a-zA-Z0-9_.\-]+$/', $f)) jsonOut(['ok'=>false]);
    $m = array_values(array_filter(getManifest(), fn($x) => $x['stored'] !== $f));
    saveManifest($m);
    @unlink(EXCEL_DIR.'/'.$f);
    clearAnalysisCaches();
    jsonOut(['ok'=>true]);
}

// ── Parámetros ──
$input = $_SERVER['REQUEST_METHOD']==='POST'
    ? (json_decode(file_get_contents('php://input'),true) ?? $_POST)
    : $_GET;

$nombre = trim($input['nombre'] ?? $input['substation_name'] ?? '');
$empresa = trim($input['empresa'] ?? '');
$departamento = trim($input['departamento'] ?? $input['region'] ?? '');
$capacidad = isset($input['capacidad']) && is_numeric($input['capacidad']) ? (float)$input['capacidad'] : null;
$potInst = isset($input['pot_inst']) && is_numeric($input['pot_inst']) ? (float)$input['pot_inst'] : null;
if (!$nombre) jsonOut(['status'=>'error','msg'=>'Parámetro nombre requerido']);
if (!preg_match('/^[\p{L}\p{N}\s\/\-\.°]+$/u', $nombre))
    jsonOut(['status'=>'error','msg'=>'Nombre inválido']);

function pathSig(string $path): ?array {
    return is_file($path) ? ['size'=>filesize($path), 'mtime'=>filemtime($path)] : null;
}
function dirSig(string $dir, array $exts): array {
    if (!is_dir($dir)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $entry) {
        if (!$entry->isFile()) continue;
        $file = $entry->getFilename();
        if (str_starts_with($file, '~$')) continue;
        $path = $entry->getPathname();
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) continue;
        $out[] = [substr($path, strlen($dir) + 1), filesize($path), filemtime($path)];
    }
    sort($out);
    return $out;
}
$cacheSig = sha1(json_encode([
    'v' => 14,
    'nombre' => strtolower($nombre),
    'empresa' => strtolower($empresa),
    'departamento' => strtolower($departamento),
    'capacidad' => $capacidad,
    'pot_inst' => $potInst,
    'analyzer' => pathSig(ANALYZER),
    'master' => pathSig(EXCEL_DIR . '/DATOS DE SUB ESTACIONES MAESTRO.xlsx'),
    'transformer_capacity' => pathSig(EXCEL_DIR . '/EquiposTransformadores.xlsx'),
    'capacity' => pathSig(ROOT . '/data/osinergmin_capacity.json'),
    'quince' => dirSig(EXCEL_DIR . '/QUINCEMINUTALES EXCEL', ['xlsx','xlsm','xls']),
    'pit' => dirSig(PIT_DIR, ['xlsx','xlsm','xls']),
    'pt_analyzer' => pathSig(PT_COES_ANALYZER),
    'pt_coes' => dirSig(PT_COES_DIR, ['pdf']),
], JSON_UNESCAPED_UNICODE));

$cacheKey = preg_replace('/[^a-z0-9]+/i', '_', strtolower($nombre.'_'.$empresa));
$cacheFile = CACHE_DIR.'/analysis_v2_'.$cacheKey.'.json';
if (file_exists($cacheFile) && filemtime($cacheFile) > time() - 86400) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if (($cached['cache_sig'] ?? '') === $cacheSig && isset($cached['result'])) jsonOut($cached['result']);
}

// ── Intentar Python ──
function setIsOverloaded(array $result): bool {
    foreach (($result['resumen_transformadores'] ?? []) as $row) {
        if (isset($row['disponible_total_mva']) && (float)$row['disponible_total_mva'] < 0) return true;
        if (stripos((string)($row['estado'] ?? ''), 'sobrecarga') !== false) return true;
    }
    foreach (($result['filas'] ?? []) as $row) {
        if (isset($row['disponible_mva']) && (float)$row['disponible_mva'] < 0) return true;
        if (stripos((string)($row['estado'] ?? ''), 'sobrecarga') !== false) return true;
    }
    return false;
}

function attachPitLookup(array $result, string $nombre, string $empresa, string $departamento = ''): array {
    if (!is_file(PIT_ANALYZER)) {
        $result['pit'] = ['status'=>'unavailable','message'=>'Analizador PIT no instalado.'];
        return $result;
    }
    $cmd = escapeshellarg(PYTHON_BIN).' '.escapeshellarg(PIT_ANALYZER)
        .' --nombre '.escapeshellarg($nombre)
        .' --empresa '.escapeshellarg($empresa)
        .' --departamento '.escapeshellarg($departamento)
        .' --pit-dir '.escapeshellarg(PIT_DIR)
        .' --limit 30 2>&1';
    $out = @shell_exec($cmd);
    $pit = $out ? json_decode(trim($out), true) : null;
    if (!is_array($pit) || !isset($pit['status'])) {
        $pit = ['status'=>'unavailable','message'=>'Respuesta PIT no valida.','raw'=>substr((string)$out, 0, 300)];
    }
    if (setIsOverloaded($result) && !empty($pit['future_transformers'])) {
        $names = [];
        foreach (array_slice($pit['future_transformers'] ?? [], 0, 3) as $m) {
            $parts = [];
            $name = trim((string)($m['elemento'] ?? 'Transformador futuro'));
            if ($name !== '') $parts[] = $name;
            if (!empty($m['potencia_mva'])) $parts[] = rtrim(rtrim((string)$m['potencia_mva'], '0'), '.') . ' MVA';
            if (!empty($m['tension_kv'])) $parts[] = $m['tension_kv'] . ' kV';
            if (!empty($m['anio_reprogramado'])) $parts[] = 'anio ' . $m['anio_reprogramado'];
            elseif (!empty($m['anio_previsto'])) $parts[] = 'anio ' . $m['anio_previsto'];
            if (!empty($m['estado'])) $parts[] = $m['estado'];
            $names[] = implode(' - ', array_filter($parts));
        }
        $pit['comentario'] = 'La SET aparece con sobrecarga en el analisis de demanda, pero el PIT registra transformador futuro asociado'
            . ($names ? ': ' . implode('; ', array_slice($names, 0, 3)) : '.')
            . ' Verificar cronograma, titular y puesta en servicio antes de tomar decision de conexion.';
    }
    $result['pit'] = $pit;
    return $result;
}

function attachPtCoesLookup(array $result, string $nombre, string $empresa, string $departamento = ''): array {
    if (!is_file(PT_COES_ANALYZER)) {
        $result['pt_coes'] = ['status'=>'unavailable','message'=>'Analizador PT COES no instalado.'];
        return $result;
    }
    $cmd = escapeshellarg(PYTHON_BIN).' '.escapeshellarg(PT_COES_ANALYZER)
        .' --nombre '.escapeshellarg($nombre)
        .' --empresa '.escapeshellarg($empresa)
        .' --departamento '.escapeshellarg($departamento)
        .' --tipo set'
        .' --pt-dir '.escapeshellarg(PT_COES_DIR)
        .' --limit 8 2>&1';
    $out = @shell_exec($cmd);
    $pt = $out ? json_decode(trim($out), true) : null;
    if (!is_array($pt) || !isset($pt['status'])) {
        $pt = ['status'=>'unavailable','message'=>'Respuesta PT COES no valida.','raw'=>substr((string)$out, 0, 300)];
    }
    $result['pt_coes'] = $pt;
    return $result;
}

$result = null;
if (file_exists(ANALYZER)) {
    $cmd = escapeshellarg(PYTHON_BIN).' '.escapeshellarg(ANALYZER)
         .' --nombre '.escapeshellarg($nombre)
         .' --empresa '.escapeshellarg($empresa);
    if ($capacidad !== null) $cmd .= ' --capacidad '.escapeshellarg((string)$capacidad);
    if ($potInst !== null) $cmd .= ' --pot-inst '.escapeshellarg((string)$potInst);
    $cmd .= ' 2>&1';
    $out = @shell_exec($cmd);
    if ($out) {
        $dec = json_decode(trim($out), true);
        if (json_last_error()===JSON_ERROR_NONE && isset($dec['status'])) {
            $result = $dec;
        } else {
            $result = ['status'=>'error','msg'=>'Respuesta Python inválida','raw'=>substr($out,0,600)];
        }
    }
}

// ── Fallback PHP mínimo ──
if (!$result) {
    $result  = [
        'status' => 'error',
        'msg' => 'No se pudo ejecutar el analizador interno.',
        'subestacion' => ['nombre'=>$nombre],
        'timestamp'           => date('Y-m-d\TH:i:s'),
    ];
}
$result = attachPitLookup($result, $nombre, $empresa, $departamento);
$result = attachPtCoesLookup($result, $nombre, $empresa, $departamento);
if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
file_put_contents($cacheFile, json_encode([
    'cache_sig' => $cacheSig,
    'cached_at' => date('c'),
    'result' => $result,
], JSON_UNESCAPED_UNICODE));
jsonOut($result);

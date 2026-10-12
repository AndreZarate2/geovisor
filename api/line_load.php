<?php
/**
 * api/line_load.php — Carga MVA de líneas de transmisión
 */
require_once dirname(__DIR__) . '/config.php';
if (!defined('ZONE_ANALYSIS_LIB_ONLY')) define('ZONE_ANALYSIS_LIB_ONLY', true);
require_once __DIR__ . '/zone_analysis.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(90);

if (!isAuth()) { jsonOut(['status'=>'error','msg'=>'No autorizado'], 401); }

$input = $_SERVER['REQUEST_METHOD']==='POST'
    ? (json_decode(file_get_contents('php://input'), true) ?? $_POST)
    : $_GET;

$codigo = trim($input['codigo'] ?? $input['cod_linea'] ?? '');
$nombre = trim($input['nombre'] ?? '');
$empresa = trim($input['empresa'] ?? '');
$departamento = trim($input['departamento'] ?? $input['region'] ?? '');

if (!$codigo && !$nombre) jsonOut(['status'=>'error','msg'=>'Código o nombre de línea requerido']);
if ($codigo && !preg_match('/^[\p{L}\p{N}\s\/\-\.]+$/u', $codigo)) jsonOut(['status'=>'error','msg'=>'Código inválido']);
if ($nombre && !preg_match('/^[\p{L}\p{N}\s\/\-\.°"()]+$/u', $nombre)) jsonOut(['status'=>'error','msg'=>'Nombre inválido']);

function linePathSig(string $path): ?array {
    return is_file($path) ? ['size'=>filesize($path), 'mtime'=>filemtime($path)] : null;
}
function lineDirSig(string $dir, array $exts): array {
    if (!is_dir($dir)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $entry) {
        if (!$entry->isFile()) continue;
        $ext = strtolower(pathinfo($entry->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) continue;
        $path = $entry->getPathname();
        $out[] = [substr($path, strlen($dir) + 1), filesize($path), filemtime($path)];
    }
    sort($out);
    return $out;
}
$cacheSig = sha1(json_encode([
    'v' => 7,
    'codigo' => strtolower($codigo),
    'nombre' => strtolower($nombre),
    'empresa' => strtolower($empresa),
    'departamento' => strtolower($departamento),
    'analyzer' => linePathSig(LINE_ANALYZER),
    'pt_analyzer' => linePathSig(PT_COES_ANALYZER),
    'pt_coes' => lineDirSig(PT_COES_DIR, ['pdf']),
    'csv' => linePathSig(LINE_LOAD_CSV),
    'xls' => linePathSig(EXCEL_DIR . '/lineas/Potencia Aparente LLTT_2025.xls'),
], JSON_UNESCAPED_UNICODE));

$cacheKey = preg_replace('/[^a-z0-9]+/i', '_', strtolower($codigo.'_'.$nombre));
$cacheFile = CACHE_DIR.'/line_load_'.$cacheKey.'.json';
if (file_exists($cacheFile) && filemtime($cacheFile) > time() - 86400) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if (($cached['cache_sig'] ?? '') === $cacheSig && isset($cached['result'])) jsonOut($cached['result']);
}

function attachLinePtCoesLookup(array $result, string $codigo, string $nombre, string $empresa, string $departamento = ''): array {
    if (!is_file(PT_COES_ANALYZER)) {
        $result['pt_coes'] = ['status'=>'unavailable','message'=>'Analizador PT COES no instalado.'];
        return $result;
    }
    $cmd = escapeshellarg(PYTHON_BIN).' '.escapeshellarg(PT_COES_ANALYZER)
        .' --codigo '.escapeshellarg($codigo)
        .' --nombre '.escapeshellarg($nombre)
        .' --empresa '.escapeshellarg($empresa)
        .' --departamento '.escapeshellarg($departamento)
        .' --tipo line'
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

function lineLoadCodeKey(string $value): string {
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) $value = $ascii;
    return strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $value));
}

function lineLoadGeoPoints(array $geometry): array {
    $type = (string)($geometry['type'] ?? '');
    $coords = $geometry['coordinates'] ?? null;
    $points = [];
    $push = function ($coord) use (&$points) {
        if (!is_array($coord) || count($coord) < 2) return;
        $lng = zNum($coord[0] ?? null);
        $lat = zNum($coord[1] ?? null);
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return;
        $points[] = [round($lat, 7), round($lng, 7)];
    };
    if ($type === 'LineString' && is_array($coords)) {
        foreach ($coords as $coord) $push($coord);
    } elseif ($type === 'MultiLineString' && is_array($coords)) {
        foreach ($coords as $line) {
            if (!is_array($line)) continue;
            foreach ($line as $coord) $push($coord);
        }
    }
    return $points;
}

function lineLoadSamplePoints(array $points, int $limit = 14): array {
    if (count($points) <= $limit) return $points;
    $out = [];
    $last = count($points) - 1;
    for ($i = 0; $i < $limit; $i++) {
        $idx = (int)round(($last * $i) / max(1, $limit - 1));
        $out[] = $points[$idx];
    }
    return $out;
}

function lineLoadFindGeometry(string $codigo, string $nombre): ?array {
    $codeKey = lineLoadCodeKey($codigo);
    $nameKey = zNormText($nombre);
    $files = [
        DATA_DIR . '/LT_Operacionm.geojson',
        DATA_DIR . '/LT_Proyectadas.geojson',
    ];
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $data = json_decode(file_get_contents($file), true);
        $features = is_array($data['features'] ?? null) ? $data['features'] : [];
        foreach ($features as $feature) {
            if (!is_array($feature)) continue;
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $featureCodes = [
                (string)($props['COD_LINEA'] ?? ''),
                (string)($props['CODIGO'] ?? ''),
                (string)($props['codigo_linea'] ?? ''),
            ];
            $matched = false;
            foreach ($featureCodes as $candidate) {
                if ($codeKey !== '' && $codeKey !== lineLoadCodeKey('-') && lineLoadCodeKey($candidate) === $codeKey) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched && $nameKey !== '') {
                $featureName = zNormText((string)($props['NOMBRE_LINEA'] ?? $props['NOMBRE'] ?? $props['nombre'] ?? ''));
                $matched = $featureName !== '' && ($featureName === $nameKey || str_contains($featureName, $nameKey) || str_contains($nameKey, $featureName));
            }
            if (!$matched) continue;
            $points = lineLoadGeoPoints(is_array($feature['geometry'] ?? null) ? $feature['geometry'] : []);
            if (count($points) < 2) continue;
            return [
                'source'=>basename($file),
                'codigo'=>(string)($props['COD_LINEA'] ?? $props['CODIGO'] ?? $codigo),
                'nombre'=>(string)($props['NOMBRE_LINEA'] ?? $props['NOMBRE'] ?? $nombre),
                'tension_kv'=>$props['TENSION_KV'] ?? $props['TENSION_NO'] ?? null,
                'longitud_km'=>$props['LONGITUD'] ?? null,
                'points'=>$points,
            ];
        }
    }
    return null;
}

function attachLineCeraunicoAnalysis(array $result, string $codigo, string $nombre): array {
    if (isset($result['descargas_atmosfericas']) || !function_exists('zCeraunicoAnalyzePoints')) return $result;
    $operative = is_array($result['operativa'] ?? null) ? $result['operativa'] : [];
    $queryCode = trim((string)($operative['codigo_linea'] ?? $result['codigo_consulta'] ?? $codigo));
    $queryName = trim((string)($operative['nombre_linea'] ?? $result['nombre_consulta'] ?? $nombre));
    $geom = lineLoadFindGeometry($queryCode, $queryName);
    if (!$geom) {
        $result['descargas_atmosfericas'] = [
            'status'=>'unavailable',
            'fuente'=>'OSINERGMIN CERAUNICO',
            'message'=>'No se encontro geometria local de la linea para consultar CERAUNICO.',
        ];
        return $result;
    }
    $points = lineLoadSamplePoints($geom['points'], 14);
    $result['descargas_atmosfericas'] = zCeraunicoAnalyzePoints($points);
    $result['geometria_linea'] = [
        'source'=>$geom['source'],
        'codigo'=>$geom['codigo'],
        'nombre'=>$geom['nombre'],
        'tension_kv'=>$geom['tension_kv'],
        'longitud_km'=>$geom['longitud_km'],
        'muestras_ceraunico'=>count($points),
    ];
    return $result;
}

$result = null;
if (file_exists(LINE_ANALYZER)) {
    $cmd = escapeshellarg(PYTHON_BIN).' '.escapeshellarg(LINE_ANALYZER)
         .' --codigo '.escapeshellarg($codigo)
         .' --nombre '.escapeshellarg($nombre)
         .' --empresa '.escapeshellarg($empresa)
         .' 2>&1';
    $out = @shell_exec($cmd);
    if ($out) {
        $dec = json_decode(trim($out), true);
        if (json_last_error() === JSON_ERROR_NONE && isset($dec['status'])) {
            $result = $dec;
            $result = attachLinePtCoesLookup($result, $codigo, $nombre, $empresa, $departamento);
            $result = attachLineCeraunicoAnalysis($result, $codigo, $nombre);
            if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
            file_put_contents($cacheFile, json_encode([
                'cache_sig' => $cacheSig,
                'cached_at' => date('c'),
                'result' => $result,
            ], JSON_UNESCAPED_UNICODE));
        } else {
            $result = ['status'=>'error','msg'=>'Respuesta Python inválida','raw'=>substr($out,0,600)];
        }
    }
}

if (!$result) {
    $result = ['status'=>'error','msg'=>'No se pudo ejecutar el analizador de líneas.'];
}
if (is_array($result) && !isset($result['pt_coes'])) {
    $result = attachLinePtCoesLookup($result, $codigo, $nombre, $empresa, $departamento);
}
if (is_array($result) && !isset($result['descargas_atmosfericas'])) {
    $result = attachLineCeraunicoAnalysis($result, $codigo, $nombre);
}
jsonOut($result);

<?php
/** Perfil de elevacion para el Taller GEO. Servicio externo configurable y cacheado. */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');

if (!isAuth()) jsonOut(['ok'=>false, 'message'=>'No autorizado'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok'=>false, 'message'=>'Metodo no permitido'], 405);
requireCsrf();

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 128 * 1024) jsonOut(['ok'=>false, 'message'=>'Solicitud demasiado grande'], 413);
$input = json_decode($raw, true);
$coordinates = is_array($input['coordinates'] ?? null) ? array_slice($input['coordinates'], 0, 100) : [];
$points = [];
foreach ($coordinates as $coordinate) {
    if (!is_array($coordinate) || !is_numeric($coordinate[0] ?? null) || !is_numeric($coordinate[1] ?? null)) continue;
    $lat = round((float)$coordinate[0], 6); $lng = round((float)$coordinate[1], 6);
    if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
    $points[] = [$lat, $lng];
}
if (!$points) jsonOut(['ok'=>false, 'message'=>'No se recibieron coordenadas validas.'], 400);

$cacheDir = CACHE_DIR . '/elevation';
$cacheKey = hash('sha256', json_encode($points));
$cachePath = $cacheDir . '/' . $cacheKey . '.json';
if (is_file($cachePath) && filemtime($cachePath) > time() - 30 * 24 * 60 * 60) {
    $cached = json_decode((string)file_get_contents($cachePath), true);
    if (is_array($cached) && !empty($cached['ok'])) {
        $cached['cache'] = true;
        jsonOut($cached);
    }
}

$base = trim((string)getenv('ELEVATION_API_BASE')) ?: 'https://api.open-meteo.com/v1/elevation';
if (!preg_match('~^https://~i', $base)) jsonOut(['ok'=>false, 'message'=>'Proveedor de elevacion no seguro o mal configurado.'], 500);
$query = http_build_query([
    'latitude'=>implode(',', array_column($points, 0)),
    'longitude'=>implode(',', array_column($points, 1)),
]);
$apiKey = trim((string)getenv('ELEVATION_API_KEY'));
if ($apiKey !== '') $query .= '&apikey=' . rawurlencode($apiKey);
$url = $base . (str_contains($base, '?') ? '&' : '?') . $query;

$body = '';
$httpCode = 0;
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_TIMEOUT=>25,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HTTPHEADER=>['Accept: application/json', 'User-Agent: CVC-Geovisor/1.0'],
    ] + appCurlTlsOptions();
    curl_setopt_array($ch, $opts);
    $result = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (is_string($result)) $body = $result;
    if ($body === '' && $error !== '') jsonOut(['ok'=>false, 'message'=>'El servicio de elevacion no respondio: ' . substr($error, 0, 160)], 502);
} else {
    $context = stream_context_create(['http'=>['timeout'=>25, 'header'=>"Accept: application/json\r\nUser-Agent: CVC-Geovisor/1.0\r\n"], 'ssl'=>appSslStreamOptions()]);
    $result = @file_get_contents($url, false, $context);
    if (is_string($result)) $body = $result;
    $httpCode = $body !== '' ? 200 : 0;
}
if ($httpCode < 200 || $httpCode >= 300 || $body === '') jsonOut(['ok'=>false, 'message'=>'El proveedor de elevacion devolvio HTTP ' . $httpCode . '.'], 502);
$decoded = json_decode($body, true);
$elevations = is_array($decoded['elevation'] ?? null) ? $decoded['elevation'] : [];
if (count($elevations) !== count($points)) jsonOut(['ok'=>false, 'message'=>'El proveedor devolvio un perfil incompleto.'], 502);

$profile = [];
$values = [];
$distanceKm = 0.0;
$ascent = 0.0; $descent = 0.0;
foreach ($points as $idx => $point) {
    $elevation = is_numeric($elevations[$idx] ?? null) ? round((float)$elevations[$idx], 1) : null;
    if ($elevation === null) continue;
    if ($idx > 0) {
        [$lat1,$lng1] = $points[$idx - 1]; [$lat2,$lng2] = $point;
        $dLat = deg2rad($lat2-$lat1); $dLng = deg2rad($lng2-$lng1);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)**2;
        $distanceKm += 6371.0088 * 2 * atan2(sqrt($a), sqrt(max(0, 1-$a)));
        $previous = $values[count($values)-1] ?? $elevation;
        $delta = $elevation - $previous;
        if ($delta > 0) $ascent += $delta; else $descent += abs($delta);
    }
    $values[] = $elevation;
    $profile[] = ['lat'=>$point[0], 'lng'=>$point[1], 'distance_km'=>round($distanceKm, 4), 'elevation_m'=>$elevation];
}
if (!$values) jsonOut(['ok'=>false, 'message'=>'No se obtuvieron elevaciones validas.'], 502);
$response = [
    'ok'=>true,
    'provider'=>'Open-Meteo Elevation API / Copernicus DEM',
    'resolution_m'=>90,
    'profile'=>$profile,
    'min_m'=>min($values),
    'avg_m'=>round(array_sum($values) / count($values), 1),
    'max_m'=>max($values),
    'ascent_m'=>round($ascent, 1),
    'descent_m'=>round($descent, 1),
    'distance_km'=>round($distanceKm, 4),
    'cache'=>false,
    'note'=>'Estimacion DEM; no reemplaza levantamiento topografico.',
];
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0750, true);
@file_put_contents($cachePath, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
jsonOut($response);


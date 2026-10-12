<?php
/** Guarda proyectos GEO normalizados por usuario; nunca conserva el KMZ/KML original. */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');

if (!isAuth()) jsonOut(['ok'=>false, 'message'=>'No autorizado'], 401);

$username = trim((string)($_SESSION['username'] ?? 'usuario')) ?: 'usuario';
$ownerKey = substr(hash('sha256', strtolower($username)), 0, 24);
$baseDir = UPLOADS_DIR . '/geo_projects/' . $ownerKey;

function geoProjectFail(string $message, int $code = 400): void {
    jsonOut(['ok'=>false, 'message'=>$message], $code);
}

function geoProjectId(mixed $value): string {
    $id = strtolower(trim((string)$value));
    return preg_match('/^geo_[a-f0-9]{20}$/', $id) ? $id : '';
}

function geoProjectText(mixed $value, int $max = 180): string {
    $text = trim(preg_replace('/\s+/u', ' ', (string)$value) ?: '');
    return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
}

function geoProjectPoint(mixed $value): ?array {
    if (!is_array($value) || count($value) < 2 || !is_numeric($value[0]) || !is_numeric($value[1])) return null;
    $lat = round((float)$value[0], 7);
    $lng = round((float)$value[1], 7);
    if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
    return [$lat, $lng];
}

function geoProjectProperties(mixed $value): array {
    if (!is_array($value)) return [];
    $out = [];
    foreach ($value as $key => $item) {
        if (count($out) >= 50) break;
        $safeKey = preg_replace('/[^a-zA-Z0-9_ .\-]/u', '_', (string)$key) ?: '';
        if ($safeKey === '' || preg_match('/pass|secret|token|credential|api.?key|authorization/i', $safeKey)) continue;
        if (!is_scalar($item) && $item !== null) continue;
        $out[substr($safeKey, 0, 80)] = geoProjectText($item, 500);
    }
    return $out;
}

function geoProjectFeatures(mixed $value): array {
    if (!is_array($value)) return [];
    $out = [];
    $totalPoints = 0;
    foreach (array_slice($value, 0, 500) as $idx => $feature) {
        if (!is_array($feature)) continue;
        $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];
        $type = (string)($geometry['type'] ?? '');
        if (!in_array($type, ['Point','LineString','Polygon'], true)) continue;
        $points = [];
        foreach (array_slice(is_array($geometry['coordinates'] ?? null) ? $geometry['coordinates'] : [], 0, 6000) as $coordinate) {
            $point = geoProjectPoint($coordinate);
            if ($point) $points[] = $point;
        }
        $minimum = $type === 'Polygon' ? 3 : ($type === 'LineString' ? 2 : 1);
        if (count($points) < $minimum) continue;
        $totalPoints += count($points);
        if ($totalPoints > 250000) geoProjectFail('El proyecto supera el limite seguro de 250 000 vertices. Dividalo en varios proyectos.');
        $rawId = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($feature['id'] ?? '')) ?: '';
        $id = substr($rawId !== '' ? $rawId : ('geo-item-' . ($idx + 1)), 0, 90);
        $out[] = [
            'id'=>$id,
            'name'=>geoProjectText($feature['name'] ?? ('Elemento ' . ($idx + 1)), 120),
            'geometry'=>['type'=>$type, 'coordinates'=>$points],
            'properties'=>geoProjectProperties($feature['properties'] ?? []),
        ];
    }
    return $out;
}

function geoProjectData(mixed $value): array {
    if (!is_array($value)) $value = [];
    $features = geoProjectFeatures($value['features'] ?? []);
    if (!$features) geoProjectFail('El proyecto no contiene puntos, rutas o poligonos validos.');
    $ids = array_fill_keys(array_column($features, 'id'), true);
    $meta = [];
    foreach (is_array($value['meta'] ?? null) ? $value['meta'] : [] as $id => $item) {
        if (!isset($ids[$id]) || !is_array($item)) continue;
        $meta[$id] = [
            'enabled'=>!array_key_exists('enabled', $item) || (bool)$item['enabled'],
            'visible'=>!array_key_exists('visible', $item) || (bool)$item['visible'],
            'analysisType'=>geoProjectText($item['analysisType'] ?? '', 30),
            'lineType'=>geoProjectText($item['lineType'] ?? '', 30),
            'voltageKv'=>is_numeric($item['voltageKv'] ?? null) ? round((float)$item['voltageKv'], 3) : null,
            'circuit'=>geoProjectText($item['circuit'] ?? '', 20),
        ];
    }
    $scenes = [];
    foreach (array_slice(is_array($value['scenes'] ?? null) ? $value['scenes'] : [], 0, 60) as $idx => $scene) {
        if (!is_array($scene) || !is_array($scene['center'] ?? null)) continue;
        $lat = $scene['center']['lat'] ?? null; $lng = $scene['center']['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) continue;
        $scenes[] = [
            'id'=>'scene-' . ($idx + 1),
            'title'=>geoProjectText($scene['title'] ?? ('Vista ' . ($idx + 1)), 80),
            'center'=>['lat'=>round((float)$lat, 7), 'lng'=>round((float)$lng, 7)],
            'zoom'=>max(2, min(20, (int)($scene['zoom'] ?? 8))),
            'selected'=>isset($ids[(string)($scene['selected'] ?? '')]) ? (string)$scene['selected'] : '',
        ];
    }
    $documents = [];
    foreach (array_slice(is_array($value['documents'] ?? null) ? $value['documents'] : [], 0, 80) as $document) {
        if (!is_array($document)) continue;
        $documents[] = ['name'=>geoProjectText($document['name'] ?? '', 180), 'features'=>max(0, (int)($document['features'] ?? 0))];
    }
    $view = is_array($value['view'] ?? null) ? $value['view'] : [];
    $center = is_array($view['center'] ?? null) ? $view['center'] : [];
    return [
        'features'=>$features,
        'meta'=>$meta,
        'scenes'=>$scenes,
        'documents'=>$documents,
        'notice'=>geoProjectText($value['notice'] ?? '', 500),
        'view'=>[
            'center'=>['lat'=>is_numeric($center['lat'] ?? null) ? round((float)$center['lat'], 7) : -9.19, 'lng'=>is_numeric($center['lng'] ?? null) ? round((float)$center['lng'], 7) : -75.0152],
            'zoom'=>max(2, min(20, (int)($view['zoom'] ?? 6))),
        ],
    ];
}

function geoProjectRead(string $path): ?array {
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = geoProjectId($_GET['id'] ?? '');
    if ($id !== '') {
        $path = $baseDir . '/' . $id . '.json';
        $project = is_file($path) ? geoProjectRead($path) : null;
        if (!$project) geoProjectFail('Proyecto no encontrado.', 404);
        jsonOut(['ok'=>true, 'project'=>$project]);
    }
    $projects = [];
    foreach (glob($baseDir . '/geo_*.json') ?: [] as $path) {
        $project = geoProjectRead($path);
        if (!$project) continue;
        $projects[] = [
            'id'=>$project['id'] ?? basename($path, '.json'),
            'name'=>$project['name'] ?? 'Proyecto GEO',
            'feature_count'=>count($project['data']['features'] ?? []),
            'updated_at'=>$project['updated_at'] ?? '',
        ];
    }
    usort($projects, fn($a, $b) => strcmp((string)$b['updated_at'], (string)$a['updated_at']));
    jsonOut(['ok'=>true, 'projects'=>array_slice($projects, 0, 100)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') geoProjectFail('Metodo no permitido.', 405);
requireCsrf();
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 12 * 1024 * 1024) geoProjectFail('El proyecto supera el limite de 12 MB.');
$input = json_decode($raw, true);
if (!is_array($input) || ($input['action'] ?? '') !== 'save') geoProjectFail('Solicitud invalida.');
$name = geoProjectText($input['name'] ?? '', 90);
if ($name === '') geoProjectFail('Indique un nombre para el proyecto.');
$id = geoProjectId($input['id'] ?? '');
if ($id === '') $id = 'geo_' . bin2hex(random_bytes(10));
$data = geoProjectData($input['project'] ?? []);
if (!is_dir($baseDir) && !@mkdir($baseDir, 0750, true) && !is_dir($baseDir)) geoProjectFail('No se pudo crear el almacenamiento de proyectos.', 500);
$path = $baseDir . '/' . $id . '.json';
$existing = is_file($path) ? geoProjectRead($path) : null;
$project = [
    'id'=>$id,
    'name'=>$name,
    'owner'=>$username,
    'created_at'=>$existing['created_at'] ?? date('c'),
    'updated_at'=>date('c'),
    'data'=>$data,
];
$encoded = json_encode($project, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($encoded)) geoProjectFail('No se pudo serializar el proyecto.', 500);
$tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
if (@file_put_contents($tmp, $encoded, LOCK_EX) === false || !@rename($tmp, $path)) {
    @unlink($tmp);
    geoProjectFail('No se pudo guardar el proyecto.', 500);
}
jsonOut(['ok'=>true, 'project'=>['id'=>$id, 'name'=>$name, 'feature_count'=>count($data['features']), 'updated_at'=>$project['updated_at']]]);

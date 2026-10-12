<?php
/**
 * api/zone_analysis.php - Viabilidad de zona dibujada y zonas sugeridas
 */
require_once dirname(__DIR__) . '/config.php';
@set_time_limit(300);
@ini_set('memory_limit', '1024M');
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

if (!defined('ZONE_ANALYSIS_LIB_ONLY')) {
    header('Content-Type: application/json; charset=utf-8');
    if (!isAuth()) { jsonOut(['status'=>'error','msg'=>'No autorizado'], 401); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrf();

    $rawBody = file_get_contents('php://input');
    $jsonBody = $rawBody ? json_decode($rawBody, true) : null;
    $input = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (is_array($jsonBody) ? $jsonBody : $_POST)
        : $_GET;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

function zNum($value): ?float {
    if ($value === null || $value === '') return null;
    if (!is_numeric($value)) return null;
    return (float)$value;
}

function zClamp(float $value, float $min, float $max): float {
    return max($min, min($max, $value));
}

function zPick(array $props, array $keys, string $fallback = ''): string {
    foreach ($keys as $key) {
        if (isset($props[$key]) && trim((string)$props[$key]) !== '') return trim((string)$props[$key]);
    }
    return $fallback;
}

function zReadGeoJson(string $path): array {
    if (!is_file($path)) {
        $resolved = zResolveGeoJsonPath($path);
        if (!$resolved) return [];
        $path = $resolved;
    }
    $lower = strtolower($path);
    $isGz = str_ends_with($lower, '.gz');
    $size = is_file($path) ? (int)filesize($path) : 0;
    if ($isGz && $size > 18 * 1024 * 1024) return [];
    if (!$isGz && $size > 30 * 1024 * 1024) return [];
    $raw = (string)file_get_contents($path);
    if ($isGz) {
        $decoded = function_exists('gzdecode') ? @gzdecode($raw) : false;
        if ($decoded === false) return [];
        $raw = $decoded;
    }
    if (strlen($raw) > 36 * 1024 * 1024) return [];
    $data = json_decode($raw, true);
    return is_array($data) && isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
}

function zResolveGeoJsonPath(string $idOrPath): ?string {
    if (is_file($idOrPath)) return $idOrPath;
    $id = normalizeLayerId(pathinfo($idOrPath, PATHINFO_FILENAME) ?: $idOrPath);
    if ($id === '') return null;
    $candidates = [];
    foreach ([
        DATA_DIR . '/' . $id . '.geojson',
        DATA_DIR . '/' . $id . '.json',
        CACHE_DIR . '/' . $id . '.geojson',
        CACHE_DIR . '/' . $id . '.json',
        CACHE_DIR . '/' . $id . '.geojson.gz',
        CACHE_DIR . '/' . $id . '.json.gz',
    ] as $candidate) {
        if (is_file($candidate)) $candidates[] = $candidate;
    }
    if (!$candidates) return null;
    usort($candidates, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $candidates[0];
}

function zLayerPath(string $id): string {
    return zResolveGeoJsonPath($id) ?: DATA_DIR . '/' . normalizeLayerId($id) . '.geojson';
}

function zSourceScanPath(array $source): ?string {
    $path = zResolveGeoJsonPath((string)($source['path'] ?? ''));
    return $path && is_file($path) ? $path : null;
}

function zShouldScanSource(array $source, string $path): bool {
    $kind = (string)($source['kind'] ?? '');
    $size = is_file($path) ? filesize($path) : 0;
    if ($kind === 'set' || $kind === 'line' || $kind === 'concession') return $size <= 120 * 1024 * 1024;
    if ($kind === 'mt' || $kind === 'sed') return $size <= 80 * 1024 * 1024;
    if (in_array($kind, ['predio', 'cultural', 'ambiental', 'productivo'], true)) return $size <= 80 * 1024 * 1024;
    return $size <= 20 * 1024 * 1024;
}

function zSources(): array {
    static $resolvedSources = null;
    if (is_array($resolvedSources)) return $resolvedSources;
    $sources = [
        ['path'=>CACHE_DIR . '/osinergmin_18.geojson', 'kind'=>'set', 'label'=>'SET OSINERGMIN'],
        ['path'=>zLayerPath('SET_Operacion'), 'kind'=>'set', 'label'=>'SET local'],
        ['path'=>zLayerPath('SET_Proyectadas'), 'kind'=>'set', 'label'=>'SET proyectada'],
        ['path'=>CACHE_DIR . '/osinergmin_15.geojson', 'kind'=>'line', 'label'=>'LT OSINERGMIN'],
        ['path'=>zLayerPath('LT_Operacionm'), 'kind'=>'line', 'label'=>'LT local'],
        ['path'=>CACHE_DIR . '/osinergmin_22.geojson', 'kind'=>'mt', 'label'=>'MT OSINERGMIN'],
        ['path'=>zLayerPath('Tramo_media_tension'), 'kind'=>'mt', 'label'=>'Media tension'],
        ['path'=>zLayerPath('SED_Esc_1_30000'), 'kind'=>'sed', 'label'=>'SED'],
        ['path'=>zLayerPath('SED_Esc_1_9000'), 'kind'=>'sed', 'label'=>'SED'],
        ['path'=>CACHE_DIR . '/osinergmin_33.geojson', 'kind'=>'concession', 'label'=>'Concesión OSINERGMIN'],
        ['path'=>zLayerPath('Concesiones_Distribucion_vigente'), 'kind'=>'concession', 'label'=>'Concesión vigente'],
        ['path'=>zLayerPath('Concesiones_Distribucion_tramite'), 'kind'=>'concession', 'label'=>'Concesión en trámite'],
    ];
    $seenPaths = [];
    foreach ($sources as $source) $seenPaths[realpath($source['path']) ?: $source['path']] = true;
    foreach (getLocalLayerRegistry(true) as $layer) {
        $id = (string)($layer['id'] ?? '');
        if (preg_match('/^Concesiones_Distribucion_(vigente|tramite)_(rural|definitiva)$/', $id)) continue;
        $path = localLayerPath($id);
        if (!$path) continue;
        $key = realpath($path) ?: $path;
        if (isset($seenPaths[$key])) continue;
        $kind = zLayerKind($layer, 'external');
        $sources[] = ['path'=>$path, 'kind'=>$kind, 'label'=>$layer['nombre'] ?? $layer['id']];
        $seenPaths[$key] = true;
    }
    foreach (getApiRegistry(true) as $api) {
        $path = EXTERNAL_LAYER_CACHE_DIR . '/' . $api['id'] . '.geojson';
        if (is_file($path)) {
            $sources[] = ['path'=>$path, 'kind'=>zLayerKind($api, 'external'), 'label'=>'API externa: ' . $api['name']];
        }
    }
    $resolvedSources = $sources;
    return $resolvedSources;
}

function zAnalysisSourceSignature(string $version = 'analysis-v5'): string {
    static $memo = [];
    if (isset($memo[$version])) return $memo[$version];
    $parts = [$version, (string)@filemtime(__FILE__), (string)@filesize(__FILE__)];
    foreach ([DATA_DIR, EXTERNAL_LAYER_CACHE_DIR, CACHE_DIR . '/analysis_index', PIT_DIR, PT_COES_DIR, EXCEL_DIR] as $dir) {
        if (!is_dir($dir)) continue;
        $parts[] = (realpath($dir) ?: $dir) . '|' . (int)@filemtime($dir);
    }
    foreach ([15, 16, 18, 19, 21, 22, 33] as $layerId) {
        $path = CACHE_DIR . '/osinergmin_' . $layerId . '.geojson';
        if (!is_file($path)) continue;
        $parts[] = basename($path) . '|' . (int)@filemtime($path) . '|' . (int)@filesize($path);
    }
    return $memo[$version] = sha1(implode("\n", $parts));
}

function zAnalysisCacheKey(string $namespace, array $payload, string $version = 'analysis-v5'): string {
    return sha1($namespace . '|' . zAnalysisSourceSignature($version) . '|' . (json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($payload)));
}

function zAnalysisCachePath(string $namespace, string $key): string {
    $safe = preg_replace('/[^a-z0-9_-]+/i', '_', strtolower($namespace)) ?: 'analysis';
    return CACHE_DIR . '/analysis_result_' . $safe . '_' . $key . '.json';
}

function zAnalysisCacheLoad(string $namespace, string $key, int $ttlSeconds): ?array {
    $path = zAnalysisCachePath($namespace, $key);
    if (!is_file($path) || filemtime($path) < time() - max(60, $ttlSeconds)) return null;
    $stored = json_decode((string)@file_get_contents($path), true);
    if (!is_array($stored) || !is_array($stored['data'] ?? null)) return null;
    $data = $stored['data'];
    $data['_analysis_cache'] = [
        'key'=>$key,
        'hit'=>true,
        'created_at'=>$stored['created_at'] ?? null,
    ];
    return $data;
}

function zAnalysisCacheStore(string $namespace, string $key, array $data): array {
    $dir = dirname(zAnalysisCachePath($namespace, $key));
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $createdAt = date('c');
    $clean = $data;
    unset($clean['_analysis_cache']);
    $path = zAnalysisCachePath($namespace, $key);
    $tmp = $path . '.' . getmypid() . '.tmp';
    $json = json_encode(['created_at'=>$createdAt, 'data'=>$clean], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (is_string($json) && @file_put_contents($tmp, $json, LOCK_EX) !== false) {
        if (!@rename($tmp, $path)) {
            @copy($tmp, $path);
            @unlink($tmp);
        }
    }
    $data['_analysis_cache'] = ['key'=>$key, 'hit'=>false, 'created_at'=>$createdAt];
    return $data;
}

function zAnalysisCacheGetOrCompute(string $namespace, array $payload, callable $compute, int $ttlSeconds = 604800, string $version = 'analysis-v5'): array {
    $key = zAnalysisCacheKey($namespace, $payload, $version);
    $cached = zAnalysisCacheLoad($namespace, $key, $ttlSeconds);
    if ($cached !== null) return $cached;
    $data = $compute();
    if (!is_array($data)) $data = ['status'=>'error', 'msg'=>'El analisis no devolvio un resultado valido.'];
    if (($data['status'] ?? 'success') === 'error') {
        $data['_analysis_cache'] = ['key'=>$key, 'hit'=>false, 'stored'=>false];
        return $data;
    }
    return zAnalysisCacheStore($namespace, $key, $data);
}

function zOpportunityJobDir(): string {
    return CACHE_DIR . '/opportunity_jobs';
}

function zOpportunityQueue(string $key, array $payload): array {
    $dir = zOpportunityJobDir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return ['queued'=>false, 'message'=>'No se pudo crear la cola de análisis.'];
    }
    $jobPath = $dir . '/' . $key . '.json';
    $lockPath = $jobPath . '.lock';
    if (is_file($lockPath) && (int)@filemtime($lockPath) > time() - 900) {
        return ['queued'=>true, 'running'=>true];
    }
    if (is_file($lockPath)) @unlink($lockPath);
    if (is_file($jobPath)) {
        $previous = json_decode((string)@file_get_contents($jobPath), true);
        $finishedAt = is_array($previous) ? strtotime((string)($previous['finished_at'] ?? '')) : false;
        if (($previous['status'] ?? '') === 'error' && $finishedAt && $finishedAt > time() - 300) {
            return ['queued'=>false, 'message'=>(string)($previous['error'] ?? 'El análisis en segundo plano falló.')];
        }
    }

    $job = [
        'key'=>$key,
        'payload'=>$payload,
        'status'=>'queued',
        'created_at'=>date('c'),
    ];
    $tmp = $jobPath . '.' . getmypid() . '.tmp';
    $encoded = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($encoded) || @file_put_contents($tmp, $encoded, LOCK_EX) === false || !@rename($tmp, $jobPath)) {
        @unlink($tmp);
        return ['queued'=>false, 'message'=>'No se pudo registrar el análisis pendiente.'];
    }
    $lock = @fopen($lockPath, 'x');
    if (!is_resource($lock)) return ['queued'=>true, 'running'=>true];
    fwrite($lock, (string)getmypid());
    fclose($lock);

    $worker = dirname(__DIR__) . '/scripts/opportunities_worker.php';
    if (!is_file($worker)) {
        @unlink($lockPath);
        return ['queued'=>false, 'message'=>'No se encontró el procesador de oportunidades.'];
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $quote = static fn(string $value): string => '"' . str_replace('"', '""', $value) . '"';
        $command = 'cmd.exe /D /S /C start "" /B '
            . $quote(PHP_BINARY) . ' ' . $quote($worker) . ' ' . $quote($jobPath)
            . ' >NUL 2>&1';
    } else {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($jobPath)
            . ' >/dev/null 2>&1 &';
    }
    $handle = @popen($command, 'r');
    if (!is_resource($handle)) {
        @unlink($lockPath);
        return ['queued'=>false, 'message'=>'No se pudo iniciar el análisis en segundo plano.'];
    }
    @pclose($handle);
    return ['queued'=>true, 'running'=>false];
}

function zNormalizePolygon($raw): array {
    if (!is_array($raw)) return [];
    $points = [];
    foreach ($raw as $p) {
        $lat = null; $lng = null;
        if (is_array($p)) {
            if (array_key_exists('lat', $p) && array_key_exists('lng', $p)) {
                $lat = zNum($p['lat']); $lng = zNum($p['lng']);
            } elseif (count($p) >= 2) {
                $lat = zNum($p[0]); $lng = zNum($p[1]);
            }
        }
        if ($lat === null || $lng === null) continue;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
        $points[] = [$lat, $lng];
    }
    if (count($points) > 2) {
        $first = $points[0];
        $last = $points[count($points) - 1];
        if (abs($first[0] - $last[0]) < 0.000001 && abs($first[1] - $last[1]) < 0.000001) {
            array_pop($points);
        }
    }
    return $points;
}

function zPolygonBbox(array $points): array {
    $lats = array_column($points, 0);
    $lngs = array_column($points, 1);
    return [
        'minLat'=>min($lats), 'maxLat'=>max($lats),
        'minLng'=>min($lngs), 'maxLng'=>max($lngs),
    ];
}

function zBboxIntersects(array $a, array $b, float $pad = 0.0): bool {
    return !(
        $a['maxLat'] < $b['minLat'] - $pad ||
        $a['minLat'] > $b['maxLat'] + $pad ||
        $a['maxLng'] < $b['minLng'] - $pad ||
        $a['minLng'] > $b['maxLng'] + $pad
    );
}

function zPointInPolygon(float $lat, float $lng, array $poly): bool {
    $inside = false;
    $count = count($poly);
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $yi = $poly[$i][0]; $xi = $poly[$i][1];
        $yj = $poly[$j][0]; $xj = $poly[$j][1];
        $cross = (($yi > $lat) !== ($yj > $lat));
        if ($cross) {
            $xAtY = ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi;
            if ($lng < $xAtY) $inside = !$inside;
        }
    }
    return $inside;
}

function zOrientation(array $a, array $b, array $c): float {
    return (($b[1] - $a[1]) * ($c[0] - $a[0])) - (($b[0] - $a[0]) * ($c[1] - $a[1]));
}

function zOnSegment(array $a, array $b, array $c): bool {
    return min($a[0], $b[0]) - 1e-10 <= $c[0] && $c[0] <= max($a[0], $b[0]) + 1e-10
        && min($a[1], $b[1]) - 1e-10 <= $c[1] && $c[1] <= max($a[1], $b[1]) + 1e-10
        && abs(zOrientation($a, $b, $c)) < 1e-10;
}

function zSegmentsIntersect(array $a, array $b, array $c, array $d): bool {
    $o1 = zOrientation($a, $b, $c);
    $o2 = zOrientation($a, $b, $d);
    $o3 = zOrientation($c, $d, $a);
    $o4 = zOrientation($c, $d, $b);
    if (($o1 > 0) !== ($o2 > 0) && ($o3 > 0) !== ($o4 > 0)) return true;
    return zOnSegment($a, $b, $c) || zOnSegment($a, $b, $d) || zOnSegment($c, $d, $a) || zOnSegment($c, $d, $b);
}

function zPolygonsIntersect(array $a, array $b): bool {
    if (count($a) < 3 || count($b) < 3) return false;
    if (!zBboxIntersects(zPolygonBbox($a), zPolygonBbox($b))) return false;
    foreach ($a as $p) if (zPointInPolygon($p[0], $p[1], $b)) return true;
    foreach ($b as $p) if (zPointInPolygon($p[0], $p[1], $a)) return true;
    $na = count($a); $nb = count($b);
    for ($i = 0; $i < $na; $i++) {
        $a1 = $a[$i]; $a2 = $a[($i + 1) % $na];
        for ($j = 0; $j < $nb; $j++) {
            if (zSegmentsIntersect($a1, $a2, $b[$j], $b[($j + 1) % $nb])) return true;
        }
    }
    return false;
}

function zDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a)));
}

function zPointSegmentDistanceKm(array $p, array $a, array $b): float {
    $lat0 = deg2rad(($a[0] + $b[0] + $p[0]) / 3.0);
    $toXY = static fn(array $q): array => [$q[1] * 111.320 * cos($lat0), $q[0] * 110.574];
    [$px, $py] = $toXY($p);
    [$ax, $ay] = $toXY($a);
    [$bx, $by] = $toXY($b);
    $dx = $bx - $ax;
    $dy = $by - $ay;
    $den = $dx * $dx + $dy * $dy;
    $t = $den > 0 ? max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $den)) : 0.0;
    $x = $ax + $t * $dx;
    $y = $ay + $t * $dy;
    return sqrt(($px - $x) ** 2 + ($py - $y) ** 2);
}

function zPointPolygonDistanceKm(array $point, array $polygon): float {
    if (count($polygon) < 3) return INF;
    if (zPointInPolygon($point[0], $point[1], $polygon)) return 0.0;
    $best = INF;
    $n = count($polygon);
    for ($i = 0; $i < $n; $i++) {
        $best = min($best, zPointSegmentDistanceKm($point, $polygon[$i], $polygon[($i + 1) % $n]));
    }
    return $best;
}

function zCentroid(array $points): array {
    $lat = 0.0; $lng = 0.0;
    foreach ($points as $p) { $lat += $p[0]; $lng += $p[1]; }
    $n = max(1, count($points));
    return [$lat / $n, $lng / $n];
}

function zAreaKm2(array $points): float {
    $center = zCentroid($points);
    $lat0 = deg2rad($center[0]);
    $xy = [];
    foreach ($points as $p) {
        $xy[] = [$p[1] * 111.320 * cos($lat0), $p[0] * 110.574];
    }
    $sum = 0.0;
    $n = count($xy);
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $sum += $xy[$i][0] * $xy[$j][1] - $xy[$j][0] * $xy[$i][1];
    }
    return abs($sum) / 2.0;
}

function zCollectCoords($coords, array &$out, int $limit = 20000): void {
    if (count($out) >= $limit || !is_array($coords) || empty($coords)) return;
    if (isset($coords[0], $coords[1]) && is_numeric($coords[0]) && is_numeric($coords[1])) {
        $lng = (float)$coords[0];
        $lat = (float)$coords[1];
        if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) $out[] = [$lat, $lng];
        return;
    }
    foreach ($coords as $part) {
        zCollectCoords($part, $out, $limit);
        if (count($out) >= $limit) return;
    }
}

function zFeatureCoords(array $feature, int $limit = 20000): array {
    $coords = [];
    $geom = $feature['geometry'] ?? null;
    if (is_array($geom) && isset($geom['coordinates'])) zCollectCoords($geom['coordinates'], $coords, $limit);
    return $coords;
}

function zGeoRingToLatLng(array $ring): array {
    $out = [];
    foreach ($ring as $pt) {
        if (!is_array($pt) || count($pt) < 2 || !is_numeric($pt[0]) || !is_numeric($pt[1])) continue;
        $lng = (float)$pt[0];
        $lat = (float)$pt[1];
        if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) $out[] = [$lat, $lng];
    }
    if (count($out) > 2) {
        $first = $out[0];
        $last = $out[count($out) - 1];
        if (abs($first[0] - $last[0]) < 0.000001 && abs($first[1] - $last[1]) < 0.000001) array_pop($out);
    }
    return $out;
}

function zFeaturePolygonRings(array $feature, int $limit = 20000): array {
    $geom = $feature['geometry'] ?? null;
    if (!is_array($geom) || !isset($geom['coordinates'])) return [];
    $type = (string)($geom['type'] ?? '');
    $rings = [];
    if ($type === 'Polygon') {
        $ring = zGeoRingToLatLng($geom['coordinates'][0] ?? []);
        if (count($ring) >= 3) $rings[] = $ring;
    } elseif ($type === 'MultiPolygon') {
        foreach ($geom['coordinates'] as $poly) {
            $ring = zGeoRingToLatLng($poly[0] ?? []);
            if (count($ring) >= 3) $rings[] = $ring;
            if (count($rings) >= $limit) break;
        }
    }
    return $rings;
}

function zCoordsBbox(array $coords): ?array {
    if (!$coords) return null;
    $lats = array_column($coords, 0);
    $lngs = array_column($coords, 1);
    return [
        'minLat'=>min($lats), 'maxLat'=>max($lats),
        'minLng'=>min($lngs), 'maxLng'=>max($lngs),
    ];
}

function zAnyCoordInside(array $coords, array $polygon, int $checks = 700): bool {
    if (!$coords) return false;
    $step = max(1, (int)floor(count($coords) / max(1, $checks)));
    for ($i = 0; $i < count($coords); $i += $step) {
        if (zPointInPolygon($coords[$i][0], $coords[$i][1], $polygon)) return true;
    }
    return false;
}

function zFeatureTouchesPolygon(array $coords, ?array $bbox, array $polygon, array $polyBbox): bool {
    if (!$coords || !$bbox) return false;
    if (!zBboxIntersects($bbox, $polyBbox)) return false;
    if (count($coords) === 1) return zPointInPolygon($coords[0][0], $coords[0][1], $polygon);
    if (zAnyCoordInside($coords, $polygon)) return true;
    $first = $coords[0];
    $last = $coords[count($coords) - 1];
    $closed = abs($first[0] - $last[0]) < 0.000001 && abs($first[1] - $last[1]) < 0.000001;
    if ($closed && isset($polygon[0]) && zPointInPolygon($polygon[0][0], $polygon[0][1], $coords)) return true;
    $n = count($coords);
    $m = count($polygon);
    for ($i = 0; $i < $n - 1; $i++) {
        $a = $coords[$i];
        $b = $coords[$i + 1];
        for ($j = 0; $j < $m; $j++) {
            if (zSegmentsIntersect($a, $b, $polygon[$j], $polygon[($j + 1) % $m])) return true;
        }
    }
    if ($closed) {
        $a = $coords[$n - 1];
        $b = $coords[0];
        for ($j = 0; $j < $m; $j++) {
            if (zSegmentsIntersect($a, $b, $polygon[$j], $polygon[($j + 1) % $m])) return true;
        }
    }
    return false;
}

function zSamplePointsInPolygon(array $polygon, int $target = 196): array {
    $bbox = zCoordsBbox($polygon);
    if (!$bbox) return [];
    $grid = max(6, (int)ceil(sqrt($target)));
    $latStep = ($bbox['maxLat'] - $bbox['minLat']) / $grid;
    $lngStep = ($bbox['maxLng'] - $bbox['minLng']) / $grid;
    if ($latStep <= 0 || $lngStep <= 0) return [zCentroid($polygon)];
    $points = [];
    for ($i = 0; $i < $grid; $i++) {
        for ($j = 0; $j < $grid; $j++) {
            $lat = $bbox['minLat'] + ($i + 0.5) * $latStep;
            $lng = $bbox['minLng'] + ($j + 0.5) * $lngStep;
            if (zPointInPolygon($lat, $lng, $polygon)) $points[] = [$lat, $lng];
        }
    }
    return $points ?: [zCentroid($polygon)];
}

function zPredioAnalysis(array $polygon, float $areaKm2): array {
    $polyBbox = zPolygonBbox($polygon);
    $records = [];
    $rings = [];
    $seen = [];
    foreach (zSources() as $source) {
        if (($source['kind'] ?? '') !== 'predio') continue;
        $path = zSourceScanPath($source);
        if (!$path || !zShouldScanSource($source, $path)) continue;
        foreach (zReadGeoJson($path) as $feature) {
            if (!is_array($feature)) continue;
            $coords = zFeatureCoords($feature, 20000);
            $bbox = zCoordsBbox($coords);
            if (!$coords || !$bbox || !zBboxIntersects($bbox, $polyBbox)) continue;
            if (!zFeatureTouchesPolygon($coords, $bbox, $polygon, $polyBbox)) continue;
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $label = zPick($props, ['DENOM','TITULAR','PART_REG','COND_JUR','CLASIF1'], 'Predio identificado');
            $key = zNormText($label . '|' . ($props['PART_REG'] ?? '') . '|' . ($props['OBJECTID'] ?? ''));
            if ($key !== '' && isset($seen[$key])) continue;
            if ($key !== '') $seen[$key] = true;
            $records[] = [
                'denominacion'=>zPick($props, ['DENOM','OBS'], $label),
                'titular'=>zPick($props, ['TITULAR','PROPIETARIO','NOMBRE'], 's/d'),
                'partida'=>zPick($props, ['PART_REG','PARTIDA','NUM_PART'], 's/d'),
                'clasificacion'=>trim(zPick($props, ['CLASIF1'], '') . ' ' . zPick($props, ['COND_JUR'], '')),
                'area_grafica_ha'=>zNum($props['AR_GRAF_HA'] ?? ($props['AREA_HA'] ?? null)),
                'fuente'=>$source['label'],
            ];
            foreach (zFeaturePolygonRings($feature, 8) as $ring) {
                $rb = zCoordsBbox($ring);
                if ($rb && zBboxIntersects($rb, $polyBbox)) $rings[] = ['bbox'=>$rb, 'ring'=>$ring];
                if (count($rings) >= 120) break 2;
            }
            if (count($records) >= 40) break 2;
        }
    }
    $samplePoints = zSamplePointsInPolygon($polygon, 225);
    $covered = 0;
    foreach ($samplePoints as $pt) {
        foreach ($rings as $r) {
            $b = $r['bbox'];
            if ($pt[0] < $b['minLat'] || $pt[0] > $b['maxLat'] || $pt[1] < $b['minLng'] || $pt[1] > $b['maxLng']) continue;
            if (zPointInPolygon($pt[0], $pt[1], $r['ring'])) {
                $covered++;
                break;
            }
        }
    }
    $pct = $samplePoints ? round(($covered / count($samplePoints)) * 100, 1) : 0.0;
    $owners = [];
    foreach ($records as $row) {
        $owner = trim((string)($row['titular'] ?? ''));
        if ($owner !== '' && $owner !== '-' && !in_array($owner, $owners, true)) $owners[] = $owner;
    }
    return [
        'status'=>$records ? 'success' : 'no_data',
        'criterio'=>'Cobertura aproximada por muestreo espacial usando solo la API de predios cargada en la plataforma.',
        'predios_identificados'=>count($records),
        'porcentaje_area_con_predio'=>min(100.0, $pct),
        'area_con_predio_ha'=>round($areaKm2 * 100.0 * min(100.0, $pct) / 100.0, 2),
        'propietarios'=>array_slice($owners, 0, 12),
        'registros'=>array_slice($records, 0, 12),
    ];
}

function zDemandProfile(array $points, array $scan, float $areaKm2): array {
    $center = $scan['centro'] ?? zCentroid($points);
    $radius = max(8.0, min(70.0, sqrt(max(0.01, $areaKm2)) * 18.0));
    $samples = [];
    try {
        $samples = zProductiveSamplesNear((float)$center[0], (float)$center[1], $radius, []);
    } catch (Throwable $e) {
        $samples = [];
    }
    $types = [];
    $areaHa = 0.0;
    foreach ($samples as $s) {
        $kind = (string)($s['kind'] ?? '');
        if ($kind === 'agricola') $types['Agricola'] = true;
        if ($kind === 'acuicola') $types['Acuicola'] = true;
        $areaHa += (float)($s['area_ha'] ?? 0);
    }
    if (($scan['indicadores']['productivo_intersecta'] ?? 0) > 0) $types['Productiva por capas cargadas'] = true;
    if (($scan['indicadores']['predios_intersectan'] ?? 0) > 0 && !$types) $types['Predial / por confirmar en campo'] = true;
    $typeList = array_keys($types);
    $tipo = $typeList ? implode(' / ', array_slice($typeList, 0, 3)) : 'No determinado con capas cargadas';
    $comentario = $typeList
        ? 'Perfil inferido desde capas MIDAGRI/PRODUCE, predios y evidencias productivas cargadas; validar giro real de clientes en campo.'
        : 'No se detectaron capas productivas suficientes para clasificar el tipo de demanda; validar usuarios y actividad economica en campo.';
    return [
        'status'=>$typeList ? 'success' : 'no_data',
        'tipo_principal'=>$tipo,
        'tipo_probable'=>$tipo,
        'clientes_identificados'=>count($samples),
        'area_productiva_ha'=>round($areaHa, 2),
        'radio_busqueda_km'=>round($radius, 1),
        'comentario'=>$comentario,
        'muestras'=>array_slice(array_map(fn($s) => [
            'tipo'=>($s['kind'] ?? ''),
            'nombre'=>($s['name'] ?? ''),
            'fuente'=>($s['source'] ?? ''),
            'area_ha'=>round((float)($s['area_ha'] ?? 0), 2),
            'departamento'=>($s['departamento'] ?? ''),
            'provincia'=>($s['provincia'] ?? ''),
            'distrito'=>($s['distrito'] ?? ''),
        ], $samples), 0, 10),
    ];
}

function zRestrictionsSummary(array $scan, array $relief, array $predios, array $ceraunico = []): array {
    $ind = $scan['indicadores'] ?? [];
    $samples = $scan['muestras'] ?? [];
    $rows = [];
    if (($relief['status'] ?? '') === 'success') {
        $rows[] = [
            'tipo'=>'Relieve / pendiente referencial',
            'nivel'=>($relief['riesgo'] ?? 'medio'),
            'detalle'=>($relief['descripcion'] ?? 'Relieve consultado con servicio externo.'),
            'muestra'=>'Elevacion ' . ($relief['min_m'] ?? 's/d') . ' - ' . ($relief['max_m'] ?? 's/d') . ' m',
        ];
    }
    foreach ([
        ['cultural_intersecta', 'cultural', 'Sitios arqueologicos / CIRA', 'alto'],
        ['ambiental_intersecta', 'ambiental', 'Areas protegidas o ecosistemas fragiles', 'alto'],
        ['concesiones_intersectan', 'concesiones', 'Concesiones vigentes o en tramite', 'medio'],
        ['mt_intersecta', 'mt', 'Redes de media tension existentes', 'medio'],
        ['sed_dentro', 'sed', 'SED dentro del poligono', 'medio'],
        ['predios_intersectan', 'predios', 'Predios identificados por API', 'informativo'],
    ] as $cfg) {
        [$key, $sampleKey, $label, $level] = $cfg;
        $count = (int)($ind[$key] ?? 0);
        if ($count <= 0) continue;
        $rows[] = [
            'tipo'=>$label,
            'nivel'=>$level,
            'detalle'=>$count . ' cruce(s) o coincidencia(s) en capas cargadas.',
            'muestra'=>implode(', ', array_slice($samples[$sampleKey] ?? [], 0, 4)) ?: 's/d',
        ];
    }
    if (($ceraunico['status'] ?? '') === 'success') {
        $maxDa = (float)($ceraunico['max'] ?? 0);
        $risk = zCeraunicoRiskLevel($maxDa);
        $level = in_array($risk, ['alto','muy alto','critico','extremo'], true) ? 'alto' : ($maxDa >= 5 ? 'medio' : 'bajo');
        $rows[] = [
            'tipo'=>'Descargas atmosfericas CERAUNICO',
            'nivel'=>$level,
            'detalle'=>'Densidad maxima referencial: ' . round($maxDa, 2) . ' rayos/km2/año; promedio: ' . round((float)($ceraunico['promedio'] ?? 0), 2) . ' rayos/km2/año; nivel ' . $risk . '.',
            'muestra'=>($ceraunico['comentario'] ?? 'Fuente OSINERGMIN CERAUNICO.') . ' Clase maxima: ' . ($ceraunico['clase_maxima'] ?? 's/d') . '.',
        ];
    }
    $penalty = 0;
    foreach ($rows as $r) {
        $level = strtolower((string)($r['nivel'] ?? ''));
        $penalty += $level === 'alto' ? 18 : ($level === 'medio' ? 10 : 3);
    }
    return [
        'status'=>$rows ? 'success' : 'no_data',
        'area_util_referencial_pct'=>max(0, min(100, 100 - $penalty)),
        'criterio'=>'Estimacion preliminar; el porcentaje util debe verificarse con capas oficiales actualizadas y visita de campo.',
        'items'=>$rows ?: [[
            'tipo'=>'Sin restricciones criticas detectadas',
            'nivel'=>'bajo',
            'detalle'=>'No se identificaron cruces relevantes en las capas cargadas.',
            'muestra'=>'Requiere validacion de campo.',
        ]],
    ];
}

function zDistributionNetworkSummary(array $scan): array {
    $ind = $scan['indicadores'] ?? [];
    $samples = $scan['muestras'] ?? [];
    return [
        'mt_intersecta'=>(int)($ind['mt_intersecta'] ?? 0),
        'sed_dentro'=>(int)($ind['sed_dentro'] ?? 0),
        'lt_intersecta'=>(int)($ind['lineas_intersectan'] ?? 0),
        'muestras_mt'=>array_slice($samples['mt'] ?? [], 0, 8),
        'muestras_sed'=>array_slice($samples['sed'] ?? [], 0, 8),
        'muestras_lt'=>array_slice($samples['lineas'] ?? [], 0, 8),
        'comentario'=>((int)($ind['mt_intersecta'] ?? 0) + (int)($ind['sed_dentro'] ?? 0)) > 0
            ? 'Existen redes de distribucion o SED en la zona; verificar titularidad, operacion y compatibilidad con concesion/convenio.'
            : 'No se identificaron redes MT/SED dentro del poligono con las capas cargadas.',
    ];
}

function zFieldVisitRecommendation(float $areaKm2, array $demand, array $restrictions, array $predios): array {
    $areaHa = $areaKm2 * 100.0;
    $days = max(1, (int)ceil($areaHa / 350.0));
    $clients = (int)($demand['clientes_identificados'] ?? 0);
    if ($clients > 0) $days += (int)ceil($clients / 20);
    $riskCount = count($restrictions['items'] ?? []);
    if ($riskCount > 2) $days += 1;
    if ((float)($predios['porcentaje_area_con_predio'] ?? 0) > 0) $days += 1;
    $days = max(1, min(12, $days));
    return [
        'dias_recomendados'=>$days,
        'ratio'=>'1 dia base por cada 350 ha, +1 dia por cada 20 evidencias/clientes productivos, + ajustes por predios y restricciones.',
        'actividades'=>[
            'Reconocimiento de accesos, relieve, servidumbres y puntos de conexion.',
            'Validacion de redes MT/SED existentes, SET cercanas y estado operativo visible.',
            'Contraste de predios identificados, ocupacion real y posibles restricciones culturales/ambientales.',
        ],
    ];
}

function zFeatureName(array $feature, string $fallback): string {
    $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
    return zPick($props, ['NOMBRE','NOMBRE_ANP','NOMBANP','NOM_ANP','NOMBRE_LINEA','COD_LINEA','COD_SE','CONCESION','NOMBRE_ZON','DENOM','TITULAR','PART_REG','NAME','EMPRESA'], $fallback);
}

function zLayerKind(array $layer, string $default = 'external'): string {
    $text = zNormText(($layer['id'] ?? '') . ' ' . ($layer['nombre'] ?? '') . ' ' . ($layer['grupo'] ?? '') . ' ' . ($layer['menu'] ?? ''));
    $tipo = (string)($layer['tipo'] ?? '');
    if (str_contains($text, 'conces')) return 'concession';
    if (str_contains($text, 'predio') || str_contains($text, 'sunarp') || str_contains($text, 'titular')) return 'predio';
    // "Acuicultura" contiene la palabra "cultura". Clasificar primero las
    // fuentes productivas evita falsos positivos de patrimonio/CIRA.
    if (str_contains($text, 'midagri') || str_contains($text, 'agric') || str_contains($text, 'produce') || str_contains($text, 'acuic')) return 'productivo';
    if (str_contains($text, 'cultura') || str_contains($text, 'cira') || str_contains($text, 'arqueolog') || str_contains($text, 'qhapaq')) return 'cultural';
    if (str_contains($text, 'anp') || str_contains($text, 'sernanp') || str_contains($text, 'natural') || str_contains($text, 'proteg') || str_contains($text, 'ecosistema') || str_contains($text, 'fragil') || str_contains($text, 'sensible')) return 'ambiental';
    if (str_contains($text, 'set') || str_contains($text, 'subestacion')) return 'set';
    if (str_contains($text, 'sed')) return 'sed';
    if ($tipo === 'line' && (str_contains($text, 'media') || str_contains($text, 'mt') || str_contains($text, 'tramo'))) return 'mt';
    if ($tipo === 'line') return 'line';
    return $default;
}

function zScanPolygon(array $points): array {
    $polyBbox = zPolygonBbox($points);
    $centroid = zCentroid($points);
    $ind = [
        'sets_dentro'=>0, 'sets_cercanas'=>0, 'lineas_intersectan'=>0,
        'mt_intersecta'=>0, 'sed_dentro'=>0, 'concesiones_intersectan'=>0,
        'predios_intersectan'=>0, 'cultural_intersecta'=>0, 'ambiental_intersecta'=>0, 'productivo_intersecta'=>0,
        'externas_intersectan'=>0,
        'fuentes'=>[],
    ];
    $samples = [
        'sets'=>[], 'lineas'=>[], 'mt'=>[], 'sed'=>[], 'concesiones'=>[], 'predios'=>[], 'cultural'=>[], 'ambiental'=>[], 'productivo'=>[], 'externas'=>[],
    ];

    foreach (zSources() as $source) {
        $path = zSourceScanPath($source);
        if (!$path || !zShouldScanSource($source, $path)) continue;
        $features = zReadGeoJson($path);
        if (!$features) continue;
        $ind['fuentes'][$source['label']] = count($features);
        foreach ($features as $feature) {
            if (!is_array($feature)) continue;
            $coords = zFeatureCoords($feature, 12000);
            $bbox = zCoordsBbox($coords);
            if (!$coords || !$bbox) continue;
            $name = zFeatureName($feature, $source['label']);

            if ($source['kind'] === 'set' || $source['kind'] === 'sed') {
                $pt = $coords[0];
                $inside = zPointInPolygon($pt[0], $pt[1], $points);
                $near = zDistanceKm($centroid[0], $centroid[1], $pt[0], $pt[1]) <= 15;
                if ($source['kind'] === 'set') {
                    if ($inside) $ind['sets_dentro']++;
                    if ($inside || $near) {
                        $ind['sets_cercanas']++;
                        if (count($samples['sets']) < 6) $samples['sets'][] = $name;
                    }
                } else {
                    if ($inside) {
                        $ind['sed_dentro']++;
                        if (count($samples['sed']) < 6) $samples['sed'][] = $name;
                    }
                }
                continue;
            }

            $touches = zFeatureTouchesPolygon($coords, $bbox, $points, $polyBbox);
            if (!$touches) continue;
            if ($source['kind'] === 'line') {
                $ind['lineas_intersectan']++;
                if (count($samples['lineas']) < 6) $samples['lineas'][] = $name;
            } elseif ($source['kind'] === 'mt') {
                $ind['mt_intersecta']++;
                if (count($samples['mt']) < 6) $samples['mt'][] = $name;
            } elseif ($source['kind'] === 'concession') {
                $ind['concesiones_intersectan']++;
                if (count($samples['concesiones']) < 6) $samples['concesiones'][] = $name;
            } elseif ($source['kind'] === 'predio') {
                $ind['predios_intersectan']++;
                if (count($samples['predios']) < 10) $samples['predios'][] = $name;
            } elseif ($source['kind'] === 'cultural') {
                $ind['cultural_intersecta']++;
                if (count($samples['cultural']) < 8) $samples['cultural'][] = $name;
            } elseif ($source['kind'] === 'ambiental') {
                $ind['ambiental_intersecta']++;
                if (count($samples['ambiental']) < 8) $samples['ambiental'][] = $name;
            } elseif ($source['kind'] === 'productivo') {
                $ind['productivo_intersecta']++;
                if (count($samples['productivo']) < 8) $samples['productivo'][] = $name;
            } elseif ($source['kind'] === 'external') {
                $ind['externas_intersectan']++;
                if (count($samples['externas']) < 6) $samples['externas'][] = $name;
            }
        }
    }
    return ['indicadores'=>$ind, 'muestras'=>$samples, 'centro'=>$centroid, 'bbox'=>$polyBbox];
}

function zEstimateDemand(float $areaKm2, array $ind): int {
    $base = max(60.0, $areaKm2 * 120.0);
    $infra = min(1200.0, ($ind['sets_cercanas'] * 180.0) + ($ind['lineas_intersectan'] * 110.0) + ($ind['mt_intersecta'] * 70.0) + ($ind['sed_dentro'] * 35.0));
    if ($ind['concesiones_intersectan'] > 0) $infra *= 0.75;
    return (int)round(zClamp($base + $infra, 50, 25000));
}

function zProcedure(float $kw): string {
    if ($kw > 500) return 'Concesión de distribución';
    if ($kw < 500) return 'Convenio municipal';
    return 'Umbral 500 kW: revisar expediente';
}

function zViability(float $areaKm2, float $kw, array $ind, array $relief = []): array {
    $score = 35.0;
    if ($ind['sets_cercanas'] > 0) $score += 18;
    if ($ind['sets_dentro'] > 0) $score += 10;
    if ($ind['lineas_intersectan'] > 0) $score += 18;
    if ($ind['mt_intersecta'] > 0 || $ind['sed_dentro'] > 0) $score += 12;
    if (($ind['externas_intersectan'] ?? 0) > 0) $score += 6;
    if ($kw > 500) $score += 6;
    if ($areaKm2 < 0.04) $score -= 8;
    if ($ind['concesiones_intersectan'] > 0) $score -= 16;
    if (($relief['riesgo'] ?? '') === 'alto') $score -= 16;
    elseif (($relief['riesgo'] ?? '') === 'medio') $score -= 7;
    $score = (int)round(zClamp($score, 0, 100));
    $nivel = $score >= 70 ? 'Alta' : ($score >= 45 ? 'Media' : 'Baja');
    return ['score'=>$score, 'nivel'=>$nivel];
}

function zReasons(array $ind, array $samples, float $kw, array $relief = [], array $sets = []): array {
    $reasons = [];
    if ($ind['sets_cercanas'] > 0) $reasons[] = 'Hay SET cercanas o dentro del polígono: ' . implode(', ', array_slice($samples['sets'], 0, 3));
    if ($ind['lineas_intersectan'] > 0) $reasons[] = 'Cruzan o tocan líneas de transmisión relevantes: ' . implode(', ', array_slice($samples['lineas'], 0, 3));
    if ($ind['mt_intersecta'] > 0 || $ind['sed_dentro'] > 0) $reasons[] = 'Se detecta infraestructura de distribución MT/SED dentro del área.';
    if (($ind['externas_intersectan'] ?? 0) > 0) $reasons[] = 'La zona cruza información externa registrada: ' . implode(', ', array_slice($samples['externas'], 0, 3));
    if ($ind['concesiones_intersectan'] > 0) $reasons[] = 'Existe superposición con concesión vigente o en trámite; revisar titularidad y expediente.';
    if (($relief['status'] ?? '') === 'success') {
        $reasons[] = 'Relieve estimado: ' . $relief['descripcion'];
    }
    $available = array_values(array_filter($sets, fn($s) => (($s['disponible_total_mva'] ?? null) !== null && $s['disponible_total_mva'] > 0)));
    if ($available) {
        $names = array_map(fn($s) => $s['nombre'] . ' (' . $s['disponible_total_mva'] . ' MVA libres)', array_slice($available, 0, 3));
        $reasons[] = 'SET con disponibilidad referencial cercana: ' . implode(', ', $names) . '.';
    }
    if (!$reasons) $reasons[] = 'No se encontró infraestructura cercana suficiente en las capas cargadas; requiere levantamiento de campo.';
    $reasons[] = $kw > 500 ? 'La demanda usada supera 500 kW, por lo que orienta a concesión.' : 'La demanda usada es menor o igual al umbral de 500 kW, por lo que orienta a convenio o revisión del caso.';
    return $reasons;
}

function zSamplePolygonPoints(array $points, int $limit = 12): array {
    $samples = [zCentroid($points)];
    $step = max(1, (int)floor(count($points) / max(1, $limit - 1)));
    for ($i = 0; $i < count($points); $i += $step) {
        $samples[] = $points[$i];
        if (count($samples) >= $limit) break;
    }
    return $samples;
}

function zPowerShellGet(string $url): ?string {
    if (PHP_OS_FAMILY !== 'Windows' || !function_exists('shell_exec')) return null;
    $ps = getenv('SystemRoot') ? getenv('SystemRoot') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe' : 'powershell.exe';
    $url64 = base64_encode($url);
    $script = '[Console]::OutputEncoding=New-Object System.Text.UTF8Encoding $false; $OutputEncoding=[Console]::OutputEncoding;'
        . '$ProgressPreference="SilentlyContinue";'
        . '$u=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $url64 . '"));'
        . '(Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 30).Content';
    $encoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $script));
    $cmd = '"' . $ps . '" -NoProfile -ExecutionPolicy Bypass -EncodedCommand ' . $encoded . ' 2>NUL';
    $out = @shell_exec($cmd);
    return is_string($out) && trim($out) !== '' ? $out : null;
}

function zReliefAnalysis(array $points): array {
    $samples = zSamplePolygonPoints($points, 12);
    $lats = array_map(fn($p) => round($p[0], 5), $samples);
    $lngs = array_map(fn($p) => round($p[1], 5), $samples);
    $url = 'https://api.open-meteo.com/v1/elevation?latitude=' . implode(',', $lats) . '&longitude=' . implode(',', $lngs);
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>18, CURLOPT_CONNECTTIMEOUT=>8] + appCurlTlsOptions());
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code < 200 || $code >= 300) throw new RuntimeException('sin respuesta');
        } else {
            $body = @file_get_contents($url, false, stream_context_create([
                'http'=>['timeout'=>18, 'ignore_errors'=>true],
                'ssl'=>appSslStreamOptions(),
            ]));
            if ($body === false) $body = zPowerShellGet($url);
            if ($body === false || $body === null || trim((string)$body) === '') throw new RuntimeException('sin respuesta');
        }
        $data = json_decode((string)$body, true);
        $vals = array_values(array_filter($data['elevation'] ?? [], fn($v) => is_numeric($v)));
        if (!$vals) throw new RuntimeException('sin elevacion');
        $min = min($vals);
        $max = max($vals);
        $range = $max - $min;
        $risk = $range >= 650 ? 'alto' : ($range >= 250 ? 'medio' : 'bajo');
        $desc = $risk === 'alto'
            ? "alto, con variacion aproximada de {$range} m; puede encarecer accesos, servidumbres y tendido."
            : ($risk === 'medio'
                ? "medio, con variacion aproximada de {$range} m; requiere revisar pendientes y accesos."
                : "bajo a moderado, con variacion aproximada de {$range} m.");
        return [
            'status'=>'success',
            'fuente'=>'Open-Meteo Elevation',
            'min_m'=>round($min, 1),
            'max_m'=>round($max, 1),
            'promedio_m'=>round(array_sum($vals) / count($vals), 1),
            'rango_m'=>round($range, 1),
            'riesgo'=>$risk,
            'descripcion'=>$desc,
        ];
    } catch (Throwable $e) {
        return ['status'=>'unavailable', 'message'=>'No se pudo consultar relieve externo en este momento.'];
    }
}

function zCeraunicoLayerMap(): array {
    return [
        2025=>5, 2024=>8, 2023=>11, 2022=>14, 2021=>17,
        2020=>20, 2019=>23, 2018=>26, 2017=>29, 2016=>32,
        2015=>35, 2014=>38, 2013=>41,
    ];
}

function zCeraunicoRiskLevel(float $value): string {
    if ($value >= 65) return 'extremo';
    if ($value >= 57) return 'critico';
    if ($value >= 33) return 'muy alto';
    if ($value >= 17) return 'alto';
    if ($value >= 9) return 'medio alto';
    if ($value >= 5) return 'medio';
    if ($value >= 2) return 'bajo';
    if ($value > 0) return 'muy bajo';
    return 'sin registro';
}

function zCeraunicoRiskComment(float $value): string {
    if ($value >= 65) return 'Exposicion extrema a descargas atmosfericas; considerar apantallamiento reforzado, puesta a tierra de baja resistencia, DPS y coordinacion de aislamiento.';
    if ($value >= 57) return 'Exposicion critica a descargas atmosfericas; revisar cable de guarda, pararrayos, aislamiento y sistema de puesta a tierra.';
    if ($value >= 33) return 'Exposicion muy alta a descargas atmosfericas; reforzar protecciones contra sobretensiones y criterios de aislamiento.';
    if ($value >= 17) return 'Exposicion alta; revisar proteccion contra sobretensiones, cable de guarda y nivel de aislamiento.';
    if ($value >= 9) return 'Exposicion media alta; considerar verificacion de puesta a tierra y protecciones normalizadas.';
    if ($value >= 5) return 'Exposicion media; aplicar criterios normativos de proteccion contra descargas atmosfericas.';
    if ($value > 0) return 'Exposicion baja; mantener criterios normativos de proteccion.';
    return 'Sin registro relevante de rayos en el punto consultado segun CERAUNICO.';
}

function zCeraunicoClassLabel(float $value): string {
    if ($value <= 0) return '0';
    if ($value <= 1) return '1';
    if ($value <= 2) return '2';
    if ($value <= 3) return '3';
    if ($value <= 4) return '4';
    if ($value <= 8) return '5 - 8';
    if ($value <= 16) return '9 - 16';
    if ($value <= 32) return '17 - 32';
    if ($value <= 56) return '33 - 56';
    if ($value <= 64) return '57 - 64';
    return '65 - 128';
}

function zCeraunicoClassOrder(): array {
    return ['0','1','2','3','4','5 - 8','9 - 16','17 - 32','33 - 56','57 - 64','65 - 128'];
}

function zCeraunicoIdentifyUrl(float $lat, float $lng, int $year = 2025): ?string {
    $layers = zCeraunicoLayerMap();
    $layer = $layers[$year] ?? $layers[2025];
    if (!defined('URL_CERAUNICO_REST')) return null;
    $delta = 0.28;
    $args = [
        'f'=>'json',
        'tolerance'=>3,
        'returnGeometry'=>'false',
        'imageDisplay'=>'700,700,96',
        'mapExtent'=>($lng - $delta) . ',' . ($lat - $delta) . ',' . ($lng + $delta) . ',' . ($lat + $delta),
        'geometry'=>$lng . ',' . $lat,
        'geometryType'=>'esriGeometryPoint',
        'sr'=>'4326',
        'layers'=>'visible:' . $layer,
    ];
    return rtrim(URL_CERAUNICO_REST, '/') . '/identify?' . http_build_query($args);
}

function zCeraunicoParseIdentify(?array $data, float $lat, float $lng): ?array {
    if (!is_array($data)) return null;
    $results = is_array($data['results'] ?? null) ? $data['results'] : [];
    if (!$results) return null;
    $attrs = is_array($results[0]['attributes'] ?? null) ? $results[0]['attributes'] : [];
    $raw = $attrs['Classify.Pixel Value'] ?? $attrs['Classify.Class value'] ?? $attrs['Pixel Value'] ?? null;
    if ($raw === null || $raw === '') return null;
    $value = (float)$raw;
    return [
        'lat'=>round($lat, 6),
        'lng'=>round($lng, 6),
        'valor'=>round($value, 2),
        'nivel'=>zCeraunicoRiskLevel($value),
        'comentario'=>zCeraunicoRiskComment($value),
    ];
}

function zCeraunicoPointCachePath(float $lat, float $lng, int $year): string {
    $key = sha1($year . '|' . number_format($lat, 4, '.', '') . '|' . number_format($lng, 4, '.', ''));
    return CACHE_DIR . '/ceraunico_point_' . $key . '.json';
}

function zCeraunicoPointCacheLoad(float $lat, float $lng, int $year): array {
    $path = zCeraunicoPointCachePath($lat, $lng, $year);
    if (!is_file($path)) return ['cached'=>false, 'data'=>null];
    $stored = json_decode((string)@file_get_contents($path), true);
    if (!is_array($stored)) return ['cached'=>false, 'data'=>null];
    $ttl = !empty($stored['found']) ? 30 * 24 * 60 * 60 : 24 * 60 * 60;
    if (filemtime($path) < time() - $ttl) return ['cached'=>false, 'data'=>null];
    return ['cached'=>true, 'data'=>(is_array($stored['data'] ?? null) ? $stored['data'] : null)];
}

function zCeraunicoPointCacheStore(float $lat, float $lng, int $year, ?array $data): void {
    $path = zCeraunicoPointCachePath($lat, $lng, $year);
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
    @file_put_contents($path, json_encode([
        'fetched_at'=>date('c'),
        'found'=>$data !== null,
        'data'=>$data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function zCeraunicoIdentifyPoints(array $points, int $year = 2025): array {
    $results = [];
    $pending = [];
    foreach ($points as $pt) {
        if (!is_array($pt) || count($pt) < 2) continue;
        $lat = round((float)$pt[0], 5);
        $lng = round((float)$pt[1], 5);
        $key = $lat . ',' . $lng;
        if (array_key_exists($key, $results) || isset($pending[$key])) continue;
        $cached = zCeraunicoPointCacheLoad($lat, $lng, $year);
        if ($cached['cached']) {
            $results[$key] = $cached['data'];
            continue;
        }
        $url = zCeraunicoIdentifyUrl($lat, $lng, $year);
        if ($url) $pending[$key] = ['lat'=>$lat, 'lng'=>$lng, 'url'=>$url];
    }
    if (!$pending) return $results;

    if (function_exists('curl_multi_init') && function_exists('curl_init')) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($pending as $key => $row) {
            $ch = curl_init($row['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_TIMEOUT=>15,
                CURLOPT_CONNECTTIMEOUT=>6,
                CURLOPT_HTTPHEADER=>['Accept: application/json'],
            ] + appCurlTlsOptions());
            curl_multi_add_handle($mh, $ch);
            $handles[] = ['key'=>$key, 'row'=>$row, 'handle'=>$ch];
        }
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running && $status === CURLM_OK) {
                $selected = curl_multi_select($mh, 1.0);
                if ($selected === -1) usleep(10000);
            }
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $entry) {
            $ch = $entry['handle'];
            $body = curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $decoded = ($code >= 200 && $code < 300 && is_string($body)) ? json_decode($body, true) : null;
            $hit = zCeraunicoParseIdentify(is_array($decoded) ? $decoded : null, $entry['row']['lat'], $entry['row']['lng']);
            $results[$entry['key']] = $hit;
            zCeraunicoPointCacheStore($entry['row']['lat'], $entry['row']['lng'], $year, $hit);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }

    foreach ($pending as $key => $row) {
        $hit = zCeraunicoParseIdentify(zFetchJsonUrl($row['url']), $row['lat'], $row['lng']);
        $results[$key] = $hit;
        zCeraunicoPointCacheStore($row['lat'], $row['lng'], $year, $hit);
    }
    return $results;
}

function zCeraunicoIdentifyPoint(float $lat, float $lng, int $year = 2025): ?array {
    $lat = round($lat, 5);
    $lng = round($lng, 5);
    $rows = zCeraunicoIdentifyPoints([[$lat, $lng]], $year);
    return $rows[$lat . ',' . $lng] ?? null;
}

function zCeraunicoAnalyzePoints(array $points, int $year = 2025): array {
    $samples = [];
    $values = [];
    $seen = [];
    $unique = [];
    foreach ($points as $pt) {
        if (!is_array($pt) || count($pt) < 2) continue;
        $lat = round((float)$pt[0], 5);
        $lng = round((float)$pt[1], 5);
        $key = $lat . ',' . $lng;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = [$lat, $lng];
        if (count($unique) >= 10) break;
    }
    $identified = zCeraunicoIdentifyPoints($unique, $year);
    foreach ($unique as $pt) {
        $hit = $identified[$pt[0] . ',' . $pt[1]] ?? null;
        if (!is_array($hit)) continue;
        $samples[] = $hit;
        $values[] = (float)$hit['valor'];
    }
    if (!$values) {
        return [
            'status'=>'unavailable',
            'fuente'=>'OSINERGMIN CERAUNICO',
            'anio'=>$year,
            'message'=>'No se pudo consultar CERAUNICO para la geometria evaluada.',
        ];
    }
    $max = max($values);
    $avg = array_sum($values) / count($values);
    $classCounts = [];
    $levelCounts = [];
    foreach ($values as $value) {
        $label = zCeraunicoClassLabel((float)$value);
        $level = zCeraunicoRiskLevel((float)$value);
        $classCounts[$label] = ($classCounts[$label] ?? 0) + 1;
        $levelCounts[$level] = ($levelCounts[$level] ?? 0) + 1;
    }
    $classRows = [];
    foreach (zCeraunicoClassOrder() as $label) {
        $count = (int)($classCounts[$label] ?? 0);
        if ($count <= 0) continue;
        $representative = match ($label) {
            '0' => 0.0,
            '1' => 1.0,
            '2' => 2.0,
            '3' => 3.0,
            '4' => 4.0,
            '5 - 8' => 8.0,
            '9 - 16' => 16.0,
            '17 - 32' => 32.0,
            '33 - 56' => 56.0,
            '57 - 64' => 64.0,
            default => 128.0,
        };
        $classRows[] = [
            'clase'=>$label,
            'nivel'=>zCeraunicoRiskLevel($representative),
            'conteo'=>$count,
            'porcentaje'=>round(($count / max(1, count($values))) * 100, 1),
        ];
    }
    $levelRows = [];
    foreach ($levelCounts as $level => $count) {
        $levelRows[] = [
            'nivel'=>$level,
            'conteo'=>(int)$count,
            'porcentaje'=>round(($count / max(1, count($values))) * 100, 1),
        ];
    }
    return [
        'status'=>'success',
        'fuente'=>'OSINERGMIN CERAUNICO - Descargas atmosfericas',
        'anio'=>$year,
        'unidad'=>'rayos totales por km2/año',
        'muestras'=>count($samples),
        'min'=>round(min($values), 2),
        'promedio'=>round($avg, 2),
        'max'=>round($max, 2),
        'nivel'=>zCeraunicoRiskLevel($max),
        'comentario'=>zCeraunicoRiskComment($max),
        'clase_maxima'=>zCeraunicoClassLabel($max),
        'clases'=>$classRows,
        'niveles'=>$levelRows,
        'puntos'=>array_slice($samples, 0, 6),
    ];
}

function zCeraunicoAnalysis(array $polygon, int $year = 2025): array {
    return zCeraunicoAnalyzePoints(zSamplePolygonPoints($polygon, 10), $year);
}



function zSetCompany(array $feature): string {
    $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
    return zPick($props, ['EMPRESA','CODEMP','empresa'], '');
}

function zNearestSets(array $center, array $polygon, int $limit = 5, float $radiusKm = 45.0): array {
    $hits = [];
    $seen = [];
    foreach (zSources() as $source) {
        if ($source['kind'] !== 'set') continue;
        $path = zSourceScanPath($source);
        if (!$path || !zShouldScanSource($source, $path)) continue;
        foreach (zReadGeoJson($path) as $feature) {
            if (!is_array($feature)) continue;
            $coords = zFeatureCoords($feature, 1);
            if (!$coords) continue;
            $pt = $coords[0];
            $centerDist = zDistanceKm($center[0], $center[1], $pt[0], $pt[1]);
            $inside = zPointInPolygon($pt[0], $pt[1], $polygon);
            $polyDist = $inside ? 0.0 : zPointPolygonDistanceKm($pt, $polygon);
            if (!$inside && $polyDist > $radiusKm) continue;
            $name = zFeatureName($feature, $source['label']);
            $company = zSetCompany($feature);
            $key = strtolower($name . '|' . $company);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $hits[] = [
                'nombre'=>$name,
                'empresa'=>$company,
                'lat'=>round($pt[0], 6),
                'lng'=>round($pt[1], 6),
                'distancia_km'=>round($polyDist, 2),
                'distancia_centro_km'=>round($centerDist, 2),
                'dentro'=>$inside,
                'ubicacion'=>$inside ? 'Dentro del poligono' : 'Fuera del poligono',
                'fuente'=>$source['label'],
            ];
        }
    }
    usort($hits, fn($a, $b) => ($b['dentro'] <=> $a['dentro']) ?: ($a['distancia_km'] <=> $b['distancia_km']));
    return array_slice($hits, 0, $limit);
}

function zNormText(string $value): string {
    $value = strtolower(trim($value));
    $plain = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($plain) && $plain !== '') $value = $plain;
    return preg_replace('/[^a-z0-9]+/', '', $value) ?: '';
}

function zAvailabilitySummary(array $data): array {
    $summaries = $data['resumen_transformadores'] ?? [];
    $detailRows = is_array($data['filas'] ?? null) ? $data['filas'] : [];
    $cap = 0.0; $dem = 0.0; $disp = 0.0; $has = false; $hasDisp = false; $noMeasurement = false;
    $setSummary = is_array($data['resumen_set'] ?? null) ? $data['resumen_set'] : [];
    $modern = (int)($data['metodologia_version'] ?? 0) >= 2 && !empty($setSummary);
    if ($modern) {
        $capValue = $setSummary['capacidad_primaria_registrada_mva'] ?? ($setSummary['capacidad_primaria_instalada_mva'] ?? ($setSummary['capacidad_total_mva'] ?? null));
        $demValue = $setSummary['pico_entrada_set_mva'] ?? ($setSummary['demanda_total_mva'] ?? null);
        $dispValue = $setSummary['disponible_tecnico_mva'] ?? ($setSummary['disponible_total_mva'] ?? null);
        if ($capValue !== null) { $cap = (float)$capValue; $has = true; }
        if ($demValue !== null) $dem = (float)$demValue;
        if ($dispValue !== null) { $disp = (float)$dispValue; $hasDisp = true; }
        $noMeasurement = !$hasDisp || stripos((string)($setSummary['estado'] ?? ''), 'sin medicion') !== false;
    } else {
        foreach ($summaries as $s) {
            if (isset($s['capacidad_total_mva'])) { $cap += (float)$s['capacidad_total_mva']; $has = true; }
            if (isset($s['demanda_total_mva'])) $dem += (float)$s['demanda_total_mva'];
            if (isset($s['disponible_total_mva'])) { $disp += (float)$s['disponible_total_mva']; $hasDisp = true; }
            if (($s['disponible_total_mva'] ?? null) === null || stripos((string)($s['estado'] ?? ''), 'sin medicion') !== false) $noMeasurement = true;
        }
    }
    if (!$modern && !$has) {
        foreach ($detailRows as $row) {
            if (isset($row['capacidad_mva'])) { $cap += (float)$row['capacidad_mva']; $has = true; }
            if (isset($row['demanda_max_mva'])) $dem += (float)$row['demanda_max_mva'];
            if (isset($row['disponible_mva'])) { $disp += (float)$row['disponible_mva']; $hasDisp = true; }
            if (!empty($row['sin_medicion_quinceminutal']) || stripos((string)($row['estado'] ?? ''), 'sin medicion') !== false) $noMeasurement = true;
        }
    }
    $devanados = [];
    $has229 = false;
    foreach ($detailRows as $row) {
        if (!is_array($row)) continue;
        $kv = zNum($row['tension_kv'] ?? null);
        if ($kv !== null && abs($kv - 22.9) <= 0.35) $has229 = true;
        $devanados[] = [
            'transformador'=>trim((string)($row['transformador'] ?? ($row['trafo'] ?? ''))),
            'barra'=>trim((string)($row['barra'] ?? ($row['bus'] ?? ''))),
            'tension_kv'=>$kv,
            'lado_transformador'=>trim((string)($row['lado_transformador'] ?? ($row['lado'] ?? ''))),
            'capacidad_mva'=>isset($row['capacidad_mva']) ? round((float)$row['capacidad_mva'], 4) : null,
            'demanda_max_mva'=>isset($row['demanda_max_mva']) ? round((float)$row['demanda_max_mva'], 4) : null,
            'disponible_mva'=>isset($row['disponible_mva']) ? round((float)$row['disponible_mva'], 4) : null,
            'disponible_tecnico_mva'=>isset($row['disponible_tecnico_mva']) ? round((float)$row['disponible_tecnico_mva'], 4) : null,
            'disponible_planificacion_mva'=>isset($row['disponible_planificacion_mva']) ? round((float)$row['disponible_planificacion_mva'], 4) : null,
            'fecha_critica'=>$row['fecha_critica'] ?? null,
            'restriccion'=>$row['restriccion'] ?? null,
            'fp_referencia_nueva_carga'=>$row['fp_referencia_nueva_carga'] ?? null,
            'estado'=>$row['estado'] ?? '',
            'fuente_capacidad'=>$row['fuente_capacidad'] ?? '',
            'capacidad_nota'=>$row['capacidad_nota'] ?? '',
        ];
    }
    usort($devanados, function($a, $b) {
        $ta = (string)($a['transformador'] ?? '');
        $tb = (string)($b['transformador'] ?? '');
        $cmp = strnatcasecmp($ta, $tb);
        if ($cmp !== 0) return $cmp;
        return ((float)($b['tension_kv'] ?? 0)) <=> ((float)($a['tension_kv'] ?? 0));
    });
    $status = $modern
        ? (string)($setSummary['estado'] ?? ($noMeasurement && !$hasDisp ? 'Sin medicion' : ($disp > 0 ? 'Disponible preliminar' : 'Sin margen demostrado')))
        : ($noMeasurement && !$hasDisp ? 'Sin medicion' : ($disp >= 0 ? 'Disponible' : 'Sobrecarga'));
    return [
        'status'=>'success',
        'archivo_quinceminutal'=>$data['archivo_quinceminutal'] ?? null,
        'capacidad_total_mva'=>round($cap, 4),
        'demanda_total_mva'=>round($dem, 4),
        'disponible_total_mva'=>$modern
            ? (($setSummary['disponible_planificacion_mva'] ?? null) !== null ? round((float)$setSummary['disponible_planificacion_mva'], 4) : ($hasDisp ? round($disp, 4) : null))
            : ($hasDisp ? round($disp, 4) : null),
        'disponible_tecnico_mva'=>$modern ? ($setSummary['disponible_tecnico_mva'] ?? null) : ($hasDisp ? round($disp, 4) : null),
        'disponible_planificacion_mva'=>$modern ? ($setSummary['disponible_planificacion_mva'] ?? null) : null,
        'transformador_recomendado'=>$modern ? ($setSummary['transformador_recomendado'] ?? null) : null,
        'barra_recomendada'=>$modern ? ($setSummary['barra_recomendada'] ?? null) : null,
        'tension_recomendada_kv'=>$modern ? ($setSummary['tension_recomendada_kv'] ?? null) : null,
        'criterio_disponibilidad'=>$modern ? ($setSummary['criterio'] ?? null) : null,
        'capacidad_firme_n_1_mva'=>$modern ? ($setSummary['capacidad_firme_n_1_mva'] ?? null) : null,
        'estado'=>$status,
        'medicion_quinceminutal'=>$data['medicion_quinceminutal'] ?? ($noMeasurement ? 'sin_medicion' : 'ok'),
        'mensaje_medicion'=>$data['mensaje_medicion'] ?? null,
        'transformadores'=>array_map(fn($s) => [
            'transformador'=>$s['transformador'] ?? '',
            'capacidad_total_mva'=>$s['capacidad_total_mva'] ?? null,
            'demanda_total_mva'=>$s['demanda_total_mva'] ?? null,
            'disponible_total_mva'=>$s['disponible_total_mva'] ?? null,
            'potencias_nominales_mva'=>$s['potencias_nominales_mva'] ?? null,
            'capacidad_primaria_mva'=>$s['capacidad_primaria_mva'] ?? null,
            'pico_primario_mva'=>$s['pico_primario_mva'] ?? null,
            'disponible_tecnico_mva'=>$s['disponible_tecnico_mva'] ?? null,
            'disponible_planificacion_mva'=>$s['disponible_planificacion_mva'] ?? null,
            'barra_recomendada'=>$s['barra_recomendada'] ?? null,
            'tension_recomendada_kv'=>$s['tension_recomendada_kv'] ?? null,
            'restriccion'=>$s['restriccion'] ?? null,
            'estado'=>$s['estado'] ?? '',
            'criterio'=>$s['criterio'] ?? '',
        ], array_slice($summaries, 0, 4)),
        'devanados'=>array_slice($devanados, 0, 40),
        'tiene_devanado_229'=>$has229,
        'alerta_229'=>$has229 ? '' : 'No se identifico devanado de 22.9 kV en la data disponible; validar tension de conexion y alternativa tecnica.',
    ];
}

function zCachedAvailability(string $name): ?array {
    static $index = null;
    if ($index === null) {
        $index = [];
        foreach (glob(CACHE_DIR . '/analysis_v2_*.json') ?: [] as $file) {
            $wrap = json_decode((string)file_get_contents($file), true);
            $data = is_array($wrap['result'] ?? null) ? $wrap['result'] : null;
            if (!$data || ($data['status'] ?? '') !== 'success') continue;
            $summary = zAvailabilitySummary($data);
            $names = [
                $data['subestacion']['nombre'] ?? '',
                $data['capacidad_mapa_sein']['nombre'] ?? '',
                basename($file, '.json'),
            ];
            foreach ($names as $n) {
                $key = zNormText((string)$n);
                if ($key !== '') $index[$key] = $summary;
            }
        }
    }
    $key = zNormText($name);
    if ($key === '') return null;
    if (isset($index[$key])) return $index[$key];
    foreach ($index as $k => $summary) {
        if ((strlen($key) >= 5 && str_contains($k, $key)) || (strlen($k) >= 5 && str_contains($key, $k))) return $summary;
    }
    return null;
}

function zCachedAvailableSets(): array {
    static $sets = null;
    if ($sets !== null) return $sets;
    $sets = [];
    $seen = [];
    foreach (glob(CACHE_DIR . '/analysis_v2_*.json') ?: [] as $file) {
        $wrap = json_decode((string)file_get_contents($file), true);
        $data = is_array($wrap['result'] ?? null) ? $wrap['result'] : null;
        if (!$data || ($data['status'] ?? '') !== 'success') continue;
        $summary = zAvailabilitySummary($data);
        if ((float)($summary['disponible_total_mva'] ?? 0) <= 0) continue;
        $cap = is_array($data['capacidad_mapa_sein'] ?? null) ? $data['capacidad_mapa_sein'] : [];
        $lat = zNum($cap['lat'] ?? null);
        $lng = zNum($cap['lng'] ?? null);
        if ($lat === null || $lng === null) continue;
        $name = trim((string)($cap['nombre'] ?? ($data['subestacion']['nombre'] ?? 'SET')));
        $company = trim((string)($cap['empresa'] ?? ($data['empresa_consulta'] ?? '')));
        $key = zNormText($name . '|' . $company);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $sets[] = [
            'nombre'=>$name,
            'empresa'=>$company,
            'lat'=>$lat,
            'lng'=>$lng,
            'fuente'=>'Disponibilidad cacheada',
            'availability'=>$summary,
        ];
    }
    usort($sets, fn($a, $b) => (($a['lat'] ?? 0) <=> ($b['lat'] ?? 0)));
    return $sets;
}

function zAvailabilityForSet(string $name, string $company = ''): array {
    $cached = zCachedAvailability($name);
    if ($cached) return $cached;
    if (!is_file(ANALYZER) || !function_exists('shell_exec')) {
        return ['status'=>'unavailable', 'mensaje'=>'Analizador local no disponible.'];
    }
    $cmd = escapeshellarg(PYTHON_BIN) . ' ' . escapeshellarg(ANALYZER)
        . ' --nombre ' . escapeshellarg($name)
        . ' --empresa ' . escapeshellarg($company)
        . ' 2>&1';
    $out = @shell_exec($cmd);
    $data = $out ? json_decode(trim($out), true) : null;
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
        return ['status'=>$data['status'] ?? 'no_data', 'mensaje'=>$data['mensaje'] ?? $data['message'] ?? 'Sin calculo de disponibilidad.'];
    }
    return zAvailabilitySummary($data);
}

function zPitForSet(string $name, string $company = '', string $departamento = ''): array {
    static $cache = [];
    $key = zNormText($name . '|' . $company . '|' . $departamento);
    if ($key !== '' && isset($cache[$key])) return $cache[$key];
    if (!defined('PIT_ANALYZER') || !defined('PIT_DIR') || !is_file(PIT_ANALYZER) || !is_dir(PIT_DIR) || !function_exists('shell_exec')) {
        $result = ['status'=>'unavailable', 'message'=>'Analizador PIT no disponible.'];
        if ($key !== '') $cache[$key] = $result;
        return $result;
    }
    $cmd = escapeshellarg(PYTHON_BIN) . ' ' . escapeshellarg(PIT_ANALYZER)
        . ' --nombre ' . escapeshellarg($name)
        . ' --empresa ' . escapeshellarg($company)
        . ' --departamento ' . escapeshellarg($departamento)
        . ' --pit-dir ' . escapeshellarg(PIT_DIR)
        . ' --limit 16 2>&1';
    $out = @shell_exec($cmd);
    $data = $out ? json_decode(trim($out), true) : null;
    if (!is_array($data) || !isset($data['status'])) {
        $data = ['status'=>'unavailable', 'message'=>'Respuesta PIT no valida.', 'raw'=>substr((string)$out, 0, 260)];
    }
    if ($key !== '') $cache[$key] = $data;
    return $data;
}

function zPitDateLabel(array $row): string {
    $parts = [];
    foreach ([
        'fecha_puesta_servicio'=>'puesta en servicio',
        'fecha_reprogramada'=>'reprogramada',
        'fecha_suscripcion'=>'suscripcion',
        'fecha_solicitud'=>'solicitud',
        'anio_reprogramado'=>'anio reprogramado',
        'anio_previsto'=>'anio previsto',
    ] as $key => $label) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') $parts[] = $label . ': ' . $value;
    }
    return implode(' | ', $parts);
}

function zPitElementText(array $row): string {
    return trim(implode(' - ', array_filter([
        (string)($row['proyecto'] ?? ''),
        (string)($row['elemento'] ?? ''),
        (string)($row['elementos'] ?? ''),
        (string)($row['modulo'] ?? ''),
    ], fn($v) => trim($v) !== '')));
}

function zPitRowsForSets(array $sets, int $limit = 14): array {
    $rows = [];
    $seen = [];
    foreach ($sets as $set) {
        $pit = is_array($set['pit'] ?? null) ? $set['pit'] : [];
        $items = [];
        foreach (($pit['future_transformers'] ?? []) as $item) {
            if (is_array($item)) $items[] = $item + ['_pit_prioridad'=>'Transformador futuro'];
        }
        foreach (($pit['matches'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $plain = strtoupper((string)($item['tipo_elemento'] ?? '') . ' ' . zPitElementText($item));
            if (str_contains($plain, 'TRANSFORMADOR') || str_contains($plain, 'TRAFO') || str_contains($plain, 'TP-')) {
                $items[] = $item + ['_pit_prioridad'=>'Transformador PIT'];
            }
        }
        foreach (($pit['matches'] ?? []) as $item) {
            if (is_array($item)) $items[] = $item + ['_pit_prioridad'=>'Elemento PIT'];
        }
        foreach ($items as $item) {
            $hash = zNormText(($set['nombre'] ?? '') . '|' . ($item['anexo'] ?? '') . '|' . ($item['fila'] ?? '') . '|' . zPitElementText($item));
            if ($hash !== '' && isset($seen[$hash])) continue;
            if ($hash !== '') $seen[$hash] = true;
            $rows[] = [
                'set'=>$set['nombre'] ?? '',
                'empresa_set'=>$set['empresa'] ?? '',
                'distancia_km'=>$set['distancia_km'] ?? null,
                'ubicacion'=>$set['ubicacion'] ?? (($set['dentro'] ?? false) ? 'Dentro del poligono' : 'Fuera del poligono'),
                'pit_etiqueta'=>$item['pit_etiqueta'] ?? ($item['pit_titulo'] ?? ($item['anexo'] ?? 'PIT')),
                'proyecto'=>$item['proyecto'] ?? '',
                'elemento'=>zPitElementText($item),
                'instalacion'=>$item['instalacion'] ?? '',
                'empresa'=>$item['empresa'] ?? '',
                'tipo_elemento'=>$item['tipo_elemento'] ?? '',
                'prioridad'=>$item['_pit_prioridad'] ?? '',
                'estado'=>$item['estado'] ?? ($item['avance'] ?? ''),
                'potencia_mva'=>$item['potencia_mva'] ?? null,
                'tension_kv'=>$item['tension_kv'] ?? '',
                'fecha'=>$item['fecha_puesta_servicio'] ?? ($item['fecha_reprogramada'] ?? ($item['fecha_suscripcion'] ?? '')),
                'fecha_detalle'=>zPitDateLabel($item),
                'anio_previsto'=>$item['anio_previsto'] ?? '',
                'anio_reprogramado'=>$item['anio_reprogramado'] ?? '',
                'comentario'=>$item['comentario'] ?? '',
                'coincidencia'=>$item['coincidencia'] ?? '',
            ];
            if (count($rows) >= $limit) return $rows;
        }
    }
    return $rows;
}

function zPtForSet(string $name, string $company = '', string $departamento = ''): array {
    static $cache = [];
    $key = zNormText($name . '|' . $company . '|' . $departamento);
    if ($key !== '' && isset($cache[$key])) return $cache[$key];
    if (!defined('PT_COES_ANALYZER') || !defined('PT_COES_DIR') || !is_file(PT_COES_ANALYZER) || !is_dir(PT_COES_DIR) || !function_exists('shell_exec')) {
        $result = ['status'=>'unavailable', 'message'=>'Analizador PT COES no disponible.'];
        if ($key !== '') $cache[$key] = $result;
        return $result;
    }
    $cmd = escapeshellarg(PYTHON_BIN) . ' ' . escapeshellarg(PT_COES_ANALYZER)
        . ' --nombre ' . escapeshellarg($name)
        . ' --empresa ' . escapeshellarg($company)
        . ' --departamento ' . escapeshellarg($departamento)
        . ' --tipo set'
        . ' --pt-dir ' . escapeshellarg(PT_COES_DIR)
        . ' --limit 8 2>&1';
    $out = @shell_exec($cmd);
    $data = $out ? json_decode(trim($out), true) : null;
    if (!is_array($data) || !isset($data['status'])) {
        $data = ['status'=>'unavailable', 'message'=>'Respuesta PT COES no valida.', 'raw'=>substr((string)$out, 0, 260)];
    }
    if ($key !== '') $cache[$key] = $data;
    return $data;
}

function zAttachPtToSets(array $sets, string $departamento = ''): array {
    foreach ($sets as &$set) {
        $pt = zPtForSet($set['nombre'] ?? '', $set['empresa'] ?? '', $departamento);
        $set['pt_coes'] = [
            'status'=>$pt['status'] ?? 'unavailable',
            'message'=>$pt['message'] ?? '',
            'criterio'=>$pt['criterio'] ?? 'PT filtrado por coincidencia explicita de SET o por proyecto nuevo del departamento cuando aplique.',
            'total_matches'=>$pt['total_matches'] ?? (is_array($pt['matches'] ?? null) ? count($pt['matches']) : 0),
            'matches'=>array_slice(is_array($pt['matches'] ?? null) ? $pt['matches'] : [], 0, 8),
        ];
    }
    unset($set);
    return $sets;
}

function zPtRowsForSets(array $sets, int $limit = 10): array {
    $rows = [];
    $seen = [];
    foreach ($sets as $set) {
        $pt = is_array($set['pt_coes'] ?? null) ? $set['pt_coes'] : [];
        foreach (($pt['matches'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $hash = zNormText(($set['nombre'] ?? '') . '|' . ($item['archivo_rel'] ?? $item['archivo'] ?? '') . '|' . ($item['titulo'] ?? ''));
            if ($hash !== '' && isset($seen[$hash])) continue;
            if ($hash !== '') $seen[$hash] = true;
            $rows[] = [
                'set'=>$set['nombre'] ?? '',
                'empresa_set'=>$set['empresa'] ?? '',
                'distancia_km'=>$set['distancia_km'] ?? null,
                'ubicacion'=>$set['ubicacion'] ?? (($set['dentro'] ?? false) ? 'Dentro del poligono' : 'Fuera del poligono'),
                'titulo'=>$item['titulo'] ?? '',
                'tipo'=>$item['tipo'] ?? '',
                'tension_kv'=>$item['tension_kv'] ?? '',
                'departamentos'=>is_array($item['departamentos'] ?? null) ? implode(', ', $item['departamentos']) : (string)($item['departamentos'] ?? ''),
                'score'=>$item['score'] ?? null,
                'match_type'=>$item['match_type'] ?? '',
                'razones'=>is_array($item['razones'] ?? null) ? implode('; ', $item['razones']) : (string)($item['razones'] ?? ''),
                'archivo'=>$item['archivo'] ?? '',
                'url'=>$item['url'] ?? '',
                'criterio'=>$pt['criterio'] ?? '',
            ];
            if (count($rows) >= $limit) return $rows;
        }
    }
    return $rows;
}

function zAttachSetAvailability(array $sets): array {
    foreach ($sets as &$set) {
        $availability = zAvailabilityForSet($set['nombre'], $set['empresa'] ?? '');
        $set += $availability;
    }
    unset($set);
    return $sets;
}

function zAttachPitToSets(array $sets, string $departamento = ''): array {
    foreach ($sets as &$set) {
        $pit = zPitForSet($set['nombre'] ?? '', $set['empresa'] ?? '', $departamento);
        $set['pit'] = [
            'status'=>$pit['status'] ?? 'unavailable',
            'message'=>$pit['message'] ?? '',
            'file'=>$pit['file'] ?? '',
            'total_matches'=>$pit['total_matches'] ?? 0,
            'future_transformer'=>!empty($pit['future_transformer']),
            'future_transformers'=>array_slice(is_array($pit['future_transformers'] ?? null) ? $pit['future_transformers'] : [], 0, 5),
            'matches'=>array_slice(is_array($pit['matches'] ?? null) ? $pit['matches'] : [], 0, 10),
        ];
    }
    unset($set);
    return $sets;
}

function zLocalReport(array $analysis): string {
    $lines = [];
    $lines[] = 'Informe de viabilidad de zona';
    $lines[] = 'Fecha: ' . date('Y-m-d H:i:s');
    $lines[] = 'Área: ' . $analysis['area_km2'] . ' km2';
    $lines[] = 'Demanda usada: ' . $analysis['demanda_kw_usada'] . ' kW (' . $analysis['demanda_origen'] . ')';
    $lines[] = 'Trámite recomendado: ' . $analysis['tramite_recomendado'];
    $lines[] = 'Viabilidad: ' . $analysis['viabilidad']['nivel'] . ' (' . $analysis['viabilidad']['score'] . '/100)';
    if (($analysis['relieve']['status'] ?? '') === 'success') {
        $lines[] = 'Relieve: ' . $analysis['relieve']['descripcion'] . ' Elevación min/max: ' . $analysis['relieve']['min_m'] . '/' . $analysis['relieve']['max_m'] . ' m.';
    }
    $lines[] = 'Indicadores: ' . json_encode($analysis['indicadores'], JSON_UNESCAPED_UNICODE);
    if (!empty($analysis['sets_cercanas'])) {
        $lines[] = 'SET cercanas:';
        foreach ($analysis['sets_cercanas'] as $set) {
            $lines[] = '- ' . $set['nombre'] . ' (' . ($set['empresa'] ?: 'sin empresa') . '), ' . $set['distancia_km'] . ' km, cap. '
                . ($set['capacidad_total_mva'] ?? 's/d') . ' MVA, demanda ' . ($set['demanda_total_mva'] ?? 's/d')
                . ' MVA, disponible ' . ($set['disponible_total_mva'] ?? 's/d') . ' MVA, estado ' . ($set['estado'] ?? 's/d') . '.';
        }
    }
    $lines[] = 'Razones:';
    foreach ($analysis['razones'] as $reason) $lines[] = '- ' . $reason;
    $lines[] = 'Nota: es un preanálisis con capas cargadas; no reemplaza estudio eléctrico, demanda sustentada ni revisión legal del expediente.';
    return implode("\n", $lines);
}

function zCleanAiText(string $text): string {
    $text = preg_replace('/\*\*([^*]+)\*\*/u', '$1', $text);
    $text = preg_replace('/__([^_]+)__/u', '$1', $text);
    $text = preg_replace('/`{1,3}/u', '', $text);
    $text = preg_replace('/^\s{0,3}#{1,6}\s*/mu', '', $text);
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/u', '$1 ($2)', $text);
    return trim((string)$text);
}

function zAiMemoryContext(): array {
    $path = defined('AI_MEMORY_FILE') ? AI_MEMORY_FILE : dirname(ROOT) . '/.geovisor_ai_memory.json';
    if (!is_file($path)) return ['modo'=>'memoria_local_contextual', 'criterios_aprendidos'=>[], 'consultas_recientes'=>[]];
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data)) return ['modo'=>'memoria_local_contextual', 'criterios_aprendidos'=>[], 'consultas_recientes'=>[]];
    $prefs = [];
    foreach (array_slice(is_array($data['preferences'] ?? null) ? $data['preferences'] : [], -12) as $pref) {
        if (!is_array($pref)) continue;
        $text = trim((string)($pref['text'] ?? ''));
        if ($text !== '') $prefs[] = ['criterio'=>zCleanAiText($text), 'fecha'=>(string)($pref['last_seen_at'] ?? $pref['created_at'] ?? '')];
    }
    $recent = [];
    foreach (array_slice(is_array($data['entries'] ?? null) ? $data['entries'] : [], -10) as $entry) {
        if (!is_array($entry)) continue;
        $query = trim((string)($entry['query'] ?? ''));
        if ($query !== '') $recent[] = ['consulta'=>zCleanAiText($query), 'temas'=>array_slice(is_array($entry['tags'] ?? null) ? $entry['tags'] : [], 0, 6)];
    }
    return [
        'modo'=>'memoria_local_contextual',
        'descripcion'=>'Preferencias y consultas previas guardadas localmente; no entrena el modelo base de OpenAI.',
        'criterios_aprendidos'=>$prefs,
        'consultas_recientes'=>$recent,
    ];
}
function zPowerShellOpenAi(string $key, string $body): array {
    if (PHP_OS_FAMILY !== 'Windows' || !function_exists('shell_exec')) return ['body'=>false, 'code'=>0, 'error'=>'PowerShell no disponible'];
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    $tmp = tempnam(CACHE_DIR, 'openai_');
    if ($tmp === false || file_put_contents($tmp, $body) === false) {
        return ['body'=>false, 'code'=>0, 'error'=>'No se pudo preparar la solicitud OpenAI'];
    }
    $ps = getenv('SystemRoot') ? getenv('SystemRoot') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe' : 'powershell.exe';
    $key64 = base64_encode($key);
    $path64 = base64_encode($tmp);
    $script = '[Console]::OutputEncoding=New-Object System.Text.UTF8Encoding $false; $OutputEncoding=[Console]::OutputEncoding;'
        . '$ProgressPreference="SilentlyContinue";'
        . '$k=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $key64 . '"));'
        . '$p=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $path64 . '"));'
        . '$b=Get-Content -LiteralPath $p -Raw -Encoding UTF8;'
        . '$bytes=[Text.Encoding]::UTF8.GetBytes($b);'
        . '$h=@{Authorization=("Bearer "+$k)};'
        . 'try {'
        . '$r=Invoke-WebRequest -Uri "https://api.openai.com/v1/responses" -Method Post -Headers $h -ContentType "application/json; charset=utf-8" -Body $bytes -UseBasicParsing -TimeoutSec 90;'
        . 'Write-Output ("__CODE__"+$r.StatusCode); Write-Output "__BODY__"; [Console]::Out.Write($r.Content);'
        . '} catch {'
        . '$status=0; $content=$_.Exception.Message;'
        . 'if ($_.Exception.Response) { $status=[int]$_.Exception.Response.StatusCode; $sr=New-Object IO.StreamReader($_.Exception.Response.GetResponseStream()); $content=$sr.ReadToEnd(); }'
        . 'Write-Output ("__CODE__"+$status); Write-Output "__BODY__"; [Console]::Out.Write($content);'
        . '}';
    $encoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $script));
    $cmd = '"' . $ps . '" -NoProfile -ExecutionPolicy Bypass -EncodedCommand ' . $encoded . ' 2>NUL';
    $out = @shell_exec($cmd);
    @unlink($tmp);
    if (is_string($out) && preg_match('/__CODE__(\d+)\R__BODY__\R?(.*)$/s', $out, $m)) {
        return ['body'=>$m[2], 'code'=>(int)$m[1], 'error'=>''];
    }
    return ['body'=>$out, 'code'=>is_string($out) && trim($out) !== '' ? 200 : 0, 'error'=>is_string($out) ? '' : 'Sin respuesta'];
}

function zOpenAiReport(array $analysis): array {
    $seed = hash('sha256', json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($analysis));
    $keys = function_exists('getOpenAIKeys') ? getOpenAIKeys() : [];
    $primaryKey = getOpenAIKey($seed);
    $keyOrder = [];
    if ($primaryKey !== '') $keyOrder[] = $primaryKey;
    foreach ($keys as $candidateKey) {
        $candidateKey = trim((string)$candidateKey);
        if ($candidateKey !== '' && !in_array($candidateKey, $keyOrder, true)) $keyOrder[] = $candidateKey;
    }
    if (!$keyOrder) {
        return [
            'enabled'=>false,
            'message'=>'Configure OPENAI_API_KEYS, OPENAI_API_KEY, OPENAI_API_KEY_1, OPENAI_API_KEY_2 o el archivo externo seguro .openai_api_key para activar el informe con OpenAI.',
        ];
    }
    $model = trim((string)getenv('OPENAI_MODEL'));
    if ($model === '') $model = 'gpt-5.4-mini';
    $memoryContext = zAiMemoryContext();
    $payload = [
        'model'=>$model,
        'instructions'=>'Eres un analista tecnico-regulatorio de distribucion electrica y desarrollo territorial en Peru. Redacta en espanol claro, no inventes datos, separa hallazgos, riesgos, disponibilidad electrica, relieve, concesiones y siguientes pasos. No uses Markdown visible: nada de asteriscos, negritas ni encabezados con #. Si los datos no bastan, dilo expresamente. Usa aprendizaje_local como memoria contextual de criterios y correcciones previas del geovisor cuando sea pertinente, sin inventar datos que no esten en el analisis o en capas cargadas.',
        'input'=>"Elabora un informe ejecutivo para decidir si la zona es dable para concesion de distribucion o convenio municipal. Usa el umbral de 500 kW y analiza: SET cercanas, capacidad, demanda maxima, disponibilidad, lineas, concesiones/tramites, relieve y posible utilidad agricola si hay capas externas. Aprendizaje local del geovisor:\n" . json_encode($memoryContext, JSON_UNESCAPED_UNICODE) . "\n\nDatos disponibles:\n" . json_encode($analysis, JSON_UNESCAPED_UNICODE),
        'max_output_tokens'=>1400,
    ];
    $body = false;
    $err = '';
    $code = 0;
    $attempts = [];
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
    foreach ($keyOrder as $idx => $attemptKey) {
        $body = false;
        $err = '';
        $code = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init('https://api.openai.com/v1/responses');
            curl_setopt_array($ch, [
                CURLOPT_POST=>true,
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_TIMEOUT=>60,
                CURLOPT_HTTPHEADER=>[
                    'Authorization: Bearer ' . $attemptKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS=>$jsonPayload,
            ] + appCurlTlsOptions());
            $body = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http'=>[
                    'method'=>'POST',
                    'header'=>"Authorization: Bearer $attemptKey\r\nContent-Type: application/json; charset=utf-8\r\n",
                    'content'=>$jsonPayload,
                    'timeout'=>60,
                    'ignore_errors'=>true,
                ],
                'ssl'=>appSslStreamOptions(),
            ]);
            $body = @file_get_contents('https://api.openai.com/v1/responses', false, $ctx);
            $status = $http_response_header[0] ?? '';
            if (preg_match('/\s(\d{3})\s/', $status, $m)) $code = (int)$m[1];
            if ($body === false) {
                $fallback = zPowerShellOpenAi($attemptKey, $jsonPayload);
                $body = $fallback['body'];
                $code = $fallback['code'];
                $err = $fallback['error'];
            }
        }
        $attempts[] = ['clave'=>'key_' . ($idx + 1), 'http_code'=>$code, 'ok'=>($body !== false && $code >= 200 && $code < 300)];
        if ($body !== false && $code >= 200 && $code < 300) break;
    }
    if ($body === false || $code < 200 || $code >= 300) {
        $safeError = preg_replace('/sk-[A-Za-z0-9._-]{8,}/i', '[clave OpenAI oculta]', (string)($err ?: substr((string)$body, 0, 300)));
        return ['enabled'=>false, 'model'=>$model, 'message'=>'OpenAI no respondio correctamente con ninguna clave configurada.', 'http_code'=>$code, 'attempts'=>$attempts, 'error'=>$safeError];
    }
    $data = json_decode((string)$body, true);
    $text = '';
    if (isset($data['output_text'])) {
        $text = trim((string)$data['output_text']);
    } elseif (isset($data['output']) && is_array($data['output'])) {
        foreach ($data['output'] as $item) {
            foreach (($item['content'] ?? []) as $content) {
                if (isset($content['text'])) $text .= $content['text'] . "\n";
            }
        }
        $text = trim($text);
    }
    return [
        'enabled'=>true,
        'model'=>$model,
        'texto'=>zCleanAiText($text ?: 'OpenAI devolvio respuesta sin texto legible.'),
        'attempts'=>$attempts,
    ];
}

function zAnalyzePolygon(array $points, ?float $demandKw, bool $useAi): array {
    $area = zAreaKm2($points);
    $scan = zScanPolygon($points);
    $ind = $scan['indicadores'];
    $relief = zReliefAnalysis($points);
    $ceraunico = zCeraunicoAnalysis($points);
    $sets = zAttachPtToSets(zAttachPitToSets(zAttachSetAvailability(zNearestSets($scan['centro'], $points, 5, 45.0))));
    $pitRows = zPitRowsForSets($sets);
    $ptRows = zPtRowsForSets($sets);
    $predios = zPredioAnalysis($points, $area);
    $demandaPerfil = zDemandProfile($points, $scan, $area);
    $restricciones = zRestrictionsSummary($scan, $relief, $predios, $ceraunico);
    $redes = zDistributionNetworkSummary($scan);
    $visitaCampo = zFieldVisitRecommendation($area, $demandaPerfil, $restricciones, $predios);
    $estimated = zEstimateDemand($area, $ind);
    $kw = $demandKw !== null && $demandKw > 0 ? $demandKw : $estimated;
    $viability = zViability($area, $kw, $ind, $relief);
    $analysis = [
        'status'=>'success',
        'tipo'=>'polygon',
        'area_km2'=>round($area, 4),
        'centro'=>['lat'=>round($scan['centro'][0], 6), 'lng'=>round($scan['centro'][1], 6)],
        'demanda_kw_estimada'=>$estimated,
        'demanda_kw_usada'=>round($kw, 2),
        'demanda_origen'=>$demandKw !== null && $demandKw > 0 ? 'manual' : 'estimada por capas',
        'tramite_recomendado'=>zProcedure($kw),
        'viabilidad'=>$viability,
        'relieve'=>$relief,
        'descargas_atmosfericas'=>$ceraunico,
        'sets_cercanas'=>$sets,
        'pit'=>[
            'status'=>$pitRows ? 'success' : 'no_data',
            'message'=>$pitRows ? '' : 'No se encontraron elementos PIT asociados a las SET cercanas evaluadas.',
            'criterio'=>'PIT revisado por nombre explicito de SET cercana; se priorizan adquisiciones, ampliaciones o instalacion de transformadores y se reportan fechas disponibles.',
            'set_rows'=>$pitRows,
        ],
        'pt_coes'=>[
            'status'=>$ptRows ? 'success' : 'no_data',
            'message'=>$ptRows ? '' : 'No se encontraron anteproyectos PT COES asociados a las SET cercanas evaluadas.',
            'criterio'=>'PT revisado por coincidencia explicita del nombre de SET/instalacion; proyectos nuevos se consideran solo cuando el contexto territorial lo sustenta.',
            'set_rows'=>$ptRows,
        ],
        'predios_api'=>$predios,
        'demanda_perfil'=>$demandaPerfil,
        'restricciones'=>$restricciones,
        'redes_distribucion'=>$redes,
        'visita_campo'=>$visitaCampo,
        'indicadores'=>$ind,
        'muestras'=>$scan['muestras'],
        'razones'=>zReasons($ind, $scan['muestras'], $kw, $relief, $sets),
        'criterio'=>'Concesión si la demanda sustentada es mayor a 500 kW; convenio municipal si es menor a 500 kW.',
    ];
    if ($pitRows) {
        $firstPit = $pitRows[0];
        $pitSet = trim((string)($firstPit['set'] ?? ''));
        $pitElement = trim((string)($firstPit['elemento'] ?? $firstPit['proyecto'] ?? 'Elemento PIT'));
        $pitDate = trim((string)($firstPit['fecha_detalle'] ?? $firstPit['fecha'] ?? ''));
        $analysis['razones'][] = 'El PIT registra elementos asociados a SET cercanas'
            . ($pitSet !== '' ? ': ' . $pitSet : '')
            . ($pitElement !== '' ? ' - ' . $pitElement : '')
            . ($pitDate !== '' ? ' (' . $pitDate . ')' : '')
            . '.';
    }
    if ($ptRows) {
        $firstPt = $ptRows[0];
        $analysis['razones'][] = 'El PT COES registra anteproyectos asociados a SET cercanas: '
            . trim((string)($firstPt['set'] ?? 'SET')) . ' - '
            . trim((string)($firstPt['titulo'] ?? 'anteproyecto PT')) . '.';
    }
    if (($ceraunico['status'] ?? '') === 'success') {
        $analysis['razones'][] = 'CERAUNICO OSINERGMIN registra densidad maxima referencial de ' . ($ceraunico['max'] ?? 's/d') . ' rayos/km2/año, nivel ' . ($ceraunico['nivel'] ?? 's/d') . '.';
    }
    if ((float)($predios['porcentaje_area_con_predio'] ?? 0) > 0) {
        $analysis['razones'][] = 'Segun la API de predios cargada, aproximadamente '
            . round((float)$predios['porcentaje_area_con_predio'], 1)
            . '% del poligono presenta predios identificados.';
    }
    if (!empty($demandaPerfil['tipo_probable'])) {
        $analysis['razones'][] = 'Tipo de demanda referencial identificado: ' . $demandaPerfil['tipo_probable'] . '.';
    }
    if (!empty($restricciones['items'])) {
        $analysis['razones'][] = 'Se identificaron restricciones o afectaciones preliminares que deben verificarse en gabinete y campo.';
    }
    $analysis['razones'][] = 'Se recomienda visita a campo de '
        . (int)($visitaCampo['dias_recomendados'] ?? 1)
        . ' dia(s), calculada por area, evidencias productivas/clientes, predios y restricciones.';
    $analysis['informe_texto'] = zLocalReport($analysis);
    if ($useAi) $analysis['ai'] = zOpenAiReport($analysis);
    return $analysis;
}

function zAnalyzePolygonCached(array $points, ?float $demandKw, bool $useAi): array {
    $normalized = array_map(
        static fn(array $p): array => [round((float)$p[0], 6), round((float)$p[1], 6)],
        $points
    );
    $payload = [
        'polygon'=>$normalized,
        'demanda_kw'=>$demandKw !== null ? round($demandKw, 2) : null,
        'use_ai'=>$useAi,
    ];
    return zAnalysisCacheGetOrCompute(
        'zone_polygon',
        $payload,
        static fn(): array => zAnalyzePolygon($points, $demandKw, $useAi),
        $useAi ? 24 * 60 * 60 : 7 * 24 * 60 * 60,
        'zone-polygon-v2'
    );
}

function zLoadSetPoints(int $limit = 300): array {
    $points = [];
    foreach (zSources() as $source) {
        if ($source['kind'] !== 'set') continue;
        $path = zSourceScanPath($source);
        if (!$path || !zShouldScanSource($source, $path)) continue;
        foreach (zReadGeoJson($path) as $feature) {
            if (count($points) >= $limit) return $points;
            $coords = zFeatureCoords($feature, 1);
            if (!$coords) continue;
            $points[] = [
                'lat'=>$coords[0][0],
                'lng'=>$coords[0][1],
                'nombre'=>zFeatureName($feature, $source['label']),
                'fuente'=>$source['label'],
            ];
        }
    }
    return $points;
}

function zLoadLineSamples(int $limit = 5000): array {
    $out = [];
    foreach (zSources() as $source) {
        if (!in_array($source['kind'], ['line','mt'], true)) continue;
        $path = zSourceScanPath($source);
        if (!$path || !zShouldScanSource($source, $path)) continue;
        foreach (zReadGeoJson($path) as $feature) {
            $coords = zFeatureCoords($feature, 200);
            $step = max(1, (int)floor(count($coords) / 10));
            for ($i = 0; $i < count($coords); $i += $step) {
                $out[] = $coords[$i];
                if (count($out) >= $limit) return $out;
            }
        }
    }
    return $out;
}

function zLoadConcessionAreas(int $limit = 20000): array {
    static $cache = [];
    if (isset($cache[$limit])) return $cache[$limit];
    $areas = [];
    foreach (zSources() as $source) {
        if ($source['kind'] !== 'concession') continue;
        $path = zSourceScanPath($source);
        if (!$path || !zShouldScanSource($source, $path)) continue;
        foreach (zReadGeoJson($path) as $feature) {
            if (count($areas) >= $limit) return $cache[$limit] = $areas;
            foreach (zFeaturePolygonRings($feature, 50) as $coords) {
                if (count($areas) >= $limit) return $cache[$limit] = $areas;
                $bbox = zCoordsBbox($coords);
                if ($bbox) $areas[] = ['bbox'=>$bbox, 'coords'=>$coords, 'nombre'=>zFeatureName($feature, $source['label'])];
            }
        }
    }
    return $cache[$limit] = $areas;
}

function zPointCoveredByConcession(float $lat, float $lng, array $areas): bool {
    if (isset($areas['cells'], $areas['areas'])) {
        $key = floor($lat) . ':' . floor($lng);
        $idxs = $areas['cells'][$key] ?? [];
        $pool = [];
        foreach ($idxs as $idx) {
            if (isset($areas['areas'][$idx])) $pool[] = $areas['areas'][$idx];
        }
        $areas = $pool;
    }
    foreach ($areas as $area) {
        $b = $area['bbox'];
        if ($lat < $b['minLat'] || $lat > $b['maxLat'] || $lng < $b['minLng'] || $lng > $b['maxLng']) continue;
        if (zPointInPolygon($lat, $lng, $area['coords'])) return true;
    }
    return false;
}

function zBuildAreaIndex(array $areas): array {
    $cells = [];
    foreach ($areas as $idx => $area) {
        $b = $area['bbox'];
        $minLat = (int)floor($b['minLat']); $maxLat = (int)floor($b['maxLat']);
        $minLng = (int)floor($b['minLng']); $maxLng = (int)floor($b['maxLng']);
        for ($lat = $minLat; $lat <= $maxLat; $lat++) {
            for ($lng = $minLng; $lng <= $maxLng; $lng++) {
                $cells[$lat . ':' . $lng][] = $idx;
            }
        }
    }
    return ['areas'=>$areas, 'cells'=>$cells];
}

function zPolygonCoveredByConcession(array $polygon, array $areas): bool {
    if (isset($areas['areas'])) $areas = $areas['areas'];
    if (count($polygon) < 3) return true;
    $bbox = zPolygonBbox($polygon);
    foreach ($areas as $area) {
        if (!zBboxIntersects($bbox, $area['bbox'])) continue;
        if (zPolygonsIntersect($polygon, $area['coords'])) return true;
    }
    return false;
}

function zOffsetPoint(float $lat, float $lng, float $km, float $bearingDeg): array {
    $rad = deg2rad($bearingDeg);
    $dLat = ($km * cos($rad)) / 111.32;
    $cosLat = max(0.2, cos(deg2rad($lat)));
    $dLng = ($km * sin($rad)) / (111.32 * $cosLat);
    return [$lat + $dLat, $lng + $dLng];
}

function zApproxCoastLng(float $lat): float {
    $pts = [
        [-18.35, -70.45], [-17.20, -71.35], [-15.85, -75.15], [-14.05, -76.30],
        [-12.05, -77.18], [-10.00, -78.25], [-8.10, -79.05], [-6.75, -79.95],
        [-5.20, -81.05], [-3.50, -80.35], [-1.20, -80.85], [0.30, -80.15],
    ];
    usort($pts, fn($a, $b) => $a[0] <=> $b[0]);
    if ($lat <= $pts[0][0]) return $pts[0][1];
    if ($lat >= $pts[count($pts)-1][0]) return $pts[count($pts)-1][1];
    for ($i = 1; $i < count($pts); $i++) {
        if ($lat <= $pts[$i][0]) {
            [$lat0, $lng0] = $pts[$i - 1];
            [$lat1, $lng1] = $pts[$i];
            $t = ($lat - $lat0) / max(0.0001, $lat1 - $lat0);
            return $lng0 + ($lng1 - $lng0) * $t;
        }
    }
    return -80.0;
}

function zLooksOffshore(float $lat, float $lng): bool {
    if ($lat < -18.7 || $lat > 0.5 || $lng < -82.8 || $lng > -68.0) return true;
    if ($lng < zApproxCoastLng($lat) - 0.05) return true;
    return false;
}

function zPolygonLooksOffshore(array $polygon): bool {
    if (count($polygon) < 3) return true;
    $center = zCentroid($polygon);
    if (zLooksOffshore($center[0], $center[1])) return true;
    $off = 0;
    foreach ($polygon as $p) {
        if (zLooksOffshore((float)$p[0], (float)$p[1])) $off++;
    }
    return $off > count($polygon) * 0.35;
}

function zSquarePolygon(float $lat, float $lng, float $radiusKm = 2.2): array {
    return [
        zOffsetPoint($lat, $lng, $radiusKm, 315),
        zOffsetPoint($lat, $lng, $radiusKm, 45),
        zOffsetPoint($lat, $lng, $radiusKm, 135),
        zOffsetPoint($lat, $lng, $radiusKm, 225),
    ];
}

function zStableSeed(string $value): int {
    return (int)sprintf('%u', crc32($value));
}

function zSeedNoise(int $seed, int $i): float {
    $n = sin(($seed + ($i + 1) * 57.19) * 12.9898) * 43758.5453;
    return abs($n - floor($n));
}

function zOrganicPolygon(float $lat, float $lng, float $radiusKm = 2.0, int $vertices = 9, int $seed = 1): array {
    $vertices = max(6, min(14, $vertices));
    $step = 360.0 / $vertices;
    $start = fmod((float)$seed, 360.0);
    $points = [];
    for ($i = 0; $i < $vertices; $i++) {
        $jitter = (zSeedNoise($seed, $i) - 0.5) * $step * 0.42;
        $factor = 0.74 + zSeedNoise($seed + 711, $i) * 0.54;
        if ($i % 3 === 0) $factor += 0.10;
        $factor = zClamp($factor, 0.68, 1.34);
        $points[] = zOffsetPoint($lat, $lng, $radiusKm * $factor, $start + $i * $step + $jitter);
    }
    return $points;
}

function zCountNearSamples(array $samples, float $lat, float $lng, float $radiusKm, int $cap = 20): int {
    $count = 0;
    foreach ($samples as $p) {
        if (zDistanceKm($lat, $lng, $p[0], $p[1]) <= $radiusKm) $count++;
        if ($count >= $cap) return $count;
    }
    return $count;
}

function zBearingDeg(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $lat1 = deg2rad($lat1); $lat2 = deg2rad($lat2);
    $dLng = deg2rad($lng2 - $lng1);
    $y = sin($dLng) * cos($lat2);
    $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLng);
    return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
}

function zFetchJsonUrl(string $url): array {
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>true,
            CURLOPT_CONNECTTIMEOUT=>15,
            CURLOPT_TIMEOUT=>55,
            CURLOPT_HTTPHEADER=>['Accept: application/json'],
        ] + appCurlTlsOptions());
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            throw new RuntimeException($err ?: 'HTTP ' . $code);
        }
    } else {
        $body = @file_get_contents($url);
        if ($body === false && PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
            $ps = getenv('SystemRoot') ? getenv('SystemRoot') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe' : 'powershell.exe';
            $url64 = base64_encode($url);
            $script = '[Console]::OutputEncoding=New-Object System.Text.UTF8Encoding $false;'
                . '$ProgressPreference="SilentlyContinue";'
                . '$u=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $url64 . '"));'
                . '$r=Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 60;'
                . '$r.Content';
            $encoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $script));
            $cmd = '"' . $ps . '" -NoProfile -ExecutionPolicy Bypass -EncodedCommand ' . $encoded . ' 2>NUL';
            $out = @shell_exec($cmd);
            if (is_string($out) && trim($out) !== '') $body = $out;
        }
        if ($body === false) throw new RuntimeException('No se pudo leer fuente externa.');
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data)) throw new RuntimeException('Respuesta externa no es JSON valido.');
    if (isset($data['error'])) throw new RuntimeException($data['error']['message'] ?? 'Error de ArcGIS');
    return $data;
}

function zEsriGeomCenter(array $geometry): ?array {
    if (isset($geometry['x'], $geometry['y'])) return [(float)$geometry['y'], (float)$geometry['x']];
    $pts = [];
    if (isset($geometry['rings']) && is_array($geometry['rings'])) {
        foreach ($geometry['rings'] as $ring) {
            foreach ($ring as $pt) {
                if (is_array($pt) && count($pt) >= 2) $pts[] = [(float)$pt[1], (float)$pt[0]];
                if (count($pts) >= 600) break 2;
            }
        }
    } elseif (isset($geometry['paths']) && is_array($geometry['paths'])) {
        foreach ($geometry['paths'] as $path) {
            foreach ($path as $pt) {
                if (is_array($pt) && count($pt) >= 2) $pts[] = [(float)$pt[1], (float)$pt[0]];
                if (count($pts) >= 600) break 2;
            }
        }
    }
    return $pts ? zCentroid($pts) : null;
}

function zArcgisProductiveSamples(string $service, int $layer, array $bbox, string $where, string $fields, string $source, string $kind, int $limit = 240): array {
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    $cache = CACHE_DIR . '/productive_' . sha1($service . '|' . $layer . '|' . implode(',', $bbox) . '|' . $where . '|' . $fields) . '.json';
    if (is_file($cache) && filemtime($cache) > time() - 7 * 24 * 60 * 60) {
        $cached = json_decode((string)file_get_contents($cache), true);
        if (is_array($cached)) return $cached;
    }
    $url = rtrim($service, '/') . '/' . $layer . '/query?' . http_build_query([
        'where'=>$where,
        'outFields'=>$fields,
        'returnGeometry'=>'true',
        'outSR'=>'4326',
        'inSR'=>'4326',
        'geometry'=>implode(',', $bbox),
        'geometryType'=>'esriGeometryEnvelope',
        'spatialRel'=>'esriSpatialRelIntersects',
        'f'=>'json',
        'resultRecordCount'=>$limit,
        'maxAllowableOffset'=>'0.01',
        'geometryPrecision'=>'5',
    ]);
    $data = zFetchJsonUrl($url);
    $samples = [];
    foreach (($data['features'] ?? []) as $feature) {
        if (!is_array($feature)) continue;
        $attrs = is_array($feature['attributes'] ?? null) ? $feature['attributes'] : [];
        if ($kind === 'acuicola' && stripos((string)($attrs['AMBITO'] ?? ''), 'MARICULTURA') !== false) continue;
        $center = zEsriGeomCenter(is_array($feature['geometry'] ?? null) ? $feature['geometry'] : []);
        if (!$center) continue;
        $dep = zAttrsDepartment($attrs);
        $samples[] = [
            'lat'=>$center[0],
            'lng'=>$center[1],
            'source'=>$source,
            'kind'=>$kind,
            'name'=>zPick($attrs, ['NOM_SE','ZONA','NOMBRE_RECURSO','ESPECIE','NOMBDIST','DISTRITO'], $source),
            'departamento'=>$dep,
            'provincia'=>zPick($attrs, ['NOMBPROV','PROVINCIA'], ''),
            'distrito'=>zPick($attrs, ['NOMBDIST','DISTRITO'], ''),
            'macro_region'=>zNaturalRegion($center[0], $center[1], $dep),
            'area_ha'=>zNum($attrs['MNSA_2024'] ?? ($attrs['AREA_TOTAL_HA'] ?? null)) ?? 0,
            'attrs'=>$attrs,
        ];
    }
    file_put_contents($cache, json_encode($samples, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $samples;
}

function zProductiveSamplesNear(float $lat, float $lng, float $radiusKm = 55, array $filters = []): array {
    $padLat = $radiusKm / 111.32;
    $padLng = $radiusKm / (111.32 * max(0.25, cos(deg2rad($lat))));
    $bbox = [$lng - $padLng, $lat - $padLat, $lng + $padLng, $lat + $padLat];
    $sources = [
        [
            'service'=>'https://winlmprap24.midagri.gob.pe/arcgis_server/rest/services/ENIS/MNSA/MapServer',
            'layer'=>0,
            'where'=>'1=1',
            'fields'=>'ID_SE,NOMBDEP,NOMBPROV,NOMBDIST,NOM_SE,MNSA_2024,USO',
            'source'=>'MIDAGRI superficie agricola',
            'kind'=>'agricola',
            'limit'=>80,
        ],
        [
            'service'=>'http://sigeo.produce.gob.pe:6080/arcgis/rest/services/acuicultura/CATASTRO_ACUICOLA_GDB_WEB_WMS/MapServer',
            'layer'=>0,
            'where'=>"ESTADO='VIGENTE' AND AMBITO='CONTINENTAL'",
            'fields'=>'CODIGO,TITULAR,AREA_TOTAL_HA,TIPO_RECURSO,NOMBRE_RECURSO,ESPECIE,AMBITO,TIPO_DERECHO,CATEGORIA_PRODUCTIVA,ESTADO,DEPARTAMENTO,PROVINCIA,DISTRITO,ZONA',
            'source'=>'PRODUCE acuicultura continental',
            'kind'=>'acuicola',
            'limit'=>80,
        ],
    ];
    $samples = [];
    foreach ($sources as $src) {
        try {
            foreach (zArcgisProductiveSamples($src['service'], $src['layer'], $bbox, $src['where'], $src['fields'], $src['source'], $src['kind'], $src['limit']) as $sample) {
                $attrs = is_array($sample['attrs'] ?? null) ? $sample['attrs'] : [];
                if (empty($sample['departamento'])) $sample['departamento'] = zAttrsDepartment($attrs);
                if (empty($sample['macro_region'])) $sample['macro_region'] = zNaturalRegion((float)$sample['lat'], (float)$sample['lng'], (string)($sample['departamento'] ?? ''));
                if (!zOpportunityMatchesFilters($sample['lat'], $sample['lng'], $attrs, $filters)) continue;
                $d = zDistanceKm($lat, $lng, $sample['lat'], $sample['lng']);
                if ($d <= $radiusKm) {
                    $sample['dist_set_km'] = $d;
                    $samples[] = $sample;
                }
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    usort($samples, function($a, $b) {
        $ad = $a['dist_set_km'] ?? 999;
        $bd = $b['dist_set_km'] ?? 999;
        return ($ad <=> $bd) ?: (($b['area_ha'] ?? 0) <=> ($a['area_ha'] ?? 0));
    });
    return $samples;
}

function zProductiveEvidence(float $lat, float $lng, array $samples, float $radiusKm = 7): array {
    $near = [];
    foreach ($samples as $sample) {
        $d = zDistanceKm($lat, $lng, $sample['lat'], $sample['lng']);
        if ($d <= $radiusKm) {
            $sample['distancia_km'] = $d;
            $near[] = $sample;
        }
    }
    usort($near, fn($a, $b) => ($a['distancia_km'] <=> $b['distancia_km']));
    $sources = [];
    $score = 0.0;
    foreach (array_slice($near, 0, 12) as $sample) {
        $sources[$sample['source']] = true;
        $score += $sample['kind'] === 'agricola' ? 1.4 : 1.1;
        $score += min(1.5, ((float)($sample['area_ha'] ?? 0)) / 120);
    }
    return [
        'count'=>count($near),
        'score'=>$score,
        'sources'=>array_keys($sources),
        'nearest'=>$near[0] ?? null,
        'samples'=>array_slice(array_map(fn($s) => [
            'fuente'=>$s['source'],
            'tipo'=>$s['kind'],
            'nombre'=>$s['name'],
            'distancia_km'=>round($s['distancia_km'], 2),
            'area_ha'=>round((float)($s['area_ha'] ?? 0), 2),
        ], $near), 0, 5),
    ];
}

function zOpportunityDepartments(): array {
    return [
        'amazonas'=>'Amazonas','ancash'=>'Ancash','apurimac'=>'Apurimac','arequipa'=>'Arequipa',
        'ayacucho'=>'Ayacucho','cajamarca'=>'Cajamarca','callao'=>'Callao','cusco'=>'Cusco',
        'huancavelica'=>'Huancavelica','huanuco'=>'Huanuco','ica'=>'Ica','junin'=>'Junin',
        'lalibertad'=>'La Libertad','lambayeque'=>'Lambayeque','lima'=>'Lima','loreto'=>'Loreto',
        'madrededios'=>'Madre de Dios','moquegua'=>'Moquegua','pasco'=>'Pasco','piura'=>'Piura',
        'puno'=>'Puno','sanmartin'=>'San Martin','tacna'=>'Tacna','tumbes'=>'Tumbes','ucayali'=>'Ucayali',
    ];
}

function zDepartmentMacro(string $department): string {
    $d = zNormText($department);
    $selva = ['amazonas','loreto','madrededios','sanmartin','ucayali'];
    $costa = ['callao','tumbes','piura','lambayeque','lalibertad','lima','ica','arequipa','moquegua','tacna'];
    if (in_array($d, $selva, true)) return 'selva';
    if (in_array($d, $costa, true)) return 'costa';
    return 'sierra';
}

function zNaturalRegion(float $lat, float $lng, string $department = ''): string {
    if ($department !== '') return zDepartmentMacro($department);
    if ($lng > -74.05) return 'selva';
    if ($lng < zApproxCoastLng($lat) + 0.80) return 'costa';
    return 'sierra';
}

function zNormalizeOpportunityFilters(array $filters): array {
    $macro = strtolower((string)($filters['macro_region'] ?? ''));
    if (!in_array($macro, ['costa','sierra','selva'], true)) $macro = '';
    $deps = [];
    foreach (($filters['departamentos'] ?? []) as $dep) {
        $k = zNormText((string)$dep);
        if ($k !== '') $deps[$k] = true;
    }
    $target = is_array($filters['target_center'] ?? null) ? $filters['target_center'] : [];
    $targetLat = $filters['target_lat'] ?? $target['lat'] ?? null;
    $targetLng = $filters['target_lng'] ?? $target['lng'] ?? null;
    $targetLabel = trim((string)($filters['target_label'] ?? $target['label'] ?? ''));
    $targetDep = trim((string)($filters['target_departamento'] ?? $target['departamento'] ?? ''));
    $targetRadius = (float)($filters['target_radius_km'] ?? $target['radius_km'] ?? 95);
    $hasTarget = is_numeric($targetLat) && is_numeric($targetLng);
    if ($hasTarget) {
        $targetLat = (float)$targetLat;
        $targetLng = (float)$targetLng;
        if ($targetLat < -18.7 || $targetLat > 0.5 || $targetLng < -82.8 || $targetLng > -68.0) $hasTarget = false;
        if ($hasTarget && zLooksOffshore($targetLat, $targetLng)) $hasTarget = false;
        if ($hasTarget && $targetDep !== '') $deps[zNormText($targetDep)] = true;
        if ($hasTarget && $macro === '') $macro = zNaturalRegion($targetLat, $targetLng, $targetDep);
    }
    $sources = [];
    foreach (($filters['fuentes_generacion'] ?? []) as $src) {
        $s = zNormText((string)$src);
        if (in_array($s, ['solar','eolica','biomasa','termica','hidro','hidroelectrica'], true)) {
            $sources[$s === 'hidroelectrica' ? 'hidro' : $s] = true;
        }
    }
    return [
        'macro_region'=>$macro,
        'departamentos'=>array_keys($deps),
        'fuentes_generacion'=>array_keys($sources),
        'incluir_generacion'=>!empty($filters['incluir_generacion']) || !empty($sources) || !empty($filters['sin_set_cercana']),
        'sin_set_cercana'=>!empty($filters['sin_set_cercana']),
        'distribuir_por_set'=>!empty($filters['distribuir_por_set']),
        'seed'=>(int)($filters['seed'] ?? 0),
        'target_lat'=>$hasTarget ? $targetLat : null,
        'target_lng'=>$hasTarget ? $targetLng : null,
        'target_label'=>$hasTarget ? $targetLabel : '',
        'target_departamento'=>$hasTarget ? $targetDep : '',
        'target_radius_km'=>$hasTarget ? zClamp($targetRadius, 25, 450) : 0,
    ];
}

function zAttrsDepartment(array $attrs): string {
    return zPick($attrs, ['NOMBDEP','DEPARTAMENTO','DEP','REGION','REGIÓN','nomdpto','DEPARTAMEN'], '');
}

function zOpportunityMatchesFilters(float $lat, float $lng, array $attrs, array $filters): bool {
    $filters = zNormalizeOpportunityFilters($filters);
    $dep = zAttrsDepartment($attrs);
    if ($filters['departamentos']) {
        $depKey = zNormText($dep);
        if ($depKey === '' || !in_array($depKey, $filters['departamentos'], true)) return false;
    }
    if ($filters['macro_region']) {
        if (zNaturalRegion($lat, $lng, $dep) !== $filters['macro_region']) return false;
    }
    if ($filters['target_lat'] !== null && $filters['target_lng'] !== null) {
        if (zDistanceKm($lat, $lng, (float)$filters['target_lat'], (float)$filters['target_lng']) > (float)$filters['target_radius_km']) return false;
    }
    return true;
}

function zGenerationForRegion(string $macro, array $requested, array $evidence = []): array {
    $alts = zGenerationAlternatives($macro, $requested, ['count'=>count($evidence), 'samples'=>[]], 0, 0, 0, 0);
    return array_map(fn($a) => $a['fuente'], $alts);
}

function zGenerationReason(string $source, string $macro): string {
    return match ($source) {
        'solar' => 'Alternativa referencial por radiacion favorable y posibilidad de suministro distribuido.',
        'eolica' => 'Alternativa referencial por potencial de viento en costa o crestas andinas; requiere medición anemométrica.',
        'biomasa' => 'Alternativa referencial por evidencia agrícola/agroindustrial cercana que podría sustentar biomasa.',
        'hidro' => 'Alternativa referencial para sierra o selva alta; requiere validación hidrológica, caudal y permisos.',
        'termica' => 'Alternativa de respaldo para demanda aislada donde no hay SET cercana ni recurso renovable confirmado.',
        default => 'Alternativa referencial de generación local por ausencia de SET cercana.',
    } . ' Region natural estimada: ' . ucfirst($macro) . '.';
}

function zGenerationLabel(string $source): string {
    return match ($source) {
        'solar' => 'Solar fotovoltaica',
        'eolica' => 'Eólica',
        'hidro' => 'Hidroeléctrica',
        'biomasa' => 'Biomasa',
        'termica' => 'Térmica/respaldo',
        default => ucfirst($source),
    };
}

function zGenerationAlternatives(string $macro, array $requested, array $evidence, int $nearLines, int $nearExternal, float $lat, float $lng): array {
    $requestedMap = [];
    foreach ($requested as $src) $requestedMap[zNormText((string)$src)] = true;
    $count = (int)($evidence['count'] ?? 0);
    $sources = is_array($evidence['sources'] ?? null) ? $evidence['sources'] : [];
    $hasAgriculture = false;
    $hasAquaculture = false;
    foreach ($sources as $src) {
        $n = zNormText((string)$src);
        if (str_contains($n, 'midagri') || str_contains($n, 'agric')) $hasAgriculture = true;
        if (str_contains($n, 'produce') || str_contains($n, 'acuic')) $hasAquaculture = true;
    }
    foreach (($evidence['samples'] ?? []) as $sample) {
        $kind = zNormText((string)($sample['tipo'] ?? $sample['kind'] ?? ''));
        if ($kind === 'agricola') $hasAgriculture = true;
        if ($kind === 'acuicola') $hasAquaculture = true;
    }
    $productiveBonus = min(18, $count * 0.45);
    $lineBonus = min(8, $nearLines * 0.7);
    $coastProximity = $lng < zApproxCoastLng($lat) + 1.15;
    $selvaAltaProxy = $macro === 'selva' && $lng < -75.2;
    $andesProxy = $macro === 'sierra' || $selvaAltaProxy;
    $southernSolar = $lat < -12.0 && $lng < -69.0;

    $raw = [
        'solar' => [
            'score'=>($macro === 'costa' ? 78 : ($macro === 'sierra' ? 72 : 58)) + ($southernSolar ? 8 : 0) + min(5, $lineBonus),
            'razon'=>($macro === 'selva'
                ? 'Viable como generación distribuida o híbrida; requiere revisar nubosidad local y disponibilidad de terreno firme.'
                : 'Buena alternativa por potencial solar nacional y facilidad modular; requiere evaluar radiacion, sombra y acceso a red.'),
            'condicion'=>'Validar radiacion, area disponible, servidumbre y punto de conexion.',
        ],
        'eolica' => [
            'score'=>($macro === 'costa' ? 70 : ($macro === 'sierra' ? 58 : 34)) + ($coastProximity ? 8 : 0) + ($andesProxy ? 5 : 0),
            'razon'=>($macro === 'selva'
                ? 'Menor prioridad preliminar en selva baja; solo considerar si mediciones locales muestran viento suficiente.'
                : 'Alternativa posible en costa, lomas o crestas andinas; requiere medicion anemometrica.'),
            'condicion'=>'Instalar medicion de viento y revisar accesos para transporte de equipos.',
        ],
        'hidro' => [
            'score'=>($macro === 'sierra' ? 76 : ($selvaAltaProxy ? 70 : ($macro === 'selva' ? 48 : 38))) + ($hasAquaculture ? 3 : 0),
            'razon'=>($andesProxy
                ? 'Buena opcion preliminar si existe caudal y desnivel aprovechable cerca de la zona.'
                : 'En selva baja o costa plana suele requerir estudios de caudal y salto; prioridad preliminar moderada o baja.'),
            'condicion'=>'Validar caudal, salto bruto/neto, permisos de agua, ambiente y zona de captacion.',
        ],
        'biomasa' => [
            'score'=>50 + $productiveBonus + ($hasAgriculture ? 18 : 0) + ($macro === 'selva' ? 6 : 0),
            'razon'=>'Alternativa asociada a residuos agrícolas, agroindustriales o biomasa local; depende de volumen constante y logística.',
            'condicion'=>'Cuantificar residuos, humedad, transporte, estacionalidad y contrato de abastecimiento.',
        ],
        'termica' => [
            'score'=>45 + ($nearLines === 0 ? 8 : 0) + ($macro === 'selva' ? 4 : 0),
            'razon'=>'Opcion de respaldo o solucion aislada cuando no hay SET cercana o el recurso renovable no cubre continuidad.',
            'condicion'=>'Revisar combustible, costos operativos, emisiones, permisos y uso solo como respaldo/hibrido.',
        ],
    ];

    $out = [];
    foreach ($raw as $source => $item) {
        $score = (int)round(zClamp((float)$item['score'] + (isset($requestedMap[$source]) ? 8 : 0), 0, 100));
        $out[] = [
            'fuente'=>$source,
            'nombre'=>zGenerationLabel($source),
            'score'=>$score,
            'prioridad'=>$score >= 75 ? 'Alta' : ($score >= 55 ? 'Media' : 'Baja'),
            'razon'=>$item['razon'],
            'condicion'=>$item['condicion'],
            'solicitada'=>isset($requestedMap[$source]),
        ];
    }
    usort($out, fn($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['fuente'], $b['fuente']));
    return $out;
}

function zSeedCentersForGeneration(array $filters): array {
    $filters = zNormalizeOpportunityFilters($filters);
    if ($filters['target_lat'] !== null && $filters['target_lng'] !== null) {
        $departments = zOpportunityDepartments();
        $dep = $filters['target_departamento'];
        if ($dep === '' && $filters['departamentos']) $dep = $departments[$filters['departamentos'][0]] ?? $filters['departamentos'][0];
        $label = $filters['target_label'] !== '' ? $filters['target_label'] : 'zona solicitada';
        return [[ucfirst($label) . ' - zona solicitada', (float)$filters['target_lat'], (float)$filters['target_lng'], $dep]];
    }
    $all = [
        ['Loreto - corredor Iquitos/Nauta', -3.82, -73.35, 'Loreto'],
        ['Ucayali - eje Pucallpa', -8.38, -74.56, 'Ucayali'],
        ['San Martin - eje Tarapoto/Moyobamba', -6.46, -76.28, 'San Martin'],
        ['Madre de Dios - eje Puerto Maldonado', -12.62, -69.18, 'Madre de Dios'],
        ['Amazonas - eje Bagua/Utcubamba', -5.63, -78.53, 'Amazonas'],
        ['Piura - agroindustria norte', -5.25, -80.05, 'Piura'],
        ['Lambayeque - Olmos/Motupe', -6.05, -79.72, 'Lambayeque'],
        ['Ica - valles agroexportadores', -14.08, -75.72, 'Ica'],
        ['Arequipa - Majes/Siguas', -16.30, -72.20, 'Arequipa'],
        ['Tacna - La Yarada', -18.12, -70.25, 'Tacna'],
        ['Cajamarca - corredores altoandinos', -6.78, -78.45, 'Cajamarca'],
        ['Ayacucho - valles interandinos', -13.15, -74.25, 'Ayacucho'],
        ['Cusco - La Convencion', -12.85, -72.70, 'Cusco'],
        ['Puno - altiplano productivo', -15.75, -70.05, 'Puno'],
        ['Huancavelica - corredor andino', -12.78, -74.97, 'Huancavelica'],
    ];
    return array_values(array_filter($all, function($c) use ($filters) {
        $dep = zNormText($c[3]);
        if ($filters['departamentos'] && !in_array($dep, $filters['departamentos'], true)) return false;
        if ($filters['macro_region'] && zDepartmentMacro($c[3]) !== $filters['macro_region']) return false;
        return true;
    }));
}

function zGenerationCandidates(array $filters, array $areaIndex, array $lineSamples, array $external, array $existing, int $limit = 12): array {
    $filters = zNormalizeOpportunityFilters($filters);
    $out = [];
    $requested = $filters['fuentes_generacion'];
    foreach (zSeedCentersForGeneration($filters) as $idx => $centerInfo) {
        [$label, $baseLat, $baseLng, $dep] = $centerInfo;
        $macro = $dep !== '' ? zDepartmentMacro($dep) : zNaturalRegion($baseLat, $baseLng);
        $searchRadius = $filters['target_radius_km'] > 0 ? max(45, min(160, (float)$filters['target_radius_km'])) : ($macro === 'selva' ? 95 : 70);
        $samples = zProductiveSamplesNear($baseLat, $baseLng, $searchRadius, $filters);
        if (!$samples) {
            $samples = [[
                'lat'=>$baseLat,
                'lng'=>$baseLng,
                'source'=>'Semilla territorial referencial',
                'kind'=>'territorial',
                'name'=>$label,
                'departamento'=>$dep,
                'macro_region'=>$macro,
                'area_ha'=>0,
                'attrs'=>['DEPARTAMENTO'=>$dep],
            ]];
        }
        foreach (array_slice($samples, 0, 10) as $sampleIdx => $sample) {
            $lat = (float)$sample['lat'];
            $lng = (float)$sample['lng'];
            if (zLooksOffshore($lat, $lng)) continue;

            $baseSeed = zStableSeed($label . '|' . $sampleIdx . '|' . ($filters['seed'] ?? 0));
            $radii = $macro === 'selva'
                ? [18.0, 14.0, 10.0, 7.0, 5.0]
                : ($macro === 'sierra' ? [12.0, 9.0, 6.5, 4.5] : [10.0, 7.5, 5.0, 3.8]);
            $centers = [[$lat, $lng, 0.0]];
            $bearingOffset = ($baseSeed % 360);
            foreach ([6.0, 12.0, 20.0, 32.0, 45.0] as $offsetKm) {
                foreach ([0, 45, 90, 135, 180, 225, 270, 315] as $bearing) {
                    [$olat, $olng] = zOffsetPoint($lat, $lng, $offsetKm, $bearing + $bearingOffset);
                    $centers[] = [$olat, $olng, $offsetKm];
                }
            }

            $created = false;
            $seenCenters = [];
            foreach ($centers as $centerIdx => $center) {
                [$clat, $clng, $offsetKm] = $center;
                $centerKey = round($clat, 3) . ':' . round($clng, 3);
                if (isset($seenCenters[$centerKey])) continue;
                $seenCenters[$centerKey] = true;
                if ($clat < -18.7 || $clat > 0.5 || $clng < -82.7 || $clng > -68.0) continue;
                if (zLooksOffshore($clat, $clng)) continue;
                if (zPointCoveredByConcession($clat, $clng, $areaIndex)) continue;

                foreach ($radii as $ri => $radiusKm) {
                    $poly = zOrganicPolygon($clat, $clng, $radiusKm, 9 + (($idx + $sampleIdx + $ri) % 4), $baseSeed + $centerIdx * 97 + $ri * 211);
                    if (zPolygonLooksOffshore($poly) || zPolygonCoveredByConcession($poly, $areaIndex)) continue;
                    if (zCandidateOverlaps($poly, array_merge($existing, $out))) continue;

                    $evidence = zProductiveEvidence($clat, $clng, $samples, max(12, $radiusKm + min(10.0, $offsetKm)));
                    if (($evidence['count'] ?? 0) < 1 && ($sample['kind'] ?? '') !== 'territorial') continue;
                    $sources = zGenerationForRegion($macro, $requested, $samples);
                    $source = $sources[0] ?? 'solar';
                    $nearLines = zCountNearSamples($lineSamples, $clat, $clng, max(18, $radiusKm * 1.2), 25);
                    $nearExternal = zCountNearSamples($external, $clat, $clng, max(14, $radiusKm), 16);
                    $alternatives = zGenerationAlternatives($macro, $requested, $evidence, $nearLines, $nearExternal, $clat, $clng);
                    $source = $alternatives[0]['fuente'] ?? $source;
                    $score = (int)round(zClamp(58 + min(18, ($evidence['score'] ?? 0) * 3.2) + min(10, $nearLines * 1.1) + ($macro === 'selva' ? 6 : 0), 0, 100));
                    $kw = (int)round(max(520, min(18000, 650 + max(1, ($evidence['count'] ?? 0)) * 420)));
                    $out[] = [
                        'nombre'=>'Generacion local - ' . $label,
                        'fuente'=>'Motor GIS generación local',
                        'lat'=>round($clat, 6),
                        'lng'=>round($clng, 6),
                        'departamento'=>$dep,
                        'macro_region'=>$macro,
                        'polygon'=>array_map(fn($p) => ['lat'=>round($p[0], 6), 'lng'=>round($p[1], 6)], $poly),
                        'score'=>$score,
                        'viabilidad'=>$score >= 72 ? 'Alta' : 'Media',
                        'lineas_cercanas'=>$nearLines,
                        'capas_externas_cercanas'=>$nearExternal,
                        'evidencia_productiva'=>$evidence['count'] ?? 0,
                        'fuentes_productivas'=>$evidence['sources'] ?? [],
                        'muestras_productivas'=>$evidence['samples'] ?? [],
                        'en_concesion_existente'=>false,
                        'radio_referencia_km'=>round($radiusKm, 2),
                        'demanda_kw_referencia'=>$kw,
                        'tramite_recomendado'=>zProcedure($kw),
                        'set_referencia'=>null,
                        'sets_disponibles'=>[],
                        'modo_suministro'=>'generacion_local_sin_set_cercana',
                        'fuente_generacion_recomendada'=>$source,
                        'fuentes_generacion_alternativas'=>array_map(fn($a) => $a['fuente'], $alternatives),
                        'alternativas_generacion'=>$alternatives,
                        'razon_generacion'=>($alternatives[0]['razon'] ?? zGenerationReason($source, $macro)) . ' Región natural estimada: ' . ucfirst($macro) . '.',
                        'razon'=>'Polígono referencial en zona no concesionada con evidencia productiva cercana. Se propone generación local porque no se prioriza una SET cercana en esta alternativa.',
                    ];
                    $created = true;
                    if (count($out) >= $limit) return $out;
                    break 2;
                }
            }
            if ($created) break;
        }
    }
    return $out;
}

function zCandidateOverlaps(array $polygon, array $candidates): bool {
    $center = zCentroid($polygon);
    foreach ($candidates as $candidate) {
        $other = [];
        foreach (($candidate['polygon'] ?? []) as $p) {
            if (isset($p['lat'], $p['lng'])) $other[] = [(float)$p['lat'], (float)$p['lng']];
        }
        if (count($other) < 3) continue;
        if (zDistanceKm($center[0], $center[1], (float)($candidate['lat'] ?? $center[0]), (float)($candidate['lng'] ?? $center[1])) < 10.0) return true;
        if (zPolygonsIntersect($polygon, $other)) return true;
    }
    return false;
}

function zExternalSamples(int $limit = 5000): array {
    $points = [];
    foreach (zSources() as $source) {
        if ($source['kind'] !== 'external') continue;
        $path = zSourceScanPath($source);
        if (!$path || !zShouldScanSource($source, $path)) continue;
        foreach (zReadGeoJson($path) as $feature) {
            $coords = zFeatureCoords($feature, 500);
            foreach ($coords as $pt) {
                $points[] = $pt;
                if (count($points) >= $limit) return $points;
            }
        }
    }
    return $points;
}

function zAnalyzeOpportunities(bool $useAi, array $filters = []): array {
    $filters = zNormalizeOpportunityFilters($filters);
    $sets = zCachedAvailableSets();
    $seenSets = [];
    $seenNames = [];
    foreach ($sets as $set) {
        $seenSets[zNormText(($set['nombre'] ?? '') . '|' . ($set['empresa'] ?? ''))] = true;
        $seenNames[zNormText((string)($set['nombre'] ?? ''))] = true;
    }
    if (!$sets) {
        foreach (zLoadSetPoints(240) as $set) {
            $key = zNormText(($set['nombre'] ?? '') . '|' . ($set['empresa'] ?? ''));
            $nameKey = zNormText((string)($set['nombre'] ?? ''));
            if ($key === '' || isset($seenSets[$key]) || isset($seenNames[$nameKey])) continue;
            $availability = zAvailabilityForSet((string)($set['nombre'] ?? ''), (string)($set['empresa'] ?? ''));
            if (($availability['status'] ?? '') !== 'success') continue;
            if ((float)($availability['disponible_total_mva'] ?? 0) <= 0) continue;
            $set['availability'] = $availability;
            $sets[] = $set;
            $seenSets[$key] = true;
            $seenNames[$nameKey] = true;
            if (count($sets) >= 24) break;
        }
    }
    if ($filters['sin_set_cercana']) $sets = [];
    usort($sets, fn($a, $b) => (($b['availability']['disponible_total_mva'] ?? 0) <=> ($a['availability']['disponible_total_mva'] ?? 0)));
    $lineSamples = zLoadLineSamples();
    $areas = zLoadConcessionAreas();
    $areaIndex = zBuildAreaIndex($areas);
    $external = zExternalSamples();
    $candidates = [];
    $seenZones = [];

    foreach ($sets as $set) {
        $setKey = strtolower(trim($set['nombre'] . '|' . ($set['empresa'] ?? '')));
        if ($setKey === '|') continue;
        if ($filters['macro_region'] && zNaturalRegion((float)$set['lat'], (float)$set['lng']) !== $filters['macro_region']) continue;
        $availability = $set['availability'] ?? zAvailabilityForSet($set['nombre'], $set['empresa'] ?? '');
        $availableMva = (float)($availability['disponible_total_mva'] ?? 0);
        if (($availability['status'] ?? '') !== 'success' || $availableMva <= 0) continue;

        $seed = zStableSeed($setKey);
        $nearLinesAtSet = zCountNearSamples($lineSamples, $set['lat'], $set['lng'], 14, 20);
        $nearExternalAtSet = zCountNearSamples($external, $set['lat'], $set['lng'], 12, 14);
        $productives = zProductiveSamplesNear($set['lat'], $set['lng'], $availableMva >= 10 ? 65 : 42, $filters);
        if (!$productives) continue;

        $baseRadius = zClamp(sqrt($availableMva) * 0.72, 4.5, 18.0);
        $radii = [
            $baseRadius,
            zClamp($baseRadius * 0.82, 4.0, 15.0),
            zClamp($baseRadius * 1.18, 5.2, 22.0),
        ];
        $perSetLimit = $availableMva >= 25 ? 4 : ($availableMva >= 6 ? 3 : 2);
        $createdForSet = 0;

        foreach (array_slice($productives, 0, 55) as $sampleIdx => $sample) {
            $bearingFromSet = zBearingDeg($set['lat'], $set['lng'], $sample['lat'], $sample['lng']);
            $centers = [[$sample['lat'], $sample['lng'], 0.0]];
            foreach ([3.5, 6.5, 10.0, 14.0] as $offsetKm) {
                foreach ([0, 45, -45, 90, -90, 180] as $turn) {
                    [$olat, $olng] = zOffsetPoint($sample['lat'], $sample['lng'], $offsetKm, $bearingFromSet + $turn);
                    $centers[] = [$olat, $olng, $offsetKm];
                }
            }

            foreach ($centers as $center) {
                [$clat, $clng, $offsetKm] = $center;
                if ($clat < -18.7 || $clat > 0.5 || $clng < -82.7 || $clng > -68.0) continue;
                if (zLooksOffshore($clat, $clng)) continue;
                if (zPointCoveredByConcession($clat, $clng, $areaIndex)) continue;
                $evidence = zProductiveEvidence($clat, $clng, $productives, max(11.0, $baseRadius * 0.85));
                if (($evidence['count'] ?? 0) < 1 || ($evidence['score'] ?? 0) < 1.0) continue;

                foreach ($radii as $ri => $radiusKm) {
                    $vertices = 7 + (($seed + $sampleIdx + $ri) % 5);
                    $poly = zOrganicPolygon($clat, $clng, $radiusKm, $vertices, $seed + $sampleIdx * 41 + (int)round($offsetKm * 100) + $ri * 101);
                    if (zPolygonLooksOffshore($poly)) continue;
                    if (zPolygonCoveredByConcession($poly, $areaIndex)) continue;
                    if (zCandidateOverlaps($poly, $candidates)) continue;

                    $zoneKey = round($clat, 2) . ':' . round($clng, 2);
                    if (isset($seenZones[$zoneKey])) continue;
                    $seenZones[$zoneKey] = true;

                    $distKm = zDistanceKm($set['lat'], $set['lng'], $clat, $clng);
                    $nearLines = max($nearLinesAtSet, zCountNearSamples($lineSamples, $clat, $clng, max(14, $radiusKm * 1.1), 20));
                    $nearExternal = max($nearExternalAtSet, zCountNearSamples($external, $clat, $clng, max(12, $radiusKm), 14));
                    $distanceBonus = max(0, 13 - min(13, $distKm * 0.30));
                    $productiveBonus = min(24, ($evidence['score'] ?? 0) * 4.2 + min(8, ($evidence['count'] ?? 0) * 0.55));
                    $score = 28 + min(34, $availableMva * 2.5) + min(14, $nearLines * 1.8) + min(6, $nearExternal * 1.2) + $distanceBonus + $productiveBonus;
                    $score = (int)round(zClamp($score, 0, 100));
                    if ($score < 62) continue;

                    $kw = (int)round(max(520, min(25000, $availableMva * 850)));
                    $nearestName = trim((string)($evidence['nearest']['name'] ?? ''));
                    $zoneName = 'Zona sugerida - ' . $set['nombre'];
                    if ($nearestName !== '' && zNormText($nearestName) !== zNormText((string)$set['nombre'])) {
                        $zoneName .= ' / ' . substr($nearestName, 0, 48);
                    }
                    $macroRegion = zNaturalRegion($clat, $clng, (string)($sample['departamento'] ?? ''));
                    $generationAlternatives = zGenerationAlternatives($macroRegion, $filters['fuentes_generacion'], $evidence, $nearLines, $nearExternal, $clat, $clng);
                    $candidates[] = [
                        'nombre'=>$zoneName,
                        'fuente'=>$set['fuente'],
                        'lat'=>round($clat, 6),
                        'lng'=>round($clng, 6),
                        'departamento'=>$sample['departamento'] ?? '',
                        'macro_region'=>$macroRegion,
                        'polygon'=>array_map(fn($p) => ['lat'=>round($p[0], 6), 'lng'=>round($p[1], 6)], $poly),
                        'score'=>$score,
                        'viabilidad'=>$score >= 72 ? 'Alta' : 'Media',
                        'lineas_cercanas'=>$nearLines,
                        'capas_externas_cercanas'=>$nearExternal,
                        'evidencia_productiva'=>$evidence['count'] ?? 0,
                        'fuentes_productivas'=>$evidence['sources'] ?? [],
                        'muestras_productivas'=>$evidence['samples'] ?? [],
                        'en_concesion_existente'=>false,
                        'radio_referencia_km'=>round($radiusKm, 2),
                        'demanda_kw_referencia'=>$kw,
                        'tramite_recomendado'=>zProcedure($kw),
                        'set_referencia'=>[
                            'nombre'=>$set['nombre'],
                            'empresa'=>$set['empresa'] ?? '',
                            'lat'=>round($set['lat'], 6),
                            'lng'=>round($set['lng'], 6),
                            'distancia_km'=>round($distKm, 1),
                            'capacidad_total_mva'=>$availability['capacidad_total_mva'] ?? null,
                            'demanda_total_mva'=>$availability['demanda_total_mva'] ?? null,
                            'disponible_total_mva'=>$availability['disponible_total_mva'] ?? null,
                            'estado'=>$availability['estado'] ?? '',
                        ],
                        'sets_disponibles'=>[[
                            'nombre'=>$set['nombre'],
                            'empresa'=>$set['empresa'] ?? '',
                            'distancia_km'=>round($distKm, 1),
                            'disponible_total_mva'=>$availability['disponible_total_mva'] ?? null,
                        ]],
                        'modo_suministro'=>'distribucion_desde_set_disponible',
                        'fuente_generacion_recomendada'=>$generationAlternatives[0]['fuente'] ?? '',
                        'fuentes_generacion_alternativas'=>array_map(fn($a) => $a['fuente'], $generationAlternatives),
                        'alternativas_generacion'=>$generationAlternatives,
                        'razon_generacion'=>isset($generationAlternatives[0]) ? ($generationAlternatives[0]['razon'] . ' Como existe SET disponible, evaluar generación como suministro complementario o proyecto híbrido con distribución local.') : '',
                        'razon'=>'Polígono orgánico generado alrededor de evidencia productiva MIDAGRI/PRODUCE, fuera de concesiones vigentes/en trámite cargadas, cercano a SET con disponibilidad e infraestructura eléctrica. También incluye evaluación preliminar de generación local complementaria.',
                    ];
                    $createdForSet++;
                    break;
                }
                if ($createdForSet >= $perSetLimit) break 2;
            }
        }
        if (count($candidates) >= 30) break;
    }
    if ($filters['incluir_generacion'] || count($candidates) < 10) {
        $candidates = array_merge($candidates, zGenerationCandidates($filters, $areaIndex, $lineSamples, $external, $candidates, 14));
    }
    usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
    $balanced = [];
    $usedSetNames = [];
    $usedZoneNames = [];
    foreach ($candidates as $candidate) {
        $nameKey = zNormText((string)($candidate['set_referencia']['nombre'] ?? ($candidate['departamento'] ?? $candidate['nombre'] ?? '')));
        $zoneName = zNormText((string)($candidate['nombre'] ?? ''));
        if ($nameKey === '' || isset($usedSetNames[$nameKey]) || isset($usedZoneNames[$zoneName])) continue;
        $balanced[] = $candidate;
        $usedSetNames[$nameKey] = true;
        if ($zoneName !== '') $usedZoneNames[$zoneName] = true;
    }
    foreach ($candidates as $candidate) {
        if (count($balanced) >= 24) break;
        $zoneName = zNormText((string)($candidate['nombre'] ?? ''));
        if ($zoneName !== '' && isset($usedZoneNames[$zoneName])) continue;
        if (in_array($candidate, $balanced, true)) continue;
        $balanced[] = $candidate;
        if ($zoneName !== '') $usedZoneNames[$zoneName] = true;
    }
    if ($filters['seed']) {
        $shift = count($balanced) ? ($filters['seed'] % count($balanced)) : 0;
        if ($shift > 0) $balanced = array_merge(array_slice($balanced, $shift), array_slice($balanced, 0, $shift));
    }
    $analysis = [
        'status'=>'success',
        'tipo'=>'opportunities',
        'criterio'=>'Ranking heurístico nacional filtrado por consulta: SET con disponibilidad calculada, evidencia agrícola MIDAGRI o acuicultura continental PRODUCE, región natural/departamento cuando aplica, alternativas de generación local si no hay SET cercana, y polígonos orgánicos sin superposición entre sí ni con concesiones cargadas.',
        'filtros'=>$filters,
        'candidatos'=>array_slice($balanced, 0, 24),
    ];
    if ($useAi) $analysis['ai'] = zOpenAiReport($analysis);
    return $analysis;
}

if (!defined('ZONE_ANALYSIS_LIB_ONLY')) {
    $mode = (string)($input['mode'] ?? 'polygon');
    $useAi = filter_var($input['use_ai'] ?? false, FILTER_VALIDATE_BOOLEAN);

    try {
        if ($mode === 'opportunities') {
            $filters = [
                'macro_region'=>$input['macro_region'] ?? '',
                'departamentos'=>isset($input['departamentos']) && is_array($input['departamentos']) ? $input['departamentos'] : [],
                'fuentes_generacion'=>isset($input['fuentes_generacion']) && is_array($input['fuentes_generacion']) ? $input['fuentes_generacion'] : [],
                'incluir_generacion'=>filter_var($input['incluir_generacion'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'sin_set_cercana'=>filter_var($input['sin_set_cercana'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'distribuir_por_set'=>filter_var($input['distribuir_por_set'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'seed'=>(int)($input['seed'] ?? 0),
                'target_lat'=>$input['target_lat'] ?? null,
                'target_lng'=>$input['target_lng'] ?? null,
                'target_label'=>$input['target_label'] ?? '',
                'target_departamento'=>$input['target_departamento'] ?? '',
                'target_radius_km'=>$input['target_radius_km'] ?? 0,
            ];
            $payload = ['use_ai'=>$useAi, 'filters'=>$filters];
            $key = zAnalysisCacheKey('opportunities', $payload, 'opportunities-v2');
            $cached = zAnalysisCacheLoad('opportunities', $key, 24 * 60 * 60);
            if ($cached !== null) jsonOut($cached);
            $queue = zOpportunityQueue($key, $payload);
            if (empty($queue['queued'])) {
                jsonOut(['status'=>'error', 'msg'=>$queue['message'] ?? 'No se pudo iniciar el análisis.'], 500);
            }
            jsonOut([
                'status'=>'processing',
                'tipo'=>'opportunities',
                'candidatos'=>[],
                'msg'=>'El análisis territorial se está preparando en segundo plano.',
                'retry_after'=>4,
                '_analysis_cache'=>['key'=>$key, 'hit'=>false, 'pending'=>true],
            ], 202);
        }
        $points = zNormalizePolygon($input['polygon'] ?? []);
        if (count($points) < 3) {
            jsonOut(['status'=>'error','msg'=>'Dibuje un poligono con al menos 3 puntos.'], 400);
        }
        $demandKw = zNum($input['demanda_kw'] ?? null);
        jsonOut(zAnalyzePolygonCached($points, $demandKw, $useAi));
    } catch (Throwable $e) {
        jsonOut(['status'=>'error','msg'=>$e->getMessage()], 500);
    }
}

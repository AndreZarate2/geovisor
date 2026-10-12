<?php
/**
 * api/osinergmin_layer.php — Proxy seguro para capas oficiales OSINERGMIN.
 * Devuelve GeoJSON con atributos reales desde Electricidad/ELECTRICIDAD.
 */
require_once dirname(__DIR__) . '/config.php';
@set_time_limit(300);

if (!isAuth()) { jsonOut(['error' => 'No autorizado'], 401); }

$layerRaw = strtolower(trim((string)($_GET['layer'] ?? '0')));
$isGenerationAggregate = in_array($layerRaw, ['generacion', 'generation', 'centrales', 'centrales_generacion'], true);
$layer = $isGenerationAggregate ? 0 : (int)$layerRaw;
$allowed = [
    3 => 'Centrales hidroelectricas',
    4 => 'Centrales termicas',
    5 => 'Generacion aislada - municipios',
    7 => 'Centrales hidroelectricas proyectadas',
    8 => 'Centrales termicas proyectadas',
    11 => 'Centrales RER en operacion',
    13 => 'Centrales RER proyectadas',
    15 => 'Líneas de transmisión en operación',
    16 => 'Líneas de transmisión proyectadas',
    18 => 'SET en operación',
    19 => 'SET proyectadas',
    21 => 'Salida MT SET',
    22 => 'Tramo Media Tensión',
    25 => 'SED escala 1:30000',
    26 => 'SED escala 1:9000',
    33 => 'Área de Concesión',
];

if (!$isGenerationAggregate && !isset($allowed[$layer])) {
    jsonOut(['error' => 'Capa OSINERGMIN no permitida'], 400);
}
$isMtLayer = !$isGenerationAggregate && $layer === 22;

$bbox = trim((string)($_GET['bbox'] ?? ''));
$bboxParts = $bbox !== '' ? array_map('trim', explode(',', $bbox)) : [];
$hasBbox = count($bboxParts) === 4;
if ($bbox !== '' && !$hasBbox) {
    jsonOut(['error' => 'bbox inválido'], 400);
}
if ($hasBbox) {
    $nums = array_map('floatval', $bboxParts);
    foreach ($nums as $n) {
        if (!is_finite($n)) jsonOut(['error' => 'bbox inválido'], 400);
    }
    [$xmin, $ymin, $xmax, $ymax] = $nums;
    if ($xmin >= $xmax || $ymin >= $ymax || $xmin < -180 || $xmax > 180 || $ymin < -90 || $ymax > 90) {
        jsonOut(['error' => 'bbox fuera de rango'], 400);
    }
    $bbox = implode(',', array_map(fn($n) => rtrim(rtrim(sprintf('%.6F', $n), '0'), '.'), $nums));
}

function osServiceUrl(int $layer): string {
    return $layer === 22 ? URL_MT_REST : URL_ELEC_REST;
}

function osGenerationLayers(): array {
    return [
        3 => ['title' => 'Centrales hidroelectricas', 'categoria' => 'Convencional', 'tecnologia' => 'Hidroelectrica', 'estado' => 'En operacion'],
        4 => ['title' => 'Centrales termicas', 'categoria' => 'Convencional', 'tecnologia' => 'Termica', 'estado' => 'En operacion'],
        5 => ['title' => 'Generacion aislada - municipios', 'categoria' => 'Aislada municipal', 'tecnologia' => 'Generacion aislada', 'estado' => 'En operacion'],
        7 => ['title' => 'Centrales hidroelectricas proyectadas', 'categoria' => 'Convencional', 'tecnologia' => 'Hidroelectrica', 'estado' => 'Proyectada'],
        8 => ['title' => 'Centrales termicas proyectadas', 'categoria' => 'Convencional', 'tecnologia' => 'Termica', 'estado' => 'Proyectada'],
        11 => ['title' => 'Centrales RER en operacion', 'categoria' => 'RER', 'tecnologia' => 'Renovable no convencional', 'estado' => 'En operacion'],
        13 => ['title' => 'Centrales RER proyectadas', 'categoria' => 'RER', 'tecnologia' => 'Renovable no convencional', 'estado' => 'Proyectada'],
    ];
}

if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
$cacheKey = $isGenerationAggregate ? 'generacion' : (string)$layer;
$cacheSuffix = $isMtLayer ? '_ogc_v3' : '';
$cacheSuffix .= $hasBbox ? '_bbox_' . substr(sha1($bbox), 0, 16) : '';
$cache = CACHE_DIR . '/osinergmin_' . $cacheKey . $cacheSuffix . '.geojson';
$ttl = $hasBbox ? 45 * 60 : 6 * 60 * 60;

if (file_exists($cache) && (time() - filemtime($cache) < $ttl)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=600');
    readfile($cache);
    exit;
}

function osFetch(string $url): string {
    if (!function_exists('curl_init')) {
        $body = @file_get_contents($url);
        if ($body === false && PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
            $ps = getenv('SystemRoot') ? getenv('SystemRoot') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe' : 'powershell.exe';
            $url64 = base64_encode($url);
            $script = '[Console]::OutputEncoding=New-Object System.Text.UTF8Encoding $false;'
                . '$ProgressPreference="SilentlyContinue";'
                . '$u=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $url64 . '"));'
                . '$r=Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 90;'
                . 'if($r.Content -is [byte[]]){[Console]::Out.Write([Text.Encoding]::UTF8.GetString($r.Content))}else{[Console]::Out.Write($r.Content)}';
            $encoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $script));
            $cmd = '"' . $ps . '" -NoProfile -ExecutionPolicy Bypass -EncodedCommand ' . $encoded . ' 2>NUL';
            $out = @shell_exec($cmd);
            if (is_string($out) && trim($out) !== '') $body = $out;
        }
        if ($body === false || trim((string)$body) === '') throw new RuntimeException('No se pudo leer OSINERGMIN.');
        return (string)$body;
    }
    $caFile = ROOT . '/certs/cacert.pem';
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];
    if (file_exists($caFile)) {
        $opts[CURLOPT_CAINFO] = $caFile;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code >= 400) {
        if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
            $ps = getenv('SystemRoot') ? getenv('SystemRoot') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe' : 'powershell.exe';
            $url64 = base64_encode($url);
            $script = '[Console]::OutputEncoding=New-Object System.Text.UTF8Encoding $false;'
                . '$ProgressPreference="SilentlyContinue";'
                . '$u=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $url64 . '"));'
                . '$r=Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 90;'
                . 'if($r.Content -is [byte[]]){[Console]::Out.Write([Text.Encoding]::UTF8.GetString($r.Content))}else{[Console]::Out.Write($r.Content)}';
            $encoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $script));
            $cmd = '"' . $ps . '" -NoProfile -ExecutionPolicy Bypass -EncodedCommand ' . $encoded . ' 2>NUL';
            $out = @shell_exec($cmd);
            if (is_string($out) && trim($out) !== '') return $out;
        }
        throw new RuntimeException($err ?: 'HTTP ' . $code);
    }
    return $body;
}

try {
    if ($isGenerationAggregate) {
        $features = [];
        $generationLayers = osGenerationLayers();
        $clientLimit = (int)($_GET['limit'] ?? 0);
        $limit = min(max($clientLimit ?: 3000, 500), 10000);
        $pageSize = 1000;

        foreach ($generationLayers as $subLayer => $info) {
            $offset = 0;
            do {
                $queryArgs = [
                    'where' => '1=1',
                    'outFields' => '*',
                    'returnGeometry' => 'true',
                    'outSR' => '4326',
                    'f' => 'geojson',
                    'resultOffset' => $offset,
                    'resultRecordCount' => $pageSize,
                    'geometryPrecision' => '6',
                ];
                $url = URL_ELEC_REST . '/' . $subLayer . '/query?' . http_build_query($queryArgs);
                $data = json_decode(osFetch($url), true);
                if (!is_array($data)) {
                    throw new RuntimeException('Respuesta no valida de OSINERGMIN');
                }
                if (isset($data['error'])) {
                    throw new RuntimeException($data['error']['message'] ?? 'Error de OSINERGMIN');
                }

                $batch = $data['features'] ?? [];
                foreach ($batch as $feature) {
                    if (!isset($feature['properties']) || !is_array($feature['properties'])) {
                        $feature['properties'] = [];
                    }
                    $props = &$feature['properties'];
                    $props['_os_layer'] = $subLayer;
                    $props['_os_layer_name'] = $info['title'];
                    $props['_os_categoria'] = $info['categoria'];
                    $props['_os_tecnologia'] = $info['tecnologia'];
                    $props['_os_estado'] = $info['estado'];
                    if (($props['EMPRESA'] ?? '') === '' && !empty($props['PROPIETARIO'])) $props['EMPRESA'] = $props['PROPIETARIO'];
                    if (($props['POT_INST'] ?? '') === '' && isset($props['POT_INSTALADA'])) $props['POT_INST'] = $props['POT_INSTALADA'];
                    if (($props['ESTADO'] ?? '') === '' && !empty($props['CONDICION'])) $props['ESTADO'] = $props['CONDICION'];
                    if (($props['TIPO_CENTRAL'] ?? '') === '' && !empty($props['FUENTE'])) $props['TIPO_CENTRAL'] = 'GENERACION AISLADA';
                    unset($props);
                    $features[] = $feature;
                }

                $hasMore = !empty($data['exceededTransferLimit'])
                    || !empty($data['properties']['exceededTransferLimit'])
                    || count($batch) === $pageSize;
                $offset += count($batch);
            } while ($hasMore && count($batch) > 0 && $offset < $limit);
        }

        $out = [
            'type' => 'FeatureCollection',
            'features' => $features,
            'metadata' => [
                'source' => 'OSINERGMIN Electricidad',
                'service' => URL_ELEC_REST,
                'layer' => 'generacion',
                'title' => 'Centrales de generacion',
                'sublayers' => array_keys($generationLayers),
                'count' => count($features),
                'cached_at' => date('c'),
            ],
        ];

        $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($cache, $json);

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=600');
        echo $json;
        exit;
    }

    $features = [];
    $offset = 0;
    $clientLimit = (int)($_GET['limit'] ?? 0);
    if ($hasBbox && $isMtLayer) {
        $limit = min(max($clientLimit ?: 900, 100), 1500);
        $pageSize = min(500, $limit);
        // Este servicio MT tiene un esquema distinto al MapServer de Electricidad.
        // ArcGIS rechaza toda la consulta si outFields contiene siquiera un campo inexistente.
        $outFields = 'OBJECTID,CODEMP,CODTRAMOMT,CODTIPORED,LONGITUD,PROPIEDAD,FECPUESTASERVICIO,TIPO_CENT,FECHA_PS';
    } else {
        $limit = $hasBbox ? min(max($clientLimit ?: 3500, 500), 5000) : 25000;
        $pageSize = min(1000, $limit);
        $outFields = '*';
    }

    do {
        $queryArgs = [
            'where' => '1=1',
            'outFields' => $outFields,
            'returnGeometry' => 'true',
            'outSR' => '4326',
            'f' => 'geojson',
            'resultOffset' => $offset,
            'resultRecordCount' => $pageSize,
            'maxAllowableOffset' => ($hasBbox && $isMtLayer) ? '0.0015' : ($hasBbox ? '0.0006' : '0.005'),
            'geometryPrecision' => ($hasBbox && $isMtLayer) ? '5' : ($hasBbox ? '6' : '5'),
        ];
        if ($hasBbox) {
            $queryArgs += [
                'geometry' => $bbox,
                'geometryType' => 'esriGeometryEnvelope',
                'inSR' => '4326',
                'spatialRel' => 'esriSpatialRelIntersects',
            ];
        }
        $query = http_build_query($queryArgs);
        $serviceUrl = osServiceUrl($layer);
        $url = $serviceUrl . '/' . $layer . '/query?' . $query;
        $data = json_decode(osFetch($url), true);

        if (!is_array($data)) {
            throw new RuntimeException('Respuesta no válida de OSINERGMIN');
        }
        if (isset($data['error'])) {
            throw new RuntimeException($data['error']['message'] ?? 'Error de OSINERGMIN');
        }

        $batch = $data['features'] ?? [];
        foreach ($batch as $feature) {
            if ($isMtLayer) {
                $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
                if (empty($props['EMPRESA']) && !empty($props['CODEMP'])) $props['EMPRESA'] = $props['CODEMP'];
                if (empty($props['CODIGO']) && !empty($props['CODTRAMOMT'])) $props['CODIGO'] = $props['CODTRAMOMT'];
                if (empty($props['NOMBRE']) && !empty($props['CODTRAMOMT'])) $props['NOMBRE'] = 'Tramo MT ' . $props['CODTRAMOMT'];
                if (empty($props['PUEST_OPER']) && !empty($props['FECPUESTASERVICIO'])) $props['PUEST_OPER'] = $props['FECPUESTASERVICIO'];
                $feature['properties'] = $props;
            }
            $features[] = $feature;
        }

        $hasMore = !empty($data['exceededTransferLimit'])
            || !empty($data['properties']['exceededTransferLimit'])
            || count($batch) === $pageSize;
        $offset += count($batch);
    } while ($hasMore && count($batch) > 0 && $offset < $limit);

    $out = [
        'type' => 'FeatureCollection',
        'features' => $features,
        'metadata' => [
            'source' => 'OSINERGMIN Electricidad',
            'service' => osServiceUrl($layer),
            'layer' => $layer,
            'title' => $allowed[$layer],
            'count' => count($features),
            'cached_at' => date('c'),
        ],
    ];

    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents($cache, $json);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=600');
    echo $json;
} catch (Throwable $e) {
    if (file_exists($cache)) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=120');
        readfile($cache);
        exit;
    }
    jsonOut(['error' => 'No se pudo consultar OSINERGMIN: ' . $e->getMessage()], 502);
}

<?php
/**
 * api/external_layer.php - Convierte APIs REST/GeoJSON registradas en capas GeoJSON.
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(300);
@ini_set('memory_limit', '1024M');

if (!isAuth()) { jsonOut(['error'=>'No autorizado'], 401); }

$id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['id'] ?? '');
if (!$id) jsonOut(['error'=>'ID de API requerido'], 400);

function extFindApi(string $id): ?array {
    foreach (getApiRegistry(false) as $api) {
        if (($api['id'] ?? '') === $id) return $api;
    }
    return null;
}

function extPowerShellFetch(string $url): ?string {
    if (PHP_OS_FAMILY !== 'Windows' || !function_exists('shell_exec')) return null;
    $ps = getenv('SystemRoot') ? getenv('SystemRoot') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe' : 'powershell.exe';
    $url64 = base64_encode($url);
    $script = '[Console]::OutputEncoding=New-Object System.Text.UTF8Encoding $false; $OutputEncoding=[Console]::OutputEncoding;'
        . '$ProgressPreference="SilentlyContinue";'
        . '$u=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $url64 . '"));'
        . '$r=Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 180;'
        . '$r.Content';
    $encoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $script));
    $cmd = '"' . $ps . '" -NoProfile -ExecutionPolicy Bypass -EncodedCommand ' . $encoded . ' 2>NUL';
    $out = @shell_exec($cmd);
    return is_string($out) && trim($out) !== '' ? $out : null;
}

function extFetchJson(string $url): array {
    if (!isSafeExternalUrl($url)) {
        throw new RuntimeException('URL externa no permitida por seguridad.');
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>true,
            CURLOPT_CONNECTTIMEOUT=>15,
            CURLOPT_TIMEOUT=>180,
            CURLOPT_HTTPHEADER=>[
                'Accept: application/json, application/geo+json',
                'Range-Unit: items',
            ],
        ] + appCurlTlsOptions());
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            throw new RuntimeException('API externa no respondio correctamente' . ($code ? " (HTTP $code)" : '') . ($err ? ": $err" : ''));
        }
    } else {
        $headers = ['Accept: application/json, application/geo+json', 'Range-Unit: items'];
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        if (!empty($query['apikey'])) {
            $headers[] = 'apikey: ' . $query['apikey'];
            $headers[] = 'Authorization: Bearer ' . $query['apikey'];
        }
        $ctx = stream_context_create([
            'http'=>[
                'method'=>'GET',
                'header'=>implode("\r\n", $headers),
                'timeout'=>180,
                'ignore_errors'=>true,
            ],
            'ssl'=>appSslStreamOptions(),
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            $body = extPowerShellFetch($url);
        }
        if ($body === false || $body === null || trim((string)$body) === '') {
            throw new RuntimeException('No se pudo leer la API externa desde PHP ni desde el conector HTTPS local.');
        }
        $status = $http_response_header[0] ?? '';
        if (preg_match('/\s([45]\d\d)\s/', $status, $m)) {
            throw new RuntimeException('API externa no respondio correctamente (HTTP ' . $m[1] . '): ' . substr((string)$body, 0, 220));
        }
    }
    $body = (string)$body;
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
    if (!preg_match('//u', $body)) {
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $body);
        if (is_string($fixed) && $fixed !== '') $body = $fixed;
    }
    $json = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('La API no devolvio JSON valido: ' . json_last_error_msg());
    }
    return $json;
}

function extFetchText(string $url): string {
    if (!isSafeExternalUrl($url)) {
        throw new RuntimeException('URL externa no permitida por seguridad.');
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>true,
            CURLOPT_CONNECTTIMEOUT=>15,
            CURLOPT_TIMEOUT=>180,
            CURLOPT_HTTPHEADER=>['Accept: text/html, application/json, */*'],
        ] + appCurlTlsOptions());
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            throw new RuntimeException('API externa no respondio correctamente' . ($code ? " (HTTP $code)" : '') . ($err ? ": $err" : ''));
        }
    } else {
        $ctx = stream_context_create([
            'http'=>[
                'method'=>'GET',
                'header'=>"Accept: text/html, application/json, */*\r\n",
                'timeout'=>180,
                'ignore_errors'=>true,
            ],
            'ssl'=>appSslStreamOptions(),
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) $body = extPowerShellFetch($url);
        if ($body === false || $body === null || trim((string)$body) === '') {
            throw new RuntimeException('No se pudo leer la API externa desde PHP ni desde el conector HTTPS local.');
        }
        $status = $http_response_header[0] ?? '';
        if (preg_match('/\s([45]\d\d)\s/', $status, $m)) {
            throw new RuntimeException('API externa no respondio correctamente (HTTP ' . $m[1] . '): ' . substr((string)$body, 0, 220));
        }
    }
    $body = (string)$body;
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
    if (!preg_match('//u', $body)) {
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $body);
        if (is_string($fixed) && $fixed !== '') $body = $fixed;
    }
    return $body;
}

function extHtmlOptionsToGeoJson(string $html, array $api): ?array {
    if (!preg_match_all('/<option\b([^>]*)>(.*?)<\/option>/is', $html, $matches, PREG_SET_ORDER)) return null;
    $features = [];
    foreach ($matches as $idx => $match) {
        $attrs = (string)($match[1] ?? '');
        $label = trim(html_entity_decode(strip_tags((string)($match[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($label === '' || str_starts_with($label, '--')) continue;
        $lat = null; $lng = null; $value = '';
        if (preg_match('/\blat\s*=\s*["\']?([^"\'>\s]+)/i', $attrs, $m)) $lat = is_numeric($m[1]) ? (float)$m[1] : null;
        if (preg_match('/\blng\s*=\s*["\']?([^"\'>\s]+)/i', $attrs, $m)) $lng = is_numeric($m[1]) ? (float)$m[1] : null;
        if (preg_match('/\bvalue\s*=\s*["\']?([^"\'>\s]+)/i', $attrs, $m)) $value = html_entity_decode((string)$m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
        $features[] = [
            'type'=>'Feature',
            'properties'=>[
                'NOMBRE'=>$label,
                'CODIGO_ANP'=>$value,
                'FUENTE'=>'API externa',
                '_api_name'=>$api['name'],
                '_row'=>count($features) + 1,
                'nota'=>'Punto referencial publicado por el geoportal; validar poligono oficial ANP si el expediente lo requiere.',
            ],
            'geometry'=>['type'=>'Point', 'coordinates'=>[$lng, $lat]],
        ];
    }
    return [
        'type'=>'FeatureCollection',
        'metadata'=>[
            'source'=>'api-html-options',
            'name'=>$api['name'],
            'id'=>$api['id'],
            'features'=>count($features),
            'geometry_note'=>'Puntos referenciales; no reemplazan poligonos oficiales ANP.',
        ],
        'features'=>$features,
    ];
}

function extSpecialApiToGeoJson(string $url, array $api): ?array {
    if (($api['id'] ?? '') !== 'cultura_anp_nacional') return null;
    return extHtmlOptionsToGeoJson(extFetchText($url), $api);
}

function extAppendQuery(string $url, array $params): string {
    $sep = str_contains($url, '?') ? '&' : '?';
    return $url . $sep . http_build_query($params);
}

function extPrepareSourceUrl(string $url): string {
    $parts = parse_url($url);
    $query = [];
    parse_str($parts['query'] ?? '', $query);
    $service = strtolower((string)($query['service'] ?? ''));
    $request = strtolower((string)($query['request'] ?? ''));
    if ($service === 'wfs' && $request === 'getfeature') {
        $add = [];
        if (empty($query['outputFormat'])) $add['outputFormat'] = 'application/json';
        if (empty($query['srsName'])) $add['srsName'] = 'EPSG:4326';
        if ($add) $url = extAppendQuery($url, $add);
    }
    return $url;
}

function extLooksArcgis(string $url, array $data): bool {
    if (isset($data['layers']) || isset($data['features'])) return true;
    return (bool)preg_match('~/((MapServer)|(FeatureServer))(/?\d*)?$~i', parse_url($url, PHP_URL_PATH) ?: '');
}

function extEsriGeometry(array $geometry, string $geometryType = ''): ?array {
    if (isset($geometry['x'], $geometry['y'])) {
        return ['type'=>'Point', 'coordinates'=>[(float)$geometry['x'], (float)$geometry['y']]];
    }
    if (isset($geometry['paths']) && is_array($geometry['paths'])) {
        return count($geometry['paths']) === 1
            ? ['type'=>'LineString', 'coordinates'=>$geometry['paths'][0]]
            : ['type'=>'MultiLineString', 'coordinates'=>$geometry['paths']];
    }
    if (isset($geometry['rings']) && is_array($geometry['rings'])) {
        return ['type'=>'Polygon', 'coordinates'=>$geometry['rings']];
    }
    return null;
}

function extApiLimit(array $api): int {
    $id = (string)($api['id'] ?? '');
    if ($id === 'midagri_mnsa_2024') return 250000;
    if ($id === 'produce_catastro_acuicola') return 200000;
    return 50000;
}

function extKeepGeometry(array $api, ?array $geom, string $geometryType = ''): bool {
    if (!$geom) return false;
    if (($api['id'] ?? '') !== 'produce_catastro_acuicola') return true;
    $type = strtolower((string)($geom['type'] ?? $geometryType));
    return str_contains($type, 'polygon');
}

function extArcgisFeaturesToGeoJson(array $data, array $api, string $layerName = '', int $rowOffset = 0): ?array {
    if (!isset($data['features']) || !is_array($data['features'])) return null;
    $features = [];
    $geometryType = (string)($data['geometryType'] ?? '');
    foreach ($data['features'] as $idx => $feature) {
        if (!is_array($feature)) continue;
        $props = is_array($feature['attributes'] ?? null) ? $feature['attributes'] : (is_array($feature['properties'] ?? null) ? $feature['properties'] : []);
        $geom = null;
        if (isset($feature['geometry']) && is_array($feature['geometry'])) $geom = extEsriGeometry($feature['geometry'], $geometryType);
        if (!extKeepGeometry($api, $geom, $geometryType)) continue;
        if ($layerName !== '') $props['_api_layer'] = $layerName;
        $features[] = [
            'type'=>'Feature',
            'properties'=>$props + ['FUENTE'=>'API externa', '_api_name'=>$api['name'], '_row'=>$rowOffset + $idx + 1],
            'geometry'=>$geom,
        ];
    }
    return [
        'type'=>'FeatureCollection',
        'metadata'=>['source'=>'api', 'name'=>$api['name'], 'id'=>$api['id'], 'features'=>count($features)],
        'features'=>$features,
    ];
}

function extArcgisServiceToGeoJson(string $url, array $data, array $api): ?array {
    if (isset($data['features'])) return extArcgisFeaturesToGeoJson($data, $api);
    if (!isset($data['layers']) || !is_array($data['layers'])) return null;
    $all = [];
    $warnings = [];
    $service = preg_replace('~/+$~', '', preg_replace('~/query$~i', '', strtok($url, '?') ?: $url));
    $pageSize = ($api['id'] ?? '') === 'midagri_mnsa_2024' ? 2000 : 1000;
    $limit = extApiLimit($api);
    foreach ($data['layers'] as $layerInfo) {
        if (!is_array($layerInfo) || !isset($layerInfo['id'])) continue;
        $layerName = trim((string)($layerInfo['name'] ?? ('Capa ' . $layerInfo['id'])));
        try {
            $layerMeta = [];
            try {
                $layerMeta = extFetchJson(extAppendQuery($service . '/' . rawurlencode((string)$layerInfo['id']), ['f'=>'json']));
            } catch (Throwable $ignored) {}
            if (($api['id'] ?? '') === 'produce_catastro_acuicola') {
                $gt = strtolower((string)($layerMeta['geometryType'] ?? ''));
                if ($gt !== '' && !str_contains($gt, 'polygon')) continue;
            }
            $ids = [];
            if (($api['id'] ?? '') !== 'midagri_mnsa_2024') {
                try {
                    $idData = extFetchJson(extAppendQuery($service . '/' . rawurlencode((string)$layerInfo['id']) . '/query', [
                        'f'=>'json',
                        'where'=>'1=1',
                        'returnIdsOnly'=>'true',
                    ]));
                    if (is_array($idData['objectIds'] ?? null)) {
                        $ids = array_values(array_filter($idData['objectIds'], fn($v) => is_numeric($v)));
                        sort($ids, SORT_NUMERIC);
                    }
                } catch (Throwable $ignored) {
                }
            }

            if ($ids) {
                $idChunkSize = ($api['id'] ?? '') === 'midagri_mnsa_2024' ? 80 : 250;
                foreach (array_chunk($ids, $idChunkSize) as $chunkIdx => $chunk) {
                    if (count($all) >= $limit) {
                        $warnings[] = $layerName . ': limite de registros alcanzado (' . $limit . ') de ' . count($ids) . ' IDs.';
                        break 2;
                    }
                    $queryUrl = extAppendQuery($service . '/' . rawurlencode((string)$layerInfo['id']) . '/query', [
                        'f'=>'json',
                        'where'=>'1=1',
                        'objectIds'=>implode(',', $chunk),
                        'outFields'=>'*',
                        'returnGeometry'=>'true',
                        'outSR'=>'4326',
                        'maxAllowableOffset'=>'0.005',
                        'geometryPrecision'=>'5',
                    ]);
                    $layerData = extFetchJson($queryUrl);
                    $gj = extArcgisFeaturesToGeoJson($layerData, $api, $layerName, $chunkIdx * $idChunkSize);
                    if ($gj) $all = array_merge($all, $gj['features']);
                }
                continue;
            }

            $offset = 0;
            $supportsPagination = !empty($layerMeta['advancedQueryCapabilities']['supportsPagination']) || !empty($layerMeta['supportsPagination']);
            do {
                if (count($all) >= $limit) {
                    $warnings[] = $layerName . ': limite de registros alcanzado (' . $limit . ').';
                    break 2;
                }
                $queryUrl = extAppendQuery($service . '/' . rawurlencode((string)$layerInfo['id']) . '/query', [
                    'f'=>'json',
                    'where'=>'1=1',
                    'outFields'=>'*',
                    'returnGeometry'=>'true',
                    'outSR'=>'4326',
                    'resultOffset'=>$offset,
                    'resultRecordCount'=>$pageSize,
                    'maxAllowableOffset'=>'0.005',
                    'geometryPrecision'=>'5',
                ]);
                $layerData = extFetchJson($queryUrl);
                $batch = $layerData['features'] ?? [];
                if (!is_array($batch) || !$batch) break;
                $gj = extArcgisFeaturesToGeoJson($layerData, $api, $layerName, $offset);
                if ($gj) $all = array_merge($all, $gj['features']);
                $hasMore = !empty($layerData['exceededTransferLimit']) || ($supportsPagination && count($batch) === $pageSize);
                $offset += count($batch);
            } while ($hasMore && $offset < $limit);
        } catch (Throwable $e) {
            $warnings[] = $layerName . ': ' . $e->getMessage();
        }
    }
    return [
        'type'=>'FeatureCollection',
        'metadata'=>['source'=>'api', 'name'=>$api['name'], 'id'=>$api['id'], 'features'=>count($all), 'arcgis_layers'=>count($data['layers']), 'warnings'=>$warnings],
        'features'=>$all,
    ];
}

function extReadUInt32(string $bin, int &$off, bool $le): int {
    $part = substr($bin, $off, 4);
    $off += 4;
    $v = unpack($le ? 'V' : 'N', $part);
    return (int)$v[1];
}

function extReadDouble(string $bin, int &$off, bool $le): float {
    $part = substr($bin, $off, 8);
    $off += 8;
    $v = unpack($le ? 'e' : 'E', $part);
    return (float)$v[1];
}

function extWkbGeometry(string $bin, int &$off = 0): ?array {
    if (strlen($bin) < $off + 5) return null;
    $le = ord($bin[$off]) === 1;
    $off += 1;
    $type = extReadUInt32($bin, $off, $le);
    $hasSrid = (bool)($type & 0x20000000);
    $base = $type & 0x000000ff;
    if ($hasSrid) $off += 4;

    if ($base === 1) {
        $x = extReadDouble($bin, $off, $le);
        $y = extReadDouble($bin, $off, $le);
        return ['type'=>'Point', 'coordinates'=>[$x, $y]];
    }
    if ($base === 2) {
        $n = extReadUInt32($bin, $off, $le);
        $coords = [];
        for ($i = 0; $i < $n; $i++) $coords[] = [extReadDouble($bin, $off, $le), extReadDouble($bin, $off, $le)];
        return ['type'=>'LineString', 'coordinates'=>$coords];
    }
    if ($base === 3) {
        $rings = extReadUInt32($bin, $off, $le);
        $poly = [];
        for ($r = 0; $r < $rings; $r++) {
            $n = extReadUInt32($bin, $off, $le);
            $ring = [];
            for ($i = 0; $i < $n; $i++) $ring[] = [extReadDouble($bin, $off, $le), extReadDouble($bin, $off, $le)];
            $poly[] = $ring;
        }
        return ['type'=>'Polygon', 'coordinates'=>$poly];
    }
    if (in_array($base, [4,5,6], true)) {
        $n = extReadUInt32($bin, $off, $le);
        $geoms = [];
        for ($i = 0; $i < $n; $i++) {
            $g = extWkbGeometry($bin, $off);
            if ($g) $geoms[] = $g;
        }
        if ($base === 4) return ['type'=>'MultiPoint', 'coordinates'=>array_map(fn($g) => $g['coordinates'], $geoms)];
        if ($base === 5) return ['type'=>'MultiLineString', 'coordinates'=>array_map(fn($g) => $g['coordinates'], $geoms)];
        if ($base === 6) return ['type'=>'MultiPolygon', 'coordinates'=>array_map(fn($g) => $g['coordinates'], $geoms)];
    }
    return null;
}

function extGeometryFromValue($value): ?array {
    if (is_array($value) && isset($value['type'], $value['coordinates'])) return $value;
    if (!is_string($value)) return null;
    $text = trim($value);
    if ($text === '') return null;
    if ($text[0] === '{') {
        $json = json_decode($text, true);
        if (is_array($json) && isset($json['type'], $json['coordinates'])) return $json;
        if (is_array($json) && isset($json['geometry'])) return extGeometryFromValue($json['geometry']);
    }
    if (preg_match('/^[0-9A-Fa-f]{18,}$/', $text) && strlen($text) % 2 === 0) {
        $bin = @hex2bin($text);
        if ($bin !== false) {
            $off = 0;
            return extWkbGeometry($bin, $off);
        }
    }
    return null;
}

function extGeometryFromRow(array $row): ?array {
    if (isset($row['type'], $row['coordinates'])) return $row;
    $geomKeys = ['geometry','geom','geojson','geom_json','st_asgeojson','st_astext','the_geom','wkb_geometry','shape'];
    foreach ($geomKeys as $key) {
        if (array_key_exists($key, $row)) {
            $geom = extGeometryFromValue($row[$key]);
            if ($geom) return $geom;
        }
    }
    $latKeys = ['lat','latitud','latitude','y','LAT','Latitude','LATITUD'];
    $lngKeys = ['lng','lon','long','longitud','longitude','x','LNG','Lon','Longitude','LONGITUD'];
    $lat = null; $lng = null;
    foreach ($latKeys as $key) if (isset($row[$key]) && is_numeric($row[$key])) { $lat = (float)$row[$key]; break; }
    foreach ($lngKeys as $key) if (isset($row[$key]) && is_numeric($row[$key])) { $lng = (float)$row[$key]; break; }
    if ($lat !== null && $lng !== null && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
        return ['type'=>'Point', 'coordinates'=>[$lng, $lat]];
    }
    return null;
}

function extCleanProps(array $row): array {
    $drop = ['geometry','geom','geojson','geom_json','st_asgeojson','st_astext','the_geom','wkb_geometry','shape'];
    foreach ($drop as $key) unset($row[$key]);
    return $row;
}

function extNestedFeatureCollections(array $data, array $api): ?array {
    $features = [];
    $layers = [];

    foreach ($data as $layerName => $value) {
        if (!is_array($value)) continue;

        $collection = null;
        if (($value['type'] ?? '') === 'FeatureCollection' && isset($value['features']) && is_array($value['features'])) {
            $collection = $value;
        } elseif (isset($value['data']) || isset($value['features'])) {
            $collection = extToGeoJson($value, $api);
        }
        if (!$collection || !isset($collection['features']) || !is_array($collection['features'])) continue;

        $layerLabel = (string)$layerName;
        $layers[] = $layerLabel;
        foreach ($collection['features'] as $idx => $feature) {
            if (!is_array($feature) || !isset($feature['geometry']) || !is_array($feature['geometry'])) continue;
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $feature['properties'] = $props + [
                'FUENTE'=>'API externa',
                '_api_name'=>$api['name'],
                '_api_layer'=>$layerLabel,
                '_row'=>$idx + 1,
            ];
            $features[] = $feature;
        }
    }

    if (!$features) return null;
    return [
        'type'=>'FeatureCollection',
        'metadata'=>[
            'source'=>'api',
            'name'=>$api['name'],
            'id'=>$api['id'],
            'features'=>count($features),
            'nested_layers'=>array_values(array_unique($layers)),
        ],
        'features'=>$features,
    ];
}

function extToGeoJson(array $data, array $api, string $url = ''): array {
    if (($data['type'] ?? '') === 'FeatureCollection' && isset($data['features']) && is_array($data['features'])) {
        $data['metadata'] = ['source'=>'api', 'name'=>$api['name'], 'id'=>$api['id'], 'features'=>count($data['features'])];
        foreach ($data['features'] as $idx => &$feature) {
            if (!is_array($feature)) continue;
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $feature['properties'] = $props + ['FUENTE'=>'API externa', '_api_name'=>$api['name'], '_row'=>$idx + 1];
        }
        unset($feature);
        return $data;
    }
    if ($url !== '' && extLooksArcgis($url, $data)) {
        $arcgis = extArcgisServiceToGeoJson($url, $data, $api);
        if ($arcgis) return $arcgis;
    }
    if (!array_is_list($data)) {
        $nested = extNestedFeatureCollections($data, $api);
        if ($nested) return $nested;
    }
    $rows = $data;
    if (isset($data['data']) && is_array($data['data'])) $rows = $data['data'];
    if (isset($data['features']) && is_array($data['features'])) $rows = $data['features'];
    if (!array_is_list($rows)) $rows = [$rows];

    $features = [];
    $skipped = 0;
    foreach ($rows as $idx => $row) {
        if (!is_array($row)) { $skipped++; continue; }
        if (($row['type'] ?? '') === 'Feature' && isset($row['geometry'])) {
            $features[] = $row;
            continue;
        }
        $geom = extGeometryFromRow($row);
        if (!$geom) { $skipped++; continue; }
        $features[] = [
            'type'=>'Feature',
            'properties'=>extCleanProps($row) + ['FUENTE'=>'API externa', '_api_name'=>$api['name'], '_row'=>$idx + 1],
            'geometry'=>$geom,
        ];
    }
    return [
        'type'=>'FeatureCollection',
        'metadata'=>[
            'source'=>'api',
            'name'=>$api['name'],
            'id'=>$api['id'],
            'features'=>count($features),
            'skipped_without_geometry'=>$skipped,
        ],
        'features'=>$features,
    ];
}

try {
    $api = extFindApi($id);
    if (!$api) jsonOut(['error'=>'API externa no registrada.'], 404);
    if (!is_dir(EXTERNAL_LAYER_CACHE_DIR)) mkdir(EXTERNAL_LAYER_CACHE_DIR, 0755, true);
    $cache = EXTERNAL_LAYER_CACHE_DIR . '/' . $id . '.geojson';
    $sig = sha1('v8|' . $api['url'] . '|' . ($api['date'] ?? '') . '|' . ($api['tipo'] ?? ''));
    if (is_file($cache) && filemtime($cache) > time() - 1800) {
        $cached = json_decode((string)file_get_contents($cache), true);
        if (($cached['metadata']['sig'] ?? '') === $sig) {
            echo json_encode($cached, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    $sourceUrl = extPrepareSourceUrl($api['url']);
    if (preg_match('~/((MapServer)|(FeatureServer))(/?\d*)?$~i', parse_url($sourceUrl, PHP_URL_PATH) ?: '') && !str_contains($sourceUrl, 'f=')) {
        $sourceUrl = extAppendQuery($sourceUrl, ['f'=>'json']);
    }
    $geojson = extSpecialApiToGeoJson($sourceUrl, $api);
    if (!$geojson) {
        $json = extFetchJson($sourceUrl);
        $geojson = extToGeoJson($json, $api, $sourceUrl);
    }
    $geojson['metadata']['sig'] = $sig;
    file_put_contents($cache, json_encode($geojson, JSON_UNESCAPED_UNICODE));
    echo json_encode($geojson, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    jsonOut(['error'=>$e->getMessage()], 500);
}

<?php
/**
 * api/temp_geo_report.php - Analiza geometrías KML/KMZ temporales y exporta PDF/DOCX.
 */
require_once dirname(__DIR__) . '/config.php';
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
define('ZONE_ANALYSIS_LIB_ONLY', true);
require_once __DIR__ . '/zone_analysis.php';

@set_time_limit(300);
@ini_set('memory_limit', '1024M');

if (!isAuth()) { jsonOut(['ok'=>false, 'message'=>'No autorizado'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonOut(['ok'=>false, 'message'=>'Método no permitido'], 405); }
requireCsrf();

function tgNormPoints($raw): array {
    if (!is_array($raw)) return [];
    $pts = [];
    foreach ($raw as $p) {
        $lat = null; $lng = null;
        if (is_array($p)) {
            if (array_key_exists('lat', $p) && array_key_exists('lng', $p)) {
                $lat = zNum($p['lat']); $lng = zNum($p['lng']);
            } elseif (count($p) >= 2) {
                $lat = zNum($p[0]); $lng = zNum($p[1]);
            }
        }
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
        $pts[] = [round($lat, 7), round($lng, 7)];
        if (count($pts) >= 6000) break;
    }
    if (count($pts) > 1) {
        $first = $pts[0];
        $last = $pts[count($pts) - 1];
        if (abs($first[0] - $last[0]) < 1e-10 && abs($first[1] - $last[1]) < 1e-10) array_pop($pts);
    }
    return $pts;
}

function tgLineLengthKm(array $pts): float {
    $km = 0.0;
    for ($i = 1; $i < count($pts); $i++) $km += zDistanceKm($pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1]);
    return round($km, 3);
}

function tgPointSegmentDistanceKm(array $p, array $a, array $b): float {
    $lat0 = deg2rad(($a[0] + $b[0] + $p[0]) / 3.0);
    $toXY = static fn(array $q): array => [$q[1] * 111.320 * cos($lat0), $q[0] * 110.574];
    [$px, $py] = $toXY($p);
    [$ax, $ay] = $toXY($a);
    [$bx, $by] = $toXY($b);
    $dx = $bx - $ax; $dy = $by - $ay;
    $den = $dx * $dx + $dy * $dy;
    $t = $den > 0 ? max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $den)) : 0.0;
    $x = $ax + $t * $dx; $y = $ay + $t * $dy;
    return sqrt(($px - $x) ** 2 + ($py - $y) ** 2);
}

function tgPointLineDistanceKm(array $p, array $line): float {
    if (count($line) < 2) return INF;
    $best = INF;
    for ($i = 1; $i < count($line); $i++) $best = min($best, tgPointSegmentDistanceKm($p, $line[$i - 1], $line[$i]));
    return $best;
}

function tgLineTouchesCoords(array $line, array $coords, ?array $bbox, float $padKm = 0.0): bool {
    if (count($line) < 2 || !$coords || !$bbox) return false;
    $padDeg = $padKm / 110.0;
    if (!zBboxIntersects(zCoordsBbox($line), $bbox, $padDeg)) return false;
    if (count($coords) === 1) return tgPointLineDistanceKm($coords[0], $line) <= max(0.25, $padKm);
    $closed = count($coords) >= 3
        && abs($coords[0][0] - $coords[count($coords) - 1][0]) < 0.000001
        && abs($coords[0][1] - $coords[count($coords) - 1][1]) < 0.000001;
    if ($closed) {
        foreach ($line as $p) if (zPointInPolygon($p[0], $p[1], $coords)) return true;
    }
    for ($i = 1; $i < count($line); $i++) {
        for ($j = 1; $j < count($coords); $j++) {
            if (zSegmentsIntersect($line[$i - 1], $line[$i], $coords[$j - 1], $coords[$j])) return true;
        }
        if ($closed && zSegmentsIntersect($line[$i - 1], $line[$i], $coords[count($coords) - 1], $coords[0])) return true;
    }
    return false;
}

function tgNearestSet(array $point): ?array {
    $best = null;
    foreach (zSources() as $source) {
        if (($source['kind'] ?? '') !== 'set') continue;
        $path = zSourceScanPath($source);
        if (!$path || !zShouldScanSource($source, $path)) continue;
        foreach (zReadGeoJson($path) as $feature) {
            if (!is_array($feature)) continue;
            $coords = zFeatureCoords($feature, 1);
            if (!$coords) continue;
            $dist = zDistanceKm($point[0], $point[1], $coords[0][0], $coords[0][1]);
            if ($best === null || $dist < $best['distancia_km']) {
                $best = [
                    'nombre'=>zFeatureName($feature, $source['label']),
                    'fuente'=>$source['label'],
                    'lat'=>round($coords[0][0], 6),
                    'lng'=>round($coords[0][1], 6),
                    'distancia_km'=>round($dist, 2),
                ];
            }
        }
    }
    return $best;
}

function tgNearestSetFromCatalog(array $point, array $catalog): ?array {
    $best = null;
    foreach ($catalog as $set) {
        if (!is_array($set) || !isset($set['lat'], $set['lng'])) continue;
        $dist = zDistanceKm($point[0], $point[1], (float)$set['lat'], (float)$set['lng']);
        if ($best === null || $dist < $best['distancia_km']) {
            $best = [
                'nombre'=>(string)($set['nombre'] ?? 'SET'),
                'fuente'=>(string)($set['fuente'] ?? 'SET'),
                'lat'=>round((float)$set['lat'], 6),
                'lng'=>round((float)$set['lng'], 6),
                'distancia_km'=>round($dist, 2),
            ];
        }
    }
    return $best;
}

function tgPointConcessions(array $point): array {
    $hits = [];
    foreach (zLoadConcessionAreas() as $area) {
        $b = $area['bbox'];
        if ($point[0] < $b['minLat'] || $point[0] > $b['maxLat'] || $point[1] < $b['minLng'] || $point[1] > $b['maxLng']) continue;
        if (zPointInPolygon($point[0], $point[1], $area['coords'])) {
            $hits[] = ['nombre'=>$area['nombre']];
            if (count($hits) >= 6) break;
        }
    }
    return $hits;
}

function tgPointContext(array $point, ?array $setCatalog = null): array {
    $set = $setCatalog === null ? tgNearestSet($point) : tgNearestSetFromCatalog($point, $setCatalog);
    $parts = [round($point[0], 6) . ', ' . round($point[1], 6)];
    if ($set) $parts[] = 'SET cercana: ' . $set['nombre'] . ' a ' . $set['distancia_km'] . ' km';
    $conc = tgPointConcessions($point);
    if ($conc) $parts[] = 'dentro de ' . implode(', ', array_map(fn($x) => $x['nombre'], array_slice($conc, 0, 2)));
    return ['lat'=>round($point[0], 6), 'lng'=>round($point[1], 6), 'referencia'=>implode(' | ', $parts), 'set_cercana'=>$set, 'concesiones'=>$conc];
}

function tgDepartmentBboxes(): array {
    static $boxes = null;
    if (is_array($boxes)) return $boxes;
    $boxes = [];
    $path = zLayerPath('MIDAGRI_Departamentos');
    if (!is_file($path)) return $boxes;
    foreach (zReadGeoJson($path) as $feature) {
        if (!is_array($feature)) continue;
        $coords = zFeatureCoords($feature, 12000);
        $bbox = zCoordsBbox($coords);
        if (!$bbox) continue;
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $rawName = zPick($props, ['NOMBDEP', 'NOMBDPTO', 'DEPARTAMENTO', 'DEPARTAMEN', 'NOMBRE'], zFeatureName($feature, ''));
        $name = zNormText(str_replace('_', ' ', $rawName));
        if ($name !== '') $boxes[$name] = $bbox;
    }
    return $boxes;
}

function tgDepartmentSourceRelevant(string $path, array $lineBbox): bool {
    $base = basename($path);
    if (!preg_match('/^MIDAGRI_Dep_(?!Departamentos)(.+?)\.geojson(?:\.gz)?$/i', $base, $m)) return true;
    $sourceName = zNormText(str_replace('_', ' ', (string)$m[1]));
    if ($sourceName === '') return true;
    foreach (tgDepartmentBboxes() as $department => $bbox) {
        if ($department !== $sourceName && !str_contains($department, $sourceName) && !str_contains($sourceName, $department)) continue;
        return zBboxIntersects($lineBbox, $bbox, 0.02);
    }
    return true;
}

function tgScanLine(array $line): array {
    $bbox = zCoordsBbox($line);
    $hits = ['concesiones'=>[], 'lt'=>[], 'mt'=>[], 'sed'=>[], 'sets'=>[], 'predios'=>[], 'cultural'=>[], 'ambiental'=>[], 'productivo'=>[], 'externas'=>[]];
    $setCatalog = [];
    foreach (zSources() as $source) {
        $kind = (string)($source['kind'] ?? '');
        $path = zSourceScanPath($source);
        if (!$path || !zShouldScanSource($source, $path)) continue;
        if ($bbox && !tgDepartmentSourceRelevant($path, $bbox)) continue;
        $features = zReadGeoJson($path);
        foreach ($features as $feature) {
            if (!is_array($feature)) continue;
            $coords = zFeatureCoords($feature, 12000);
            $fb = zCoordsBbox($coords);
            if (!$coords || !$fb) continue;
            $name = zFeatureName($feature, $source['label']);
            if ($kind === 'set' || $kind === 'sed') {
                $dist = tgPointLineDistanceKm($coords[0], $line);
                if ($kind === 'set') {
                    $setCatalog[] = [
                        'nombre'=>$name,
                        'fuente'=>$source['label'],
                        'lat'=>$coords[0][0],
                        'lng'=>$coords[0][1],
                    ];
                }
                if ($dist <= ($kind === 'set' ? 8.0 : 2.0)) {
                    $bucket = $kind === 'set' ? 'sets' : 'sed';
                    $hits[$bucket][] = ['nombre'=>$name, 'distancia_km'=>round($dist, 2), 'fuente'=>$source['label']];
                }
            } elseif (tgLineTouchesCoords($line, $coords, $fb, 0.2)) {
                $bucket = $kind === 'concession' ? 'concesiones'
                    : ($kind === 'mt' ? 'mt'
                    : ($kind === 'line' ? 'lt'
                    : ($kind === 'predio' ? 'predios'
                    : ($kind === 'cultural' ? 'cultural'
                    : ($kind === 'ambiental' ? 'ambiental'
                    : ($kind === 'productivo' ? 'productivo' : 'externas'))))));
                $hits[$bucket][] = ['nombre'=>$name, 'fuente'=>$source['label']];
            }
            foreach ($hits as $k => $rows) if (count($hits[$k]) > 12) $hits[$k] = array_slice($hits[$k], 0, 12);
        }
        unset($features);
    }
    usort($hits['sets'], fn($a, $b) => $a['distancia_km'] <=> $b['distancia_km']);
    usort($hits['sed'], fn($a, $b) => $a['distancia_km'] <=> $b['distancia_km']);
    return ['bbox'=>$bbox, 'hallazgos'=>$hits, 'set_catalog'=>$setCatalog];
}

function tgSampleLinePoints(array $points, int $limit = 10): array {
    if (count($points) <= $limit) return $points;
    $out = [];
    $last = count($points) - 1;
    for ($i = 0; $i < $limit; $i++) {
        $idx = (int)round(($last * $i) / max(1, $limit - 1));
        $out[] = $points[$idx];
    }
    return $out;
}

function tgLineRestrictions(array $scan, float $lengthKm, array $ceraunico = []): array {
    $hits = $scan['hallazgos'] ?? [];
    $items = [];
    if (!empty($hits['concesiones'])) $items[] = ['tipo'=>'Concesiones', 'nivel'=>'alto', 'detalle'=>'La ruta cruza concesiones vigentes o en tramite; validar autorizaciones y servidumbre.'];
    if (!empty($hits['cultural'])) $items[] = ['tipo'=>'Patrimonio/CIRA', 'nivel'=>'alto', 'detalle'=>'Se detectan capas culturales o CIRA cercanas/intersectadas; requiere revision arqueologica.'];
    if (!empty($hits['ambiental'])) $items[] = ['tipo'=>'Ambiental', 'nivel'=>'alto', 'detalle'=>'Se detectan areas ambientales/protegidas o ecosistemas sensibles en la ruta.'];
    if (!empty($hits['predios'])) $items[] = ['tipo'=>'Predios', 'nivel'=>'medio', 'detalle'=>'La ruta cruza predios identificados por API; revisar permisos y servidumbre.'];
    if (!empty($hits['mt']) || !empty($hits['sed'])) $items[] = ['tipo'=>'Redes de distribucion', 'nivel'=>'medio', 'detalle'=>'Existen redes MT/SED en la zona; contrastar interferencias y posibilidades de conexion.'];
    if ($lengthKm > 25) $items[] = ['tipo'=>'Longitud de ruta', 'nivel'=>'medio', 'detalle'=>'Ruta mayor a 25 km; revisar accesos, caida de tension, perdidas y mantenimiento.'];
    if (($ceraunico['status'] ?? '') === 'success') {
        $maxDa = (float)($ceraunico['max'] ?? 0);
        $risk = function_exists('zCeraunicoRiskLevel') ? zCeraunicoRiskLevel($maxDa) : ($maxDa >= 17 ? 'alto' : ($maxDa >= 5 ? 'medio' : 'bajo'));
        $level = in_array($risk, ['alto','muy alto','critico','extremo'], true) ? 'alto' : ($maxDa >= 5 ? 'medio' : 'bajo');
        $items[] = ['tipo'=>'Descargas atmosfericas CERAUNICO', 'nivel'=>$level, 'detalle'=>'Densidad maxima referencial: ' . round($maxDa, 2) . ' rayos/km2/año; nivel ' . $risk . '; ' . ($ceraunico['comentario'] ?? 'Fuente OSINERGMIN CERAUNICO.')];
    }
    return [
        'items'=>$items,
        'comentario'=>$items ? 'Afectaciones preliminares identificadas con capas cargadas.' : 'Sin restricciones relevantes identificadas con las capas cargadas.',
    ];
}

function tgLineNetworkSummary(array $scan): array {
    $hits = $scan['hallazgos'] ?? [];
    return [
        'mt_intersecta'=>count($hits['mt'] ?? []),
        'sed_cercanas'=>count($hits['sed'] ?? []),
        'lt_intersecta'=>count($hits['lt'] ?? []),
        'muestras_mt'=>array_map(fn($x) => $x['nombre'] ?? '', array_slice($hits['mt'] ?? [], 0, 8)),
        'muestras_sed'=>array_map(fn($x) => $x['nombre'] ?? '', array_slice($hits['sed'] ?? [], 0, 8)),
        'comentario'=>(!empty($hits['mt']) || !empty($hits['sed']))
            ? 'Se identificaron redes de distribucion cercanas o intersectadas por la ruta.'
            : 'No se identificaron redes MT/SED con las capas cargadas.',
    ];
}

function tgLineFieldVisitRecommendation(float $lengthKm, array $restrictions): array {
    $days = max(1, (int)ceil($lengthKm / 25.0));
    $riskCount = count($restrictions['items'] ?? []);
    if ($riskCount > 2) $days += 1;
    if ($lengthKm > 60) $days += 1;
    $days = max(1, min(12, $days));
    return [
        'dias_recomendados'=>$days,
        'ratio'=>'1 dia base por cada 25 km de ruta, + ajustes por restricciones, accesos e interferencias.',
        'actividades'=>[
            'Reconocimiento de inicio, fin, accesos, cruces y puntos de posible conexion.',
            'Validacion de interferencias con redes existentes, predios y servidumbres.',
            'Revision visual de restricciones culturales, ambientales y pendientes criticas.',
        ],
    ];
}

function tgAnalyzeLine(array $points, array $meta, string $name): array {
    $start = $points[0];
    $end = $points[count($points) - 1];
    $scan = tgScanLine($points);
    $setCatalog = is_array($scan['set_catalog'] ?? null) ? $scan['set_catalog'] : [];
    $kv = zNum($meta['voltage_kv'] ?? null) ?? 0.0;
    $lineType = (($meta['line_type'] ?? '') === 'distribucion') ? 'Distribución' : 'Transmisión';
    $circuit = (($meta['circuit'] ?? '') === 'doble') ? 'Doble terna' : 'Simple terna';
    $length = tgLineLengthKm($points);
    $risk = [];
    if ($scan['hallazgos']['concesiones']) $risk[] = 'Cruza o toca concesiones vigentes/en trámite; revisar titular, autorización y compatibilidad de servidumbre.';
    if ($length > 50) $risk[] = 'Ruta extensa: revisar caída de tensión, pérdidas, accesos y mantenimiento.';
    if ($kv <= 0) $risk[] = 'No se indicó tensión nominal; el dimensionamiento es referencial.';
    if (!$scan['hallazgos']['sets']) $risk[] = 'No se identificó SET cercana a la ruta con las capas cargadas.';
    $ceraunico = function_exists('zCeraunicoAnalyzePoints') ? zCeraunicoAnalyzePoints(tgSampleLinePoints($points, 10)) : ['status'=>'unavailable'];
    if (($ceraunico['status'] ?? '') === 'success' && (float)($ceraunico['max'] ?? 0) >= 9) $risk[] = 'CERAUNICO registra densidad relevante de rayos totales por km2/año; revisar apantallamiento, aislamiento y puesta a tierra segun el nivel de exposicion.';
    $restrictions = tgLineRestrictions($scan, $length, $ceraunico);
    $networks = tgLineNetworkSummary($scan);
    $fieldVisit = tgLineFieldVisitRecommendation($length, $restrictions);
    $startContext = tgPointContext($start, $setCatalog);
    $endContext = tgPointContext($end, $setCatalog);
    $midContext = tgPointContext($points[(int)floor(count($points) / 2)], $setCatalog);
    return [
        'status'=>'success',
        'tipo'=>'linea',
        'nombre'=>$name,
        'longitud_km'=>$length,
        'punto_inicio'=>$startContext,
        'punto_fin'=>$endContext,
        'punto_medio'=>$midContext,
        'especificacion'=>['tipo_linea'=>$lineType, 'tension_kv'=>$kv, 'terna'=>$circuit],
        'tecnico'=>[
            'conductor_referencial'=>$kv <= 35 ? 'AAAC/ACSR 70-150 mm2 para distribución; validar ampacidad, caída de tensión y cortocircuito.' : ($kv <= 138 ? 'ACSR/AAAC 185-400 mm2; validar flujo, pérdidas, flecha, aislamiento y servidumbre.' : 'Conductor de alta capacidad o haz conductor; requiere estudio de corona, flujo y servidumbre.'),
            'estructura_referencial'=>$kv <= 35 ? 'Postes CAC/metálicos con retenciones en cambios de dirección.' : ($kv <= 69 ? 'Postes metálicos o estructuras livianas según vano y acceso.' : 'Torres reticuladas o monopostes con estudio de cimentación y puesta a tierra.'),
            'riesgos'=>$risk ?: ['Ruta preliminar sin alertas críticas en las capas cargadas; requiere validación de campo.'],
        ],
        'hallazgos'=>$scan['hallazgos'],
        'descargas_atmosfericas'=>$ceraunico,
        'restricciones'=>$restrictions,
        'redes_distribucion'=>$networks,
        'visita_campo'=>$fieldVisit,
        'coordenadas_muestra'=>array_slice($points, 0, 120),
    ];
}

$raw = file_get_contents('php://input');
$input = $raw ? json_decode($raw, true) : [];
if (!is_array($input)) jsonOut(['ok'=>false, 'message'=>'Solicitud inválida'], 400);
$format = strtolower((string)($input['format'] ?? 'json'));
if (!in_array($format, ['json', 'pdf', 'docx'], true)) jsonOut(['ok'=>false, 'message'=>'Formato no permitido'], 400);
$geometry = is_array($input['geometry'] ?? null) ? $input['geometry'] : [];
$type = (string)($geometry['type'] ?? '');
$points = tgNormPoints($geometry['coordinates'] ?? []);
$name = trim((string)($input['name'] ?? 'Geometría temporal KML/KMZ'));
$meta = is_array($input['meta'] ?? null) ? $input['meta'] : [];

if ($type === 'Polygon' && count($points) < 3) jsonOut(['ok'=>false, 'message'=>'Polígono temporal inválido'], 400);
if ($type === 'LineString' && count($points) < 2) jsonOut(['ok'=>false, 'message'=>'Línea temporal inválida'], 400);
if (!in_array($type, ['Polygon', 'LineString'], true)) {
    jsonOut(['ok'=>false, 'message'=>'Solo se admiten polígonos y líneas para informe técnico.'], 400);
}

$demandKw = zNum($input['demanda_kw'] ?? null);
$cachePayload = [
    'type'=>$type,
    'points'=>array_map(static fn(array $p): array => [round((float)$p[0], 6), round((float)$p[1], 6)], $points),
    'name'=>$name,
    'demanda_kw'=>$demandKw !== null ? round($demandKw, 2) : null,
    'meta'=>[
        'analysis_type'=>$meta['analysis_type'] ?? '',
        'line_type'=>$meta['line_type'] ?? '',
        'voltage_kv'=>zNum($meta['voltage_kv'] ?? null),
        'circuit'=>$meta['circuit'] ?? '',
    ],
];
$analysis = zAnalysisCacheGetOrCompute('temp_geometry', $cachePayload, static function () use ($type, $points, $demandKw, $meta, $name): array {
    if ($type === 'Polygon') {
        $result = zAnalyzePolygonCached($points, $demandKw, false);
        $result['tipo'] = 'poligono';
        $result['nombre'] = $name;
        $result['punto_inicio'] = tgPointContext($points[0]);
        $result['punto_fin'] = tgPointContext($points[count($points) - 1]);
        $result['area_km2'] = round((float)($result['area_km2'] ?? zAreaKm2($points)), 3);
        return $result;
    }
    return tgAnalyzeLine($points, $meta, $name);
}, 7 * 24 * 60 * 60, 'temp-geometry-v7');

$payload = [
    'analysis'=>$analysis,
    'analysis_cache_key'=>$analysis['_analysis_cache']['key'] ?? '',
    'geometry'=>['type'=>$type, 'coordinates'=>$points],
    'meta'=>[
        'project'=>'Informe técnico temporal KML/KMZ',
        'author'=>$_SESSION['username'] ?? 'CVC Energía',
        'name'=>$name,
        'line_type'=>$meta['line_type'] ?? '',
        'voltage_kv'=>$meta['voltage_kv'] ?? '',
        'circuit'=>$meta['circuit'] ?? '',
        'template'=>defined('ZONE_REPORT_TEMPLATE') ? ZONE_REPORT_TEMPLATE : '',
        'template_pdf'=>defined('ZONE_REPORT_TEMPLATE_PDF') ? ZONE_REPORT_TEMPLATE_PDF : '',
        'generated_at'=>date('Y-m-d H:i:s'),
        'preserve_template_body'=>true,
        'report_kind'=>($type === 'LineString' ? 'temp_line' : 'temp_polygon'),
    ],
];

if ($format === 'json') jsonOut(['ok'=>true] + $payload);

if (!is_dir(REPORT_DIR)) mkdir(REPORT_DIR, 0755, true);
$stamp = date('Ymd_His') . '_' . substr(sha1(json_encode($payload) . microtime(true)), 0, 8);
$base = REPORT_DIR . '/informe_temporal_kml_' . $stamp;
$jsonFile = $base . '.json';
$outFile = $base . '.' . $format;
file_put_contents($jsonFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$builder = ROOT . '/scripts/temp_geo_report_builder.py';
$cmd = escapeshellarg(DOC_PYTHON_BIN) . ' ' . escapeshellarg($builder)
    . ' --input ' . escapeshellarg($jsonFile)
    . ($format === 'docx' ? ' --out-docx ' . escapeshellarg($outFile) : ' --out-pdf ' . escapeshellarg($outFile))
    . ' 2>&1';
$out = @shell_exec($cmd);
if (!is_file($outFile) || filesize($outFile) <= 0) {
    jsonOut(['ok'=>false, 'message'=>'No se pudo generar el informe temporal. ' . substr((string)$out, 0, 500)], 500);
}
header('Content-Type: ' . ($format === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf'));
header('Content-Length: ' . filesize($outFile));
header('Content-Disposition: attachment; filename="' . basename($outFile) . '"');
readfile($outFile);

<?php
/**
 * api/openai_chat.php - Chat tecnico con OpenAI para el geovisor.
 */
define('ZONE_ANALYSIS_LIB_ONLY', true);
require_once dirname(__DIR__) . '/api/zone_analysis.php';

header('Content-Type: application/json; charset=utf-8');
@ini_set('memory_limit', '1024M');
if (!isAuth()) { jsonOut(['ok'=>false,'message'=>'No autorizado'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonOut(['ok'=>false,'message'=>'Metodo no permitido'], 405); }
requireCsrf();

$raw = file_get_contents('php://input');
$input = $raw ? json_decode($raw, true) : [];
if (!is_array($input)) $input = [];

function chatSafeText($value, int $max = 1800): string {
    $text = trim((string)$value);
    $text = preg_replace('/\s+/', ' ', $text);
    return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
}

function chatCleanOutput(string $text): string {
    $text = preg_replace('/\*\*([^*]+)\*\*/u', '$1', $text);
    $text = preg_replace('/__([^_]+)__/u', '$1', $text);
    $text = preg_replace('/`{1,3}/u', '', $text);
    $text = preg_replace('/^\s{0,3}#{1,6}\s*/mu', '', $text);
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/u', '$1 ($2)', $text);
    return trim((string)$text);
}

function chatIntentText($value, int $max = 900): string {
    $text = strtolower(chatSafeText($value, $max));
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($ascii) && $ascii !== '') $text = strtolower($ascii);
    }
    return preg_replace('/[^a-z0-9]+/', ' ', $text);
}

function chatPolygonAnchor($value): ?array {
    if (!is_array($value)) return null;
    $points = [];
    foreach (array_slice($value, 0, 700) as $point) {
        if (!is_array($point)) continue;
        $lat = $point['lat'] ?? ($point[0] ?? null);
        $lng = $point['lng'] ?? ($point[1] ?? null);
        if (!is_numeric($lat) || !is_numeric($lng)) continue;
        $lat = (float)$lat;
        $lng = (float)$lng;
        if ($lat < -18.7 || $lat > 0.5 || $lng < -82.8 || $lng > -68.0) continue;
        $points[] = [$lat, $lng];
    }
    if (count($points) < 3) return null;

    $centerLat = array_sum(array_column($points, 0)) / count($points);
    $centerLng = array_sum(array_column($points, 1)) / count($points);
    $maxRadius = 0.0;
    foreach ($points as [$lat, $lng]) {
        $maxRadius = max($maxRadius, zDistanceKm($centerLat, $centerLng, $lat, $lng));
    }
    $searchRadius = min(180.0, max(25.0, $maxRadius * 1.35 + 10.0));

    return [
        'lat'=>round($centerLat, 6),
        'lng'=>round($centerLng, 6),
        'label'=>'poligono KML o zona actual',
        'radius_km'=>round($searchRadius, 1),
        'polygon_radius_km'=>round($maxRadius, 1),
        'point_count'=>count($points),
    ];
}

function chatTopSets(int $limit = 8): array {
    $out = [];
    foreach (array_slice(zCachedAvailableSets(), 0, $limit) as $set) {
        $a = $set['availability'] ?? [];
        $out[] = [
            'nombre'=>$set['nombre'] ?? '',
            'empresa'=>$set['empresa'] ?? '',
            'lat'=>$set['lat'] ?? null,
            'lng'=>$set['lng'] ?? null,
            'capacidad_mva'=>$a['capacidad_total_mva'] ?? null,
            'demanda_mva'=>$a['demanda_total_mva'] ?? null,
            'disponible_mva'=>$a['disponible_total_mva'] ?? null,
            'disponible_tecnico_mva'=>$a['disponible_tecnico_mva'] ?? null,
            'disponible_planificacion_mva'=>$a['disponible_planificacion_mva'] ?? null,
            'transformador_recomendado'=>$a['transformador_recomendado'] ?? null,
            'barra_recomendada'=>$a['barra_recomendada'] ?? null,
            'tension_recomendada_kv'=>$a['tension_recomendada_kv'] ?? null,
            'criterio'=>$a['criterio_disponibilidad'] ?? null,
            'capacidad_firme_n_1_mva'=>$a['capacidad_firme_n_1_mva'] ?? null,
            'estado'=>$a['estado'] ?? '',
        ];
    }
    return $out;
}

function chatLayerInventory(): array {
    $items = [];
    foreach (zSources() as $source) {
        $count = 0;
        $path = (string)($source['path'] ?? '');
        $path = zResolveGeoJsonPath($path) ?: $path;
        if (is_file($path)) {
            $head = (string)file_get_contents($path, false, null, 0, 4096);
            if (str_ends_with(strtolower($path), '.gz')) {
                $count = null;
            } elseif (preg_match('/"features"\s*:\s*(\d+)/', $head, $m)) {
                $count = (int)$m[1];
            } elseif (filesize($path) < 6 * 1024 * 1024) {
                $data = json_decode((string)file_get_contents($path), true);
                $count = is_array($data['features'] ?? null) ? count($data['features']) : 0;
            } else {
                $count = null;
            }
        }
        $items[] = ['capa'=>$source['label'] ?? '', 'tipo'=>$source['kind'] ?? '', 'registros'=>$count, 'cache_mb'=>is_file($path) ? round(filesize($path) / 1048576, 1) : 0];
    }
    return array_slice($items, 0, 24);
}

function chatShouldDrawPolygons(array $messages): bool {
    $last = chatIntentText(end($messages)['content'] ?? '', 700);
    foreach ([
        'dibujar', 'dibuja', 'graficar', 'grafica', 'poligono', 'poligonos',
        'zonas sugeridas', 'sugerir zonas', 'proponer zonas', 'oportunidades',
        'buscar zonas', 'mejores zonas', 'marcar en mapa', 'pintar en mapa',
        'mostrar en mapa', 'propuesta de zona', 'propuestas de zona'
    ] as $needle) {
        if (str_contains($last, $needle)) return true;
    }
    return false;
}

function chatWantsEntityAnchoredGeometry(array $messages): bool {
    $last = chatIntentText(end($messages)['content'] ?? '', 1200);
    if (!chatShouldDrawPolygons($messages)) return false;
    foreach ([
        'adyacente', 'alrededor', 'contorno', 'junto a', 'cerca de', 'colindante',
        'rodeando', 'frente a', 'a un lado', 'ampliacion', 'perimetro de', 'entorno de'
    ] as $needle) {
        if (str_contains($last, chatIntentText($needle, 80))) return true;
    }
    return (bool)preg_match('/\b(set|subestacion|linea|lt|concesion|zona)\b/', $last);
}

function chatRequestedPolygonCount(array $messages): int {
    $last = chatIntentText(end($messages)['content'] ?? '', 1000);
    if (preg_match('/\b(un|uno|una)\s+solo\s+(poligono|zona)\b/', $last)
        || preg_match('/\bsolo\s+(un|uno|una)\s+(poligono|zona)\b/', $last)
        || preg_match('/\b(un|uno|una)\s+(unico|unica)\s+(poligono|zona)\b/', $last)) return 1;
    if (preg_match('/\b([1-9]|1[0-2])\s+(poligono|poligonos|zona|zonas)\b/', $last, $m)) return max(1, min(12, (int)$m[1]));
    $words = ['un'=>1,'uno'=>1,'una'=>1,'dos'=>2,'tres'=>3,'cuatro'=>4,'cinco'=>5,'seis'=>6,'siete'=>7,'ocho'=>8,'nueve'=>9,'diez'=>10];
    foreach ($words as $word => $count) {
        if (preg_match('/\b' . $word . '\s+(poligono|poligonos|zona|zonas)\b/', $last)) return $count;
    }
    if (preg_match('/\b(poligono|zona)\b/', $last) && !preg_match('/\b(poligonos|zonas|varias|varios|alternativas)\b/', $last)) return 1;
    return 5;
}

function chatEntityScore(string $query, string $name): int {
    $q = zNormText($query); $n = zNormText($name);
    if ($q === '' || strlen($n) < 3) return 0;
    $core = trim((string)preg_replace('/^(set|subestacion|linea|lt|zona|concesion|central)\s+/', '', $n));
    $score = 0;
    if (strlen($n) >= 5 && str_contains($q, $n)) $score += 190 + min(40, strlen($n));
    if (strlen($core) >= 5 && str_contains($q, $core)) $score += 165 + min(35, strlen($core));
    $stop = array_flip(['set','subestacion','linea','zona','concesion','central','electrica','electrico','kv','de','del','la','el']);
    $nameWords = array_values(array_filter(explode(' ', $core), fn($word) => strlen($word) >= 3 && !isset($stop[$word])));
    $queryWords = array_flip(array_filter(explode(' ', $q), fn($word) => strlen($word) >= 3));
    $matches = 0;
    foreach ($nameWords as $word) if (isset($queryWords[$word])) $matches++;
    if ($matches >= 2) $score += $matches * 22 + (int)round(55 * $matches / max(1, count($nameWords)));
    elseif ($matches === 1 && count($nameWords) === 1 && strlen($nameWords[0]) >= 6) $score += 55;
    return $score;
}

function chatEntityBounds(array $coords): ?array {
    if (!$coords) return null;
    $lats = array_column($coords, 0); $lngs = array_column($coords, 1);
    return ['norte'=>max($lats), 'sur'=>min($lats), 'este'=>max($lngs), 'oeste'=>min($lngs)];
}

function chatResolveMentionedEntities(array $messages, mixed $viewer): array {
    $last = chatLastUserMessage($messages);
    $ranked = [];
    $client = is_array($viewer) && is_array($viewer['entidades_mencionadas'] ?? null) ? $viewer['entidades_mencionadas'] : [];
    foreach (array_slice($client, 0, 12) as $item) {
        if (!is_array($item)) continue;
        $name = chatSafeText($item['nombre'] ?? '', 180);
        $center = is_array($item['centro'] ?? null) ? $item['centro'] : [];
        if ($name === '' || !is_numeric($center['lat'] ?? null) || !is_numeric($center['lng'] ?? null)) continue;
        $score = max((int)($item['score'] ?? 0), chatEntityScore($last, $name));
        if ($score < 48) continue;
        $ranked[] = [
            'score'=>$score + 30,
            'nombre'=>$name,
            'tipo'=>chatSafeText($item['tipo'] ?? 'Entidad de plataforma', 60),
            'fuente'=>chatSafeText($item['fuente'] ?? 'Capa activa del visor', 120),
            'lat'=>(float)$center['lat'], 'lng'=>(float)$center['lng'],
            'bounds'=>is_array($item['bounds'] ?? null) ? $item['bounds'] : null,
            'propiedades'=>is_array($item['propiedades'] ?? null) ? array_slice($item['propiedades'], 0, 18, true) : [],
            'origen'=>'indice_cliente',
        ];
    }

    foreach (zCachedAvailableSets() as $set) {
        $name = (string)($set['nombre'] ?? '');
        $score = chatEntityScore($last, $name);
        if ($score < 48) continue;
        $ranked[] = [
            'score'=>$score + 20, 'nombre'=>$name, 'tipo'=>'SET',
            'fuente'=>(string)($set['fuente'] ?? 'Disponibilidad cacheada'),
            'lat'=>(float)$set['lat'], 'lng'=>(float)$set['lng'], 'bounds'=>null,
            'propiedades'=>['empresa'=>$set['empresa'] ?? '', 'disponibilidad'=>$set['availability'] ?? []],
            'origen'=>'cache_disponibilidad',
        ];
    }

    if (!$ranked) {
        foreach (zSources() as $source) {
            $path = zSourceScanPath($source);
            if (!$path || !zShouldScanSource($source, $path)) continue;
            foreach (zReadGeoJson($path) as $feature) {
                if (!is_array($feature)) continue;
                $name = zFeatureName($feature, '');
                $score = chatEntityScore($last, $name);
                if ($score < 48) continue;
                $coords = zFeatureCoords($feature, 500);
                if (!$coords) continue;
                $bounds = chatEntityBounds($coords);
                $lat = array_sum(array_column($coords, 0)) / count($coords);
                $lng = array_sum(array_column($coords, 1)) / count($coords);
                $ranked[] = [
                    'score'=>$score, 'nombre'=>$name,
                    'tipo'=>(string)($source['kind'] ?? 'entidad'),
                    'fuente'=>(string)($source['label'] ?? 'Capa de plataforma'),
                    'lat'=>$lat, 'lng'=>$lng, 'bounds'=>$bounds,
                    'propiedades'=>is_array($feature['properties'] ?? null) ? array_slice($feature['properties'], 0, 18, true) : [],
                    'origen'=>'indice_servidor',
                ];
            }
        }
    }
    usort($ranked, fn($a, $b) => ($b['score'] <=> $a['score']));
    $out = []; $seen = [];
    foreach ($ranked as $item) {
        $key = zNormText(($item['tipo'] ?? '') . '|' . ($item['nombre'] ?? '') . '|' . round((float)$item['lat'], 5) . '|' . round((float)$item['lng'], 5));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $item;
        if (count($out) >= 8) break;
    }
    return $out;
}

function chatEntityPolygonCandidate(array $entity, string $request, int $index = 0): array {
    $intent = chatIntentText($request, 1200);
    $lat = (float)$entity['lat']; $lng = (float)$entity['lng'];
    $bounds = is_array($entity['bounds'] ?? null) ? $entity['bounds'] : [];
    $widthKm = isset($bounds['este'], $bounds['oeste']) ? abs((float)$bounds['este'] - (float)$bounds['oeste']) * 111.32 * max(.2, cos(deg2rad($lat))) : 0.0;
    $heightKm = isset($bounds['norte'], $bounds['sur']) ? abs((float)$bounds['norte'] - (float)$bounds['sur']) * 110.574 : 0.0;
    $baseRadius = max(0.25, min(3.0, max($widthKm, $heightKm) * .65 + .25));
    $adjacent = str_contains($intent, 'adyacente') || str_contains($intent, 'junto a') || str_contains($intent, 'colindante') || str_contains($intent, 'a un lado');
    $angle = deg2rad(($index * 67 + 90) % 360);
    $offsetKm = $adjacent ? $baseRadius * 1.75 : 0.0;
    $centerLat = $lat + cos($angle) * $offsetKm / 110.574;
    $centerLng = $lng + sin($angle) * $offsetKm / (111.32 * max(.2, cos(deg2rad($lat))));
    $polygon = [];
    for ($i = 0; $i < 16; $i++) {
        $theta = 2 * M_PI * $i / 16;
        $polygon[] = [
            round($centerLat + cos($theta) * $baseRadius / 110.574, 7),
            round($centerLng + sin($theta) * $baseRadius / (111.32 * max(.2, cos(deg2rad($centerLat)))), 7),
        ];
    }
    $name = (string)($entity['nombre'] ?? 'entidad');
    return [
        'nombre'=>($adjacent ? 'Poligono adyacente a ' : 'Poligono alrededor de ') . $name,
        'lat'=>round($centerLat, 7), 'lng'=>round($centerLng, 7),
        'polygon'=>$polygon, 'radio_referencia_km'=>round($baseRadius, 3),
        'score'=>100, 'viabilidad'=>'Trazado solicitado',
        'demanda_kw_referencia'=>null, 'tramite_recomendado'=>'Validacion territorial preliminar',
        'modo_suministro'=>'geometria_solicitada_por_usuario',
        'set_referencia'=>str_contains(zNormText((string)($entity['tipo'] ?? '')), 'set') ? [
            'nombre'=>$name, 'empresa'=>$entity['propiedades']['empresa'] ?? '', 'distancia_km'=>round($offsetKm, 3),
        ] : [],
        'lineas_cercanas'=>0, 'capas_externas_cercanas'=>0, 'evidencia_productiva'=>0,
        'razon'=>'Geometria generada exactamente desde la entidad resuelta en la plataforma; requiere validacion de campo y restricciones antes de uso tecnico.',
        'entidad_ancla'=>array_diff_key($entity, ['propiedades'=>true]),
        'request_geometry'=>true,
    ];
}

function chatNeedsOpportunityContext(array $messages): bool {
    $last = chatIntentText(end($messages)['content'] ?? '', 900);
    foreach ([
        'zona', 'zonas', 'potencial', 'concesion', 'convenio', 'distribucion',
        'agricola', 'agricultura', 'acuicola', 'produce', 'midagri',
        'disponibilidad', 'capacidad de carga', 'margen de carga', 'mejor lugar', 'donde conviene'
    ] as $needle) {
        if (str_contains($last, $needle)) return true;
    }
    return false;
}

function chatOpportunityFilters(array $messages): array {
    $last = chatIntentText(end($messages)['content'] ?? '', 1400);
    $macro = '';
    foreach (['selva','costa','sierra'] as $region) {
        if (str_contains($last, $region)) { $macro = $region; break; }
    }
    $deps = [
        'amazonas'=>'Amazonas','ancash'=>'Ancash','apurimac'=>'Apurimac','arequipa'=>'Arequipa',
        'ayacucho'=>'Ayacucho','cajamarca'=>'Cajamarca','callao'=>'Callao','cusco'=>'Cusco',
        'huancavelica'=>'Huancavelica','huanuco'=>'Huanuco','ica'=>'Ica','junin'=>'Junin',
        'la libertad'=>'La Libertad','lalibertad'=>'La Libertad','lambayeque'=>'Lambayeque',
        'lima'=>'Lima','loreto'=>'Loreto','madre de dios'=>'Madre de Dios','madrededios'=>'Madre de Dios',
        'moquegua'=>'Moquegua','pasco'=>'Pasco','piura'=>'Piura','puno'=>'Puno',
        'san martin'=>'San Martin','sanmartin'=>'San Martin','tacna'=>'Tacna','tumbes'=>'Tumbes','ucayali'=>'Ucayali',
    ];
    $foundDeps = [];
    foreach ($deps as $needle => $name) {
        $depNeedle = chatIntentText($needle, 80);
        if ($depNeedle !== '' && preg_match('/(^| )' . preg_quote($depNeedle, '/') . '( |$)/', $last)) $foundDeps[$name] = true;
    }
    $places = chatKnownPlaces();
    usort($places, fn($a, $b) => strlen($b['alias']) <=> strlen($a['alias']));
    $target = null;
    foreach ($places as $place) {
        $alias = chatIntentText($place['alias'], 80);
        if ($alias === '') continue;
        if (preg_match('/(^| )' . preg_quote($alias, '/') . '( |$)/', $last)) {
            $target = $place;
            $foundDeps[$place['departamento']] = true;
            if ($macro === '') $macro = $place['macro_region'];
            break;
        }
    }
    if (!$target && count($foundDeps) === 1) {
        $depName = array_key_first($foundDeps);
        $target = chatDepartmentTarget((string)$depName);
        if ($target && $macro === '') $macro = $target['macro_region'];
    }
    $sources = [];
    foreach ([
        'solar'=>'solar', 'solares'=>'solar',
        'eolica'=>'eolica', 'eolicas'=>'eolica', 'viento'=>'eolica',
        'biomasa'=>'biomasa',
        'termica'=>'termica', 'termicas'=>'termica',
        'hidro'=>'hidro', 'hidroelectrica'=>'hidro', 'hidroelectricas'=>'hidro',
    ] as $needle => $source) {
        if (str_contains($last, $needle)) $sources[$source] = true;
    }
    $generationWords = ['generacion','generar energia','centrales','central','solar','eolica','biomasa','termica','hidro','sin set','sin subestacion','no hay subestaciones'];
    $incluirGeneracion = false;
    foreach ($generationWords as $needle) {
        if (str_contains($last, preg_replace('/[^a-z0-9]+/', ' ', $needle))) { $incluirGeneracion = true; break; }
    }
    $sinSet = false;
    foreach (['sin set','sin subestacion','sin subestaciones','no hay set','no hay subestaciones','no hay subestacion','alejada de set'] as $needle) {
        if (str_contains($last, preg_replace('/[^a-z0-9]+/', ' ', $needle))) { $sinSet = true; break; }
    }
    return [
        'macro_region'=>$macro,
        'departamentos'=>array_values(array_keys($foundDeps)),
        'fuentes_generacion'=>array_values(array_keys($sources)),
        'incluir_generacion'=>$incluirGeneracion || $sinSet || (bool)$target,
        'sin_set_cercana'=>$sinSet,
        'distribuir_por_set'=>str_contains($last, 'cada set') || str_contains($last, 'por set') || str_contains($last, 'subestaciones disponibles'),
        'seed'=>abs(crc32($last . '|' . date('YmdHi'))),
        'target_center'=>$target ? [
            'lat'=>$target['lat'],
            'lng'=>$target['lng'],
            'label'=>$target['label'],
            'departamento'=>$target['departamento'],
            'radius_km'=>$target['radius_km'],
        ] : null,
    ];
}

function chatDepartmentTarget(string $department): ?array {
    $key = zNormText($department);
    $targets = [
        'amazonas'=>['label'=>'Amazonas', 'lat'=>-5.15, 'lng'=>-78.05, 'departamento'=>'Amazonas', 'macro_region'=>'selva', 'radius_km'=>170],
        'ancash'=>['label'=>'Ancash', 'lat'=>-9.45, 'lng'=>-77.55, 'departamento'=>'Ancash', 'macro_region'=>'sierra', 'radius_km'=>190],
        'apurimac'=>['label'=>'Apurimac', 'lat'=>-14.00, 'lng'=>-72.85, 'departamento'=>'Apurimac', 'macro_region'=>'sierra', 'radius_km'=>150],
        'arequipa'=>['label'=>'Arequipa', 'lat'=>-16.05, 'lng'=>-72.35, 'departamento'=>'Arequipa', 'macro_region'=>'costa', 'radius_km'=>230],
        'ayacucho'=>['label'=>'Ayacucho', 'lat'=>-13.80, 'lng'=>-74.05, 'departamento'=>'Ayacucho', 'macro_region'=>'sierra', 'radius_km'=>190],
        'cajamarca'=>['label'=>'Cajamarca', 'lat'=>-6.85, 'lng'=>-78.55, 'departamento'=>'Cajamarca', 'macro_region'=>'sierra', 'radius_km'=>190],
        'callao'=>['label'=>'Callao', 'lat'=>-12.05, 'lng'=>-77.13, 'departamento'=>'Callao', 'macro_region'=>'costa', 'radius_km'=>35],
        'cusco'=>['label'=>'Cusco', 'lat'=>-13.10, 'lng'=>-72.35, 'departamento'=>'Cusco', 'macro_region'=>'sierra', 'radius_km'=>220],
        'huancavelica'=>['label'=>'Huancavelica', 'lat'=>-12.80, 'lng'=>-75.00, 'departamento'=>'Huancavelica', 'macro_region'=>'sierra', 'radius_km'=>150],
        'huanuco'=>['label'=>'Huanuco', 'lat'=>-9.70, 'lng'=>-75.90, 'departamento'=>'Huanuco', 'macro_region'=>'sierra', 'radius_km'=>190],
        'ica'=>['label'=>'Ica', 'lat'=>-14.15, 'lng'=>-75.55, 'departamento'=>'Ica', 'macro_region'=>'costa', 'radius_km'=>170],
        'junin'=>['label'=>'Junin', 'lat'=>-11.35, 'lng'=>-75.20, 'departamento'=>'Junin', 'macro_region'=>'sierra', 'radius_km'=>180],
        'lalibertad'=>['label'=>'La Libertad', 'lat'=>-7.95, 'lng'=>-78.45, 'departamento'=>'La Libertad', 'macro_region'=>'costa', 'radius_km'=>190],
        'lambayeque'=>['label'=>'Lambayeque', 'lat'=>-6.30, 'lng'=>-79.85, 'departamento'=>'Lambayeque', 'macro_region'=>'costa', 'radius_km'=>120],
        'lima'=>['label'=>'Lima', 'lat'=>-11.80, 'lng'=>-76.85, 'departamento'=>'Lima', 'macro_region'=>'costa', 'radius_km'=>230],
        'loreto'=>['label'=>'Loreto', 'lat'=>-4.05, 'lng'=>-74.50, 'departamento'=>'Loreto', 'macro_region'=>'selva', 'radius_km'=>430],
        'madrededios'=>['label'=>'Madre de Dios', 'lat'=>-12.00, 'lng'=>-70.20, 'departamento'=>'Madre de Dios', 'macro_region'=>'selva', 'radius_km'=>250],
        'moquegua'=>['label'=>'Moquegua', 'lat'=>-16.95, 'lng'=>-70.95, 'departamento'=>'Moquegua', 'macro_region'=>'costa', 'radius_km'=>150],
        'pasco'=>['label'=>'Pasco', 'lat'=>-10.50, 'lng'=>-75.30, 'departamento'=>'Pasco', 'macro_region'=>'sierra', 'radius_km'=>150],
        'piura'=>['label'=>'Piura', 'lat'=>-5.05, 'lng'=>-80.25, 'departamento'=>'Piura', 'macro_region'=>'costa', 'radius_km'=>170],
        'puno'=>['label'=>'Puno', 'lat'=>-15.20, 'lng'=>-69.95, 'departamento'=>'Puno', 'macro_region'=>'sierra', 'radius_km'=>220],
        'sanmartin'=>['label'=>'San Martin', 'lat'=>-7.00, 'lng'=>-76.70, 'departamento'=>'San Martin', 'macro_region'=>'selva', 'radius_km'=>190],
        'tacna'=>['label'=>'Tacna', 'lat'=>-17.65, 'lng'=>-70.45, 'departamento'=>'Tacna', 'macro_region'=>'costa', 'radius_km'=>150],
        'tumbes'=>['label'=>'Tumbes', 'lat'=>-3.80, 'lng'=>-80.45, 'departamento'=>'Tumbes', 'macro_region'=>'costa', 'radius_km'=>90],
        'ucayali'=>['label'=>'Ucayali', 'lat'=>-8.80, 'lng'=>-74.55, 'departamento'=>'Ucayali', 'macro_region'=>'selva', 'radius_km'=>280],
    ];
    if (!isset($targets[$key])) return null;
    return $targets[$key];
}

function chatKnownPlaces(): array {
    return [
        ['alias'=>'iquitos', 'label'=>'Iquitos', 'lat'=>-3.7437, 'lng'=>-73.2516, 'departamento'=>'Loreto', 'macro_region'=>'selva', 'radius_km'=>105],
        ['alias'=>'nauta', 'label'=>'Nauta', 'lat'=>-4.5086, 'lng'=>-73.5767, 'departamento'=>'Loreto', 'macro_region'=>'selva', 'radius_km'=>85],
        ['alias'=>'pucallpa', 'label'=>'Pucallpa', 'lat'=>-8.3791, 'lng'=>-74.5539, 'departamento'=>'Ucayali', 'macro_region'=>'selva', 'radius_km'=>95],
        ['alias'=>'tarapoto', 'label'=>'Tarapoto', 'lat'=>-6.4869, 'lng'=>-76.3597, 'departamento'=>'San Martin', 'macro_region'=>'selva', 'radius_km'=>85],
        ['alias'=>'moyobamba', 'label'=>'Moyobamba', 'lat'=>-6.0342, 'lng'=>-76.9717, 'departamento'=>'San Martin', 'macro_region'=>'selva', 'radius_km'=>85],
        ['alias'=>'puerto maldonado', 'label'=>'Puerto Maldonado', 'lat'=>-12.5933, 'lng'=>-69.1891, 'departamento'=>'Madre de Dios', 'macro_region'=>'selva', 'radius_km'=>95],
        ['alias'=>'bagua', 'label'=>'Bagua', 'lat'=>-5.6386, 'lng'=>-78.5311, 'departamento'=>'Amazonas', 'macro_region'=>'selva', 'radius_km'=>80],
        ['alias'=>'utcubamba', 'label'=>'Utcubamba', 'lat'=>-5.7564, 'lng'=>-78.4417, 'departamento'=>'Amazonas', 'macro_region'=>'selva', 'radius_km'=>80],
        ['alias'=>'jaen', 'label'=>'Jaen', 'lat'=>-5.7081, 'lng'=>-78.8078, 'departamento'=>'Cajamarca', 'macro_region'=>'sierra', 'radius_km'=>75],
        ['alias'=>'olmos', 'label'=>'Olmos', 'lat'=>-5.9847, 'lng'=>-79.7450, 'departamento'=>'Lambayeque', 'macro_region'=>'costa', 'radius_km'=>75],
        ['alias'=>'piura', 'label'=>'Piura', 'lat'=>-5.1945, 'lng'=>-80.6328, 'departamento'=>'Piura', 'macro_region'=>'costa', 'radius_km'=>80],
        ['alias'=>'tumbes', 'label'=>'Tumbes', 'lat'=>-3.5669, 'lng'=>-80.4515, 'departamento'=>'Tumbes', 'macro_region'=>'costa', 'radius_km'=>70],
        ['alias'=>'ica', 'label'=>'Ica', 'lat'=>-14.0678, 'lng'=>-75.7286, 'departamento'=>'Ica', 'macro_region'=>'costa', 'radius_km'=>85],
        ['alias'=>'majes', 'label'=>'Majes', 'lat'=>-16.3533, 'lng'=>-72.1884, 'departamento'=>'Arequipa', 'macro_region'=>'costa', 'radius_km'=>75],
        ['alias'=>'ilo', 'label'=>'Ilo', 'lat'=>-17.6394, 'lng'=>-71.3375, 'departamento'=>'Moquegua', 'macro_region'=>'costa', 'radius_km'=>65],
        ['alias'=>'la yarada', 'label'=>'La Yarada', 'lat'=>-18.1964, 'lng'=>-70.5509, 'departamento'=>'Tacna', 'macro_region'=>'costa', 'radius_km'=>60],
        ['alias'=>'cusco', 'label'=>'Cusco', 'lat'=>-13.5320, 'lng'=>-71.9675, 'departamento'=>'Cusco', 'macro_region'=>'sierra', 'radius_km'=>85],
        ['alias'=>'quillabamba', 'label'=>'Quillabamba', 'lat'=>-12.8642, 'lng'=>-72.6936, 'departamento'=>'Cusco', 'macro_region'=>'selva', 'radius_km'=>85],
        ['alias'=>'puno', 'label'=>'Puno', 'lat'=>-15.8402, 'lng'=>-70.0219, 'departamento'=>'Puno', 'macro_region'=>'sierra', 'radius_km'=>85],
        ['alias'=>'ayacucho', 'label'=>'Ayacucho', 'lat'=>-13.1631, 'lng'=>-74.2236, 'departamento'=>'Ayacucho', 'macro_region'=>'sierra', 'radius_km'=>80],
        ['alias'=>'cajamarca', 'label'=>'Cajamarca', 'lat'=>-7.1617, 'lng'=>-78.5128, 'departamento'=>'Cajamarca', 'macro_region'=>'sierra', 'radius_km'=>80],
        ['alias'=>'huancavelica', 'label'=>'Huancavelica', 'lat'=>-12.7864, 'lng'=>-74.9764, 'departamento'=>'Huancavelica', 'macro_region'=>'sierra', 'radius_km'=>75],
        ['alias'=>'huanuco', 'label'=>'Huanuco', 'lat'=>-9.9306, 'lng'=>-76.2422, 'departamento'=>'Huanuco', 'macro_region'=>'sierra', 'radius_km'=>80],
    ];
}

function chatWantsWeb(array $messages): bool {
    $last = chatIntentText(end($messages)['content'] ?? '', 900);
    foreach (['internet','web','actual','noticia','buscar','sunarp','produce','midagri','google','propietario','partida','fuente','fuentes','utilidades','puedes analizar'] as $needle) {
        if (str_contains($last, $needle)) return true;
    }
    return false;
}

function chatWantsPdf(array $messages): bool {
    $last = chatIntentText(end($messages)['content'] ?? '', 900);
    foreach (['pdf','informe','descargar','exportar','documento'] as $needle) {
        if (str_contains($last, $needle)) return true;
    }
    return false;
}

function chatIsUtilityQuestion(array $messages): bool {
    $last = chatIntentText(end($messages)['content'] ?? '', 1200);
    foreach (['utilidades','que puedes analizar','puedes analizar','listado de utilidades','que haces','funciones'] as $needle) {
        if (str_contains($last, $needle)) return true;
    }
    return false;
}

function chatWantsLocateEntity(array $messages): bool {
    $last = chatIntentText(end($messages)['content'] ?? '', 1200);
    $locate = false;
    foreach ([
        'ubicar', 'ubica', 'ubicame', 'localizar', 'localiza', 'muestrame',
        'mostrarme', 'donde esta', 'donde queda', 'centrar', 'llevame', 'llevarme'
    ] as $needle) {
        if (str_contains($last, $needle)) { $locate = true; break; }
    }
    if (!$locate) return false;
    foreach ([
        'analizar', 'analiza', 'evaluar', 'evalua', 'disponibilidad', 'capacidad',
        'demanda', 'carga', 'sobrecarga', 'factibilidad', 'propuesta', 'proponer',
        'dibujar', 'dibuja', 'poligono', 'contorno', 'adyacente', 'ampliacion'
    ] as $needle) {
        if (str_contains($last, $needle)) return false;
    }
    return true;
}

function chatClassifyRequest(array $messages, array $entities = []): string {
    if (chatWantsLocateEntity($messages)) return 'locate_entity';
    if (chatShouldDrawPolygons($messages)) return 'draw_geometry';
    $last = chatIntentText(end($messages)['content'] ?? '', 1200);
    if ($entities && preg_match('/\b(disponibilidad|capacidad|demanda|carga|sobrecarga|factibilidad|analizar|analiza|evaluar|evalua)\b/', $last)) {
        return 'analyze_entity';
    }
    if (chatNeedsOpportunityContext($messages)) return 'analyze_opportunities';
    return 'general';
}

function chatEntityForClient(array $entity): array {
    return [
        'nombre'=>chatSafeText($entity['nombre'] ?? 'Entidad', 180),
        'tipo'=>chatSafeText($entity['tipo'] ?? 'Entidad de plataforma', 80),
        'fuente'=>chatSafeText($entity['fuente'] ?? 'Capa cargada', 160),
        'lat'=>is_numeric($entity['lat'] ?? null) ? round((float)$entity['lat'], 7) : null,
        'lng'=>is_numeric($entity['lng'] ?? null) ? round((float)$entity['lng'], 7) : null,
        'bounds'=>is_array($entity['bounds'] ?? null) ? $entity['bounds'] : null,
    ];
}

function chatEntityProperty(array $entity, array $needles): string {
    $properties = is_array($entity['propiedades'] ?? null) ? $entity['propiedades'] : [];
    foreach ($properties as $key => $value) {
        if (is_array($value) || is_object($value) || $value === null) continue;
        $normKey = chatIntentText((string)$key, 100);
        foreach ($needles as $needle) {
            if ($normKey === $needle || str_contains($normKey, $needle)) return chatSafeText($value, 120);
        }
    }
    return '';
}

function chatLocalEntityLocationAnswer(array $entity): string {
    $name = chatSafeText($entity['nombre'] ?? 'la entidad', 180);
    $type = chatSafeText($entity['tipo'] ?? 'entidad de plataforma', 80);
    $source = chatSafeText($entity['fuente'] ?? 'capa cargada', 160);
    $lines = [
        sprintf('Si. Encontre %s como %s y la centre en el geovisor.', $name, $type),
    ];
    if (is_numeric($entity['lat'] ?? null) && is_numeric($entity['lng'] ?? null)) {
        $lines[] = sprintf('Ubicacion: %.6f, %.6f.', (float)$entity['lat'], (float)$entity['lng']);
    }
    $company = chatEntityProperty($entity, ['empresa', 'titular', 'propietario']);
    $voltage = chatEntityProperty($entity, ['tension', 'voltaje', 'kv']);
    if ($company !== '') $lines[] = 'Empresa o titular registrado: ' . $company . '.';
    if ($voltage !== '') $lines[] = 'Tension registrada: ' . $voltage . (preg_match('/kv/i', $voltage) ? '.' : ' kV.');
    $lines[] = 'Fuente: ' . $source . '.';
    $lines[] = 'La identificacion se realizo por el nombre visible en las capas; no es necesario indicar un codigo interno.';
    return implode("\n", $lines);
}

function chatCompactViewer($viewer): array {
    if (!is_array($viewer)) return ['nota'=>'El navegador no envio estado de capas activas en esta consulta.'];
    $active = [];
    foreach (array_slice(is_array($viewer['capas_activas'] ?? null) ? $viewer['capas_activas'] : [], 0, 30) as $layer) {
        if (!is_array($layer)) continue;
        $active[] = [
            'id'=>chatSafeText($layer['id'] ?? '', 80), 'nombre'=>chatSafeText($layer['nombre'] ?? '', 120),
            'grupo'=>chatSafeText($layer['grupo'] ?? '', 100), 'tipo'=>chatSafeText($layer['tipo'] ?? '', 60),
            'proveedor'=>chatSafeText($layer['proveedor'] ?? '', 60),
        ];
    }
    $temporary = [];
    foreach (array_slice(is_array($viewer['geometrias_kml_temporales'] ?? null) ? $viewer['geometrias_kml_temporales'] : [], 0, 8) as $geometry) {
        if (!is_array($geometry)) continue;
        $geometry['coordenadas_muestra'] = array_slice(is_array($geometry['coordenadas_muestra'] ?? null) ? $geometry['coordenadas_muestra'] : [], 0, 6);
        $temporary[] = $geometry;
    }
    return [
        'capas_activas'=>$active,
        'total_capas_activas'=>(int)($viewer['total_capas_activas'] ?? count($active)),
        'vista_mapa'=>is_array($viewer['vista_mapa'] ?? null) ? $viewer['vista_mapa'] : null,
        'capas_indexadas'=>(int)($viewer['capas_indexadas'] ?? 0),
        'tiene_poligono_actual'=>(bool)($viewer['tiene_poligono_actual'] ?? false),
        'origen_poligono_actual'=>chatSafeText($viewer['origen_poligono_actual'] ?? '', 100),
        'geometrias_kml_temporales'=>$temporary,
    ];
}

function chatLocalGeneralAnswer(array $messages, array $computedOpportunities, array $filters = [], string $error = ''): string {
    $last = chatIntentText(end($messages)['content'] ?? '', 1200);
    if (chatIsUtilityQuestion($messages)) {
        $lines = [
            'Puedo ayudarte con estas utilidades dentro del geovisor:',
            '1. Graficar poligonos preliminares para concesion o convenio por departamento, ciudad o zona indicada.',
            '2. Evaluar distribucion desde SET cercanas con disponibilidad de carga.',
            '3. Comparar generacion local: solar, eolica, hidroelectrica, biomasa y termica/respaldo.',
            '4. Revisar evidencia productiva MIDAGRI, PRODUCE acuicultura, predios, concesiones y restricciones cargadas.',
            '5. Diferenciar si corresponde concesion de distribucion o convenio municipal segun demanda referencial.',
            '6. Exportar poligonos a KML o GeoJSON y generar PDF de la respuesta o de una zona evaluada.',
            '7. Usar busqueda web mediante OpenAI cuando la API esta disponible para complementar con fuentes externas.',
        ];
        if ($error !== '') $lines[] = 'Nota: la busqueda web de OpenAI no respondio en este momento: ' . chatSafeText($error, 180) . '. El analisis local sigue disponible.';
        return implode("\n", $lines);
    }
    if ($computedOpportunities) return chatLocalFallback($computedOpportunities, $error, $filters);
    $msg = 'Puedo responder con la informacion local del geovisor y generar PDF, pero no pude completar la consulta con OpenAI en este momento.';
    if ($error !== '') $msg .= ' Detalle tecnico: ' . chatSafeText($error, 180) . '.';
    $msg .= "\nPuede pedirme graficar zonas por departamento o ciudad, evaluar generacion y distribucion, o exportar un informe PDF.";
    return $msg;
}

function chatLocalRequestAnswer(array $messages, array $computedOpportunities, array $filters, bool $anchored, array $entities, string $error = '', string $intent = 'general'): string {
    if ($intent === 'locate_entity') {
        if ($entities) return chatLocalEntityLocationAnswer($entities[0]);
        return 'No pude identificar con certeza la entidad solicitada en las capas cargadas. Escriba el nombre completo tal como aparece en el mapa o active su capa; no necesita indicar un codigo interno.';
    }
    if ($intent === 'analyze_entity' && $entities && $error !== '') {
        $entity = $entities[0];
        $text = sprintf(
            'Identifique %s como %s en la fuente %s, pero no completare una conclusion tecnica automatica con una respuesta ajena al pedido.',
            $entity['nombre'] ?? 'la entidad',
            $entity['tipo'] ?? 'entidad de plataforma',
            $entity['fuente'] ?? 'capa cargada'
        );
        $text .= ' OpenAI esta temporalmente limitado; reintente en unos segundos para completar el analisis con los datos de esa entidad.';
        return $text;
    }
    if (!$anchored) return chatLocalGeneralAnswer($messages, $computedOpportunities, $filters, $error);
    if (!$entities) {
        $text = 'No dibuje poligonos genericos porque el pedido depende de una entidad concreta y no pude identificarla con certeza en las capas disponibles. Active la capa correspondiente o escriba el nombre completo visible en el mapa; no necesita indicar un codigo interno.';
        if ($error !== '') $text .= ' Estado de GPT: ' . chatSafeText($error, 180) . '.';
        return $text;
    }
    $entity = $entities[0];
    $count = count($computedOpportunities);
    $text = sprintf(
        'Ubique %s como %s en la fuente %s. Dibuje %d %s preliminar solicitado usando esa entidad como ancla geografica; no agregue propuestas ajenas al pedido.',
        $entity['nombre'] ?? 'la entidad',
        $entity['tipo'] ?? 'entidad de plataforma',
        $entity['fuente'] ?? 'capa cargada',
        $count,
        $count === 1 ? 'poligono' : 'poligonos'
    );
    $text .= ' El contorno es referencial y debe contrastarse con predios, concesiones, servidumbres, restricciones y levantamiento de campo antes de usarlo en un expediente.';
    if ($error !== '') $text .= ' La redaccion de GPT no estuvo disponible: ' . chatSafeText($error, 180) . '.';
    return $text;
}

function chatCompactCandidate(array $c): array {
    $set = is_array($c['set_referencia'] ?? null) ? $c['set_referencia'] : [];
    $compact = [
        'nombre'=>$c['nombre'] ?? '',
        'score'=>$c['score'] ?? null,
        'viabilidad'=>$c['viabilidad'] ?? '',
        'centro'=>['lat'=>$c['lat'] ?? null, 'lng'=>$c['lng'] ?? null],
        'radio_km'=>$c['radio_referencia_km'] ?? null,
        'demanda_kw'=>$c['demanda_kw_referencia'] ?? null,
        'tramite'=>$c['tramite_recomendado'] ?? '',
        'evidencia_productiva'=>$c['evidencia_productiva'] ?? 0,
        'fuentes_productivas'=>$c['fuentes_productivas'] ?? [],
        'departamento'=>$c['departamento'] ?? '',
        'macro_region'=>$c['macro_region'] ?? '',
        'modo_suministro'=>$c['modo_suministro'] ?? '',
        'fuente_generacion_recomendada'=>$c['fuente_generacion_recomendada'] ?? '',
        'alternativas_generacion'=>array_slice(is_array($c['alternativas_generacion'] ?? null) ? $c['alternativas_generacion'] : [], 0, 5),
        'razon_generacion'=>$c['razon_generacion'] ?? '',
        'set'=>[
            'nombre'=>$set['nombre'] ?? '',
            'empresa'=>$set['empresa'] ?? '',
            'distancia_km'=>$set['distancia_km'] ?? null,
            'disponible_mva'=>$set['disponible_total_mva'] ?? null,
            'estado'=>$set['estado'] ?? '',
        ],
    ];
    if (!empty($c['request_geometry'])) {
        $compact['request_geometry'] = true;
        $compact['entidad_ancla'] = $c['entidad_ancla'] ?? null;
        $compact['polygon'] = array_slice(is_array($c['polygon'] ?? null) ? $c['polygon'] : [], 0, 40);
    }
    return $compact;
}

function chatExtractText(array $data): string {
    if (isset($data['output_text'])) return trim((string)$data['output_text']);
    $parts = [];
    foreach (($data['output'] ?? []) as $item) {
        foreach (($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                $parts[] = (string)$content['text'];
            }
        }
    }
    return trim(implode("\n", $parts));
}

function chatExtractSources($value, array &$out = []): array {
    if (count($out) >= 12) return $out;
    if (is_array($value)) {
        $url = isset($value['url']) && is_string($value['url']) ? $value['url'] : '';
        if ($url !== '' && preg_match('~^https?://~i', $url)) {
            $key = strtolower($url);
            $seen = [];
            foreach ($out as $src) $seen[strtolower((string)($src['url'] ?? ''))] = true;
            if (empty($seen[$key])) {
                $out[] = [
                    'title'=>(string)($value['title'] ?? $value['name'] ?? $value['source'] ?? 'Fuente web'),
                    'url'=>$url,
                ];
            }
        }
        foreach ($value as $child) {
            if (count($out) >= 12) break;
            if (is_array($child)) chatExtractSources($child, $out);
        }
    }
    return $out;
}

function chatLocalFallback(array $computedOpportunities, string $error = '', array $filters = []): string {
    if (!$computedOpportunities) {
        $message = 'Analisis local de contingencia: no encontre poligonos viables con la data cargada para la zona indicada. Revise si existen capas productivas, SET, concesiones y limites cargados para ese ambito.';
        if ($error !== '') $message .= ' Estado de GPT: ' . chatSafeText($error, 220);
        return $message;
    }
    $target = is_array($filters['target_center'] ?? null) ? $filters['target_center'] : [];
    $scope = trim((string)($target['label'] ?? $filters['target_label'] ?? ''));
    if ($scope === '' && !empty($filters['departamentos']) && is_array($filters['departamentos'])) $scope = implode(', ', $filters['departamentos']);
    if ($scope === '' && !empty($filters['macro_region'])) $scope = ucfirst((string)$filters['macro_region']);
    $lines = ['Analisis local de contingencia del geovisor; esta respuesta no fue redactada por GPT.'];
    if ($scope !== '') $lines[] = 'Ambito aplicado: ' . $scope . '.';
    if (isset($target['lat'], $target['lng'])) {
        $lines[] = sprintf(
            'Ancla geografica: %.6f, %.6f; radio de busqueda aproximado %s km.',
            (float)$target['lat'],
            (float)$target['lng'],
            $target['radius_km'] ?? '-'
        );
    }
    foreach (array_slice($computedOpportunities, 0, 5) as $idx => $zone) {
        $set = is_array($zone['set_referencia'] ?? null) ? $zone['set_referencia'] : [];
        $alts = [];
        foreach (array_slice(is_array($zone['alternativas_generacion'] ?? null) ? $zone['alternativas_generacion'] : [], 0, 3) as $alt) {
            $alts[] = ($alt['nombre'] ?? $alt['fuente'] ?? '-') . ' ' . ($alt['prioridad'] ?? '') . ' ' . ($alt['score'] ?? '-') . '/100';
        }
        if ($set) {
            $lines[] = sprintf(
                '%d. %s: %s, score %s, demanda referencial %s kW, SET cercana %s con %s MVA libres. Generacion complementaria: %s.',
                $idx + 1,
                $zone['nombre'] ?? 'Zona propuesta',
                $zone['viabilidad'] ?? 'viabilidad por revisar',
                $zone['score'] ?? '-',
                $zone['demanda_kw_referencia'] ?? '-',
                $set['nombre'] ?? '-',
                $set['disponible_total_mva'] ?? '-',
                $alts ? implode('; ', $alts) : 'por evaluar'
            );
        } else {
            $lines[] = sprintf(
                '%d. %s: %s, score %s, demanda referencial %s kW, sin SET cercana priorizada. Alternativas: %s.',
                $idx + 1,
                $zone['nombre'] ?? 'Zona propuesta',
                $zone['viabilidad'] ?? 'viabilidad por revisar',
                $zone['score'] ?? '-',
                $zone['demanda_kw_referencia'] ?? '-',
                $alts ? implode('; ', $alts) : ($zone['fuente_generacion_recomendada'] ?? 'generacion local')
            );
        }
    }
    if ($error !== '') $lines[] = 'Nota tecnica: ' . chatSafeText($error, 220);
    return chatCleanOutput(implode("\n", $lines));
}

function chatEnvValue(string $name): string {
    $value = getenv($name);
    if ($value === false && isset($_ENV[$name])) $value = $_ENV[$name];
    if ($value === false && isset($_SERVER[$name])) $value = $_SERVER[$name];
    return trim((string)($value === false ? '' : $value));
}

function chatSplitOpenAIKeys(string $value): array {
    if ($value === '') return [];
    $parts = preg_split('/[,\s;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) return [];
    return array_map('trim', $parts);
}

function chatConfiguredOpenAIKeys(): array {
    $keys = function_exists('getOpenAIKeys') ? getOpenAIKeys() : [];
    if (!$keys) {
        $keys = chatSplitOpenAIKeys(chatEnvValue('OPENAI_API_KEYS'));
        foreach (['OPENAI_API_KEY', 'OPENAI_API_KEY_1', 'OPENAI_API_KEY_2'] as $name) {
            $keys = array_merge($keys, chatSplitOpenAIKeys(chatEnvValue($name)));
        }
    }
    $out = [];
    $seen = [];
    foreach ($keys as $key) {
        $key = trim((string)$key);
        if ($key === '') continue;
        $hash = hash('sha256', $key);
        if (isset($seen[$hash])) continue;
        $seen[$hash] = true;
        $out[] = $key;
    }
    return $out;
}

function chatSelectOpenAIKey(array $keys, array $messages, string $rawInput): string {
    $keys = array_values(array_filter($keys, fn($key) => trim((string)$key) !== ''));
    $count = count($keys);
    if ($count === 0) return '';
    if ($count === 1) return (string)$keys[0];
    $lastUser = '';
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (($messages[$i]['role'] ?? '') === 'user') {
            $lastUser = (string)($messages[$i]['content'] ?? '');
            break;
        }
    }
    $basis = $rawInput !== '' ? $rawInput : json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $seed = $lastUser . '|' . (string)$basis;
    $index = (int)(sprintf('%u', crc32($seed)) % $count);
    return (string)$keys[$index];
}

function chatRedactOpenAISecrets(string $text, array $keys = []): string {
    if ($text === '') return '';
    foreach ($keys as $key) {
        $key = trim((string)$key);
        if (strlen($key) >= 8) $text = str_replace($key, '[clave OpenAI oculta]', $text);
    }
    $text = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer [clave OpenAI oculta]', $text);
    $text = preg_replace('/sk-[A-Za-z0-9._-]{8,}/i', '[clave OpenAI oculta]', $text);
    return (string)$text;
}

function chatOpenAIUserNotice(int $code, string $body = '', string $transportError = ''): string {
    $decoded = json_decode($body, true);
    $apiCode = strtolower((string)($decoded['error']['code'] ?? ''));
    $apiType = strtolower((string)($decoded['error']['type'] ?? ''));
    $message = strtolower((string)($decoded['error']['message'] ?? ''));
    if ($code === 429 && (str_contains($apiCode, 'insufficient_quota') || str_contains($message, 'quota') || str_contains($message, 'billing'))) {
        return 'La cuenta de OpenAI no tiene credito de API disponible. El geovisor conservara las funciones locales.';
    }
    if ($code === 429 || str_contains($apiType, 'rate_limit') || str_contains($message, 'rate limit')) {
        return 'OpenAI alcanzo temporalmente el limite de uso por minuto. Espere unos segundos y vuelva a intentarlo.';
    }
    if ($code === 401 || $code === 403) return 'OpenAI rechazo la autenticacion configurada. Revise la clave y los permisos del proyecto de API.';
    if ($code >= 500) return 'OpenAI esta temporalmente no disponible. Vuelva a intentarlo en unos segundos.';
    if ($code >= 400) return 'OpenAI rechazo temporalmente la solicitud (HTTP ' . $code . ').';
    if ($transportError !== '') return 'No se pudo establecer conexion con OpenAI en este momento.';
    return 'OpenAI no devolvio una respuesta valida en este momento.';
}

function chatMemoryPath(): string {
    return defined('AI_MEMORY_FILE') ? AI_MEMORY_FILE : dirname(ROOT) . '/.geovisor_ai_memory.json';
}

function chatMemoryDefault(): array {
    return ['version'=>1, 'updated_at'=>'', 'entries'=>[], 'preferences'=>[]];
}

function chatMemorySanitize(string $text, int $max = 1200): string {
    $text = chatSafeText($text, $max);
    $text = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer [clave OpenAI oculta]', $text);
    $text = preg_replace('/sk-[A-Za-z0-9._-]{8,}/i', '[clave OpenAI oculta]', $text);
    return trim((string)$text);
}

function chatMemoryLoad(): array {
    $path = chatMemoryPath();
    if (!is_file($path)) return chatMemoryDefault();
    $raw = (string)@file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) return chatMemoryDefault();
    if (!is_array($data['entries'] ?? null)) $data['entries'] = [];
    if (!is_array($data['preferences'] ?? null)) $data['preferences'] = [];
    $data['version'] = (int)($data['version'] ?? 1);
    return $data;
}

function chatMemorySave(array $memory): void {
    $path = chatMemoryPath();
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $memory['version'] = 1;
    $memory['updated_at'] = date('c');
    $memory['entries'] = array_slice(array_values(is_array($memory['entries'] ?? null) ? $memory['entries'] : []), -160);
    $memory['preferences'] = array_slice(array_values(is_array($memory['preferences'] ?? null) ? $memory['preferences'] : []), -80);
    @file_put_contents($path, json_encode($memory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function chatLastUserMessage(array $messages): string {
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (($messages[$i]['role'] ?? '') === 'user') return chatSafeText($messages[$i]['content'] ?? '', 1800);
    }
    return '';
}

function chatMemoryTags(string $text): array {
    $norm = chatIntentText($text, 1800);
    $map = [
        'concesiones'=>['concesion','concesiones','vigente','tramite','rural','definitiva'],
        'distribucion'=>['distribucion','media tension','mt','sed','set','subestacion'],
        'transmision'=>['transmision','linea','terna','kv','conductor','estructura'],
        'zonas'=>['zona','poligono','kml','kmz','ruta','relieve','viabilidad'],
        'reportes'=>['pdf','word','informe','plantilla','exportar','descargar'],
        'predios'=>['predio','sunarp','propietario','partida'],
        'productivo'=>['midagri','agricola','agricultura','produce','acuicultura','cultivo'],
        'operacion'=>['error','corrige','optimiza','pesado','carga','lento'],
    ];
    $tags = [];
    foreach ($map as $tag => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($norm, chatIntentText($needle, 80))) { $tags[] = $tag; break; }
        }
    }
    return array_values(array_unique($tags));
}

function chatMemoryKeywords(string $text): array {
    $norm = chatIntentText($text, 1800);
    $words = preg_split('/\s+/', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stop = array_flip(['para','como','cuando','donde','quiero','debe','deben','tenga','tiene','esto','esta','este','esas','esos','todo','todas','todos','bien','mejor','parte','opcion','hacer','realizar','consulta','consultas','analiza','analisis']);
    $out = [];
    foreach ($words as $w) {
        if (strlen($w) < 4 || isset($stop[$w])) continue;
        $out[$w] = true;
        if (count($out) >= 35) break;
    }
    return array_keys($out);
}

function chatMemoryPreference(string $lastUser): string {
    $norm = chatIntentText($lastUser, 1800);
    foreach (['quiero que','debe','deberia','corrige','corrigeme','prioriza','considera','separa','diferencia','ordenado','no recomiendes','no debe','evita','siempre','plantilla','formato'] as $signal) {
        if (str_contains($norm, chatIntentText($signal, 80))) return chatMemorySanitize($lastUser, 420);
    }
    return '';
}

function chatMemoryRecall(array $messages): array {
    $memory = chatMemoryLoad();
    $last = chatLastUserMessage($messages);
    $tags = chatMemoryTags($last);
    $keywords = chatMemoryKeywords($last);
    $ranked = [];
    foreach (($memory['entries'] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        $entryTags = is_array($entry['tags'] ?? null) ? $entry['tags'] : [];
        $entryKeywords = is_array($entry['keywords'] ?? null) ? $entry['keywords'] : [];
        $score = count(array_intersect($tags, $entryTags)) * 4 + count(array_intersect($keywords, $entryKeywords));
        if ($score <= 0) continue;
        $ranked[] = ['score'=>$score, 'entry'=>$entry];
    }
    usort($ranked, fn($a, $b) => ($b['score'] <=> $a['score']));
    $related = [];
    foreach (array_slice($ranked, 0, 8) as $item) {
        $entry = $item['entry'];
        $related[] = [
            'consulta'=>chatMemorySanitize((string)($entry['query'] ?? ''), 260),
            'temas'=>array_slice(is_array($entry['tags'] ?? null) ? $entry['tags'] : [], 0, 6),
            'veces_consultado'=>(int)($entry['count'] ?? 1),
            'ultima_vez'=>(string)($entry['last_seen_at'] ?? $entry['created_at'] ?? ''),
        ];
    }
    $prefs = [];
    foreach (array_slice(is_array($memory['preferences'] ?? null) ? $memory['preferences'] : [], -14) as $pref) {
        if (!is_array($pref)) continue;
        $prefs[] = [
            'criterio'=>chatMemorySanitize((string)($pref['text'] ?? ''), 360),
            'fecha'=>(string)($pref['last_seen_at'] ?? $pref['created_at'] ?? ''),
        ];
    }
    return [
        'modo'=>'memoria_local_contextual',
        'descripcion'=>'Memoria local del geovisor basada en consultas previas; no entrena el modelo base de OpenAI.',
        'total_consultas'=>count(is_array($memory['entries'] ?? null) ? $memory['entries'] : []),
        'temas_consulta_actual'=>$tags,
        'criterios_aprendidos'=>$prefs,
        'consultas_relacionadas'=>$related,
    ];
}

function chatMemoryRecordQuery(array $messages, array $filters = [], array $computedOpportunities = []): void {
    $last = chatLastUserMessage($messages);
    if ($last === '') return;
    $memory = chatMemoryLoad();
    $query = chatMemorySanitize($last, 900);
    $norm = chatIntentText($query, 1600);
    if ($norm === '') return;
    $hash = substr(hash('sha256', $norm), 0, 16);
    $tags = chatMemoryTags($query);
    $keywords = chatMemoryKeywords($query);
    $now = date('c');
    $found = false;
    foreach ($memory['entries'] as &$entry) {
        if (($entry['hash'] ?? '') === $hash) {
            $entry['last_seen_at'] = $now;
            $entry['count'] = (int)($entry['count'] ?? 1) + 1;
            $entry['tags'] = array_values(array_unique(array_merge(is_array($entry['tags'] ?? null) ? $entry['tags'] : [], $tags)));
            $entry['keywords'] = array_slice(array_values(array_unique(array_merge(is_array($entry['keywords'] ?? null) ? $entry['keywords'] : [], $keywords))), 0, 45);
            $entry['filters'] = $filters;
            $entry['computed_zones'] = count($computedOpportunities);
            $found = true;
            break;
        }
    }
    unset($entry);
    if (!$found) {
        $memory['entries'][] = [
            'hash'=>$hash,
            'created_at'=>$now,
            'last_seen_at'=>$now,
            'count'=>1,
            'query'=>$query,
            'tags'=>$tags,
            'keywords'=>$keywords,
            'filters'=>$filters,
            'computed_zones'=>count($computedOpportunities),
        ];
    }
    $pref = chatMemoryPreference($query);
    if ($pref !== '') {
        $prefHash = substr(hash('sha256', chatIntentText($pref, 900)), 0, 16);
        $prefFound = false;
        foreach ($memory['preferences'] as &$item) {
            if (($item['hash'] ?? '') === $prefHash) {
                $item['last_seen_at'] = $now;
                $item['count'] = (int)($item['count'] ?? 1) + 1;
                $prefFound = true;
                break;
            }
        }
        unset($item);
        if (!$prefFound) {
            $memory['preferences'][] = ['hash'=>$prefHash, 'created_at'=>$now, 'last_seen_at'=>$now, 'count'=>1, 'text'=>$pref, 'tags'=>$tags];
        }
    }
    chatMemorySave($memory);
}

$messages = is_array($input['messages'] ?? null) ? $input['messages'] : [];
$cleanMessages = [];
foreach (array_slice($messages, -6) as $msg) {
    if (!is_array($msg)) continue;
    $role = ($msg['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $content = chatSafeText($msg['content'] ?? '', 1200);
    if ($content !== '') $cleanMessages[] = ['role'=>$role, 'content'=>$content];
}
if (!$cleanMessages) jsonOut(['ok'=>false,'message'=>'Escriba una consulta para la IA.'], 400);
$openAIKeys = chatConfiguredOpenAIKeys();
$key = chatSelectOpenAIKey($openAIKeys, $cleanMessages, $raw);

$computedOpportunities = [];
$explicitWebRequest = chatWantsWeb($cleanMessages);
$webEnabled = $explicitWebRequest;
$pdfRequested = chatWantsPdf($cleanMessages);
$drawPolygons = chatShouldDrawPolygons($cleanMessages);
$anchoredGeometry = chatWantsEntityAnchoredGeometry($cleanMessages);
$locateRequested = chatWantsLocateEntity($cleanMessages);
$requestedPolygonCount = chatRequestedPolygonCount($cleanMessages);
$lastIntent = chatIntentText(chatLastUserMessage($cleanMessages), 1400);
$resolvedEntities = ($anchoredGeometry || $locateRequested || preg_match('/\b(set|subestacion|linea|lt|concesion|zona)\b/', $lastIntent))
    ? chatResolveMentionedEntities($cleanMessages, $input['viewer'] ?? [])
    : [];
$requestIntent = chatClassifyRequest($cleanMessages, $resolvedEntities);
$drawPolygons = $requestIntent === 'locate_entity' ? false : $drawPolygons;
$anchoredGeometry = $requestIntent === 'locate_entity' ? false : $anchoredGeometry;
$opportunityFilters = chatOpportunityFilters($cleanMessages);
$polygonAnchor = chatPolygonAnchor($input['current_polygon'] ?? null);
if ($resolvedEntities) {
    $entity = $resolvedEntities[0];
    $opportunityFilters['target_center'] = [
        'lat'=>$entity['lat'], 'lng'=>$entity['lng'], 'label'=>$entity['nombre'],
        'radius_km'=>max(3.0, min(80.0, (float)($opportunityFilters['target_center']['radius_km'] ?? 18.0))),
        'entity_type'=>$entity['tipo'], 'entity_source'=>$entity['fuente'],
    ];
    $opportunityFilters['target_label'] = $entity['nombre'];
}
if ($requestIntent === 'locate_entity') {
    $focusEntity = $resolvedEntities ? chatEntityForClient($resolvedEntities[0]) : null;
    jsonOut([
        'ok'=>true,
        'model'=>'motor-gis-local',
        'local'=>true,
        'intent'=>$requestIntent,
        'text'=>chatCleanOutput(chatLocalRequestAnswer($cleanMessages, [], $opportunityFilters, false, $resolvedEntities, '', $requestIntent)),
        'focus_entity'=>$focusEntity,
        'entities'=>array_map('chatEntityForClient', array_slice($resolvedEntities, 0, 5)),
        'polygons'=>[],
        'pdf_requested'=>$pdfRequested,
        'web'=>['enabled'=>false, 'available'=>count($openAIKeys) > 0, 'summary'=>'Ubicacion resuelta directamente con las capas del geovisor.'],
    ]);
}
if ($polygonAnchor && empty($opportunityFilters['target_center']) && ($drawPolygons || chatNeedsOpportunityContext($cleanMessages))) {
    $opportunityFilters['target_center'] = $polygonAnchor;
    $opportunityFilters['incluir_generacion'] = true;
}
if ($drawPolygons && $anchoredGeometry) {
    if ($resolvedEntities) {
        $entityCount = count($resolvedEntities);
        for ($i = 0; $i < $requestedPolygonCount; $i++) {
            $computedOpportunities[] = chatEntityPolygonCandidate($resolvedEntities[min($i, $entityCount - 1)], chatLastUserMessage($cleanMessages), $i);
        }
    }
} elseif ($drawPolygons || chatNeedsOpportunityContext($cleanMessages)) {
    try {
        $opp = zAnalyzeOpportunities(false, $opportunityFilters);
        $computedOpportunities = array_slice($opp['candidatos'] ?? [], 0, 12);
    } catch (Throwable $e) {
        $computedOpportunities = [];
    }
}
$memoryContext = chatMemoryRecall($cleanMessages);
chatMemoryRecordQuery($cleanMessages, $opportunityFilters, $computedOpportunities);

if (chatIsUtilityQuestion($cleanMessages)) {
    jsonOut([
        'ok'=>true,
        'model'=>'motor-gis-local',
        'local'=>true,
        'intent'=>$requestIntent,
        'text'=>chatCleanOutput(chatLocalRequestAnswer($cleanMessages, $computedOpportunities, $opportunityFilters, $anchoredGeometry, $resolvedEntities, '', $requestIntent)),
        'polygons'=>[],
        'pdf_requested'=>$pdfRequested,
        'web'=>[
            'enabled'=>true,
            'available'=>count($openAIKeys) > 0,
            'summary'=>count($openAIKeys) > 0
                ? 'El asistente tiene configurada busqueda web mediante OpenAI web_search para consultas que requieran informacion externa. Esta lista de utilidades se genera localmente desde las capacidades del geovisor.'
                : 'La busqueda web mediante OpenAI requiere configurar OPENAI_API_KEYS, OPENAI_API_KEY, OPENAI_API_KEY_1, OPENAI_API_KEY_2 o el archivo externo seguro .openai_api_key. Esta lista de utilidades se genera localmente desde las capacidades del geovisor.',
        ],
    ]);
}

if ($key === '') {
    jsonOut([
        'ok'=>true,
        'model'=>'motor-gis-local',
        'fallback'=>true,
        'intent'=>$requestIntent,
        'text'=>chatCleanOutput(chatLocalRequestAnswer($cleanMessages, $computedOpportunities, $opportunityFilters, $anchoredGeometry, $resolvedEntities, 'No hay conexion de OpenAI configurada.', $requestIntent)),
        'polygons'=>$drawPolygons ? $computedOpportunities : [],
        'pdf_requested'=>$pdfRequested,
        'web'=>[
            'enabled'=>true,
            'available'=>false,
            'summary'=>'La conexion a internet del asistente requiere OpenAI web_search, pero no hay claves validas configuradas en variables de entorno ni en el archivo externo seguro .openai_api_key. Se entrego respuesta local con la data del geovisor.',
        ],
    ]);
}

$context = [
    'aprendizaje_continuo'=>$memoryContext,
    'capas_visibles_y_cacheadas'=>chatLayerInventory(),
    'sets_con_disponibilidad'=>chatTopSets(10),
    'zonas_sugeridas_actuales'=>array_map('chatCompactCandidate', array_slice(is_array($input['opportunities'] ?? null) ? $input['opportunities'] : [], 0, 6)),
    'zonas_calculadas_para_dibujar'=>array_map('chatCompactCandidate', array_slice($computedOpportunities, 0, 8)),
    'entidades_mencionadas_resueltas'=>array_slice($resolvedEntities, 0, 5),
    'intencion_detectada'=>$requestIntent,
    'orden_de_geometria'=>[
        'anclada_a_entidad'=>$anchoredGeometry,
        'cantidad_solicitada'=>$requestedPolygonCount,
        'regla'=>'Cuando anclada_a_entidad es verdadero, las geometrías devueltas son la ejecución exacta del pedido y no deben sustituirse por oportunidades generales.',
    ],
    'filtros_interpretados_para_poligonos'=>$opportunityFilters,
    'ancla_geografica_del_poligono_actual'=>$polygonAnchor,
    'zona_evaluada_actual'=>is_array($input['zone'] ?? null) ? $input['zone'] : null,
    'poligono_dibujado_actual'=>is_array($input['current_polygon'] ?? null) ? array_slice($input['current_polygon'], 0, 160) : null,
    'estado_cliente_visor'=>chatCompactViewer($input['viewer'] ?? null),
    'criterios'=>[
        'No recomendar zonas superpuestas a concesiones o tramites cargados.',
        'Priorizar evidencia agricola MIDAGRI y acuicultura continental PRODUCE.',
        'Evitar zonas en mar o sin actividad productiva visible.',
        'Diferenciar concesion de distribucion si demanda sustentada es mayor a 500 kW y convenio municipal si es menor o igual a 500 kW.',
        'Indicar incertidumbres cuando falten datos de campo, demanda o validacion normativa.',
    ],
];

$conversation = "";
foreach ($cleanMessages as $msg) {
    $conversation .= strtoupper($msg['role']) . ': ' . $msg['content'] . "\n";
}

$model = trim((string)getenv('OPENAI_CHAT_MODEL'));
// GPT-5.4 mini es el modelo de mayor capacidad que el proyecto de API activo
// acepta actualmente. OPENAI_CHAT_MODEL permite subirlo sin tocar el codigo
// cuando la facturacion del proyecto habilite las familias superiores.
if ($model === '') $model = 'gpt-5.4-mini';
$reasoningEffort = strtolower(trim((string)getenv('OPENAI_CHAT_REASONING_EFFORT')));
if (!in_array($reasoningEffort, ['none','low','medium','high','xhigh','max'], true)) $reasoningEffort = 'medium';

$payload = [
    'model'=>$model,
    'reasoning'=>['effort'=>$reasoningEffort],
    'instructions'=>'Eres un consultor tecnico peruano en distribucion electrica, generacion electrica, GIS, agroindustria, acuicultura, predios y arqueologia. Responde en espanol, con criterio ejecutivo y conciso. No uses Markdown visible: nada de asteriscos, encabezados con #, tablas Markdown ni texto en negrita; escribe texto limpio con frases cortas. Si el usuario pregunta por tu acceso, indica que si tienes acceso indirecto mediante el contexto estructurado adjunto del geovisor: capas cacheadas, capas activas del cliente, SET con disponibilidad, zonas calculadas, filtros interpretados, poligono actual y vista del mapa. No digas que no puedes ver la plataforma; explica la limitacion exacta solo si falta un dato de campo o una capa no esta cargada. Usa esa data como base, declara incertidumbre si falta campo y, si tienes web_search disponible, puedes buscar informacion externa actual para complementar la respuesta. Para disponibilidad de transformadores usa potencia compleja P+jQ y condiciones simultaneas. Nunca sumes capacidades de devanados ni maximos de barras ocurridos en horarios distintos. disponible_tecnico_mva es un margen preliminar en la barra indicada; disponible_planificacion_mva es el valor prudente para propuestas. capacidad_firme_n_1_mva nulo significa que no existe capacidad firme demostrada y debe declararse pendiente de flujo de carga, topologia y contingencia N-1. Si filtros_interpretados_para_poligonos incluye target_center, respeta esa zona como orden geografica principal: no propongas ni expliques poligonos fuera de su departamento, region natural o radio indicado salvo que el usuario pida comparar alternativas nacionales. Prioriza zonas con cultivos, acuicultura continental o demanda productiva, cercanas a SET con disponibilidad, sin concesion/tramite ni mar. Diferencia Costa, Sierra y Selva. Analiza siempre generacion y distribucion juntas: distribucion desde SET disponible, generacion local, o esquema hibrido generacion local mas red de distribucion. Usa el campo alternativas_generacion si existe y compara solar, eolica, hidroelectrica, biomasa y termica/respaldo con prioridad, sustento y condicion tecnica pendiente. No reduzcas la recomendacion solo a biomasa salvo que el ranking lo justifique claramente. Usa aprendizaje_continuo como memoria local de criterios y correcciones previas: aplica criterios_aprendidos y consultas_relacionadas cuando sean pertinentes, sin inventar datos que no esten en capas o contexto. Si una consulta previa corrigio un formato, separacion de capas, plantilla o enfoque tecnico, manten esa preferencia en nuevas respuestas. Si se adjuntan zonas_calculadas_para_dibujar, explicalas como propuestas graficables en el mapa.',
    'input'=>"Contexto estructurado del geovisor:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\nConversacion:\n" . $conversation,
    'max_output_tokens'=>1600,
];
$payload['instructions'] .= "\n\nObjetivo especifico del chat territorial:\n"
    . "- Responde la solicitud concreta del ultimo mensaje; no la sustituyas por una plantilla ni repitas mecanicamente una respuesta anterior.\n"
    . "- Respeta intencion_detectada. Una orden de ubicar o localizar solo debe centrar e identificar la entidad; no debe convertirse en un analisis de oportunidades ni en generacion de poligonos.\n"
    . "- Usa entidades_mencionadas_resueltas para reconocer por nombre subestaciones, lineas, concesiones y otros elementos visibles. No pidas un codigo interno si el nombre ya fue resuelto.\n"
    . "- Si orden_de_geometria.anclada_a_entidad es verdadero, respeta exactamente la entidad, cantidad y relacion espacial pedidas. No agregues poligonos nacionales, oportunidades distintas ni alternativas no solicitadas.\n"
    . "- Si existe ancla_geografica_del_poligono_actual o poligono_dibujado_actual, usalos como referencia espacial primaria. Las propuestas deben quedar dentro o alrededor de ese ambito y no en otras regiones, salvo que el usuario lo pida.\n"
    . "- Cuando el usuario mencione un porcentaje, expansion, contorno o franja, explica brevemente como interpretaste esa proporcion respecto del poligono antes de recomendar.\n"
    . "- Distingue hechos calculados por el geovisor, inferencias tecnicas y datos pendientes de validar. No inventes disponibilidad, demanda, distancias, cultivos, concesiones ni coordenadas.\n"
    . "- Si zonas_calculadas_para_dibujar contiene alternativas, selecciona y compara las pertinentes al ambito actual; no enumeres zonas nacionales ajenas al pedido.\n"
    . "- Entrega una conclusion accionable, sustento electrico y territorial, riesgos o restricciones, y siguientes verificaciones. Conserva variedad solo cuando la evidencia cambie.";
if ($webEnabled) {
    $payload['tools'] = [[
        'type'=>'web_search',
        'user_location'=>[
            'type'=>'approximate',
            'country'=>'PE',
            'city'=>'Lima',
            'timezone'=>'America/Lima',
        ],
    ]];
    $payload['tool_choice'] = 'auto';
    $payload['include'] = ['web_search_call.action.sources'];
}

$body = false;
$err = '';
$code = 0;
$jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$keyOrder = [];
if ($key !== '') $keyOrder[] = $key;
foreach ($openAIKeys as $candidateKey) {
    $candidateKey = trim((string)$candidateKey);
    if ($candidateKey !== '' && !in_array($candidateKey, $keyOrder, true)) $keyOrder[] = $candidateKey;
}

$attempts = [];
foreach ($keyOrder as $idx => $attemptKey) {
    $body = false;
    $err = '';
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>80,
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
        $fallback = zPowerShellOpenAi($attemptKey, $jsonPayload);
        $body = $fallback['body'];
        $code = $fallback['code'];
        $err = $fallback['error'];
    }
    $attempts[] = [
        'clave'=>'key_' . ($idx + 1),
        'http_code'=>$code,
        'ok'=>($body !== false && $code >= 200 && $code < 300),
        'error'=>chatSafeText(chatRedactOpenAISecrets((string)$err, $openAIKeys), 180),
    ];
    if ($body !== false && $code >= 200 && $code < 300) break;
}

if ($body === false || $code < 200 || $code >= 300) {
    $fallbackReason = chatOpenAIUserNotice($code, is_string($body) ? $body : '', (string)$err);
    jsonOut([
        'ok'=>true,
        'model'=>'motor-gis-local',
        'requested_model'=>$model,
        'reasoning_effort'=>$reasoningEffort,
        'fallback'=>true,
        'intent'=>$requestIntent,
        'notice'=>$fallbackReason,
        'text'=>chatCleanOutput(chatLocalRequestAnswer($cleanMessages, $computedOpportunities, $opportunityFilters, $anchoredGeometry, $resolvedEntities, $fallbackReason, $requestIntent)),
        'polygons'=>$drawPolygons ? $computedOpportunities : [],
        'pdf_requested'=>$pdfRequested,
        'openai_attempts'=>$attempts,
        'web'=>[
            'enabled'=>$webEnabled,
            'available'=>false,
            'summary'=>$fallbackReason . ' Se entrego una respuesta local limitada a la intencion solicitada.',
            'http_code'=>$code,
        ],
    ]);
}

$data = json_decode((string)$body, true);
if (!is_array($data)) {
    jsonOut([
        'ok'=>true,
        'model'=>'motor-gis-local',
        'requested_model'=>$model,
        'reasoning_effort'=>$reasoningEffort,
        'fallback'=>true,
        'intent'=>$requestIntent,
        'text'=>chatCleanOutput(chatLocalRequestAnswer($cleanMessages, $computedOpportunities, $opportunityFilters, $anchoredGeometry, $resolvedEntities, 'OpenAI no devolvio una respuesta valida.', $requestIntent)),
        'polygons'=>$drawPolygons ? $computedOpportunities : [],
        'pdf_requested'=>$pdfRequested,
        'web'=>[
            'enabled'=>true,
            'available'=>false,
            'summary'=>'La busqueda web estaba activada mediante OpenAI, pero la respuesta no fue valida. Se entrego respuesta local con la data del geovisor.',
        ],
    ]);
}

$text = chatExtractText($data);
if ($text === '') $text = 'No se recibio texto util desde OpenAI.';
$text = chatCleanOutput(chatRedactOpenAISecrets($text, $openAIKeys));
$sources = chatExtractSources($data);
jsonOut([
    'ok'=>true,
    'model'=>$model,
    'reasoning_effort'=>$reasoningEffort,
    'intent'=>$requestIntent,
    'text'=>$text,
    'polygons'=>$drawPolygons ? $computedOpportunities : [],
    'pdf_requested'=>$pdfRequested,
    'sources'=>$sources,
    'web'=>[
        'enabled'=>$webEnabled,
        'available'=>true,
        'summary'=>$webEnabled ? 'Busqueda web habilitada mediante OpenAI web_search para complementar la data local cuando la consulta lo requiera.' : 'Respuesta sin busqueda web.',
    ],
]);

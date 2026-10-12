<?php
/**
 * api/temp_kml.php - Lee KML/KMZ/GeoJSON como geometria temporal de analisis.
 */
require_once dirname(__DIR__) . '/config.php';
@set_time_limit(180);
@ini_set('memory_limit', '512M');
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

header('Content-Type: application/json; charset=utf-8');

if (!isAuth()) jsonOut(['ok'=>false, 'message'=>'No autorizado'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok'=>false, 'message'=>'Método no permitido'], 405);
requireCsrf();
// KML/KMZ are analysis inputs only: never persist uploads on disk.
$persistUpload = false;
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

function tempKmlFail(string $message, int $code = 400): void {
    jsonOut(['ok'=>false, 'message'=>$message], $code);
}

function tempKmlUploadError(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el limite del servidor.',
        UPLOAD_ERR_PARTIAL => 'El archivo se recibio parcialmente. Vuelva a intentar.',
        UPLOAD_ERR_NO_FILE => 'Seleccione un archivo geografico.',
        UPLOAD_ERR_NO_TMP_DIR => 'PHP no tiene una carpeta temporal configurada.',
        UPLOAD_ERR_CANT_WRITE => 'PHP no pudo escribir el archivo temporal en disco.',
        UPLOAD_ERR_EXTENSION => 'Una extension de PHP detuvo la carga.',
        default => 'No se pudo recibir el archivo (codigo ' . $code . ').',
    };
}

function tempKmlSafeName(string $name, string $ext): string {
    $base = basename(str_replace('\\', '/', $name));
    $base = preg_replace('/[^a-zA-Z0-9._\- ]+/u', '_', $base) ?: '';
    $base = trim(preg_replace('/\s+/', ' ', $base) ?: '', " .\t\n\r\0\x0B");
    if ($base === '' || strtolower(pathinfo($base, PATHINFO_EXTENSION)) !== $ext) {
        $base = 'archivo_' . date('Ymd_His') . '.' . $ext;
    }
    return substr($base, 0, 180);
}

function tempKmlPersistUpload(string $tmp, string $name, string $ext, int $expectedSize): string {
    $storageDir = defined('KML_DIR') ? KML_DIR : dirname(__DIR__) . '/uploads/kml';
    if (!is_dir($storageDir) && !@mkdir($storageDir, 0750, true) && !is_dir($storageDir)) {
        tempKmlFail('El archivo se pudo analizar, pero no se pudo crear uploads/kml para guardarlo.', 500);
    }
    $safe = tempKmlSafeName($name, $ext);
    $stem = pathinfo($safe, PATHINFO_FILENAME);
    $suffix = '.' . $ext;
    $dest = $storageDir . '/' . $safe;
    if (is_file($dest)) {
        $stamp = date('Ymd_His');
        $seq = 0;
        do {
            $extra = $seq > 0 ? '_' . $seq : '';
            $dest = $storageDir . '/' . $stem . '_' . $stamp . $extra . $suffix;
            $seq++;
        } while (is_file($dest) && $seq < 1000);
    }
    $part = $dest . '.upload-' . bin2hex(random_bytes(4)) . '.tmp';
    $copied = @copy($tmp, $part);
    $sizeOk = $copied && is_file($part) && (int)@filesize($part) === $expectedSize;
    if (!$sizeOk || !@rename($part, $dest)) {
        @unlink($part);
        tempKmlFail('El archivo se pudo analizar, pero no se pudo guardar en uploads/kml.', 500);
    }
    return basename($dest);
}

function tempKmlCoordinates(string $text): array {
    $points = [];
    $chunks = preg_split('/\s+/', trim($text));
    foreach ($chunks ?: [] as $chunk) {
        if ($chunk === '') continue;
        $parts = array_map('trim', explode(',', $chunk));
        if (count($parts) < 2) continue;
        $lng = (float)$parts[0];
        $lat = (float)$parts[1];
        if (!is_finite($lat) || !is_finite($lng)) continue;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
        $points[] = [$lat, $lng];
        if (count($points) >= 6000) break;
    }
    if (count($points) > 1) {
        $first = $points[0];
        $last = $points[count($points) - 1];
        if (abs($first[0] - $last[0]) < 1e-10 && abs($first[1] - $last[1]) < 1e-10) array_pop($points);
    }
    return $points;
}

function tempGeoJsonPoint(mixed $coordinate): ?array {
    if (!is_array($coordinate) || count($coordinate) < 2 || !is_numeric($coordinate[0]) || !is_numeric($coordinate[1])) return null;
    $lng = (float)$coordinate[0];
    $lat = (float)$coordinate[1];
    if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
    return [$lat, $lng];
}

function tempGeoJsonPoints(mixed $coordinates, bool $removeClosure = false): array {
    if (!is_array($coordinates)) return [];
    $points = [];
    foreach ($coordinates as $coordinate) {
        $point = tempGeoJsonPoint($coordinate);
        if ($point) $points[] = $point;
        if (count($points) >= 6000) break;
    }
    if ($removeClosure && count($points) > 1 && $points[0] === $points[count($points) - 1]) array_pop($points);
    return $points;
}

function tempGeoJsonAddGeometry(array &$features, mixed $geometry, string $name, array $properties, string $sourceName, int &$seq): void {
    if (!is_array($geometry) || count($features) >= 250) return;
    $type = (string)($geometry['type'] ?? '');
    $coordinates = $geometry['coordinates'] ?? null;
    $add = static function(string $geometryType, array $points, string $suffix = '') use (&$features, $name, $properties, $sourceName, &$seq): void {
        if (count($features) >= 250 || !$points) return;
        $minimum = $geometryType === 'Polygon' ? 3 : ($geometryType === 'LineString' ? 2 : 1);
        if (count($points) < $minimum) return;
        $features[] = [
            'id'=>'temp-' . (++$seq),
            'name'=>$name . $suffix,
            'geometry'=>['type'=>$geometryType, 'coordinates'=>$geometryType === 'Point' ? [$points[0]] : $points],
            'properties'=>array_merge($properties, ['source_file'=>$sourceName]),
        ];
    };

    if ($type === 'Point') {
        $point = tempGeoJsonPoint($coordinates);
        if ($point) $add('Point', [$point]);
    } elseif ($type === 'MultiPoint') {
        foreach ($coordinates ?: [] as $idx => $coordinate) {
            $point = tempGeoJsonPoint($coordinate);
            if ($point) $add('Point', [$point], ' ' . ($idx + 1));
        }
    } elseif ($type === 'LineString') {
        $add('LineString', tempGeoJsonPoints($coordinates));
    } elseif ($type === 'MultiLineString') {
        foreach ($coordinates ?: [] as $idx => $line) $add('LineString', tempGeoJsonPoints($line), ' ' . ($idx + 1));
    } elseif ($type === 'Polygon') {
        $add('Polygon', tempGeoJsonPoints($coordinates[0] ?? [], true));
    } elseif ($type === 'MultiPolygon') {
        foreach ($coordinates ?: [] as $idx => $polygon) $add('Polygon', tempGeoJsonPoints($polygon[0] ?? [], true), ' ' . ($idx + 1));
    } elseif ($type === 'GeometryCollection') {
        foreach (($geometry['geometries'] ?? []) as $idx => $item) {
            tempGeoJsonAddGeometry($features, $item, $name . ' ' . ($idx + 1), $properties, $sourceName, $seq);
        }
    }
}

function tempGeoJsonParse(string $json, string $sourceName, ?array &$meta = null): array {
    if (str_starts_with($json, "\xEF\xBB\xBF")) $json = substr($json, 3);
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        tempKmlFail('GeoJSON invalido: ' . $e->getMessage() . '.');
    }
    if (!is_array($data)) tempKmlFail('El archivo no contiene una estructura GeoJSON valida.');
    $type = (string)($data['type'] ?? '');
    if ($type === 'FeatureCollection') {
        $items = is_array($data['features'] ?? null) ? $data['features'] : [];
    } elseif ($type === 'Feature') {
        $items = [$data];
    } elseif (isset($data['coordinates']) || $type === 'GeometryCollection') {
        $items = [['type'=>'Feature', 'properties'=>[], 'geometry'=>$data]];
    } elseif (array_is_list($data)) {
        $items = $data;
    } else {
        tempKmlFail('Use un FeatureCollection, Feature o geometria GeoJSON.');
    }

    $features = [];
    $seq = 0;
    $total = count($items);
    foreach ($items as $idx => $item) {
        if (count($features) >= 250) break;
        if (!is_array($item)) continue;
        $properties = is_array($item['properties'] ?? null) ? $item['properties'] : [];
        $name = trim((string)($properties['name'] ?? $properties['nombre'] ?? $properties['NOMBRE'] ?? $properties['Name'] ?? ''));
        if ($name === '') $name = 'Elemento temporal ' . ($idx + 1);
        $geometry = (($item['type'] ?? '') === 'Feature') ? ($item['geometry'] ?? null) : ($item['geometry'] ?? $item);
        tempGeoJsonAddGeometry($features, $geometry, $name, $properties, $sourceName, $seq);
    }
    if (!$features) tempKmlFail('No se encontraron puntos, lineas o poligonos con coordenadas WGS84 validas (EPSG:4326).');
    $meta = [
        'total_features'=>$total,
        'total_placemarks'=>$total,
        'returned_features'=>count($features),
        'feature_limit'=>250,
        'truncated'=>$total > count($features) || count($features) >= 250,
        'parser'=>'geojson',
    ];
    return $features;
}

function tempKmlTrackCoordinates(DOMXPath $xp, DOMNode $track): array {
    $points = [];
    foreach ($xp->query('.//*[local-name()="coord"]', $track) as $coord) {
        $parts = preg_split('/\s+/', trim((string)$coord->textContent));
        if (count($parts ?: []) < 2) continue;
        $lng = (float)$parts[0];
        $lat = (float)$parts[1];
        if (!is_finite($lat) || !is_finite($lng)) continue;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
        $points[] = [$lat, $lng];
        if (count($points) >= 6000) break;
    }
    return $points;
}

function tempKmlNodeText(DOMXPath $xp, DOMNode $ctx, string $query): string {
    $node = $xp->query($query, $ctx)->item(0);
    return $node ? trim((string)$node->textContent) : '';
}

function tempKmlFolderPath(DOMXPath $xp, DOMNode $placemark): string {
    $parts = [];
    $node = $placemark->parentNode;
    while ($node instanceof DOMNode) {
        $local = strtolower((string)($node->localName ?? $node->nodeName));
        if (in_array($local, ['folder','document'], true)) {
            $name = tempKmlNodeText($xp, $node, './*[local-name()="name"]');
            if ($name !== '') array_unshift($parts, $name);
        }
        $node = $node->parentNode;
    }
    return implode(' / ', array_values(array_unique($parts)));
}

function tempKmlPlacemarkProperties(DOMXPath $xp, DOMNode $placemark, string $sourceName): array {
    $folder = tempKmlFolderPath($xp, $placemark);
    $properties = ['source_file'=>$sourceName, 'folder_path'=>$folder !== '' ? $folder : 'Sin carpeta'];
    $description = tempKmlNodeText($xp, $placemark, './*[local-name()="description"]');
    $styleUrl = tempKmlNodeText($xp, $placemark, './*[local-name()="styleUrl"]');
    $visibility = tempKmlNodeText($xp, $placemark, './*[local-name()="visibility"]');
    if ($description !== '') $properties['description'] = substr(strip_tags($description), 0, 1200);
    if ($styleUrl !== '') $properties['style_url'] = substr($styleUrl, 0, 240);
    if ($visibility !== '') $properties['visibility'] = $visibility === '0' ? '0' : '1';
    foreach ($xp->query('./*[local-name()="ExtendedData"]//*[local-name()="Data"]', $placemark) as $dataNode) {
        if (!$dataNode instanceof DOMElement || count($properties) >= 35) break;
        $key = preg_replace('/[^a-zA-Z0-9_ .\-]/u', '_', (string)$dataNode->getAttribute('name')) ?: '';
        $value = tempKmlNodeText($xp, $dataNode, './*[local-name()="value"]');
        if ($key !== '' && $value !== '') $properties[substr($key, 0, 80)] = substr($value, 0, 500);
    }
    return $properties;
}

function tempKmlParse(string $xml, string $sourceName, ?array &$meta = null): array {
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) tempKmlFail('No se pudo leer el contenido KML.');

    $xp = new DOMXPath($doc);
    $placemarks = $xp->query('//*[local-name()="Placemark"]');
    $features = [];
    $seq = 0;
    $limit = 250;
    $processedPlacemarks = 0;
    $hitLimit = false;
    foreach ($placemarks as $placemark) {
        if (!$placemark instanceof DOMNode) continue;
        $processedPlacemarks++;
        $name = tempKmlNodeText($xp, $placemark, './*[local-name()="name"]');
        if ($name === '') $name = 'Elemento temporal ' . (count($features) + 1);
        $placemarkProperties = tempKmlPlacemarkProperties($xp, $placemark, $sourceName);

        foreach ($xp->query('.//*[local-name()="Polygon"]', $placemark) as $poly) {
            $coords = tempKmlNodeText($xp, $poly, './/*[local-name()="outerBoundaryIs"]//*[local-name()="coordinates"]');
            if ($coords === '') $coords = tempKmlNodeText($xp, $poly, './/*[local-name()="coordinates"]');
            $points = tempKmlCoordinates($coords);
            if (count($points) >= 3) {
                $features[] = [
                    'id'=>'temp-' . (++$seq),
                    'name'=>$name,
                    'geometry'=>['type'=>'Polygon', 'coordinates'=>$points],
                    'properties'=>$placemarkProperties,
                ];
            }
        }

        foreach ($xp->query('.//*[local-name()="LineString"]', $placemark) as $line) {
            $points = tempKmlCoordinates(tempKmlNodeText($xp, $line, './/*[local-name()="coordinates"]'));
            if (count($points) >= 2) {
                $features[] = [
                    'id'=>'temp-' . (++$seq),
                    'name'=>$name,
                    'geometry'=>['type'=>'LineString', 'coordinates'=>$points],
                    'properties'=>$placemarkProperties,
                ];
            }
        }

        foreach ($xp->query('.//*[local-name()="Track"]', $placemark) as $track) {
            $points = tempKmlTrackCoordinates($xp, $track);
            if (count($points) >= 2) {
                $features[] = [
                    'id'=>'temp-' . (++$seq),
                    'name'=>$name,
                    'geometry'=>['type'=>'LineString', 'coordinates'=>$points],
                    'properties'=>array_merge($placemarkProperties, ['source_geometry'=>'gx:Track']),
                ];
            }
        }

        foreach ($xp->query('.//*[local-name()="Point"]', $placemark) as $point) {
            $points = tempKmlCoordinates(tempKmlNodeText($xp, $point, './/*[local-name()="coordinates"]'));
            if (count($points) >= 1) {
                $features[] = [
                    'id'=>'temp-' . (++$seq),
                    'name'=>$name,
                    'geometry'=>['type'=>'Point', 'coordinates'=>[$points[0]]],
                    'properties'=>$placemarkProperties,
                ];
            }
        }

        if (count($features) >= $limit) {
            $hitLimit = true;
            break;
        }
    }
    if (!$features) tempKmlFail('No se encontraron polígonos, líneas o puntos en el archivo.');
    $totalPlacemarks = $placemarks instanceof DOMNodeList ? $placemarks->length : $processedPlacemarks;
    $meta = [
        'total_placemarks'=>$totalPlacemarks,
        'returned_features'=>min(count($features), $limit),
        'feature_limit'=>$limit,
        'truncated'=>$hitLimit || $processedPlacemarks < $totalPlacemarks || count($features) > $limit,
    ];
    if (count($features) > $limit) $features = array_slice($features, 0, $limit);
    return $features;
}

function tempKmlParseFast(string $xml, string $sourceName, ?array &$meta = null): array {
    if (!class_exists('XMLReader')) return tempKmlParse($xml, $sourceName, $meta);
    $prev = libxml_use_internal_errors(true);
    $reader = new XMLReader();
    $ok = $reader->XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);
    if (!$ok) {
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return tempKmlParse($xml, $sourceName, $meta);
    }
    $total = substr_count($xml, '<Placemark');
    if ($total <= 0) {
        $counted = preg_match_all('/<(?:[A-Za-z0-9_-]+:)?Placemark\\b/i', $xml, $unused);
        $total = is_int($counted) ? $counted : 0;
    }
    $collected = 0;
    $sampleBytes = 0;
    $fragments = [];
    $samplePlacemarkLimit = 400;
    $sampleByteLimit = 8 * 1024 * 1024;
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || strcasecmp($reader->localName, 'Placemark') !== 0) continue;
        if ($collected >= $samplePlacemarkLimit || $sampleBytes >= $sampleByteLimit) break;
        $fragment = $reader->readOuterXML();
        if (!is_string($fragment) || trim($fragment) === '') continue;
        $fragments[] = $fragment;
        $collected++;
        $sampleBytes += strlen($fragment);
    }
    $reader->close();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$fragments) return tempKmlParse($xml, $sourceName, $meta);
    if ($total <= 0) $total = $collected;

    $sampleXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:gx="http://www.google.com/kml/ext/2.2"><Document>'
        . implode('', $fragments)
        . '</Document></kml>';
    $sampleMeta = [];
    $features = tempKmlParse($sampleXml, $sourceName, $sampleMeta);
    if (count($features) < 250 && $total > $collected) {
        return tempKmlParse($xml, $sourceName, $meta);
    }
    $meta = [
        'total_placemarks'=>$total,
        'returned_features'=>count($features),
        'feature_limit'=>250,
        'truncated'=>$total > $collected || !empty($sampleMeta['truncated']),
        'parser'=>'stream',
    ];
    return $features;
}

function tempKmlInflate(string $bytes, int $method): string {
    if ($method === 0) return $bytes;
    if ($method === 8) {
        $out = @gzinflate($bytes);
        if (is_string($out)) return $out;
    }
    tempKmlFail('El KMZ usa un método de compresión no soportado por este servidor.', 500);
}

function tempKmlFromZipBytes(string $zipBytes): string {
    $len = strlen($zipBytes);
    $min = max(0, $len - 66000);
    $eocd = -1;
    for ($i = $len - 22; $i >= $min; $i--) {
        if (substr($zipBytes, $i, 4) === "PK\x05\x06") { $eocd = $i; break; }
    }
    if ($eocd < 0 || $eocd + 22 > $len) tempKmlFail('No se pudo leer la estructura del KMZ.');
    $end = unpack('vdisk/vcdDisk/vdiskEntries/ventries/VcdSize/VcdOffset/vcommentLen', substr($zipBytes, $eocd + 4, 18));
    $pos = (int)($end['cdOffset'] ?? 0);
    $entries = (int)($end['entries'] ?? 0);
    for ($i = 0; $i < $entries && $pos + 46 <= $len; $i++) {
        if (substr($zipBytes, $pos, 4) !== "PK\x01\x02") break;
        $h = unpack('vverMade/vverNeed/vflags/vmethod/vtime/vdate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen/vcommentLen/vdisk/vinternal/Vexternal/VlocalOffset', substr($zipBytes, $pos + 4, 42));
        $nameLen = (int)($h['nameLen'] ?? 0);
        $extraLen = (int)($h['extraLen'] ?? 0);
        $commentLen = (int)($h['commentLen'] ?? 0);
        $entryName = substr($zipBytes, $pos + 46, $nameLen);
        $pos += 46 + $nameLen + $extraLen + $commentLen;
        if (strtolower(pathinfo($entryName, PATHINFO_EXTENSION)) !== 'kml') continue;

        $local = (int)($h['localOffset'] ?? -1);
        if ($local < 0 || $local + 30 > $len || substr($zipBytes, $local, 4) !== "PK\x03\x04") continue;
        $lh = unpack('vver/vflags/vmethod/vtime/vdate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen', substr($zipBytes, $local + 4, 26));
        $start = $local + 30 + (int)($lh['nameLen'] ?? 0) + (int)($lh['extraLen'] ?? 0);
        $compSize = (int)($h['compSize'] ?? 0);
        if ($compSize <= 0 || $start + $compSize > $len) continue;
        return tempKmlInflate(substr($zipBytes, $start, $compSize), (int)($h['method'] ?? 0));
    }
    tempKmlFail('El KMZ no contiene un archivo KML legible.');
}

function tempKmlReadKmz(string $path): string {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) tempKmlFail('No se pudo abrir el KMZ.');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'kml') continue;
            $content = $zip->getFromIndex($i);
            if (is_string($content) && trim($content) !== '') {
                $zip->close();
                return $content;
            }
        }
        $zip->close();
        tempKmlFail('El KMZ no contiene un archivo KML legible.');
    }
    $bytes = (string)file_get_contents($path);
    return tempKmlFromZipBytes($bytes);
}

function tempKmlReadKmzDocuments(string $path, string $archiveName): array {
    if (!class_exists('ZipArchive')) {
        return [['name'=>$archiveName . ' / doc.kml', 'entry'=>'doc.kml', 'content'=>tempKmlReadKmz($path)]];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) tempKmlFail('No se pudo abrir el KMZ.');
    $documents = [];
    $totalUncompressed = 0;
    $maxDocuments = 30;
    $maxUncompressed = 64 * 1024 * 1024;
    for ($i = 0; $i < $zip->numFiles && count($documents) < $maxDocuments; $i++) {
        $entry = str_replace('\\', '/', (string)$zip->getNameIndex($i));
        if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'kml') continue;
        $stat = $zip->statIndex($i);
        $size = is_array($stat) ? (int)($stat['size'] ?? 0) : 0;
        if ($size <= 0 || $size > 32 * 1024 * 1024 || $totalUncompressed + $size > $maxUncompressed) continue;
        $content = $zip->getFromIndex($i);
        if (!is_string($content) || trim($content) === '') continue;
        $totalUncompressed += strlen($content);
        $safeEntry = preg_replace('~(^|/)\.\.(/|$)~', '/', $entry) ?: basename($entry);
        $documents[] = [
            'name'=>$archiveName . ' / ' . $safeEntry,
            'entry'=>$safeEntry,
            'content'=>$content,
        ];
    }
    $zip->close();
    if (!$documents) tempKmlFail('El KMZ no contiene documentos KML legibles dentro de los limites seguros.');
    return $documents;
}

if (empty($_FILES['kml_file']) || !is_array($_FILES['kml_file'])) tempKmlFail('Seleccione un archivo KML, KMZ, GeoJSON o JSON.');
$file = $_FILES['kml_file'];
$uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) tempKmlFail(tempKmlUploadError($uploadError));
if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 40 * 1024 * 1024) tempKmlFail('El archivo debe pesar hasta 40 MB.');
$name = (string)($file['name'] ?? 'temporal.kml');
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['kml', 'kmz', 'geojson', 'json'], true)) tempKmlFail('Formato no permitido. Use KML, KMZ, GeoJSON o JSON.');

$tmp = (string)($file['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) tempKmlFail('Archivo temporal inválido.');

$parseMeta = [];
$features = [];
if (in_array($ext, ['geojson', 'json'], true)) {
    $json = (string)file_get_contents($tmp);
    if (trim($json) === '') tempKmlFail('El archivo GeoJSON esta vacio.');
    $features = tempGeoJsonParse($json, $name, $parseMeta);
} else {
    $documents = $ext === 'kml'
        ? [['name'=>$name, 'entry'=>$name, 'content'=>(string)file_get_contents($tmp)]]
        : tempKmlReadKmzDocuments($tmp, $name);
    $documentMeta = [];
    $totalPlacemarks = 0;
    $truncated = false;
    $globalSeq = 0;
    foreach ($documents as $document) {
        if (count($features) >= 250) { $truncated = true; break; }
        $xml = (string)($document['content'] ?? '');
        if (trim($xml) === '') continue;
        if (!preg_match('/<(?:[A-Za-z0-9_-]+:)?(?:Point|LineString|Polygon|Track)\b/i', $xml)) {
            $documentMeta[] = ['name'=>(string)($document['entry'] ?? $document['name']), 'features'=>0];
            continue;
        }
        $docMeta = [];
        $docFeatures = strlen($xml) <= 12 * 1024 * 1024
            ? tempKmlParse($xml, (string)$document['name'], $docMeta)
            : tempKmlParseFast($xml, (string)$document['name'], $docMeta);
        $returned = 0;
        foreach ($docFeatures as $feature) {
            if (count($features) >= 250) { $truncated = true; break; }
            $feature['id'] = 'temp-' . (++$globalSeq);
            $feature['properties']['document'] = (string)($document['entry'] ?? $document['name']);
            $features[] = $feature;
            $returned++;
        }
        $totalPlacemarks += (int)($docMeta['total_placemarks'] ?? count($docFeatures));
        $truncated = $truncated || !empty($docMeta['truncated']);
        $documentMeta[] = ['name'=>(string)($document['entry'] ?? $document['name']), 'features'=>$returned];
    }
    if (!$features) tempKmlFail('El archivo no contiene un KML legible con geometria compatible.');
    $parseMeta = [
        'total_placemarks'=>$totalPlacemarks,
        'returned_features'=>count($features),
        'feature_limit'=>250,
        'truncated'=>$truncated || $totalPlacemarks > count($features),
        'parser'=>$ext === 'kmz' ? 'kmz-multiple' : 'kml',
        'kml_documents'=>count($documentMeta),
        'documents'=>$documentMeta,
    ];
}
$storedName = null;
if ($persistUpload) {
    $storedName = tempKmlPersistUpload($tmp, $name, $ext, (int)$file['size']);
}
jsonOut([
    'ok'=>true,
    'file'=>$name,
    'saved'=>$storedName !== null,
    'saved_name'=>$storedName,
    'saved_path'=>$storedName !== null ? 'uploads/kml/' . $storedName : null,
    'features'=>$features,
] + $parseMeta);

<?php
/**
 * upload.php — Panel de Administración CVC
 * Solo administradores: gestión de Excel, GeoJSON, PDF unifilar y APIs.
 */
require_once __DIR__ . '/config.php';
requireAdmin();

$msgs = [];
$tab = $_GET['tab'] ?? 'excel';
$username = $_SESSION['username'] ?? 'admin';
$capas = getLocalLayerRegistry(false);
$layerGroups = knownLayerGroups();
$layerMenus = knownLayerMenus();

function iniSizeToBytes(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;
    $unit = strtolower(substr($value, -1));
    $num = (float)$value;
    return match ($unit) {
        'g' => (int)($num * 1024 * 1024 * 1024),
        'm' => (int)($num * 1024 * 1024),
        'k' => (int)($num * 1024),
        default => (int)$num,
    };
}
function uploadPostLimitExceeded(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = iniSizeToBytes((string)ini_get('post_max_size'));
    return $postMax > 0 && $contentLength > $postMax;
}
function uploadErrorMessage(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el limite permitido por PHP. Reinicie el servidor local desde VS Code y vuelva a intentar.',
        UPLOAD_ERR_PARTIAL => 'El archivo se subio parcialmente. Vuelva a intentar la carga.',
        UPLOAD_ERR_NO_FILE => 'No se selecciono ningun archivo.',
        UPLOAD_ERR_NO_TMP_DIR => 'No existe carpeta temporal para la subida. Revise la configuracion de PHP.',
        UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en disco. Revise permisos de la carpeta.',
        UPLOAD_ERR_EXTENSION => 'Una extension de PHP detuvo la subida del archivo.',
        default => 'No se pudo subir el archivo. Codigo de error: ' . $code,
    };
}

function uploadGeoJsonType(array $data): ?string {
    $geometry = null;
    $type = (string)($data['type'] ?? '');
    if ($type === 'FeatureCollection') {
        $first = $data['features'][0] ?? null;
        $geometry = is_array($first) ? ($first['geometry'] ?? null) : null;
    } elseif ($type === 'Feature') {
        $geometry = $data['geometry'] ?? null;
    } elseif (isset($data['coordinates']) || $type === 'GeometryCollection') {
        $geometry = $data;
    } elseif (array_is_list($data)) {
        $first = $data[0] ?? null;
        $geometry = is_array($first) ? ($first['geometry'] ?? $first) : null;
    }
    if (!is_array($geometry)) return null;
    $geometryType = (string)($geometry['type'] ?? '');
    if (in_array($geometryType, ['Point', 'MultiPoint'], true)) return 'point';
    if (in_array($geometryType, ['LineString', 'MultiLineString'], true)) return 'line';
    if (in_array($geometryType, ['Polygon', 'MultiPolygon'], true)) return 'polygon';
    if ($geometryType === 'GeometryCollection') {
        foreach (($geometry['geometries'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $detected = uploadGeoJsonType($item);
            if ($detected) return $detected;
        }
    }
    return null;
}

function uploadGeoJsonLooksValid(array $data): bool {
    $type = (string)($data['type'] ?? '');
    if ($type === 'FeatureCollection') return isset($data['features']) && is_array($data['features']);
    if ($type === 'Feature') return isset($data['geometry']) && (is_array($data['geometry']) || $data['geometry'] === null);
    if (in_array($type, ['Point','MultiPoint','LineString','MultiLineString','Polygon','MultiPolygon','GeometryCollection'], true)) return true;
    if (!array_is_list($data)) return false;
    foreach (array_slice($data, 0, 5) as $item) {
        if (!is_array($item) || (($item['type'] ?? '') !== 'Feature' && !isset($item['geometry']))) return false;
    }
    return $data !== [];
}

function storeUploadedFile(string $source, string $dest, bool $stripUtf8Bom, ?string &$error = null): bool {
    $error = null;
    $sourceSize = (int)@filesize($source);
    if ($sourceSize <= 0) {
        $error = 'El archivo temporal esta vacio o ya no esta disponible.';
        return false;
    }
    ensureDir(dirname($dest));
    try {
        $token = bin2hex(random_bytes(5));
    } catch (Throwable $e) {
        $token = str_replace('.', '', uniqid('', true));
    }
    $part = $dest . '.upload-' . $token . '.tmp';
    $bomRemoved = false;

    if ($stripUtf8Bom) {
        $in = @fopen($source, 'rb');
        $out = @fopen($part, 'xb');
        if (!$in || !$out) {
            if (is_resource($in)) fclose($in);
            if (is_resource($out)) fclose($out);
            @unlink($part);
            $error = 'No se pudo preparar el archivo para guardarlo.';
            return false;
        }
        $prefix = (string)fread($in, 3);
        if ($prefix === "\xEF\xBB\xBF") {
            $bomRemoved = true;
        } else {
            fwrite($out, $prefix);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    } elseif (!@move_uploaded_file($source, $part) && !@copy($source, $part)) {
        @unlink($part);
        $error = 'PHP no pudo mover el archivo desde su carpeta temporal.';
        return false;
    }

    $expectedSize = $sourceSize - ($bomRemoved ? 3 : 0);
    if (!is_file($part) || (int)@filesize($part) !== $expectedSize) {
        @unlink($part);
        $error = 'La copia quedo incompleta; el archivo anterior se conservo.';
        return false;
    }

    $backup = null;
    if (is_file($dest)) {
        $backup = $dest . '.backup-' . $token;
        if (!@rename($dest, $backup)) {
            @unlink($part);
            $error = 'El archivo actual esta abierto o bloqueado por OneDrive. Cierrelo y vuelva a intentar.';
            return false;
        }
    }
    if (!@rename($part, $dest)) {
        @unlink($part);
        if ($backup && is_file($backup)) @rename($backup, $dest);
        $error = 'No se pudo activar el archivo nuevo; el archivo anterior fue restaurado.';
        return false;
    }
    if ($backup && is_file($backup)) @unlink($backup);
    return true;
}

$postLimitExceeded = uploadPostLimitExceeded();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($postLimitExceeded) {
        $msgs[] = [
            't'=>'er',
            'm'=>'El archivo enviado pesa ' . formatBytes((int)($_SERVER['CONTENT_LENGTH'] ?? 0)) . ', pero el limite actual de POST es ' . ini_get('post_max_size') . '. Reinicie el debug de VS Code para aplicar los nuevos limites y vuelva a intentar.',
        ];
    } else {
        requireCsrf();
    }
}
$isMutatingGet = isset($_GET['del_kind'], $_GET['file']) || isset($_GET['del_layer_id']) || isset($_GET['del_api_id']) || isset($_GET['restore_api_id']);
if ($isMutatingGet && !verifyCsrfToken((string)($_GET['_csrf'] ?? ''))) {
    http_response_code(403);
    echo 'Token de seguridad inválido. Recargue el panel e intente nuevamente.';
    exit;
}

function ensureDir(string $dir): void { if (!is_dir($dir)) mkdir($dir, 0755, true); }
function safeName(string $name): string { return preg_replace('/[^a-zA-Z0-9._\-\sÁÉÍÓÚáéíóúÑñ]/u', '_', basename($name)); }
function safeExtractZip(string $zipPath, string $destDir, array $allowedExts = ['pdf']): bool {
    if (!class_exists('ZipArchive')) return false;
    ensureDir($destDir);
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return false;
    $base = realpath($destDir);
    if (!$base) { $zip->close(); return false; }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = (string)$zip->getNameIndex($i);
        $name = str_replace('\\', '/', $entry);
        if ($name === '' || str_ends_with($name, '/') || str_contains($name, '../') || str_starts_with($name, '/')) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) continue;
        $parts = array_map('safeName', explode('/', $name));
        $target = $base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        $targetDir = dirname($target);
        if (!is_dir($targetDir)) mkdir($targetDir, 0750, true);
        $stream = $zip->getStream($entry);
        if (!$stream) continue;
        $out = fopen($target, 'wb');
        if ($out) {
            stream_copy_to_stream($stream, $out, 300 * 1024 * 1024);
            fclose($out);
        }
        fclose($stream);
    }
    $zip->close();
    return true;
}
function uploadAscii(string $value): string {
    $value = html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii)) $value = $ascii;
    }
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value));
}
function validateUploadedData(string $kind, string $path, string $ext, ?array &$meta = null): ?string {
    $meta = [];
    if (!is_file($path) || filesize($path) <= 0) return 'El archivo esta vacio o no pudo leerse.';

    if (in_array($ext, ['xlsx', 'xlsm', 'docx'], true)) {
        $head = (string)file_get_contents($path, false, null, 0, 4);
        if (!str_starts_with($head, "PK")) return 'El archivo Office esta danado o no corresponde a su extension.';
        if (!class_exists('ZipArchive')) {
            if ($ext === 'docx') return null;
            $validator = ANALYSIS_DIR . '/validate_data_upload.py';
            if (!is_file($validator) || !function_exists('shell_exec')) return null;
            $cmd = escapeshellarg(PYTHON_BIN) . ' ' . escapeshellarg($validator)
                . ' --path ' . escapeshellarg($path)
                . ' --kind ' . escapeshellarg($kind) . ' 2>&1';
            $out = @shell_exec($cmd);
            $result = $out ? json_decode(trim($out), true) : null;
            if (!is_array($result) || !array_key_exists('ok', $result)) {
                return 'No se pudo validar la estructura del Excel antes de reemplazar el archivo actual.';
            }
            return !empty($result['ok']) ? null : (string)($result['message'] ?? 'El Excel no es valido.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return 'No se pudo abrir el archivo Office. Verifique que no este danado.';
        $requiredPart = $ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
        $content = $zip->getFromName($requiredPart);
        $zip->close();
        if (!is_string($content) || $content === '') return 'El archivo no contiene una estructura Office valida.';

        if ($kind === 'excel') {
            preg_match_all('/<sheet\b[^>]*\bname="([^"]+)"/i', $content, $matches);
            $sheets = array_map('uploadAscii', $matches[1] ?? []);
            foreach (['subestacion', 'transformador', 'barra'] as $required) {
                $found = false;
                foreach ($sheets as $sheet) {
                    if (str_contains($sheet, $required)) { $found = true; break; }
                }
                if (!$found) return 'El Excel maestro no contiene la hoja requerida: ' . ucfirst($required) . '.';
            }
        }
        return null;
    }

    if ($ext === 'xls') {
        $head = (string)file_get_contents($path, false, null, 0, 8);
        return $head === hex2bin('D0CF11E0A1B11AE1') ? null : 'El archivo XLS esta danado o no corresponde a su extension.';
    }
    if ($ext === 'pdf') {
        $head = (string)file_get_contents($path, false, null, 0, 5);
        return $head === '%PDF-' ? null : 'El archivo no es un PDF valido.';
    }
    if (in_array($ext, ['geojson', 'json'], true)) {
        $size = (int)filesize($path);
        $head = (string)file_get_contents($path, false, null, 0, min($size, 4096));
        $hasBom = str_starts_with($head, "\xEF\xBB\xBF");
        if ($hasBom) $head = substr($head, 3);
        $first = substr(ltrim($head), 0, 1);
        if (!in_array($first, ['{', '['], true)) return 'El archivo no contiene JSON/GeoJSON valido.';
        $meta['strip_bom'] = $hasBom;

        // GeoJSON muy grandes se comprueban sin construir un segundo arbol
        // completo en memoria; el visor los sirve por streaming/gzip.
        if ($size > 64 * 1024 * 1024) {
            $tail = (string)file_get_contents($path, false, null, max(0, $size - 4096));
            $last = substr(rtrim($tail), -1);
            return in_array($last, ['}', ']'], true)
                ? null
                : 'El archivo GeoJSON parece estar incompleto o truncado.';
        }

        $json = (string)file_get_contents($path);
        if ($hasBom) $json = substr($json, 3);
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return 'JSON invalido: ' . $e->getMessage() . '.';
        }
        if (!is_array($data) || !uploadGeoJsonLooksValid($data)) {
            return 'El JSON no contiene una estructura GeoJSON reconocible (FeatureCollection, Feature o geometria).';
        }
        $meta['tipo'] = uploadGeoJsonType($data);
        $meta['feature_count'] = (($data['type'] ?? '') === 'FeatureCollection' && is_array($data['features'] ?? null))
            ? count($data['features'])
            : (array_is_list($data) ? count($data) : 1);
        if (($meta['feature_count'] ?? 0) < 1) return 'El GeoJSON no contiene elementos para mostrar.';
        return null;
    }
    return null;
}
function listFiles(string $dir, array $exts): array {
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f === 'manifest.json') continue;
        $path = $dir.'/'.$f;
        if (!is_file($path)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) continue;
        $out[] = ['name'=>$f, 'path'=>$path, 'size'=>filesize($path), 'date'=>date('Y-m-d H:i:s', filemtime($path))];
    }
    usort($out, fn($a,$b) => strcmp($a['name'], $b['name']));
    return $out;
}
function listFilesRecursive(string $dir, array $exts): array {
    if (!is_dir($dir)) return [];
    $out = [];
    $root = realpath($dir);
    if (!$root) return [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $entry) {
        if (!$entry->isFile()) continue;
        $ext = strtolower(pathinfo($entry->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) continue;
        $path = $entry->getPathname();
        $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
        $out[] = ['name'=>$rel, 'path'=>$path, 'size'=>filesize($path), 'date'=>date('Y-m-d H:i:s', filemtime($path))];
    }
    usort($out, fn($a,$b) => strcmp($a['name'], $b['name']));
    return $out;
}
function listLayerFiles(): array {
    $out = [];
    $seen = [];
    foreach (getLocalLayerRegistry(false) as $layer) {
        $id = normalizeLayerId((string)($layer['id'] ?? ''));
        if ($id === '' || isset($seen[$id])) continue;
        $path = localLayerPath($id);
        if (!$path || !is_file($path)) continue;
        $seen[$id] = true;
        $out[] = [
            'name'=>basename($path),
            'id'=>$id,
            'path'=>$path,
            'size'=>filesize($path),
            'date'=>date('Y-m-d H:i:s', filemtime($path)),
        ];
    }
    usort($out, fn($a,$b) => strcmp($a['name'], $b['name']));
    return $out;
}
$targets = [
    'excel' => ['dir'=>EXCEL_DIR, 'ext'=>['xlsx'], 'label'=>'Excel maestro de subestaciones', 'fixed'=>'DATOS DE SUB ESTACIONES MAESTRO.xlsx'],
    'transformers' => ['dir'=>EXCEL_DIR, 'ext'=>['xlsx'], 'label'=>'Capacidades de transformadores', 'fixed'=>'EquiposTransformadores.xlsx'],
    'quince' => ['dir'=>EXCEL_DIR.'/QUINCEMINUTALES EXCEL', 'ext'=>['xlsx','xlsm','xls'], 'label'=>'Quinceminutales'],
    'pit' => ['dir'=>PIT_DIR, 'ext'=>['xlsx','xlsm','xls'], 'label'=>'Anexos PIT mensual'],
    'pt' => ['dir'=>PT_COES_DIR, 'ext'=>['pdf','zip'], 'label'=>'PT COES / anteproyectos'],
    'geojson' => ['dir'=>DATA_DIR, 'ext'=>['geojson','json'], 'label'=>'GeoJSON'],
    'pdf' => ['dir'=>PDF_DIR, 'ext'=>['pdf'], 'label'=>'PDF unifilar'],
    'template_docx' => ['dir'=>TEMPLATE_DIR, 'ext'=>['docx'], 'label'=>'Plantilla Word de informe de zona', 'fixed'=>'zona_reporte.docx'],
    'template_pdf' => ['dir'=>TEMPLATE_DIR, 'ext'=>['pdf'], 'label'=>'Plantilla PDF de referencia', 'fixed'=>'zona_reporte.pdf'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$postLimitExceeded && isset($_POST['upload_kind'])) {
    $kind = $_POST['upload_kind'];
    if (!isset($targets[$kind])) {
        $msgs[] = ['t'=>'er','m'=>'Destino inválido.'];
    } elseif (empty($_FILES['data_file']['name'])) {
        $msgs[] = ['t'=>'er','m'=>'No se seleccionó ningún archivo.'];
    } else {
        $cfg = $targets[$kind];
        $uploadError = (int)($_FILES['data_file']['error'] ?? UPLOAD_ERR_OK);
        $ext = strtolower(pathinfo($_FILES['data_file']['name'], PATHINFO_EXTENSION));
        $name = $cfg['fixed'] ?? safeName($_FILES['data_file']['name']);
        $uploadMeta = [];
        if ($uploadError !== UPLOAD_ERR_OK) {
            $msgs[] = ['t'=>'er','m'=>uploadErrorMessage($uploadError)];
        } elseif (!in_array($ext, $cfg['ext'], true)) {
            $msgs[] = ['t'=>'er','m'=>'Extensión no permitida para '.$cfg['label']];
        } elseif ($_FILES['data_file']['size'] > MAX_MB * 1024 * 1024) {
            $msgs[] = ['t'=>'er','m'=>'El archivo supera '.MAX_MB.' MB.'];
        } elseif (($validationError = validateUploadedData($kind, $_FILES['data_file']['tmp_name'], $ext, $uploadMeta)) !== null) {
            $msgs[] = ['t'=>'er','m'=>$validationError . ' Se conservo el archivo anterior sin cambios.'];
        } else {
            ensureDir($cfg['dir']);
            $dest = $cfg['dir'].'/'.$name;
            $storeError = null;
            $stripBom = $kind === 'geojson' && !empty($uploadMeta['strip_bom']);
            if (storeUploadedFile($_FILES['data_file']['tmp_name'], $dest, $stripBom, $storeError)) {
                if ($kind === 'quince') clearAnalysisCaches('quince', $dest);
                elseif (in_array($kind, ['excel', 'transformers', 'pit', 'pt'], true)) clearAnalysisCaches('results', $dest);
                if ($kind === 'pt' && $ext === 'zip') safeExtractZip($dest, $cfg['dir'], ['pdf']);
                if ($kind === 'geojson') {
                    $layerId = normalizeLayerId($name);
                    $registry = getLocalLayerRegistry(false);
                    $idx = array_search($layerId, array_column($registry, 'id'), true);
                    $existing = $idx !== false ? $registry[$idx] : [];
                    $grupo = trim((string)($_POST['layer_group_new'] ?? '')) ?: trim((string)($_POST['layer_group'] ?? ''));
                    $menu = trim((string)($_POST['layer_menu_new'] ?? '')) ?: trim((string)($_POST['layer_menu'] ?? ''));
                    $requestedType = (string)($_POST['layer_tipo'] ?? 'auto');
                    $detectedType = (string)($uploadMeta['tipo'] ?? '');
                    $layerType = in_array($requestedType, ['point','line','polygon'], true)
                        ? $requestedType
                        : ($detectedType ?: ($existing['tipo'] ?? inferLayerType($layerId)));
                    $item = [
                        'id'=>$layerId,
                        'nombre'=>trim((string)($_POST['layer_name'] ?? '')) ?: ($existing['nombre'] ?? str_replace('_', ' ', $layerId)),
                        'tipo'=>$layerType,
                        'color'=>preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['layer_color'] ?? '')) ? $_POST['layer_color'] : ($existing['color'] ?? '#38bdf8'),
                        'grupo'=>$grupo ?: ($existing['grupo'] ?? 'Otras capas'),
                        'menu'=>$menu ?: ($existing['menu'] ?? layerMenuForGroup($grupo ?: 'Otras capas')),
                        'activa'=>!empty($_POST['layer_active']),
                    ];
                    if ($idx !== false) $registry[$idx] = $item;
                    else $registry[] = $item;
                    saveLocalLayerRegistry($registry);
                    @unlink(CACHE_DIR . '/' . $layerId . '.geojson.gz');
                    @unlink(CACHE_DIR . '/' . $layerId . '.json.gz');
                }
                $extra = $kind === 'geojson' && !empty($uploadMeta['feature_count'])
                    ? ' · ' . (int)$uploadMeta['feature_count'] . ' elementos'
                    : '';
                $msgs[] = ['t'=>'ok','m'=>'Archivo actualizado: '.$name.$extra];
            } else {
                $msgs[] = ['t'=>'er','m'=>$storeError ?: 'No se pudo guardar el archivo.'];
            }
        }
        $tab = in_array($kind, ['template_docx','template_pdf'], true) ? 'templates'
            : (in_array($kind, ['transformers','quince','pit'], true) ? 'excel' : $kind);
    }
}

if (isset($_GET['del_kind'], $_GET['file'])) {
    $kind = $_GET['del_kind'];
    $file = $kind === 'pt'
        ? str_replace('\\', '/', trim((string)$_GET['file']))
        : safeName($_GET['file']);
    if (isset($targets[$kind]) && $file) {
        $path = $targets[$kind]['dir'].'/'.$file;
        if ($kind === 'pt') {
            $real = realpath($path);
            $base = realpath($targets[$kind]['dir']);
            if (!$real || !$base || !str_starts_with($real, $base)) $path = '';
        }
        if (is_file($path)) {
            @unlink($path);
            if ($kind === 'quince') clearAnalysisCaches('quince', $path);
            elseif (in_array($kind, ['excel', 'transformers', 'pit', 'pt'], true)) clearAnalysisCaches('results', $path);
            if ($kind === 'geojson') {
                $layerId = normalizeLayerId($file);
                $registry = array_values(array_filter(getLocalLayerRegistry(false), fn($layer) => ($layer['id'] ?? '') !== $layerId));
                saveLocalLayerRegistry($registry);
                @unlink(CACHE_DIR . '/' . $layerId . '.geojson.gz');
                @unlink(CACHE_DIR . '/' . $layerId . '.json.gz');
            }
            $msgs[] = ['t'=>'ok','m'=>'Archivo eliminado: '.$file];
        }
    }
    $tab = in_array($kind, ['template_docx','template_pdf'], true) ? 'templates'
        : (in_array($kind, ['transformers','quince','pit'], true) ? 'excel' : $kind);
}

if (isset($_GET['del_layer_id'])) {
    $layerId = normalizeLayerId((string)$_GET['del_layer_id']);
    $path = localLayerPath($layerId);
    if ($layerId && $path && is_file($path)) {
        $real = realpath($path);
        $allowed = [realpath(DATA_DIR), realpath(CACHE_DIR)];
        $inside = false;
        foreach ($allowed as $base) {
            if ($base && $real && str_starts_with($real, $base)) $inside = true;
        }
        if ($inside) @unlink($path);
        $registry = array_values(array_filter(getLocalLayerRegistry(false), fn($layer) => ($layer['id'] ?? '') !== $layerId));
        saveLocalLayerRegistry($registry);
        $msgs[] = ['t'=>'ok','m'=>'Capa eliminada: '.$layerId];
    }
    $tab = 'geojson';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act_layer'])) {
    $layerId = normalizeLayerId((string)($_POST['layer_id'] ?? ''));
    $registry = getLocalLayerRegistry(false);
    $idx = array_search($layerId, array_column($registry, 'id'), true);
    if ($layerId && $idx !== false) {
        $grupo = trim((string)($_POST['layer_group_new'] ?? '')) ?: trim((string)($_POST['layer_group'] ?? ''));
        $menu = trim((string)($_POST['layer_menu_new'] ?? '')) ?: trim((string)($_POST['layer_menu'] ?? ''));
        $registry[$idx] = [
            'id'=>$layerId,
            'nombre'=>trim((string)($_POST['layer_name'] ?? '')) ?: ($registry[$idx]['nombre'] ?? $layerId),
            'tipo'=>in_array(($_POST['layer_tipo'] ?? ''), ['point','line','polygon'], true) ? $_POST['layer_tipo'] : ($registry[$idx]['tipo'] ?? inferLayerType($layerId)),
            'color'=>preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['layer_color'] ?? '')) ? $_POST['layer_color'] : ($registry[$idx]['color'] ?? '#38bdf8'),
            'grupo'=>$grupo ?: ($registry[$idx]['grupo'] ?? 'Otras capas'),
            'menu'=>$menu ?: ($registry[$idx]['menu'] ?? layerMenuForGroup($grupo ?: 'Otras capas')),
            'activa'=>!empty($_POST['layer_active']),
        ];
        saveLocalLayerRegistry($registry);
        $msgs[] = ['t'=>'ok','m'=>'Capa actualizada: '.$registry[$idx]['nombre']];
    }
    $tab = 'geojson';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act_api'])) {
    $name = trim($_POST['api_name'] ?? '');
    $url = trim($_POST['api_url'] ?? '');
    $tipo = in_array(($_POST['api_tipo'] ?? ''), ['point','line','polygon'], true) ? $_POST['api_tipo'] : 'point';
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['api_color'] ?? '')) ? $_POST['api_color'] : '#38bdf8';
    $grupo = trim((string)($_POST['api_group_new'] ?? '')) ?: trim((string)($_POST['api_group'] ?? ''));
    $menu = trim((string)($_POST['api_menu_new'] ?? '')) ?: trim((string)($_POST['api_menu'] ?? ''));
    if ($name && isSafeExternalUrl($url)) {
        $items = getUserApiRegistry(false);
        $placement = apiPlacement(['name'=>$name, 'url'=>$url, 'grupo'=>$grupo, 'menu'=>$menu]);
        $items[] = [
            'id'=>stableId('api', $name.'|'.$url),
            'name'=>$name,
            'url'=>$url,
            'tipo'=>$tipo,
            'color'=>$color,
            'grupo'=>$grupo ?: ($placement['grupo'] ?? 'APIs externas'),
            'menu'=>$menu ?: ($placement['menu'] ?? layerMenuForGroup($grupo ?: 'APIs externas')),
            'date'=>date('Y-m-d H:i:s'),
        ];
        saveApiRegistry($items);
        $msgs[] = ['t'=>'ok','m'=>'API registrada como capa externa del visor.'];
    } else {
        $msgs[] = ['t'=>'er','m'=>'Nombre o URL inválida o no permitida. Use solo http/https público.'];
    }
    $tab = 'api';
}

if (isset($_GET['del_api_id'])) {
    $apiId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['del_api_id']);
    $items = getUserApiRegistry(false);
    $before = count($items);
    $items = array_values(array_filter($items, fn($api) => ($api['id'] ?? '') !== $apiId));
    if (count($items) < $before) {
        saveApiRegistry($items);
        $msgs[] = ['t'=>'ok','m'=>'API eliminada.'];
    } else {
        $overrides = getApiOverrides();
        $overrides[$apiId] = array_merge($overrides[$apiId] ?? [], ['enabled'=>false]);
        saveApiOverrides($overrides);
        $msgs[] = ['t'=>'ok','m'=>'API oficial ocultada del visor.'];
    }
    $tab = 'api';
}

if (isset($_GET['restore_api_id'])) {
    $apiId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['restore_api_id']);
    $overrides = getApiOverrides();
    if (isset($overrides[$apiId])) {
        $overrides[$apiId]['enabled'] = true;
        saveApiOverrides($overrides);
        $msgs[] = ['t'=>'ok','m'=>'API oficial restaurada.'];
    }
    $tab = 'api';
}

$transformerExcelName = 'EquiposTransformadores.xlsx';
$excelRootAll = listFiles(EXCEL_DIR, ['xlsx','xls','csv']);
$transformerFiles = array_values(array_filter($excelRootAll, fn($f) => strcasecmp($f['name'], $transformerExcelName) === 0));
$excelRoot = array_values(array_filter($excelRootAll, fn($f) => strcasecmp($f['name'], $transformerExcelName) !== 0));
$quince = listFiles(EXCEL_DIR.'/QUINCEMINUTALES EXCEL', ['xlsx','xlsm','xls']);
$pitFiles = listFiles(PIT_DIR, ['xlsx','xlsm','xls']);
$ptFiles = listFilesRecursive(PT_COES_DIR, ['pdf','zip']);
$templateDocx = listFiles(TEMPLATE_DIR, ['docx']);
$templatePdf = listFiles(TEMPLATE_DIR, ['pdf']);
$geojson = listLayerFiles();
$pdfs = listFiles(PDF_DIR, ['pdf']);
$capas = getLocalLayerRegistry(false);
$layerGroups = knownLayerGroups();
$layerMenus = knownLayerMenus();
$apis = getAdminApiRegistry(false);
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Administración · GeoVisor CVC</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(ROOT . '/assets/css/style.css') ?>"/>
</head>
<body>
<div class="adm-wrap">
  <div class="adm-hdr">
    <img src="assets/img/cvc-logo.png" alt="CVC Energía" style="width:150px;height:auto"/>
    <div>
      <div class="adm-title">Panel de Administración</div>
      <div class="adm-sub">GeoVisor CVC · <?= htmlspecialchars($username) ?></div>
    </div>
    <a href="index.php" class="back-btn">Volver al mapa</a>
  </div>

  <?php foreach ($msgs as $msg): ?>
  <div style="max-width:980px;margin:0 auto 14px"><div class="alrt <?= $msg['t'] ?>"><?= htmlspecialchars($msg['m']) ?></div></div>
  <?php endforeach; ?>

  <div class="tabs">
    <button class="tab <?= $tab==='excel'?'on':'' ?>" data-tab="excel">Excel</button>
    <button class="tab <?= $tab==='geojson'?'on':'' ?>" data-tab="geojson">GeoJSON</button>
    <button class="tab <?= $tab==='pdf'?'on':'' ?>" data-tab="pdf">PDF unifilar</button>
    <button class="tab <?= $tab==='templates'?'on':'' ?>" data-tab="templates">Plantillas</button>
    <button class="tab <?= $tab==='pt'?'on':'' ?>" data-tab="pt">PT COES</button>
    <button class="tab <?= $tab==='api'?'on':'' ?>" data-tab="api">APIs externas</button>
  </div>

  <div class="tab-p <?= $tab==='excel'?'on':'' ?>" id="tab-excel">
    <div class="fcard">
      <h2>Archivos Excel</h2>
      <p>Actualiza el maestro o los quinceminutales conservando el mismo nombre del archivo para reemplazarlo.</p>
      <div class="admin-grid">
        <?php foreach ([
          ['transformers','Capacidades de transformadores',$transformerFiles,'Se guarda como EquiposTransformadores.xlsx para alimentar el análisis de disponibilidad.'],
          ['excel','Maestro de subestaciones',$excelRoot,'Se guarda siempre como DATOS DE SUB ESTACIONES MAESTRO.xlsx para que cada reemplazo sea reconocido por el analizador.'],
          ['quince','Quinceminutales',$quince,''],
          ['pit','Anexos PIT mensual',$pitFiles,'']
        ] as $block): ?>
        <section class="admin-box">
          <h3><?= $block[1] ?></h3>
          <?php if (!empty($block[3])): ?><p class="mini-note"><?= htmlspecialchars($block[3]) ?></p><?php endif; ?>
          <form method="POST" enctype="multipart/form-data" class="inline-upload">
            <?= csrfField() ?>
            <input type="hidden" name="upload_kind" value="<?= $block[0] ?>"/>
            <input type="file" name="data_file" <?= $block[0] === 'transformers' ? 'accept=".xlsx"' : '' ?> required/>
            <button class="btn-sub" type="submit">Subir / reemplazar</button>
          </form>
          <?= renderTable($block[2], $block[0]) ?>
        </section>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="tab-p <?= $tab==='templates'?'on':'' ?>" id="tab-templates">
    <div class="fcard">
      <h2>Plantillas de informes</h2>
      <p>Administra el formato activo para los informes de zonas evaluadas. El Word se usa como base de estilo y el PDF queda como referencia visual.</p>
      <div class="admin-grid">
        <?php foreach ([
          ['template_docx','Plantilla Word activa',$templateDocx,'Se guarda como zona_reporte.docx y alimenta las descargas Word/PDF.','accept=".docx"'],
          ['template_pdf','Plantilla PDF de referencia',$templatePdf,'Se guarda como zona_reporte.pdf para conservar la referencia del formato.','accept=".pdf"']
        ] as $block): ?>
        <section class="admin-box">
          <h3><?= $block[1] ?></h3>
          <p class="mini-note"><?= htmlspecialchars($block[3]) ?></p>
          <form method="POST" enctype="multipart/form-data" class="inline-upload">
            <?= csrfField() ?>
            <input type="hidden" name="upload_kind" value="<?= $block[0] ?>"/>
            <input type="file" name="data_file" <?= $block[4] ?> required/>
            <button class="btn-sub" type="submit">Subir / reemplazar</button>
          </form>
          <?= renderTable($block[2], $block[0]) ?>
        </section>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="tab-p <?= $tab==='geojson'?'on':'' ?>" id="tab-geojson">
    <div class="fcard">
      <h2>Capas GeoJSON</h2>
      <p>Las capas activas del visor se leen desde <code>data/geojson</code>.</p>
      <form method="POST" enctype="multipart/form-data" class="inline-upload">
        <?= csrfField() ?>
        <input type="hidden" name="upload_kind" value="geojson"/>
        <input type="file" name="data_file" accept=".geojson,.json" required/>
        <input name="layer_name" placeholder="Nombre visible"/>
        <select name="layer_tipo" title="Tipo de geometría">
          <option value="auto" selected>Detectar automaticamente</option>
          <option value="point">Puntos</option>
          <option value="line">Líneas</option>
          <option value="polygon">Polígonos</option>
        </select>
        <input name="layer_color" type="color" value="#38bdf8" title="Color"/>
        <input name="layer_menu" list="layerMenus" placeholder="Menú"/>
        <input name="layer_menu_new" placeholder="Nuevo menu"/>
        <input name="layer_group" list="layerGroups" placeholder="Grupo"/>
        <input name="layer_group_new" placeholder="Nuevo grupo"/>
        <label class="mini-check"><input type="checkbox" name="layer_active" value="1" checked/> Activa</label>
        <button class="btn-sub" type="submit">Subir / reemplazar</button>
      </form>
      <datalist id="layerGroups">
        <?php foreach ($layerGroups as $group): ?><option value="<?= htmlspecialchars($group) ?>"></option><?php endforeach; ?>
      </datalist>
      <datalist id="layerMenus">
        <?php foreach ($layerMenus as $menu): ?><option value="<?= htmlspecialchars($menu) ?>"></option><?php endforeach; ?>
      </datalist>
      <?= renderGeoJsonGroups($geojson, $capas, $layerGroups, $layerMenus) ?>
    </div>
  </div>

  <div class="tab-p <?= $tab==='pdf'?'on':'' ?>" id="tab-pdf">
    <div class="fcard">
      <h2>PDF unifilar</h2>
      <p>Sube el PDF unifilar actualizado. El análisis lo mostrará como referencia visual.</p>
      <form method="POST" enctype="multipart/form-data" class="inline-upload">
        <?= csrfField() ?>
        <input type="hidden" name="upload_kind" value="pdf"/>
        <input type="file" name="data_file" accept=".pdf" required/>
        <button class="btn-sub" type="submit">Subir / reemplazar</button>
      </form>
      <?= renderTable($pdfs, 'pdf') ?>
    </div>
  </div>

  <div class="tab-p <?= $tab==='pt'?'on':'' ?>" id="tab-pt">
    <div class="fcard">
      <h2>Plan de Transmisión COES 2027-2036</h2>
      <p>Administra los PDFs de anteproyectos. El análisis de SET y líneas los revisa para detectar proyectos asociados.</p>
      <form method="POST" enctype="multipart/form-data" class="inline-upload">
        <?= csrfField() ?>
        <input type="hidden" name="upload_kind" value="pt"/>
        <input type="file" name="data_file" accept=".pdf,.zip" required/>
        <button class="btn-sub" type="submit">Subir / reemplazar</button>
      </form>
      <?= renderTable($ptFiles, 'pt') ?>
    </div>
  </div>

  <div class="tab-p <?= $tab==='api'?'on':'' ?>" id="tab-api">
    <div class="fcard">
      <h2>APIs externas</h2>
      <p>Registra endpoints REST o GeoJSON. El visor los convertirá en capas y los mostrará en el menú del mapa.</p>
      <div class="api-card">
        <strong>Electricidad OSINERGMIN</strong>
        <span><?= htmlspecialchars(URL_ELEC_REST) ?></span>
      </div>
      <form method="POST" class="api-form">
        <?= csrfField() ?>
        <input type="hidden" name="act_api" value="1"/>
        <input name="api_name" placeholder="Nombre de la API" required/>
        <input name="api_url" placeholder="https://..." required/>
        <select name="api_tipo" title="Tipo de geometría">
          <option value="point">Puntos</option>
          <option value="line">Líneas</option>
          <option value="polygon">Polígonos</option>
        </select>
        <input name="api_color" type="color" value="#38bdf8" title="Color de capa"/>
        <input name="api_menu" list="layerMenus" placeholder="Menú"/>
        <input name="api_menu_new" placeholder="Nuevo menú"/>
        <input name="api_group" list="layerGroups" placeholder="Grupo"/>
        <input name="api_group_new" placeholder="Nuevo grupo"/>
        <button class="btn-sub" type="submit">Registrar</button>
      </form>
      <div class="admin-scroll admin-api-scroll">
      <?php foreach ($apis as $api): ?>
        <div class="api-card <?= empty($api['enabled']) ? 'muted-card' : '' ?>">
          <strong><?= htmlspecialchars($api['name']) ?></strong>
          <span><?= htmlspecialchars($api['tipo']) ?> · <?= htmlspecialchars(($api['menu'] ?? 'APIs externas') . ' / ' . ($api['grupo'] ?? 'APIs externas')) ?> · <?= htmlspecialchars($api['url'] ?? 'URL protegida') ?></span>
          <?php if (!empty($api['official'])): ?>
            <?php if (empty($api['enabled'])): ?>
              <a class="btn-sub sm" href="<?= htmlspecialchars(csrfUrl('upload.php?restore_api_id=' . urlencode($api['id']) . '&tab=api')) ?>">Restaurar</a>
            <?php else: ?>
              <a class="btn-del" href="<?= htmlspecialchars(csrfUrl('upload.php?del_api_id=' . urlencode($api['id']) . '&tab=api')) ?>">Ocultar</a>
            <?php endif; ?>
          <?php else: ?>
            <a class="btn-del" href="<?= htmlspecialchars(csrfUrl('upload.php?del_api_id=' . urlencode($api['id']) . '&tab=api')) ?>" onclick="return confirm('¿Eliminar esta API?')">Eliminar</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php
function renderTable(array $files, string $kind): string {
    ob_start(); ?>
    <div class="admin-scroll admin-scroll-sm">
    <table class="dtbl">
      <thead><tr><th>Archivo</th><th>Tamaño</th><th>Actualizado</th><th>Acción</th></tr></thead>
      <tbody>
        <?php if (!$files): ?>
          <tr><td colspan="4">No hay archivos.</td></tr>
        <?php endif; ?>
        <?php foreach ($files as $f): ?>
        <tr>
          <td><?= htmlspecialchars($f['name']) ?></td>
          <td><?= formatBytes((int)$f['size']) ?></td>
          <td><?= htmlspecialchars($f['date']) ?></td>
          <td><a class="btn-del" href="<?= htmlspecialchars(csrfUrl('upload.php?del_kind=' . urlencode($kind) . '&file=' . urlencode($f['name']))) ?>" onclick="return confirm('¿Eliminar este archivo?')">Eliminar</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php return ob_get_clean();
}

function renderGeoJsonTable(array $files, array $registry, array $groups): string {
    $byId = [];
    foreach ($registry as $layer) $byId[$layer['id']] = $layer;
    ob_start(); ?>
    <table class="dtbl layer-admin-table">
      <thead><tr><th>Archivo</th><th>Propiedades de capa</th><th>Actualizado</th><th>Accion</th></tr></thead>
      <tbody>
        <?php if (!$files): ?>
          <tr><td colspan="4">No hay archivos.</td></tr>
        <?php endif; ?>
        <?php foreach ($files as $f):
          $id = normalizeLayerId($f['name']);
          $layer = $byId[$id] ?? ['id'=>$id, 'nombre'=>str_replace('_',' ', $id), 'tipo'=>inferLayerType($id), 'color'=>'#38bdf8', 'grupo'=>'Otras capas', 'activa'=>false];
        ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars($f['name']) ?></strong><br/>
            <small><?= formatBytes((int)$f['size']) ?></small>
          </td>
          <td>
            <form method="POST" class="layer-edit-form">
              <?= csrfField() ?>
              <input type="hidden" name="act_layer" value="1"/>
              <input type="hidden" name="layer_id" value="<?= htmlspecialchars($id) ?>"/>
              <input name="layer_name" value="<?= htmlspecialchars($layer['nombre']) ?>" placeholder="Nombre visible"/>
              <select name="layer_tipo">
                <?php foreach (['point'=>'Puntos','line'=>'Líneas','polygon'=>'Polígonos'] as $value=>$label): ?>
                  <option value="<?= $value ?>" <?= ($layer['tipo'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
              <input name="layer_color" type="color" value="<?= htmlspecialchars($layer['color']) ?>"/>
              <input name="layer_group" list="layerGroups" value="<?= htmlspecialchars($layer['grupo']) ?>" placeholder="Grupo"/>
              <input name="layer_group_new" placeholder="Nuevo grupo"/>
              <label class="mini-check"><input type="checkbox" name="layer_active" value="1" <?= !empty($layer['activa']) ? 'checked' : '' ?>/> Activa</label>
              <button class="btn-sub sm" type="submit">Guardar</button>
            </form>
          </td>
          <td><?= htmlspecialchars($f['date']) ?></td>
          <td><a class="btn-del" href="<?= htmlspecialchars(csrfUrl('upload.php?del_kind=geojson&file=' . urlencode($f['name']))) ?>" onclick="return confirm('¿Eliminar esta capa del visor?')">Eliminar</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php return ob_get_clean();
}

function renderGeoJsonGroupsLegacy(array $files, array $registry, array $groups): string {
    $byId = [];
    foreach ($registry as $layer) $byId[$layer['id']] = $layer;
    $bucket = [];
    foreach ($files as $f) {
        $id = normalizeLayerId($f['id'] ?? $f['name']);
        $layer = $byId[$id] ?? ['id'=>$id, 'nombre'=>str_replace('_',' ', $id), 'tipo'=>inferLayerType($id), 'color'=>'#38bdf8', 'grupo'=>'Otras capas', 'menu'=>'Eléctrico', 'activa'=>false];
        $key = ($layer['menu'] ?? 'Eléctrico') . ' / ' . ($layer['grupo'] ?? 'Otras capas');
        $bucket[$key][] = [$f, $id, $layer];
    }
    ksort($bucket, SORT_NATURAL | SORT_FLAG_CASE);
    ob_start(); ?>
    <?php if (!$files): ?>
      <table class="dtbl"><tbody><tr><td>No hay archivos.</td></tr></tbody></table>
    <?php endif; ?>
    <?php foreach ($bucket as $title => $rows): ?>
      <details class="admin-layer-group" open>
        <summary><?= htmlspecialchars($title) ?> <span><?= count($rows) ?></span></summary>
        <table class="dtbl layer-admin-table">
          <thead><tr><th>Archivo</th><th>Propiedades de capa</th><th>Actualizado</th><th>Accion</th></tr></thead>
          <tbody>
          <?php foreach ($rows as [$f, $id, $layer]): ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($f['name']) ?></strong><br/>
              <small><?= formatBytes((int)$f['size']) ?></small>
            </td>
            <td>
              <form method="POST" class="layer-edit-form">
                <?= csrfField() ?>
                <input type="hidden" name="act_layer" value="1"/>
                <input type="hidden" name="layer_id" value="<?= htmlspecialchars($id) ?>"/>
                <input name="layer_name" value="<?= htmlspecialchars($layer['nombre']) ?>" placeholder="Nombre visible"/>
                <select name="layer_tipo">
                  <?php foreach (['point'=>'Puntos','line'=>'Líneas','polygon'=>'Polígonos'] as $value=>$label): ?>
                    <option value="<?= $value ?>" <?= ($layer['tipo'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
                <input name="layer_color" type="color" value="<?= htmlspecialchars($layer['color']) ?>"/>
                <input name="layer_menu" list="layerMenus" value="<?= htmlspecialchars($layer['menu'] ?? 'Eléctrico') ?>" placeholder="Menú"/>
                <input name="layer_menu_new" placeholder="Nuevo menú"/>
                <input name="layer_group" list="layerGroups" value="<?= htmlspecialchars($layer['grupo']) ?>" placeholder="Grupo"/>
                <input name="layer_group_new" placeholder="Nuevo grupo"/>
                <label class="mini-check"><input type="checkbox" name="layer_active" value="1" <?= !empty($layer['activa']) ? 'checked' : '' ?>/> Activa</label>
                <button class="btn-sub sm" type="submit">Guardar</button>
              </form>
            </td>
            <td><?= htmlspecialchars($f['date']) ?></td>
            <td><a class="btn-del" href="<?= htmlspecialchars(csrfUrl('upload.php?del_layer_id=' . urlencode($id) . '&tab=geojson')) ?>" onclick="return confirm('Eliminar esta capa del visor?')">Eliminar</a></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endforeach; ?>
    <?php return ob_get_clean();
}

function adminAscii(string $text): string {
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($converted) && $converted !== '') $text = $converted;
    }
    return strtolower($text);
}

function adminCanonicalMenu(string $menu): string {
    $menu = trim($menu);
    if ($menu === '') return '';
    $s = adminAscii($menu);
    return match ($s) {
        'electricidad', 'electrico' => 'Electricidad',
        'ambiental' => 'Ambiental',
        'agricola y acuicultura' => 'Agricola y Acuicultura',
        'predios' => 'Predios',
        'ciras aprobados' => 'CIRAS aprobados',
        'zonas arqueologicas' => 'Zonas Arqueologicas',
        'otros' => 'Otros',
        default => $menu,
    };
}

function adminMenuName(array $layer, string $id): string {
    $explicit = trim((string)($layer['menu'] ?? ''));
    if ($explicit !== '') return adminCanonicalMenu($explicit);
    $s = adminAscii(($layer['menu'] ?? '') . ' ' . ($layer['grupo'] ?? '') . ' ' . ($layer['nombre'] ?? '') . ' ' . $id);
    if (str_contains($s, 'ambient') || str_contains($s, 'anp') || str_contains($s, 'ecosistema') || str_contains($s, 'fragil')) return 'Ambiental';
    if (str_contains($s, 'agric') || str_contains($s, 'midagri') || str_contains($s, 'produce') || str_contains($s, 'acui')) return 'Agricola y Acuicultura';
    if (str_contains($s, 'predio') || str_contains($s, 'sunarp') || str_contains($s, 'piura') || str_contains($s, 'olmos') || str_contains($s, 'moquegua')) return 'Predios';
    if (str_contains($s, 'cira') || str_contains($s, 'mc_cira_ubigeo')) return 'CIRAS aprobados';
    if (str_contains($s, 'arque') || str_contains($s, 'cultura')) return 'Zonas Arqueologicas';
    return 'Electricidad';
}

function adminGroupName(array $layer, string $id, string $menu): string {
    $explicit = trim((string)($layer['grupo'] ?? ''));
    if ($explicit !== '') return $explicit;
    $s = adminAscii(($layer['grupo'] ?? '') . ' ' . ($layer['nombre'] ?? '') . ' ' . $id);
    if ($menu === 'Agricola y Acuicultura') {
        if (str_contains($s, 'produce') || str_contains($s, 'acui')) return 'PRODUCE / Archivos acuicolas';
        if (str_contains($s, 'midagri') || str_contains($s, 'departamento')) return 'MIDAGRI / Departamentos';
        return 'MIDAGRI / Capas agrícolas';
    }
    if ($menu === 'Predios') return 'APIs de predios';
    if ($menu === 'CIRAS aprobados') return 'CIRA - Coordenada referencial';
    if ($menu === 'Zonas Arqueologicas') return 'CULTURA';
    if (str_contains($s, 'conces')) return 'Áreas de concesión';
    if (str_contains($s, 'distrib') || str_contains($s, 'media') || str_contains($s, 'sed') || str_contains($s, 'salida')) return 'Distribución';
    if (str_contains($s, 'subest') || preg_match('/\bset\b/', $s)) return 'Subestaciones';
    if (str_contains($s, 'trans') || preg_match('/\blt\b/', $s)) return 'Transmisión';
    return trim((string)($layer['grupo'] ?? 'Otras capas')) ?: 'Otras capas';
}

function adminDefaultGroupsForMenu(string $menu): array {
    return match (adminCanonicalMenu($menu)) {
        'Electricidad' => ['Subestaciones','Transmision','Distribucion','Areas de concesion / Vigentes','Areas de concesion / En tramite','API OSINERGMIN','Otras capas'],
        'Ambiental' => ['AMBIENTAL','ANP y ecosistemas fragiles','Ecosistemas','Areas naturales protegidas','Otras capas'],
        'Agricola y Acuicultura' => ['MIDAGRI / Departamentos','PRODUCE Acuicultura','Departamentos','API MIDAGRI','API PRODUCE'],
        'Predios' => ['APIs de predios','SUNARP BGR','Otras capas'],
        'CIRAS aprobados' => ['CIRA - Coordenada referencial'],
        'Zonas Arqueologicas' => ['Zonas arqueologicas','CULTURA','Sitios arqueologicos'],
        default => ['Otras capas'],
    };
}

function renderGeoJsonGroups(array $files, array $registry, array $groups, array $menus): string {
    $byId = [];
    foreach ($registry as $layer) $byId[$layer['id']] = $layer;
    $bucket = [];
    foreach ($menus as $menuName) {
        $menuName = adminCanonicalMenu((string)$menuName);
        if ($menuName === '') continue;
        $bucket[$menuName] = $bucket[$menuName] ?? [];
        foreach (adminDefaultGroupsForMenu($menuName) as $groupName) {
            $bucket[$menuName][$groupName] = $bucket[$menuName][$groupName] ?? [];
        }
    }
    foreach ($registry as $layer) {
        $id = normalizeLayerId((string)($layer['id'] ?? ''));
        if ($id === '') continue;
        $menu = adminMenuName($layer, $id);
        $group = adminGroupName($layer, $id, $menu);
        $bucket[$menu][$group] = $bucket[$menu][$group] ?? [];
    }
    foreach ($files as $f) {
        $id = normalizeLayerId($f['id'] ?? $f['name']);
        $layer = $byId[$id] ?? ['id'=>$id, 'nombre'=>str_replace('_',' ', $id), 'tipo'=>inferLayerType($id), 'color'=>'#38bdf8', 'grupo'=>'Otras capas', 'menu'=>'Electricidad', 'activa'=>false];
        $menu = adminMenuName($layer, $id);
        $group = adminGroupName($layer, $id, $menu);
        $bucket[$menu][$group][] = [$f, $id, $layer];
    }
    $menuOrder = ['Electricidad','Ambiental','Agricola y Acuicultura','Predios','CIRAS aprobados','Zonas Arqueologicas','Otros'];
    uksort($bucket, function($a, $b) use ($menuOrder) {
        $pa = array_search($a, $menuOrder, true);
        $pb = array_search($b, $menuOrder, true);
        $pa = $pa === false ? 99 : $pa;
        $pb = $pb === false ? 99 : $pb;
        return $pa === $pb ? strcmp($a, $b) : ($pa <=> $pb);
    });
    foreach ($bucket as &$groupsByMenu) ksort($groupsByMenu, SORT_NATURAL | SORT_FLAG_CASE);
    unset($groupsByMenu);

    ob_start(); ?>
    <?php if (!$files): ?>
      <div class="admin-scroll"><table class="dtbl"><tbody><tr><td>No hay archivos.</td></tr></tbody></table></div>
    <?php endif; ?>
    <?php foreach ($bucket as $menu => $groupsByMenu): ?>
      <?php $countMenu = array_sum(array_map('count', $groupsByMenu)); ?>
      <details class="admin-menu-group" open>
        <summary><?= htmlspecialchars($menu) ?> <span><?= $countMenu ?></span></summary>
        <div class="admin-menu-body">
        <?php foreach ($groupsByMenu as $title => $rows): ?>
          <details class="admin-layer-group" open>
            <summary><?= htmlspecialchars($title) ?> <span><?= count($rows) ?></span></summary>
            <?php if (!$rows): ?>
            <div class="admin-empty-group">Sin GeoJSON en este grupo. Puede asignar una capa usando los campos de menu/grupo al subir o editar un archivo.</div>
            <?php else: ?>
            <div class="admin-scroll admin-layer-scroll">
            <table class="dtbl layer-admin-table">
              <thead><tr><th>Archivo</th><th>Propiedades de capa</th><th>Actualizado</th><th>Accion</th></tr></thead>
              <tbody>
              <?php foreach ($rows as [$f, $id, $layer]): ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($f['name']) ?></strong><br/>
                  <small><?= formatBytes((int)$f['size']) ?></small>
                </td>
                <td>
                  <form method="POST" class="layer-edit-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="act_layer" value="1"/>
                    <input type="hidden" name="layer_id" value="<?= htmlspecialchars($id) ?>"/>
                    <input name="layer_name" value="<?= htmlspecialchars($layer['nombre']) ?>" placeholder="Nombre visible"/>
                    <select name="layer_tipo">
                      <?php foreach (['point'=>'Puntos','line'=>'Líneas','polygon'=>'Polígonos'] as $value=>$label): ?>
                        <option value="<?= $value ?>" <?= ($layer['tipo'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                      <?php endforeach; ?>
                    </select>
                    <input name="layer_color" type="color" value="<?= htmlspecialchars($layer['color']) ?>"/>
                    <input name="layer_menu" list="layerMenus" value="<?= htmlspecialchars(adminMenuName($layer, $id)) ?>" placeholder="Menú"/>
                    <input name="layer_menu_new" placeholder="Nuevo menú"/>
                    <input name="layer_group" list="layerGroups" value="<?= htmlspecialchars(adminGroupName($layer, $id, adminMenuName($layer, $id))) ?>" placeholder="Grupo"/>
                    <input name="layer_group_new" placeholder="Nuevo grupo"/>
                    <label class="mini-check"><input type="checkbox" name="layer_active" value="1" <?= !empty($layer['activa']) ? 'checked' : '' ?>/> Activa</label>
                    <button class="btn-sub sm" type="submit">Guardar</button>
                  </form>
                </td>
                <td><?= htmlspecialchars($f['date']) ?></td>
                <td><a class="btn-del" href="<?= htmlspecialchars(csrfUrl('upload.php?del_layer_id=' . urlencode($id) . '&tab=geojson')) ?>" onclick="return confirm('Eliminar esta capa del visor?')">Eliminar</a></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            </div>
            <?php endif; ?>
          </details>
        <?php endforeach; ?>
        </div>
      </details>
    <?php endforeach; ?>
    <?php return ob_get_clean();
}
?>
<script>
document.querySelectorAll('.tab').forEach(b => {
  b.addEventListener('click', () => {
    document.querySelectorAll('.tab,.tab-p').forEach(e => e.classList.remove('on'));
    b.classList.add('on');
    document.getElementById('tab-'+b.dataset.tab).classList.add('on');
  });
});
</script>
</body>
</html>

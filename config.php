<?php
/**
 * config.php — SIG Energético-Minero OSINERGMIN
 * Configuración central. Incluir siempre al inicio de cada archivo PHP.
 */

// ── Sesión segura ──
function appIsHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

if (session_status() === PHP_SESSION_NONE) {
    session_name('CVC_GEOVISOR');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', appIsHttps() ? 1 : 0);
    session_start();
}

function sendSecurityHeaders(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://threejs.org; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob: https:; connect-src 'self' https: http:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
    if (appIsHttps()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
sendSecurityHeaders();

// ── Credenciales ──

// ── Rutas absolutas ──
define('ROOT',          __DIR__);
define('DATA_DIR',      ROOT . '/data/geojson');
define('UPLOADS_DIR',   ROOT . '/uploads');
define('EXCEL_DIR',     ROOT . '/uploads/excel');
define('PDF_DIR',       ROOT . '/uploads/pdf');
define('CIRA_PDF_DIR',  ROOT . '/uploads/cira_pdfs');
define('TEMPLATE_DIR',  ROOT . '/uploads/templates');
define('KML_DIR',       ROOT . '/uploads/kml');
define('CACHE_DIR',     ROOT . '/cache');
define('ANALYSIS_DIR',  ROOT . '/analysis');
define('REPORT_DIR',    ROOT . '/reports');
define('CA_BUNDLE_FILE', is_file(ROOT . '/certs/cacert.pem') ? ROOT . '/certs/cacert.pem' : '');
define('MANIFEST_FILE', EXCEL_DIR . '/manifest.json');
define('AUTH_DB_FILE',  getenv('GEOVISOR_AUTH_DB') ?: dirname(ROOT) . '/.geovisor_auth/users.json');
function appCurlTlsOptions(): array {
    if (!defined('CURLOPT_SSL_VERIFYPEER')) return [];
    $options = [
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if (CA_BUNDLE_FILE !== '') $options[CURLOPT_CAINFO] = CA_BUNDLE_FILE;
    return $options;
}
function appSslStreamOptions(): array {
    $options = [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
    ];
    if (CA_BUNDLE_FILE !== '') $options['cafile'] = CA_BUNDLE_FILE;
    return $options;
}
function detectPythonBin(): string {
    $env = getenv('PYTHON_BIN');
    if ($env) return $env;

    $candidates = [
        ROOT . '/.venv/Scripts/python.exe',
        'C:/Users/cvc/AppData/Local/Programs/Python/Python314/python.exe',
        'C:/Users/cvc/AppData/Local/Programs/Python/Python313/python.exe',
        'C:/Users/cvc/AppData/Local/Programs/Python/Python312/python.exe',
        'C:/Users/cvc/AppData/Local/Programs/Python/Python311/python.exe',
        'C:/Users/mzarate/AppData/Local/Programs/Python/Python314/python.exe',
        'C:/Users/mzarate/AppData/Local/Programs/Python/Python313/python.exe',
        'C:/Users/mzarate/AppData/Local/Programs/Python/Python312/python.exe',
        'C:/Users/mzarate/AppData/Local/Programs/Python/Python311/python.exe',
        'C:/Users/mzarate/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) return $candidate;
    }
    return PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3';
}
define('PYTHON_BIN',    detectPythonBin());
function detectDocumentPythonBin(): string {
    $env = getenv('DOC_PYTHON_BIN');
    if ($env) return $env;
    $projectVenv = ROOT . '/.venv/Scripts/python.exe';
    if (is_file($projectVenv)) return $projectVenv;
    $bundledCvc = 'C:/Users/cvc/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe';
    if (is_file($bundledCvc)) return $bundledCvc;
    $bundled = 'C:/Users/mzarate/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe';
    if (is_file($bundled)) return $bundled;
    return PYTHON_BIN;
}
define('DOC_PYTHON_BIN', detectDocumentPythonBin());
define('ANALYZER',      ANALYSIS_DIR . '/set_load_analyzer.py');
define('LINE_ANALYZER', ANALYSIS_DIR . '/line_load_analyzer.py');
define('ZONE_REPORT_BUILDER', ROOT . '/scripts/zone_report_builder.py');
define('SET_REPORT_BUILDER', ROOT . '/scripts/set_report_builder.py');
define('LINE_REPORT_BUILDER', ROOT . '/scripts/line_report_builder.py');
define('ZONE_REPORT_TEMPLATE', is_file(TEMPLATE_DIR . '/zona_reporte.docx')
    ? TEMPLATE_DIR . '/zona_reporte.docx'
    : 'C:/Users/cvc/OneDrive - CONSORCIO ELECTRICO DE VILLACURI S.A.C/ANDRE/PLANTILLA PARA PRESENTAR RESUMENES TECNICOS/PLANTILLA 1.docx');
define('ZONE_REPORT_TEMPLATE_PDF', is_file(TEMPLATE_DIR . '/zona_reporte.pdf')
    ? TEMPLATE_DIR . '/zona_reporte.pdf'
    : 'C:/Users/cvc/OneDrive - CONSORCIO ELECTRICO DE VILLACURI S.A.C/ANDRE/PLANTILLA PARA PRESENTAR RESUMENES TECNICOS/PLANTILLA 1.pdf');
define('LINE_LOAD_CSV', EXCEL_DIR . '/lineas/Potencia Aparente LLTT_2025.csv');
define('MAX_MB',        250);

// ── URLs OSINERGMIN ──
define('URL_ELEC_REST', 'https://gisem.osinergmin.gob.pe/serverosih/rest/services/Electricidad/ELECTRICIDAD/MapServer');
define('URL_MT_REST',   'https://gisem.osinergmin.gob.pe/serverosih/rest/services/OGC/PeruOsinergmin019RedesMediaTension/MapServer');
define('URL_CERAUNICO_REST', 'https://gisem.osinergmin.gob.pe/serverosih/rest/services/Electricidad/DescargasAtmosfericas/MapServer');
define('URL_ELEC_WMS',  'https://gisem.osinergmin.gob.pe/serverosih/services/Electricidad/ELECTRICIDAD/MapServer/WMSServer');
define('URL_ELEC',      URL_ELEC_REST);
define('API_REGISTRY_FILE', ROOT . '/uploads/api_registry.json');
define('API_OVERRIDES_FILE', ROOT . '/uploads/api_layer_overrides.json');
define('LAYER_REGISTRY_FILE', ROOT . '/uploads/layer_registry.json');
define('OPENAI_KEY_FILE', getenv('GEOVISOR_OPENAI_KEY_FILE') ?: dirname(ROOT) . '/.openai_api_key');
define('AI_MEMORY_FILE', getenv('GEOVISOR_AI_MEMORY_FILE') ?: dirname(ROOT) . '/.geovisor_ai_memory.json');
define('EXTERNAL_LAYER_CACHE_DIR', CACHE_DIR . '/external_layers');
define('PIT_DIR', EXCEL_DIR . '/PIT');
define('PIT_ANALYZER', ANALYSIS_DIR . '/pit_lookup.py');
define('PT_COES_DIR', UPLOADS_DIR . '/pt_coes');
define('PT_COES_ANALYZER', ANALYSIS_DIR . '/pt_lookup.py');

function stableId(string $prefix, string $value): string {
    return $prefix . '_' . substr(sha1($value), 0, 12);
}

function apiLayerId(array $api, int $idx = 0): string {
    $raw = trim((string)($api['id'] ?? ''));
    if ($raw !== '') return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $raw);
    return stableId('api', (($api['name'] ?? 'api') . '|' . ($api['url'] ?? '') . '|' . $idx));
}

function layerMenuForGroup(string $group): string {
    $fallback = $group;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $group);
        if (is_string($converted) && $converted !== '') $fallback = $converted;
    }
    $s = strtolower($fallback);
    if (str_contains($s, 'agro') || str_contains($s, 'agric') || str_contains($s, 'midagri') || str_contains($s, 'acui') || str_contains($s, 'produce')) return 'Agricola y Acuicultura';
    if (str_contains($s, 'ambient') || str_contains($s, 'anp') || str_contains($s, 'sernanp') || str_contains($s, 'ecosistema') || str_contains($s, 'fragil')) return 'Ambiental';
    if (str_contains($s, 'predio') || str_contains($s, 'sunarp') || str_contains($s, 'piura') || str_contains($s, 'olmos') || str_contains($s, 'moquegua')) return 'Predios';
    if (str_contains($s, 'cira')) return 'CIRAS aprobados';
    if (str_contains($s, 'arque') || str_contains($s, 'cultura')) return 'Zonas arqueologicas';
    if (str_contains($s, 'api')) return 'APIs externas';
    return 'Electricidad';
}

function apiPlacement(array $api): array {
    $text = strtolower(($api['name'] ?? '') . ' ' . ($api['url'] ?? '') . ' ' . ($api['grupo'] ?? '') . ' ' . ($api['menu'] ?? ''));
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : $text;
    if (is_string($ascii) && $ascii !== '') $text = strtolower($ascii);
    if (str_contains($text, 'pred') || str_contains($text, 'piura') || str_contains($text, 'olmos') || str_contains($text, 'moquegua') || str_contains($text, 'lambayeque')) {
        return ['menu'=>'Predios', 'grupo'=>'APIs de predios'];
    }
    if (str_contains($text, 'midagri') || str_contains($text, 'mnsa') || str_contains($text, 'agric')) {
        return ['menu'=>'Agricola y Acuicultura', 'grupo'=>'API MIDAGRI'];
    }
    if (str_contains($text, 'produce') || str_contains($text, 'acuic')) {
        return ['menu'=>'Agricola y Acuicultura', 'grupo'=>'API PRODUCE'];
    }
    if (str_contains($text, 'ambient') || str_contains($text, 'anp') || str_contains($text, 'sernanp') || str_contains($text, 'ecosistema') || str_contains($text, 'fragil')) {
        return ['menu'=>'Ambiental', 'grupo'=>'ANP y ecosistemas fragiles'];
    }
    if (str_contains($text, 'cira') || str_contains($text, 'mc_cira_ubigeo')) {
        return ['menu'=>'CIRAS aprobados', 'grupo'=>'CIRA - Coordenada referencial'];
    }
    $fallbackMenu = trim((string)($api['menu'] ?? ''));
    $fallbackGroup = trim((string)($api['grupo'] ?? ''));
    if ($fallbackMenu === '' || strcasecmp($fallbackMenu, 'APIs externas') === 0) $fallbackMenu = 'Predios';
    if ($fallbackGroup === '' || strcasecmp($fallbackGroup, 'APIs externas') === 0) $fallbackGroup = 'APIs de predios';
    return ['menu'=>$fallbackMenu, 'grupo'=>$fallbackGroup];
}

function officialApiLayers(): array {
    return [
        [
            'id' => 'midagri_mnsa_2024',
            'name' => 'MIDAGRI - Superficie Agricola Nacional 2024',
            'url' => 'https://winlmprap24.midagri.gob.pe/arcgis_server/rest/services/ENIS/MNSA/MapServer',
            'tipo' => 'polygon',
            'color' => '#22c55e',
            'grupo' => 'API MIDAGRI',
            'menu' => 'Agricola y Acuicultura',
            'date' => '2024',
            'official' => true,
        ],
        [
            'id' => 'produce_catastro_acuicola',
            'name' => 'PRODUCE - Catastro Acuicola Nacional',
            'url' => 'http://sigeo.produce.gob.pe:6080/arcgis/rest/services/acuicultura/CATASTRO_ACUICOLA_GDB_WEB_WMS/MapServer',
            'tipo' => 'polygon',
            'color' => '#06b6d4',
            'grupo' => 'API PRODUCE',
            'menu' => 'Agricola y Acuicultura',
            'date' => 'acuicultura',
            'official' => true,
        ],
        [
            'id' => 'cultura_cira_aprobados',
            'name' => 'CIRAS aprobados - Coordenada referencial',
            'url' => 'https://geoportal.cultura.gob.pe/dgpa/json_ciras',
            'tipo' => 'point',
            'color' => '#7c3aed',
            'grupo' => 'CIRA - Coordenada referencial',
            'menu' => 'CIRAS aprobados',
            'date' => 'tiempo real',
            'official' => true,
        ],
        [
            'id' => 'cultura_anp_nacional',
            'name' => 'ANP de Administracion Nacional - punto referencial',
            'url' => 'https://geoportal.cultura.gob.pe/interoperabilidad/json_anp_nac',
            'tipo' => 'point',
            'color' => '#15803d',
            'grupo' => 'ANP y ecosistemas fragiles',
            'menu' => 'Ambiental',
            'date' => 'tiempo real',
            'official' => true,
        ],
    ];
}

function normalizeApiRegistryItems(array $items, bool $public = false): array {
    $out = [];
    foreach ($items as $idx => $api) {
        if (!is_array($api)) continue;
        $url = trim((string)($api['url'] ?? ''));
        $name = trim((string)($api['name'] ?? ''));
        if (!$url || !$name || !isSafeExternalUrl($url)) continue;
        $placement = apiPlacement($api);
        $rawGroup = trim((string)($api['grupo'] ?? ''));
        $rawMenu = trim((string)($api['menu'] ?? ''));
        $finalGroup = ($rawGroup === '' || strcasecmp($rawGroup, 'APIs externas') === 0) ? ($placement['grupo'] ?? 'APIs externas') : $rawGroup;
        $finalMenu = ($rawMenu === '' || strcasecmp($rawMenu, 'APIs externas') === 0) ? ($placement['menu'] ?? layerMenuForGroup($finalGroup)) : $rawMenu;
        $item = [
            'id' => apiLayerId($api, $idx),
            'name' => $name,
            'url' => $url,
            'tipo' => in_array(($api['tipo'] ?? ''), ['point','line','polygon'], true) ? $api['tipo'] : 'point',
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($api['color'] ?? '')) ? $api['color'] : '#38bdf8',
            'grupo' => $finalGroup,
            'menu' => $finalMenu,
            'date' => $api['date'] ?? '',
            'official' => !empty($api['official']),
            'enabled' => !array_key_exists('enabled', $api) || filter_var($api['enabled'], FILTER_VALIDATE_BOOLEAN),
        ];
        if (!$public) $item['url'] = $url;
        else unset($item['url']);
        $out[] = $item;
    }
    return $out;
}

function getUserApiRegistry(bool $public = false): array {
    if (!file_exists(API_REGISTRY_FILE)) return [];
    $items = json_decode(file_get_contents(API_REGISTRY_FILE), true) ?: [];
    return normalizeApiRegistryItems($items, $public);
}

function getApiOverrides(): array {
    if (!file_exists(API_OVERRIDES_FILE)) return [];
    $items = json_decode((string)file_get_contents(API_OVERRIDES_FILE), true);
    return is_array($items) ? $items : [];
}

function saveApiOverrides(array $items): void {
    if (!is_dir(dirname(API_OVERRIDES_FILE))) mkdir(dirname(API_OVERRIDES_FILE), 0755, true);
    file_put_contents(API_OVERRIDES_FILE, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function applyApiOverrides(array $items, bool $includeDisabled = false): array {
    $overrides = getApiOverrides();
    $out = [];
    foreach ($items as $api) {
        $id = $api['id'] ?? '';
        $ov = is_string($id) && isset($overrides[$id]) && is_array($overrides[$id]) ? $overrides[$id] : [];
        $enabled = !array_key_exists('enabled', $ov) || filter_var($ov['enabled'], FILTER_VALIDATE_BOOLEAN);
        if (!$enabled && !$includeDisabled) continue;
        foreach (['name','tipo','color','grupo','menu'] as $key) {
            if (isset($ov[$key]) && trim((string)$ov[$key]) !== '') $api[$key] = $ov[$key];
        }
        $api['enabled'] = $enabled;
        $out[] = $api;
    }
    return $out;
}

function getApiRegistry(bool $public = false): array {
    return array_merge(
        applyApiOverrides(normalizeApiRegistryItems(officialApiLayers(), $public)),
        getUserApiRegistry($public)
    );
}

function getAdminApiRegistry(bool $public = false): array {
    return array_merge(
        applyApiOverrides(normalizeApiRegistryItems(officialApiLayers(), $public), true),
        getUserApiRegistry($public)
    );
}

function saveApiRegistry(array $items): void {
    if (!is_dir(dirname(API_REGISTRY_FILE))) mkdir(dirname(API_REGISTRY_FILE), 0755, true);
    $userItems = array_values(array_filter($items, fn($api) => empty($api['official'])));
    file_put_contents(API_REGISTRY_FILE, json_encode($userItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function envValue(string $name): string {
    $value = getenv($name);
    if ($value === false && isset($_ENV[$name])) $value = $_ENV[$name];
    if ($value === false && isset($_SERVER[$name])) $value = $_SERVER[$name];
    return trim((string)($value === false ? '' : $value));
}

function splitOpenAIKeys(string $value): array {
    if ($value === '') return [];
    $parts = preg_split('/[,\s;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($parts) ? array_map('trim', $parts) : [];
}

function getOpenAIKeys(): array {
    $keys = [];
    foreach (['OPENAI_API_KEYS', 'OPENAI_API_KEY', 'OPENAI_API_KEY_1', 'OPENAI_API_KEY_2'] as $name) {
        $keys = array_merge($keys, splitOpenAIKeys(envValue($name)));
    }
    if (!$keys && is_file(OPENAI_KEY_FILE)) {
        $keys = splitOpenAIKeys(trim((string)file_get_contents(OPENAI_KEY_FILE)));
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

function getOpenAIKey(?string $seed = null): string {
    $keys = getOpenAIKeys();
    $count = count($keys);
    if ($count === 0) return '';
    if ($count === 1) return (string)$keys[0];
    $basis = $seed ?? (($_SERVER['REMOTE_ADDR'] ?? 'local') . '|' . (string)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)));
    $index = (int)(sprintf('%u', crc32($basis)) % $count);
    return (string)$keys[$index];
}

// ── Capas GeoJSON disponibles ──
define('CAPAS', json_encode([
    ['id'=>'SET_Operacion',                          'nombre'=>'SET en Operación',            'tipo'=>'point',   'color'=>'#00d4ff','grupo'=>'Subestaciones','activa'=>false],
    ['id'=>'SET_Proyectadas',                        'nombre'=>'SET Proyectadas',             'tipo'=>'point',   'color'=>'#ff9d00','grupo'=>'Subestaciones','activa'=>false],
    ['id'=>'LT_Operacionm',                          'nombre'=>'LT en Operación',             'tipo'=>'line',    'color'=>'#00ff88','grupo'=>'Transmisión',  'activa'=>false],
    ['id'=>'LT_Proyectadas',                         'nombre'=>'LT Proyectadas',              'tipo'=>'line',    'color'=>'#ffcc00','grupo'=>'Transmisión',  'activa'=>false],
    ['id'=>'SED_Esc_1_30000',                        'nombre'=>'SED Escala 1:30 000',         'tipo'=>'point',   'color'=>'#a78bfa','grupo'=>'Distribución', 'activa'=>false],
    ['id'=>'SED_Esc_1_9000',                         'nombre'=>'SED Escala 1:9 000',          'tipo'=>'point',   'color'=>'#818cf8','grupo'=>'Distribución', 'activa'=>false],
    ['id'=>'Salida_MT_SET',                          'nombre'=>'Salidas MT desde SET',        'tipo'=>'point',   'color'=>'#f472b6','grupo'=>'Distribución', 'activa'=>false],
    ['id'=>'Tramo_media_tension',                    'nombre'=>'Tramos Media Tensión',        'tipo'=>'line',    'color'=>'#fb923c','grupo'=>'Distribución', 'activa'=>false],
    ['id'=>'Concesiones_Distribucion_vigente_definitiva', 'nombre'=>'Definitivas',                'tipo'=>'polygon', 'color'=>'#16a34a','grupo'=>'Áreas de concesión / Vigentes','activa'=>false],
    ['id'=>'Concesiones_Distribucion_vigente_rural',      'nombre'=>'Rurales',                    'tipo'=>'polygon', 'color'=>'#06b6d4','grupo'=>'Áreas de concesión / Vigentes','activa'=>false],
    ['id'=>'Concesiones_Distribucion_tramite_definitiva', 'nombre'=>'Definitivas',                'tipo'=>'polygon', 'color'=>'#f59e0b','grupo'=>'Áreas de concesión / En trámite','activa'=>false],
    ['id'=>'Concesiones_Distribucion_tramite_rural',      'nombre'=>'Rurales',                    'tipo'=>'polygon', 'color'=>'#a855f7','grupo'=>'Áreas de concesión / En trámite','activa'=>false],
]));

function normalizeLayerId(string $id): string {
    $id = preg_replace('/\.(geojson|json)(\.gz)?$/i', '', $id);
    $id = trim(pathinfo($id, PATHINFO_FILENAME) ?: $id);
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $id);
}

function inferLayerType(string $id): string {
    $s = strtolower($id);
    if (str_contains($s, 'conces')) return 'polygon';
    if (str_contains($s, 'lt_') || str_contains($s, 'line') || str_contains($s, 'tramo')) return 'line';
    return 'point';
}

function normalizeLocalLayers(array $items): array {
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = normalizeLayerId((string)($item['id'] ?? ''));
        if ($id === '') continue;
        $tipo = in_array(($item['tipo'] ?? ''), ['point','line','polygon'], true) ? $item['tipo'] : inferLayerType($id);
        $out[] = [
            'id' => $id,
            'nombre' => trim((string)($item['nombre'] ?? $id)) ?: $id,
            'tipo' => $tipo,
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($item['color'] ?? '')) ? $item['color'] : '#38bdf8',
            'grupo' => trim((string)($item['grupo'] ?? 'Otras capas')) ?: 'Otras capas',
            'menu' => trim((string)($item['menu'] ?? layerMenuForGroup((string)($item['grupo'] ?? 'Otras capas')))),
            'activa' => filter_var($item['activa'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }
    return $out;
}

function defaultLocalLayers(): array {
    $layers = normalizeLocalLayers(json_decode(CAPAS, true) ?: []);
    $defaults = [
        'SET_Operacion' => ['nombre'=>'SET en Operacion', 'grupo'=>'Subestaciones', 'menu'=>'Electricidad', 'activa'=>true],
        'LT_Operacionm' => ['nombre'=>'LT en Operacion', 'grupo'=>'Transmision', 'menu'=>'Electricidad', 'activa'=>true],
        'SET_Proyectadas' => ['nombre'=>'SET Proyectadas', 'grupo'=>'Subestaciones', 'menu'=>'Electricidad', 'activa'=>false],
        'LT_Proyectadas' => ['nombre'=>'LT Proyectadas', 'grupo'=>'Transmision', 'menu'=>'Electricidad', 'activa'=>false],
        'Tramo_media_tension' => ['nombre'=>'Tramos Media Tension', 'grupo'=>'Distribucion', 'menu'=>'Electricidad', 'activa'=>false],
        'Salida_MT_SET' => ['nombre'=>'Salidas MT desde SET', 'grupo'=>'Distribucion', 'menu'=>'Electricidad', 'activa'=>false],
        'SED_Esc_1_30000' => ['nombre'=>'SED Escala 1:30 000', 'grupo'=>'Distribucion', 'menu'=>'Electricidad', 'activa'=>false],
        'SED_Esc_1_9000' => ['nombre'=>'SED Escala 1:9 000', 'grupo'=>'Distribucion', 'menu'=>'Electricidad', 'activa'=>false],
        'Concesiones_Distribucion_vigente_definitiva' => ['nombre'=>'Definitivas', 'tipo'=>'polygon', 'color'=>'#16a34a', 'grupo'=>'Áreas de concesión / Vigentes', 'menu'=>'Electricidad', 'activa'=>false],
        'Concesiones_Distribucion_vigente_rural' => ['nombre'=>'Rurales', 'tipo'=>'polygon', 'color'=>'#06b6d4', 'grupo'=>'Áreas de concesión / Vigentes', 'menu'=>'Electricidad', 'activa'=>false],
        'Concesiones_Distribucion_tramite_definitiva' => ['nombre'=>'Definitivas', 'tipo'=>'polygon', 'color'=>'#f59e0b', 'grupo'=>'Áreas de concesión / En trámite', 'menu'=>'Electricidad', 'activa'=>false],
        'Concesiones_Distribucion_tramite_rural' => ['nombre'=>'Rurales', 'tipo'=>'polygon', 'color'=>'#a855f7', 'grupo'=>'Áreas de concesión / En trámite', 'menu'=>'Electricidad', 'activa'=>false],
    ];
    foreach ($layers as &$layer) {
        $id = (string)($layer['id'] ?? '');
        if (isset($defaults[$id])) $layer = array_merge($layer, $defaults[$id]);
    }
    unset($layer);
    return $layers;
}

function localLayerPath(string $id): ?string {
    $id = normalizeLayerId($id);
    $candidates = [];
    foreach ([DATA_DIR, CACHE_DIR] as $dir) {
        foreach (['geojson','json','geojson.gz','json.gz'] as $ext) {
            $path = $dir . '/' . $id . '.' . $ext;
            if (is_file($path)) $candidates[] = $path;
        }
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $file) {
                if ($file === '.' || $file === '..' || $file === 'manifest.json') continue;
                if (!preg_match('/\.(geojson|json)(\.gz)?$/i', $file)) continue;
                $fileId = normalizeLayerId(preg_replace('/\.(geojson|json)(\.gz)?$/i', '', $file));
                if ($fileId === $id) {
                    $path = $dir . '/' . $file;
                    if (is_file($path)) $candidates[] = $path;
                }
            }
        }
    }
    if (!$candidates) return null;
    $candidates = array_values(array_unique($candidates));
    usort($candidates, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $candidates[0];
}

function getLocalLayerRegistry(bool $onlyExisting = true): array {
    $layers = [];
    foreach (defaultLocalLayers() as $layer) $layers[$layer['id']] = $layer;

    if (is_file(LAYER_REGISTRY_FILE)) {
        $custom = json_decode((string)file_get_contents(LAYER_REGISTRY_FILE), true);
        foreach (normalizeLocalLayers(is_array($custom) ? $custom : []) as $layer) {
            $layers[$layer['id']] = array_merge($layers[$layer['id']] ?? [], $layer);
        }
    }

    if (is_dir(DATA_DIR)) {
        foreach (scandir(DATA_DIR) ?: [] as $file) {
            if ($file === '.' || $file === '..') continue;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['geojson','json'], true)) continue;
            $id = normalizeLayerId($file);
            if (!isset($layers[$id])) {
                $layers[$id] = [
                    'id'=>$id,
                    'nombre'=>str_replace('_', ' ', $id),
                    'tipo'=>inferLayerType($id),
                    'color'=>'#38bdf8',
                    'grupo'=>'Otras capas',
                    'menu'=>'Electricidad',
                    'activa'=>false,
                ];
            }
        }
    }
    if (is_dir(CACHE_DIR)) {
        foreach (scandir(CACHE_DIR) ?: [] as $file) {
            if ($file === '.' || $file === '..') continue;
            if (!preg_match('/\.geojson(\.gz)?$/i', $file)) continue;
            $id = normalizeLayerId(preg_replace('/\.geojson(\.gz)?$/i', '', $file));
            // Los GeoJSON generados por el proxy oficial son archivos de caché,
            // no capas locales administrables. Sus nombres pueden incluir el
            // número de capa, la estrategia de consulta y el hash del bbox.
            if (preg_match('/^osinergmin_/i', $id)) continue;
            if (!isset($layers[$id])) {
                $layers[$id] = [
                    'id'=>$id,
                    'nombre'=>str_replace('_', ' ', $id),
                    'tipo'=>inferLayerType($id),
                    'color'=>'#38bdf8',
                    'grupo'=>'Otras capas',
                    'menu'=>'Electricidad',
                    'activa'=>false,
                ];
            }
        }
    }

    $forcedDefaults = [
        'MIDAGRI_Departamentos' => [
            'id'=>'MIDAGRI_Departamentos',
            'nombre'=>'Limites departamentales',
            'tipo'=>'polygon',
            'color'=>'#84cc16',
            'grupo'=>'Departamentos',
            'menu'=>'Agricola y Acuicultura',
            'activa'=>true,
        ],
    ];
    foreach ($forcedDefaults as $id => $forced) {
        if (localLayerPath($id) !== null) {
            $layers[$id] = array_merge($layers[$id] ?? [], $forced);
        }
    }

    $concessionVisualLayers = [
        'Concesiones_Distribucion_vigente_definitiva' => ['id'=>'Concesiones_Distribucion_vigente_definitiva', 'nombre'=>'Definitivas', 'tipo'=>'polygon', 'color'=>'#16a34a', 'grupo'=>'Áreas de concesión / Vigentes', 'menu'=>'Electricidad', 'activa'=>false],
        'Concesiones_Distribucion_vigente_rural' => ['id'=>'Concesiones_Distribucion_vigente_rural', 'nombre'=>'Rurales', 'tipo'=>'polygon', 'color'=>'#06b6d4', 'grupo'=>'Áreas de concesión / Vigentes', 'menu'=>'Electricidad', 'activa'=>false],
        'Concesiones_Distribucion_tramite_definitiva' => ['id'=>'Concesiones_Distribucion_tramite_definitiva', 'nombre'=>'Definitivas', 'tipo'=>'polygon', 'color'=>'#f59e0b', 'grupo'=>'Áreas de concesión / En trámite', 'menu'=>'Electricidad', 'activa'=>false],
        'Concesiones_Distribucion_tramite_rural' => ['id'=>'Concesiones_Distribucion_tramite_rural', 'nombre'=>'Rurales', 'tipo'=>'polygon', 'color'=>'#a855f7', 'grupo'=>'Áreas de concesión / En trámite', 'menu'=>'Electricidad', 'activa'=>false],
    ];
    $hasSplitConcessionLayers = false;
    foreach ($concessionVisualLayers as $id => $visualLayer) {
        if (localLayerPath($id) !== null) {
            $hasSplitConcessionLayers = true;
            $layers[$id] = array_merge($layers[$id] ?? [], $visualLayer);
        }
    }
    if ($hasSplitConcessionLayers) {
        unset($layers['Concesiones_Distribucion_vigente'], $layers['Concesiones_Distribucion_tramite']);
    }
    $out = array_values($layers);
    if ($onlyExisting) {
        $out = array_values(array_filter($out, fn($layer) => localLayerPath($layer['id']) !== null));
    }
    usort($out, fn($a, $b) => strcmp(($a['menu'] ?? '') . ($a['grupo'] ?? '') . ($a['nombre'] ?? ''), ($b['menu'] ?? '') . ($b['grupo'] ?? '') . ($b['nombre'] ?? '')));
    return $out;
}

function saveLocalLayerRegistry(array $items): void {
    if (!is_dir(dirname(LAYER_REGISTRY_FILE))) mkdir(dirname(LAYER_REGISTRY_FILE), 0755, true);
    file_put_contents(LAYER_REGISTRY_FILE, json_encode(normalizeLocalLayers($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function knownLayerGroups(): array {
    $extraKnownGroups = ['Ambiental','AMBIENTAL','ANP y ecosistemas fragiles','Ecosistemas','Areas naturales protegidas','Sitios arqueologicos'];
    $groups = ['Áreas de concesión','Distribución','Subestaciones','Transmisión','API OSINERGMIN','MIDAGRI','Departamentos','API MIDAGRI','PRODUCE','API PRODUCE','APIs de predios','SUNARP BGR','CIRA - Coordenada referencial','Zonas arqueológicas','Otras capas'];
    foreach ($extraKnownGroups as $extraGroup) $groups[] = $extraGroup;
    foreach (array_merge(getLocalLayerRegistry(false), getAdminApiRegistry(true)) as $item) {
        $g = trim((string)($item['grupo'] ?? ''));
        if ($g !== '') $groups[] = $g;
    }
    $groups = array_values(array_unique($groups));
    sort($groups, SORT_NATURAL | SORT_FLAG_CASE);
    return $groups;
}

function knownLayerMenus(): array {
    $extraKnownMenus = ['Ambiental'];
    $menus = ['Electricidad','Ambiental','Agricola y Acuicultura','Predios','CIRAS aprobados','Zonas Arqueologicas','Otros'];
    foreach ($extraKnownMenus as $extraMenu) $menus[] = $extraMenu;
    foreach (array_merge(getLocalLayerRegistry(false), getAdminApiRegistry(true)) as $item) {
        $m = trim((string)($item['menu'] ?? ''));
        if ($m !== '') $menus[] = $m;
    }
    $menus = array_values(array_unique($menus));
    sort($menus, SORT_NATURAL | SORT_FLAG_CASE);
    return $menus;
}

// ── Subestaciones mineras (12 puntos verificados) ──
define('SUBS', json_encode([
    ['id'=>1, 'nombre'=>'S.E. Antamina 220/23 kV',    'empresa'=>'Cía. Minera Antamina S.A.',             'lat'=>-9.5653,  'lng'=>-77.0363,'tension'=>'220/23 kV',  'potencia'=>120,'fp'=>0.95,'region'=>'Áncash',       'provincia'=>'Huari'         ],
    ['id'=>2, 'nombre'=>'S.E. Cerro Verde 220/33 kV', 'empresa'=>'Soc. Minera Cerro Verde S.A.A.',        'lat'=>-16.5528, 'lng'=>-71.6020,'tension'=>'220/33 kV',  'potencia'=>250,'fp'=>0.96,'region'=>'Arequipa',     'provincia'=>'Arequipa'      ],
    ['id'=>3, 'nombre'=>'S.E. Antapaccay/Tintaya',    'empresa'=>'Cía. Minera Antapaccay (Glencore)',     'lat'=>-14.8839, 'lng'=>-71.3133,'tension'=>'220/138 kV', 'potencia'=>100,'fp'=>0.95,'region'=>'Cusco',        'provincia'=>'Espinar'       ],
    ['id'=>4, 'nombre'=>'S.E. Las Bambas 220/66 kV',  'empresa'=>'Minera Las Bambas S.A. (MMG)',          'lat'=>-14.0893, 'lng'=>-72.3356,'tension'=>'220/66 kV',  'potencia'=>130,'fp'=>0.97,'region'=>'Apurímac',    'provincia'=>'Cotabambas'    ],
    ['id'=>5, 'nombre'=>'S.E. Toquepala 138/33 kV',   'empresa'=>'Southern Peru Copper Corp.',            'lat'=>-17.2388, 'lng'=>-70.6157,'tension'=>'138/33 kV',  'potencia'=>80, 'fp'=>0.90,'region'=>'Tacna',        'provincia'=>'Jorge Basadre' ],
    ['id'=>6, 'nombre'=>'S.E. Cuajone 138/33 kV',     'empresa'=>'Southern Peru Copper Corp.',            'lat'=>-17.0200, 'lng'=>-70.8310,'tension'=>'138/33 kV',  'potencia'=>75, 'fp'=>0.93,'region'=>'Moquegua',    'provincia'=>'Mariscal Nieto'],
    ['id'=>7, 'nombre'=>'S.E. Yanacocha 138/33 kV',   'empresa'=>'Minera Yanacocha (Newmont)',            'lat'=>-6.9729,  'lng'=>-78.4979,'tension'=>'138/33 kV',  'potencia'=>40, 'fp'=>0.88,'region'=>'Cajamarca',   'provincia'=>'Cajamarca'     ],
    ['id'=>8, 'nombre'=>'S.E. Uchuchacua 138 kV',     'empresa'=>'Cía. de Minas Buenaventura',           'lat'=>-10.6180, 'lng'=>-76.6876,'tension'=>'138 kV',     'potencia'=>35, 'fp'=>0.87,'region'=>'Lima',         'provincia'=>'Oyón'          ],
    ['id'=>9, 'nombre'=>'S.E. Paragsha 2 220/138 kV', 'empresa'=>'Nexa Resources Perú S.A.A.',           'lat'=>-10.6747, 'lng'=>-76.2657,'tension'=>'220/138 kV', 'potencia'=>90, 'fp'=>0.92,'region'=>'Pasco',        'provincia'=>'Pasco'         ],
    ['id'=>10,'nombre'=>'S.E. Marcona 500/220 kV',    'empresa'=>'Shougang Hierro Perú S.A.A.',          'lat'=>-15.3585, 'lng'=>-75.1669,'tension'=>'500/220 kV', 'potencia'=>180,'fp'=>0.94,'region'=>'Ica',          'provincia'=>'Nazca'         ],
    ['id'=>11,'nombre'=>'S.E. Constancia 138/33 kV',  'empresa'=>'Hudbay Minerals Inc.',                 'lat'=>-14.4816, 'lng'=>-71.7993,'tension'=>'138/33 kV',  'potencia'=>55, 'fp'=>0.91,'region'=>'Cusco',        'provincia'=>'Chumbivilcas'  ],
    ['id'=>12,'nombre'=>'S.E. Cobriza 220 kV',        'empresa'=>'Doe Run Perú',                         'lat'=>-12.5930, 'lng'=>-74.3719,'tension'=>'220 kV',     'potencia'=>60, 'fp'=>0.89,'region'=>'Huancavelica','provincia'=>'Churcampa'     ],
]));

// ── Helpers ──
function getManifest(): array {
    if (!file_exists(MANIFEST_FILE)) return [];
    return json_decode(file_get_contents(MANIFEST_FILE), true) ?? [];
}
function saveManifest(array $m): void {
    if (!is_dir(EXCEL_DIR)) mkdir(EXCEL_DIR, 0755, true);
    file_put_contents(MANIFEST_FILE, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function clearAnalysisCaches(string $scope = 'results', ?string $changedFile = null): void {
    if (!is_dir(CACHE_DIR)) return;

    foreach (scandir(CACHE_DIR) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = CACHE_DIR . '/' . $file;
        if (is_file($path) && (str_starts_with($file, 'analysis_') || str_starts_with($file, 'line_load_'))) {
            @unlink($path);
        }
    }

    if ($scope !== 'quince' && $scope !== 'all') return;

    $idxDir = CACHE_DIR . '/analysis_index';
    if (!is_dir($idxDir)) return;
    $changedName = $changedFile ? basename($changedFile) : null;
    foreach (scandir($idxDir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $idxDir . '/' . $file;
        if (!is_file($path) || !preg_match('/\.(json|tmp)$/', $file)) continue;
        if ($scope === 'all' || str_ends_with($file, '.tmp')) {
            @unlink($path);
            continue;
        }

        $cached = json_decode((string)@file_get_contents($path), true);
        $sourceName = is_array($cached) ? basename((string)($cached['archivo'] ?? '')) : '';
        $sourcePath = $sourceName !== '' ? EXCEL_DIR . '/QUINCEMINUTALES EXCEL/' . $sourceName : '';
        $source = is_array($cached['source'] ?? null) ? $cached['source'] : [];
        $stale = $sourceName === ''
            || !is_file($sourcePath)
            || (int)($source['size'] ?? -1) !== (int)@filesize($sourcePath)
            || (int)($source['mtime'] ?? -1) !== (int)@filemtime($sourcePath);
        if (($changedName !== null && strcasecmp($sourceName, $changedName) === 0) || $stale) {
            @unlink($path);
        }
    }
}
function formatBytes(int $b): string {
    if ($b >= 1048576) return round($b/1048576,1).' MB';
    if ($b >= 1024)    return round($b/1024,1).' KB';
    return $b.' B';
}
function jsonOut(array $d, int $c = 200): void {
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false) {
        $json = json_encode([
            'status'=>'error',
            'ok'=>false,
            'msg'=>'No se pudo serializar la respuesta JSON.',
            'json_error'=>json_last_error_msg(),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    echo $json;
    exit;
}

function authLoadUsers(): array {
    if (!is_file(AUTH_DB_FILE)) return ['version'=>1, 'users'=>[]];
    $data = json_decode((string)file_get_contents(AUTH_DB_FILE), true);
    if (!is_array($data)) return ['version'=>1, 'users'=>[]];
    $data['users'] = is_array($data['users'] ?? null) ? $data['users'] : [];
    return $data;
}

function authSaveUsers(array $data): void {
    $dir = dirname(AUTH_DB_FILE);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $data['version'] = 1;
    $data['updated_at'] = date('c');
    file_put_contents(AUTH_DB_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function authNormalizeUsername(string $username): string {
    return strtolower(trim($username));
}

function authUser(string $username): ?array {
    $username = authNormalizeUsername($username);
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) return null;
    $db = authLoadUsers();
    $user = $db['users'][$username] ?? null;
    return is_array($user) ? $user + ['username'=>$username] : null;
}

function authVerify(string $username, string $password): ?array {
    $user = authUser($username);
    if (!$user || empty($user['active'])) return null;
    $hash = (string)($user['password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) return null;
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $db = authLoadUsers();
        $key = authNormalizeUsername($username);
        if (isset($db['users'][$key])) {
            $db['users'][$key]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            authSaveUsers($db);
        }
    }
    return $user;
}

function authLogin(array $user): void {
    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = in_array(($user['role'] ?? 'viewer'), ['admin','viewer'], true) ? $user['role'] : 'viewer';
    $_SESSION['guest'] = false;
    $_SESSION['login_at'] = time();
}

function authLoginGuest(): void {
    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    $_SESSION['username'] = 'invitado';
    $_SESSION['role'] = 'viewer';
    $_SESSION['guest'] = true;
    $_SESSION['login_at'] = time();
}

function authThrottleKey(string $username): string {
    return 'auth_fail_' . sha1(authNormalizeUsername($username) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'local'));
}

function authTooManyAttempts(string $username): bool {
    $row = $_SESSION[authThrottleKey($username)] ?? ['count'=>0, 'until'=>0];
    return is_array($row) && (int)($row['until'] ?? 0) > time();
}

function authRegisterFailure(string $username): void {
    $key = authThrottleKey($username);
    $row = is_array($_SESSION[$key] ?? null) ? $_SESSION[$key] : ['count'=>0, 'until'=>0];
    $count = (int)($row['count'] ?? 0) + 1;
    $_SESSION[$key] = [
        'count'=>$count,
        'until'=>$count >= 5 ? time() + min(900, 60 * ($count - 4)) : 0,
    ];
}

function authClearFailures(string $username): void {
    unset($_SESSION[authThrottleKey($username)]);
}

function csrfToken(): string {
    if (empty($_SESSION['_csrf_token'])) $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '"/>';
}

function csrfUrl(string $url): string {
    return $url . (str_contains($url, '?') ? '&' : '?') . '_csrf=' . rawurlencode(csrfToken());
}

function verifyCsrfToken(?string $token): bool {
    return is_string($token) && $token !== '' && hash_equals((string)($_SESSION['_csrf_token'] ?? ''), $token);
}

function requestCsrfToken(): ?string {
    return $_POST['_csrf'] ?? $_GET['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
}

function requireCsrf(): void {
    if (!verifyCsrfToken(requestCsrfToken())) {
        jsonOut(['ok'=>false, 'msg'=>'Token de seguridad inválido. Recargue la página e intente nuevamente.'], 403);
    }
}

function isPrivateHost(string $host): bool {
    $host = strtolower(trim($host, "[] \t\r\n"));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) return true;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        return filter_var($host, FILTER_VALIDATE_IP, $flags) === false;
    }
    if (preg_match('/(^|\.)local$|(^|\.)internal$|(^|\.)lan$/i', $host)) return true;
    $ips = @gethostbynamel($host) ?: [];
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return true;
    }
    return false;
}

function isSafeExternalUrl(string $url): bool {
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = (string)($parts['host'] ?? '');
    if (!in_array($scheme, ['http','https'], true) || $host === '') return false;
    if (isPrivateHost($host)) return false;
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function isAuth(): bool {
    if (empty($_SESSION['logged_in']) || empty($_SESSION['username'])) return false;
    if (!empty($_SESSION['guest']) && ($_SESSION['username'] ?? '') === 'invitado') {
        $_SESSION['role'] = 'viewer';
        return true;
    }
    $user = authUser((string)$_SESSION['username']);
    if (!$user || empty($user['active'])) {
        $_SESSION = [];
        return false;
    }
    $_SESSION['role'] = in_array(($user['role'] ?? 'viewer'), ['admin','viewer'], true) ? $user['role'] : 'viewer';
    return true;
}
function requireAuth(): void {
    if (!isAuth()) { header('Location: index.php'); exit; }
}
function currentRole(): string { return $_SESSION['role'] ?? 'viewer'; }
function isAdmin(): bool { return currentRole() === 'admin'; }
function requireAdmin(): void {
    requireAuth();
    if (!isAdmin()) { http_response_code(403); echo 'No autorizado'; exit; }
}

<?php
/**
 * api/zone_report.php - Genera informe DOCX/PDF de una zona evaluada.
 */
require_once dirname(__DIR__) . '/config.php';
@set_time_limit(300);

if (!isAuth()) { http_response_code(401); echo 'No autorizado'; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrf();

define('ZONE_ANALYSIS_LIB_ONLY', true);
require_once __DIR__ . '/zone_analysis.php';

$raw = file_get_contents('php://input');
$input = $raw ? json_decode($raw, true) : $_POST;
if (!is_array($input)) { http_response_code(400); echo 'Solicitud inválida'; exit; }

$format = strtolower((string)($input['format'] ?? 'pdf'));
if (!in_array($format, ['docx', 'pdf'], true)) { http_response_code(400); echo 'Formato no permitido'; exit; }

$points = zNormalizePolygon($input['polygon'] ?? []);
if (count($points) < 3) { http_response_code(400); echo 'Polígono inválido'; exit; }

$demandKw = zNum($input['demanda_kw'] ?? null);
$useAi = filter_var($input['use_ai'] ?? true, FILTER_VALIDATE_BOOLEAN);

try {
    $analysis = zAnalyzePolygonCached($points, $demandKw, $useAi);
    if (!is_dir(REPORT_DIR)) mkdir(REPORT_DIR, 0755, true);
    $stamp = date('Ymd_His') . '_' . substr(sha1(json_encode($points) . microtime(true)), 0, 8);
    $base = REPORT_DIR . '/informe_zona_' . $stamp;
    $jsonFile = $base . '.json';
    $docxFile = $base . '.docx';
    $pdfFile = $base . '.pdf';
    $payload = [
        'analysis'=>$analysis,
        'polygon'=>$points,
        'meta'=>[
            'project'=>trim((string)($input['project'] ?? 'Evaluación preliminar de zona eléctrica')),
            'client'=>trim((string)($input['client'] ?? '')),
            'author'=>trim((string)($input['author'] ?? ($_SESSION['username'] ?? 'CVC Energía'))),
            'template'=>ZONE_REPORT_TEMPLATE,
            'template_pdf'=>defined('ZONE_REPORT_TEMPLATE_PDF') ? ZONE_REPORT_TEMPLATE_PDF : '',
            'preserve_template_body'=>true,
            'report_kind'=>'zone',
        ],
    ];
    file_put_contents($jsonFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $cmd = escapeshellarg(DOC_PYTHON_BIN) . ' ' . escapeshellarg(ZONE_REPORT_BUILDER)
        . ' --input ' . escapeshellarg($jsonFile)
        . ($format === 'docx' ? ' --out-docx ' . escapeshellarg($docxFile) : ' --out-pdf ' . escapeshellarg($pdfFile))
        . ' 2>&1';
    $out = @shell_exec($cmd);
    $file = $format === 'docx' ? $docxFile : $pdfFile;
    if (!is_file($file) || filesize($file) <= 0) {
        throw new RuntimeException('No se pudo generar el informe. ' . substr((string)$out, 0, 500));
    }
    $download = basename($file);
    header('Content-Type: ' . ($format === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf'));
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: attachment; filename="' . $download . '"');
    readfile($file);
} catch (Throwable $e) {
    http_response_code(500);
    echo $e->getMessage();
}

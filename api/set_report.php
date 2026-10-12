<?php
/**
 * api/set_report.php - Exporta análisis de disponibilidad de SET a PDF/XLSX.
 */
require_once dirname(__DIR__) . '/config.php';
@set_time_limit(300);
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

if (!isAuth()) { http_response_code(401); echo 'No autorizado'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Metodo no permitido'; exit; }
requireCsrf();

$raw = file_get_contents('php://input');
$input = $raw ? json_decode($raw, true) : [];
if (!is_array($input)) { http_response_code(400); echo 'Solicitud inválida'; exit; }

$format = strtolower((string)($input['format'] ?? 'pdf'));
if (!in_array($format, ['pdf', 'xlsx', 'docx'], true)) { http_response_code(400); echo 'Formato no permitido'; exit; }

$analysis = $input['analysis'] ?? null;
if (!is_array($analysis)) { http_response_code(400); echo 'Analisis no recibido'; exit; }

function setReportNamePart($value): string {
    $text = trim((string)$value);
    if ($text === '') return 'set';
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $text));
    return trim($text, '_') ?: 'set';
}

try {
    if (!is_dir(REPORT_DIR)) mkdir(REPORT_DIR, 0755, true);
    $analysis['generated_at'] = date('Y-m-d H:i:s');
    $analysis['template'] = defined('ZONE_REPORT_TEMPLATE') ? ZONE_REPORT_TEMPLATE : '';
    $analysis['template_pdf'] = defined('ZONE_REPORT_TEMPLATE_PDF') ? ZONE_REPORT_TEMPLATE_PDF : '';
    $analysis['author'] = $_SESSION['username'] ?? 'CVC Energia';
    $name = setReportNamePart($analysis['subestacion']['nombre'] ?? 'set');
    $stamp = date('Ymd_His') . '_' . substr(sha1(json_encode($analysis) . microtime(true)), 0, 8);
    $base = REPORT_DIR . '/disponibilidad_set_' . $name . '_' . $stamp;
    $jsonFile = $base . '.json';
    $outFile = $base . '.' . $format;
    file_put_contents($jsonFile, json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));

    $switch = ' --out-pdf ';
    if ($format === 'xlsx') $switch = ' --out-xlsx ';
    if ($format === 'docx') $switch = ' --out-docx ';

    $cmd = escapeshellarg(DOC_PYTHON_BIN) . ' ' . escapeshellarg(SET_REPORT_BUILDER)
        . ' --input ' . escapeshellarg($jsonFile)
        . $switch . escapeshellarg($outFile)
        . ' 2>&1';
    $out = @shell_exec($cmd);
    if (!is_file($outFile) || filesize($outFile) <= 0) {
        throw new RuntimeException('No se pudo generar el reporte de disponibilidad. ' . substr((string)$out, 0, 600));
    }

    while (ob_get_level()) ob_end_clean();
    if ($format === 'pdf') {
        header('Content-Type: application/pdf');
    } elseif ($format === 'xlsx') {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    } else {
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
    header('Content-Length: ' . filesize($outFile));
    header('Content-Disposition: attachment; filename="' . basename($outFile) . '"');
    readfile($outFile);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}

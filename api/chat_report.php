<?php
/**
 * api/chat_report.php - Genera PDF de una respuesta del asistente IA territorial.
 */
require_once dirname(__DIR__) . '/config.php';
@set_time_limit(180);

if (!isAuth()) { http_response_code(401); echo 'No autorizado'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Metodo no permitido'; exit; }
requireCsrf();

$raw = file_get_contents('php://input');
$input = $raw ? json_decode($raw, true) : [];
if (!is_array($input)) { http_response_code(400); echo 'Solicitud inválida'; exit; }

function chatReportText($value, int $max = 12000): string {
    $text = trim((string)$value);
    $text = preg_replace('/\r\n?/', "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
}

try {
    $answer = chatReportText($input['answer'] ?? '');
    if ($answer === '') throw new RuntimeException('No hay respuesta para exportar.');
    if (!is_dir(REPORT_DIR)) mkdir(REPORT_DIR, 0755, true);
    $stamp = date('Ymd_His') . '_' . substr(sha1($answer . microtime(true)), 0, 8);
    $base = REPORT_DIR . '/chat_ia_' . $stamp;
    $jsonFile = $base . '.json';
    $pdfFile = $base . '.pdf';
    $payload = [
        'title'=>chatReportText($input['title'] ?? 'Informe del Asistente IA territorial', 300),
        'question'=>chatReportText($input['question'] ?? '', 4000),
        'answer'=>$answer,
        'generated_at'=>date('Y-m-d H:i:s'),
        'user'=>$_SESSION['username'] ?? '',
        'opportunities'=>is_array($input['opportunities'] ?? null) ? array_slice($input['opportunities'], 0, 12) : [],
        'viewer'=>is_array($input['viewer'] ?? null) ? $input['viewer'] : [],
        'web'=>is_array($input['web'] ?? null) ? $input['web'] : [],
        'sources'=>is_array($input['sources'] ?? null) ? array_slice($input['sources'], 0, 12) : [],
    ];
    file_put_contents($jsonFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $builder = ROOT . '/scripts/chat_report_builder.py';
    $cmd = escapeshellarg(DOC_PYTHON_BIN) . ' ' . escapeshellarg($builder)
        . ' --input ' . escapeshellarg($jsonFile)
        . ' --out-pdf ' . escapeshellarg($pdfFile)
        . ' 2>&1';
    $out = @shell_exec($cmd);
    if (!is_file($pdfFile) || filesize($pdfFile) <= 0) {
        throw new RuntimeException('No se pudo generar el PDF del chat. ' . substr((string)$out, 0, 600));
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($pdfFile));
    header('Content-Disposition: attachment; filename="' . basename($pdfFile) . '"');
    readfile($pdfFile);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}

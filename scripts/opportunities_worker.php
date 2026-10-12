<?php
/**
 * Construye en segundo plano el cache de oportunidades territoriales.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('ZONE_ANALYSIS_LIB_ONLY', true);
require_once dirname(__DIR__) . '/api/zone_analysis.php';

@ini_set('memory_limit', '1536M');
@set_time_limit(0);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$jobArg = (string)($argv[1] ?? '');
$jobPath = realpath($jobArg);
$jobDir = realpath(zOpportunityJobDir());
if (!$jobPath || !$jobDir || !str_starts_with($jobPath, $jobDir . DIRECTORY_SEPARATOR)) exit(2);

$lockPath = $jobPath . '.lock';
$job = json_decode((string)@file_get_contents($jobPath), true);
if (!is_array($job)) {
    @unlink($lockPath);
    exit(3);
}
$key = (string)($job['key'] ?? '');
$payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
$filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
$useAi = !empty($payload['use_ai']);
if (!preg_match('/^[a-f0-9]{40}$/', $key)) {
    @unlink($lockPath);
    exit(4);
}

try {
    $job['status'] = 'running';
    $job['started_at'] = date('c');
    @file_put_contents($jobPath, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    $result = zAnalyzeOpportunities($useAi, $filters);
    if (!is_array($result) || ($result['status'] ?? 'error') === 'error') {
        throw new RuntimeException((string)($result['msg'] ?? 'El análisis no devolvió un resultado válido.'));
    }
    zAnalysisCacheStore('opportunities', $key, $result);
    $job['status'] = 'done';
    $job['finished_at'] = date('c');
    $job['candidates'] = count($result['candidatos'] ?? []);
    @file_put_contents($jobPath, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @unlink($lockPath);
    exit(0);
} catch (Throwable $e) {
    $job['status'] = 'error';
    $job['finished_at'] = date('c');
    $job['error'] = $e->getMessage();
    @file_put_contents($jobPath, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @unlink($lockPath);
    exit(5);
}

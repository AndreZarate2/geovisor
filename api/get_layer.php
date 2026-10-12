<?php
/**
 * api/get_layer.php — Sirve archivos GeoJSON
 * GET ?id=nombre_capa
 */
require_once dirname(__DIR__) . '/config.php';

// Verificar autenticación
if (!isAuth()) { jsonOut(['error'=>'No autorizado'], 401); }

$id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['id'] ?? '');
if (!$id) { jsonOut(['error'=>'ID de capa requerido'], 400); }

$path = localLayerPath($id);
if (!$path || !file_exists($path)) { jsonOut(['error'=>'Capa no encontrada: '.$id], 404); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

// Compresión GZIP si el cliente la soporta
$ae = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
if (str_ends_with(strtolower($path), '.gz')) {
    if (str_contains($ae, 'gzip')) {
        header('Content-Encoding: gzip');
        readfile($path);
    } else {
        if (function_exists('readgzfile')) {
            readgzfile($path);
        } else {
            echo '{"type":"FeatureCollection","features":[]}';
        }
    }
    exit;
}
if (str_contains($ae, 'gzip')) {
    $gz = CACHE_DIR . '/' . $id . '.' . pathinfo($path, PATHINFO_EXTENSION) . '.gz';
    if (!file_exists($gz) || filemtime($gz) < filemtime($path)) {
        if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
        file_put_contents($gz, gzencode(file_get_contents($path), 5));
    }
    if (file_exists($gz)) {
        header('Content-Encoding: gzip');
        readfile($gz);
        exit;
    }
}
readfile($path);

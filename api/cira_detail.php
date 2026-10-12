<?php
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(60);

if (!isAuth()) jsonOut(['status'=>'error', 'message'=>'No autorizado'], 401);

$id = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? $_POST['id'] ?? ''));
if ($id === '') jsonOut(['status'=>'error', 'message'=>'ID CIRA requerido'], 400);

function ciraFetchDetailHtml(string $id): string {
    $url = 'https://geoportal.cultura.gob.pe/mapa/verInformacionMarker';
    $body = http_build_query(['capa'=>'capa_cira_mrk', 'id'=>$id]);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$body,
            CURLOPT_CONNECTTIMEOUT=>12,
            CURLOPT_TIMEOUT=>45,
            CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],
        ] + appCurlTlsOptions());
        $html = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($html === false || $code < 200 || $code >= 300) {
            $ps = ciraPowerShellPost($id);
            if (is_string($ps) && trim($ps) !== '') return $ps;
            throw new RuntimeException('Cultura no devolvio el detalle CIRA' . ($code ? " (HTTP $code)" : '') . ($err ? ": $err" : ''));
        }
        return (string)$html;
    }
    $ctx = stream_context_create(['http'=>[
        'method'=>'POST',
        'header'=>"Content-Type: application/x-www-form-urlencoded\r\n",
        'content'=>$body,
        'timeout'=>45,
        'ignore_errors'=>true,
    ]]);
    $html = @file_get_contents($url, false, $ctx);
    if (!is_string($html) || trim($html) === '') {
        $ps = ciraPowerShellPost($id);
        if (is_string($ps) && trim($ps) !== '') return $ps;
        throw new RuntimeException('No se pudo consultar el detalle CIRA.');
    }
    return $html;
}

function ciraPowerShellPost(string $id): ?string {
    if (PHP_OS_FAMILY !== 'Windows' || !function_exists('shell_exec')) return null;
    $ps = getenv('SystemRoot') ? getenv('SystemRoot') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe' : 'powershell.exe';
    $script = '[Console]::OutputEncoding=New-Object System.Text.UTF8Encoding $false;'
        . '$ProgressPreference="SilentlyContinue";'
        . '$body=@{capa="capa_cira_mrk";id="' . $id . '"};'
        . '$r=Invoke-WebRequest -Uri "https://geoportal.cultura.gob.pe/mapa/verInformacionMarker" -Method Post -Body $body -UseBasicParsing -TimeoutSec 45;'
        . '$r.Content';
    $encoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $script));
    $cmd = '"' . $ps . '" -NoProfile -ExecutionPolicy Bypass -EncodedCommand ' . $encoded . ' 2>NUL';
    $out = @shell_exec($cmd);
    return is_string($out) && trim($out) !== '' ? $out : null;
}

function ciraPlain(string $value): string {
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $value));
}

function ciraNorm(string $value): string {
    $value = ciraPlain($value);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') $value = $ascii;
    }
    return strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $value)));
}

function ciraExtractPair(string $html, string $label): string {
    $target = ciraNorm($label);
    if (preg_match_all('~<div[^>]*class="[^"]*etiqueta(?:_2)?[^"]*"[^>]*>(.*?)</div>\s*<div[^>]*class="[^"]*info[^"]*"[^>]*>(.*?)</div>~isu', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $found = ciraNorm($m[1] ?? '');
            if ($found === $target || str_contains($found, $target)) return ciraPlain($m[2] ?? '');
        }
    }
    return '';
}

function ciraFindPdfUrl(string $html): string {
    $patterns = [
        '~https?://[^"\']+\.pdf(?:\?[^"\']*)?~i',
        '~(?:https?://[^"\']+)?/app-cira/verDocumentoCiraServlet\?param=[^"\'<>\s]+~i',
        '~verDocumentoCiraServlet\?param=[^"\'<>\s]+~i',
    ];
    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $html, $m)) continue;
        $pdf = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($pdf, '/')) return 'https://aplicaciones.cultura.gob.pe' . $pdf;
        if (!preg_match('~^https?://~i', $pdf)) return 'https://aplicaciones.cultura.gob.pe/app-cira/' . ltrim($pdf, '/');
        return $pdf;
    }
    if (preg_match('~href=["\']([^"\']+)["\']~i', $html, $m)) {
        $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (stripos($href, 'verDocumentoCiraServlet') !== false) {
            if (!preg_match('~^https?://~i', $href)) $href = 'https://aplicaciones.cultura.gob.pe/app-cira/' . ltrim($href, '/');
            return $href;
        }
    }
    if (preg_match('~verDocumentoCiraServlet[^a-z0-9]+([a-z0-9]{8,})~i', $html, $m)) {
        return 'https://aplicaciones.cultura.gob.pe/app-cira/verDocumentoCiraServlet?param=' . $m[1];
    }
    if (preg_match('~param=([a-z0-9]{8,})~i', $html, $m)) {
        return 'https://aplicaciones.cultura.gob.pe/app-cira/verDocumentoCiraServlet?param=' . $m[1];
    }
    return '';
}

try {
    $html = ciraFetchDetailHtml($id);
    $pdf = ciraFindPdfUrl($html);

    $safeHtml = preg_replace('~<script\b[^>]*>.*?</script>~isu', '', $html);
    $safeHtml = preg_replace('~\son[a-z]+\s*=\s*(["\']).*?\1~isu', '', $safeHtml);

    jsonOut([
        'status'=>'success',
        'id'=>$id,
        'region'=>ciraExtractPair($html, 'Region'),
        'provincia'=>ciraExtractPair($html, 'Provincia'),
        'distrito'=>ciraExtractPair($html, 'Distrito'),
        'zona'=>ciraExtractPair($html, 'Zona'),
        'norte'=>ciraExtractPair($html, 'Norte'),
        'este'=>ciraExtractPair($html, 'Este'),
        'nro'=>ciraExtractPair($html, 'Nro'),
        'anio'=>ciraExtractPair($html, 'Ano'),
        'fecha_emision'=>ciraExtractPair($html, 'Fecha de emision'),
        'expediente'=>ciraExtractPair($html, 'Expediente'),
        'fecha_expediente'=>ciraExtractPair($html, 'Fecha de expediente'),
        'proyecto'=>ciraExtractPair($html, 'Proyecto'),
        'pdf_url'=>$pdf,
        'local_pdf_url'=>$pdf !== '' ? 'api/cira_pdf.php?id=' . rawurlencode($id) : '',
        'html'=>$safeHtml,
    ]);
} catch (Throwable $e) {
    jsonOut(['status'=>'error', 'message'=>$e->getMessage()], 500);
}

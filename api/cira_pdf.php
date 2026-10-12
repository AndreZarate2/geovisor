<?php
require_once dirname(__DIR__) . '/config.php';
@set_time_limit(120);

if (!isAuth()) { http_response_code(401); echo 'No autorizado'; exit; }

$id = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? ''));
if ($id === '') { http_response_code(400); echo 'ID CIRA requerido'; exit; }
$param = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_GET['param'] ?? ''));

function ciraPdfUrlFromParam(string $param): string {
    return $param !== '' ? 'https://aplicaciones.cultura.gob.pe/app-cira/verDocumentoCiraServlet?param=' . rawurlencode($param) : '';
}

function ciraPdfFetchDetailHtml(string $id): string {
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
        curl_close($ch);
        if (is_string($html) && trim($html) !== '' && $code >= 200 && $code < 300) return $html;
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
        $ps = getenv('SystemRoot') ? getenv('SystemRoot') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe' : 'powershell.exe';
        $script = '[Console]::OutputEncoding=New-Object System.Text.UTF8Encoding $false;'
            . '$ProgressPreference="SilentlyContinue";'
            . '$body=@{capa="capa_cira_mrk";id="' . $id . '"};'
            . '$r=Invoke-WebRequest -Uri "https://geoportal.cultura.gob.pe/mapa/verInformacionMarker" -Method Post -Body $body -UseBasicParsing -TimeoutSec 45;'
            . '$r.Content';
        $encoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $script));
        $cmd = '"' . $ps . '" -NoProfile -ExecutionPolicy Bypass -EncodedCommand ' . $encoded . ' 2>NUL';
        $html = @shell_exec($cmd);
    }
    if (!is_string($html) || trim($html) === '') throw new RuntimeException('No se pudo consultar el detalle CIRA.');
    return $html;
}

function ciraPdfUrlFromHtml(string $html): string {
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
        return ciraPdfUrlFromParam($m[1]);
    }
    if (preg_match('~param=([a-z0-9]{8,})~i', $html, $m)) {
        return ciraPdfUrlFromParam($m[1]);
    }
    return '';
}

function ciraPdfDownload(string $url): string {
    if (!isSafeExternalUrl($url)) throw new RuntimeException('URL PDF no permitida.');
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $caFile = ROOT . '/certs/cacert.pem';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>true,
            CURLOPT_CONNECTTIMEOUT=>15,
            CURLOPT_TIMEOUT=>90,
            CURLOPT_USERAGENT=>'Mozilla/5.0 CVC GeoVisor CIRA PDF',
            CURLOPT_REFERER=>'https://geoportal.cultura.gob.pe/mapa/portal',
            CURLOPT_HTTPHEADER=>['Accept: application/pdf,application/octet-stream,*/*'],
        ]);
        if (is_file($caFile)) curl_setopt($ch, CURLOPT_CAINFO, $caFile);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) throw new RuntimeException('No se pudo descargar el PDF CIRA.');
        if (!ciraPdfLooksValid((string)$body, $type)) throw new RuntimeException('Cultura devolvio un archivo no PDF para este CIRA.');
        return (string)$body;
    }
    $body = @file_get_contents($url);
    if (!is_string($body) || $body === '' || !ciraPdfLooksValid($body, '')) throw new RuntimeException('No se pudo descargar el PDF CIRA.');
    return $body;
}

function ciraPdfLooksValid(string $body, string $contentType = ''): bool {
    if (str_starts_with($body, '%PDF-')) return true;
    if (stripos($contentType, 'application/pdf') !== false && strlen($body) > 1000) return true;
    return false;
}

function ciraPdfDownloadToFilePowerShell(string $url, string $file): bool {
    if (PHP_OS_FAMILY !== 'Windows' || !function_exists('shell_exec')) return false;
    $ps = getenv('SystemRoot') ? getenv('SystemRoot') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe' : 'powershell.exe';
    $url64 = base64_encode($url);
    $file64 = base64_encode($file);
    $script = '[Console]::OutputEncoding=New-Object System.Text.UTF8Encoding $false;'
        . '$ProgressPreference="SilentlyContinue";'
        . '$u=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $url64 . '"));'
        . '$p=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("' . $file64 . '"));'
        . '$h=@{Accept="application/pdf,application/octet-stream,*/*";Referer="https://geoportal.cultura.gob.pe/mapa/portal";"User-Agent"="Mozilla/5.0 CVC GeoVisor CIRA PDF"};'
        . 'Invoke-WebRequest -Uri $u -OutFile $p -Headers $h -UseBasicParsing -TimeoutSec 90;'
        . 'if ((Test-Path $p) -and ((Get-Item $p).Length -gt 1000)) { $b=[IO.File]::ReadAllBytes($p); if($b.Length -gt 5 -and $b[0] -eq 37 -and $b[1] -eq 80 -and $b[2] -eq 68 -and $b[3] -eq 70){ "ok" } }';
    $encoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $script));
    $cmd = '"' . $ps . '" -NoProfile -ExecutionPolicy Bypass -EncodedCommand ' . $encoded . ' 2>NUL';
    $out = @shell_exec($cmd);
    return is_string($out) && str_contains($out, 'ok') && is_file($file) && filesize($file) > 1000;
}

try {
    if (!is_dir(CIRA_PDF_DIR)) mkdir(CIRA_PDF_DIR, 0755, true);
    $file = CIRA_PDF_DIR . '/cira_' . $id . '.pdf';
    $validCache = is_file($file) && filesize($file) > 1000 && str_starts_with((string)file_get_contents($file, false, null, 0, 5), '%PDF-');
    if (!$validCache) {
        $pdfUrl = ciraPdfUrlFromParam($param);
        if ($pdfUrl === '') $pdfUrl = ciraPdfUrlFromHtml(ciraPdfFetchDetailHtml($id));
        if ($pdfUrl === '') throw new RuntimeException('El detalle CIRA no publico enlace PDF.');
        try {
            $pdf = ciraPdfDownload($pdfUrl);
            file_put_contents($file, $pdf);
        } catch (Throwable $downloadError) {
            if (!ciraPdfDownloadToFilePowerShell($pdfUrl, $file)) throw $downloadError;
        }
    }
    if (!is_file($file) || filesize($file) < 1000 || !str_starts_with((string)file_get_contents($file, false, null, 0, 5), '%PDF-')) {
        throw new RuntimeException('El archivo descargado no es un PDF valido.');
    }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="cira_' . $id . '.pdf"');
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize($file));
    readfile($file);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}

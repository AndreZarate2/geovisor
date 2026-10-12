<?php
require_once __DIR__ . '/config.php';
if (isAuth() && !verifyCsrfToken((string)($_GET['_csrf'] ?? ''))) {
    http_response_code(403);
    echo 'Token de seguridad invalido.';
    exit;
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: index.php');
exit;

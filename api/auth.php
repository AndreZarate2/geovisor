<?php
/**
 * api/auth.php - Endpoint AJAX de autenticacion.
 */
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['ok'=>false,'msg'=>'Metodo no permitido'], 405);
}
requireCsrf();

if (($_POST['guest'] ?? '') === '1') {
    authLoginGuest();
    jsonOut(['ok'=>true,'role'=>'viewer','name'=>'Invitado']);
}

$u = authNormalizeUsername((string)($_POST['usuario'] ?? ''));
$p = (string)($_POST['clave'] ?? '');

if (!preg_match('/^[a-z0-9._-]{3,40}$/', $u) || $p === '') {
    jsonOut(['ok'=>false,'msg'=>'Usuario o contrasena incorrectos'], 401);
}

if (authTooManyAttempts($u)) {
    jsonOut(['ok'=>false,'msg'=>'Demasiados intentos. Espere unos minutos antes de volver a ingresar.'], 429);
}

$user = authVerify($u, $p);
if (!$user) {
    authRegisterFailure($u);
    jsonOut(['ok'=>false,'msg'=>'Usuario o contrasena incorrectos'], 401);
}

authClearFailures($u);
authLogin($user);
jsonOut(['ok'=>true,'role'=>$_SESSION['role'],'name'=>$u]);

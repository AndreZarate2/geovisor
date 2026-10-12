<?php
/**
 * CLI user manager for GeoVisor CVC.
 *
 * Usage:
 *   php scripts/manage_users.php init
 *   php scripts/manage_users.php add admin NewStrongPassword admin
 *   php scripts/manage_users.php add usuario NewStrongPassword viewer
 *   php scripts/manage_users.php password admin NewStrongPassword
 *   php scripts/manage_users.php list
 */
require_once dirname(__DIR__) . '/config.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

function usage(): void {
    echo "Usage:\n";
    echo "  php scripts/manage_users.php init\n";
    echo "  php scripts/manage_users.php add <user> <password> <admin|viewer>\n";
    echo "  php scripts/manage_users.php password <user> <password>\n";
    echo "  php scripts/manage_users.php list\n";
}

function assertPasswordPolicy(string $password): void {
    if (strlen($password) < 10) {
        fwrite(STDERR, "Password must have at least 10 characters.\n");
        exit(1);
    }
}

$cmd = $argv[1] ?? '';
$db = authLoadUsers();
$db['users'] = is_array($db['users'] ?? null) ? $db['users'] : [];

if ($cmd === 'init') {
    if (!empty($db['users'])) {
        echo "User database already exists: " . AUTH_DB_FILE . "\n";
        exit(0);
    }
    $adminPass = (string)getenv('GEOVISOR_INITIAL_ADMIN_PASSWORD');
    $viewerPass = (string)getenv('GEOVISOR_INITIAL_VIEWER_PASSWORD');
    if ($adminPass === '' || $viewerPass === '') {
        fwrite(STDERR, "Set GEOVISOR_INITIAL_ADMIN_PASSWORD and GEOVISOR_INITIAL_VIEWER_PASSWORD before init.\n");
        exit(1);
    }
    assertPasswordPolicy($adminPass);
    assertPasswordPolicy($viewerPass);
    $db['users'] = [
        'admin'=>[
            'password_hash'=>password_hash($adminPass, PASSWORD_DEFAULT),
            'role'=>'admin',
            'active'=>true,
            'created_at'=>date('c'),
        ],
        'usuario'=>[
            'password_hash'=>password_hash($viewerPass, PASSWORD_DEFAULT),
            'role'=>'viewer',
            'active'=>true,
            'created_at'=>date('c'),
        ],
    ];
    authSaveUsers($db);
    echo "User database created: " . AUTH_DB_FILE . "\n";
    echo "Users created with the passwords supplied through environment variables.\n";
    exit(0);
}

if ($cmd === 'list') {
    foreach ($db['users'] as $name => $user) {
        echo $name . "\t" . ($user['role'] ?? 'viewer') . "\t" . (!empty($user['active']) ? 'active' : 'disabled') . "\n";
    }
    exit(0);
}

if ($cmd === 'add') {
    $name = authNormalizeUsername($argv[2] ?? '');
    $password = (string)($argv[3] ?? '');
    $role = (string)($argv[4] ?? 'viewer');
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $name) || !in_array($role, ['admin','viewer'], true)) {
        usage();
        exit(1);
    }
    assertPasswordPolicy($password);
    $db['users'][$name] = [
        'password_hash'=>password_hash($password, PASSWORD_DEFAULT),
        'role'=>$role,
        'active'=>true,
        'created_at'=>$db['users'][$name]['created_at'] ?? date('c'),
        'updated_at'=>date('c'),
    ];
    authSaveUsers($db);
    echo "Saved user: $name\n";
    exit(0);
}

if ($cmd === 'password') {
    $name = authNormalizeUsername($argv[2] ?? '');
    $password = (string)($argv[3] ?? '');
    if (!isset($db['users'][$name])) {
        fwrite(STDERR, "User not found: $name\n");
        exit(1);
    }
    assertPasswordPolicy($password);
    $db['users'][$name]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    $db['users'][$name]['updated_at'] = date('c');
    authSaveUsers($db);
    echo "Password updated: $name\n";
    exit(0);
}

usage();
exit(1);

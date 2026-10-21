<?php
declare(strict_types=1);

// Only the guarded runner owns the initially empty, disposable test schema.
if (!isset($pdo, $root, $host, $port, $name, $user, $password) || !($pdo instanceof PDO)
    || !preg_match('/(?:^|_)test(?:_|$)/i', $name)) {
    throw new RuntimeException('Menu acceptance requires the guarded, real MySQL migration runner.');
}
require_once $root . '/app/Support/functions.php';
require_once $root . '/tests/Feature/navigation_menus.php';
$menuConfigPath = tempnam(sys_get_temp_dir(), 'vvx-menu-db-test-');
if ($menuConfigPath === false) throw new RuntimeException('Unable to create a temporary menu test configuration.');
$menuTimezone = date_default_timezone_get();
try {
    file_put_contents($menuConfigPath, "<?php\nreturn " . var_export(['storage_mode' => 'mysql', 'timezone' => 'UTC',
        'base_url' => 'http://127.0.0.1:8765', 'mail' => ['mode' => 'disabled'],
        'security' => ['app_key' => bin2hex(random_bytes(32))],
        'db' => compact('host', 'port', 'name', 'user', 'password') + ['charset' => 'utf8mb4']], true) . ";\n");
    chmod($menuConfigPath, 0600);
    date_default_timezone_set('UTC');
    $menuChecks = vvx_menu_acceptance(new App\Core\Config($menuConfigPath), $pdo);
    echo "Navigation menu MySQL acceptance passed ({$menuChecks} assertions; independent committed-row readback, validation, stale revisions, owner checks and reset tombstones; server "
        . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ").\n";
} finally {
    date_default_timezone_set($menuTimezone);
    unlink($menuConfigPath);
}

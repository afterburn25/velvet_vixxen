<?php
declare(strict_types=1);

// Invoked after the guarded migration runner created an empty named test database.
if (!isset($pdo, $root, $host, $port, $name, $user, $password) || !($pdo instanceof PDO)
    || !preg_match('/(?:^|_)test(?:_|$)/i', $name)) {
    throw new RuntimeException('Media acceptance requires the guarded, real MySQL migration runner.');
}
require_once $root . '/app/Support/functions.php';
require_once $root . '/tests/Feature/media_center_persistence.php';
$mediaConfigPath = tempnam(sys_get_temp_dir(), 'vvx-media-db-test-');
if ($mediaConfigPath === false) throw new RuntimeException('Unable to create a temporary media test configuration.');
$mediaTimezone = date_default_timezone_get();
try {
    file_put_contents($mediaConfigPath, "<?php\nreturn " . var_export(['storage_mode' => 'mysql', 'timezone' => 'UTC',
        'base_url' => 'http://127.0.0.1:8765', 'mail' => ['mode' => 'disabled'],
        'security' => ['app_key' => bin2hex(random_bytes(32))],
        'db' => compact('host', 'port', 'name', 'user', 'password') + ['charset' => 'utf8mb4']], true) . ";\n");
    chmod($mediaConfigPath, 0600);
    date_default_timezone_set('UTC');
    $mediaChecks = vvx_media_acceptance(new App\Core\Config($mediaConfigPath), $pdo);
    echo "Media Center MySQL persistence passed ({$mediaChecks} assertions; separate connection verifies committed rows, captions/tags/order and due publication; server "
        . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ").\n";
} finally {
    date_default_timezone_set($mediaTimezone);
    unlink($mediaConfigPath);
}

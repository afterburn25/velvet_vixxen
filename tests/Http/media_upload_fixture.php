<?php
declare(strict_types=1);

// Private CLI fixture for the actual extracted-package upload regression.
// Never copied into the served application and never included in a customer ZIP.
$root = realpath((string) ($argv[1] ?? ''));
$action = (string) ($argv[2] ?? '');
if (PHP_SAPI !== 'cli' || $root === false
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    || !is_file($root . '/.vvx-test-runtime')) {
    throw new RuntimeException('A marked disposable temporary runtime is required.');
}

if ($action === 'setup') {
    if (is_file($root . '/config/app.php') || is_file($root . '/storage/data.json')) {
        throw new RuntimeException('Refusing to replace existing fixture configuration or records.');
    }
    $base = rtrim((string) ($argv[3] ?? ''), '/');
    if (parse_url($base, PHP_URL_SCHEME) !== 'http'
        || parse_url($base, PHP_URL_HOST) !== '127.0.0.1') {
        throw new RuntimeException('The fixture requires a localhost HTTP URL.');
    }
    $id = 'upload-fixture-' . bin2hex(random_bytes(8));
    $config = [
        'installed' => true, 'installation_id' => $id,
        'app_name' => 'Velvet Vixxen upload acceptance',
        'version' => trim((string) file_get_contents($root . '/VERSION')),
        'base_url' => $base, 'timezone' => 'UTC', 'currency' => 'USD',
        'support_email' => 'owner@example.test',
        'storage_mode' => 'file', 'file_store_path' => 'storage/data.json',
        'db' => ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'password' => '', 'charset' => 'utf8mb4'],
        'mail' => ['mode' => 'disabled'],
        'security' => [
            'app_key' => bin2hex(random_bytes(32)),
            'signing_key' => bin2hex(random_bytes(32)),
            'media_key' => bin2hex(random_bytes(32)),
        ],
        'paths' => ['storage' => 'storage', 'private' => 'storage/private', 'uploads' => 'uploads',
            'images' => 'images', 'addons' => 'addons', 'templates' => 'templates'],
        'installed_at' => gmdate('c'),
    ];
    foreach (['storage/sessions', 'storage/private'] as $relative) {
        if (!is_dir($root . '/' . $relative)) {
            mkdir($root . '/' . $relative, 0755, true);
        }
    }
    $data = json_decode((string) file_get_contents($root . '/storage/defaults/data.json'), true, 512, JSON_THROW_ON_ERROR);
    $data['admins']['upload-fixture-owner'] = [
        'id' => 'upload-fixture-owner', 'name' => 'Upload Fixture Owner',
        'email' => 'owner@example.test', 'password_hash' => password_hash('VelvetDemo12', PASSWORD_DEFAULT),
        'role' => 'owner', 'is_active' => 1, 'createdAt' => gmdate('c'),
    ];
    $data['members']['upload-fixture-member'] = [
        'id' => 'upload-fixture-member', 'displayName' => 'Upload Fixture Member',
        'email' => 'member@example.test', 'passwordHash' => password_hash('VelvetMember12', PASSWORD_DEFAULT),
        'emailVerifiedAt' => gmdate('c'), 'status' => 'active', 'lastLoginAt' => null,
        'lastSeenAt' => null, 'preferences' => [], 'failedLoginCount' => 0, 'lockedUntil' => null,
        'createdAt' => gmdate('c'), 'updatedAt' => gmdate('c'), 'deletedAt' => null,
    ];
    file_put_contents($root . '/config/app.php', "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n");
    file_put_contents($root . '/storage/data.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    file_put_contents($root . '/storage/install.lock', json_encode([
        'installed' => true, 'installation_id' => $id, 'version' => $config['version'],
        'base_url' => $base, 'installed_at' => $config['installed_at'],
    ], JSON_THROW_ON_ERROR));
    foreach (['config/app.php', 'storage/data.json', 'storage/install.lock'] as $relative) {
        chmod($root . '/' . $relative, 0600);
    }
    echo "Disposable package fixture configured.\n";
    exit;
}

if ($action === 'member-session') {
    define('ROOT_PATH', $root);
    session_name('velvet_vixxen_admin');
    session_save_path($root . '/storage/sessions');
    session_start();
    require $root . '/app/bootstrap.php';
    $result = member_auth()->attempt('member@example.test', 'VelvetMember12');
    if (empty($result['ok']) || !member_auth()->check() || app_auth()->check()) {
        throw new RuntimeException('The real member login did not produce a member-only session.');
    }
    $session = ['name' => session_name(), 'id' => session_id(), 'csrf' => csrf_token()];
    session_write_close();
    echo json_encode($session, JSON_THROW_ON_ERROR);
    exit;
}

throw new RuntimeException('Unknown disposable fixture action.');

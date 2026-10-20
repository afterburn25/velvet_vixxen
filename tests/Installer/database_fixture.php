<?php
declare(strict_types=1);

/** Shared helpers for disposable MySQL installer tests; never shipped in customer packages. */
function vvx_installer_fixture_database(): array
{
    $db = [
        'host' => getenv('VELVET_TEST_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('VELVET_TEST_DB_PORT') ?: 3306),
        'name' => getenv('VELVET_TEST_DB_NAME') ?: '',
        'user' => getenv('VELVET_TEST_DB_USER') ?: '',
        'password' => getenv('VELVET_TEST_DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ];
    if (!preg_match('/(?:^|_)test(?:_|$)/i', $db['name']) || $db['user'] === '') {
        throw new RuntimeException('Refusing installer fixtures: explicitly configure a disposable *_test_* database and user.');
    }
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
        $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    if (!preg_match('/^8\.0\./', (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION))) {
        throw new RuntimeException('These acceptance fixtures require actual MySQL 8.0; another database engine is not a passing substitute.');
    }
    return [$db, $pdo];
}

function vvx_installer_fixture_empty(PDO $pdo): void
{
    if ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) !== []) {
        throw new RuntimeException('Refusing to start installer fixtures: the explicitly named test database must initially be empty.');
    }
}

function vvx_installer_fixture_quote(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) throw new RuntimeException('Unsafe fixture SQL identifier.');
    return '`' . $identifier . '`';
}

/** Called only after the suite proved that it exclusively owns an initially empty test schema. */
function vvx_installer_fixture_reset_database(PDO $pdo, bool $ownsInitiallyEmptyDatabase): void
{
    if (!$ownsInitiallyEmptyDatabase || !preg_match('/(?:^|_)test(?:_|$)/i', (string) $pdo->query('SELECT DATABASE()')->fetchColumn())) {
        throw new RuntimeException('Refusing fixture cleanup without exclusive disposable-database ownership.');
    }
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($tables as $table) $pdo->exec('DROP TABLE ' . vvx_installer_fixture_quote((string) $table));
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}

function vvx_installer_fixture_remove(string $path): void
{
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') vvx_installer_fixture_remove($path . '/' . $entry);
    }
    @rmdir($path);
}

function vvx_installer_fixture_extract(string $archive, string $runtime): void
{
    if (is_dir($runtime)) {
        if (!is_file($runtime . '/.vvx-installer-db-fixture')) throw new RuntimeException('Refusing to replace an unmarked runtime.');
        vvx_installer_fixture_remove($runtime);
    }
    mkdir($runtime, 0700, true);
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true || !$zip->extractTo($runtime)) throw new RuntimeException('Could not extract the actual fresh installation package.');
    $zip->close();
    file_put_contents($runtime . '/.vvx-installer-db-fixture', 'Disposable installer acceptance fixture.');
    if (!is_file($runtime . '/config/app.php')) copy($runtime . '/config/app.example.php', $runtime . '/config/app.php');
}

function vvx_installer_fixture_load(string $runtime, bool $loadInstaller = true): void
{
    if (!is_file($runtime . '/.vvx-installer-db-fixture')) throw new RuntimeException('Refusing to load an unmarked installation fixture.');
    if (!defined('ROOT_PATH')) define('ROOT_PATH', $runtime);
    require_once $runtime . '/includes/autoload.php';
    require_once $runtime . '/includes/installation_recovery.php';
    require_once $runtime . '/app/Support/functions.php';
    if ($loadInstaller) {
        foreach (['InstallerLog', 'FileSystemService', 'EmailAddress', 'InstallerService'] as $service) {
            require_once $runtime . '/install/services/' . $service . '.php';
        }
    }
}

function vvx_installer_fixture_payload(array $db, string $baseUrl = 'http://127.0.0.1:8876'): array
{
    return [
        'install_id' => 'new-browser-session-fixture',
        'db' => $db,
        'owner' => ['name' => 'Installer Test Owner', 'email' => 'installer-owner@example.test', 'password' => 'OwnerFixture123!'],
        'site' => ['site_name' => 'Installer Acceptance Fixture', 'base_url' => $baseUrl,
            'support_email' => 'support@example.test', 'timezone' => 'America/Chicago', 'currency' => 'USD', 'age_gate_enabled' => false],
        'mail' => ['mode' => 'disabled'],
    ];
}

function vvx_installer_fixture_staged_config(array $db, string $installationId = 'existing-installation-fixture'): array
{
    return [
        'installed' => false, 'installation_id' => $installationId, 'version' => '0.8.1',
        'storage_mode' => 'mysql', 'db' => $db, 'base_url' => 'http://127.0.0.1:8876',
        'security' => ['app_key' => str_repeat('a', 64), 'signing_key' => str_repeat('s', 64), 'media_key' => str_repeat('m', 64)],
    ];
}

function vvx_installer_fixture_write_config(string $runtime, array $config): void
{
    file_put_contents($runtime . '/config/app.php', "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n");
    chmod($runtime . '/config/app.php', 0600);
}

function vvx_installer_fixture_sentinels(PDO $pdo, string $runtime): void
{
    $pdo->exec('CREATE TABLE unrelated_existing_records (id INT PRIMARY KEY, original_value VARCHAR(100) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO unrelated_existing_records VALUES (1,'Existing unrelated table and row remain')");
    if (!is_dir($runtime . '/uploads')) mkdir($runtime . '/uploads', 0755, true);
    if (!is_dir($runtime . '/storage/private')) mkdir($runtime . '/storage/private', 0755, true);
    file_put_contents($runtime . '/uploads/existing-upload.bin', 'Existing upload bytes remain unchanged.');
    file_put_contents($runtime . '/storage/private/existing-private.bin', 'Existing private bytes remain unchanged.');
}

/** Snapshot schema definitions and rows, including timestamps, rather than merely table counts. */
function vvx_installer_fixture_snapshot(PDO $pdo): array
{
    $snapshot = [];
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    sort($tables, SORT_STRING);
    foreach ($tables as $table) {
        $quoted = vvx_installer_fixture_quote((string) $table);
        $definition = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
        $rows = $pdo->query('SELECT * FROM ' . $quoted)->fetchAll();
        usort($rows, static fn(array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));
        $snapshot[(string) $table] = ['schema' => $definition[1], 'rows' => $rows];
    }
    return $snapshot;
}

function vvx_installer_fixture_install(string $runtime, array $payload, ?callable $progress = null): array
{
    $log = new \Velvet\Installer\InstallerLog($runtime, 'disposable-' . bin2hex(random_bytes(5)));
    return (new \Velvet\Installer\InstallerService($runtime, $log, $progress))->install($payload);
}

/** Create realistic existing owner/content rows without booting or installing the authoritative checkout. */
function vvx_installer_fixture_populate(PDO $pdo, array $db, string $runtime, bool $completeState = true): array
{
    (new \App\Core\MigrationRunner($pdo, $runtime . '/database/migrations'))->migrate();
    $config = vvx_installer_fixture_staged_config($db);
    vvx_installer_fixture_write_config($runtime, $config);
    $payload = vvx_installer_fixture_payload($db);
    $store = new \App\Core\Store(new \App\Core\Config($runtime . '/config/app.php'));
    $adminId = (int) $store->createAdmin($payload['owner']['name'], $payload['owner']['email'], $payload['owner']['password']);
    $defaults = json_decode((string) file_get_contents($runtime . '/storage/defaults/data.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($defaults['settings'] as $key => $value) {
        if (is_array($value)) $value['existingFixtureMarker'] = 'Preserve this configured value.';
        if ($key === 'site') $value['site_name'] = 'Existing Owner Website';
        $store->setSetting((string) $key, $value);
    }
    $store->setSetting('installation', ['version' => '0.8.0', 'installed_at' => '2026-01-02T03:04:05Z']);
    foreach ($defaults['membershipPlans'] as $plan) {
        unset($plan['id']);
        $plan['price'] = 37.25;
        $store->saveMembershipPlan($plan);
    }
    foreach ($defaults['media'] as $asset) {
        $asset['title'] = 'Owner edited ' . $asset['title'];
        $store->addMedia($asset);
    }
    foreach ($defaults['templates'] as $template) {
        unset($template['id']);
        $template['canvaUrl'] = 'https://www.canva.com/design/existing-fixture/edit';
        $store->saveTemplate($template);
    }
    foreach (['hero', 'footer'] as $section) {
        $scene = (array) ($defaults['scenes'][$section]['published'] ?? []);
        $scene['existingFixtureMarker'] = 'Preserve live ' . $section . ' and all its versions.';
        $store->saveScene($section, $scene, true, $adminId);
    }
    $pdo->exec("UPDATE payment_providers SET name='Existing gateway label',settings_json='{\"fixture\":\"retained\"}',is_enabled=0,sort_order=44,updated_at='2026-01-02 03:04:05' WHERE provider_key='sandbox'");
    $pdo->exec("INSERT INTO roles(role_key,name,description,is_system,created_at,updated_at) VALUES('owner','Existing role title','Existing role description',1,'2026-01-02 03:04:05','2026-01-02 03:04:05')");
    $pdo->exec("INSERT INTO members(display_name,email,password_hash,status,created_at,updated_at) VALUES('Existing fixture member','existing-member@example.test','fixture-unusable-password','active','2026-01-02 03:04:05','2026-01-02 03:04:05')");
    $memberId = (int) $pdo->lastInsertId();
    $order = $pdo->prepare("INSERT INTO orders(member_id,order_number,status,grand_total,metadata_json,created_at,updated_at) VALUES(?,?,'processing',27.25,?,'2026-01-02 03:04:05','2026-01-02 03:04:05')");
    $order->execute([$memberId, 'EXISTING-FIXTURE-ORDER', '{"existing":"private order snapshot"}']);
    $pdo->exec("INSERT INTO booking_requests(contact_name,contact_email,details,internal_notes,consent_at,created_at,updated_at) VALUES('Existing inquiry fixture','existing-inquiry@example.test','Retain the existing inquiry','Retain private owner notes','2026-01-02 03:04:05','2026-01-02 03:04:05','2026-01-02 03:04:05')");
    if ($completeState) {
        $statement = $pdo->prepare("INSERT INTO installation_state(state_key,state_value,updated_at) VALUES('installation',?,'2026-01-02 03:04:05')");
        $statement->execute([json_encode(['installation_id' => $config['installation_id'], 'version' => '0.8.0',
            'installed_at' => '2026-01-02T03:04:05Z', 'base_url' => 'http://127.0.0.1:8876'], JSON_THROW_ON_ERROR)]);
    }
    vvx_installer_fixture_sentinels($pdo, $runtime);
    return $config;
}

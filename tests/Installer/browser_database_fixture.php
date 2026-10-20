<?php
declare(strict_types=1);

// Fixture preparation/verification only. Browser requests execute the real packaged installer.
require __DIR__ . '/database_fixture.php';
[$db, $pdo] = vvx_installer_fixture_database();
$runtime = realpath(getenv('VELVET_INSTALLER_RUNTIME') ?: '') ?: '';
$expectedParent = rtrim(sys_get_temp_dir(), '/') . '/vvx-installer-browser-';
if (!str_starts_with($runtime, $expectedParent) || !is_file($runtime . '/.vvx-installer-db-fixture')) {
    throw new RuntimeException('Refusing an unmarked/non-disposable browser installer runtime.');
}
$command = $argv[1] ?? '';
$ownerMarker = dirname($runtime) . '/database-ownership.json';
$snapshotPath = dirname($runtime) . '/browser-database-before.json';
$configBefore = dirname($runtime) . '/browser-config-before.php';
$keysBefore = dirname($runtime) . '/browser-keys-before.json';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$ownership = is_file($ownerMarker) ? json_decode((string) file_get_contents($ownerMarker), true) : [];
if ($command !== 'prepare-partial') {
    $assert(($ownership['database'] ?? '') === $db['name'] && ($ownership['runtime'] ?? '') === $runtime,
        'Browser fixture does not own this initially empty database.');
}
if ($command === 'cleanup') {
    vvx_installer_fixture_reset_database($pdo, true);
    echo "Browser MySQL fixture cleaned.\n";
    exit;
}
vvx_installer_fixture_load($runtime, in_array($command, ['prepare-partial', 'stage-resume'], true));

if ($command === 'prepare-partial') {
    vvx_installer_fixture_empty($pdo);
    file_put_contents($ownerMarker, json_encode(['database' => $db['name'], 'runtime' => $runtime], JSON_THROW_ON_ERROR));
    $file = $runtime . '/database/migrations/202610170001_core.php';
    $definition = require $file;
    foreach ($definition['up'] as $sql) $pdo->exec($sql);
    (new \App\Core\MigrationRunner($pdo, $runtime . '/database/migrations'))->ensureRegistry();
    $ledger = $pdo->prepare('INSERT INTO migrations VALUES(?,?,?,1,?)');
    $ledger->execute([$definition['id'], $definition['description'], hash_file('sha256', $file), '2026-01-02 03:04:05']);
    $config = vvx_installer_fixture_staged_config($db);
    $temp = dirname($runtime) . '/fixture-database-config.php';
    file_put_contents($temp, "<?php\nreturn " . var_export($config, true) . ";\n");
    $store = new \App\Core\Store(new \App\Core\Config($temp));
    $defaults = json_decode((string) file_get_contents($runtime . '/storage/defaults/data.json'), true, 512, JSON_THROW_ON_ERROR);
    $site = $defaults['settings']['site'];
    $site['site_name'] = 'Existing Browser Fixture Website';
    $site['age_gate_enabled'] = false;
    $site['existingFixtureMarker'] = 'Retained before browser installation.';
    $store->setSetting('site', $site);
    $asset = $defaults['media'][0]; $asset['title'] = 'Existing browser fixture background';
    $store->addMedia($asset);
    vvx_installer_fixture_sentinels($pdo, $runtime);
    unlink($temp);
    file_put_contents($snapshotPath, json_encode(vvx_installer_fixture_snapshot($pdo), JSON_THROW_ON_ERROR));
    copy($runtime . '/config/app.php', $configBefore);
    echo "Prepared exact first-migration tables with retained site/media/upload data for browser installation.\n";
    exit;
}

if ($command === 'stage-resume') {
    $config = \App\Core\Config::loadFresh($runtime . '/config/app.php');
    $assert(($config['installed'] ?? false) === true, 'A completed first browser installation is required before staged retry.');
    file_put_contents($keysBefore, json_encode(['security' => $config['security'], 'installation_id' => $config['installation_id']], JSON_THROW_ON_ERROR));
    // Simulate the interrupted-finalization window on the fixture only. No real site lock is removed.
    $config['installed'] = false;
    $config['installer_pending'] = true;
    vvx_installer_fixture_write_config($runtime, $config);
    @unlink($runtime . '/storage/install.lock');
    @unlink($runtime . '/config/app.php.previous');
    $pdo->exec("UPDATE payment_providers SET name='Existing browser gateway',settings_json='{\"browser\":\"retained\"}',is_enabled=0,updated_at='2026-01-02 03:04:05' WHERE provider_key='sandbox'");
    $pdo->exec("INSERT INTO booking_requests(contact_name,contact_email,details,internal_notes,consent_at,created_at,updated_at) VALUES('Existing browser inquiry','browser-inquiry@example.test','Preserve existing browser inquiry','Preserve owner-only note','2026-01-02 03:04:05','2026-01-02 03:04:05','2026-01-02 03:04:05')");
    $payload = vvx_installer_fixture_payload($db, (string) $config['base_url']);
    $payload['owner']['password'] = 'FixtureOwn12';
    $failure = '';
    try {
        vvx_installer_fixture_install($runtime, $payload, static function (string $stage): void {
            if ($stage === 'verification') throw new RuntimeException('Injected browser final verification failure.');
        });
    } catch (RuntimeException $error) { $failure = $error->getMessage(); }
    $assert(str_contains($failure, 'Injected browser final verification failure'), 'The real post-final-configuration failure was not exercised.');
    $pending = \App\Core\Config::loadFresh($runtime . '/config/app.php');
    $previous = \App\Core\Config::loadFresh($runtime . '/config/app.php.previous');
    $assert(($pending['installed'] ?? null) === false && ($pending['installer_pending'] ?? null) === true,
        'Final verification failure did not retain a pending installer checkpoint.');
    $assert(($previous['installed'] ?? false) === true, 'The stale installed=true backup required to test HTTP bootstrap recovery was not created.');
    $assert(!is_file($runtime . '/storage/install.lock'), 'The injected failed verification retained an installed lock.');
    file_put_contents($snapshotPath, json_encode(vvx_installer_fixture_snapshot($pdo), JSON_THROW_ON_ERROR));
    copy($runtime . '/config/app.php', $configBefore);
    echo "Injected real final verification failure; pending config, stale installed=true backup and original keys are ready for HTTP/mobile retry.\n";
    exit;
}

$before = json_decode((string) file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR);
$after = vvx_installer_fixture_snapshot($pdo);
if ($command === 'verify-unchanged') {
    $assert($before === $after, 'Rejected browser install changed database schema or rows.');
    $assert(file_get_contents($runtime . '/config/app.php') === file_get_contents($configBefore), 'Rejected browser install changed saved configuration.');
    echo "Rejected browser attempt preserved database and configuration.\n";
    exit;
}
if ($command !== 'verify-installed') throw new RuntimeException('Unknown fixture command.');
foreach ($before as $table => $state) {
    $assert(isset($after[$table]), 'Browser install deleted an existing table: ' . $table);
    foreach ($state['rows'] as $row) $assert(in_array($row, $after[$table]['rows'], true), 'Browser install changed an existing row or timestamp in ' . $table);
}
$config = \App\Core\Config::loadFresh($runtime . '/config/app.php');
$lock = json_decode((string) file_get_contents($runtime . '/storage/install.lock'), true, 512, JSON_THROW_ON_ERROR);
$assert(($config['installed'] ?? false) === true && ($lock['installation_id'] ?? '') === $config['installation_id'], 'Browser setup did not activate a verified configuration and lock.');
$version = trim((string) file_get_contents($runtime . '/VERSION'));
$assert($version === VELVET_VIXXEN_VERSION && ($config['version'] ?? '') === $version && ($lock['version'] ?? '') === $version,
    'Browser setup configuration, lock and executing runtime do not match the canonical package version.');
$assert((int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 15, 'Browser setup did not complete every migration.');
$assert((int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 1, 'Browser setup duplicated or omitted the owner account.');
$assert(!is_dir($runtime . '/install'), 'Successful browser setup did not retire the public installer.');
$assert(file_get_contents($runtime . '/uploads/existing-upload.bin') === 'Existing upload bytes remain unchanged.', 'Browser setup changed uploaded bytes.');
$assert(file_get_contents($runtime . '/storage/private/existing-private.bin') === 'Existing private bytes remain unchanged.', 'Browser setup changed private bytes.');
if (is_file($keysBefore)) {
    $keys = json_decode((string) file_get_contents($keysBefore), true, 512, JSON_THROW_ON_ERROR);
    $assert($config['security'] === $keys['security'] && $config['installation_id'] === $keys['installation_id'], 'Resumed browser setup replaced existing keys or identity.');
    foreach (['admins', 'membership_plans', 'media_assets', 'canva_templates', 'scenes', 'scene_versions'] as $table) {
        $assert(count($before[$table]['rows']) === count($after[$table]['rows']), 'Resumed browser setup duplicated existing content: ' . $table);
    }
}
echo "Browser installation verified: every migration, owner, existing rows/keys/uploads, lock and cleanup.\n";

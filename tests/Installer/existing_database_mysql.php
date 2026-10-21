<?php
declare(strict_types=1);

// Actual InstallerService + actual packaged source + a guarded real MySQL 8 database.
// No app bootstrap, configuration staging, or demo install touches the source checkout.
require __DIR__ . '/database_fixture.php';
$source = dirname(__DIR__, 2);
[$db, $pdo] = vvx_installer_fixture_database();
vvx_installer_fixture_empty($pdo);
$ownsDatabase = true;
$work = sys_get_temp_dir() . '/vvx-installer-db-test-' . bin2hex(random_bytes(8));
$runtime = $work . '/runtime';
mkdir($work, 0700, true);
$checks = 0;
$caseName = 'package setup';
$assert = static function (bool $condition, string $message) use (&$checks, &$caseName): void {
    if (!$condition) throw new RuntimeException($caseName . ': ' . $message);
    $checks++;
};
$reject = static function (callable $operation, string $needle = '') use ($assert): string {
    $message = '';
    try { $operation(); } catch (RuntimeException $error) { $message = $error->getMessage(); }
    $assert($message !== '', 'Expected failure was incorrectly accepted.');
    $assert($needle === '' || str_contains(strtolower($message), strtolower($needle)), 'Unexpected failure: ' . $message);
    $assert(!str_contains($message, 'Choose an empty database'), 'The obsolete blanket empty-database rejection returned.');
    return $message;
};
$payload = vvx_installer_fixture_payload($db);

try {
    $process = proc_open(['python3', $source . '/scripts/package.py', '--output', $work . '/packages'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run the real runtime package builder.');
    $buildOutput = stream_get_contents($pipes[1]); $buildError = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Runtime package build failed: ' . $buildError . $buildOutput);
    $buildManifest = json_decode($buildOutput, true, 512, JSON_THROW_ON_ERROR);
    $freshPackages = array_values(array_filter((array) ($buildManifest['packages'] ?? []),
        static fn(array $package): bool => ($package['kind'] ?? '') === 'fresh'));
    if (count($freshPackages) !== 1 || !is_string($freshPackages[0]['filename'] ?? null)
        || basename($freshPackages[0]['filename']) !== $freshPackages[0]['filename']) {
        throw new RuntimeException('The package builder did not identify one fresh installation archive.');
    }
    $archive = $work . '/packages/' . $freshPackages[0]['filename'];
    $reset = static function (string $label) use (&$caseName, $pdo, $ownsDatabase, $archive, $runtime): void {
        $caseName = $label;
        vvx_installer_fixture_reset_database($pdo, $ownsDatabase);
        vvx_installer_fixture_extract($archive, $runtime);
        vvx_installer_fixture_load($runtime);
    };
    $preservedFiles = static function () use ($runtime, $assert): void {
        $assert(file_get_contents($runtime . '/uploads/existing-upload.bin') === 'Existing upload bytes remain unchanged.', 'Existing uploaded bytes changed.');
        $assert(file_get_contents($runtime . '/storage/private/existing-private.bin') === 'Existing private bytes remain unchanged.', 'Existing private bytes changed.');
    };
    $preservedRows = static function (array $before) use ($pdo, $assert): void {
        $after = vvx_installer_fixture_snapshot($pdo);
        foreach ($before as $table => $state) {
            $assert(isset($after[$table]), 'Existing table disappeared: ' . $table);
            foreach ($state['rows'] as $row) {
                $assert(in_array($row, $after[$table]['rows'], true), 'Existing row/column/timestamp changed in ' . $table);
            }
        }
    };
    $assertInstalled = static function (array $result, bool $ownerReused) use ($runtime, $pdo, $assert): array {
        $config = \App\Core\Config::loadFresh($runtime . '/config/app.php');
        $lock = json_decode((string) file_get_contents($runtime . '/storage/install.lock'), true, 512, JSON_THROW_ON_ERROR);
        $assert(($config['installed'] ?? false) === true, 'Final configuration is not installed.');
        $assert(($config['installer_pending'] ?? false) !== true, 'Successful final activation still has a pending installer marker.');
        $assert(($lock['installed'] ?? false) === true && $lock['installation_id'] === $config['installation_id'], 'Final lock and configuration identity differ.');
        $version = trim((string) file_get_contents($runtime . '/VERSION'));
        $assert($version === VELVET_VIXXEN_VERSION, 'Packaged VERSION and executing PHP version differ.');
        $assert(($config['version'] ?? '') === $version && ($lock['version'] ?? '') === $version, 'Installer configuration or lock retained a pinned release version.');
        $audit = json_decode((string) $pdo->query("SELECT context_json FROM audit_log WHERE action_key IN ('installation.completed','installation.resumed') ORDER BY id DESC LIMIT 1")->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        $assert(($audit['version'] ?? '') === $version, 'The completed/resumed installation audit has the wrong release version.');
        $assert(($result['owner_reused'] ?? null) === $ownerReused, 'Wrong owner reuse result.');
        $assert((int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 17, 'The complete migration ledger is missing entries.');
        $assert((int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 1, 'Owner account was missing or duplicated.');
        $owner = $pdo->query('SELECT * FROM admins LIMIT 1')->fetch();
        $assert(password_verify('OwnerFixture123!', $owner['password_hash']), 'Owner cannot authenticate after installation.');
        return $config;
    };

    $reset('empty database');
    foreach (['install/services/InstallerService.php', 'app/Core/MigrationRunner.php'] as $file) {
        $assert(hash_file('sha256', $runtime . '/' . $file) === hash_file('sha256', $source . '/' . $file), 'Test package does not contain current executable source: ' . $file);
    }
    $result = vvx_installer_fixture_install($runtime, $payload);
    $assert(($result['database_mode'] ?? '') === 'created', 'Empty database did not take the creation path.');
    $assertInstalled($result, false);
    $installation = json_decode((string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key='installation'")->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $identity = json_decode((string) $pdo->query("SELECT state_value FROM installation_state WHERE state_key='installation'")->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $assert(($installation['version'] ?? '') === VELVET_VIXXEN_VERSION, 'A fresh installation setting has the wrong canonical release version.');
    $assert(($identity['version'] ?? '') === VELVET_VIXXEN_VERSION, 'A fresh database installation identity has the wrong canonical release version.');
    echo "PASS installer MySQL: empty database and working owner login\n";

    $reset('unrelated existing table');
    vvx_installer_fixture_sentinels($pdo, $runtime);
    $before = vvx_installer_fixture_snapshot($pdo);
    $assertInstalled(vvx_installer_fixture_install($runtime, $payload), false);
    $preservedRows($before); $preservedFiles();
    echo "PASS installer MySQL: unrelated existing tables and uploaded/private files preserved\n";

    $reset('interrupted core migration with no ledger');
    $core = require $runtime . '/database/migrations/202610170001_core.php';
    foreach (array_slice($core['up'], 0, 3) as $sql) $pdo->exec($sql);
    $pdo->exec("INSERT INTO settings(setting_key,setting_value,updated_at) VALUES('existing_custom_setting','{\"retained\":true}','2026-01-02 03:04:05')");
    vvx_installer_fixture_sentinels($pdo, $runtime);
    $before = vvx_installer_fixture_snapshot($pdo);
    $result = vvx_installer_fixture_install($runtime, $payload);
    $assert(($result['database_mode'] ?? '') === 'resumed', 'Partial schema did not report resumed setup.');
    $assertInstalled($result, false); $preservedRows($before); $preservedFiles();
    echo "PASS installer MySQL: interrupted core tables complete without deleting existing rows\n";

    $reset('complete existing site and staged configuration');
    $existingConfig = vvx_installer_fixture_populate($pdo, $db, $runtime);
    $before = vvx_installer_fixture_snapshot($pdo);
    $result = vvx_installer_fixture_install($runtime, $payload);
    $afterConfig = $assertInstalled($result, true);
    $assert($result['database_mode'] === 'resumed' && $result['migrations'] === [], 'Complete schema unnecessarily reran migrations.');
    $assert($afterConfig['security'] === $existingConfig['security'] && $afterConfig['installation_id'] === $existingConfig['installation_id'], 'Existing staged keys or identity were replaced.');
    $preservedRows($before); $preservedFiles();
    foreach (['admins', 'membership_plans', 'media_assets', 'canva_templates', 'scenes', 'scene_versions'] as $table) {
        $assert((int) $pdo->query('SELECT COUNT(*) FROM ' . vvx_installer_fixture_quote($table))->fetchColumn() === count($before[$table]['rows']), 'Existing content was duplicated or republished: ' . $table);
    }
    $finishedSnapshot = vvx_installer_fixture_snapshot($pdo);
    $finishedBytes = file_get_contents($runtime . '/config/app.php');
    $reject(static fn() => vvx_installer_fixture_install($runtime, $payload), 'existing installation state');
    $assert($finishedSnapshot === vvx_installer_fixture_snapshot($pdo) && file_get_contents($runtime . '/config/app.php') === $finishedBytes, 'A repeated completed install changed existing state.');
    echo "PASS installer MySQL: existing owner/hash, keys, identity, content, orders, bookings and repeated completed attempt preserved\n";

    $reset('process interrupted after installed configuration and lock');
    $existingConfig = vvx_installer_fixture_populate($pdo, $db, $runtime);
    $pending = $existingConfig; $pending['installed'] = true; $pending['installer_pending'] = true;
    vvx_installer_fixture_write_config($runtime, $pending);
    file_put_contents($runtime . '/storage/install.lock', json_encode(['installed' => true, 'installation_id' => $pending['installation_id'], 'version' => '0.8.1'], JSON_THROW_ON_ERROR));
    $before = vvx_installer_fixture_snapshot($pdo); $bytes = file_get_contents($runtime . '/config/app.php');
    $badOwner = $payload; $badOwner['owner']['password'] = 'WrongFixture123!';
    $reject(static fn() => vvx_installer_fixture_install($runtime, $badOwner), 'owner');
    $assert($before === vvx_installer_fixture_snapshot($pdo) && $bytes === file_get_contents($runtime . '/config/app.php'), 'Pending installed=true checkpoint was changed by an unauthenticated request.');
    $afterConfig = $assertInstalled(vvx_installer_fixture_install($runtime, $payload), true);
    $assert($afterConfig['security'] === $existingConfig['security'] && $afterConfig['installation_id'] === $existingConfig['installation_id'], 'Process-kill window resumed with different keys or identity.');
    $preservedRows($before); $preservedFiles();
    echo "PASS installer MySQL: interrupted installed=true/pending configuration plus matching lock resumes and clears only after verification\n";

    $reset('complete existing schema without migration registry');
    $existingConfig = vvx_installer_fixture_populate($pdo, $db, $runtime);
    $pdo->exec('DROP TABLE migrations');
    $before = vvx_installer_fixture_snapshot($pdo);
    $afterConfig = $assertInstalled(vvx_installer_fixture_install($runtime, $payload), true);
    $preservedRows($before); $preservedFiles();
    $assert($afterConfig['security'] === $existingConfig['security'], 'Registry reconstruction regenerated security keys.');
    $assert($pdo->query("SELECT status FROM orders WHERE order_number='EXISTING-FIXTURE-ORDER'")->fetchColumn() === 'processing', 'ENUM recovery changed an existing order status.');
    echo "PASS installer MySQL: ledgerless full schema resumes with current order status and customized sandbox provider unchanged\n";

    $reset('existing compatible database without local configuration');
    $existingConfig = vvx_installer_fixture_populate($pdo, $db, $runtime);
    unlink($runtime . '/config/app.php');
    $before = vvx_installer_fixture_snapshot($pdo);
    $afterConfig = $assertInstalled(vvx_installer_fixture_install($runtime, $payload), true);
    $assert($afterConfig['installation_id'] === $existingConfig['installation_id'], 'Database identity was not retained when adopting compatible schema.');
    $preservedRows($before); $preservedFiles();
    echo "PASS installer MySQL: compatible existing database is adopted after owner authentication\n";

    foreach (['wrong-password', 'different-owner', 'inactive-owner', 'non-owner-role'] as $variant) {
        $reset('owner authentication ' . $variant);
        vvx_installer_fixture_populate($pdo, $db, $runtime);
        $attempt = $payload;
        if ($variant === 'wrong-password') $attempt['owner']['password'] = 'WrongFixture123!';
        if ($variant === 'different-owner') $attempt['owner']['email'] = 'another-owner@example.test';
        if ($variant === 'inactive-owner') $pdo->exec('UPDATE admins SET is_active=0');
        if ($variant === 'non-owner-role') $pdo->exec("UPDATE admins SET role='administrator'");
        $before = vvx_installer_fixture_snapshot($pdo);
        $configBytes = file_get_contents($runtime . '/config/app.php');
        $reject(static fn() => vvx_installer_fixture_install($runtime, $attempt), 'owner');
        $assert($before === vvx_installer_fixture_snapshot($pdo), 'Rejected owner authentication altered schema or database rows.');
        $assert(file_get_contents($runtime . '/config/app.php') === $configBytes, 'Rejected owner authentication replaced the current configuration.');
        $preservedFiles();
    }
    echo "PASS installer MySQL: wrong password, another account, disabled owner and non-owner role cannot change setup\n";

    $reset('recovered matching security key backup');
    $existingConfig = vvx_installer_fixture_populate($pdo, $db, $runtime);
    $backup = $existingConfig; $backup['installed'] = true;
    file_put_contents($runtime . '/config/app.php.previous', "<?php\nreturn " . var_export($backup, true) . ";\n");
    unlink($runtime . '/config/app.php');
    $afterConfig = $assertInstalled(vvx_installer_fixture_install($runtime, $payload), true);
    $assert($afterConfig['security'] === $existingConfig['security'] && $afterConfig['installation_id'] === $existingConfig['installation_id'], 'Matching backup keys and installation identity were not recovered.');
    echo "PASS installer MySQL: matching prior configuration restores existing keys\n";

    $reset('encrypted license records require original keys');
    vvx_installer_fixture_populate($pdo, $db, $runtime);
    $pdo->exec("INSERT INTO addon_licenses(addon_key,installation_id,license_key_encrypted,created_at,updated_at) VALUES('fixture-addon','existing-installation-fixture','fixture-encrypted-existing-license','2026-01-02 03:04:05','2026-01-02 03:04:05')");
    unlink($runtime . '/config/app.php');
    $before = vvx_installer_fixture_snapshot($pdo);
    $message = $reject(static fn() => vvx_installer_fixture_install($runtime, $payload));
    $assert(str_contains(strtolower($message), 'key') && str_contains(strtolower($message), 'config'), 'Missing original encrypted-data keys did not explain configuration restoration.');
    $assert($before === vvx_installer_fixture_snapshot($pdo) && !is_file($runtime . '/config/app.php'), 'Missing-key refusal generated replacement keys or modified existing records.');
    echo "PASS installer MySQL: encrypted existing records cannot silently lose their original keys\n";

    foreach (['identity', 'database'] as $variant) {
        $reset('conflicting saved ' . $variant);
        $config = vvx_installer_fixture_populate($pdo, $db, $runtime);
        if ($variant === 'identity') $config['installation_id'] = 'different-installation-fixture';
        else $config['db']['name'] = 'different_database_test';
        vvx_installer_fixture_write_config($runtime, $config);
        $before = vvx_installer_fixture_snapshot($pdo); $bytes = file_get_contents($runtime . '/config/app.php');
        $reject(static fn() => vvx_installer_fixture_install($runtime, $payload));
        $assert($before === vvx_installer_fixture_snapshot($pdo) && $bytes === file_get_contents($runtime . '/config/app.php'), 'Conflicting saved database/identity was overwritten.');
    }
    echo "PASS installer MySQL: mismatched saved database or installation identity stops without writes\n";

    $reset('failed finalization and retry');
    $core = require $runtime . '/database/migrations/202610170001_core.php';
    foreach ($core['up'] as $sql) $pdo->exec($sql);
    $pdo->exec("INSERT INTO settings(setting_key,setting_value,updated_at) VALUES('retained_before_failure','{\"existing\":true}','2026-01-02 03:04:05')");
    vvx_installer_fixture_sentinels($pdo, $runtime);
    $before = vvx_installer_fixture_snapshot($pdo);
    $capturedKeys = null; $capturedIdentity = null;
    $interrupt = static function (string $stage) use ($runtime, &$capturedKeys, &$capturedIdentity): void {
        if ($stage !== 'owner') return;
        $staged = \App\Core\Config::loadFresh($runtime . '/config/app.php');
        $capturedKeys = $staged['security']; $capturedIdentity = $staged['installation_id'];
        throw new RuntimeException('Injected interruption after all database migrations.');
    };
    $reject(static fn() => vvx_installer_fixture_install($runtime, $payload, $interrupt), 'Injected interruption');
    $staged = \App\Core\Config::loadFresh($runtime . '/config/app.php');
    $assert(($staged['installed'] ?? null) === false && ($staged['installer_pending'] ?? null) === true, 'Failure did not retain resumable staged configuration.');
    $assert($staged['security'] === $capturedKeys && $staged['installation_id'] === $capturedIdentity, 'Failure replaced the keys or installation identity.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 17, 'Failure destructively rolled back completed DDL or removed the migration registry.');
    $preservedRows($before); $preservedFiles();
    $retry = $payload; $retry['install_id'] = 'different-retry-browser-session';
    $afterConfig = $assertInstalled(vvx_installer_fixture_install($runtime, $retry), false);
    $assert($afterConfig['security'] === $capturedKeys && $afterConfig['installation_id'] === $capturedIdentity, 'Retry regenerated keys or identity.');
    $preservedRows($before); $preservedFiles();
    echo "PASS installer MySQL: failed finalization retains DDL, data and keys; a new-session retry completes safely\n";

    $reset('interrupted defaults are filled without duplication');
    vvx_installer_fixture_populate($pdo, $db, $runtime, false);
    $pdo->exec("DELETE FROM settings WHERE setting_key='canva'");
    $keepMedia = (int) $pdo->query('SELECT MIN(id) FROM media_assets')->fetchColumn();
    $keepTemplate = (int) $pdo->query('SELECT MIN(id) FROM canva_templates')->fetchColumn();
    $pdo->exec('DELETE FROM media_assets WHERE id<>' . $keepMedia);
    $pdo->exec('DELETE FROM canva_templates WHERE id<>' . $keepTemplate);
    $pdo->exec("DELETE FROM scene_versions WHERE section_key='footer'");
    $pdo->exec("DELETE FROM scenes WHERE section_key='footer'");
    $before = vvx_installer_fixture_snapshot($pdo);
    $assertInstalled(vvx_installer_fixture_install($runtime, $payload), true);
    $preservedRows($before); $preservedFiles();
    $defaults = json_decode((string) file_get_contents($runtime . '/storage/defaults/data.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (['media_assets' => ['media', 'file'], 'canva_templates' => ['templates', 'starterFile'], 'membership_plans' => ['membershipPlans', 'key']] as $table => [$collection, $key]) {
        $expected = count(array_unique(array_column($defaults[$collection], $key)));
        $assert((int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() === $expected, 'Natural-key defaults were omitted or duplicated in ' . $table);
    }
    $assert((int) $pdo->query("SELECT COUNT(*) FROM scenes WHERE section_key='footer'")->fetchColumn() === 2, 'Missing footer draft/publication was not completed.');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM scene_versions WHERE section_key='hero'")->fetchColumn() === 1, 'Existing Hero scene was republished.');
    echo "PASS installer MySQL: interrupted settings/plans/media/templates/scenes complete once without overwriting natural-key matches\n";

    $reset('concurrent requests for one site or database');
    vvx_installer_fixture_sentinels($pdo, $runtime);
    $otherRuntime = $work . '/other-runtime';
    vvx_installer_fixture_extract($archive, $otherRuntime);
    $concurrentChecks = 0;
    $concurrent = static function (string $stage) use ($runtime, $otherRuntime, $payload, $pdo, $assert, $reject, &$concurrentChecks): void {
        if ($stage !== 'migrations') return;
        $before = vvx_installer_fixture_snapshot($pdo);
        $activeBytes = file_get_contents($runtime . '/config/app.php');
        $otherBytes = file_get_contents($otherRuntime . '/config/app.php');
        $reject(static fn() => vvx_installer_fixture_install($runtime, $payload), 'Another installation request');
        $assert(file_get_contents($runtime . '/config/app.php') === $activeBytes, 'Concurrent same-site request replaced staged configuration.');
        $reject(static fn() => vvx_installer_fixture_install($otherRuntime, $payload), 'already using this database');
        $assert(file_get_contents($otherRuntime . '/config/app.php') === $otherBytes, 'Concurrent other-site request staged a conflicting configuration.');
        $assert($before === vvx_installer_fixture_snapshot($pdo), 'Rejected concurrent request changed the database.');
        $concurrentChecks++;
    };
    $assertInstalled(vvx_installer_fixture_install($runtime, $payload, $concurrent), false);
    $assert($concurrentChecks === 1, 'Concurrent real service instances were not exercised while locks were held.');
    $preservedFiles();
    echo "PASS installer MySQL: site file lock and cross-site database lock serialize real installer instances\n";

    foreach (['checksum', 'unknown-migration', 'incompatible-column'] as $variant) {
        $reset('read-only compatibility refusal ' . $variant);
        vvx_installer_fixture_populate($pdo, $db, $runtime);
        if ($variant === 'checksum') $pdo->exec("UPDATE migrations SET checksum=REPEAT('0',64) WHERE migration_key='202610170001_core'");
        if ($variant === 'unknown-migration') $pdo->exec("INSERT INTO migrations VALUES('unknown_existing_migration','Existing external record',REPEAT('f',64),1,'2026-01-02 03:04:05')");
        if ($variant === 'incompatible-column') $pdo->exec('ALTER TABLE settings MODIFY setting_value VARCHAR(4000) NOT NULL');
        $before = vvx_installer_fixture_snapshot($pdo); $bytes = file_get_contents($runtime . '/config/app.php');
        $reject(static fn() => vvx_installer_fixture_install($runtime, $payload));
        $assert($before === vvx_installer_fixture_snapshot($pdo) && $bytes === file_get_contents($runtime . '/config/app.php'), 'Compatibility refusal changed schema, rows, or saved configuration.');
    }
    echo "PASS installer MySQL: checksum drift, unknown migrations and incompatible existing schema fail without mutation\n";

    $reset('schema resume ALTER and conflict regressions');
    require __DIR__ . '/migration_resume_mysql_scenarios.php';

    echo 'Existing-database installer acceptance passed (' . $checks . ' assertions; PHP ' . PHP_VERSION . '; MySQL '
        . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "; actual extracted customer package).\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Installer MySQL acceptance failed [' . $caseName . ']: ' . $error->getMessage() . PHP_EOL);
    $failed = true;
} finally {
    vvx_installer_fixture_reset_database($pdo, $ownsDatabase);
    vvx_installer_fixture_remove($work);
}
exit(isset($failed) ? 1 : 0);

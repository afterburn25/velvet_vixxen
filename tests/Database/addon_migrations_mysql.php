<?php
declare(strict_types=1);

// Independent acceptance: actual Fresh ZIP, actual AddonManager, real MySQL 8.
// This suite owns only a schema it first proves is empty and clearly test-named.
require dirname(__DIR__) . '/Installer/database_fixture.php';
$source = dirname(__DIR__, 2);
$browserAcceptance = in_array('--browser', array_slice($argv, 1), true);
if (array_diff(array_slice($argv, 1), ['--browser']) !== []) throw new RuntimeException('Usage: php tests/Database/addon_migrations_mysql.php [--browser]');
[$db, $pdo] = vvx_installer_fixture_database();
vvx_installer_fixture_empty($pdo);
$ownsDatabase = true;
$work = sys_get_temp_dir() . '/vvx-addon-db-test-' . bin2hex(random_bytes(8));
$runtime = $work . '/runtime';
mkdir($work, 0700, true);
$checks = 0;
$case = 'packaged setup';
$assert = static function (bool $condition, string $message) use (&$checks, &$case): void {
    if (!$condition) throw new RuntimeException($case . ': ' . $message);
    $checks++;
};
$reject = static function (callable $operation, string $needle) use ($assert): Throwable {
    $error = null;
    try { $operation(); } catch (Throwable $caught) { $error = $caught; }
    $assert($error instanceof Throwable, 'Expected rejection was accepted.');
    $assert(str_contains(strtolower($error->getMessage()), strtolower($needle)), 'Unexpected error: ' . $error->getMessage());
    return $error;
};
$tableExists = static function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() === 1;
};
$columnExists = static function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() === 1;
};
$runner = null;
$coreKeys = [];

try {
    $process = proc_open(['python3', $source . '/scripts/package.py', '--output', $work . '/packages'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not build the actual runtime package.');
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Runtime package builder failed: ' . $errors . $output);
    $manifest = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    $fresh = array_values(array_filter($manifest['packages'] ?? [], static fn(array $entry): bool => ($entry['kind'] ?? '') === 'fresh'));
    $assert(count($fresh) === 1 && basename((string) $fresh[0]['filename']) === $fresh[0]['filename'], 'Builder did not produce one identifiable Fresh ZIP.');
    vvx_installer_fixture_extract($work . '/packages/' . $fresh[0]['filename'], $runtime);
    file_put_contents($runtime . '/.vvx-addon-acceptance', 'Exclusively owned add-on MySQL lifecycle acceptance.');
    vvx_installer_fixture_load($runtime, false);
    require_once $runtime . '/includes/version.php';
    foreach (['AddonManager', 'AddonStoreTrait', 'AddonRuntime', 'AddonMigrationPlan', 'AddonMigrationRecovery', 'AddonMigrationException'] as $class) {
        $assert(hash_file('sha256', $runtime . '/app/Core/' . $class . '.php') === hash_file('sha256', $source . '/app/Core/' . $class . '.php'), 'Fresh ZIP is missing current executable migration code: ' . $class);
    }
    foreach (['admin/addon-recovery.php', 'app/Views/admin/addons.php'] as $path) {
        $assert(hash_file('sha256', $runtime . '/' . $path) === hash_file('sha256', $source . '/' . $path), 'Fresh ZIP omitted the real owner recovery UI: ' . $path);
    }
    $configuration = [
        'installed' => true, 'storage_mode' => 'mysql', 'db' => $db,
        'version' => VELVET_VIXXEN_VERSION, 'base_url' => 'http://127.0.0.1', 'mail' => ['mode' => 'disabled'],
        'installation_id' => 'addon-mysql-acceptance-fixture', 'addon_acceptance_fixture' => true,
        'security' => ['app_key' => str_repeat('a', 64), 'signing_key' => str_repeat('s', 64), 'media_key' => str_repeat('m', 64)],
    ];
    vvx_installer_fixture_write_config($runtime, $configuration);
    file_put_contents($runtime . '/storage/install.lock', json_encode(['installed' => true, 'installation_id' => $configuration['installation_id']], JSON_THROW_ON_ERROR));
    $config = new \App\Core\Config($runtime . '/config/app.php');
    $store = new \App\Core\Store($config);
    $manager = new \App\Core\AddonManager($store);
    $runner = new \App\Core\MigrationRunner($pdo, $runtime . '/database/migrations');
    $coreKeys = $runner->migrate();
    $store->createAdmin('Add-on Acceptance Owner', 'addon-owner@example.test', 'AddonFixtureOwner123!');
    $_SESSION = [];
    $addonId = 'fixture.mysql-lifecycle';
    $installedRoot = $runtime . '/addons/installed/' . $addonId;
    $recoveryPath = $runtime . '/storage/backups/addons/migrations/' . hash('sha256', $addonId) . '.json';
    $lifecyclePath = $runtime . '/storage/backups/addons/lifecycle/' . hash('sha256', $addonId) . '.json';
    $pdo->exec("INSERT INTO settings(setting_key,setting_value,updated_at) VALUES('addon_acceptance_sentinel','{\"retained\":true}','2026-01-02 03:04:05')");
    $pdo->exec('CREATE TABLE vvx_unrelated_addon_sentinel (id INT PRIMARY KEY, value_text VARCHAR(80) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO vvx_unrelated_addon_sentinel VALUES (1,'Unrelated existing data remains byte for byte')");
    $coreSentinel = $pdo->query("SELECT * FROM settings WHERE setting_key='addon_acceptance_sentinel'")->fetch();
    $customSentinel = $pdo->query('SELECT * FROM vvx_unrelated_addon_sentinel')->fetchAll();
    $preserved = static function () use ($pdo, $assert, $coreSentinel, $customSentinel): void {
        $assert($pdo->query("SELECT * FROM settings WHERE setting_key='addon_acceptance_sentinel'")->fetch() === $coreSentinel, 'Core data or timestamp changed.');
        $assert($pdo->query('SELECT * FROM vvx_unrelated_addon_sentinel')->fetchAll() === $customSentinel, 'Unrelated schema/data changed.');
    };

    $v1 = ['id' => '001_records',
        'up' => ['CREATE TABLE vvx_addon_records (id INT PRIMARY KEY, value_text VARCHAR(100) NOT NULL) ENGINE=InnoDB'],
        'down' => ['DROP TABLE IF EXISTS vvx_addon_records']];
    $v2 = ['id' => '002_notes',
        'up' => ['ALTER TABLE vvx_addon_records ADD COLUMN note_text VARCHAR(100) NULL', 'CREATE TABLE vvx_addon_notes (id INT PRIMARY KEY, record_id INT NOT NULL) ENGINE=InnoDB'],
        'down' => ['DROP TABLE IF EXISTS vvx_addon_notes', 'ALTER TABLE vvx_addon_records DROP COLUMN note_text']];
    $encodeMigration = static fn(array $migration): string => json_encode($migration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $definitions = static function (array $migrations) use ($encodeMigration): array {
        return array_map(static fn(array $migration): array => $migration + ['checksum' => hash('sha256', $encodeMigration($migration))], $migrations);
    };
    $package = static function (string $version, array $migrations, array $permissions = ['database.migrate']) use ($work, $addonId, $encodeMigration): string {
        $directory = $work . '/addon-source-' . bin2hex(random_bytes(5));
        mkdir($directory . '/database/migrations', 0750, true);
        mkdir($directory . '/src', 0750, true);
        $manifest = ['schema' => 1, 'id' => $addonId, 'name' => 'Disposable MySQL Lifecycle Fixture', 'vendor' => 'Test Fixtures',
            'version' => $version, 'description' => 'Local acceptance fixture only.', 'minimum_core_version' => '0.6.0',
            'maximum_core_version' => '0.9.x', 'minimum_php_version' => '8.2.0', 'bootstrap' => 'src/bootstrap.php',
            'permissions' => $permissions, 'dependencies' => (object) [], 'conflicts' => (object) [], 'license_type' => 'free'];
        file_put_contents($directory . '/addon.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/src/bootstrap.php', "<?php\ndeclare(strict_types=1);\n// Fixture version " . $version . "\n");
        foreach ($migrations as $migration) file_put_contents($directory . '/database/migrations/' . $migration['id'] . '.json', $encodeMigration($migration));
        $checksums = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) if ($file->isFile()) $checksums[substr($file->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $file->getPathname());
        ksort($checksums);
        file_put_contents($directory . '/checksums.json', json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $archive = $work . '/fixture-' . $version . '-' . bin2hex(random_bytes(5)) . '.zip';
        \App\Core\AddonArchive::createFromDirectory($directory, $archive);
        return $archive;
    };
    $install = static function (string $version, array $migrations, array $permissions = ['database.migrate']) use ($package, $manager, $assert): array {
        $archive = $package($version, $migrations, $permissions);
        $review = $manager->stageRemotePackage($archive, (string) hash_file('sha256', $archive));
        $assert(($review['checksums']['status'] ?? '') === 'verified', 'Fixture package was not individually checksum-covered.');
        return $manager->install((string) $review['token'], true, true);
    };
    $currentFileHashes = static function () use ($installedRoot): array {
        $hashes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($installedRoot, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) $hashes[substr($file->getPathname(), strlen($installedRoot))] = hash_file('sha256', $file->getPathname());
        }
        ksort($hashes);
        return $hashes;
    };

    $case = 'first install and same-version repair';
    $record = $install('1.0.0', [$v1]);
    $assert($record['version'] === '1.0.0' && $record['status'] === 'active', 'First package registry/version is wrong.');
    $assert($tableExists('vvx_addon_records'), 'Actual add-on CREATE TABLE did not execute.');
    $pdo->exec("INSERT INTO vvx_addon_records VALUES (1,'Existing add-on record must survive updates')");
    $addonRows = $pdo->query('SELECT * FROM vvx_addon_records')->fetchAll();
    $ledger1 = $store->addonMigrations($addonId);
    $assert(count($ledger1) === 1 && $ledger1['001_records']['checksum'] === $definitions([$v1])[0]['checksum'] && $ledger1['001_records']['batch'] === 1, 'Migration checksum/batch was not recorded accurately.');
    $install('1.0.0', [$v1]);
    $assert($store->addonMigrations($addonId) === $ledger1 && $pdo->query('SELECT * FROM vvx_addon_records')->fetchAll() === $addonRows, 'Repair reran SQL, changed ledger timestamps, or deleted existing rows.');
    $assert(!is_file($recoveryPath) && !is_file($lifecyclePath), 'Successful install left an unresolved recovery marker.');
    $preserved();
    echo "PASS add-on MySQL: real package install, checksum/batch ledger and idempotent repair\n";

    $case = 'version update and actual schema rollback';
    $install('2.0.0', [$v1, $v2]);
    $assert($columnExists('vvx_addon_records', 'note_text') && $tableExists('vvx_addon_notes'), 'Version two SQL did not execute.');
    $ledger2 = $store->addonMigrations($addonId);
    $assert(count($ledger2) === 2 && $ledger2['002_notes']['batch'] === 2 && $ledger2['001_records'] === $ledger1['001_records'], 'Update ledger lost its earlier migration or batch boundary.');
    $rolledBack = $manager->rollbackLatest($addonId);
    $assert($rolledBack['version'] === '1.0.0' && $rolledBack['status'] === 'active', 'Rollback did not restore the prior active version.');
    $assert(!$columnExists('vvx_addon_records', 'note_text') && !$tableExists('vvx_addon_notes'), 'Old files were restored while newer schema remained.');
    $assert(json_decode((string) file_get_contents($installedRoot . '/addon.json'), true)['version'] === '1.0.0', 'Installed files do not match the rollback registry version.');
    $assert($store->addonMigrations($addonId) === $ledger1 && $pdo->query('SELECT * FROM vvx_addon_records')->fetchAll() === $addonRows, 'Rollback changed retained base data/history.');
    $preserved();
    echo "PASS add-on MySQL: v1 to v2 to v1 aligns files, schema, ledger and retained rows\n";

    $case = 'preflight rejects all invalid work before mutation';
    $beforeFiles = $currentFileHashes();
    $newBeforeKnown = ['id' => '000_before_checksum', 'up' => ['CREATE TABLE vvx_addon_before_checksum (id INT PRIMARY KEY) ENGINE=InnoDB'], 'down' => ['DROP TABLE IF EXISTS vvx_addon_before_checksum']];
    $changedV1 = $v1; $changedV1['up'][0] .= ' ';
    $reject(static fn() => $install('2.0.0', [$newBeforeKnown, $changedV1]), 'checksum mismatch');
    $assert(!$tableExists('vvx_addon_before_checksum') && $currentFileHashes() === $beforeFiles && $store->addonMigrations($addonId) === $ledger1, 'Late checksum mismatch allowed earlier SQL/files/ledger mutation.');
    $reject(static fn() => $install('2.0.0', [$v1, $v2], []), 'database.migrate');
    $reject(static fn() => $install('2.0.0', [$v2]), 'does not own');
    $badDown = $v2; $badDown['down'] = [];
    $reject(static fn() => $install('2.0.0', [$v1, $badDown]), 'explicit up and down');
    $badCore = ['id' => '003_core', 'up' => ['ALTER TABLE settings ADD COLUMN addon_overwrite INT NULL'], 'down' => ['ALTER TABLE settings DROP COLUMN addon_overwrite']];
    $reject(static fn() => $install('2.0.0', [$v1, $badCore]), 'does not own');
    $badCustom = ['id' => '003_custom', 'up' => ['CREATE TABLE IF NOT EXISTS vvx_unrelated_addon_sentinel (id INT PRIMARY KEY) ENGINE=InnoDB'], 'down' => ['DROP TABLE IF EXISTS vvx_unrelated_addon_sentinel']];
    $reject(static fn() => $install('2.0.0', [$v1, $badCustom]), 'pre-existing table');
    $badMulti = $v2; $badMulti['up'][0] .= '; DELETE FROM settings';
    $reject(static fn() => $install('2.0.0', [$v1, $badMulti]), 'exactly one statement');
    $destructiveDown = $v2; $destructiveDown['down'][] = 'DROP TABLE vvx_addon_records';
    $reject(static fn() => $install('2.0.0', [$v1, $destructiveDown]), 'pre-existing or unrelated');
    $destructiveUp = ['id' => '002_delete_rows', 'up' => ['DELETE FROM vvx_addon_records'], 'down' => ['DELETE FROM vvx_addon_records']];
    $reject(static fn() => $install('2.0.0', [$v1, $destructiveUp]), 'additive schema changes only');
    $incompleteDown = $v2; $incompleteDown['down'] = ['DROP TABLE IF EXISTS vvx_addon_notes'];
    $reject(static fn() => $install('2.0.0', [$v1, $incompleteDown]), 'every added schema object');
    $assert(!$columnExists('vvx_addon_records', 'note_text') && $currentFileHashes() === $beforeFiles && $store->addonMigrations($addonId) === $ledger1, 'Rejected package changed active state.');
    $preserved();
    echo "PASS add-on MySQL: full preflight, permission, missing history, down definitions and owned-table boundaries\n";

    $case = 'later SQL failure compensates partial and completed migrations';
    $partial = ['id' => '003_partial', 'up' => ['CREATE TABLE vvx_addon_partial (id INT PRIMARY KEY) ENGINE=InnoDB', 'ALTER TABLE vvx_addon_partial ADD COLUMN bad_value VVX_INVALID_TYPE'], 'down' => ['DROP TABLE IF EXISTS vvx_addon_partial']];
    $error = $reject(static fn() => $install('3.0.0', [$v1, $v2, $partial]), 'completed changes were rolled back');
    $assert($error instanceof \App\Core\AddonMigrationException && !$error->recoveryRequired(), 'Completed compensation was not distinguished from held recovery.');
    $assert(!$tableExists('vvx_addon_partial') && !$tableExists('vvx_addon_notes') && !$columnExists('vvx_addon_records', 'note_text'), 'Later failed SQL left earlier or partial DDL behind.');
    $assert($currentFileHashes() === $beforeFiles && $store->addon($addonId)['version'] === '1.0.0' && $store->addonMigrations($addonId) === $ledger1, 'Failed update changed the previous package or ledger.');
    $assert($pdo->query('SELECT * FROM vvx_addon_records')->fetchAll() === $addonRows && !is_file($recoveryPath), 'Successful compensation changed retained rows or left recovery pending.');
    $preserved();
    echo "PASS add-on MySQL: a later SQL error reverses both its partial work and earlier completed migrations\n";

    $case = 'partial migration never reverses its unexecuted statements';
    $partialExisting = ['id' => '002_existing_column', 'up' => [
        'ALTER TABLE vvx_addon_records ADD COLUMN temporary_note VARCHAR(40) NULL',
        'ALTER TABLE vvx_addon_records ADD COLUMN value_text VARCHAR(100) NOT NULL',
    ], 'down' => ['ALTER TABLE vvx_addon_records DROP COLUMN value_text', 'ALTER TABLE vvx_addon_records DROP COLUMN temporary_note']];
    $reject(static fn() => $install('2.0.0', [$v1, $partialExisting]), 'completed changes were rolled back');
    $assert($columnExists('vvx_addon_records', 'value_text') && !$columnExists('vvx_addon_records', 'temporary_note'), 'Compensation ran the down counterpart of a failed, unexecuted ADD statement.');
    $assert($pdo->query('SELECT * FROM vvx_addon_records')->fetchAll() === $addonRows && $store->addonMigrations($addonId) === $ledger1, 'Partial compensation dropped pre-existing column data or registry history.');
    $preserved();
    echo "PASS add-on MySQL: partial compensation preserves pre-existing columns and their values\n";

    $case = 'migration ledger and package registry failures';
    $pdo->exec("CREATE TRIGGER vvx_reject_addon_migration BEFORE INSERT ON addon_migrations FOR EACH ROW BEGIN IF NEW.migration_key='002_notes' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture ledger rejection'; END IF; END");
    try { $reject(static fn() => $install('2.0.0', [$v1, $v2]), 'completed changes were rolled back'); }
    finally { $pdo->exec('DROP TRIGGER vvx_reject_addon_migration'); }
    $assert(!$columnExists('vvx_addon_records', 'note_text') && !$tableExists('vvx_addon_notes') && $store->addonMigrations($addonId) === $ledger1, 'Ledger failure left unrecorded committed DDL.');
    $pdo->exec("CREATE TRIGGER vvx_reject_addon_registry BEFORE UPDATE ON addons FOR EACH ROW BEGIN IF NEW.version='2.0.0' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture registry rejection'; END IF; END");
    try { $reject(static fn() => $install('2.0.0', [$v1, $v2]), 'Fixture registry rejection'); }
    finally { $pdo->exec('DROP TRIGGER vvx_reject_addon_registry'); }
    $assert($currentFileHashes() === $beforeFiles, 'Registry failure discarded prior files or retained new files in the active path.');
    $assert($store->addon($addonId)['version'] === '1.0.0' && $store->addon($addonId)['status'] === 'active', 'Registry failure lost the prior active record.');
    $assert($store->addonMigrations($addonId) === $ledger1 && !$columnExists('vvx_addon_records', 'note_text') && !$tableExists('vvx_addon_notes'), 'Registry failure did not reverse this attempt\'s migrations.');
    $assert($pdo->query('SELECT * FROM vvx_addon_records')->fetchAll() === $addonRows && !is_file($recoveryPath), 'Registry recovery lost retained data or remained pending.');
    $preserved();
    echo "PASS add-on MySQL: actual ledger/registry write failures preserve prior files, schema and data\n";

    $case = 'failed down SQL retains progress and blocks activation';
    $held = ['id' => '003_held', 'up' => [
        'CREATE TABLE vvx_addon_recovery_aux (id INT PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE vvx_addon_recovery_parent (id INT PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE vvx_addon_recovery_child (id INT PRIMARY KEY, parent_id INT, CONSTRAINT vvx_recovery_fk FOREIGN KEY(parent_id) REFERENCES vvx_addon_recovery_parent(id)) ENGINE=InnoDB',
        'ALTER TABLE vvx_addon_recovery_parent ADD COLUMN bad_value VVX_INVALID_TYPE',
    ], 'down' => ['DROP TABLE vvx_addon_recovery_aux', 'DROP TABLE vvx_addon_recovery_parent', 'DROP TABLE IF EXISTS vvx_addon_recovery_child']];
    $error = $reject(static fn() => $install('3.0.0', [$v1, $v2, $held]), 'recovery is incomplete');
    $assert($error instanceof \App\Core\AddonMigrationException && $error->recoveryRequired(), 'Failed compensation was reported as a complete rollback.');
    $assert(is_file($recoveryPath) && (fileperms($recoveryPath) & 0007) === 0, 'Private recovery progress is missing or world-accessible.');
    $progress = json_decode((string) file_get_contents($recoveryPath), true, 512, JSON_THROW_ON_ERROR);
    $assert($progress['steps'][0]['next'] === 1 && $progress['pendingStatement'] === false && !$tableExists('vvx_addon_recovery_aux'), 'Failed down statement lost its completed-statement boundary.');
    $assert($tableExists('vvx_addon_recovery_parent') && $tableExists('vvx_addon_recovery_child') && isset($store->addonMigrations($addonId)['002_notes']), 'Incomplete recovery silently erased its remaining schema or ledger.');
    $assert($currentFileHashes() === $beforeFiles && $store->addon($addonId)['status'] === 'quarantined', 'Held recovery did not retain and quarantine the previous package.');
    $reject(static fn() => $manager->activate($addonId), 'pending migration recovery');
    $reject(static fn() => $manager->uninstall($addonId, true), 'pending migration recovery');
    $reject(static fn() => $install('1.0.0', [$v1]), 'pending migration recovery');
    // Even if a separate stale writer marks it active, the durable marker wins.
    $record = $store->addon($addonId); $record['status'] = 'active'; $store->saveAddon($record);
    $addonRuntime = new \App\Core\AddonRuntime($store); $addonRuntime->boot();
    $assert($addonRuntime->context($addonId) === null, 'Runtime boot ignored an unresolved migration marker.');
    $mismatchedRecovery = $definitions([$v1, $v2, $held]); $mismatchedRecovery[2]['down'][0] = 'DROP TABLE IF EXISTS vvx_addon_recovery_aux';
    $reject(static fn() => $store->recoverAddonMigrations($addonId, $mismatchedRecovery), 'exact original');
    $intent = json_decode((string) file_get_contents($lifecyclePath), true, 512, JSON_THROW_ON_ERROR);
    $assert($intent['phase'] === 'failed' && $intent['recovery'] === 'restore' && $manager->pendingRecoveries()[0]['canRetry'], 'Known failed compensation did not retain a safely resumable package intent.');
    // Actual HTTP performs owner/CSRF negative checks, a still-blocked retry,
    // then a test operator removes only the fixture-owned FK blocker. The real
    // owner form must resume without repeating the earlier strict DROP.
    $process = proc_open(['python3', $source . '/tests/Http/addon_recovery_http.py', $runtime], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run the real owner recovery HTTP acceptance.');
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Owner recovery HTTP acceptance failed: ' . $errors . $output);
    echo $output;
    clearstatcache();
    $assert(!is_file($recoveryPath) && !is_file($lifecyclePath) && !$tableExists('vvx_addon_recovery_parent') && !$columnExists('vvx_addon_records', 'note_text'), 'Recovery did not resume from its exact completed boundary.');
    $assert($store->addonMigrations($addonId) === $ledger1 && $pdo->query('SELECT * FROM vvx_addon_records')->fetchAll() === $addonRows, 'Recovery changed pre-existing rows or base migration history.');
    $assert($store->addon($addonId)['status'] === 'active', 'Completed owner recovery did not restore the prior active status.');
    $preserved();
    echo "PASS add-on MySQL: failed compensation is held, durable, non-bootable and resumable without repeating successful down SQL\n";

    if ($browserAcceptance) {
        foreach (['desktop', 'mobile'] as $viewport) {
            $case = 'real packaged owner recovery browser: ' . $viewport;
            $reject(static fn() => $install('3.0.0', [$v1, $v2, $held]), 'recovery is incomplete');
            $process = proc_open([getenv('VELVET_TEST_PYTHON') ?: 'python3', $source . '/tests/Browser/addon_recovery_browser.py', $runtime, $viewport],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Could not run the real owner recovery browser acceptance.');
            $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new RuntimeException('Owner recovery browser acceptance failed: ' . $errors . $output);
            echo $output;
            clearstatcache();
            $assert(!is_file($recoveryPath) && !is_file($lifecyclePath) && $store->addon($addonId)['status'] === 'active', 'Browser recovery left unresolved state or a disabled prior package.');
            $assert($currentFileHashes() === $beforeFiles && $store->addonMigrations($addonId) === $ledger1
                && $pdo->query('SELECT * FROM vvx_addon_records')->fetchAll() === $addonRows, 'Browser recovery changed retained package files, migration history or rows.');
            $preserved();
        }
    }

    $case = 'activation failure after explicit rollback is held honestly';
    $install('2.0.0', [$v1, $v2]);
    $pdo->exec("UPDATE vvx_addon_records SET note_text='Version two data requires a database backup' WHERE id=1");
    $pdo->exec('INSERT INTO vvx_addon_notes VALUES (1,1)');
    $v2Files = $currentFileHashes();
    $pdo->exec("CREATE TRIGGER vvx_reject_rollback_registry BEFORE UPDATE ON addons FOR EACH ROW BEGIN IF NEW.version='1.0.0' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture rollback registry rejection'; END IF; END");
    try { $reject(static fn() => $manager->rollbackLatest($addonId), 'schema rollback committed'); }
    finally { $pdo->exec('DROP TRIGGER vvx_reject_rollback_registry'); }
    $assert($currentFileHashes() === $v2Files && $store->addon($addonId)['version'] === '2.0.0', 'Failed rollback activation discarded the prior files/identity.');
    $assert($store->addon($addonId)['status'] === 'quarantined' && is_file($lifecyclePath) && !is_file($recoveryPath), 'Committed schema rollback was not held across the cleared SQL-journal boundary.');
    $assert(!$columnExists('vvx_addon_records', 'note_text') && !$tableExists('vvx_addon_notes'), 'Test did not reach the real committed-down/failed-registry boundary.');
    $reject(static fn() => $manager->activate($addonId), 'pending migration recovery');
    $intent = json_decode((string) file_get_contents($lifecyclePath), true, 512, JSON_THROW_ON_ERROR);
    $assert($intent['recovery'] === 'activate' && $manager->pendingRecoveries()[0]['canRetry'], 'A definite rejected rollback registry write did not preserve the selected target for recovery.');
    $message = $manager->recoverPending($addonId);
    $assert(str_contains($message, 'database backup') && $store->addon($addonId)['version'] === '1.0.0' && $store->addon($addonId)['status'] === 'active', 'Recovery did not complete the selected rollback or explain the data limitation.');
    $assert(!$columnExists('vvx_addon_records', 'note_text') && !$tableExists('vvx_addon_notes') && $store->addonMigrations($addonId) === $ledger1
        && $pdo->query('SELECT * FROM vvx_addon_records')->fetchAll() === $addonRows, 'Rollback recovery falsely recreated newer schema/data or changed retained base rows.');
    $assert(!is_file($lifecyclePath) && !is_file($recoveryPath), 'Completed rollback recovery left a marker.');
    $preserved();
    echo "PASS add-on MySQL: committed down SQL is not falsely presented as an automatic data restore after activation failure\n";

    $case = 'failed update compensation must restore the previous package';
    $beforeFiles = $currentFileHashes();
    $registryHeld = ['id' => '002_registry_held', 'up' => [
        'CREATE TABLE vvx_addon_registry_aux (id INT PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE vvx_addon_registry_parent (id INT PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE vvx_addon_registry_child (id INT PRIMARY KEY, parent_id INT, CONSTRAINT vvx_registry_fk FOREIGN KEY(parent_id) REFERENCES vvx_addon_registry_parent(id)) ENGINE=InnoDB',
    ], 'down' => ['DROP TABLE vvx_addon_registry_aux', 'DROP TABLE vvx_addon_registry_parent', 'DROP TABLE IF EXISTS vvx_addon_registry_child']];
    $pdo->exec("CREATE TRIGGER vvx_reject_compensated_registry BEFORE UPDATE ON addons FOR EACH ROW BEGIN IF NEW.version='2.0.0' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture compensated registry rejection'; END IF; END");
    try { $reject(static fn() => $install('2.0.0', [$v1, $registryHeld]), 'recovery is incomplete'); }
    finally { $pdo->exec('DROP TRIGGER vvx_reject_compensated_registry'); }
    $intent = json_decode((string) file_get_contents($lifecyclePath), true, 512, JSON_THROW_ON_ERROR);
    $progress = json_decode((string) file_get_contents($recoveryPath), true, 512, JSON_THROW_ON_ERROR);
    $assert($progress['operation'] === 'rollback' && $progress['steps'][0]['next'] === 1 && $intent['recovery'] === 'restore', 'Update compensation was confused with a selected version rollback.');
    $assert($currentFileHashes() === $beforeFiles && $store->addon($addonId)['version'] === '1.0.0', 'Failed compensation replaced the prior package.');
    $pdo->exec('DROP TABLE vvx_addon_registry_child');
    $manager->recoverPending($addonId);
    $assert($store->addon($addonId)['version'] === '1.0.0' && $store->addon($addonId)['status'] === 'active' && $store->addonMigrations($addonId) === $ledger1,
        'Completed update compensation incorrectly activated the rejected new package.');
    $assert(!$tableExists('vvx_addon_registry_aux') && !$tableExists('vvx_addon_registry_parent') && !is_file($lifecyclePath) && !is_file($recoveryPath), 'Completed update recovery left temporary schema or markers.');
    $preserved();
    echo "PASS add-on MySQL: registry-failure compensation resumes toward the previous package, not the rejected target\n";

    $case = 'actual interruption after SQL completion retains lifecycle intent';
    $archive = $package('2.0.0', [$v1, $v2]);
    $pdo->exec("CREATE TRIGGER vvx_hold_activation_registry BEFORE UPDATE ON addons FOR EACH ROW BEGIN IF NEW.addon_key='fixture.mysql-lifecycle' AND NEW.version='2.0.0' THEN DO SLEEP(8); SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture interrupted registry boundary'; END IF; END");
    $worker = null;
    try {
        $worker = proc_open([PHP_BINARY, $source . '/tests/Database/addon_lifecycle_worker.php', $runtime, $archive],
            [1 => ['file', $work . '/worker-output.log', 'w'], 2 => ['file', $work . '/worker-error.log', 'w']], $pipes);
        if (!is_resource($worker)) throw new RuntimeException('Could not start the killable lifecycle fixture.');
        $deadline = microtime(true) + 10;
        $atRegistry = false;
        do {
            clearstatcache();
            $intent = is_file($lifecyclePath) ? json_decode((string) file_get_contents($lifecyclePath), true) : [];
            foreach ($pdo->query('SHOW PROCESSLIST')->fetchAll() as $connection) {
                if (strtolower((string) ($connection['State'] ?? '')) === 'user sleep' && ($connection['db'] ?? '') === $db['name']
                    && ($intent['phase'] ?? '') === 'files_active') $atRegistry = true;
            }
            if ($atRegistry) break;
            if (!proc_get_status($worker)['running']) throw new RuntimeException('Lifecycle worker ended before the boundary: ' . file_get_contents($work . '/worker-error.log'));
            usleep(25000);
        } while (microtime(true) < $deadline);
        $assert($atRegistry, 'Did not observe the actual registry write while lifecycle intent was active.');
        $assert(!is_file($recoveryPath) && $columnExists('vvx_addon_records', 'note_text'), 'The SQL journal was not cleared before the observed activation boundary.');
        $assert(is_dir($runtime . '/' . $intent['oldPath']) && json_decode((string) file_get_contents($installedRoot . '/addon.json'), true)['version'] === '2.0.0', 'Prior files were discarded before registry success.');
        proc_terminate($worker, 9);
        proc_close($worker); $worker = null;
    } finally {
        if (is_resource($worker)) { proc_terminate($worker, 9); proc_close($worker); }
        $pdo->exec('DROP TRIGGER vvx_hold_activation_registry');
    }
    clearstatcache();
    $assert(is_file($lifecyclePath) && !is_file($recoveryPath), 'Process termination lost the package intent after SQL completed.');
    $reject(static fn() => $manager->activate($addonId), 'pending migration recovery');
    $reject(static fn() => $manager->recoverPending($addonId), 'unknown outcome');
    $assert(!$manager->pendingRecoveries()[0]['canRetry'], 'An uncertain package outcome was offered as a safe retry.');
    $stale = $store->addon($addonId); $stale['status'] = 'active'; $store->saveAddon($stale);
    $interruptedRuntime = new \App\Core\AddonRuntime($store); $interruptedRuntime->boot();
    $assert($interruptedRuntime->context($addonId) === null, 'Runtime ignored lifecycle-only recovery after an actual process interruption.');
    // Only the test operator can reconcile this intentionally interrupted
    // package. No product recovery path guesses that outcome or clears intent.
    $heldTarget = $work . '/interrupted-target';
    $assert(rename($installedRoot, $heldTarget) && rename($runtime . '/' . $intent['oldPath'], $installedRoot), 'Fixture operator could not restore the retained prior files.');
    $store->rollbackAddonMigrationKeys($addonId, $definitions([$v1, $v2]), ['002_notes']);
    $store->saveAddon($intent['current']);
    $assert($currentFileHashes() === $beforeFiles && $store->addonMigrations($addonId) === $ledger1 && $pdo->query('SELECT * FROM vvx_addon_records')->fetchAll() === $addonRows,
        'Fixture reconciliation did not preserve the exact previous files, ledger and retained data.');
    $operatorIntent = new \App\Core\AddonMigrationRecovery($addonId, 'lifecycle'); $operatorIntent->lock(); $operatorIntent->clear(); unset($operatorIntent);
    $preserved();
    echo "PASS add-on MySQL: killed activation remains disabled with prior files retained after the SQL journal is cleared\n";

    $case = 'preserve-data uninstall, reinstall and owned purge';
    $store->setAddonSetting($addonId, 'sentinel', 'Retained add-on setting');
    $store->saveAddonTask(['addonKey' => $addonId, 'taskKey' => 'fixture-task', 'handler' => 'fixture-handler']);
    $task = $store->addonTask($addonId, 'fixture-task');
    $pdo->exec("CREATE TRIGGER vvx_reject_uninstall_registry BEFORE DELETE ON addons FOR EACH ROW BEGIN IF OLD.addon_key='fixture.mysql-lifecycle' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture uninstall registry rejection'; END IF; END");
    try { $reject(static fn() => $manager->uninstall($addonId, false), 'Fixture uninstall registry rejection'); }
    finally { $pdo->exec('DROP TRIGGER vvx_reject_uninstall_registry'); }
    $assert($currentFileHashes() === $beforeFiles && $store->addon($addonId)['status'] === 'active' && $store->addonTask($addonId, 'fixture-task') === $task,
        'Preserved uninstall registry failure lost prior files, activity status or transactional task metadata.');
    $assert(!is_file($lifecyclePath) && $store->addonMigrations($addonId) === $ledger1 && $pdo->query('SELECT * FROM vvx_addon_records')->fetchAll() === $addonRows, 'Failed preserved uninstall changed data or left unnecessary recovery.');
    $manager->uninstall($addonId, false);
    $assert($store->addon($addonId) === null && !is_dir($installedRoot), 'Preserve-data uninstall left an installed package.');
    $assert($tableExists('vvx_addon_records') && $pdo->query('SELECT * FROM vvx_addon_records')->fetchAll() === $addonRows && $store->addonMigrations($addonId) === $ledger1, 'Preserve-data uninstall changed schema, records or migration history.');
    $assert(($store->addonSettings($addonId)['sentinel'] ?? '') === 'Retained add-on setting', 'Preserve-data uninstall deleted settings.');
    $install('1.0.0', [$v1]);
    $assert($pdo->query('SELECT * FROM vvx_addon_records')->fetchAll() === $addonRows && $store->addonMigrations($addonId) === $ledger1, 'Reinstall did not reuse preserved schema/history.');
    $beforeFiles = $currentFileHashes();
    $store->saveAddonTask(['addonKey' => $addonId, 'taskKey' => 'fixture-task', 'handler' => 'fixture-handler']);
    $task = $store->addonTask($addonId, 'fixture-task');
    $pdo->exec("CREATE TRIGGER vvx_reject_purge_registry BEFORE DELETE ON addons FOR EACH ROW BEGIN IF OLD.addon_key='fixture.mysql-lifecycle' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture purge registry rejection'; END IF; END");
    try { $reject(static fn() => $manager->uninstall($addonId, true), 'Fixture purge registry rejection'); }
    finally { $pdo->exec('DROP TRIGGER vvx_reject_purge_registry'); }
    $assert(!$tableExists('vvx_addon_records') && $store->addonMigrations($addonId) === [], 'The fixture did not reach committed purge SQL before registry deletion failed.');
    $assert($store->addon($addonId)['status'] === 'quarantined' && $currentFileHashes() === $beforeFiles && $store->addonTask($addonId, 'fixture-task') === $task,
        'Post-purge registry failure lost prior files/transactional metadata or left the add-on active.');
    $assert(($store->addonSettings($addonId)['sentinel'] ?? '') === 'Retained add-on setting' && is_file($lifecyclePath) && !is_file($recoveryPath),
        'Post-purge registry failure cleared durable lifecycle intent or partly committed metadata deletion.');
    $reject(static fn() => $manager->activate($addonId), 'pending migration recovery');
    $intent = json_decode((string) file_get_contents($lifecyclePath), true, 512, JSON_THROW_ON_ERROR);
    $assert($intent['operation'] === 'uninstall' && $intent['recovery'] === 'uninstall' && $manager->pendingRecoveries()[0]['canRetry'], 'Definite post-purge registry rejection lost its selected removal intent.');
    $message = $manager->recoverPending($addonId);
    $assert(str_contains($message, 'database backup') && !is_file($lifecyclePath), 'Completed purge recovery did not clear intent or state its data limitation.');
    $assert(!$tableExists('vvx_addon_records') && $store->addonMigrations($addonId) === [] && $store->addonSettings($addonId) === [], 'Explicit purge did not remove the add-on-owned tables and records.');
    $assert($store->addon($addonId) === null && !is_dir($installedRoot), 'Purge retained an installed registry/file tree.');
    $preserved();
    echo "PASS add-on MySQL: preserved uninstall/reinstall and explicit owned-table purge\n";

    $case = 'known failed purge down SQL resumes the selected removal';
    $install('1.0.0', [$v1]);
    $pdo->exec("INSERT INTO vvx_addon_records VALUES (1,'Disposable row for the selected purge retry')");
    $pdo->exec('CREATE TABLE vvx_addon_purge_blocker (id INT PRIMARY KEY, parent_id INT, CONSTRAINT vvx_purge_fk FOREIGN KEY(parent_id) REFERENCES vvx_addon_records(id)) ENGINE=InnoDB');
    $reject(static fn() => $manager->uninstall($addonId, true), 'rollback is incomplete');
    $progress = json_decode((string) file_get_contents($recoveryPath), true, 512, JSON_THROW_ON_ERROR);
    $intent = json_decode((string) file_get_contents($lifecyclePath), true, 512, JSON_THROW_ON_ERROR);
    $assert($progress['operation'] === 'rollback' && $progress['steps'][0]['next'] === 0 && !$progress['pendingStatement']
        && $intent['recovery'] === 'uninstall' && $store->addon($addonId)['status'] === 'quarantined', 'Failed purge lost its exact known down boundary or removal intent.');
    $reject(static fn() => $manager->recoverPending($addonId), 'still incomplete');
    $assert($tableExists('vvx_addon_records') && count($store->addonMigrations($addonId)) === 1 && is_dir($installedRoot), 'Still-blocked purge deleted ledger, files or retained rows.');
    // The test operator removes only its own deliberately introduced blocker.
    $pdo->exec('DROP TABLE vvx_addon_purge_blocker');
    $manager->recoverPending($addonId);
    $assert(!$tableExists('vvx_addon_records') && $store->addon($addonId) === null && !is_dir($installedRoot)
        && !is_file($recoveryPath) && !is_file($lifecyclePath), 'Known purge retry did not finish the original selected removal.');
    $preserved();
    echo "PASS add-on MySQL: a definite failed purge step resumes only its original recorded removal\n";
    echo 'Add-on MySQL lifecycle acceptance passed: ' . $checks . ' assertions; PHP ' . PHP_VERSION . '; MySQL ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ".\n";
} finally {
    // The initial empty-schema guard grants ownership only of this disposable
    // schema. Cleanup is mandatory even when a negative fixture fails early.
    if ($ownsDatabase) {
        vvx_installer_fixture_reset_database($pdo, true);
        if ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) !== []) throw new RuntimeException('Add-on fixture cleanup left database tables behind.');
    }
    if (is_file($runtime . '/.vvx-installer-db-fixture')) vvx_installer_fixture_remove($work);
}

echo "Add-on fixture database and extracted runtime cleanup passed.\n";

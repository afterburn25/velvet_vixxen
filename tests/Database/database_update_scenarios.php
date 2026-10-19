<?php
declare(strict_types=1);

// This is only invoked after the shared runner proved its explicitly named test database was empty.
if (!isset($pdo, $root, $host, $port, $name, $user, $password, $existing) || $existing !== []
    || !($pdo instanceof PDO) || !preg_match('/(?:^|_)test(?:_|$)/i', $name)) {
    throw new RuntimeException('Installed update scenarios require the guarded empty-database test runner.');
}
require_once $root . '/includes/installation_recovery.php';
require_once $root . '/app/Support/functions.php';
$updateTestRoot = sys_get_temp_dir() . '/vvx-installed-update-test-' . bin2hex(random_bytes(8));
$updateTestChecks = 0;
$updateTestAssert = static function (bool $condition, string $message) use (&$updateTestChecks): void {
    if (!$condition) throw new RuntimeException($message);
    $updateTestChecks++;
};
$updateTestReject = static function (callable $action, string $message) use ($updateTestAssert): void {
    $rejected = false;
    try { $action(); } catch (RuntimeException|PDOException) { $rejected = true; }
    $updateTestAssert($rejected, $message);
};
$updateTestRemove = static function (string $path) use (&$updateTestRemove): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    @chmod($path, 0700);
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $updateTestRemove($path . '/' . $entry);
    @rmdir($path);
};
$updateTestWriteMigration = static function (string $key, array $up, array $down) use ($updateTestRoot): void {
    file_put_contents($updateTestRoot . '/database/migrations/' . $key . '.php', "<?php\nreturn "
        . var_export(['id' => $key, 'description' => 'Disposable update safety fixture', 'up' => $up, 'down' => $down], true) . ";\n");
};
try {
    // Rewind only the still-empty new tables in this disposable schema to simulate a real v0.8.1 upgrade.
    foreach (['booking_form_submissions', 'merchandise_reservations', 'merchandise_variants', 'merchandise_products'] as $table) {
        if ((int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) throw new RuntimeException('Refusing to rewind a nonempty update-test table: ' . $table);
    }
    foreach (['202610190014_merchandise', '202610190013_booking_form_builder'] as $key) {
        $definition = require $root . '/database/migrations/' . $key . '.php';
        foreach ($definition['down'] as $sql) $pdo->exec($sql);
        $statement = $pdo->prepare('DELETE FROM migrations WHERE migration_key=?'); $statement->execute([$key]);
    }
    foreach (['config', 'storage', 'uploads', 'install', 'database/migrations', 'includes', 'app/Core', 'app/Console'] as $directory) {
        mkdir($updateTestRoot . '/' . $directory, 0700, true);
    }
    foreach (glob($root . '/database/migrations/*.php') ?: [] as $file) copy($file, $updateTestRoot . '/database/migrations/' . basename($file));
    foreach (['includes/autoload.php', 'includes/installation_recovery.php', 'app/Core/Database.php', 'app/Core/MigrationRunner.php',
        'app/Core/DatabaseUpdateService.php', 'app/Console/migrate.php'] as $relative) copy($root . '/' . $relative, $updateTestRoot . '/' . $relative);
    $updateTestInstallation = 'fixture-installed-update-' . bin2hex(random_bytes(8));
    $updateTestConfig = ['installed' => true, 'installation_id' => $updateTestInstallation, 'storage_mode' => 'mysql',
        'db' => compact('host', 'port', 'name', 'user', 'password') + ['charset' => 'utf8mb4'],
        'security' => ['app_key' => str_repeat('a', 48), 'signing_key' => str_repeat('s', 48), 'media_key' => str_repeat('m', 48)]];
    $updateTestConfigBytes = "<?php\nreturn " . var_export($updateTestConfig, true) . ";\n";
    $updateTestLockBytes = json_encode(['installed' => true, 'installation_id' => $updateTestInstallation], JSON_THROW_ON_ERROR);
    file_put_contents($updateTestRoot . '/config/app.php', $updateTestConfigBytes); chmod($updateTestRoot . '/config/app.php', 0600);
    file_put_contents($updateTestRoot . '/storage/install.lock', $updateTestLockBytes);
    file_put_contents($updateTestRoot . '/uploads/customer-fixture.txt', 'Preserve this pre-existing customer upload.');
    file_put_contents($updateTestRoot . '/install/fixture.txt', 'CLI must not bootstrap installer recovery.');
    $updateTestIdentity = $pdo->prepare('INSERT INTO installation_state(state_key,state_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE state_value=VALUES(state_value)');
    $updateTestIdentity->execute(['installation', json_encode(['installation_id' => $updateTestInstallation])]);
    $pdo->exec("CREATE TABLE vvx_upgrade_customer (id INT PRIMARY KEY, customer_value VARCHAR(100) NOT NULL) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO vvx_upgrade_customer VALUES (1,'Pre-existing customer record')");
    $updateTestStore = new \App\Core\Store(new \App\Core\Config($updateTestRoot . '/config/app.php'));
    $updateTestBooking = $updateTestStore->createBookingRequest(['contactName' => 'Existing Booking Fixture',
        'contactEmail' => 'upgrade-existing@example.test', 'details' => 'Existing private inquiry must survive the database upgrade.']);
    $updateTestService = new \App\Core\DatabaseUpdateService($updateTestRoot);
    $updateTestPlan = $updateTestService->preview();
    $updateTestAssert(count($updateTestPlan['applied']) === 12 && array_column($updateTestPlan['pending'], 'id') ===
        ['202610190013_booking_form_builder', '202610190014_merchandise'], 'Installed site did not list exactly the two pending013/014 updates.');
    $updateTestAssert(!file_exists($updateTestRoot . '/storage/private'), 'Read-only update inspection wrote runtime state.');
    $updateTestRegistryBefore = $pdo->query('SELECT * FROM migrations ORDER BY migration_key')->fetchAll();
    $updateTestReject(static fn() => $updateTestService->apply($updateTestPlan['plan'], false, ''), 'Database updates bypassed backup acknowledgment.');
    $updateTestReject(static fn() => $updateTestService->apply('stale-plan', true, 'Fixture backup verified'), 'A stale reviewed update plan was accepted.');
    $updateTestAssert($pdo->query('SELECT * FROM migrations ORDER BY migration_key')->fetchAll() === $updateTestRegistryBefore, 'Blocked update attempts modified migration state.');
    $updateTestApplied = $updateTestService->apply($updateTestPlan['plan'], true, 'Hosting fixture backup, verified before upgrade', false, 'Fixture owner');
    $updateTestAssert($updateTestApplied['status'] === 'completed' && count($updateTestApplied['applied']) === 2, 'Actual013/014 installed-site update did not complete.');
    $updateTestAssert($updateTestService->preview()['pending'] === [], 'Completed updates remained pending.');
    $updateTestAssert($updateTestStore->bookingRequest($updateTestBooking['id'])['details'] === $updateTestBooking['details'], 'Update altered an existing private booking.');
    $updateTestAssert($pdo->query('SELECT customer_value FROM vvx_upgrade_customer WHERE id=1')->fetchColumn() === 'Pre-existing customer record', 'Update changed existing customer data.');
    $updateTestAfter = $updateTestService->preview();
    $updateTestRegistryAfter = $pdo->query('SELECT * FROM migrations ORDER BY migration_key')->fetchAll();
    $updateTestAssert($updateTestService->apply($updateTestAfter['plan'], false, '')['status'] === 'current', 'Applying an already-current database was not a no-op.');
    $updateTestAssert($pdo->query('SELECT * FROM migrations ORDER BY migration_key')->fetchAll() === $updateTestRegistryAfter, 'Idempotent apply rewrote prior migration records.');
    $updateTestAssert(file_get_contents($updateTestRoot . '/config/app.php') === $updateTestConfigBytes
        && file_get_contents($updateTestRoot . '/storage/install.lock') === $updateTestLockBytes
        && file_get_contents($updateTestRoot . '/uploads/customer-fixture.txt') === 'Preserve this pre-existing customer upload.', 'Upgrade changed configuration, keys, installation lock or customer uploads.');

    // A deliberately failing DDL migration contains destructive down SQL: installed-site mode must never run it.
    $updateTestWriteMigration('999900000015_update_success', [
        'CREATE TABLE vvx_update_completed (id INT PRIMARY KEY, marker VARCHAR(50)) ENGINE=InnoDB',
        "INSERT INTO vvx_update_completed VALUES (1,'Completed migration remains')",
    ], ['DROP TABLE IF EXISTS vvx_update_completed']);
    $updateTestWriteMigration('999900000016_update_failure', [
        'CREATE TABLE IF NOT EXISTS vvx_update_partial (id INT PRIMARY KEY, marker VARCHAR(50)) ENGINE=InnoDB',
        "INSERT INTO vvx_update_partial VALUES (1,'Partial schema remains for review')", 'INTENTIONALLY INVALID SQL FIXTURE',
    ], ['DROP TABLE IF EXISTS vvx_upgrade_customer', 'DROP TABLE IF EXISTS vvx_update_partial']);
    $updateTestFailurePlan = $updateTestService->preview();
    $updateTestReject(static fn() => $updateTestService->apply($updateTestFailurePlan['plan'], true, 'Second verified fixture backup'), 'Deliberately failing DDL update did not report failure.');
    $updateTestAssert($pdo->query('SELECT customer_value FROM vvx_upgrade_customer WHERE id=1')->fetchColumn() === 'Pre-existing customer record', 'Failure ran destructive down SQL against pre-existing data.');
    $updateTestAssert((int) $pdo->query('SELECT COUNT(*) FROM vvx_update_completed')->fetchColumn() === 1, 'Failure automatically rolled back a completed update.');
    $updateTestAssert((int) $pdo->query('SELECT COUNT(*) FROM vvx_update_partial')->fetchColumn() === 1, 'Failed DDL was deleted instead of preserved for review.');
    $updateTestFailedStatus = $updateTestService->preview();
    $updateTestAssert($updateTestFailedStatus['lastRun']['status'] === 'failed'
        && $updateTestFailedStatus['lastRun']['applied'] === ['999900000015_update_success']
        && $updateTestFailedStatus['lastRun']['automaticRollback'] === false, 'Operator status omitted completed/failed migration facts.');
    $updateTestReject(static fn() => $updateTestService->apply($updateTestFailedStatus['plan'], true, 'Reviewed backup'), 'Failed updates retried without explicit partial-change review.');
    $updateTestWriteMigration('999900000016_update_failure', [
        'CREATE TABLE IF NOT EXISTS vvx_update_partial (id INT PRIMARY KEY, marker VARCHAR(50)) ENGINE=InnoDB',
        "INSERT IGNORE INTO vvx_update_partial VALUES (1,'Partial schema remains for review')",
    ], ['DROP TABLE IF EXISTS vvx_upgrade_customer', 'DROP TABLE IF EXISTS vvx_update_partial']);
    $updateTestRecoveryPlan = $updateTestService->preview();
    $updateTestRecovered = $updateTestService->apply($updateTestRecoveryPlan['plan'], true, 'Backup after partial-schema review', true);
    $updateTestAssert($updateTestRecovered['applied'] === ['999900000016_update_failure'], 'Reviewed retry reran an already recorded migration.');

    $updateTestWriteMigration('999900000017_update_blocked', ['CREATE TABLE vvx_update_blocked (id INT PRIMARY KEY) ENGINE=InnoDB'], ['DROP TABLE IF EXISTS vvx_update_blocked']);
    $updateTestBlockedPlan = $updateTestService->preview();
    $updateTestStateDirectory = $updateTestRoot . '/storage/private/database-updates';
    chmod($updateTestStateDirectory, 0500);
    $updateTestReject(static fn() => $updateTestService->apply($updateTestBlockedPlan['plan'], true, 'Verified fixture backup'), 'Read-only private update storage allowed a schema update.');
    chmod($updateTestStateDirectory, 0700);
    $updateTestLock = fopen($updateTestStateDirectory . '/apply.lock', 'c+b'); flock($updateTestLock, LOCK_EX);
    try { $updateTestReject(static fn() => $updateTestService->apply($updateTestBlockedPlan['plan'], true, 'Verified fixture backup'), 'A second updater bypassed the filesystem lock.'); }
    finally { flock($updateTestLock, LOCK_UN); fclose($updateTestLock); }
    $updateTestDatabaseLockName = 'vvx-update-' . substr(hash('sha256', $name . '|' . $updateTestInstallation), 0, 48);
    $updateTestDatabaseLock = $pdo->prepare('SELECT GET_LOCK(?,0)'); $updateTestDatabaseLock->execute([$updateTestDatabaseLockName]);
    try { $updateTestReject(static fn() => $updateTestService->apply($updateTestBlockedPlan['plan'], true, 'Verified fixture backup'), 'A different process bypassed the database advisory lock.'); }
    finally { $updateTestRelease = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $updateTestRelease->execute([$updateTestDatabaseLockName]); }
    file_put_contents($updateTestRoot . '/outside-status.txt', 'Preserve outside fixture status.');
    rename($updateTestStateDirectory . '/last-run.json', $updateTestStateDirectory . '/status-backup.json');
    symlink($updateTestRoot . '/outside-status.txt', $updateTestStateDirectory . '/last-run.json');
    $updateTestReject(static fn() => $updateTestService->preview(), 'Linked update status was trusted.');
    unlink($updateTestStateDirectory . '/last-run.json'); rename($updateTestStateDirectory . '/status-backup.json', $updateTestStateDirectory . '/last-run.json');
    $updateTestAssert(file_get_contents($updateTestRoot . '/outside-status.txt') === 'Preserve outside fixture status.', 'Update status handling wrote outside its private directory.');
    symlink($updateTestRoot . '/config/app.php', $updateTestRoot . '/database/migrations/999900000018_linked.php');
    $updateTestReject(static fn() => $updateTestService->preview(), 'Linked executable migration was loaded.');
    unlink($updateTestRoot . '/database/migrations/999900000018_linked.php');
    $updateTestAssert($pdo->query("SHOW TABLES LIKE 'vvx_update_blocked'")->fetchColumn() === false, 'A blocked update still created schema.');
    file_put_contents($updateTestRoot . '/storage/install.lock', json_encode(['installed' => true, 'installation_id' => 'different-fixture-installation']));
    $updateTestReject(static fn() => $updateTestService->preview(), 'Mismatched live installation lock permitted updates.');
    file_put_contents($updateTestRoot . '/storage/install.lock', $updateTestLockBytes);
    $updateTestIdentity->execute(['installation', json_encode(['installation_id' => 'wrong-database-installation'])]);
    $updateTestReject(static fn() => $updateTestService->preview(), 'A database with a different installation identity permitted updates.');
    $updateTestIdentity->execute(['installation', json_encode(['installation_id' => $updateTestInstallation])]);

    // Invoke the production CLI from the minimal installed fixture, which intentionally has no bootstrap.php.
    $updateTestCli = static function (array $arguments) use ($updateTestRoot): array {
        $process = proc_open(array_merge([PHP_BINARY, $updateTestRoot . '/app/Console/migrate.php'], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Unable to run the disposable SQL update CLI.');
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        return ['exit' => proc_close($process), 'output' => $output, 'error' => $error];
    };
    $updateTestCliStatus = $updateTestCli(['--status']);
    $updateTestAssert($updateTestCliStatus['exit'] === 0 && count(json_decode($updateTestCliStatus['output'], true)['pending'] ?? []) === 1, 'Real SQL CLI status failed.');
    $updateTestCliBlocked = $updateTestCli(['--apply']);
    $updateTestAssert($updateTestCliBlocked['exit'] !== 0 && str_contains($updateTestCliBlocked['error'], 'backup'), 'SQL CLI applied without backup confirmation.');
    $updateTestCliApplied = $updateTestCli(['--apply', '--backup-confirmed', '--backup-reference=Verified CLI fixture backup']);
    $updateTestAssert($updateTestCliApplied['exit'] === 0 && (json_decode($updateTestCliApplied['output'], true)['applied'] ?? []) === ['999900000017_update_blocked'], 'Real SQL CLI apply failed: ' . $updateTestCliApplied['error']);
    $updateTestAssert(is_file($updateTestRoot . '/install/fixture.txt') && file_get_contents($updateTestRoot . '/config/app.php') === $updateTestConfigBytes
        && file_get_contents($updateTestRoot . '/storage/install.lock') === $updateTestLockBytes, 'CLI update invoked installer cleanup or replaced installation state.');
    echo 'Installed database update scenarios passed (' . $updateTestChecks . ' checks; actual012→014 upgrade, preserved data/keys/uploads, locks, failure/retry and CLI; server '
        . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ").\n";
} finally {
    foreach (['vvx_update_blocked', 'vvx_update_partial', 'vvx_update_completed', 'vvx_upgrade_customer'] as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    $pdo->exec("DELETE FROM migrations WHERE migration_key IN ('999900000015_update_success','999900000016_update_failure','999900000017_update_blocked')");
    $updateTestRemove($updateTestRoot);
}

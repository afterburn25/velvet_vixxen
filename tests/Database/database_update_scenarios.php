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
    // Reconstruct the historical ENUM only in this exclusively owned schema.
    $updateTestDayKey='202610230020_membership_day_periods';
    $updateTestAssert((int)$pdo->query("SELECT COUNT(*) FROM membership_plans WHERE billing_interval='day'")->fetchColumn()===0,'Refusing to rewind an accepted fixed-day offer.');
    $pdo->exec("ALTER TABLE membership_plans MODIFY billing_interval ENUM('month','year','one_time') NOT NULL DEFAULT 'month'");
    $pdo->prepare('DELETE FROM migrations WHERE migration_key=?')->execute([$updateTestDayKey]);
    $updateTestRecurringKey='202610220019_recurring_lifecycle';
    $updateTestRecurringDefinition=require $root.'/database/migrations/'.$updateTestRecurringKey.'.php';
    foreach($updateTestRecurringDefinition['tables'] as $table)$updateTestAssert((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===0,'Refusing to rewind nonempty recurring fixture state.');
    foreach($updateTestRecurringDefinition['down'] as $sql)$pdo->exec($sql);
    $pdo->prepare('DELETE FROM migrations WHERE migration_key=?')->execute([$updateTestRecurringKey]);
    // Rewind only the new, proved-empty payment table before reconstructing
    // the retained historical update path. Its own 0.9 to 0.9.1 step is below.
    $updateTestPaymentKey = '202610220018_payment_journal';
    $updateTestPaymentDefinition = require $root . '/database/migrations/' . $updateTestPaymentKey . '.php';
    foreach ($updateTestPaymentDefinition['tables'] as $table) $updateTestAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0,
        'Refusing to rewind a nonempty payment fixture table: ' . $table);
    foreach ($updateTestPaymentDefinition['down'] as $sql) $pdo->exec($sql);
    $pdo->prepare('DELETE FROM migrations WHERE migration_key=?')->execute([$updateTestPaymentKey]);
    // Reconstruct the exact historical twelve-migration starting point in the
    // exclusively owned test schema. The current marketing schema must contain
    // no authority or imported customer records before this guarded rewind.
    // The current 0.9 tables are also freshly created and empty here. Rewind
    // only this owned schema, then test their real installed-site update below.
    $updateTestSafeguardKeys = ['202610210016_marketing_measurements', '202610210017_private_state'];
    foreach (array_reverse($updateTestSafeguardKeys) as $key) {
        $definition = require $root . '/database/migrations/' . $key . '.php';
        foreach ($definition['tables'] as $table) {
            $updateTestAssert((int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0,
                'Refusing to rewind nonempty 0.9 fixture table: ' . $table);
        }
        foreach ($definition['down'] as $sql) $pdo->exec($sql);
        $pdo->prepare('DELETE FROM migrations WHERE migration_key=?')->execute([$key]);
    }
    $updateTestMarketingKey = '202610200015_marketing_sql_storage';
    $updateTestMarketingRows = $pdo->query('SELECT * FROM marketing_storage_state')->fetchAll();
    $updateTestAssert($updateTestMarketingRows === [], 'Refusing to rewind existing marketing authority rows.');
    $updateTestAssert((int) $pdo->query('SELECT COUNT(*) FROM marketing_storage_records')->fetchColumn() === 0,
        'Refusing to rewind imported marketing records.');
    $updateTestAssert($pdo->query("SELECT setting_value FROM settings WHERE setting_key='marketing_sql_storage'")->fetchColumn() === false,
        'Refusing to rewind a database with recorded SQL marketing authority.');
    $updateTestMarketingDefinition = require $root . '/database/migrations/' . $updateTestMarketingKey . '.php';
    foreach ($updateTestMarketingDefinition['down'] as $sql) $pdo->exec($sql);
    $pdo->prepare('DELETE FROM migrations WHERE migration_key=?')->execute([$updateTestMarketingKey]);
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
    foreach (glob($root . '/database/migrations/*.php') ?: [] as $file) {
        if (strcmp(basename($file), '202610190014_merchandise.php') <= 0) {
            copy($file, $updateTestRoot . '/database/migrations/' . basename($file));
        }
    }
    foreach (['includes/autoload.php', 'includes/installation_recovery.php', 'app/Core/Config.php', 'app/Core/OperationsGate.php', 'app/Core/Database.php', 'app/Core/MigrationRunner.php',
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

    // Install migration015 through the same production updater used by an
    // already-installed 0.8.5 site. Schema installation does not activate SQL or
    // rewrite the separately reviewed legacy marketing snapshot.
    $updateTestMarketingDirectory = $updateTestRoot . '/storage/private/marketing';
    mkdir($updateTestMarketingDirectory, 0700, true);
    $updateTestMarketingBytes = json_encode(['meta' => ['revision' => 27, 'analytics_salt' => 'retained-fixture-salt'],
        'contacts' => [['id' => 'retained-newsletter-contact', 'email' => 'retained-newsletter@example.test',
            'status' => 'unsubscribed', 'unsubscribe_token' => 'retained-fixture-token']],
        'consents' => [['contact_id' => 'retained-newsletter-contact', 'type' => 'newsletter', 'granted' => false]],
        'campaign_queue' => [['id' => 'retained-unknown-delivery', 'status' => 'unknown', 'attempts' => 1]]],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($updateTestMarketingDirectory . '/data.json', $updateTestMarketingBytes);
    $updateTestBeforeMarketingLedger = $pdo->query('SELECT * FROM migrations ORDER BY migration_key')->fetchAll();
    copy($root . '/database/migrations/' . $updateTestMarketingKey . '.php',
        $updateTestRoot . '/database/migrations/' . $updateTestMarketingKey . '.php');
    $updateTestMarketingPlan = $updateTestService->preview();
    $updateTestAssert(count($updateTestMarketingPlan['applied']) === 14
        && array_column($updateTestMarketingPlan['pending'], 'id') === [$updateTestMarketingKey],
        'Installed fourteen-migration site did not list exactly marketing migration015.');
    $updateTestMarketingApplied = $updateTestService->apply($updateTestMarketingPlan['plan'], true,
        'Verified existing-site database and marketing-file fixture backup');
    $updateTestAssert($updateTestMarketingApplied['status'] === 'completed'
        && $updateTestMarketingApplied['applied'] === [$updateTestMarketingKey], 'Actual014→015 installed-site update did not complete.');
    $updateTestAfterMarketingLedger = $pdo->query('SELECT * FROM migrations ORDER BY migration_key')->fetchAll();
    $updateTestAssert(count($updateTestAfterMarketingLedger) === 15
        && array_values(array_filter($updateTestAfterMarketingLedger,
            static fn(array $row): bool => $row['migration_key'] !== $updateTestMarketingKey)) === $updateTestBeforeMarketingLedger,
        'Marketing schema update changed a historical migration row or timestamp.');
    $updateTestAssert($pdo->query('SELECT * FROM marketing_storage_state')->fetchAll() === $updateTestMarketingRows
        && (int) $pdo->query('SELECT COUNT(*) FROM marketing_storage_records')->fetchColumn() === 0
        && $pdo->query("SELECT setting_value FROM settings WHERE setting_key='marketing_sql_storage'")->fetchColumn() === false,
        'Schema update imported records or activated marketing SQL without the separate owner cutover.');
    $updateTestAssert(file_get_contents($updateTestMarketingDirectory . '/data.json') === $updateTestMarketingBytes,
        'Schema update rewrote existing consent, token or unknown campaign outcome bytes.');
    $updateTestAssert(file_get_contents($updateTestRoot . '/config/app.php') === $updateTestConfigBytes
        && file_get_contents($updateTestRoot . '/storage/install.lock') === $updateTestLockBytes
        && file_get_contents($updateTestRoot . '/uploads/customer-fixture.txt') === 'Preserve this pre-existing customer upload.'
        && $updateTestStore->bookingRequest($updateTestBooking['id'])['details'] === $updateTestBooking['details'],
        'Marketing schema update changed installed configuration, keys, owner content or uploads.');
    $updateTestMarketingCurrent = $updateTestService->preview();
    $updateTestAssert($updateTestMarketingCurrent['pending'] === []
        && $updateTestService->apply($updateTestMarketingCurrent['plan'], false, '')['status'] === 'current',
        'Reapplying current marketing migration was not a no-op.');
    $updateTestAssert($pdo->query('SELECT * FROM migrations ORDER BY migration_key')->fetchAll() === $updateTestAfterMarketingLedger
        && file_get_contents($updateTestMarketingDirectory . '/data.json') === $updateTestMarketingBytes,
        'No-op migration apply changed its ledger or legacy marketing data.');

    // Upgrade an actual fifteen-migration (0.8.14) site through the guarded
    // production updater. Existing member, marketing, installation and custom
    // rows must remain byte/value identical, with no module silently activated.
    $updateTestBeforeSafeguards = [];
    foreach (['admins', 'members', 'settings', 'installation_state', 'booking_requests',
        'marketing_storage_state', 'marketing_storage_records', 'vvx_upgrade_customer'] as $table) {
        $updateTestBeforeSafeguards[$table] = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll();
    }
    foreach ($updateTestSafeguardKeys as $key) copy($root . '/database/migrations/' . $key . '.php',
        $updateTestRoot . '/database/migrations/' . $key . '.php');
    $updateTestSafeguardPlan = $updateTestService->preview();
    $updateTestAssert(count($updateTestSafeguardPlan['applied']) === 15
        && array_column($updateTestSafeguardPlan['pending'], 'id') === $updateTestSafeguardKeys,
        '0.8.14 did not list exactly the 016/017 additive updates.');
    $updateTestReject(static fn() => $updateTestService->apply($updateTestMarketingCurrent['plan'], true, 'Old fixture backup'),
        'An old pre-0.9 plan approved the new migration set.');
    $updateTestSafeguardsApplied = $updateTestService->apply($updateTestSafeguardPlan['plan'], true,
        'Verified 0.8.14 database and private-state fixture backup');
    $updateTestAssert($updateTestSafeguardsApplied['status'] === 'completed'
        && $updateTestSafeguardsApplied['applied'] === $updateTestSafeguardKeys,
        'Actual 0.8.14 to 0.9 update did not apply exactly 016/017.');
    $updateTestSafeguardsLedger = $pdo->query('SELECT * FROM migrations ORDER BY migration_key')->fetchAll();
    $updateTestAssert(count($updateTestSafeguardsLedger) === 17
        && array_values(array_filter($updateTestSafeguardsLedger,
            static fn(array $row): bool => !in_array($row['migration_key'], $updateTestSafeguardKeys, true))) === $updateTestAfterMarketingLedger,
        '0.9 changed historical migration rows or timestamps.');
    foreach ($updateTestBeforeSafeguards as $table => $rows) $updateTestAssert(
        $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll() === $rows, '0.9 changed prior rows in ' . $table);
    foreach (['marketing_measurement_state', 'marketing_measurement_records', 'private_state'] as $table) $updateTestAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0, '0.9 activated a journal or report projection during migration.');
    $updateTestAssert(file_get_contents($updateTestRoot . '/config/app.php') === $updateTestConfigBytes
        && file_get_contents($updateTestRoot . '/storage/install.lock') === $updateTestLockBytes
        && file_get_contents($updateTestRoot . '/uploads/customer-fixture.txt') === 'Preserve this pre-existing customer upload.'
        && file_get_contents($updateTestMarketingDirectory . '/data.json') === $updateTestMarketingBytes,
        '0.9 replaced configuration, installation identity, uploads or private marketing bytes.');
    $updateTestSafeguardsCurrent = $updateTestService->preview();
    $updateTestAssert($updateTestSafeguardsCurrent['pending'] === []
        && $updateTestService->apply($updateTestSafeguardsCurrent['plan'], false, '')['status'] === 'current'
        && $pdo->query('SELECT * FROM migrations ORDER BY migration_key')->fetchAll() === $updateTestSafeguardsLedger,
        '0.9 reapply changed the migration ledger.');

    // Preserve pre-journal financial records through the actual additive 018
    // installed-site update. No journal operation may be invented by migration.
    $updateTestLegacyMember = $updateTestStore->createMember(['displayName' => 'Pre-journal fixture', 'email' => 'pre-journal@example.test',
        'password' => 'Pre-Journal-Fixture-123!', 'status' => 'active', 'emailVerifiedAt' => gmdate('c')]);
    $updateTestLegacyOrder = $updateTestStore->createOrder(['memberId' => $updateTestLegacyMember['id'], 'status' => 'paid',
        'subtotal' => 17.25, 'grandTotal' => 17.25, 'currency' => 'USD', 'providerKey' => 'sandbox', 'idempotencyKey' => 'pre-journal-owned-fixture'], []);
    $updateTestStore->savePaymentTransaction(['orderId' => $updateTestLegacyOrder['id'], 'providerKey' => 'sandbox',
        'providerTransactionId' => 'pre-journal-owned-transaction', 'transactionType' => 'charge', 'status' => 'paid', 'amount' => 17.25, 'currency' => 'USD']);
    $updateTestBeforePayments = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if ($table !== 'migrations') $updateTestBeforePayments[$table] = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll();
    }
    copy($root . '/database/migrations/' . $updateTestPaymentKey . '.php', $updateTestRoot . '/database/migrations/' . $updateTestPaymentKey . '.php');
    $updateTestPaymentPlan = $updateTestService->preview();
    $updateTestAssert(count($updateTestPaymentPlan['applied']) === 17 && array_column($updateTestPaymentPlan['pending'], 'id') === [$updateTestPaymentKey],
        'An actual 0.9 database did not list only the additive payment journal migration.');
    $updateTestReject(static fn() => $updateTestService->apply($updateTestSafeguardsCurrent['plan'], true, 'Old fixture backup'),
        'The old 0.9 plan approved the new payment schema.');
    $updateTestPaymentApplied = $updateTestService->apply($updateTestPaymentPlan['plan'], true, 'Verified 0.9 financial and private-state fixture backup');
    $updateTestAssert($updateTestPaymentApplied['status'] === 'completed' && $updateTestPaymentApplied['applied'] === [$updateTestPaymentKey],
        'Actual 0.9 to 0.9.1 update did not apply exactly migration018.');
    $updateTestPaymentLedger = $pdo->query('SELECT * FROM migrations ORDER BY migration_key')->fetchAll();
    $updateTestAssert(count($updateTestPaymentLedger) === 18 && array_values(array_filter($updateTestPaymentLedger,
        static fn(array $row): bool => $row['migration_key'] !== $updateTestPaymentKey)) === $updateTestSafeguardsLedger,
        'The payment migration changed historical ledger entries.');
    foreach ($updateTestBeforePayments as $table => $rows) $updateTestAssert(
        $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll() === $rows, 'The payment migration changed existing rows in ' . $table);
    $updateTestAssert((int) $pdo->query('SELECT COUNT(*) FROM payment_journal')->fetchColumn() === 0,
        'The payment migration invented operations or changed existing financial facts.');
    $updateTestAssert(file_get_contents($updateTestRoot . '/config/app.php') === $updateTestConfigBytes
        && file_get_contents($updateTestRoot . '/storage/install.lock') === $updateTestLockBytes
        && file_get_contents($updateTestRoot . '/uploads/customer-fixture.txt') === 'Preserve this pre-existing customer upload.'
        && file_get_contents($updateTestMarketingDirectory . '/data.json') === $updateTestMarketingBytes,
        'The payment migration replaced existing private files or installation identity.');
    $updateTestPaymentCurrent = $updateTestService->preview();
    $updateTestAssert($updateTestPaymentCurrent['pending'] === [] && $updateTestService->apply($updateTestPaymentCurrent['plan'], false, '')['status'] === 'current'
        && $pdo->query('SELECT * FROM migrations ORDER BY migration_key')->fetchAll() === $updateTestPaymentLedger,
        'The completed payment migration was reapplied.');

    // Actual 018→019 installed update preserves all existing commercial,
    // identity and private data, and never manufactures historical agreements.
    $updateTestBeforeRecurring=[];
    foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$updateTestBeforeRecurring[$table]=$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll();
    copy($root.'/database/migrations/'.$updateTestRecurringKey.'.php',$updateTestRoot.'/database/migrations/'.$updateTestRecurringKey.'.php');
    $updateTestRecurringPlan=$updateTestService->preview();
    $updateTestAssert(count($updateTestRecurringPlan['applied'])===18 && array_column($updateTestRecurringPlan['pending'],'id')===[$updateTestRecurringKey],'The installed 018 database did not isolate the additive recurring update.');
    $updateTestRecurringApplied=$updateTestService->apply($updateTestRecurringPlan['plan'],true,'Verified owned 018 financial and private-state backup');
    $updateTestAssert($updateTestRecurringApplied['status']==='completed' && $updateTestRecurringApplied['applied']===[$updateTestRecurringKey],'Installed recurring update did not apply only019.');
    foreach($updateTestBeforeRecurring as $table=>$rows){
        $after=$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll();
        if($table==='migrations')$after=array_values(array_filter($after,static fn(array $r):bool=>$r['migration_key']!==$updateTestRecurringKey));
        $updateTestAssert($after===$rows,'Recurring migration changed old records in '.$table);
    }
    foreach($updateTestRecurringDefinition['tables'] as $table)$updateTestAssert((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===0,'Migration019 manufactured historical recurring consent or cycles.');
    $updateTestRecurringCurrent=$updateTestService->preview();
    $updateTestAssert($updateTestRecurringCurrent['pending']===[] && $updateTestService->apply($updateTestRecurringCurrent['plan'],false,'')['status']==='current','Recurring migration did not resume idempotently.');
    $updateTestAssert(file_get_contents($updateTestRoot.'/config/app.php')===$updateTestConfigBytes && file_get_contents($updateTestRoot.'/storage/install.lock')===$updateTestLockBytes && file_get_contents($updateTestRoot.'/uploads/customer-fixture.txt')==='Preserve this pre-existing customer upload.','Recurring update changed private files or installation identity.');

    // Actual 019 to 020 widening must not convert existing catalogue or paid facts.
    $updateTestBeforeDay=[];
    foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$updateTestBeforeDay[$table]=$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll();
    copy($root.'/database/migrations/'.$updateTestDayKey.'.php',$updateTestRoot.'/database/migrations/'.$updateTestDayKey.'.php');
    $updateTestDayPlan=$updateTestService->preview();
    $updateTestAssert(count($updateTestDayPlan['applied'])===19 && array_column($updateTestDayPlan['pending'],'id')===[$updateTestDayKey],'The installed 019 schema did not isolate the day-period update.');
    $updateTestDayApplied=$updateTestService->apply($updateTestDayPlan['plan'],true,'Verified owned 019 commercial and private-state backup');
    $updateTestAssert($updateTestDayApplied['status']==='completed' && $updateTestDayApplied['applied']===[$updateTestDayKey],'Day-period update did not apply only020.');
    foreach($updateTestBeforeDay as $table=>$rows){
        $after=$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll();
        if($table==='migrations')$after=array_values(array_filter($after,static fn(array $row):bool=>$row['migration_key']!==$updateTestDayKey));
        sort($rows);sort($after);
        $updateTestAssert($after===$rows,'Day-period migration changed an existing record in '.$table);
    }
    $updateTestDayCurrent=$updateTestService->preview();
    $updateTestAssert($updateTestDayCurrent['pending']===[] && $updateTestService->apply($updateTestDayCurrent['plan'],false,'')['status']==='current','Day-period migration did not resume idempotently.');
    $updateTestAssert(file_get_contents($updateTestRoot.'/config/app.php')===$updateTestConfigBytes && file_get_contents($updateTestRoot.'/storage/install.lock')===$updateTestLockBytes,'Day-period migration changed installation identity or configuration.');

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
    echo 'Installed database update scenarios passed (' . $updateTestChecks . ' checks; actual012→014→015→017→018 upgrades, preserved financial/data/keys/uploads/marketing, locks, failure/retry and CLI; server '
        . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ").\n";
} finally {
    foreach (['vvx_update_blocked', 'vvx_update_partial', 'vvx_update_completed', 'vvx_upgrade_customer'] as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    $pdo->exec("DELETE FROM migrations WHERE migration_key IN ('999900000015_update_success','999900000016_update_failure','999900000017_update_blocked')");
    $updateTestRemove($updateTestRoot);
}

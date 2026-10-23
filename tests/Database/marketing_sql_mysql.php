<?php
declare(strict_types=1);

// Actual Fresh ZIP, actual MySQL 8, independent PHP workers and loopback SMTP.
// Owns only a database it first proves is empty and explicitly test-named.
require dirname(__DIR__) . '/Installer/database_fixture.php';
if (count($argv) !== 1) throw new RuntimeException('Usage: php tests/Database/marketing_sql_mysql.php');
$source = dirname(__DIR__, 2);
[$db, $pdo] = vvx_installer_fixture_database();
if (!in_array($db['host'], ['127.0.0.1', '::1'], true)) throw new RuntimeException('Marketing acceptance requires an explicitly configured loopback MySQL fixture.');
vvx_installer_fixture_empty($pdo);
$ownsDatabase = true;
$work = sys_get_temp_dir() . '/vvx-marketing-sql-' . bin2hex(random_bytes(8));
$runtime = $work . '/runtime-a';
$otherRoot = $work . '/runtime-b';
mkdir($work, 0700, true);
file_put_contents($work . '/.vvx-marketing-sql-acceptance', 'Exclusively owned disposable marketing SQL acceptance.');
$checks = 0; $case = 'actual Fresh package setup'; $children = [];
$migrationKey = '202610200015_marketing_sql_storage';
$collections = ['suppressions', 'posts', 'banners', 'landing_pages', 'links', 'referral_codes',
    'contacts', 'segments', 'campaigns', 'campaign_queue', 'events', 'consents'];
$assert = static function (bool $condition, string $message) use (&$checks, &$case): void {
    if (!$condition) throw new RuntimeException($case . ': ' . $message);
    $checks++;
};
$reject = static function (callable $operation, string $message) use ($assert): Throwable {
    $caught = null;
    try { $operation(); } catch (Throwable $error) { $caught = $error; }
    $assert($caught instanceof RuntimeException || $caught instanceof InvalidArgumentException, $message);
    return $caught;
};
$sqlMarketing = static function () use ($pdo): array {
    return [
        'state' => $pdo->query('SELECT * FROM marketing_storage_state ORDER BY id')->fetchAll(),
        'records' => $pdo->query('SELECT * FROM marketing_storage_records ORDER BY collection,position')->fetchAll(),
        'authority' => $pdo->query("SELECT * FROM settings WHERE setting_key='marketing_sql_storage'")->fetchAll(),
    ];
};
$assertEmptyMarketing = static function () use ($sqlMarketing, $assert): void {
    $assert($sqlMarketing() === ['state' => [], 'records' => [], 'authority' => []], 'A stopped move left a partial import or authority row.');
};
$files = static function (string $root): array {
    $result = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) $result[substr($file->getPathname(), strlen($root) + 1)] = [
            hash_file('sha256', $file->getPathname()), $file->getPerms() & 0777, $file->getMTime()];
    }
    ksort($result);
    return $result;
};
$launch = static function (string $root, string $operation, string $argument = '') use ($source, &$children): int {
    $pipes = [];
    $process = proc_open([PHP_BINARY, $source . '/tests/Database/marketing_sql_worker.php', $root, $operation, $argument],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start the separate marketing SQL fixture process.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $children[] = ['process' => $process, 'pipes' => $pipes, 'out' => '', 'err' => ''];
    return array_key_last($children);
};
$finish = static function (int $index, float $seconds = 20.0) use (&$children, $assert): array {
    $child = &$children[$index];
    $until = microtime(true) + $seconds; $exit = -1;
    do {
        $child['out'] .= stream_get_contents($child['pipes'][1]);
        $child['err'] .= stream_get_contents($child['pipes'][2]);
        $status = proc_get_status($child['process']);
        if (!$status['running']) { $exit = $status['exitcode']; break; }
        usleep(10000);
    } while (microtime(true) < $until);
    $assert(!$status['running'], 'Separate worker did not finish within its bounded fixture deadline.');
    $child['out'] .= stream_get_contents($child['pipes'][1]); $child['err'] .= stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]); fclose($child['pipes'][2]);
    $closed = proc_close($child['process']); $child['process'] = null;
    if ($exit === -1) $exit = $closed;
    $assert($exit === 0, 'Separate worker failed: ' . $child['err']);
    $assert(trim($child['err']) === '', 'Separate worker produced an unexpected warning: ' . $child['err']);
    return json_decode(trim($child['out']), true, 512, JSON_THROW_ON_ERROR);
};
$stop = static function (int $index) use (&$children): void {
    $child = &$children[$index];
    if (!is_resource($child['process'])) return;
    proc_terminate($child['process'], 9);
    foreach ([1, 2] as $pipe) if (is_resource($child['pipes'][$pipe])) fclose($child['pipes'][$pipe]);
    proc_close($child['process']); $child['process'] = null;
};
$signal = static function (string $name) use ($work): void {
    file_put_contents($work . '/' . $name . '.json', '{"release":true}', LOCK_EX);
};
$barrier = static function (string $name, float $seconds = 15.0) use ($work, &$children): array {
    $path = $work . '/' . $name . '.json'; $until = microtime(true) + $seconds;
    do {
        clearstatcache(true, $path);
        if (is_file($path)) return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach ($children as &$child) {
            if (!is_resource($child['process'])) continue;
            $child['out'] .= stream_get_contents($child['pipes'][1]); $child['err'] .= stream_get_contents($child['pipes'][2]);
        }
        unset($child);
        usleep(10000);
    } while (microtime(true) < $until);
    $errors = implode('', array_column($children, 'err'));
    throw new RuntimeException('Worker barrier timed out: ' . $name . ($errors === '' ? '' : '; ' . $errors));
};
$rowById = static function (array $rows, string $id): array {
    foreach ($rows as $row) if (($row['id'] ?? '') === $id) return $row;
    throw new RuntimeException('The expected synthetic fixture record is missing.');
};
$restoreRows = static function (string $table, array $rows) use ($pdo): void {
    foreach ($rows as $row) {
        $columns = implode(',', array_map('vvx_installer_fixture_quote', array_keys($row)));
        $statement = $pdo->prepare('INSERT INTO ' . vvx_installer_fixture_quote($table) . ' (' . $columns . ') VALUES ('
            . implode(',', array_fill(0, count($row), '?')) . ')');
        $statement->execute(array_values($row));
    }
};

try {
    $provided = getenv('VELVET_FRESH_PACKAGE');
    if (is_string($provided) && $provided !== '') {
        $archive = realpath($provided);
        $assert(is_string($archive) && is_file($archive) && str_ends_with($archive, '.zip'), 'Provided actual Fresh package is unavailable.');
        $expectedHash = getenv('VELVET_FRESH_PACKAGE_SHA256');
        if (is_string($expectedHash) && $expectedHash !== '') $assert(hash_equals($expectedHash, hash_file('sha256', $archive)), 'Provided Fresh package differs from the frozen release hash.');
    } else {
        $process = proc_open(['python3', $source . '/scripts/package.py', '--output', $work . '/packages'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not build the actual Fresh package.');
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Fresh package build failed: ' . $errors . $output);
        $manifest = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $fresh = array_values(array_filter($manifest['packages'] ?? [], static fn(array $entry): bool => ($entry['kind'] ?? '') === 'fresh'));
        $assert(count($fresh) === 1 && basename((string) $fresh[0]['filename']) === $fresh[0]['filename'], 'Builder did not identify exactly one Fresh ZIP.');
        $archive = $work . '/packages/' . $fresh[0]['filename'];
    }
    foreach ([$runtime, $otherRoot] as $root) {
        vvx_installer_fixture_extract($archive, $root);
        file_put_contents($root . '/.vvx-marketing-sql-runtime', 'Owned separate-root marketing acceptance fixture.');
    }
    vvx_installer_fixture_load($runtime, false);
    foreach (['app/Core/MarketingStore.php', 'app/Core/MarketingStorage.php', 'app/Core/MarketingSqlRepository.php',
        'app/Core/MarketingStorageException.php', 'app/Core/CampaignDeliveryService.php', 'app/Core/MailService.php',
        'database/migrations/' . $migrationKey . '.php'] as $path) {
        $assert(is_file($runtime . '/' . $path) && hash_file('sha256', $runtime . '/' . $path) === hash_file('sha256', $source . '/' . $path),
            'The Fresh ZIP does not contain the exact current executable storage dependency: ' . $path);
    }
    require_once $runtime . '/app/Core/MarketingStore.php';
    $installationId = 'marketing-sql-acceptance-' . str_repeat('i', 190 - strlen('marketing-sql-acceptance-'));
    $configuration = ['installed' => true, 'storage_mode' => 'mysql', 'db' => $db,
        'version' => VELVET_VIXXEN_VERSION, 'base_url' => 'http://127.0.0.1/nested/site',
        'mail' => ['mode' => 'disabled'], 'installation_id' => $installationId,
        'marketing_sql_acceptance_fixture' => true,
        'security' => ['app_key' => str_repeat('a', 64), 'signing_key' => str_repeat('s', 64), 'media_key' => str_repeat('m', 64)]];
    foreach ([$runtime, $otherRoot] as $root) {
        vvx_installer_fixture_write_config($root, $configuration);
        file_put_contents($root . '/storage/install.lock', json_encode(['installed' => true,
            'installation_id' => $configuration['installation_id']], JSON_THROW_ON_ERROR));
    }
    $runner = new \App\Core\MigrationRunner($pdo, $runtime . '/database/migrations');
    $applied = $runner->migrate();
    $assert(count($applied) === 19 && array_slice($applied, -5) === [$migrationKey, '202610210016_marketing_measurements', '202610210017_private_state', '202610220018_payment_journal', '202610220019_recurring_lifecycle'], 'Fresh package did not apply exactly the nineteen candidate migrations.');
    $assert(count($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)) === 91, 'Fresh migration schema does not contain all90 declared application tables plus its separate migration ledger.');
    $assertEmptyMarketing();
    $pdo->prepare("INSERT INTO installation_state(state_key,state_value,updated_at) VALUES('installation',?,'2026-01-02 03:04:05')")
        ->execute([json_encode(['installation_id' => $configuration['installation_id']], JSON_THROW_ON_ERROR)]);
    // This is the real fresh installer's settings shape: identity is in
    // installation_state, while the older setting contains version metadata.
    $pdo->prepare("INSERT INTO settings(setting_key,setting_value,updated_at) VALUES('installation',?,'2026-01-02 03:04:05')")
        ->execute([json_encode(['version' => VELVET_VIXXEN_VERSION, 'installed_at' => '2026-01-02T03:04:05+00:00'], JSON_THROW_ON_ERROR)]);
    $installationMetadata = $pdo->query("SELECT * FROM settings WHERE setting_key='installation'")->fetch();
    $pdo->exec("INSERT INTO settings(setting_key,setting_value,updated_at) VALUES('marketing_fixture_sentinel','{\"retained\":true}','2026-01-02 03:04:05')");
    $sentinel = $pdo->query("SELECT * FROM settings WHERE setting_key='marketing_fixture_sentinel'")->fetch();
    $identity = $pdo->query("SELECT * FROM installation_state WHERE state_key='installation'")->fetch();
    $configBytes = file_get_contents($runtime . '/config/app.php');
    $lockBytes = file_get_contents($runtime . '/storage/install.lock');
    $path = $runtime . '/storage/private/marketing/data.json';
    $otherPath = $otherRoot . '/storage/private/marketing/data.json';
    $guardPath = $runtime . '/storage/private/marketing/sql-cutover.json';
    $otherGuardPath = $otherRoot . '/storage/private/marketing/sql-cutover.json';

    $case = 'pre-migration compatibility and read-only review';
    // Safe only because this suite proved the just-created tables and authority empty.
    $runner->rollbackKeys([$migrationKey]);
    $beforeSchema = vvx_installer_fixture_snapshot($pdo); $beforeFiles = $files($runtime);
    $storage = new \Velvet\Core\MarketingStorage($runtime);
    $review = $storage->status();
    $assert($review['state'] === 'schema_needed' && $review['mode'] === 'file' && !$review['can_migrate'], 'Pre015 storage did not identify its required schema update.');
    $assert(vvx_installer_fixture_snapshot($pdo) === $beforeSchema && $files($runtime) === $beforeFiles && !is_file($path),
        'Opening Storage review initialized files, modified SQL, or activated a backend.');
    $reject(static fn() => $storage->migrate(str_repeat('a', 64)), 'Cutover without its verified schema was accepted.');
    $marketing = new \Velvet\Core\MarketingStore($runtime);
    $marketing->upsert('posts', ['id' => 'pre015-post', 'title' => 'File-backed marketing before database update']);
    $assert(is_file($path) && $marketing->all()['posts'][0]['id'] === 'pre015-post', 'Existing file marketing stopped working before migration015.');
    $fileBeforeSchema = file_get_contents($path);
    // An interrupted schema with orphan records is different from a clean,
    // not-yet-applied migration. Never hide those rows by selecting the file.
    $definition = require $runtime . '/database/migrations/' . $migrationKey . '.php';
    $pdo->exec($definition['up'][1]);
    $pdo->prepare('INSERT INTO marketing_storage_records(collection,entry_key_hash,entry_key_json,position,payload_json) VALUES(?,?,?,?,?)')
        ->execute(['events', hash('sha256', '0'), '0', 0, '{"id":"unclaimed-partial-fixture"}']);
    $orphan = $pdo->query('SELECT * FROM marketing_storage_records')->fetchAll();
    $assert($storage->status()['state'] === 'blocked', 'A partial schema containing unclaimed records selected the legacy file.');
    $reject(static fn() => $marketing->all(), 'Partial orphan SQL data allowed an ordinary file read.');
    $assert($pdo->query('SELECT * FROM marketing_storage_records')->fetchAll() === $orphan && file_get_contents($path) === $fileBeforeSchema,
        'Refusing an orphan partial schema modified either data source.');
    $pdo->exec('DROP TABLE marketing_storage_records'); // Only the exact orphan fixture created above.
    $assert($runner->migrate(false) === [$migrationKey], 'Applying the pending schema did not add only migration015.');
    $assert(file_get_contents($path) === $fileBeforeSchema, 'Applying the schema modified existing marketing bytes.');
    $assertEmptyMarketing();
    $ledger = $pdo->query('SELECT * FROM migrations ORDER BY migration_key')->fetchAll();
    echo "PASS marketing SQL: actual Fresh dependency hashes, exact19/90 declared schema, read-only review and pre015 file compatibility\n";

    $case = 'legacy data preparation';
    $contacts = [];
    foreach (['eligible', 'pending', 'suppressed', 'revoked', 'unsubscribed'] as $kind) {
        $contacts[$kind] = $marketing->subscribe('legacy-' . $kind . '@example.test', 'Legacy ' . $kind, 'legacy fixture', true);
        if ($kind !== 'pending') $assert($marketing->confirm($contacts[$kind]['token']), 'Could not create a confirmed legacy fixture contact.');
    }
    $marketing->unsubscribe($contacts['unsubscribed']['contact']['unsubscribe_token']);
    $marketing->update(static function (array &$data): void {
        $data['suppressions'][] = ['id' => 'legacy-suppression', 'email' => 'legacy-suppressed@example.test', 'reason' => 'Retained fixture suppression'];
        $data['consents'][] = ['id' => 'legacy-revocation', 'email' => 'legacy-revoked@example.test', 'type' => 'newsletter', 'granted' => false];
    });
    $audience = $marketing->saveSegment(['name' => 'Legacy eligible audience', 'enabled' => true,
        'rules' => ['version' => 1, 'sources' => ['legacy fixture'], 'confirmed_after' => null, 'confirmed_before' => null]]);
    $campaign = $marketing->upsert('campaigns', ['subject' => 'Retained queued subject', 'preheader' => 'Retained preheader', 'body' => 'Retained queued body',
        'audience' => ['mode' => 'segment', 'segment_id' => $audience['id'], 'segment_revision' => $audience['revision']]]);
    $assert($marketing->queueCampaign($campaign['id']) === 1, 'Legacy queue did not honor pending, revoked, unsubscribed and suppressed contacts.');
    $marketing->update(static function (array &$data) use ($contacts, $campaign): void {
        $data['posts'] = ['named-entry' => $data['posts'][0] + ['legacy_extension' => ['score' => 1.0, 'keep' => null]],
            7 => ['id' => 'sparse-post', 'title' => 'Sparse integer key'], '007' => ['id' => 'zero-prefixed-key', 'title' => 'Distinct string key']];
        $data['banners'] = [['id' => 'legacy-banner', 'enabled' => false, 'legacy_window' => 'Keep exact unknown field']];
        $data['landing_pages'] = [['id' => 'legacy-page', 'slug' => 'retained-page', 'body' => 'Existing page fixture']];
        $data['referral_codes'] = ['saved-code' => ['id' => 'legacy-referral', 'code' => 'RETAINED', 'attribution' => ['campaign' => null]]];
        $data['segments']['needs-review'] = ['id' => 'legacy-invalid-audience', 'rules' => ['unsupported_legacy_rule' => true], 'unknown' => ['preserve' => true]];
        foreach (['prepared', 'claimed', 'sending', 'sent', 'failed', 'unknown', 'suppressed'] as $status) {
            $data['campaign_queue'][] = ['id' => 'legacy-' . $status, 'campaign_id' => $campaign['id'],
                'contact_id' => $contacts['eligible']['contact']['id'], 'email' => 'legacy-eligible@example.test',
                'status' => $status, 'attempts' => 2, 'claim_token' => 'retained-' . $status . '-fixture-claim',
                'created_at' => '2026-01-02T03:04:05+00:00', 'legacy_receipt' => ['state' => $status, 'keep' => true]];
        }
        for ($i = 0; $i < 301; $i++) $data['events'][] = ['id' => 'legacy-event-' . $i, 'name' => 'page_view',
            'path' => '/retained-fixture/' . $i, 'session_hash' => hash('sha256', 'synthetic-session-' . $i), 'created_at' => '2026-01-02T03:04:05+00:00'];
        $data['meta']['analytics_salt'] = 'retained-analytics-salt-fixture';
        $data['meta']['custom_meta'] = ['float' => 1.0, 'zero' => 0, 'nullable' => null];
        $data['settings']['future_owner_option'] = ['enabled' => false, 'label' => 'Unicode fixture: café 🩷'];
        $data['legacy_extension'] = ['original_order' => ['z' => 1, 'a' => 2], 'unknown_list' => [null, true, 1.0], 8 => 'integer key retained'];
    });
    // Keep an independently initialized second document root with different
    // original file bytes; after activation it must discover SQL authority.
    $precreatedOther = new \Velvet\Core\MarketingStore($otherRoot);
    $precreatedOther->upsert('posts', ['id' => 'other-root-file', 'title' => 'Unselected legacy file must stay unchanged']);
    $otherBytes = file_get_contents($otherPath);

    $case = 'preview freshness and refusing invalid sources';
    $beforeSql = vvx_installer_fixture_snapshot($pdo); $beforeFiles = $files($runtime);
    $review = $storage->status();
    $assert($review['state'] === 'ready' && $review['mode'] === 'file' && $review['can_migrate'], 'An installed015 site could not review its protected file move.');
    $assert($storage->status()['plan'] === $review['plan'] && preg_match('/\A[a-f0-9]{64}\z/D', $review['plan']) === 1,
        'An unchanged move did not have a stable bound preview.');
    foreach ($collections as $collection) $assert($review['counts'][$collection] === count($marketing->all()[$collection]), 'Reviewed count is inaccurate for ' . $collection . '.');
    $assert(vvx_installer_fixture_snapshot($pdo) === $beforeSql && $files($runtime) === $beforeFiles, 'Review changed database rows, original bytes, or filesystem metadata.');
    $reject(static fn() => $storage->migrate('invalid-plan'), 'An invalid preview token was accepted.');
    $marketing->recordEvent('page_view', ['path' => '/changed-after-preview']);
    $reject(static fn() => $storage->migrate($review['plan']), 'A stale preview imported a changed marketing source.');
    $legacyData = $marketing->all(); $legacyBytes = file_get_contents($path);
    $legacyHash = hash('sha256', $legacyBytes); $review = $storage->status();
    $assertEmptyMarketing();
    file_put_contents($path, '{malformed legacy fixture');
    try {
        $assert($storage->status()['state'] === 'blocked', 'Malformed original JSON was offered for import.');
        $reject(static fn() => $marketing->upsert('posts', ['title' => 'Must not replace malformed history']), 'Malformed marketing data was reset by a write.');
        $reject(static fn() => $storage->migrate($review['plan']), 'Malformed JSON was imported.');
        $assert(file_get_contents($path) === '{malformed legacy fixture', 'Malformed original bytes were overwritten.');
        $assertEmptyMarketing();
    } finally { file_put_contents($path, $legacyBytes); }
    rename($path, $path . '.owned-original'); symlink($path . '.owned-original', $path);
    try {
        $assert($storage->status()['state'] === 'blocked', 'A symlinked original source was offered for import.');
        $reject(static fn() => $marketing->all(), 'A symbolic link was followed as authoritative marketing storage.');
        $assert(file_get_contents($path . '.owned-original') === $legacyBytes, 'Refusing a symbolic link changed the owned original.');
    } finally { unlink($path); rename($path . '.owned-original', $path); }
    $pdo->prepare('INSERT INTO marketing_storage_records(collection,entry_key_hash,entry_key_json,position,payload_json) VALUES(?,?,?,?,?)')
        ->execute(['events', hash('sha256', '0'), '0', 0, '{"id":"unclaimed-current-fixture"}']);
    $orphan = $sqlMarketing();
    try {
        $assert($storage->status()['state'] === 'blocked', 'Unclaimed destination records were offered for replacement.');
        $reject(static fn() => $storage->migrate($review['plan']), 'A move overwrote pre-existing unclaimed SQL records.');
        $assert($sqlMarketing() === $orphan && file_get_contents($path) === $legacyBytes, 'Destination-conflict refusal modified existing records.');
    } finally { $pdo->exec('DELETE FROM marketing_storage_records'); }
    echo "PASS marketing SQL: stable count-only review, stale-plan rejection, malformed/symlinked sources and orphan SQL preservation\n";

    $case = 'transaction rollback and authorization at commit';
    $preserved = static function () use ($pdo, $assert, $path, $otherPath, $legacyBytes, &$otherBytes, $runtime, $configBytes, $lockBytes, $ledger, $sentinel, $identity): void {
        $assert(file_get_contents($path) === $legacyBytes && file_get_contents($otherPath) === $otherBytes, 'Original marketing source bytes changed after review/cutover.');
        $assert(file_get_contents($runtime . '/config/app.php') === $configBytes && file_get_contents($runtime . '/storage/install.lock') === $lockBytes,
            'Marketing storage changed configuration, keys or installation lock.');
        $assert($pdo->query('SELECT * FROM migrations ORDER BY migration_key')->fetchAll() === $ledger, 'Marketing activity changed a schema migration or its timestamp.');
        $assert($pdo->query("SELECT * FROM settings WHERE setting_key='marketing_fixture_sentinel'")->fetch() === $sentinel
            && $pdo->query("SELECT * FROM installation_state WHERE state_key='installation'")->fetch() === $identity,
            'Marketing activity changed unrelated settings or installation identity.');
    };
    $calls = 0;
    $error = $reject(static function () use ($storage, &$calls): array {
        return $storage->migrate($storage->status()['plan'], static function () use (&$calls): void {
            $calls++; throw new RuntimeException('Fixture owner authorization revoked', 403);
        });
    }, 'A revoked owner was allowed to start the move.');
    $assert($calls === 1 && $error->getCode() === 403, 'Initial owner refusal was swallowed or invoked after import.');
    $assertEmptyMarketing(); $preserved();
    foreach (['records', 'authority'] as $failure) {
        $trigger = $failure === 'records'
            ? "CREATE TRIGGER vvx_reject_marketing_copy BEFORE INSERT ON marketing_storage_records FOR EACH ROW BEGIN IF NEW.collection='events' AND NEW.position=150 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic later import batch rejection'; END IF; END"
            : "CREATE TRIGGER vvx_reject_marketing_copy BEFORE INSERT ON settings FOR EACH ROW BEGIN IF NEW.setting_key='marketing_sql_storage' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic final authority rejection'; END IF; END";
        $pdo->exec($trigger);
        try { $reject(static fn() => $storage->migrate($storage->status()['plan']), 'Actual SQL ' . $failure . ' rejection did not stop the move.'); }
        finally { $pdo->exec('DROP TRIGGER vvx_reject_marketing_copy'); }
        $assertEmptyMarketing(); $preserved();
        $assert($storage->status()['state'] === 'ready' && !is_file($guardPath), 'A rolled-back pre-activation SQL failure changed file authority.');
    }
    $calls = 0;
    $error = $reject(static function () use ($storage, &$calls): array {
        return $storage->migrate($storage->status()['plan'], static function () use (&$calls): void {
            if (++$calls === 2) throw new RuntimeException('Fixture owner revoked immediately before commit', 403);
        });
    }, 'Owner revocation immediately before commit was ignored.');
    $assert($calls === 2 && $error->getCode() === 403 && is_file($guardPath), 'Late authorization did not follow the copy verification and durable intent boundary.');
    $assertEmptyMarketing(); $preserved();
    $retry = $storage->status();
    $assert($retry['state'] === 'blocked' && $retry['mode'] === null && $retry['can_migrate'], 'Verified rollback after durable intent did not require explicit review/retry.');
    $reject(static fn() => $marketing->all(), 'A stopped post-intent move silently resumed file reads.');
    $reject(static fn() => $marketing->recordEvent('page_view', ['path' => '/must-not-fall-back']), 'A stopped post-intent move silently resumed file writes.');
    file_put_contents($path, $legacyBytes . "\n");
    try {
        $changedRetry = $storage->status();
        $assert($changedRetry['state'] === 'blocked' && !$changedRetry['can_migrate'], 'A changed original after durable intent was offered for automatic recovery.');
        $reject(static fn() => $storage->migrate($retry['plan']), 'Retry accepted a different original source.');
        $assertEmptyMarketing();
    } finally { file_put_contents($path, $legacyBytes); }

    // Unlike an INSERT rejection, this faults the actual PDO commit call after
    // all SQL writes and the durable local intent are complete. Only connections
    // newly opened by this owned worker in this owned schema can be disconnected.
    $connections = array_map('intval', $pdo->query('SELECT ID FROM information_schema.PROCESSLIST WHERE DB=DATABASE()')->fetchAll(PDO::FETCH_COLUMN));
    $disconnectWorker = $launch($runtime, 'migrate-disconnect');
    $barrier('cutover-before-commit');
    $assert(is_file($guardPath), 'Commit failure barrier was reached without durable SQL-required intent.');
    $assertEmptyMarketing(); // Uncommitted records and activation are not visible from another connection.
    $newConnections = array_values(array_diff(array_map('intval',
        $pdo->query('SELECT ID FROM information_schema.PROCESSLIST WHERE DB=DATABASE()')->fetchAll(PDO::FETCH_COLUMN)), $connections));
    $assert(count($newConnections) === 1, 'Could not uniquely identify the isolated cutover worker connection.');
    $pdo->exec('KILL CONNECTION ' . (int) $newConnections[0]);
    $signal('cutover-commit-release');
    $disconnected = $finish($disconnectWorker);
    $assert($disconnected === ['rejected' => true, 'authorization_calls' => 2], 'A disconnected commit was reported as completed.');
    $assertEmptyMarketing(); $preserved();
    $assert($storage->status()['state'] === 'blocked' && $storage->status()['can_migrate'], 'Commit uncertainty automatically restored file authority or lost the verified explicit retry.');
    $reject(static fn() => $marketing->unsubscribe($contacts['eligible']['contact']['unsubscribe_token']), 'A disconnected commit permitted fallback to the original file.');
    echo "PASS marketing SQL: later-batch/authority rollback, repeated owner authorization and actual disconnected commit fail closed\n";

    $case = 'exact cutover, repeat and separate-root authority';
    $ready = $storage->status();
    // A file writer already inside update() on another document root must
    // finish before a SQL move can inspect or import its selected source.
    $fileEditor = $launch($otherRoot, 'held-file-update'); $barrier('file-update-held');
    $racingMove = $launch($runtime, 'migrate-observe', $ready['plan']); $barrier('racing-cutover-started');
    $waitingForDataLock = false; $until = microtime(true) + 3;
    do {
        foreach ($pdo->query('SELECT STATE,INFO FROM information_schema.PROCESSLIST WHERE DB=DATABASE()')->fetchAll() as $connection) {
            if (strtolower((string) $connection['STATE']) === 'user lock' && str_contains(strtoupper((string) $connection['INFO']), 'GET_LOCK')) $waitingForDataLock = true;
        }
        clearstatcache(true, $work . '/racing-cutover-authorized.json');
        if ($waitingForDataLock || is_file($work . '/racing-cutover-authorized.json')) break;
        usleep(10000);
    } while (microtime(true) < $until);
    $assert($waitingForDataLock && !is_file($work . '/racing-cutover-authorized.json'),
        'A cross-root cutover did not wait for the selected-file writer\'s database mutex.');
    $assertEmptyMarketing();
    $signal('file-update-release');
    $assert($finish($fileEditor)['committed'], 'The in-flight file edit did not finish before releasing the storage mutex.');
    $afterFileEdit = file_get_contents($otherPath);
    $assert($afterFileEdit !== $otherBytes && $rowById(json_decode($afterFileEdit, true, 512, JSON_THROW_ON_ERROR)['posts'], 'completed-before-cutover')['id'] === 'completed-before-cutover',
        'The pre-activation file edit disappeared or was written to the wrong source.');
    // From activation onward, retain these completed pre-activation bytes.
    $otherBytes = $afterFileEdit;
    $moved = $finish($racingMove);
    $assert($moved['state'] === 'mysql' && $moved['mode'] === 'mysql' && !$moved['can_migrate'] && $moved['source_preserved'] && $moved['authorization_calls'] === 2,
        'Verified explicit retry did not activate one SQL copy.');
    $assert($marketing->all() === $legacyData, 'Cutover changed a legacy key, entry order, unknown field, identifier, token, consent, queue outcome, audience or analytics salt.');
    foreach ($collections as $collection) $assert($moved['counts'][$collection] === count($legacyData[$collection]), 'Cutover changed record count for ' . $collection . '.');
    $sqlAfterMove = $sqlMarketing();
    $assert(count($sqlAfterMove['state']) === 1 && count($sqlAfterMove['authority']) === 1
        && count($sqlAfterMove['records']) === array_sum(array_map(static fn(string $collection): int => count($legacyData[$collection]), $collections)),
        'Activation did not atomically establish one authority and every original collection record.');
    $assert(strlen($installationId) === 190 && $sqlAfterMove['state'][0]['installation_id'] === $installationId
        && $pdo->query("SELECT * FROM settings WHERE setting_key='installation'")->fetch() === $installationMetadata,
        'Cutover truncated a valid long installation ID or rewrote the real installer metadata-only setting.');
    $assert($sqlAfterMove['state'][0]['source_sha256'] === $legacyHash && (fileperms($guardPath) & 0007) === 0,
        'The private SQL-required marker or original-source fingerprint is invalid.');
    $repeat = $storage->migrate($ready['plan']);
    $assert($repeat['state'] === 'mysql' && $sqlMarketing() === $sqlAfterMove && $marketing->all() === $legacyData,
        'A repeated owner move reimported data, duplicated rows or changed activation timestamps.');
    $assert(!is_file($otherGuardPath), 'The independent root unexpectedly already held the first root\'s guard.');
    $otherFiles = $files($otherRoot);
    $otherStorage = new \Velvet\Core\MarketingStorage($otherRoot);
    $otherReview = $otherStorage->status();
    $assert($otherReview['state'] === 'mysql' && !$otherReview['source_preserved'] && $files($otherRoot) === $otherFiles,
        'Read-only review in another root selected its stale file or rewrote local state.');
    $assert($precreatedOther->all() === $legacyData && is_file($otherGuardPath), 'An object created before cutover failed to discover SQL authority across document roots.');
    $independent = $finish($launch($otherRoot, 'read'));
    $assert($independent['state'] === 'mysql' && $independent['digest'] === hash('sha256', serialize($legacyData)),
        'Independent packaged PHP process could not read the exact activated snapshot.');
    $preserved();
    echo "PASS marketing SQL: in-flight file edit excludes cutover, exact legacy snapshot retained, one authority, repeat no-op and cross-root readers\n";

    $case = 'concurrent SQL writers and atomic mutations';
    $beforeConcurrent = $marketing->all(); $writers = [];
    for ($i = 0; $i < 4; $i++) $writers[] = $launch($i % 2 === 0 ? $runtime : $otherRoot, 'write', (string) $i);
    foreach ($writers as $writer) $assert($finish($writer) === ['events' => 12, 'contacts' => 1], 'A concurrent writer did not finish every operation.');
    $afterConcurrent = $marketing->all();
    $assert(count($afterConcurrent['events']) === count($beforeConcurrent['events']) + 48
        && count($afterConcurrent['contacts']) === count($beforeConcurrent['contacts']) + 4
        && $afterConcurrent['meta']['revision'] === $beforeConcurrent['meta']['revision'] + 52,
        'Independent SQL workers lost event/contact writes or revision increments.');
    $paths = array_column($afterConcurrent['events'], 'path');
    for ($i = 0; $i < 4; $i++) for ($j = 0; $j < 12; $j++) {
        $assert(count(array_keys($paths, '/sql-writer-' . $i . '/' . $j, true)) === 1, 'A concurrent event was missing or duplicated.');
    }
    $assert($afterConcurrent['posts'] === $beforeConcurrent['posts'] && $afterConcurrent['legacy_extension'] === $legacyData['legacy_extension']
        && array_slice($afterConcurrent['events'], 0, count($beforeConcurrent['events'])) === $beforeConcurrent['events'],
        'Concurrent writes replaced retained content, historical analytics or unknown fields.');
    $beforeFailedMutation = $sqlMarketing();
    $pdo->exec("CREATE TRIGGER vvx_reject_marketing_update BEFORE UPDATE ON marketing_storage_state FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic state-update rejection'");
    try { $reject(static fn() => $marketing->recordEvent('page_view', ['path' => '/must-rollback-sql-event']), 'An actual SQL state update error did not fail the mutation.'); }
    finally { $pdo->exec('DROP TRIGGER vvx_reject_marketing_update'); }
    $assert($sqlMarketing() === $beforeFailedMutation && $marketing->all() === $afterConcurrent, 'A failed SQL mutation left a record without matching metadata or lost prior data.');
    $preserved();
    echo "PASS marketing SQL: four independent writers across two roots retain all52 mutations; failed SQL update rolls back atomically\n";

    $case = 'database-wide delivery mutex and interrupted workers';
    $beforeBusy = $marketing->all();
    $holder = $launch($runtime, 'hold-delivery');
    $barrier('delivery-lock-held');
    try {
        $start = microtime(true);
        $busy = $finish($launch($otherRoot, 'delivery'));
        $assert($busy['busy'] === true && $busy['processed'] === 0 && $busy['recovered_claims'] === 0 && microtime(true) - $start < 3,
            'A second-root campaign worker waited or entered recovery while another worker owned the database mutex.');
        $assert($marketing->all() === $beforeBusy, 'The losing campaign worker reclassified another worker\'s claimed or sending entry.');
        $reject(static fn() => $storage->migrate($ready['plan']), 'An owner cutover was allowed to race an active campaign worker.');
        $start = microtime(true);
        $precreatedOther->upsert('posts', ['id' => 'concurrent-owner-edit', 'title' => 'Owner edits remain available during delivery']);
        $assert(microtime(true) - $start < 3, 'The delivery mutex held a long-lived data lock or database transaction.');
    } finally { $signal('delivery-lock-release'); }
    $assert($finish($holder)['acquired'] === true, 'The first worker did not retain its delivery lock through the callback.');
    $recovered = $finish($launch($otherRoot, 'delivery'));
    $recoveredData = $marketing->all();
    $assert(!$recovered['busy'] && $recovered['held'] !== null && $recovered['recovered_claims'] === 2 && $recovered['unknown'] === 1,
        'After the mutex was released, disabled-mail recovery did not preserve the claimed/sending distinction.');
    $assert($rowById($recoveredData['campaign_queue'], 'legacy-claimed')['status'] === 'queued'
        && $rowById($recoveredData['campaign_queue'], 'legacy-claimed')['attempts'] === 1
        && $rowById($recoveredData['campaign_queue'], 'legacy-sending')['status'] === 'unknown'
        && $rowById($recoveredData['campaign_queue'], 'legacy-sending')['attempts'] === 2,
        'Interrupted delivery recovery lost attempt counts or made unknown transmission retryable.');
    unlink($work . '/delivery-lock-held.json'); unlink($work . '/delivery-lock-release.json');
    $holder = $launch($runtime, 'hold-delivery'); $barrier('delivery-lock-held'); $stop($holder);
    $afterStoppedHolder = $finish($launch($otherRoot, 'delivery'));
    $assert(!$afterStoppedHolder['busy'], 'A stopped worker left the database delivery mutex permanently held.');
    $beforeStoppedEdit = $marketing->all();
    $editor = $launch($runtime, 'held-update'); $barrier('update-worker-held'); $stop($editor);
    $start = microtime(true);
    $assert($precreatedOther->all() === $beforeStoppedEdit && microtime(true) - $start < 3,
        'An abruptly stopped edit left partial data or a lasting data transaction/lock.');
    $preserved();
    echo "PASS marketing SQL: cross-root delivery exclusion, losing-worker immutability, short data locks and stopped-process recovery\n";

    $case = 'consent, unsubscribe and queued snapshot continuity';
    $stale = $marketing->all();
    $assert($precreatedOther->unsubscribe($contacts['eligible']['contact']['unsubscribe_token']), 'The original unsubscribe token did not work against SQL.');
    $afterUnsubscribe = $marketing->all();
    $stale['posts'][] = ['id' => 'stale-owner-save', 'title' => 'Must not resurrect a subscriber'];
    $reject(static fn() => $marketing->save($stale), 'A stale owner snapshot overwrote a concurrent SQL unsubscribe.');
    $assert($marketing->all() === $afterUnsubscribe
        && $rowById($afterUnsubscribe['contacts'], $contacts['eligible']['contact']['id'])['status'] === 'unsubscribed',
        'Stale-save refusal changed the committed unsubscribe.');
    $assert($rowById($afterUnsubscribe['campaign_queue'], 'legacy-prepared')['status'] === 'suppressed'
        && $rowById($afterUnsubscribe['campaign_queue'], 'legacy-failed')['status'] === 'suppressed'
        && $rowById($afterUnsubscribe['campaign_queue'], 'legacy-unknown')['status'] === 'unknown'
        && $rowById($afterUnsubscribe['campaign_queue'], 'legacy-sent')['status'] === 'sent',
        'SQL unsubscribe lost queue suppression or rewrote definite/unknown historical outcomes.');
    $consents = count($afterUnsubscribe['consents']);
    $assert($marketing->unsubscribe($contacts['eligible']['contact']['unsubscribe_token'])
        && count($marketing->all()['consents']) === $consents && !$marketing->confirm($contacts['eligible']['token']),
        'Repeated unsubscribe or an old confirmation token changed consent history.');
    $beforeFrozenEdit = $marketing->all();
    $reject(static fn() => $marketing->upsert('campaigns', ['id' => $campaign['id'], 'body' => 'Cannot replace queued SQL message']),
        'Queued message text was editable after storage cutover.');
    $assert($marketing->all() === $beforeFrozenEdit, 'Rejected queued-content editing partially mutated SQL state.');
    $marketing->saveSegment(['id' => $audience['id'], 'name' => 'Edited saved audience', 'enabled' => true,
        'rules' => ['version' => 1, 'sources' => ['new unrelated source'], 'confirmed_after' => null, 'confirmed_before' => null],
        'expected_revision' => $audience['revision']]);
    $frozen = $marketing->campaign($campaign['id']); $originalCampaign = $rowById($legacyData['campaigns'], $campaign['id']);
    $assert($frozen['audience_snapshot'] === $originalCampaign['audience_snapshot']
        && $frozen['delivery_snapshot'] === $originalCampaign['delivery_snapshot']
        && $marketing->queueCampaign($campaign['id']) === 0 && $marketing->retryCampaign($campaign['id']) === 0,
        'Editing a saved SQL audience changed a queued snapshot or requeued historical unknown deliveries.');
    $marketing->setSettings(['newsletter_signup_enabled' => false]);
    $reject(static fn() => $marketing->subscribe('disabled-signup@example.test', 'Disabled', 'fixture', true), 'SQL-backed disabled signup accepted a contact.');
    $assert($marketing->unsubscribe($contacts['pending']['contact']['unsubscribe_token']), 'Disabled SQL signup also disabled unsubscribe.');
    $marketing->setSettings(['newsletter_signup_enabled' => true]);
    $preserved();
    echo "PASS marketing SQL: original tokens, revocations, stale-save protection, frozen audiences/messages and unknown-delivery holds\n";

    $case = 'loopback SMTP concurrency and uncertain acknowledgement';
    foreach (['unsubscribe', 'pause', 'unknown'] as $transportCase) {
        $recipient = $marketing->subscribe('sql-smtp-' . $transportCase . '@example.test', 'Loopback fixture', 'sql smtp ' . $transportCase, true);
        $assert($marketing->confirm($recipient['token']), 'Could not confirm a reserved loopback fixture.');
        $segment = $marketing->saveSegment(['name' => 'SQL SMTP ' . $transportCase, 'enabled' => true,
            'rules' => ['version' => 1, 'sources' => ['sql smtp ' . $transportCase], 'confirmed_after' => null, 'confirmed_before' => null]]);
        $mailCampaign = $marketing->upsert('campaigns', ['subject' => 'Local SQL transport fixture', 'body' => 'Reserved local acceptance message.',
            'audience' => ['mode' => 'segment', 'segment_id' => $segment['id'], 'segment_revision' => $segment['revision']]]);
        $preview = $marketing->previewCampaignAudience($mailCampaign['id']);
        $assert($marketing->queueCampaign($mailCampaign['id'], $preview['revision']) === 1, 'Loopback campaign did not have exactly its one reserved recipient.');
        $sink = $launch($runtime, 'smtp', $transportCase);
        $sinkReady = $barrier('smtp-' . $transportCase . '-ready');
        $mailConfiguration = array_replace($configuration, ['mail' => ['mode' => 'smtp', 'host' => '127.0.0.1',
            'port' => $sinkReady['port'], 'encryption' => 'none', 'username' => '', 'password' => '',
            'from_email' => 'sender@example.test', 'from_name' => 'Local SQL fixture']]);
        vvx_installer_fixture_write_config($runtime, $mailConfiguration);
        $delivery = $launch($runtime, 'delivery', $mailCampaign['id']);
        $barrier('smtp-' . $transportCase . '-waiting');
        if ($transportCase !== 'unknown') {
            $duringHandshake = $marketing->all();
            $busy = $finish($launch($otherRoot, 'delivery', $mailCampaign['id']));
            $assert($busy['busy'] && $busy['recovered_claims'] === 0 && $marketing->all() === $duringHandshake,
                'A second worker entered recovery while the real SMTP handshake was in progress.');
            $start = microtime(true);
            if ($transportCase === 'unsubscribe') $assert($precreatedOther->unsubscribe($recipient['contact']['unsubscribe_token']), 'Concurrent SQL unsubscribe was rejected.');
            else $precreatedOther->pauseCampaign($mailCampaign['id'], true);
            $assert(microtime(true) - $start < 3, 'Network delivery held SQL locks that blocked a consent change or owner pause.');
            $signal('smtp-' . $transportCase . '-release');
        }
        $outcome = $finish($delivery); $sinkOutcome = $finish($sink);
        $sinkRecord = $barrier('smtp-' . $transportCase . '-result');
        $queue = array_values(array_filter($marketing->all()['campaign_queue'], static fn(array $row): bool => $row['campaign_id'] === $mailCampaign['id']));
        $assert(count($queue) === 1 && $outcome['processed'] === 1 && $outcome['accepted'] === 0 && $sinkRecord['recipients'] === 1,
            'The loopback transport duplicated a queue record or accepted a canceled/unknown delivery.');
        if ($transportCase === 'unknown') {
            $assert($sinkOutcome['complete'] && $sinkOutcome['body_bytes'] > 0 && $outcome['unknown'] === 1
                && $queue[0]['status'] === 'unknown' && $queue[0]['attempts'] === 1,
                'A dropped SMTP acknowledgement did not preserve a durable unknown outcome.');
            $assert($precreatedOther->retryCampaign($mailCampaign['id']) === 0 && $precreatedOther->queueCampaign($mailCampaign['id']) === 0,
                'Unknown acceptance was made retryable by owner queue/retry after SQL cutover.');
            $again = $finish($launch($runtime, 'delivery', $mailCampaign['id']));
            $assert(!$again['busy'] && $again['processed'] === 0, 'Another SQL worker attempted an unknown delivery again.');
        } else {
            $assert(!$sinkOutcome['complete'] && $sinkOutcome['body_bytes'] === 0 && $sinkRecord['body_bytes'] === 0,
                'Message bytes were transmitted after a consent change or owner pause.');
            $assert($queue[0]['status'] === ($transportCase === 'unsubscribe' ? 'suppressed' : 'queued')
                && $queue[0]['attempts'] === ($transportCase === 'unsubscribe' ? 1 : 0),
                'Canceled SQL delivery did not preserve suppression or release its unused attempt.');
        }
        vvx_installer_fixture_write_config($runtime, $configuration);
        $preserved();
    }
    echo "PASS marketing SQL: real loopback SMTP permits concurrent unsubscribe/pause, transmits no canceled body and holds unknown acceptance\n";

    $case = 'active SQL loss, integrity and database outage refusal';
    $activeData = $marketing->all(); $activeSql = $sqlMarketing();
    $assertBlocked = static function (string $reason) use ($storage, $marketing, $assert, $reject, $preserved): void {
        $status = $storage->status();
        $assert($status['state'] === 'blocked' && $status['mode'] === null && !$status['can_migrate'], $reason . ' did not hold active storage for review.');
        $reject(static fn() => $marketing->all(), $reason . ' silently fell back to an old file read.');
        $reject(static fn() => $marketing->recordEvent('page_view', ['path' => '/must-not-use-archive']), $reason . ' silently fell back to an old file write.');
        $preserved();
    };
    $pdo->exec('DELETE FROM marketing_storage_state');
    try { $assertBlocked('Missing SQL singleton with retained authority'); }
    finally { $restoreRows('marketing_storage_state', $activeSql['state']); }
    $pdo->exec("DELETE FROM settings WHERE setting_key='marketing_sql_storage'");
    try { $assertBlocked('Missing SQL authority with retained singleton'); }
    finally { $restoreRows('settings', $activeSql['authority']); }
    foreach (['marketing_storage_state', 'marketing_storage_records'] as $missingTable) {
        $heldTable = 'vvx_held_' . $missingTable;
        $pdo->exec('RENAME TABLE ' . vvx_installer_fixture_quote($missingTable) . ' TO ' . vvx_installer_fixture_quote($heldTable));
        try { $assertBlocked('Applied015 missing ' . $missingTable); }
        finally { $pdo->exec('RENAME TABLE ' . vvx_installer_fixture_quote($heldTable) . ' TO ' . vvx_installer_fixture_quote($missingTable)); }
    }
    $tampered = $activeSql['records'][0];
    $pdo->prepare('UPDATE marketing_storage_records SET payload_json=? WHERE collection=? AND entry_key_hash=?')
        ->execute(['{"tampered_fixture":true}', $tampered['collection'], $tampered['entry_key_hash']]);
    try { $assertBlocked('SQL record integrity mismatch'); }
    finally { $pdo->prepare('UPDATE marketing_storage_records SET payload_json=? WHERE collection=? AND entry_key_hash=?')
        ->execute([$tampered['payload_json'], $tampered['collection'], $tampered['entry_key_hash']]); }
    foreach (['conflicting' => 'conflicting-fixture-installation', 'malformed' => ['invalid' => true], 'blank' => ''] as $identityCase => $badIdentity) {
        $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='installation'")
            ->execute([json_encode(['installation_id' => $badIdentity], JSON_THROW_ON_ERROR)]);
        try { $assertBlocked(ucfirst($identityCase) . ' explicit secondary database identity'); }
        finally { $pdo->prepare("UPDATE settings SET setting_value=?,updated_at=? WHERE setting_key='installation'")
            ->execute([$installationMetadata['setting_value'], $installationMetadata['updated_at']]); }
    }
    $guardBytes = file_get_contents($guardPath);
    file_put_contents($guardPath, '{malformed SQL-required fixture');
    try { $assertBlocked('Malformed local SQL-required marker'); }
    finally { file_put_contents($guardPath, $guardBytes); }
    $errno = 0; $error = '';
    $unused = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!is_resource($unused)) throw new RuntimeException('Could not reserve an unused loopback outage fixture port.');
    $unusedAddress = (string) stream_socket_get_name($unused, false);
    $unusedPort = (int) substr($unusedAddress, strrpos($unusedAddress, ':') + 1); fclose($unused);
    foreach (['unreachable', 'file-mode', 'identity'] as $problem) {
        $badConfiguration = $configuration;
        if ($problem === 'unreachable') $badConfiguration['db']['port'] = $unusedPort;
        elseif ($problem === 'file-mode') $badConfiguration['storage_mode'] = 'file';
        else $badConfiguration['installation_id'] = 'wrong-local-fixture-installation';
        vvx_installer_fixture_write_config($runtime, $badConfiguration);
        try {
            $status = $storage->status();
            $assert($status['state'] === 'blocked' && !$status['can_migrate'], 'Active SQL ' . $problem . ' configuration was accepted.');
            $failure = $reject(static fn() => $marketing->all(), 'Active SQL ' . $problem . ' selected old JSON.');
            $reject(static fn() => $marketing->setSettings(['newsletter_signup_enabled' => false]), 'Active SQL ' . $problem . ' wrote the old JSON.');
            if ($db['password'] !== '') $assert(!str_contains(json_encode($status, JSON_THROW_ON_ERROR) . $failure->getMessage(), $db['password']), 'Database error leaked a configured credential.');
            $assert(file_get_contents($path) === $legacyBytes && file_get_contents($otherPath) === $otherBytes && $sqlMarketing() === $activeSql,
                'Refusing the broken active SQL configuration changed either source.');
        } finally { vvx_installer_fixture_write_config($runtime, $configuration); }
    }
    $assert($marketing->all() === $activeData && $storage->status()['state'] === 'mysql' && $sqlMarketing() === $activeSql,
        'Restoring the exact database/configuration did not recover the latest SQL state.');
    $assert($pdo->query("SELECT * FROM settings WHERE setting_key='installation'")->fetch() === $installationMetadata,
        'The installer\'s ordinary version metadata changed during storage acceptance.');
    $preserved();
    $lastRead = $finish($launch($otherRoot, 'read'));
    $assert($lastRead['digest'] === hash('sha256', serialize($activeData)), 'Independent final read does not match the latest committed state.');
    echo "PASS marketing SQL: missing state/authority/tables, corrupt records, conflicting identities and database outages never fall back\n";
    $evidence = ['suite' => 'marketing_sql_mysql', 'checks' => $checks, 'version' => VELVET_VIXXEN_VERSION,
        'mysql' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'migrations' => 19, 'declared_tables' => 90, 'database_tables' => 91,
        'fresh_sha256' => hash_file('sha256', $archive), 'legacy_source_preserved' => true,
        'installation_id_length' => strlen($installationId), 'metadata_only_installation_setting' => true,
        'independent_roots' => 2, 'concurrent_writers' => 4, 'external_messages' => 0];
} finally {
    foreach (array_keys($children) as $child) $stop($child);
    vvx_installer_fixture_reset_database($pdo, $ownsDatabase);
    vvx_installer_fixture_empty($pdo);
    vvx_installer_fixture_remove($work);
}
echo 'Marketing SQL MySQL acceptance passed: ' . json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

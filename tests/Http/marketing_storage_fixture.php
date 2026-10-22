<?php
declare(strict_types=1);

// CLI-only helpers for an actual customer ZIP in an exclusively owned, empty-
// origin MySQL test database. Never copy this file into a served site or package.
$root = realpath((string) ($argv[1] ?? ''));
$action = (string) ($argv[2] ?? '');
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || $root === false || !is_array($input)
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    || !is_file($root . '/.vvx-test-runtime')
    || (getenv('VELVET_TEST_DB_HOST') ?: '127.0.0.1') !== '127.0.0.1') {
    throw new RuntimeException('A marked disposable temporary runtime and loopback MySQL fixture are required.');
}
require_once dirname(__DIR__) . '/Installer/database_fixture.php';
[$db, $pdo] = vvx_installer_fixture_database();
$ownershipPath = $root . '/storage/temp/marketing-storage-ownership.json';
$sourcePath = $root . '/storage/private/marketing/data.json';
$migration = '202610200015_marketing_sql_storage';
$tables = ['marketing_storage_state', 'marketing_storage_records'];

if ($action === 'setup-mysql') {
    $base = rtrim((string) ($input['base'] ?? ''), '/');
    $smtpPort = $input['smtp_port'] ?? null;
    if (parse_url($base, PHP_URL_SCHEME) !== 'http' || parse_url($base, PHP_URL_HOST) !== '127.0.0.1'
        || !is_int($smtpPort) || $smtpPort < 1024 || $smtpPort > 65535
        || is_file($root . '/storage/install.lock') || is_file($ownershipPath)) {
        throw new RuntimeException('A new loopback runtime and reserved local SMTP listener are required.');
    }
    vvx_installer_fixture_empty($pdo);
    if (!is_dir(dirname($ownershipPath))) mkdir(dirname($ownershipPath), 0700, true);
    file_put_contents($ownershipPath, json_encode(['database' => $db['name'], 'runtime' => $root,
        'smtp_port' => $smtpPort], JSON_THROW_ON_ERROR));
    chmod($ownershipPath, 0600);
    file_put_contents($root . '/.vvx-installer-db-fixture', 'Disposable marketing storage package acceptance.');
    if (!is_file($root . '/config/app.php')) copy($root . '/config/app.example.php', $root . '/config/app.php');
    vvx_installer_fixture_load($root);
    $payload = vvx_installer_fixture_payload($db, $base);
    $payload['owner'] = ['name' => 'Marketing Fixture Owner', 'email' => 'owner@example.test', 'password' => 'VelvetDemo12'];
    $payload['site']['timezone'] = 'UTC';
    $payload['site']['site_name'] = 'Marketing Storage Acceptance';
    // Installation itself runs with disabled mail. Only after it completes do
    // we configure the non-relaying loopback listener for zero-send assertions.
    vvx_installer_fixture_install($root, $payload);
    $config = new App\Core\Config($root . '/config/app.php');
    $saved = $config->all();
    $saved['mail'] = ['mode' => 'smtp', 'host' => '127.0.0.1', 'port' => $smtpPort,
        'encryption' => 'none', 'username' => '', 'password' => '',
        'from_email' => 'owner@example.test', 'from_name' => 'Marketing Fixture'];
    $config->write($saved);
    $action = 'initialize';
}

$ownership = is_file($ownershipPath)
    ? json_decode((string) file_get_contents($ownershipPath), true, 512, JSON_THROW_ON_ERROR) : [];
if (($ownership['database'] ?? '') !== $db['name'] || ($ownership['runtime'] ?? '') !== $root) {
    throw new RuntimeException('This runtime did not claim the initially empty test database.');
}
if ($action === 'cleanup-mysql') {
    vvx_installer_fixture_reset_database($pdo, true);
    unlink($ownershipPath);
    echo json_encode(['cleaned' => true], JSON_THROW_ON_ERROR);
    exit;
}

if (!defined('ROOT_PATH')) define('ROOT_PATH', $root);
require_once $root . '/includes/autoload.php';
$config = new App\Core\Config($root . '/config/app.php');
$saved = $config->all();
if (($saved['storage_mode'] ?? '') !== 'mysql' || ($saved['db']['name'] ?? '') !== $db['name']
    || ($saved['db']['host'] ?? '') !== '127.0.0.1' || (int) ($saved['db']['port'] ?? 0) !== $db['port']
    || ($saved['db']['user'] ?? '') !== $db['user']
    || parse_url((string) ($saved['base_url'] ?? ''), PHP_URL_HOST) !== '127.0.0.1'
    || ($saved['mail']['mode'] ?? '') !== 'smtp' || ($saved['mail']['host'] ?? '') !== '127.0.0.1'
    || (int) ($saved['mail']['port'] ?? 0) !== (int) ($ownership['smtp_port'] ?? 0)
    || !is_file($root . '/storage/install.lock')) {
    throw new RuntimeException('Only the known installed MySQL fixture with local SMTP capture is supported.');
}
$ownerQuery = $pdo->prepare('SELECT id FROM admins WHERE email=?');
$ownerQuery->execute(['owner@example.test']);
if (!$ownerQuery->fetchColumn()) throw new RuntimeException('The reserved fixture owner is missing.');
require_once $root . '/app/bootstrap.php';
require_once $root . '/app/Core/MarketingStorage.php';
require_once $root . '/app/Core/MarketingStore.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$collections = ['posts', 'banners', 'landing_pages', 'links', 'referral_codes', 'contacts', 'segments',
    'campaigns', 'campaign_queue', 'events', 'consents', 'suppressions'];
$storage = new Velvet\Core\MarketingStorage($root);
if ($action === 'initialize') {
    if (is_file($root . '/storage/temp/marketing-storage-seeded')) throw new RuntimeException('Fixture records already exist.');
    $marketing = new Velvet\Core\MarketingStore($root);
    $marketing->update(static function (array &$data): void {
        $data['meta']['analytics_salt'] = 'reserved-owner-storage-analytics-salt';
        $data['settings'] = ['newsletter_signup_enabled' => true, 'campaign_delivery_enabled' => true,
            'legacy_option' => ['retained' => 'settings with Unicode — café']];
        $data['posts'] = [
            ['id' => 'storage-post-2', 'title' => 'Fixture draft second', 'slug' => 'storage-draft',
                'body' => '<p>Private fixture draft</p>', 'excerpt' => '', 'status' => 'draft',
                'publish_at' => null, 'seo_title' => 'Draft SEO', 'seo_description' => 'Retain this description',
                'created_at' => '2024-01-02T03:04:05Z', 'edit_version' => 2, 'legacy_private_note' => 'before review'],
            ['id' => 'storage-post-1', 'title' => 'Fixture scheduled first', 'slug' => 'storage-scheduled',
                'body' => '<p>Reserved future publication</p>', 'excerpt' => '', 'status' => 'scheduled',
                'publish_at' => '2035-06-15T12:30:00Z', 'seo_title' => 'Future SEO', 'seo_description' => 'Scheduled description',
                'created_at' => '2024-01-01T03:04:05Z', 'edit_version' => 4],
        ];
        $data['banners'] = [['id' => 'storage-banner', 'title' => 'Future fixture banner', 'text' => 'Future text',
            'enabled' => false, 'starts_at' => '2035-06-15T00:00:00Z', 'ends_at' => '2035-06-16T00:00:00Z', 'url' => '/']];
        $data['landing_pages'] = [['id' => 'storage-page', 'title' => 'Future campaign page', 'slug' => 'storage-page',
            'body' => '<p>Campaign page</p>', 'enabled' => false, 'starts_at' => null, 'ends_at' => null,
            'seo_title' => 'Page SEO', 'seo_description' => 'Private campaign page SEO']];
        $data['links'] = [['id' => 'storage-link-b', 'label' => 'Fixture second link', 'url' => '/', 'sort' => 20, 'enabled' => true],
            ['id' => 'storage-link-a', 'label' => 'Fixture first link', 'url' => '/membership/', 'sort' => 10, 'enabled' => true]];
        $data['referral_codes'] = [['id' => 'storage-referral', 'code' => 'STORAGE', 'label' => 'Reserved referral', 'enabled' => true]];
        $data['contacts'] = [
            ['id' => 'storage-contact-active', 'email' => 'confirmed-storage@example.test', 'name' => 'Private confirmed fixture',
                'status' => 'active', 'source' => 'storage-fixture', 'consented_at' => '2024-01-01T00:00:00Z',
                'confirmed_at' => '2024-01-02T00:00:00Z', 'confirm_token_hash' => null,
                'unsubscribe_token' => 'reserved-storage-unsubscribe-active'],
            ['id' => 'storage-contact-pending', 'email' => 'pending-storage@example.test', 'name' => 'Private pending fixture',
                'status' => 'pending', 'source' => 'storage-fixture', 'consented_at' => '2024-01-03T00:00:00Z',
                'confirmed_at' => null, 'confirm_token_hash' => hash('sha256', 'reserved-storage-confirmation'),
                'unsubscribe_token' => 'reserved-storage-unsubscribe-pending'],
        ];
        $data['segments'] = [['id' => 'storage-audience', 'name' => 'Reserved saved audience', 'enabled' => true,
            'rules' => ['version' => 1, 'sources' => ['storage-fixture'], 'confirmed_after' => '2024-01-01T00:00:00Z', 'confirmed_before' => null],
            'created_at' => '2024-01-01T00:00:00Z', 'updated_at' => '2024-01-01T00:00:00Z']];
        $data['campaigns'] = [['id' => 'storage-campaign', 'subject' => 'Reserved campaign subject', 'preheader' => 'Reserved preheader',
            'body' => 'Retain queued campaign text', 'status' => 'queued', 'scheduled_at' => '2020-01-01T00:00:00Z',
            'delivery_paused' => false, 'delivery_snapshot' => ['subject' => 'Reserved campaign subject', 'body' => 'Frozen delivery body'],
            'audience_snapshot' => ['version' => 1, 'mode' => 'all', 'label' => 'All eligible newsletter contacts',
                'rules' => ['version' => 1, 'sources' => [], 'confirmed_after' => null, 'confirmed_before' => null]]]];
        $data['campaign_queue'] = [
            ['id' => 'storage-queue-queued', 'campaign_id' => 'storage-campaign', 'contact_id' => 'storage-contact-active',
                'email' => 'confirmed-storage@example.test', 'status' => 'queued', 'attempts' => 0, 'available_at' => '2020-01-01T00:00:00Z'],
            ['id' => 'storage-queue-unknown', 'campaign_id' => 'storage-campaign', 'contact_id' => 'storage-contact-pending',
                'email' => 'pending-storage@example.test', 'status' => 'unknown', 'attempts' => 1,
                'last_error' => 'Reserved unknown delivery outcome', 'provider_metadata' => ['retained' => true]],
        ];
        $data['events'] = [['id' => 'storage-event', 'name' => 'page_view', 'path' => '/',
            'session' => 'reserved-anonymous-session', 'recorded_at' => '2024-01-01T00:00:00Z']];
        $data['consents'] = [['id' => 'storage-consent-grant', 'email' => 'confirmed-storage@example.test',
            'type' => 'newsletter', 'granted' => true, 'source' => 'storage-fixture', 'recorded_at' => '2024-01-01T00:00:00Z'],
            ['id' => 'storage-consent-revoke', 'email' => 'retired-storage@example.test', 'type' => 'newsletter',
                'granted' => false, 'source' => 'unsubscribe', 'recorded_at' => '2024-01-02T00:00:00Z']];
        $data['suppressions'] = [['email' => 'retired-storage@example.test', 'active' => true, 'reason' => 'Reserved opt-out']];
        $data['retained_legacy_header'] = ['unicode' => 'Café — preserved', 'nullable' => null, 'enabled' => false];
    });
    $store = new App\Core\Store($config);
    $member = $store->createMember(['displayName' => 'Storage Fixture Member', 'email' => 'member@example.test',
        'password' => 'VelvetMember12', 'status' => 'active']);
    $store->updateMember($member['id'], ['emailVerifiedAt' => gmdate('Y-m-d H:i:s')]);
    vvx_installer_fixture_sentinels($pdo, $root);
    if (!is_dir($root . '/storage/backups')) mkdir($root . '/storage/backups', 0700, true);
    file_put_contents($root . '/storage/backups/marketing-owner-existing.txt', 'Reserved existing backup must remain byte-identical.');
    file_put_contents($root . '/storage/temp/marketing-storage-seeded', 'Reserved fixture records are ready.');
    echo json_encode(['initialized' => true, 'state' => $storage->status()['state']], JSON_THROW_ON_ERROR);
    exit;
}
if (!is_file($root . '/storage/temp/marketing-storage-seeded')) throw new RuntimeException('Initialize the reserved marketing fixture first.');

if ($action === 'owner-status') {
    $status = $input['status'] ?? '';
    if (!in_array($status, ['owner', 'demoted', 'inactive'], true)) throw new RuntimeException('Unknown reserved owner status.');
    $pdo->prepare('UPDATE admins SET role=?,is_active=? WHERE email=?')
        ->execute([$status === 'demoted' ? 'editor' : 'owner', $status === 'inactive' ? 0 : 1, 'owner@example.test']);
    echo json_encode(['status' => $status], JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'member-session') {
    session_name('velvet_vixxen_admin');
    session_save_path($root . '/storage/sessions');
    session_start();
    $result = member_auth()->attempt('member@example.test', 'VelvetMember12');
    if (empty($result['ok']) || !member_auth()->check() || app_auth()->check()) throw new RuntimeException('A real member-only session is required.');
    $result = ['name' => session_name(), 'id' => session_id(), 'csrf' => csrf_token()];
    session_write_close();
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'change-source') {
    if (!in_array($storage->status()['state'], ['ready', 'schema_needed'], true)) throw new RuntimeException('Only unmoved fixture records may be changed.');
    $lock = fopen(dirname($sourcePath) . '/data.lock', 'c+b');
    if (!is_resource($lock) || !flock($lock, LOCK_EX)) throw new RuntimeException('Fixture source lock is unavailable.');
    try {
        $data = json_decode((string) file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);
        if (($data['posts'][0]['id'] ?? '') !== 'storage-post-2') throw new RuntimeException('The reserved source record is missing.');
        // Change a private, previously unknown field without changing counts or
        // meta revision: the reviewed fingerprint must cover every source byte.
        $data['posts'][0]['legacy_private_note'] .= ' / changed';
        file_put_contents($sourcePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    echo json_encode(['changed' => true], JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'remove-empty-schema') {
    if ($storage->status()['state'] !== 'ready') throw new RuntimeException('Only empty inactive fixture tables may be removed.');
    $pendingKeys = [$migration, '202610210016_marketing_measurements', '202610210017_private_state', '202610220018_payment_journal'];
    $emptyTables = array_merge($tables, ['marketing_measurement_state', 'marketing_measurement_records', 'private_state', 'payment_journal']);
    // Prove all later tables empty before the first DDL statement so this is a
    // genuine pre015 installation, never a missing migration below a newer ledger.
    foreach ($emptyTables as $table) if ((int) $pdo->query('SELECT COUNT(*) FROM ' . vvx_installer_fixture_quote($table))->fetchColumn() !== 0) {
        throw new RuntimeException('Refusing to remove occupied fixture tables.');
    }
    if ($pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key='marketing_sql_storage'")->fetchColumn() != 0) {
        throw new RuntimeException('Refusing to remove active marketing authority.');
    }
    foreach (array_reverse($emptyTables) as $table) $pdo->exec('DROP TABLE ' . vvx_installer_fixture_quote($table));
    $remove = $pdo->prepare('DELETE FROM migrations WHERE migration_key=?');
    foreach ($pendingKeys as $key) $remove->execute([$key]);
    echo json_encode(['state' => $storage->status()['state']], JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'hold-active-state' || $action === 'restore-active-state') {
    $held = 'marketing_storage_state_owner_fixture_held';
    $known = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if ($action === 'hold-active-state') {
        if ($storage->status()['state'] !== 'mysql' || in_array($held, $known, true)) throw new RuntimeException('Only this active fixture state may be held.');
        $pdo->exec('RENAME TABLE marketing_storage_state TO ' . vvx_installer_fixture_quote($held));
    } else {
        if (!in_array($held, $known, true) || in_array('marketing_storage_state', $known, true)) throw new RuntimeException('The known held state is unavailable.');
        $pdo->exec('RENAME TABLE ' . vvx_installer_fixture_quote($held) . ' TO marketing_storage_state');
    }
    echo json_encode(['state' => $storage->status()['state']], JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'probe-blocked-write') {
    if ($storage->status()['state'] !== 'blocked') throw new RuntimeException('A deliberately blocked fixture is required.');
    $rejected = false;
    try {
        (new Velvet\Core\MarketingStore($root))->upsert('links', ['id' => 'must-never-fall-back',
            'label' => 'Rejected fixture write', 'url' => '/', 'enabled' => false, 'sort' => 999]);
    } catch (RuntimeException) { $rejected = true; }
    echo json_encode(['rejected' => $rejected], JSON_THROW_ON_ERROR);
    exit;
}
if ($action !== 'read') throw new RuntimeException('Unknown marketing storage fixture action.');

$result = ['status' => $storage->status(), 'mysql_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
    'legacy_sha256' => hash_file('sha256', $sourcePath), 'marketing' => null, 'marketing_read_blocked' => false];
try { $result['marketing'] = (new Velvet\Core\MarketingStore($root))->all(); }
catch (RuntimeException) { $result['marketing_read_blocked'] = true; }
$result['counts'] = [];
foreach ($collections as $collection) $result['counts'][$collection] = count((array) ($result['marketing'][$collection] ?? []));
$result['protected'] = [];
foreach (['config/app.php', 'storage/install.lock', 'uploads/existing-upload.bin', 'storage/private/existing-private.bin',
    'storage/backups/marketing-owner-existing.txt'] as $path) $result['protected'][$path] = hash_file('sha256', $root . '/' . $path);
$result['unrelated'] = $pdo->query('SELECT * FROM unrelated_existing_records ORDER BY id')->fetchAll();
$result['outbox'] = $pdo->query('SELECT * FROM mail_outbox ORDER BY id')->fetchAll();
$result['sql'] = [];
$known = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach (array_merge($tables, ['marketing_storage_state_owner_fixture_held']) as $table) {
    if (!in_array($table, $known, true)) continue;
    $rows = $pdo->query('SELECT * FROM ' . vvx_installer_fixture_quote($table))->fetchAll();
    usort($rows, static fn(array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));
    $result['sql'][$table] = $rows;
}
$result['sql']['authority'] = $pdo->query("SELECT * FROM settings WHERE setting_key='marketing_sql_storage'")->fetchAll();
$result['ledger'] = $pdo->query('SELECT migration_key FROM migrations ORDER BY migration_key')->fetchAll(PDO::FETCH_COLUMN);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

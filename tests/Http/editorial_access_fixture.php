<?php
declare(strict_types=1);

// Private CLI fixture for actual extracted customer packages. Never served or
// shipped. MySQL cleanup requires ownership of an initially empty test schema.
$root = realpath((string) ($argv[1] ?? ''));
$action = (string) ($argv[2] ?? '');
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || $root === false || !is_array($input)
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    || !is_file($root . '/.vvx-test-runtime')) {
    throw new RuntimeException('A marked disposable temporary runtime is required.');
}
$ownershipPath = $root . '/storage/temp/editorial-access-ownership.json';
$manifestPath = $root . '/storage/temp/editorial-access-records.json';
$sourcePath = $root . '/storage/private/marketing/data.json';
$pdo = null;
$db = null;
require_once dirname(__DIR__) . '/Installer/database_fixture.php';

if (in_array($action, ['setup-file', 'setup-mysql'], true)) {
    $mode = $action === 'setup-file' ? 'file' : 'mysql';
    $base = rtrim((string) ($input['base'] ?? ''), '/');
    $smtpPort = $input['smtp_port'] ?? null;
    if (parse_url($base, PHP_URL_SCHEME) !== 'http' || parse_url($base, PHP_URL_HOST) !== '127.0.0.1'
        || !is_int($smtpPort) || $smtpPort < 1024 || $smtpPort > 65535
        || is_file($root . '/storage/install.lock') || is_file($ownershipPath)
        || is_file($root . '/storage/data.json') || is_file($root . '/config/app.php')) {
        throw new RuntimeException('A new loopback runtime and reserved local SMTP listener are required.');
    }
    if ($mode === 'mysql') {
        if ((getenv('VELVET_TEST_DB_HOST') ?: '127.0.0.1') !== '127.0.0.1') throw new RuntimeException('Loopback MySQL is required.');
        [$db, $pdo] = vvx_installer_fixture_database();
        vvx_installer_fixture_empty($pdo);
    }
    foreach (['storage/temp', 'storage/sessions', 'storage/private', 'storage/backups'] as $relative) {
        if (!is_dir($root . '/' . $relative)) mkdir($root . '/' . $relative, 0700, true);
    }
    file_put_contents($ownershipPath, json_encode(['runtime' => $root, 'mode' => $mode,
        'database' => $db['name'] ?? null, 'base' => $base, 'smtp_port' => $smtpPort], JSON_THROW_ON_ERROR));
    chmod($ownershipPath, 0600);
    if ($mode === 'mysql') {
        file_put_contents($root . '/.vvx-installer-db-fixture', 'Disposable editorial access package acceptance.');
        copy($root . '/config/app.example.php', $root . '/config/app.php');
        vvx_installer_fixture_load($root);
        $payload = vvx_installer_fixture_payload($db, $base);
        $payload['owner'] = ['name' => 'Editorial Fixture Owner', 'email' => 'owner@example.test', 'password' => 'VelvetDemo12'];
        $payload['site']['timezone'] = 'America/Chicago';
        $payload['site']['site_name'] = 'Editorial Access Acceptance';
        vvx_installer_fixture_install($root, $payload);
    } else {
        $id = 'editorial-fixture-' . bin2hex(random_bytes(8));
        $saved = ['installed' => true, 'installation_id' => $id, 'app_name' => 'Editorial Access Acceptance',
            'version' => trim((string) file_get_contents($root . '/VERSION')), 'base_url' => $base,
            'timezone' => 'America/Chicago', 'currency' => 'USD', 'support_email' => 'owner@example.test',
            'storage_mode' => 'file', 'file_store_path' => 'storage/data.json',
            'db' => ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'password' => '', 'charset' => 'utf8mb4'],
            'mail' => ['mode' => 'disabled'], 'security' => ['app_key' => bin2hex(random_bytes(32)),
                'signing_key' => bin2hex(random_bytes(32)), 'media_key' => bin2hex(random_bytes(32))],
            'paths' => ['storage' => 'storage', 'private' => 'storage/private', 'uploads' => 'uploads',
                'images' => 'images', 'addons' => 'addons', 'templates' => 'templates'], 'installed_at' => gmdate('c')];
        $data = json_decode((string) file_get_contents($root . '/storage/defaults/data.json'), true, 512, JSON_THROW_ON_ERROR);
        $data['admins']['editorial-fixture-owner'] = ['id' => 'editorial-fixture-owner', 'name' => 'Editorial Fixture Owner',
            'email' => 'owner@example.test', 'password_hash' => password_hash('VelvetDemo12', PASSWORD_DEFAULT),
            'role' => 'owner', 'is_active' => 1, 'createdAt' => gmdate('c')];
        file_put_contents($root . '/config/app.php', "<?php\ndeclare(strict_types=1);\nreturn " . var_export($saved, true) . ";\n");
        file_put_contents($root . '/storage/data.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        file_put_contents($root . '/storage/install.lock', json_encode(['installed' => true, 'installation_id' => $id,
            'version' => $saved['version'], 'base_url' => $base, 'installed_at' => $saved['installed_at']], JSON_THROW_ON_ERROR));
        foreach (['config/app.php', 'storage/data.json', 'storage/install.lock'] as $relative) chmod($root . '/' . $relative, 0600);
    }
    if (!defined('ROOT_PATH')) define('ROOT_PATH', $root);
    require_once $root . '/includes/autoload.php';
    $config = new App\Core\Config($root . '/config/app.php');
    $saved = $config->all();
    // Installation has disabled delivery. Thereafter any unexpected attempt is
    // observable at a non-relaying listener; no real destination is configured.
    $saved['mail'] = ['mode' => 'smtp', 'host' => '127.0.0.1', 'port' => $smtpPort, 'encryption' => 'none',
        'username' => '', 'password' => '', 'from_email' => 'owner@example.test', 'from_name' => 'Editorial Fixture'];
    $config->write($saved);
    $action = 'initialize';
}

$ownership = is_file($ownershipPath) ? json_decode((string) file_get_contents($ownershipPath), true, 512, JSON_THROW_ON_ERROR) : [];
if (($ownership['runtime'] ?? '') !== $root || !in_array($ownership['mode'] ?? '', ['file', 'mysql'], true)) {
    throw new RuntimeException('This runtime has no matching fixture ownership marker.');
}
$mode = $ownership['mode'];
if ($mode === 'mysql' && $pdo === null) {
    if ((getenv('VELVET_TEST_DB_HOST') ?: '127.0.0.1') !== '127.0.0.1') throw new RuntimeException('Loopback MySQL is required.');
    [$db, $pdo] = vvx_installer_fixture_database();
}
if ($mode === 'mysql' && ($ownership['database'] ?? '') !== $db['name']) throw new RuntimeException('The owned fixture database changed.');
if ($action === 'cleanup-mysql') {
    if ($mode !== 'mysql') throw new RuntimeException('This fixture does not own a MySQL schema.');
    vvx_installer_fixture_reset_database($pdo, true);
    unlink($ownershipPath);
    echo json_encode(['cleaned' => true], JSON_THROW_ON_ERROR);
    exit;
}
if (!defined('ROOT_PATH')) define('ROOT_PATH', $root);
require_once $root . '/includes/autoload.php';
$config = new App\Core\Config($root . '/config/app.php');
$saved = $config->all();
if (($saved['storage_mode'] ?? '') !== $mode || ($saved['base_url'] ?? '') !== $ownership['base']
    || parse_url((string) $saved['base_url'], PHP_URL_HOST) !== '127.0.0.1'
    || ($saved['mail']['mode'] ?? '') !== 'smtp' || ($saved['mail']['host'] ?? '') !== '127.0.0.1'
    || (int) ($saved['mail']['port'] ?? 0) !== $ownership['smtp_port'] || !is_file($root . '/storage/install.lock')
    || ($mode === 'mysql' && (($saved['db']['host'] ?? '') !== '127.0.0.1'
        || ($saved['db']['name'] ?? '') !== $db['name'] || ($saved['db']['user'] ?? '') !== $db['user']
        || (int) ($saved['db']['port'] ?? 0) !== $db['port']))) {
    throw new RuntimeException('Only the known installed loopback fixture with local SMTP capture is supported.');
}
require_once $root . '/app/bootstrap.php';
require_once $root . '/app/Core/MarketingStore.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$store = new App\Core\Store($config);
$owner = $store->findAdminByEmail('owner@example.test');
if (!$owner) throw new RuntimeException('The reserved fixture owner is missing.');
if ($action === 'owner-status') {
    if (!is_file($manifestPath)) throw new RuntimeException('Only an initialized owned fixture may change its reserved owner.');
    $status = $input['status'] ?? '';
    if (!in_array($status, ['owner', 'demoted', 'inactive'], true)) throw new RuntimeException('Unknown reserved owner status.');
    $role = $status === 'demoted' ? 'editor' : 'owner';
    $active = $status === 'inactive' ? 0 : 1;
    if ($pdo !== null) $pdo->prepare('UPDATE admins SET role=?,is_active=? WHERE email=?')->execute([$role, $active, 'owner@example.test']);
    else {
        $data = json_decode((string) file_get_contents($root . '/storage/data.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($data['admins']['editorial-fixture-owner']['email'] ?? '') !== 'owner@example.test') throw new RuntimeException('Reserved file owner changed.');
        $data['admins']['editorial-fixture-owner']['role'] = $role;
        $data['admins']['editorial-fixture-owner']['is_active'] = $active;
        file_put_contents($root . '/storage/data.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
    echo json_encode(['status' => $status], JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'catalog-lock' || $action === 'catalog-wait') {
    if ($pdo === null || !is_file($manifestPath)) throw new RuntimeException('This case requires initialized owned MySQL storage.');
    if ($action === 'catalog-wait') {
        $query = $pdo->prepare("SELECT ID,COMMAND,STATE,INFO FROM information_schema.PROCESSLIST WHERE DB=? AND ID<>CONNECTION_ID()");
        $query->execute([$db['name']]);
        $waiting = [];
        $observed = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (str_contains(strtolower((string) $row['STATE']), 'lock')) {
                $observed[] = ['command' => $row['COMMAND'], 'state' => $row['STATE'], 'query' => substr((string) $row['INFO'], 0, 250)];
            }
            if (str_contains(strtolower((string) $row['STATE']), 'lock')
                && preg_match('/^SELECT\s.+\bmembership_plans\b/is', (string) $row['INFO'])) $waiting[] = (int) $row['ID'];
        }
        echo json_encode(['waiting_connections' => $waiting, 'observed_locks' => $observed], JSON_THROW_ON_ERROR);
        exit;
    }
    $token = $input['token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{16}$/D', $token)) throw new RuntimeException('A new reserved lock token is required.');
    $release = $root . '/storage/temp/editorial-catalog-' . $token . '.release';
    if (file_exists($release)) throw new RuntimeException('The reserved catalog lock token was already used.');
    $pdo->exec('LOCK TABLES membership_plans WRITE');
    try {
        echo json_encode(['locked' => true, 'connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()], JSON_THROW_ON_ERROR) . "\n";
        flush();
        $deadline = microtime(true) + 30;
        while (!is_file($release) && microtime(true) < $deadline) { usleep(20000); clearstatcache(true, $release); }
        if (!is_file($release)) throw new RuntimeException('The reserved catalog lock was not released before its timeout.');
    } finally { $pdo->exec('UNLOCK TABLES'); }
    echo json_encode(['released' => true], JSON_THROW_ON_ERROR) . "\n";
    exit;
}
if (in_array($action, ['storage-files', 'hold-source', 'restore-source', 'hold-sql-state', 'restore-sql-state',
    'hold-sql-authority', 'restore-sql-authority'], true)) {
    if (!is_file($manifestPath)) throw new RuntimeException('Only an initialized owned fixture may hold storage.');
    $directory = dirname($sourcePath);
    $heldDirectory = $root . '/storage/temp/editorial-marketing-held';
    $heldAuthority = $root . '/storage/temp/editorial-authority-held.json';
    if ($action === 'hold-source') {
        if (!is_file($sourcePath) || file_exists($heldDirectory)) throw new RuntimeException('The known fixture source cannot be held.');
        if (!rename($directory, $heldDirectory)) throw new RuntimeException('Could not hold the owned marketing directory.');
    } elseif ($action === 'restore-source') {
        if (file_exists($directory) || !is_file($heldDirectory . '/data.json')) throw new RuntimeException('The known source cannot be restored exactly.');
        if (!rename($heldDirectory, $directory)) throw new RuntimeException('Could not restore the owned marketing directory.');
    } elseif ($action === 'hold-sql-state' || $action === 'restore-sql-state') {
        if ($pdo === null) throw new RuntimeException('This case requires owned MySQL storage.');
        $known = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $from = $action === 'hold-sql-state' ? 'marketing_storage_state' : 'editorial_marketing_state_held';
        $to = $action === 'hold-sql-state' ? 'editorial_marketing_state_held' : 'marketing_storage_state';
        if (!in_array($from, $known, true) || in_array($to, $known, true)) throw new RuntimeException('The known SQL state cannot be held/restored.');
        $pdo->exec('RENAME TABLE ' . vvx_installer_fixture_quote($from) . ' TO ' . vvx_installer_fixture_quote($to));
    } elseif ($action === 'hold-sql-authority') {
        if ($pdo === null || file_exists($heldAuthority)) throw new RuntimeException('The known SQL authority cannot be held.');
        $authority = $pdo->query("SELECT * FROM settings WHERE setting_key='marketing_sql_storage'")->fetchAll(PDO::FETCH_ASSOC);
        if (count($authority) !== 1) throw new RuntimeException('Expected one active fixture authority.');
        file_put_contents($heldAuthority, json_encode($authority[0], JSON_THROW_ON_ERROR));
        chmod($heldAuthority, 0600);
        $pdo->exec("DELETE FROM settings WHERE setting_key='marketing_sql_storage'");
    } elseif ($action === 'restore-sql-authority') {
        if ($pdo === null || !is_file($heldAuthority)
            || (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key='marketing_sql_storage'")->fetchColumn() !== 0) {
            throw new RuntimeException('The known SQL authority cannot be restored.');
        }
        $row = json_decode((string) file_get_contents($heldAuthority), true, 512, JSON_THROW_ON_ERROR);
        if (($row['setting_key'] ?? '') !== 'marketing_sql_storage') throw new RuntimeException('The held authority identity changed.');
        $keys = array_keys($row);
        $pdo->prepare('INSERT INTO settings (' . implode(',', array_map('vvx_installer_fixture_quote', $keys)) . ') VALUES ('
            . implode(',', array_fill(0, count($keys), '?')) . ')')->execute(array_values($row));
        unlink($heldAuthority);
    }
    $files = [];
    foreach ([$directory, $heldDirectory] as $location) {
        if (!is_dir($location)) continue;
        $files[substr($location, strlen($root) + 1) . '/'] = 'directory';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($location, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile()) $files[substr($entry->getPathname(), strlen($root) + 1)] = hash_file('sha256', $entry->getPathname());
        }
    }
    ksort($files);
    $sql = [];
    if ($pdo !== null) {
        $known = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['marketing_storage_state', 'marketing_storage_records', 'editorial_marketing_state_held'] as $table) {
            if (in_array($table, $known, true)) $sql[$table] = $pdo->query('SELECT * FROM ' . vvx_installer_fixture_quote($table))->fetchAll(PDO::FETCH_ASSOC);
        }
        $sql['authority'] = $pdo->query("SELECT * FROM settings WHERE setting_key='marketing_sql_storage'")->fetchAll(PDO::FETCH_ASSOC);
    }
    $fileState = $pdo === null ? json_decode((string) file_get_contents($root . '/storage/data.json'), true, 512, JSON_THROW_ON_ERROR) : [];
    echo json_encode(['files' => $files, 'sql_sha256' => hash('sha256', json_encode($sql, JSON_THROW_ON_ERROR)),
        'outbox_count' => $pdo ? (int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn() : count((array) ($fileState['mailOutbox'] ?? []))], JSON_THROW_ON_ERROR);
    exit;
}
$marketing = new Velvet\Core\MarketingStore($root);
$storage = new Velvet\Core\MarketingStorage($root);
$collections = ['posts', 'banners', 'landing_pages', 'links', 'referral_codes', 'contacts', 'segments',
    'campaigns', 'campaign_queue', 'events', 'consents', 'suppressions'];

if ($action === 'initialize') {
    if (is_file($manifestPath)) throw new RuntimeException('The reserved fixture has already been initialized.');
    $clock = time();
    $manifest = ['members' => [], 'plans' => [], 'subscriptions' => [], 'clock' => $clock,
        'subscription_inputs' => [], 'free_verification_method' => 'markMemberVerified'];
    foreach (['gold' => 'Velvet Gold', 'silver' => 'Velvet Silver', 'lost' => 'Temporary Editorial Tier'] as $key => $name) {
        $manifest['plans'][$key] = $store->saveMembershipPlan(['name' => $name, 'key' => 'editorial-' . $key,
            'isActive' => true, 'price' => 10, 'currency' => 'USD', 'sortOrder' => 10]);
    }
    $names = ['free', 'gold', 'silver', 'trial', 'grace', 'expired', 'cancelled', 'paused', 'ended',
        'expired-grace', 'future', 'revoked', 'inactive', 'unverified', 'deleted',
        'just-started', 'just-expired', 'current-grace', 'grace-ended', 'future-verified'];
    foreach ($names as $name) {
        $member = $store->createMember(['displayName' => 'Editorial ' . ucfirst($name),
            'email' => $name . '@example.test', 'password' => 'VelvetMember12', 'status' => 'active',
            'emailVerifiedAt' => $name === 'free' ? null : gmdate('c')]);
        $manifest['members'][$name] = (string) $member['id'];
        if ($name === 'free') {
            // Exercise the real verification API: MySQL converts its ISO input
            // to the SQL connection timezone, unlike subscription UTC strings.
            $store->markMemberVerified($member['id']);
            continue;
        }
        $status = match ($name) {'trial' => 'trialing', 'grace', 'expired-grace', 'current-grace', 'grace-ended' => 'past_due',
            'expired' => 'expired', 'cancelled' => 'cancelled', 'paused' => 'paused', default => 'active'};
        $subscription = ['memberId' => $member['id'],
            'planId' => $manifest['plans'][$name === 'silver' ? 'silver' : 'gold']['id'], 'status' => $status,
            'currentPeriodStart' => $name === 'future' ? '2035-01-01T00:00:00Z' : '2020-01-01T00:00:00Z',
            'currentPeriodEnd' => in_array($name, ['ended', 'grace', 'expired-grace'], true) ? '2020-02-01T00:00:00Z' : '2035-02-01T00:00:00Z',
            'graceEndsAt' => $name === 'grace' ? '2035-03-01T00:00:00Z' : ($name === 'expired-grace' ? '2020-03-01T00:00:00Z' : null)];
        if (in_array($name, ['just-started', 'just-expired', 'current-grace', 'grace-ended'], true)) {
            $subscription['currentPeriodStart'] = gmdate('c', $clock - ($name === 'just-started' ? 120 : 7200));
            $subscription['currentPeriodEnd'] = gmdate('c', $clock + ($name === 'just-started' ? 3600 : -120));
            $subscription['graceEndsAt'] = match ($name) {
                'current-grace' => gmdate('c', $clock + 3600),
                'grace-ended' => gmdate('c', $clock - 120), default => null,
            };
            $manifest['subscription_inputs'][$name] = $subscription;
        }
        $manifest['subscriptions'][$name] = $store->saveSubscription($subscription);
    }
    $gold = (string) $manifest['plans']['gold']['id'];
    $silver = (string) $manifest['plans']['silver']['id'];
    $lost = (string) $manifest['plans']['lost']['id'];
    $policies = ['legacy' => null, 'public' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []],
        'members' => ['version' => 1, 'mode' => 'members', 'plan_ids' => []],
        'gold' => ['version' => 1, 'mode' => 'tiers', 'plan_ids' => [$gold]],
        'silver' => ['version' => 1, 'mode' => 'tiers', 'plan_ids' => [$silver]],
        'mixed' => ['version' => 1, 'mode' => 'tiers', 'plan_ids' => [$gold, $lost]],
        'unknown' => ['version' => 1, 'mode' => 'tiers', 'plan_ids' => ['999999999999999999']],
        'malformed' => ['version' => 2, 'mode' => 'public', 'plan_ids' => []],
        'empty-tiers' => ['version' => 1, 'mode' => 'tiers', 'plan_ids' => []],
        'numeric-id' => ['version' => 1, 'mode' => 'tiers', 'plan_ids' => [(int) $gold]],
        'alias-id' => ['version' => 1, 'mode' => 'tiers', 'plan_ids' => ['0' . $gold]],
        'draft' => ['version' => 1, 'mode' => 'gold-invalid', 'plan_ids' => []],
        'future' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []],
        'ended' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []],
        'disabled' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []]];
    // A legitimate restricted draft also proves owner previews bypass timing
    // only in their private route, without granting owners public privileges.
    $policies['draft'] = ['version' => 1, 'mode' => 'tiers', 'plan_ids' => [$gold]];
    $marketing->update(static function (array &$data) use ($policies): void {
        $data['meta']['analytics_salt'] = 'reserved-editorial-analytics-salt';
        $data['settings'] = ['newsletter_signup_enabled' => true, 'campaign_delivery_enabled' => false,
            'retained_option' => ['unicode' => 'Café — untouched', 'nullable' => null]];
        $data['posts'] = $data['banners'] = $data['landing_pages'] = [];
        foreach ($policies as $name => $policy) {
            foreach (['posts', 'banners', 'landing_pages'] as $collection) {
                $kind = $collection === 'posts' ? 'post' : ($collection === 'banners' ? 'banner' : 'page');
                $record = ['id' => 'editorial-' . $kind . '-' . $name, 'title' => 'Editorial ' . $kind . ' ' . $name,
                    'slug' => 'editorial-' . $kind . '-' . $name, 'body' => 'PRIVATE_BODY_' . $kind . '_' . $name,
                    'excerpt' => 'PRIVATE_EXCERPT_' . $kind . '_' . $name, 'category' => 'PRIVATE_CATEGORY_' . $name,
                    'seo_title' => 'PRIVATE_SEO_' . $kind . '_' . $name, 'seo_description' => 'PRIVATE_META_' . $kind . '_' . $name,
                    'legacy_private_note' => 'Preserve unknown record field — ' . $name,
                    'created_at' => '2020-01-01T00:00:00Z', 'updated_at' => '2020-01-01T00:00:00Z', 'edit_version' => 3];
                if ($policy !== null) $record['access'] = $policy;
                if ($collection === 'posts') {
                    $record['status'] = in_array($name, ['draft', 'disabled', 'ended'], true) ? 'draft' : 'published';
                    $record['publish_at'] = $name === 'future' ? '2035-01-01T00:00:00Z'
                        : ($name === 'legacy' ? '2020-01-01T00:00:00Z' : ($name === 'public' ? '2021-01-01T00:00:00Z' : '2024-01-01T00:00:00Z'));
                } else {
                    $record['enabled'] = !in_array($name, ['draft', 'disabled'], true);
                    $record['starts_at'] = $name === 'future' ? '2035-01-01T00:00:00Z' : null;
                    $record['ends_at'] = $name === 'ended' ? '2020-01-02T00:00:00Z' : null;
                }
                if ($collection === 'banners') {
                    $record['text'] = 'EDITORIAL_BANNER_' . $name;
                    $record['url'] = '/campaign/?slug=editorial-page-' . $name . '&ref=EDITORIAL';
                } elseif ($collection === 'landing_pages') {
                    $record['eyebrow'] = 'Editorial saved preview';
                    $record['cta_label'] = 'Preview reserved call to action';
                    $record['cta_url'] = '/newsletter/?ref=EDITORIAL';
                }
                $data[$collection][] = $record;
            }
        }
        // First eligible wins: malformed, future and tier-only entries precede
        // members/public banners so a guest still receives the public banner.
        $priority = ['malformed', 'future', 'gold', 'silver', 'members', 'legacy'];
        usort($data['banners'], static function (array $a, array $b) use ($priority): int {
            $score = static function (string $id) use ($priority): int {
                $value = array_search(substr($id, strlen('editorial-banner-')), $priority, true);
                return $value === false ? 99 : $value;
            };
            return $score($a['id']) <=> $score($b['id']);
        });
        $data['links'] = [['id' => 'editorial-existing-link', 'label' => 'Existing reserved link', 'url' => '/', 'sort' => 10, 'enabled' => true]];
        $data['referral_codes'] = [['id' => 'editorial-existing-referral', 'code' => 'EDITORIAL', 'enabled' => true]];
        $data['contacts'] = [['id' => 'editorial-existing-contact', 'email' => 'consented@example.test', 'status' => 'active',
            'source' => 'editorial-fixture', 'confirmed_at' => '2020-01-02T00:00:00Z', 'unsubscribe_token' => 'reserved-editorial-unsubscribe']];
        $data['segments'] = [['id' => 'editorial-existing-segment', 'name' => 'Frozen audience', 'enabled' => true,
            'rules' => ['version' => 1, 'sources' => ['editorial-fixture'], 'confirmed_after' => null, 'confirmed_before' => null]]];
        $data['campaigns'] = [['id' => 'editorial-existing-campaign', 'subject' => 'Frozen campaign subject', 'body' => 'Frozen body',
            'status' => 'queued', 'scheduled_at' => '2020-01-01T00:00:00Z', 'delivery_paused' => true,
            'delivery_snapshot' => ['subject' => 'Original subject', 'body' => 'Original delivery content'],
            'audience_snapshot' => ['version' => 1, 'mode' => 'all', 'label' => 'Frozen audience',
                'rules' => ['version' => 1, 'sources' => [], 'confirmed_after' => null, 'confirmed_before' => null]]]];
        $data['campaign_queue'] = [['id' => 'editorial-existing-queue', 'campaign_id' => 'editorial-existing-campaign',
            'contact_id' => 'editorial-existing-contact', 'email' => 'consented@example.test', 'status' => 'unknown',
            'attempts' => 1, 'last_error' => 'Reserved unknown delivery outcome', 'provider_metadata' => ['retained' => true]]];
        $data['events'] = [['id' => 'editorial-existing-event', 'name' => 'page_view', 'path' => '/',
            'session' => 'reserved-anonymous-session', 'recorded_at' => '2020-01-01T00:00:00Z']];
        $data['consents'] = [['id' => 'editorial-existing-consent', 'email' => 'consented@example.test',
            'type' => 'newsletter', 'granted' => true, 'source' => 'editorial-fixture', 'recorded_at' => '2020-01-01T00:00:00Z'],
            ['id' => 'editorial-existing-revocation', 'email' => 'retired@example.test', 'type' => 'newsletter',
                'granted' => false, 'source' => 'unsubscribe', 'recorded_at' => '2020-01-02T00:00:00Z']];
        $data['suppressions'] = [['email' => 'retired@example.test', 'active' => true, 'reason' => 'Reserved opt-out']];
        $data['retained_unknown_header'] = ['unicode' => 'Café — no loss', 'zero' => 0, 'false' => false, 'null' => null];
    });
    foreach (['uploads/editorial-existing.bin', 'storage/private/editorial-existing.bin', 'storage/backups/editorial-existing.txt'] as $relative) {
        file_put_contents($root . '/' . $relative, 'Reserved editorial acceptance sentinel: ' . $relative . "\n");
    }
    if ($pdo !== null) {
        $pdo->exec('CREATE TABLE editorial_existing_records (id INT PRIMARY KEY, note VARCHAR(190) NOT NULL) ENGINE=InnoDB');
        $pdo->prepare('INSERT INTO editorial_existing_records (id,note) VALUES (?,?)')->execute([37, 'Untouched editorial fixture row']);
    }
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($manifestPath, 0600);
    echo json_encode(['initialized' => true, 'mode' => $mode, 'state' => $storage->status()['state']], JSON_THROW_ON_ERROR);
    exit;
}
if (!is_file($manifestPath)) throw new RuntimeException('Initialize the reserved editorial fixture first.');
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

if ($action === 'stale-owner-session') {
    // A server-side session from an obsolete owner identity must not inherit
    // the current account merely because its email/role flags still match.
    session_name('velvet_vixxen_admin');
    session_save_path($root . '/storage/sessions');
    session_id(bin2hex(random_bytes(24)));
    session_start();
    $_SESSION = ['admin' => ['id' => 'obsolete-editorial-owner', 'email' => 'owner@example.test',
        'name' => 'Stale Fixture Owner', 'role' => 'owner'], 'is_owner' => true, 'preview' => true];
    $session = ['name' => session_name(), 'id' => session_id()];
    session_write_close();
    echo json_encode($session, JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'member-state') {
    $name = $input['member'] ?? '';
    $state = $input['state'] ?? '';
    $id = $manifest['members'][$name] ?? null;
    if ($id === null || !in_array($state, ['active', 'inactive', 'unverified', 'revoked', 'deleted', 'future-verified'], true)
        || ($store->member($id)['email'] ?? '') !== $name . '@example.test') throw new RuntimeException('Unknown reserved member state.');
    if ($state === 'revoked') {
        foreach ($store->memberSessions($id) as $session) $store->revokeMemberSession($id, $session['id']);
    } elseif ($state === 'future-verified' && $pdo !== null) {
        $pdo->prepare("UPDATE members SET status='active',email_verified_at=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=? AND email=?")
            ->execute([$id, $name . '@example.test']);
    } elseif ($state === 'deleted') {
        if ($pdo !== null) $pdo->prepare('UPDATE members SET deleted_at=NOW() WHERE id=?')->execute([$id]);
        else {
            $data = json_decode((string) file_get_contents($root . '/storage/data.json'), true, 512, JSON_THROW_ON_ERROR);
            $data['members'][$id]['deletedAt'] = gmdate('c');
            file_put_contents($root . '/storage/data.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
    } else $store->updateMember($id, ['status' => $state === 'inactive' ? 'suspended' : 'active',
        'emailVerifiedAt' => $state === 'unverified' ? null : gmdate('c', time() + ($state === 'future-verified' ? 3600 : 0))]);
    echo json_encode(['member' => $name, 'state' => $state], JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'subscription-state') {
    $name = $input['member'] ?? '';
    $status = $input['status'] ?? '';
    $plan = $input['plan'] ?? 'gold';
    if (!isset($manifest['subscriptions'][$name], $manifest['plans'][$plan])
        || !in_array($status, ['active', 'trialing', 'past_due', 'expired', 'cancelled', 'paused'], true)) {
        throw new RuntimeException('Unknown reserved subscription state.');
    }
    $changes = ['status' => $status, 'planId' => $manifest['plans'][$plan]['id']];
    foreach (['currentPeriodStart', 'currentPeriodEnd', 'graceEndsAt'] as $key) {
        if (array_key_exists($key, $input)) {
            if ($input[$key] !== null && (!is_string($input[$key]) || !preg_match('/^(?:2020|2035)-\d\d-\d\dT00:00:00Z$/D', $input[$key]))) {
                throw new RuntimeException('Only bounded fixture dates are accepted.');
            }
            $changes[$key] = $input[$key];
        }
    }
    $manifest['subscriptions'][$name] = $store->saveSubscription(array_replace($manifest['subscriptions'][$name], $changes));
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode(['changed' => true], JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'plan-state') {
    $name = $input['plan'] ?? '';
    $state = $input['state'] ?? '';
    if (!isset($manifest['plans'][$name]) || !in_array($state, ['active', 'disabled', 'remove'], true)
        || ($state === 'remove' && $name !== 'lost')) throw new RuntimeException('Unknown reserved plan state.');
    $plan = $manifest['plans'][$name];
    if ($state === 'remove') {
        if ($pdo !== null) $pdo->prepare('DELETE FROM membership_plans WHERE id=? AND plan_key=?')->execute([$plan['id'], 'editorial-lost']);
        else {
            $data = json_decode((string) file_get_contents($root . '/storage/data.json'), true, 512, JSON_THROW_ON_ERROR);
            unset($data['membershipPlans'][(string) $plan['id']]);
            file_put_contents($root . '/storage/data.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
    } else $store->saveMembershipPlan(array_replace($plan, ['isActive' => $state === 'active']));
    echo json_encode(['changed' => true], JSON_THROW_ON_ERROR);
    exit;
}
if ($action !== 'read') throw new RuntimeException('Unknown editorial access fixture action.');
$result = ['mode' => $mode, 'status' => $storage->status(), 'marketing' => $marketing->all(),
    'legacy_sha256' => hash_file('sha256', $sourcePath), 'protected' => [], 'sql' => null,
    'mysql_version' => $pdo?->query('SELECT VERSION()')->fetchColumn(), 'plans' => [], 'unrelated' => [], 'outbox' => [],
    'site_timezone' => date_default_timezone_get(), 'membership_clock' => ['fixture_epoch' => $manifest['clock'],
        'observed_epoch' => time(), 'free_verification_method' => $manifest['free_verification_method'],
        'verification' => [], 'subscription_inputs' => $manifest['subscription_inputs'], 'subscriptions' => [], 'subscription_sql_raw' => []],
    'sql_timezone' => null];
foreach (['free', 'future-verified'] as $name) {
    $member = $store->member($manifest['members'][$name]);
    $result['membership_clock']['verification'][$name] = ['raw' => $member['emailVerifiedAt'] ?? null];
    if ($pdo !== null) {
        $query = $pdo->prepare('SELECT UNIX_TIMESTAMP(email_verified_at) FROM members WHERE id=?');
        $query->execute([$manifest['members'][$name]]);
        $result['membership_clock']['verification'][$name]['sql_epoch'] = (int) $query->fetchColumn();
    }
}
foreach ($manifest['subscription_inputs'] as $name => $_) {
    $result['membership_clock']['subscriptions'][$name] = $store->subscriptions($manifest['members'][$name]);
    if ($pdo!==null) {
        $query=$pdo->prepare('SELECT id,member_id AS memberId,plan_id AS planId,status,current_period_start AS currentPeriodStart,current_period_end AS currentPeriodEnd,grace_ends_at AS graceEndsAt FROM subscriptions WHERE member_id=? ORDER BY id');
        $query->execute([$manifest['members'][$name]]);
        $result['membership_clock']['subscription_sql_raw'][$name]=$query->fetchAll(PDO::FETCH_ASSOC);
    }
}
if ($pdo !== null) {
    $result['sql_timezone'] = $pdo->query('SELECT @@session.time_zone AS session_timezone, @@system_time_zone AS system_timezone, '
        . 'TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS session_offset_seconds')->fetch(PDO::FETCH_ASSOC);
}
$result['guest_post_limit'] = array_column($marketing->publishedPosts(1, Velvet\Core\MarketingAccess::viewer($store)), 'id');
foreach (['config/app.php', 'storage/install.lock', 'uploads/editorial-existing.bin',
    'storage/private/editorial-existing.bin', 'storage/backups/editorial-existing.txt'] as $relative) {
    $result['protected'][$relative] = hash_file('sha256', $root . '/' . $relative);
}
foreach ($manifest['plans'] as $name => $plan) $result['plans'][$name] = (string) $plan['id'];
if ($pdo !== null) {
    $result['unrelated'] = $pdo->query('SELECT * FROM editorial_existing_records ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $result['outbox'] = $pdo->query('SELECT * FROM mail_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $result['sql'] = ['marketing_storage_state' => $pdo->query('SELECT * FROM marketing_storage_state ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'marketing_storage_records' => $pdo->query('SELECT * FROM marketing_storage_records ORDER BY collection,position')->fetchAll(PDO::FETCH_ASSOC),
        'authority' => $pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key='marketing_sql_storage'")->fetchAll(PDO::FETCH_ASSOC)];
} else {
    $data = json_decode((string) file_get_contents($root . '/storage/data.json'), true, 512, JSON_THROW_ON_ERROR);
    $result['outbox'] = $data['mailOutbox'] ?? [];
}
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

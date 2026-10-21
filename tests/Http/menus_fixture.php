<?php
declare(strict_types=1);

// Private CLI observer and lock fixture. It can only touch the already-owned,
// disposable Fresh runtime created by editorial_access_http.runtime().
$root = realpath((string) ($argv[1] ?? ''));
$action = (string) ($argv[2] ?? '');
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 32, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || $root === false || !is_array($input)
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    || !is_file($root . '/.vvx-test-runtime') || !is_file($root . '/storage/temp/editorial-access-records.json')) {
    throw new RuntimeException('A marked, initialized disposable menu runtime is required.');
}
$ownership = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-ownership.json'), true, 32, JSON_THROW_ON_ERROR);
if (($ownership['runtime'] ?? '') !== $root || !in_array($ownership['mode'] ?? '', ['file', 'mysql'], true)) {
    throw new RuntimeException('This runtime is not owned by the package acceptance fixture.');
}
define('ROOT_PATH', $root);
require_once $root . '/includes/version.php';
require_once $root . '/includes/autoload.php';
require_once $root . '/app/Support/functions.php';
require_once dirname(__DIR__) . '/Installer/database_fixture.php';
$config = new App\Core\Config($root . '/config/app.php');
$saved = $config->all();
$mode = $ownership['mode'];
if (($saved['storage_mode'] ?? '') !== $mode || ($saved['base_url'] ?? '') !== ($ownership['base'] ?? '')
    || parse_url((string) $saved['base_url'], PHP_URL_SCHEME) !== 'http'
    || parse_url((string) $saved['base_url'], PHP_URL_HOST) !== '127.0.0.1'
    || ($saved['mail']['mode'] ?? '') !== 'smtp' || ($saved['mail']['host'] ?? '') !== '127.0.0.1'
    || (int) ($saved['mail']['port'] ?? 0) !== ($ownership['smtp_port'] ?? null)
    || !is_file($root . '/storage/install.lock')) throw new RuntimeException('The owned loopback configuration changed.');
$pdo = null;
if ($mode === 'mysql') {
    if ((getenv('VELVET_TEST_DB_HOST') ?: '127.0.0.1') !== '127.0.0.1') throw new RuntimeException('Loopback MySQL is required.');
    [$db, $pdo] = vvx_installer_fixture_database();
    if (($ownership['database'] ?? '') !== $db['name'] || ($saved['db']['name'] ?? '') !== $db['name']
        || ($saved['db']['host'] ?? '') !== '127.0.0.1' || ($saved['db']['user'] ?? '') !== $db['user']
        || (int) ($saved['db']['port'] ?? 0) !== $db['port']) throw new RuntimeException('The owned test database changed.');
}
$store = new App\Core\Store($config);
$owner = $store->findAdminByEmail('owner@example.test');
if (!is_array($owner)) throw new RuntimeException('The reserved fixture owner is missing.');
if ($action === 'owner-lock') {
    $token = $input['token'] ?? '';
    $status = $input['status'] ?? '';
    if ($pdo === null || !is_string($token) || !preg_match('/^[a-f0-9]{16}$/D', $token)
        || !in_array($status, ['demoted', 'inactive'], true)) throw new RuntimeException('A reserved SQL owner-lock case is required.');
    $release = $root . '/storage/temp/menu-owner-' . $token . '.release';
    if (file_exists($release)) throw new RuntimeException('The reserved lock token was already used.');
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT id FROM admins WHERE id=? FOR UPDATE');
        $query->execute([$owner['id']]);
        if ((string) $query->fetchColumn() !== (string) $owner['id']) throw new RuntimeException('The reserved owner lock was not acquired.');
        echo json_encode(['locked' => true, 'connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()], JSON_THROW_ON_ERROR) . "\n";
        flush();
        $deadline = microtime(true) + 25;
        while (!is_file($release) && microtime(true) < $deadline) usleep(20000);
        if (!is_file($release)) throw new RuntimeException('The owned owner-row lock was not released in time.');
        $pdo->prepare('UPDATE admins SET role=?,is_active=? WHERE id=?')->execute([
            $status === 'demoted' ? 'editor' : 'owner', $status === 'inactive' ? 0 : 1, $owner['id']]);
        $pdo->commit();
        echo json_encode(['committed' => true], JSON_THROW_ON_ERROR) . "\n";
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
    exit;
}
if ($action === 'owner-wait') {
    if ($pdo === null) throw new RuntimeException('This case requires owned MySQL storage.');
    $query = $pdo->prepare('SELECT ID,STATE,INFO FROM information_schema.PROCESSLIST WHERE DB=? AND ID<>CONNECTION_ID()');
    $query->execute([$db['name']]);
    $waiting = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (preg_match('/^SELECT\s+id,email,role,is_active\s+FROM\s+admins\b.+FOR UPDATE\s*$/is', (string) $row['INFO'])) {
            $waiting[] = ['id' => (int) $row['ID'], 'state' => (string) $row['STATE']];
        }
    }
    echo json_encode(['waiting' => $waiting], JSON_THROW_ON_ERROR);
    exit;
}
if ($action !== 'read') throw new RuntimeException('Unknown guarded menu fixture action.');
$result = ['mode' => $mode, 'menus' => ['header' => $store->navigationMenu('header'), 'footer' => $store->navigationMenu('footer')],
    'records' => [], 'audit' => [], 'protected' => [], 'unrelated_sha256' => '', 'outbox_count' => 0,
    'mysql_version' => $pdo?->getAttribute(PDO::ATTR_SERVER_VERSION)];
foreach (['config/app.php', 'storage/install.lock', 'uploads/editorial-existing.bin',
    'storage/private/editorial-existing.bin', 'storage/backups/editorial-existing.txt'] as $relative) {
    $result['protected'][$relative] = hash_file('sha256', $root . '/' . $relative);
}
$unrelated = [];
if ($pdo !== null) {
    // Use an independent PDO to inspect actual committed records; no Store cache.
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $rows = $pdo->query('SELECT * FROM ' . vvx_installer_fixture_quote((string) $table))->fetchAll(PDO::FETCH_ASSOC);
        $keep = [];
        foreach ($rows as $row) {
            if ($table === 'settings' && in_array($row['setting_key'], ['navigation_menu_header', 'navigation_menu_footer'], true)) {
                $result['records'][$row['setting_key']] = json_decode((string) $row['setting_value'], true, 32, JSON_THROW_ON_ERROR);
            } elseif ($table === 'audit_log' && str_starts_with((string) $row['action_key'], 'site.menu.')) {
                $result['audit'][] = $row;
            } else {
                // A signed-in public request legitimately refreshes its session timestamp.
                if (in_array($table, ['member_sessions', 'members'], true)) unset($row['last_seen_at']);
                $keep[] = json_encode($row, JSON_THROW_ON_ERROR);
            }
        }
        sort($keep, SORT_STRING);
        $unrelated[(string) $table] = $keep;
    }
    usort($result['audit'], static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
    $result['outbox_count'] = (int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn();
} else {
    $unrelated = json_decode((string) file_get_contents($root . '/storage/data.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (['navigation_menu_header', 'navigation_menu_footer'] as $key) {
        if (array_key_exists($key, $unrelated['settings'])) $result['records'][$key] = $unrelated['settings'][$key];
        unset($unrelated['settings'][$key]);
    }
    $retainedAudit = [];
    foreach ($unrelated['audit'] ?? [] as $row) {
        if (str_starts_with((string) ($row['action'] ?? ''), 'site.menu.')) $result['audit'][] = $row;
        else $retainedAudit[] = $row;
    }
    $unrelated['audit'] = $retainedAudit;
    foreach ($unrelated['memberSessions'] ?? [] as $id => $row) unset($unrelated['memberSessions'][$id]['lastSeenAt']);
    foreach ($unrelated['members'] ?? [] as $id => $row) unset($unrelated['members'][$id]['lastSeenAt']);
    $result['outbox_count'] = count($unrelated['mailOutbox'] ?? []);
}
ksort($result['records']);
$result['records'] = (object) $result['records'];
$result['unrelated_sha256'] = hash('sha256', json_encode($unrelated, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

<?php
declare(strict_types=1);

// CLI-only changes to fixed synthetic identities in a separately marked package.
$root = realpath((string) ($argv[1] ?? ''));
$action = (string) ($argv[2] ?? '');
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || $root === false || !is_array($input) || is_link((string) ($argv[1] ?? ''))
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), '/') . '/') || !is_file($root . '/.vvx-test-runtime')) {
    throw new RuntimeException('A marked disposable recovery runtime is required.');
}
$ownership = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-ownership.json'), true, 512, JSON_THROW_ON_ERROR);
$historyPath = $root . '/storage/temp/editorial-history-records.json';
if (($ownership['runtime'] ?? '') !== $root || !in_array($ownership['mode'] ?? '', ['file', 'mysql'], true)
    || parse_url((string) ($ownership['base'] ?? ''), PHP_URL_HOST) !== '127.0.0.1' || !is_file($historyPath)) {
    throw new RuntimeException('The existing guarded editorial fixtures must own this runtime.');
}
define('ROOT_PATH', $root);
require_once $root . '/includes/autoload.php';
$config = new App\Core\Config($root . '/config/app.php');
$saved = $config->all();
if (($saved['storage_mode'] ?? '') !== $ownership['mode'] || ($saved['base_url'] ?? '') !== $ownership['base']
    || ($saved['mail']['host'] ?? '') !== '127.0.0.1' || (int) ($saved['mail']['port'] ?? 0) !== $ownership['smtp_port']
    || !is_file($root . '/storage/install.lock')) throw new RuntimeException('The guarded installed configuration changed.');
if ($ownership['mode'] === 'mysql') {
    require_once dirname(__DIR__) . '/Installer/database_fixture.php';
    [$db, $pdo] = vvx_installer_fixture_database();
    if ($db['host'] !== '127.0.0.1' || $ownership['database'] !== $db['name']
        || ($saved['db']['host'] ?? '') !== $db['host'] || ($saved['db']['name'] ?? '') !== $db['name']
        || ($saved['db']['user'] ?? '') !== $db['user'] || (int) ($saved['db']['port'] ?? 0) !== $db['port']) {
        throw new RuntimeException('Only the owned disposable loopback database is allowed.');
    }
}
// Deliberately do not boot extensions; the boot probe observes the real HTTP route.
require_once $root . '/includes/bootstrap.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
require_once $root . '/app/Core/MarketingStore.php';

$probeId = 'velvet.recovery-probe';
$probeDir = $root . '/addons/installed/' . $probeId;
$probeMarker = $root . '/storage/temp/editorial-recovery-boot-probe.txt';
$probeSource = '<?php file_put_contents(root_path("storage/temp/editorial-recovery-boot-probe.txt"), "boot\\n", FILE_APPEND | LOCK_EX);';
if (in_array($action, ['probe-install', 'probe-read', 'probe-remove'], true)) {
    $store = new App\Core\Store($config);
    if ($action === 'probe-install') {
        if ($store->addon($probeId) !== null || file_exists($probeDir) || file_exists($probeMarker)) throw new RuntimeException('The reserved probe already exists.');
        if (!mkdir($probeDir . '/src', 0700, true)) throw new RuntimeException('Cannot create the owned probe.');
        $manifest = ['id' => $probeId, 'name' => 'Owned recovery boot probe', 'vendor' => 'Disposable fixture',
            'version' => '1.0.0', 'minimum_core_version' => '0.8.0', 'minimum_php_version' => '8.2.0', 'permissions' => [],
            'bootstrap' => 'src/bootstrap.php'];
        App\Core\AddonManifest::fromArray($manifest);
        file_put_contents($probeDir . '/addon.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        file_put_contents($probeDir . '/src/bootstrap.php', $probeSource);
        $store->saveAddon(['id' => $probeId, 'manifest' => $manifest, 'version' => '1.0.0', 'status' => 'active',
            'installedPath' => 'addons/installed/' . $probeId]);
    }
    if ($store->addon($probeId) === null || !is_file($probeDir . '/src/bootstrap.php')
        || file_get_contents($probeDir . '/src/bootstrap.php') !== $probeSource) throw new RuntimeException('The owned probe identity changed.');
    $result = ['boots' => is_file($probeMarker) ? substr_count((string) file_get_contents($probeMarker), 'boot') : 0,
        'status' => $store->addon($probeId)['status']];
    if ($action === 'probe-remove') {
        $store->deleteAddon($probeId);
        unlink($probeDir . '/src/bootstrap.php'); unlink($probeDir . '/addon.json'); rmdir($probeDir . '/src'); rmdir($probeDir);
        if (is_file($probeMarker)) unlink($probeMarker);
    }
    echo json_encode($result, JSON_THROW_ON_ERROR); exit;
}

$marketing = new Velvet\Core\MarketingStore($root);
$manifest = json_decode((string) file_get_contents($historyPath), true, 512, JSON_THROW_ON_ERROR);
$backup = $root . '/storage/temp/editorial-recovery-corrupt-held.json';
if ($action === 'archive-restore') {
    if (!is_file($backup) || is_link($backup)) throw new RuntimeException('Only the held synthetic archive can be restored.');
    $original = json_decode((string) file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR);
    Velvet\Core\MarketingRecovery::entries([Velvet\Core\MarketingRecovery::KEY => $original]);
    $marketing->update(static function (array &$data) use ($original): void { $data[Velvet\Core\MarketingRecovery::KEY] = $original; });
    unlink($backup);
    echo json_encode(['restored' => true], JSON_THROW_ON_ERROR); exit;
}
$collection = $input['collection'] ?? 'posts';
if (!is_string($collection)) throw new RuntimeException('Invalid reserved collection.');
Velvet\Core\MarketingPublication::collection($collection);
$entryId = Velvet\Core\MarketingRecovery::id($input['entry_id'] ?? null);
$entries = Velvet\Core\MarketingRecovery::entries($marketing->all());
$selected = null;
foreach ($entries as $entry) if ($entry['collection'] === $collection && $entry['id'] === $entryId) $selected = $entry;
if ($selected === null || !in_array($selected['record']['id'], array_column($manifest['records'][$collection], 'id'), true)) {
    throw new RuntimeException('Only a reserved synthetic history identity can be changed.');
}
if ($action === 'collision-add' || $action === 'collision-remove') {
    $kind = $input['kind'] ?? null;
    if (!in_array($kind, ['id', 'slug'], true) || ($kind === 'slug' && $collection === 'banners')) throw new RuntimeException('Invalid collision kind.');
    $row = $selected['record'];
    $row['id'] = $kind === 'id' ? $row['id'] : 'recovery-imported-collision';
    if ($kind === 'id' && $collection !== 'banners') $row['slug'] = 'recovery-imported-collision';
    $row['recovery_collision_fixture'] = $entryId;
    $marketing->update(static function (array &$data) use ($row, $collection, $action, $entryId): void {
        $matches = array_keys(array_filter($data[$collection], static fn($candidate) => ($candidate['id'] ?? null) === $row['id']));
        if ($action === 'collision-add') {
            if ($matches !== []) throw new RuntimeException('The reserved collision identity already exists.');
            $data[$collection][] = $row;
        } else {
            if (count($matches) !== 1 || ($data[$collection][$matches[0]]['recovery_collision_fixture'] ?? null) !== $entryId) throw new RuntimeException('The reserved collision identity changed.');
            unset($data[$collection][$matches[0]]); $data[$collection] = array_values($data[$collection]);
        }
    });
    echo json_encode(['changed' => $row['id']], JSON_THROW_ON_ERROR); exit;
}
if ($action === 'archive-corrupt') {
    if (file_exists($backup)) throw new RuntimeException('An owned archive is already held.');
    $envelope = $marketing->all()[Velvet\Core\MarketingRecovery::KEY];
    file_put_contents($backup, json_encode($envelope, JSON_THROW_ON_ERROR)); chmod($backup, 0600);
    $marketing->update(static function (array &$data) use ($entryId): void {
        foreach ($data[Velvet\Core\MarketingRecovery::KEY]['entries'] as &$entry) if ($entry['id'] === $entryId) $entry['record_hash'] = str_repeat('0', 64);
    });
    echo json_encode(['corrupted' => true], JSON_THROW_ON_ERROR); exit;
}
if ($action === 'archive-revise') {
    $marketing->update(static function (array &$data) use ($entryId): void {
        foreach ($data[Velvet\Core\MarketingRecovery::KEY]['entries'] as &$entry) {
            if ($entry['id'] !== $entryId) continue;
            $entry['record']['history_unknown'] = ['nested' => ['keep' => 'HISTORY_PRIVATE_UNKNOWN_IMPORTED']];
            $entry['record_hash'] = hash('sha256', json_encode($entry['record'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR, 64));
        }
    });
    echo json_encode(['revised' => true], JSON_THROW_ON_ERROR); exit;
}
throw new RuntimeException('Unknown owned recovery fixture action.');

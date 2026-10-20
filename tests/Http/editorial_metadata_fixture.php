<?php
declare(strict_types=1);

// Extends only the independently marked, installed editorial acceptance copy.
// It is a private CLI helper, never a public route or customer-package entry.
$root = realpath((string) ($argv[1] ?? ''));
$action = (string) ($argv[2] ?? '');
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || $root === false || !is_array($input)
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), '/') . '/') || !is_file($root . '/.vvx-test-runtime')) {
    throw new RuntimeException('A marked disposable metadata runtime is required.');
}
$ownership = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-ownership.json'), true, 512, JSON_THROW_ON_ERROR);
if (($ownership['runtime'] ?? '') !== $root || !in_array($ownership['mode'] ?? '', ['file', 'mysql'], true)
    || parse_url((string) ($ownership['base'] ?? ''), PHP_URL_HOST) !== '127.0.0.1'
    || !is_file($root . '/storage/temp/editorial-access-records.json')) {
    throw new RuntimeException('The prior guarded fixture must own and initialize this exact loopback runtime.');
}
$manifestPath = $root . '/storage/temp/editorial-metadata-records.json';
$mode = $ownership['mode'];
define('ROOT_PATH', $root);
require_once $root . '/includes/autoload.php';
$config = new App\Core\Config($root . '/config/app.php');
$saved = $config->all();
if (($saved['storage_mode'] ?? '') !== $mode || ($saved['base_url'] ?? '') !== $ownership['base']
    || ($saved['mail']['host'] ?? '') !== '127.0.0.1' || ($saved['mail']['mode'] ?? '') !== 'smtp'
    || (int) ($saved['mail']['port'] ?? 0) !== $ownership['smtp_port'] || !is_file($root . '/storage/install.lock')) {
    throw new RuntimeException('The installed fixture configuration or local mail capture changed.');
}
$pdo = null;
$db = null;
if ($mode === 'mysql') {
    require_once dirname(__DIR__) . '/Installer/database_fixture.php';
    [$db, $pdo] = vvx_installer_fixture_database();
    if ($db['host'] !== '127.0.0.1' || $ownership['database'] !== $db['name']
        || ($saved['db']['host'] ?? '') !== $db['host'] || ($saved['db']['name'] ?? '') !== $db['name']
        || ($saved['db']['user'] ?? '') !== $db['user'] || (int) ($saved['db']['port'] ?? 0) !== $db['port']) {
        throw new RuntimeException('Only the exact owned, disposable loopback database is allowed.');
    }
}
require_once $root . '/app/bootstrap.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$store = new App\Core\Store($config);
$owner = $store->findAdminByEmail('owner@example.test');
if (!$owner) throw new RuntimeException('The reserved owner is missing.');

// These paths intentionally precede MarketingStore construction: the actual
// save request may be holding its data lock while waiting on media_assets.
if (in_array($action, ['media-lock', 'media-wait'], true)) {
    if ($pdo === null || !is_file($manifestPath)) throw new RuntimeException('A seeded owned MySQL runtime is required.');
    if ($action === 'media-wait') {
        $query = $pdo->prepare('SELECT ID,COMMAND,STATE,INFO FROM information_schema.PROCESSLIST WHERE DB=? AND ID<>CONNECTION_ID()');
        $query->execute([$db['name']]);
        $waiting = []; $observed = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!str_contains(strtolower((string) $row['STATE']), 'lock')) continue;
            $observed[] = ['command' => $row['COMMAND'], 'state' => $row['STATE'], 'query' => substr((string) $row['INFO'], 0, 250)];
            if (preg_match('/^SELECT\s.+\bmedia_assets\b/is', (string) $row['INFO'])) $waiting[] = (int) $row['ID'];
        }
        echo json_encode(['waiting_connections' => $waiting, 'observed_locks' => $observed], JSON_THROW_ON_ERROR);
        exit;
    }
    $token = $input['token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{16}$/D', $token)) throw new RuntimeException('A reserved media-lock token is required.');
    $release = $root . '/storage/temp/editorial-metadata-media-' . $token . '.release';
    if (file_exists($release)) throw new RuntimeException('A media-lock token may only be used once.');
    $pdo->exec('LOCK TABLES media_assets WRITE');
    try {
        echo json_encode(['locked' => true, 'connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()], JSON_THROW_ON_ERROR) . "\n";
        flush();
        $deadline = microtime(true) + 30;
        while (!is_file($release) && microtime(true) < $deadline) { usleep(20000); clearstatcache(true, $release); }
        if (!is_file($release)) throw new RuntimeException('The owned media lock was not released in time.');
    } finally { $pdo->exec('UNLOCK TABLES'); }
    echo json_encode(['released' => true], JSON_THROW_ON_ERROR) . "\n";
    exit;
}

require_once $root . '/app/Core/MarketingStore.php';
require_once dirname(__DIR__) . '/Feature/editorial_metadata.php';
$marketing = new Velvet\Core\MarketingStore($root);
if ($action === 'seed') {
    if (is_file($manifestPath)) throw new RuntimeException('Metadata fixture already seeded.');
    $existing = $marketing->all();
    if (array_filter($existing['posts'], static fn(array $post): bool => str_starts_with((string) ($post['id'] ?? ''), 'metadata-'))) {
        throw new RuntimeException('Reserved metadata identities already exist.');
    }
    $assets = vvx_metadata_test_assets($store, $root);
    foreach (['members', 'gold', 'draft', 'future'] as $name) {
        $file = 'uploads/images/editorial-metadata-' . $name . '.png';
        copy($root . '/' . $assets['a']['file'], $root . '/' . $file);
        $assets[$name] = $store->addMedia(['title' => 'Metadata image ' . $name, 'type' => 'image',
            'file' => $file, 'thumbnail' => $file, 'mime' => 'image/png', 'width' => 96, 'height' => 64, 'private' => false]);
    }
    $prior = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-records.json'), true, 512, JSON_THROW_ON_ERROR);
    $gold = (string) $prior['plans']['gold']['id'];
    $records = [];
    foreach (['main', 'public-a', 'public-b', 'members', 'gold', 'draft', 'future', 'owner-draft', 'malformed'] as $name) {
        $changes = ['featured_asset_id' => (string) $assets[in_array($name, ['members', 'gold', 'draft', 'future'], true) ? $name : ($name === 'public-b' ? 'b' : 'a')]['id']];
        if ($name === 'members') $changes['access'] = ['version' => 1, 'mode' => 'members', 'plan_ids' => []];
        if ($name === 'gold') $changes['access'] = ['version' => 1, 'mode' => 'tiers', 'plan_ids' => [$gold]];
        if (in_array($name, ['draft', 'owner-draft'], true)) $changes['status'] = 'draft';
        if ($name === 'future') $changes['publish_at'] = '2035-01-01T00:00:00Z';
        if ($name === 'malformed') $changes += ['tags' => [['private' => true]], 'author_name' => ['private'], 'seo_title' => false,
            'seo_description' => ['PRIVATE_MALFORMED_DESCRIPTION'], 'related_post_ids' => ['../private']];
        $records[$name] = vvx_metadata_test_post($name, $changes);
    }
    $ordered = array_map(static fn(string $name): string => 'metadata-post-' . $name, ['gold', 'draft', 'future', 'members', 'public-b', 'public-a']);
    $records['main']['related_post_ids'] = $ordered;
    $records['owner-draft']['related_post_ids'] = $ordered;
    $page = ['id' => 'metadata-page-main', 'title' => 'Metadata landing page', 'slug' => 'metadata-page-main',
        'eyebrow' => 'Saved campaign', 'excerpt' => 'META_PAGE_EXCERPT', 'body' => 'META_PAGE_BODY',
        'cta_label' => 'Existing call to action', 'cta_url' => '/newsletter/', 'enabled' => true,
        'starts_at' => null, 'ends_at' => null, 'access' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []],
        'featured_asset_id' => (string) $assets['b']['id'], 'featured_alt' => 'META_PAGE_ALT',
        'seo_title' => 'META_PAGE_SEO', 'seo_description' => 'META_PAGE_DESCRIPTION',
        'legacy_unknown' => ['keep' => true], 'created_at' => '2020-01-01T00:00:00Z', 'updated_at' => '2020-01-01T00:00:00Z', 'edit_version' => 3];
    $marketing->update(static function (array &$data) use ($records, $page): void {
        array_push($data['posts'], ...array_values($records));
        $data['landing_pages'][] = $page;
    });
    $manifest = ['assets' => $assets, 'records' => $records, 'page' => $page, 'related_order' => $ordered,
        'post_positions' => array_flip(array_column($marketing->all()['posts'], 'id'))];
    file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    chmod($manifestPath, 0600);
    // Deletion uses actual saved asset state, independent of image() helpers.
    if ($pdo !== null) $pdo->prepare('UPDATE media_assets SET deleted_at=NOW() WHERE id=?')->execute([$assets['deleted']['id']]);
    else $store->mutateFileStore(static function (array &$data) use ($assets): void { $data['media'][(string) $assets['deleted']['id']]['deletedAt'] = gmdate('c'); });
    echo json_encode($manifest, JSON_THROW_ON_ERROR);
    exit;
}
if (!is_file($manifestPath)) throw new RuntimeException('Seed the owned metadata fixture first.');
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
if ($action === 'media-state') {
    $name = $input['name'] ?? '';
    $state = $input['state'] ?? '';
    if (!in_array($name, ['a', 'b'], true) || !in_array($state, ['deleted', 'private', 'restored', 'missing', 'file-restored'], true)) {
        throw new RuntimeException('Only the two reserved public images may be changed.');
    }
    $asset = $manifest['assets'][$name];
    if ($state === 'missing' || $state === 'file-restored') {
        $from = $root . '/' . $asset['file'] . ($state === 'missing' ? '' : '.held');
        $to = $root . '/' . $asset['file'] . ($state === 'missing' ? '.held' : '');
        if (!is_file($from) || is_file($to) || is_link($from)) throw new RuntimeException('The reserved original cannot be held/restored.');
        if (!rename($from, $to)) throw new RuntimeException('The reserved image move failed.');
    } elseif ($pdo !== null) {
        $query = $pdo->prepare('UPDATE media_assets SET deleted_at=?,file_path=? WHERE id=? AND title=?');
        $query->execute([$state === 'deleted' ? gmdate('Y-m-d H:i:s') : null,
            $state === 'private' ? $manifest['assets']['private']['file'] : $asset['file'], $asset['id'], $asset['title']]);
    } else {
        $store->mutateFileStore(static function (array &$data) use ($asset, $state, $manifest): void {
            $id = (string) $asset['id'];
            if (($data['media'][$id]['title'] ?? '') !== $asset['title']) throw new RuntimeException('Reserved asset identity changed.');
            $data['media'][$id] = $state === 'restored' ? $asset : array_replace($data['media'][$id],
                ['file' => $state === 'private' ? $manifest['assets']['private']['file'] : $asset['file'],
                 'private' => $state === 'private', 'deletedAt' => $state === 'deleted' ? gmdate('c') : null]);
        });
    }
    echo json_encode(['changed' => $name, 'state' => $state], JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'related-state') {
    $name = $input['name'] ?? '';
    $state = $input['state'] ?? '';
    if (!in_array($name, ['public-a', 'public-b', 'members', 'gold'], true) || !in_array($state, ['draft', 'future', 'members', 'remove', 'restore'], true)) {
        throw new RuntimeException('Only a reserved related fixture may be changed.');
    }
    $record = $manifest['records'][$name];
    $marketing->update(static function (array &$data) use ($record, $state, $manifest): void {
        $index = array_search($record['id'], array_column($data['posts'], 'id'), true);
        if ($state === 'restore') {
            if ($index === false) array_splice($data['posts'], $manifest['post_positions'][$record['id']], 0, [$record]);
            else $data['posts'][$index] = $record;
            return;
        }
        if ($index === false) throw new RuntimeException('The reserved related post is missing.');
        if ($state === 'remove') { array_splice($data['posts'], $index, 1); return; }
        if ($state === 'draft') $data['posts'][$index]['status'] = 'draft';
        if ($state === 'future') $data['posts'][$index]['publish_at'] = '2035-01-01T00:00:00Z';
        if ($state === 'members') $data['posts'][$index]['access'] = ['version' => 1, 'mode' => 'members', 'plan_ids' => []];
    });
    echo json_encode(['changed' => $name, 'state' => $state], JSON_THROW_ON_ERROR);
    exit;
}
if ($action !== 'read') throw new RuntimeException('Unknown metadata fixture action.');
$result = ['manifest' => $manifest, 'assets' => [], 'image_files' => [], 'raw_sql_assets' => null];
foreach ($manifest['assets'] as $name => $asset) {
    $result['assets'][$name] = $store->mediaAsset((string) $asset['id']);
    $relative = (string) $asset['file'];
    if (str_starts_with($relative, 'uploads/images/') && !str_contains($relative, '..') && !str_contains($relative, '?')) {
        $path = $root . '/' . $relative;
        $result['image_files'][$name] = is_link($path) ? ['link' => readlink($path)]
            : (is_file($path) ? ['sha256' => hash_file('sha256', $path), 'bytes' => filesize($path)] : null);
    }
}
if ($pdo !== null) {
    $ids = array_map(static fn(array $asset): string => (string) $asset['id'], array_values($manifest['assets']));
    $query = $pdo->prepare('SELECT id,title,file_path,thumbnail_path,mime_type,deleted_at,tags_json,metadata_json FROM media_assets WHERE id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
    $query->execute($ids);
    $result['raw_sql_assets'] = $query->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

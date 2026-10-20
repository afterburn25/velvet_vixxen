<?php
declare(strict_types=1);

// Private CLI helper for an independently marked, installed loopback package.
// This file never belongs in a customer archive or a public route.
$root = realpath((string) ($argv[1] ?? ''));
$action = (string) ($argv[2] ?? '');
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || $root === false || !is_array($input)
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), '/') . '/') || !is_file($root . '/.vvx-test-runtime')) {
    throw new RuntimeException('A marked disposable history runtime is required.');
}
$ownership = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-ownership.json'), true, 512, JSON_THROW_ON_ERROR);
$metadataPath = $root . '/storage/temp/editorial-metadata-records.json';
$manifestPath = $root . '/storage/temp/editorial-history-records.json';
if (($ownership['runtime'] ?? '') !== $root || !in_array($ownership['mode'] ?? '', ['file', 'mysql'], true)
    || parse_url((string) ($ownership['base'] ?? ''), PHP_URL_HOST) !== '127.0.0.1' || !is_file($metadataPath)) {
    throw new RuntimeException('The guarded editorial and metadata fixtures must own this exact runtime.');
}
define('ROOT_PATH', $root);
require_once $root . '/includes/autoload.php';
$config = new App\Core\Config($root . '/config/app.php');
$saved = $config->all();
if (($saved['storage_mode'] ?? '') !== $ownership['mode'] || ($saved['base_url'] ?? '') !== $ownership['base']
    || ($saved['mail']['mode'] ?? '') !== 'smtp' || ($saved['mail']['host'] ?? '') !== '127.0.0.1'
    || (int) ($saved['mail']['port'] ?? 0) !== $ownership['smtp_port'] || !is_file($root . '/storage/install.lock')) {
    throw new RuntimeException('The installed fixture configuration or local mail listener changed.');
}
$pdo = null;
$db = null;
if ($ownership['mode'] === 'mysql') {
    require_once dirname(__DIR__) . '/Installer/database_fixture.php';
    [$db, $pdo] = vvx_installer_fixture_database();
    if ($db['host'] !== '127.0.0.1' || $ownership['database'] !== $db['name']
        || ($saved['db']['host'] ?? '') !== $db['host'] || ($saved['db']['name'] ?? '') !== $db['name']
        || ($saved['db']['user'] ?? '') !== $db['user'] || (int) ($saved['db']['port'] ?? 0) !== $db['port']) {
        throw new RuntimeException('Only the owned disposable loopback database is allowed.');
    }
}
require_once $root . '/app/bootstrap.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

if (in_array($action, ['hold-read-artifact', 'restore-read-artifact', 'read-artifacts', 'read-lock-mode'], true)) {
    if (!is_file($manifestPath)) throw new RuntimeException('An initialized history fixture is required.');
    $directory = $root . '/storage/private/marketing';
    if ($action === 'read-lock-mode') {
        $mode = $input['mode'] ?? null;
        $path = $directory . '/data.lock';
        if (!in_array($mode, [0440, 0640], true) || !is_file($path) || is_link($path)) {
            throw new RuntimeException('Only a known read-only/owner mode may be applied to the reserved lock.');
        }
        if (!chmod($path, $mode)) throw new RuntimeException('The reserved lock mode could not be set.');
        clearstatcache(true, $path);
    } elseif ($action !== 'read-artifacts') {
        $name = $input['name'] ?? '';
        if (!in_array($name, ['data.lock', 'sql-cutover.json'], true)
            || ($name === 'sql-cutover.json' && $pdo === null)) throw new RuntimeException('Unknown owned read artifact.');
        $live = $directory . '/' . $name;
        $held = $root . '/storage/temp/editorial-history-held-' . $name;
        [$from, $to] = $action === 'hold-read-artifact' ? [$live, $held] : [$held, $live];
        if (!is_file($from) || is_link($from) || file_exists($to)) throw new RuntimeException('The owned artifact cannot be held/restored exactly.');
        if (!rename($from, $to)) throw new RuntimeException('The owned artifact move failed.');
    }
    $files = [];
    foreach (['data.json', 'data.lock', 'sql-cutover.json', 'delivery.lock'] as $name) {
        $path = $directory . '/' . $name;
        $files[$name] = is_file($path) && !is_link($path)
            ? ['sha256' => hash_file('sha256', $path), 'mode' => fileperms($path) & 0777] : null;
    }
    echo json_encode(['files' => $files], JSON_THROW_ON_ERROR);
    exit;
}

// These operations deliberately precede MarketingStore construction. The real
// history request is observed waiting on its authoritative record read.
if (in_array($action, ['records-lock', 'records-wait'], true)) {
    if ($pdo === null || !is_file($manifestPath)) throw new RuntimeException('An initialized owned MySQL fixture is required.');
    if ($action === 'records-wait') {
        $query = $pdo->prepare('SELECT ID,COMMAND,STATE,INFO FROM information_schema.PROCESSLIST WHERE DB=? AND ID<>CONNECTION_ID()');
        $query->execute([$db['name']]);
        $waiting = []; $observed = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!str_contains(strtolower((string) $row['STATE']), 'lock')) continue;
            $observed[] = ['command' => $row['COMMAND'], 'state' => $row['STATE'], 'query' => substr((string) $row['INFO'], 0, 250)];
            if (preg_match('/^SELECT\s.+\bmarketing_storage_records\b/is', (string) $row['INFO'])) $waiting[] = (int) $row['ID'];
        }
        echo json_encode(['waiting_connections' => $waiting, 'observed_locks' => $observed], JSON_THROW_ON_ERROR);
        exit;
    }
    $token = $input['token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{16}$/D', $token)) throw new RuntimeException('A reserved lock token is required.');
    $release = $root . '/storage/temp/editorial-history-records-' . $token . '.release';
    if (file_exists($release)) throw new RuntimeException('The record lock token may only be used once.');
    $pdo->exec('LOCK TABLES marketing_storage_records WRITE');
    try {
        echo json_encode(['locked' => true, 'connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()], JSON_THROW_ON_ERROR) . "\n";
        flush();
        $deadline = microtime(true) + 30;
        while (!is_file($release) && microtime(true) < $deadline) { usleep(20000); clearstatcache(true, $release); }
        if (!is_file($release)) throw new RuntimeException('The owned history record lock was not released.');
    } finally { $pdo->exec('UNLOCK TABLES'); }
    echo json_encode(['released' => true], JSON_THROW_ON_ERROR) . "\n";
    exit;
}

require_once $root . '/app/Core/MarketingStore.php';
$marketing = new Velvet\Core\MarketingStore($root);
$metadata = json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
if ($action === 'seed') {
    if (is_file($manifestPath)) throw new RuntimeException('The history fixture is already seeded.');
    $existing = $marketing->all();
    foreach (['posts', 'banners', 'landing_pages'] as $collection) {
        foreach ($existing[$collection] as $row) {
            if (str_starts_with((string) ($row['id'] ?? ''), 'history-')) throw new RuntimeException('Reserved history identities already exist.');
        }
    }
    $records = [];
    foreach (['posts' => 'post', 'banners' => 'banner', 'landing_pages' => 'page'] as $collection => $kind) {
        $base = $collection === 'posts' ? $metadata['records']['main'] : ($collection === 'landing_pages' ? $metadata['page'] : [
            'text' => 'Original history banner', 'url' => '/newsletter/', 'enabled' => true, 'starts_at' => null, 'ends_at' => null,
            'access' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []], 'created_at' => '2020-01-01T00:00:00Z',
            'updated_at' => '2020-01-01T00:00:00Z', 'edit_version' => 4]);
        foreach ($collection === 'posts' ? ['main', 'guarded', 'retention', 'malformed', 'delete'] : ['main'] as $name) {
            $row = $base;
            $row['id'] = 'history-' . $kind . '-' . $name;
            $row[$collection === 'banners' ? 'text' : 'title'] = 'History original ' . $kind . ' ' . $name;
            if ($collection !== 'banners') {
                $row['slug'] = $row['id'];
                $row['body'] = 'HISTORY_PRIOR_BODY_' . $collection . '_' . $name . "\n" . '<img data-history-injection="body" src="x"> & original text';
                $row['excerpt'] = 'HISTORY_PRIOR_EXCERPT_' . $name;
                $row['seo_title'] = 'History original search ' . $name;
                $row['seo_description'] = 'HISTORY_PRIOR_DESCRIPTION_' . $name;
                $row['featured_asset_id'] = (string) $metadata['assets']['a']['id'];
                $row['featured_alt'] = 'History original image description';
            }
            if ($collection === 'posts') {
                $row['related_post_ids'] = ['metadata-post-public-b', 'metadata-post-public-a'];
                $row['tags'] = ['History original', 'Café'];
                $row['author_name'] = 'Nikki original byline';
                if ($name === 'guarded') $row['access'] = $metadata['records']['gold']['access'];
            }
            $row['history_unknown'] = ['nested' => ['keep' => 'HISTORY_PRIVATE_UNKNOWN_' . $kind . '_' . $name]];
            $records[$collection][$name] = $row;
        }
    }
    $marketing->update(static function (array &$data) use ($records): void {
        foreach ($records as $collection => $rows) array_push($data[$collection], ...array_values($rows));
    });
    $manifest = ['records' => $records, 'positions' => []];
    foreach ($records as $collection => $_) $manifest['positions'][$collection] = array_column($marketing->all()[$collection], 'id');
    file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    chmod($manifestPath, 0600);
    echo json_encode($manifest, JSON_THROW_ON_ERROR);
    exit;
}
if (!is_file($manifestPath)) throw new RuntimeException('Seed the owned history fixture first.');
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
if ($action === 'current-unknown') {
    $collection = $input['collection'] ?? '';
    if (!in_array($collection, ['posts', 'banners', 'landing_pages'], true)) throw new RuntimeException('Unknown reserved collection.');
    $id = $manifest['records'][$collection]['main']['id'];
    $marketing->update(static function (array &$data) use ($collection, $id): void {
        foreach ($data[$collection] as &$row) {
            if (($row['id'] ?? null) === $id) $row['history_unknown'] = ['nested' => ['keep' => 'HISTORY_PRIVATE_UNKNOWN_CURRENT_' . $collection]];
        }
    });
    echo json_encode(['changed' => $id], JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'malformed-history') {
    $marketing->update(static function (array &$data): void {
        foreach ($data['posts'] as &$row) {
            if (($row['id'] ?? null) === 'history-post-malformed') {
                $row['editorial_history'] = ['schema' => 'invalid', 'entries' => 'HISTORY_MALFORMED_PRIVATE'];
            }
        }
    });
    echo json_encode(['changed' => 'history-post-malformed'], JSON_THROW_ON_ERROR);
    exit;
}
if ($action !== 'read') throw new RuntimeException('Unknown history fixture action.');
echo json_encode(['manifest' => $manifest], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

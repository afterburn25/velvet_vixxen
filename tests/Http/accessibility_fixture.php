<?php
declare(strict_types=1);

// Populate visible interactive content only inside the exact, already installed
// loopback fixture. The browser then executes the unmodified customer runtime.
$root = realpath($argv[1] ?? '');
if (PHP_SAPI !== 'cli' || !$root || !str_starts_with($root, sys_get_temp_dir() . '/')
    || !is_file($root . '/.vvx-test-runtime') || !is_file($root . '/storage/temp/editorial-access-ownership.json')) {
    throw new RuntimeException('An owned disposable accessibility runtime is required.');
}
$owned = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-ownership.json'), true, 32, JSON_THROW_ON_ERROR);
$values = require $root . '/config/app.php';
if (($owned['runtime'] ?? '') !== $root || ($values['base_url'] ?? '') !== ($owned['base'] ?? '')
    || parse_url($owned['base'], PHP_URL_HOST) !== '127.0.0.1'
    || !in_array($owned['mode'] ?? '', ['file', 'mysql'], true)
    || ($values['storage_mode'] ?? '') !== $owned['mode'] || !is_file($root . '/storage/install.lock')
    || ($values['mail']['host'] ?? '') !== '127.0.0.1' || ($values['mail']['mode'] ?? '') !== 'smtp'
    || (int) ($values['mail']['port'] ?? 0) !== $owned['smtp_port']) {
    throw new RuntimeException('Only the exact installed loopback fixture with local capture may be seeded.');
}
if ($owned['mode'] === 'mysql') {
    require __DIR__ . '/../Installer/database_fixture.php';
    [$db, $pdo] = vvx_installer_fixture_database();
    if ($db['host'] !== '127.0.0.1' || ($owned['database'] ?? '') !== $db['name']
        || ($values['db']['name'] ?? '') !== $db['name'] || ($values['db']['user'] ?? '') !== $db['user']
        || (int) ($values['db']['port'] ?? 0) !== $db['port']) {
        throw new RuntimeException('The isolated accessibility database identity changed.');
    }
}
define('ROOT_PATH', $root);
require $root . '/includes/autoload.php';
require $root . '/app/Support/functions.php';
$config = new App\Core\Config($root . '/config/app.php');
$store = new App\Core\Store($config);
if (!$store->findAdminByEmail('owner@example.test') || $store->video('accessibility-preview-fixture')) {
    throw new RuntimeException('The reserved owner or empty accessibility media identity is unavailable.');
}
$site = (array) $store->setting('site', []);
$site['age_gate_enabled'] = true;
$store->setSetting('site', $site);
$defaults = json_decode((string) file_get_contents($root . '/storage/defaults/data.json'), true, 512, JSON_THROW_ON_ERROR);
$assets = [];
foreach ($defaults['media'] as $asset) {
    if (!in_array($asset['id'] ?? '', ['demo-video-poster', 'demo-video-trailer', 'demo-caption'], true)) continue;
    $identity = $asset['id'];
    if (!empty($asset['private']) || str_starts_with($asset['file'], 'private:')
        || !is_file($root . '/uploads/' . $asset['file'])) throw new RuntimeException('A real packaged public demo asset is required.');
    unset($asset['id']);
    $assets[$identity] = $store->addMedia($asset);
}
if (count($assets) !== 3) throw new RuntimeException('The saved poster, trailer and captions are required.');
$video = $store->saveVideo(['slug' => 'accessibility-preview-fixture', 'title' => 'Accessible preview fixture',
    'description' => 'A people-free packaged demo for the real player and caption controls.',
    'posterAssetId' => $assets['demo-video-poster']['id'], 'trailerAssetId' => $assets['demo-video-trailer']['id'],
    'fullAssetId' => $assets['demo-video-trailer']['id'], 'captionAssetId' => $assets['demo-caption']['id'],
    'status' => 'published', 'accessMode' => 'public', 'currency' => 'USD',
    'publishedAt' => gmdate('Y-m-d H:i:s'), 'duration' => 6]);
if (!$video || empty($video['captionAsset'])) throw new RuntimeException('Actual stored media relationships were not preserved.');
echo json_encode(['seeded' => true, 'mode' => $store->mode(), 'video_id' => $video['id']], JSON_THROW_ON_ERROR);

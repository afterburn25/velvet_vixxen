<?php
declare(strict_types=1);

$root = realpath((string) ($argv[1] ?? '')); $action = (string) ($argv[2] ?? '');
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || !$root || !str_starts_with($root, sys_get_temp_dir() . '/vvx-protection-') || !is_file($root . '/.vvx-test-runtime')) throw new RuntimeException('An owned protection package fixture is required.');
$ownership = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-ownership.json'), true, 512, JSON_THROW_ON_ERROR);
if (($ownership['runtime'] ?? null) !== $root || !in_array($ownership['mode'] ?? '', ['file', 'mysql'], true) || parse_url($ownership['base'] ?? '', PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('The loopback fixture ownership does not match.');
define('ROOT_PATH', $root); require_once $root . '/includes/bootstrap.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if (app_config('base_url') !== $ownership['base'] || app_config('storage_mode') !== $ownership['mode']) throw new RuntimeException('Fixture configuration changed.');
require_once $root . '/app/Core/MarketingStore.php';
$store = new App\Core\Store(app_config()); $marketing = new Velvet\Core\MarketingStore($root);
$recordsFile = $root . '/storage/temp/protection-fixture-records.json';
if ($action === 'seed') {
    if (is_file($recordsFile)) throw new RuntimeException('The protection fixture was already seeded.');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6XioAAAAASUVORK5CYII=');
    file_put_contents($root . '/uploads/protection-picture.png', $png);
    $image = $store->addMedia(['title' => 'Protection fixture picture', 'type' => 'image', 'file' => 'protection-picture.png', 'thumbnail' => 'protection-picture.png', 'mime' => 'image/png', 'size' => strlen($png), 'width' => 1, 'height' => 1]);
    if (!is_dir($root . '/storage/private/media/fixture')) mkdir($root . '/storage/private/media/fixture', 0700, true);
    file_put_contents($root . '/storage/private/media/fixture/protected.mp4', 'SYNTHETIC_PRIVATE_VIDEO_BYTES'); chmod($root . '/storage/private/media/fixture/protected.mp4', 0600);
    $private = $store->addMedia(['title' => 'Protection fixture private video', 'type' => 'video', 'file' => 'private:fixture/protected.mp4', 'mime' => 'video/mp4', 'private' => true, 'size' => 29]);
    $album = $store->saveAlbum(['title' => 'Protection fixture album', 'slug' => 'protection-fixture-album', 'status' => 'published', 'coverAssetId' => $image['id']]);
    $video = $store->saveVideo(['title' => 'Protection fixture video', 'slug' => 'protection-fixture-video', 'status' => 'published', 'posterAssetId' => $image['id'], 'fullAssetId' => $private['id'], 'accessMode' => 'public']);
    $post = $marketing->savePublication('posts', ['title' => 'Protection fixture post', 'slug' => 'protection-fixture-post', 'status' => 'published', 'body' => 'This current body must survive withdrawal.', 'featured_asset_id' => $image['id']]);
    if ($ownership['mode'] === 'mysql') { $storage = new Velvet\Core\MarketingStorage($root); $plan = $storage->status(); $storage->migrate($plan['plan']); }
    $records = ['image' => $image['id'], 'private' => $private['id'], 'album' => $album['id'], 'video' => $video['id'], 'post' => $post['id'],
        'signed_url' => media_signed_url($private['id']), 'image_sha256' => hash('sha256', $png)];
    file_put_contents($recordsFile, json_encode($records, JSON_THROW_ON_ERROR)); chmod($recordsFile, 0600);
    echo json_encode($records, JSON_THROW_ON_ERROR); exit;
}
$records = json_decode((string) file_get_contents($recordsFile), true, 512, JSON_THROW_ON_ERROR);
$protection = new App\Core\ProtectionRecords(app_config());
if ($action === 'snapshot') {
    $state = (new App\Core\PrivateState(app_config()))->read('protection');
    $marketingData = $marketing->all();
    $protected = array_intersect_key($marketingData, array_flip(['contacts', 'suppressions', 'consents', 'campaigns', 'campaign_queue', 'coupons', 'promotions', 'tracking_links', 'analytics_events']));
    echo json_encode(['state' => $state, 'enabled' => app_config('protection.enabled', false), 'pending' => app_config('protection.setup_pending', false),
        'config_sha256' => hash_file('sha256', $root . '/config/app.php'), 'protected_sha256' => hash('sha256', json_encode($protected, JSON_THROW_ON_ERROR)),
        'album' => $store->album($records['album']), 'video' => $store->video($records['video']), 'post' => $marketing->publicationRecord('posts', $records['post']),
        'raw_exists' => is_file($root . '/uploads/protection-picture.png'),
        'probe_files' => count(glob($root . '/uploads/protection-check-*.txt')),
        'private_png' => $protection->vaultEntry('uploads/protection-picture.png')], JSON_THROW_ON_ERROR); exit;
}
if ($action === 'post-edit') {
    $post = $marketing->publicationRecord('posts', $records['post']);
    $marketing->savePublication('posts', ['id' => $post['id'], 'expected_revision' => $post['revision'], 'status' => 'draft', 'body' => 'Newest saved protection draft.']);
    echo '{}'; exit;
}
if ($action === 'stale-public-writer') {
    // A real old worker can still write a raw file after protection activation.
    // The actual server rule, independent of its absence, must deny that file.
    file_put_contents($root . '/uploads/old-worker-output.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6XioAAAAASUVORK5CYII='));
    echo json_encode(['exists' => is_file($root . '/uploads/old-worker-output.png')], JSON_THROW_ON_ERROR); exit;
}
if ($action === 'age-owner-session') {
    $token = $input['cookie'] ?? '';
    if (!is_string($token) || preg_match('/\A[a-zA-Z0-9,-]{1,128}\z/D', $token) !== 1) throw new RuntimeException('Choose an owned test session.');
    session_name('velvet_vixxen_admin');
    session_save_path($root . '/storage/sessions');
    if (!is_file($root . '/storage/sessions/sess_' . $token)) throw new RuntimeException('The owned admin session file is absent.');
    session_id($token); session_start();
    if (!isset($_SESSION['admin']['authenticated_at'])) throw new RuntimeException('The owned admin session is absent.');
    $_SESSION['admin']['authenticated_at'] = time() - 901; session_write_close(); echo '{}'; exit;
}
throw new RuntimeException('Unknown protection fixture action.');

<?php
declare(strict_types=1);

// Private CLI acceptance helper. It is never served or shipped in a customer ZIP.
$root = realpath((string) ($argv[1] ?? ''));
$action = (string) ($argv[2] ?? '');
if (PHP_SAPI !== 'cli' || $root === false
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    || !is_file($root . '/.vvx-test-runtime')) {
    throw new RuntimeException('A marked disposable temporary runtime is required.');
}
$idsPath = $root . '/storage/temp/media-acceptance-ids.json';
$ownershipPath = $root . '/storage/temp/media-database-ownership.json';
if ($action === 'setup-mysql' || $action === 'cleanup-mysql') {
    require_once dirname(__DIR__) . '/Installer/database_fixture.php';
    [$db, $pdo] = vvx_installer_fixture_database();
    if ($action === 'cleanup-mysql') {
        $ownership = is_file($ownershipPath) ? json_decode((string) file_get_contents($ownershipPath), true, 512, JSON_THROW_ON_ERROR) : [];
        if (($ownership['database'] ?? '') !== $db['name'] || ($ownership['runtime'] ?? '') !== $root) {
            throw new RuntimeException('Refusing cleanup: this fixture did not claim an initially empty database.');
        }
        vvx_installer_fixture_reset_database($pdo, true);
        unlink($ownershipPath);
        echo json_encode(['cleaned' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    $base = rtrim((string) ($argv[3] ?? ''), '/');
    if (parse_url($base, PHP_URL_SCHEME) !== 'http' || parse_url($base, PHP_URL_HOST) !== '127.0.0.1'
        || is_file($root . '/storage/install.lock')) {
        throw new RuntimeException('A new loopback runtime is required for MySQL acceptance.');
    }
    vvx_installer_fixture_empty($pdo);
    if (!is_dir(dirname($ownershipPath))) mkdir(dirname($ownershipPath), 0700, true);
    file_put_contents($ownershipPath, json_encode(['database' => $db['name'], 'runtime' => $root], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/.vvx-installer-db-fixture', 'Disposable Media Center package acceptance.');
    if (!is_file($root . '/config/app.php')) copy($root . '/config/app.example.php', $root . '/config/app.php');
    vvx_installer_fixture_load($root);
    $payload = vvx_installer_fixture_payload($db, $base);
    $payload['owner'] = ['name' => 'Media Fixture Owner', 'email' => 'owner@example.test', 'password' => 'VelvetDemo12'];
    $payload['site']['timezone'] = 'UTC';
    $payload['site']['site_name'] = 'Media Center acceptance';
    vvx_installer_fixture_install($root, $payload);
    $action = 'initialize';
}

if (!defined('ROOT_PATH')) define('ROOT_PATH', $root);
require_once $root . '/includes/autoload.php';
if ($action === 'initialize') {
    $config = new App\Core\Config($root . '/config/app.php');
    $saved = $config->all();
    if (parse_url((string) ($saved['base_url'] ?? ''), PHP_URL_HOST) !== '127.0.0.1' || is_file($idsPath)) {
        throw new RuntimeException('Refusing to initialize an existing or non-loopback acceptance fixture.');
    }
    $saved['timezone'] = 'UTC';
    $config->write($saved);
}
require_once $root . '/app/bootstrap.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
require_once dirname(__DIR__) . '/Feature/media_center_persistence.php';
$store = new App\Core\Store(new App\Core\Config($root . '/config/app.php'));

if ($action === 'initialize') {
    if ($store->mode() === 'file') {
        $store->mutateFileStore(static function (array &$data): void {
            $owner = isset($data['admins']['upload-fixture-owner']) ? 'upload-fixture-owner' : 'demo-owner';
            if (!isset($data['admins'][$owner]) || !in_array($data['admins'][$owner]['email'] ?? '', ['owner@example.test', 'admin@example.com'], true)) {
                throw new RuntimeException('The reserved fixture owner is missing.');
            }
            $data['admins'][$owner]['email'] = 'owner@example.test';
            $data['admins'][$owner]['name'] = 'Media Fixture Owner';
            foreach ($data['members'] as &$member) {
                if (($member['email'] ?? '') === 'member@example.com') $member['email'] = 'member@example.test';
            }
            unset($member);
            $data['settings']['site']['age_gate_enabled'] = false;
        });
    } elseif (!$store->member('member@example.test')) {
        $member = $store->createMember(['displayName' => 'Media Fixture Member', 'email' => 'member@example.test',
            'password' => 'VelvetMember12', 'status' => 'active']);
        $store->updateMember($member['id'], ['emailVerifiedAt' => gmdate('Y-m-d H:i:s')]);
    }
    $seed = vvx_media_test_seed($store, 'media-acceptance');
    $store->setSetting('media_acceptance_unrelated', ['preserve' => true]);
    if (!is_dir(dirname($idsPath))) mkdir(dirname($idsPath), 0700, true);
    file_put_contents($idsPath, json_encode($seed, JSON_THROW_ON_ERROR));
    chmod($idsPath, 0600);
    $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    foreach (['a' => [239, 25, 158], 'b' => [34, 129, 204], 'c' => [121, 39, 188], 'cover' => [210, 81, 45]] as $key => $color) {
        $pixels = '';
        for ($y = 0; $y < 64; $y++) $pixels .= "\0" . str_repeat(pack('C3', ...$color), 64);
        $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNC5', 64, 64, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($pixels)) . $chunk('IEND', '');
        foreach (['storage/private/media/images/media-acceptance', 'uploads/thumbnails/media-acceptance'] as $directory) {
            if (!is_dir($root . '/' . $directory)) mkdir($root . '/' . $directory, 0755, true);
            file_put_contents($root . '/' . $directory . '/' . $key . '.png', $png);
        }
    }
    $directory = $root . '/storage/private/media/audio/media-acceptance';
    if (!is_dir($directory)) mkdir($directory, 0755, true);
    $pcm = str_repeat("\0", 16000);
    file_put_contents($directory . '/tone.wav', 'RIFF' . pack('V', strlen($pcm) + 36) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm);
    echo json_encode(['initialized' => true, 'mode' => $store->mode()], JSON_THROW_ON_ERROR);
    exit;
}

if (!is_file($idsPath)) throw new RuntimeException('Initialize the reserved Media Center fixture first.');
$seed = json_decode((string) file_get_contents($idsPath), true, 512, JSON_THROW_ON_ERROR);
if ($action === 'member-session') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('velvet_vixxen_admin');
        session_save_path($root . '/storage/sessions');
        session_start();
    }
    $result = member_auth()->attempt('member@example.test', 'VelvetMember12');
    if (empty($result['ok']) || !member_auth()->check() || app_auth()->check()) throw new RuntimeException('A real member-only session is required.');
    $session = ['name' => session_name(), 'id' => session_id(), 'csrf' => csrf_token()];
    session_write_close();
    echo json_encode($session, JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'owner-status') {
    $status = (string) ($argv[3] ?? '');
    if (!in_array($status, ['owner', 'demoted', 'inactive'], true)) throw new RuntimeException('Unknown reserved owner status.');
    $role = $status === 'demoted' ? 'editor' : 'owner'; $active = $status === 'inactive' ? 0 : 1;
    if ($store->mode() === 'file') {
        $store->mutateFileStore(static function (array &$data) use ($role, $active): void {
            foreach ($data['admins'] as &$owner) if (($owner['email'] ?? '') === 'owner@example.test') {
                $owner['role'] = $role; $owner['is_active'] = $active;
            }
        });
    } else {
        $db = (new App\Core\Database(new App\Core\Config($root . '/config/app.php')))->connection();
        $db->prepare('UPDATE admins SET role=?,is_active=? WHERE email=?')->execute([$role, $active, 'owner@example.test']);
    }
    echo json_encode(['status' => $status], JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'publish-at') {
    $clock = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) ($argv[3] ?? ''), new DateTimeZone('UTC'));
    if (!$clock) throw new RuntimeException('A fixed fixture clock is required.');
    echo json_encode($store->publishDueMedia(100, $clock), JSON_THROW_ON_ERROR);
    exit;
}
if ($action !== 'read') throw new RuntimeException('Unknown Media Center fixture action.');
$gallery = $store->gallery($seed['gallery']['id']);
$playlist = $store->audioPlaylist($seed['playlist']['id']);
$result = ['mode' => $store->mode(), 'seed' => $seed, 'gallery' => $gallery, 'playlist' => $playlist,
    'assets' => array_map(static fn(array $asset): ?array => $store->mediaAsset($asset['id']), $seed['assets']),
    'unrelated' => $store->setting('media_acceptance_unrelated'), 'plan_ids' => $store->contentPlanIds('gallery', $gallery['id'])];
if ($store->mode() === 'mysql') {
    // A separate process and independent PDO read prove committed SQL, not a returned in-memory array.
    $db = (new App\Core\Database(new App\Core\Config($root . '/config/app.php')))->connection();
    $query = $db->prepare('SELECT asset_id,caption,sort_order,is_preview FROM photo_gallery_items WHERE gallery_id=? ORDER BY sort_order,id');
    $query->execute([$gallery['id']]); $result['sql']['items'] = $query->fetchAll(PDO::FETCH_ASSOC);
    $query = $db->prepare('SELECT track_id,sort_order FROM audio_playlist_items WHERE playlist_id=? ORDER BY sort_order');
    $query->execute([$playlist['id']]); $result['sql']['tracks'] = $query->fetchAll(PDO::FETCH_ASSOC);
    $query = $db->prepare('SELECT id,tags_json FROM media_assets WHERE id IN (?,?,?,?) ORDER BY id');
    $query->execute(array_column($seed['assets'], 'id')); $result['sql']['assets'] = $query->fetchAll(PDO::FETCH_ASSOC);
    $query = $db->prepare("SELECT plan_id FROM content_access_rules WHERE content_type='gallery' AND content_id=? AND rule_type='plan_include' ORDER BY plan_id");
    $query->execute([$gallery['id']]); $result['sql']['plan_ids'] = array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
    $result['mysql_version'] = $db->getAttribute(PDO::ATTR_SERVER_VERSION);
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

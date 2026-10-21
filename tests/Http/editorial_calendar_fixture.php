<?php
declare(strict_types=1);

// Private CLI fixture for an independently installed, marked loopback package.
// This file and its synthetic content never belong in a customer archive.
$root = realpath((string) ($argv[1] ?? ''));
$action = (string) ($argv[2] ?? '');
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
if (PHP_SAPI !== 'cli' || $root === false || !is_array($input)
    || !str_starts_with($root, rtrim(sys_get_temp_dir(), '/') . '/') || !is_file($root . '/.vvx-test-runtime')) {
    throw new RuntimeException('A marked disposable calendar runtime is required.');
}
$ownership = json_decode((string) file_get_contents($root . '/storage/temp/editorial-access-ownership.json'), true, 512, JSON_THROW_ON_ERROR);
$metadataPath = $root . '/storage/temp/editorial-metadata-records.json';
$manifestPath = $root . '/storage/temp/editorial-calendar-records.json';
if (($ownership['runtime'] ?? '') !== $root || !in_array($ownership['mode'] ?? '', ['file', 'mysql'], true)
    || parse_url((string) ($ownership['base'] ?? ''), PHP_URL_HOST) !== '127.0.0.1'
    || !is_file($metadataPath) || !is_file($root . '/storage/temp/editorial-history-records.json')) {
    throw new RuntimeException('The guarded editorial/history fixtures must own this exact runtime.');
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
require_once $root . '/app/Core/MarketingStore.php';
$store = new App\Core\Store($config);
$marketing = new Velvet\Core\MarketingStore($root);
$metadata = json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);

if ($action === 'seed') {
    if (is_file($manifestPath)) throw new RuntimeException('The calendar fixture is already seeded.');
    foreach ($marketing->all()['posts'] as $row) {
        if (str_starts_with((string) ($row['id'] ?? ''), 'calendar-')) throw new RuntimeException('Reserved calendar identities exist.');
    }
    $records = [];
    $dates = [
        'main' => ['2030-02-02T18:00:00Z', '2030-02-12T18:00:00Z'],
        'month-start' => ['2030-02-01T06:00:00Z', null],
        'month-end' => ['2030-03-01T05:59:59Z', null],
        'previous-edge' => ['2030-02-01T05:59:59Z', null],
        'next-edge' => ['2030-03-01T06:00:00Z', null],
        'fold-first' => ['2026-11-01T01:30:00-05:00', '2026-11-01T02:00:00-06:00'],
        'fold-second' => ['2026-11-01T01:30:00-06:00', '2026-11-01T02:30:00-06:00'],
        'active' => ['2020-01-01T00:00:00Z', '2099-01-01T00:00:00Z'],
        'expired' => ['2020-01-01T00:00:00Z', '2021-01-01T00:00:00Z'],
        'undated' => [null, null], 'invalid' => ['2030-02-08T18:00:00Z', ['invalid']],
        'invalid-active' => ['2020-01-01T00:00:00Z', ['invalid']],
        'legacy' => ['2020-01-01T00:00:00Z', null],
        'boundary' => ['2020-01-01T00:00:00Z', null],
        'browser' => ['2030-02-05T18:00:00Z', null],
        'browser-history' => ['2030-02-06T18:00:00Z', null],
    ];
    foreach ($dates as $name => [$start, $end]) {
        $row = $metadata['records']['main'];
        $row['id'] = $row['slug'] = 'calendar-post-' . $name;
        $row['title'] = 'Calendar post ' . $name . ($name === 'main' ? ' <img data-calendar-injection="title"> & café' : '');
        $row['body'] = 'CALENDAR_PRIVATE_BODY_' . $name;
        $row['excerpt'] = 'CALENDAR_PRIVATE_EXCERPT_' . $name;
        $row['seo_title'] = 'CALENDAR_PRIVATE_SEO_' . $name;
        $row['seo_description'] = 'CALENDAR_PRIVATE_DESCRIPTION_' . $name;
        $row['tags'] = ['CALENDAR_PRIVATE_TAG_' . $name];
        $row['author_name'] = 'CALENDAR_PRIVATE_AUTHOR_' . $name;
        $row['publish_at'] = $start;
        $row['status'] = $name === 'undated' || str_starts_with($name, 'browser') ? 'draft' : 'published';
        $row['related_post_ids'] = $name === 'active'
            ? ['calendar-post-expired', 'calendar-post-invalid-active', 'calendar-post-boundary', 'calendar-post-legacy'] : [];
        $row['calendar_unknown'] = ['preserved' => 'CALENDAR_PRIVATE_UNKNOWN_' . $name];
        if ($end !== null) $row['expires_at'] = $end;
        $records[$name] = $row;
    }
    $banner = ['id' => 'calendar-banner-main', 'text' => 'Calendar banner', 'url' => '/newsletter/',
        'enabled' => true, 'starts_at' => '2030-02-03T18:00:00Z', 'ends_at' => '2030-02-13T18:00:00Z',
        'access' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []]];
    $page = array_replace($metadata['page'], ['id' => 'calendar-page-main', 'slug' => 'calendar-page-main',
        'title' => 'Calendar landing page', 'body' => 'CALENDAR_PRIVATE_PAGE_BODY',
        'starts_at' => '2030-02-04T18:00:00Z', 'ends_at' => '2030-02-14T18:00:00Z']);
    $campaign = ['id' => 'calendar-campaign-main', 'subject' => 'Calendar email campaign',
        'body' => 'CALENDAR_PRIVATE_CAMPAIGN_BODY', 'preheader' => 'CALENDAR_PRIVATE_CAMPAIGN_PREHEADER',
        'status' => 'draft', 'scheduled_at' => '2030-02-07T18:00:00Z',
        'delivery_snapshot' => ['body' => 'CALENDAR_PRIVATE_FROZEN_DELIVERY'], 'calendar_unknown' => 'CALENDAR_PRIVATE_CAMPAIGN_UNKNOWN'];
    $marketing->update(static function (array &$data) use ($records, $banner, $page, $campaign): void {
        array_push($data['posts'], ...array_values($records));
        $data['banners'][] = $banner;
        $data['landing_pages'][] = $page;
        $data['campaigns'][] = $campaign;
    });
    $cover = (string) $metadata['assets']['a']['id'];
    $gallery = $store->saveGallery(['title' => 'Calendar gallery', 'slug' => 'calendar-gallery-main',
        'description' => 'CALENDAR_PRIVATE_GALLERY_DESCRIPTION', 'coverAssetId' => $cover, 'status' => 'scheduled',
        'accessMode' => 'public', 'scheduledAt' => '2030-02-15T12:00:00', 'items' => [['assetId' => $cover, 'caption' => 'Calendar fixture image']]]);
    $dueGallery = $store->saveGallery(['title' => 'Calendar due gallery', 'slug' => 'calendar-gallery-due',
        'coverAssetId' => $cover, 'status' => 'scheduled', 'accessMode' => 'public',
        'scheduledAt' => '2020-01-01T12:00:00', 'items' => [['assetId' => $cover, 'caption' => 'Calendar due fixture image']]]);
    $video = $store->saveVideo(['title' => 'Calendar video', 'slug' => 'calendar-video-main',
        'description' => 'CALENDAR_PRIVATE_VIDEO_DESCRIPTION', 'posterAssetId' => $cover,
        'status' => 'scheduled', 'accessMode' => 'public', 'scheduledAt' => '2030-02-16 12:00:00']);
    $album = $store->saveAlbum(['title' => 'Calendar album', 'slug' => 'calendar-album-main',
        'description' => 'CALENDAR_PRIVATE_ALBUM_DESCRIPTION', 'coverAssetId' => $cover,
        'status' => 'scheduled', 'scheduledAt' => '2030-02-17 12:00:00']);
    $file = 'uploads/audio/editorial-calendar-silence.wav';
    if (!is_dir($root . '/uploads/audio')) mkdir($root . '/uploads/audio', 0750, true);
    $pcm = str_repeat("\0", 1600);
    file_put_contents($root . '/' . $file, 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt '
        . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm);
    $audio = $store->addMedia(['title' => 'Calendar synthetic silence', 'type' => 'audio', 'file' => $file,
        'mime' => 'audio/wav', 'duration' => 0.1, 'private' => false]);
    $track = $store->saveTrack(['title' => 'Calendar track', 'slug' => 'calendar-track-main',
        'description' => 'CALENDAR_PRIVATE_TRACK_DESCRIPTION', 'lyrics' => 'CALENDAR_PRIVATE_LYRICS',
        'albumId' => (string) $album['id'], 'audioAssetId' => (string) $audio['id'], 'coverAssetId' => $cover,
        'status' => 'scheduled', 'accessMode' => 'public', 'scheduledAt' => '2030-02-18 12:00:00']);
    $playlist = $store->saveAudioPlaylist(['title' => 'Calendar playlist', 'slug' => 'calendar-playlist-main',
        'description' => 'CALENDAR_PRIVATE_PLAYLIST_DESCRIPTION', 'coverAssetId' => $cover,
        'status' => 'scheduled', 'accessMode' => 'public', 'scheduledAt' => '2030-02-19T12:00:00',
        'trackIds' => [(string) $track['id']]]);
    $sceneIds = [];
    foreach (['hero', 'footer'] as $section) {
        $scene = $store->scene($section);
        $scene['calendar_private_unknown'] = 'CALENDAR_PRIVATE_SCENE_BODY';
        $sceneIds[$section] = $store->saveScene($section, $scene, true);
        if ($pdo !== null) {
            // Scene writers use SQL NOW(), so preserve its connection-timezone
            // convention while fixing the same absolute instant as file mode.
            $published = (new DateTimeImmutable('2030-02-20T12:00:00-06:00'))->getTimestamp();
            $pdo->prepare('UPDATE scenes SET created_at=FROM_UNIXTIME(?),updated_at=FROM_UNIXTIME(?) WHERE id=? AND section_key=? AND status=?')
                ->execute([$published, $published, $sceneIds[$section], $section, 'published']);
            $pdo->prepare('UPDATE scene_versions SET created_at=FROM_UNIXTIME(?) WHERE scene_id=? AND section_key=?')
                ->execute([$published, $sceneIds[$section], $section]);
        } else {
            $store->mutateFileStore(static function (array &$data) use ($section): void {
                $last = array_key_last($data['scenes'][$section]['versions']);
                $data['scenes'][$section]['versions'][$last]['publishedAt'] = '2030-02-20T12:00:00-06:00';
            });
        }
    }
    $manifest = ['records' => $records, 'banner' => $banner, 'page' => $page, 'campaign' => $campaign,
        'media' => compact('gallery', 'dueGallery', 'video', 'album', 'track', 'playlist'), 'scenes' => $sceneIds,
        'month' => '2030-02', 'timezone' => 'America/Chicago'];
    file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    chmod($manifestPath, 0600);
    echo json_encode($manifest, JSON_THROW_ON_ERROR);
    exit;
}
if (!is_file($manifestPath)) throw new RuntimeException('Seed the owned calendar fixture first.');
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
if ($action === 'stale-expiry-session') {
    // Exercise current-owner authorization with the obsolete session's own
    // valid CSRF token, rather than stopping at a token mismatch first.
    session_name('velvet_vixxen_admin');
    session_save_path($root . '/storage/sessions');
    session_id(bin2hex(random_bytes(24)));
    session_start();
    $_SESSION = ['admin' => ['id' => 'obsolete-editorial-owner', 'email' => 'owner@example.test',
        'name' => 'Stale Calendar Fixture Owner', 'role' => 'owner'], '_csrf' => bin2hex(random_bytes(32)),
        'is_owner' => true, 'preview' => true];
    $session = ['name' => session_name(), 'id' => session_id(), 'csrf' => $_SESSION['_csrf']];
    session_write_close();
    echo json_encode($session, JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'boundary') {
    // Reserve a short real-clock expiry, then observe the actual public route.
    $expires = time() + 4;
    $marketing->update(static function (array &$data) use ($expires): void {
        foreach ($data['posts'] as &$row) {
            if (($row['id'] ?? null) === 'calendar-post-boundary') $row['expires_at'] = gmdate('c', $expires);
        }
    });
    echo json_encode(['expires_epoch' => $expires, 'expires_at' => gmdate('c', $expires)], JSON_THROW_ON_ERROR);
    exit;
}
if ($action !== 'read') throw new RuntimeException('Unknown calendar fixture action.');
$result = ['manifest' => $manifest, 'media' => ['albums' => $store->albums(), 'tracks' => $store->tracks(),
    'playlists' => $store->audioPlaylists(), 'galleries' => $store->galleries(), 'videos' => $store->videos()],
    'scenes' => [], 'raw_sql' => null];
foreach (['hero', 'footer'] as $section) {
    $result['scenes'][$section] = ['draft' => $store->scene($section), 'published' => $store->scene($section, true),
        'history' => $store->sceneVersions($section)];
}
if ($pdo !== null) {
    $result['raw_sql'] = [];
    foreach (['audio_albums', 'audio_tracks', 'audio_playlists', 'audio_playlist_items', 'photo_galleries', 'photo_gallery_items', 'videos', 'scenes', 'scene_versions'] as $table) {
        $order = $table === 'audio_playlist_items' ? 'playlist_id,track_id' : 'id';
        $result['raw_sql'][$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY ' . $order)->fetchAll(PDO::FETCH_ASSOC);
    }
}
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

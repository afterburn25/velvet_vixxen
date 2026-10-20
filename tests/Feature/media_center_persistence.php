<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\EntitlementResolver;
use App\Core\MediaAdminService;
use App\Core\Store;

/** Reserved records shared by file, real-MySQL and HTTP acceptance. */
function vvx_media_test_seed(Store $store, string $prefix): array
{
    $assets = [];
    foreach (['a', 'b', 'c', 'cover'] as $key) {
        $assets[$key] = $store->addMedia(['title' => 'Media fixture ' . $key, 'type' => 'gallery',
            'file' => 'private:images/media-acceptance/' . $key . '.png',
            'thumbnail' => 'thumbnails/media-acceptance/' . $key . '.png', 'mime' => 'image/png',
            'width' => 2, 'height' => 2, 'private' => true, 'tags' => ['original-' . $key]]);
    }
    $audio = $store->addMedia(['title' => 'Fixture audio', 'type' => 'audio',
        'file' => 'private:audio/media-acceptance/tone.wav', 'mime' => 'audio/wav', 'private' => true]);
    $tracks = [];
    foreach (['first', 'second', 'draft'] as $key) {
        $tracks[$key] = $store->saveTrack(['title' => $prefix . ' track ' . $key,
            'slug' => $prefix . '-track-' . $key, 'artist' => 'Acceptance fixture',
            'audioAssetId' => $audio['id'], 'coverAssetId' => $assets['cover']['id'],
            'status' => $key === 'draft' ? 'draft' : 'published',
            'accessMode' => $key === 'second' ? 'subscription' : 'public', 'previewSeconds' => 0]);
    }
    $gallery = $store->saveGallery(['title' => $prefix . ' gallery', 'slug' => $prefix . '-gallery',
        'status' => 'published', 'accessMode' => 'ppv', 'standardPrice' => 9.50, 'currency' => 'USD',
        'coverAssetId' => $assets['b']['id'], 'previewCount' => 1, 'items' => [
            ['assetId' => $assets['a']['id'], 'caption' => 'Original A', 'sortOrder' => 10, 'isPreview' => true],
            ['assetId' => $assets['b']['id'], 'caption' => 'Original B', 'sortOrder' => 20, 'isPreview' => false],
            ['assetId' => $assets['c']['id'], 'caption' => 'Original C', 'sortOrder' => 30, 'isPreview' => false],
        ]]);
    $playlist = $store->saveAudioPlaylist(['title' => $prefix . ' playlist', 'slug' => $prefix . '-playlist',
        'status' => 'draft', 'accessMode' => 'registered', 'coverAssetId' => $assets['cover']['id'],
        'trackIds' => [$tracks['first']['id'], $tracks['second']['id']]]);
    $plan = $store->saveMembershipPlan(['name' => 'Restricted fixture plan', 'key' => $prefix . '-restricted', 'price' => 5, 'isActive' => true]);
    $store->setContentPlanRules('gallery', $gallery['id'], [$plan['id']]);
    return ['assets' => $assets, 'audio' => $audio, 'tracks' => $tracks, 'gallery' => $gallery, 'playlist' => $playlist, 'plan' => $plan];
}

function vvx_media_gallery_post(array $gallery, array $changes = []): array
{
    return array_replace(['id' => $gallery['id'], 'title' => $gallery['title'], 'slug' => $gallery['slug'],
        'description' => $gallery['description'] ?? '', 'cover_asset_id' => $gallery['coverAssetId'] ?? '',
        'status' => $gallery['status'], 'access_mode' => $gallery['accessMode'],
        'standard_price' => $gallery['standardPrice'] ?? '', 'currency' => $gallery['currency'] ?? 'USD',
        'preview_count' => $gallery['previewCount'] ?? 0, 'media_revision' => $gallery['revision'] ?? '',
        'asset_order' => json_encode(array_column($gallery['items'], 'assetId'), JSON_THROW_ON_ERROR)], $changes);
}

function vvx_media_playlist_post(array $playlist, array $changes = []): array
{
    return array_replace(['id' => $playlist['id'], 'title' => $playlist['title'], 'slug' => $playlist['slug'],
        'description' => $playlist['description'] ?? '', 'cover_asset_id' => $playlist['coverAssetId'] ?? '',
        'status' => $playlist['status'], 'access_mode' => $playlist['accessMode'],
        'track_ids' => $playlist['trackIds'], 'media_revision' => $playlist['revision'] ?? ''], $changes);
}

/** Execute identical persistence requirements against real file storage or MySQL. */
function vvx_media_acceptance(Config $config, ?PDO $database = null): int
{
    $checks = 0;
    $assert = static function (bool $okay, string $message) use (&$checks): void {
        $checks++;
        if (!$okay) throw new RuntimeException($message);
    };
    $reject = static function (callable $operation, string $message) use ($assert): void {
        $error = null;
        try { $operation(); } catch (RuntimeException|InvalidArgumentException $caught) { $error = $caught; }
        $assert($error !== null, $message);
    };
    $store = new Store($config);
    $seed = vvx_media_test_seed($store, 'acceptance-' . bin2hex(random_bytes(4)));
    $assets = array_map(static fn(array $asset): string => (string) $asset['id'], $seed['assets']);
    $id = (string) $seed['gallery']['id'];
    $playlistId = (string) $seed['playlist']['id'];
    $planIds = [(string) $seed['plan']['id']];
    $reloadGallery = static fn(): array => (new Store(new Config($config->path())))->gallery($id) ?? [];
    $reloadPlaylist = static fn(): array => (new Store(new Config($config->path())))->audioPlaylist($playlistId) ?? [];
    $snapshot = static function () use ($config, $reloadGallery, $reloadPlaylist, $assets): array {
        $fresh = new Store(new Config($config->path()));
        return [$reloadGallery(), $reloadPlaylist(), array_map(static fn(string $assetId): ?array => $fresh->mediaAsset($assetId), $assets),
            $fresh->contentPlanIds('gallery', $reloadGallery()['id'])];
    };
    $persistedItems = static function () use ($database, $id, $reloadGallery): array {
        if ($database === null) return array_map(static fn(array $item): array => [
            'asset_id' => (string) $item['assetId'], 'caption' => $item['caption'],
            'sort_order' => (int) $item['sortOrder'], 'is_preview' => (int) $item['isPreview'],
        ], $reloadGallery()['items']);
        // This PDO is independent of Store's connection and reads committed rows.
        $query = $database->prepare('SELECT asset_id,caption,sort_order,is_preview FROM photo_gallery_items WHERE gallery_id=? ORDER BY sort_order,id');
        $query->execute([$id]);
        return array_map(static fn(array $row): array => ['asset_id' => (string) $row['asset_id'],
            'caption' => $row['caption'], 'sort_order' => (int) $row['sort_order'], 'is_preview' => (int) $row['is_preview']], $query->fetchAll(PDO::FETCH_ASSOC));
    };
    $admin = new MediaAdminService($store);
    $assert((new Store($config))->contentPlanIds('gallery', $id) === $planIds, 'A nonempty membership-plan restriction did not persist.');
    if ($database !== null) {
        $query = $database->prepare("SELECT plan_id FROM content_access_rules WHERE content_type='gallery' AND content_id=? AND rule_type='plan_include' ORDER BY plan_id");
        $query->execute([$id]);
        $assert(array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN)) === $planIds, 'The actual MySQL plan rule was not inserted.');
    }
    $store->setContentPlanRules('gallery', $id, []);
    $assert((new Store($config))->contentPlanIds('gallery', $id) === [], 'Explicit empty plan rules did not clear.');
    if ($database !== null) {
        $query->execute([$id]);
        $assert($query->fetchAll(PDO::FETCH_COLUMN) === [], 'The actual MySQL empty plan-rule update left rows behind.');
    }
    $store->setContentPlanRules('gallery', $id, $planIds);
    $initial = $reloadGallery();
    $assert(is_string($initial['revision'] ?? null) && $initial['revision'] !== '', 'Gallery reload lacks a usable edit revision.');
    $caption = "Caption <em>stays text</em> & \"quoted\"\nSecond line — café";
    $admin->saveGallery(vvx_media_gallery_post($initial, [
        'asset_order' => json_encode([$assets['c'], $assets['a']], JSON_THROW_ON_ERROR),
        'asset_captions' => [$assets['c'] => $caption], 'asset_tags' => [$assets['c'] => 'studio, sunset'],
        'preview_count' => 1,
    ]), []);
    $saved = $reloadGallery();
    $assert(array_column($saved['items'], 'assetId') === [$assets['c'], $assets['a']], 'Gallery removal/reordering did not persist exactly after a fresh read.');
    $assert($persistedItems() === [
        ['asset_id' => $assets['c'], 'caption' => $caption, 'sort_order' => 10, 'is_preview' => 1],
        ['asset_id' => $assets['a'], 'caption' => 'Original A', 'sort_order' => 20, 'is_preview' => 0],
    ], 'Committed gallery item IDs, captions, order or preview flags differ from the submitted contract.');
    $assert((string) $saved['coverAssetId'] === $assets['c'], 'Removing the cover item did not select the first retained photo.');
    $assert($saved['items'][0]['asset']['tags'] === ['studio', 'sunset'], 'Reloaded gallery did not hydrate updated asset tags.');
    $assert((new Store($config))->mediaAsset($assets['b'])['tags'] === ['original-b'], 'Removing a gallery item altered its independent asset tags.');
    $assert($saved['revision'] !== $initial['revision'], 'A persisted gallery edit did not advance the revision.');
    $assert((new Store($config))->contentPlanIds('gallery', $id) === $planIds, 'Gallery caption/order edits changed membership-plan restrictions.');
    if ($database !== null) {
        $query = $database->prepare('SELECT cover_asset_id,preview_count FROM photo_galleries WHERE id=?');
        $query->execute([$id]); $row = $query->fetch(PDO::FETCH_ASSOC);
        $assert((string) $row['cover_asset_id'] === $assets['c'] && (int) $row['preview_count'] === 1, 'MySQL committed cover/preview metadata disagrees with the gallery.');
        $query = $database->prepare('SELECT tags_json FROM media_assets WHERE id=?');
        $query->execute([$assets['c']]);
        $assert(json_decode((string) $query->fetchColumn(), true, 512, JSON_THROW_ON_ERROR) === ['studio', 'sunset'], 'Gallery tag edits were not committed to media_assets.tags_json.');
    }

    // Omitted metadata/membership preserves the saved data for older clients.
    $post = vvx_media_gallery_post($saved, ['title' => 'Title-only edit']); unset($post['asset_order']);
    $admin->saveGallery($post, []);
    $retained = $reloadGallery();
    $assert(array_column($retained['items'], 'assetId') === [$assets['c'], $assets['a']], 'A subsequent edit reappended the removed photo.');
    $assert($retained['items'][0]['caption'] === $caption && $retained['items'][0]['asset']['tags'] === ['studio', 'sunset'], 'Omitted captions/tags were cleared on a normal edit.');
    $assert($retained['items'][1]['asset']['tags'] === ['original-a'], 'Unedited photo tags were lost.');

    // Each negative control must preserve both gallery data and shared asset tags.
    $badEdits = [
        ['asset_order' => 'not-json'],
        ['asset_order' => json_encode([$assets['a'], $assets['a']])],
        ['asset_order' => json_encode([$assets['a'], '999999999'])],
        ['asset_order' => json_encode([$assets['a'], $assets['b']])],
        ['asset_order' => json_encode([[$assets['a']]])],
        ['asset_order' => '[]'],
        ['asset_captions' => [$assets['b'] => 'Omitted item must not be edited']],
        ['asset_captions' => [$assets['c'] => str_repeat('x', 2001)]],
        ['asset_captions' => [$assets['c'] => ['nested']]],
        ['asset_tags' => [$assets['b'] => 'foreign item']],
        ['asset_tags' => [$assets['c'] => implode(',', array_map(static fn(int $n): string => 'tag-' . $n, range(1, 31)))]],
        ['asset_tags' => [$assets['c'] => str_repeat('x', 65)]],
        ['cover_asset_id' => $assets['cover']],
        ['media_revision' => $initial['revision']],
        ['media_revision' => ''],
    ];
    foreach ($badEdits as $index => $changes) {
        $before = $snapshot();
        $reject(static fn() => $admin->saveGallery(vvx_media_gallery_post($reloadGallery(), $changes), []), 'Invalid gallery edit was accepted (case ' . $index . ').');
        $assert($snapshot() === $before, 'Rejected gallery edit changed committed records or tags (case ' . $index . ').');
    }
    $admin->saveGallery(vvx_media_gallery_post($reloadGallery(), [
        'asset_captions' => [$assets['c'] => ''], 'asset_tags' => [$assets['c'] => ''],
    ]), []);
    $cleared = $reloadGallery();
    $assert($cleared['items'][0]['caption'] === '' && $cleared['items'][0]['asset']['tags'] === [], 'Explicit blank caption/tags did not clear.');
    $assert($cleared['items'][1]['caption'] === 'Original A' && $cleared['items'][1]['asset']['tags'] === ['original-a'], 'Clearing one photo changed its sibling.');

    // A separate custom cover must survive membership edits.
    $custom = $store->saveGallery(['title' => 'Separate custom cover', 'slug' => 'custom-cover-' . bin2hex(random_bytes(4)),
        'coverAssetId' => $assets['cover'], 'status' => 'draft', 'accessMode' => 'public', 'items' => [
            ['assetId' => $assets['a'], 'caption' => 'First'], ['assetId' => $assets['c'], 'caption' => 'Second']]]);
    $admin->saveGallery(vvx_media_gallery_post($custom, ['asset_order' => json_encode([$assets['c']])]), []);
    $assert((string) (new Store($config))->gallery($custom['id'])['coverAssetId'] === $assets['cover'], 'Removing a gallery photo discarded a separate custom cover.');

    $playlist = $reloadPlaylist();
    $trackIds = [(string) $seed['tracks']['second']['id'], (string) $seed['tracks']['first']['id']];
    $assert(is_string($playlist['revision'] ?? null) && $playlist['revision'] !== '', 'Playlist reload lacks a usable revision.');
    $schedule = '2031-06-15 12:00:00';
    $admin->saveAudioPlaylist(vvx_media_playlist_post($playlist, ['track_ids' => $trackIds,
        'status' => 'scheduled', 'scheduled_at' => '2031-06-15T12:00', 'published_at' => '']));
    $playlist = $reloadPlaylist();
    $assert($playlist['trackIds'] === $trackIds && $playlist['scheduledAt'] === $schedule && $playlist['publishedAt'] === null, 'Playlist order or explicit scheduling dates did not persist.');
    $admin->saveAudioPlaylist(vvx_media_playlist_post($playlist, ['title' => 'Retain playlist dates']));
    $assert($reloadPlaylist()['scheduledAt'] === $schedule, 'A playlist edit without date fields cleared its schedule.');
    if ($database !== null) {
        $query = $database->prepare('SELECT track_id,sort_order FROM audio_playlist_items WHERE playlist_id=? ORDER BY sort_order');
        $query->execute([$playlistId]); $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $assert(array_map('strval', array_column($rows, 'track_id')) === $trackIds && array_map('intval', array_column($rows, 'sort_order')) === [10, 20], 'MySQL playlist IDs/order did not commit exactly.');
        $query = $database->prepare('SELECT scheduled_at,published_at,status FROM audio_playlists WHERE id=?');
        $query->execute([$playlistId]); $row = $query->fetch(PDO::FETCH_ASSOC);
        $assert($row['scheduled_at'] === $schedule && $row['published_at'] === null && $row['status'] === 'scheduled', 'MySQL playlist status/date columns disagree with the saved form.');
    }
    foreach ([['scheduled_at' => ''], ['scheduled_at' => '2031-02-30T12:00'], ['scheduled_at' => 'tomorrow'],
        ['scheduled_at' => '2031-06-15T12:00Z'], ['scheduled_at' => ['nested']], ['published_at' => 'not-a-date'],
        ['track_ids' => [$trackIds[0], '999999999']], ['track_ids' => [$trackIds[0], $trackIds[0]]],
        ['media_revision' => $seed['playlist']['revision']], ['media_revision' => '']] as $index => $changes) {
        $before = $snapshot();
        $reject(static fn() => $admin->saveAudioPlaylist(vvx_media_playlist_post($reloadPlaylist(), $changes)), 'Invalid/stale playlist edit was accepted (case ' . $index . ').');
        $assert($snapshot() === $before, 'Rejected playlist edit changed committed state (case ' . $index . ').');
    }

    // A gallery and playlist become visible only after an explicit due run.
    $admin->saveGallery(vvx_media_gallery_post($reloadGallery(), ['status' => 'scheduled',
        'scheduled_at' => '2031-06-15T12:00', 'published_at' => '']), []);
    $oldPlaylist = $reloadPlaylist();
    $future = $store->saveAudioPlaylist(['title' => 'Future publication guard', 'slug' => 'future-' . bin2hex(random_bytes(4)),
        'trackIds' => $trackIds, 'status' => 'scheduled', 'accessMode' => 'subscription',
        'scheduledAt' => $schedule, 'publishedAt' => '2031-06-16 12:00:00']);
    $clock = new DateTimeImmutable($schedule, new DateTimeZone('UTC'));
    $assert((new Store($config))->publishDueMedia(100, $clock->modify('-1 second'))['published'] === 0, 'Scheduler published before the due time.');
    $assert(!in_array($id, array_column((new Store($config))->galleries(true), 'id'), true)
        && !in_array($playlistId, array_column((new Store($config))->audioPlaylists(true), 'id'), true), 'Scheduled records leaked into published listings before a due run.');
    $store->setSetting('media_acceptance_unrelated', ['preserve' => true]);
    $first = (new Store($config))->publishDueMedia(1, $clock);
    $assert($first['published'] === 1, 'Scheduler did not honor its total batch limit.');
    $second = (new Store($config))->publishDueMedia(100, $clock);
    $assert($second['published'] === 1 && $first['galleries'] + $second['galleries'] === 1
        && $first['playlists'] + $second['playlists'] === 1, 'Due gallery/playlist publication counts are incorrect.');
    $assert((new Store($config))->publishDueMedia(100, $clock)['published'] === 0, 'Repeated due run published the same item again.');
    $publishedGallery = $reloadGallery(); $publishedPlaylist = $reloadPlaylist();
    $assert($publishedGallery['status'] === 'published' && $publishedPlaylist['status'] === 'published', 'Due publication did not survive a fresh Store read.');
    $assert($publishedGallery['scheduledAt'] === $schedule && $publishedPlaylist['scheduledAt'] === $schedule
        && $publishedGallery['publishedAt'] === $schedule && $publishedPlaylist['publishedAt'] === $schedule, 'Publication lost the schedule or chose a different release date.');
    $assert($publishedPlaylist['trackIds'] === $trackIds && array_column($publishedGallery['items'], 'assetId') === [$assets['c'], $assets['a']], 'Scheduler changed media membership or order.');
    $assert((new Store($config))->audioPlaylist($future['id'])['status'] === 'scheduled', 'A future published_at bypassed the due guard.');
    $assert((new Store($config))->setting('media_acceptance_unrelated') === ['preserve' => true], 'Due publication lost unrelated saved settings.');
    $assert((new Store($config))->contentPlanIds('gallery', $id) === $planIds, 'Due publication changed gallery membership-plan restrictions.');
    $access = new EntitlementResolver(new Store($config));
    $assert(!$access->resolve('gallery', $publishedGallery, null, true)['allowed']
        && !$access->resolve('playlist', $publishedPlaylist, null, true)['allowed'], 'Due publication bypassed paid/registered access.');
    $before = $snapshot();
    $reject(static fn() => $admin->saveAudioPlaylist(vvx_media_playlist_post($oldPlaylist, ['status' => 'draft'])), 'An old owner form overwrote scheduler publication.');
    $assert($snapshot() === $before, 'Rejected pre-publication revision changed published data.');
    $admin->saveAudioPlaylist(vvx_media_playlist_post($publishedPlaylist, ['status' => 'draft', 'scheduled_at' => '', 'published_at' => '']));
    $assert($reloadPlaylist()['scheduledAt'] === null && $reloadPlaylist()['publishedAt'] === null, 'Explicit date clearing on a draft did not persist.');

    // The same bounded runner must continue to publish the existing album,
    // track and video types, retaining dates, private references and access.
    $priorDate = '2031-06-15 11:59:00';
    $dueBase = ['status' => 'scheduled', 'scheduledAt' => $schedule, 'publishedAt' => $priorDate];
    $dueAlbum = (new Store($config))->saveAlbum($dueBase + ['title' => 'Due album fixture',
        'slug' => 'due-album-' . bin2hex(random_bytes(4)), 'coverAssetId' => $assets['cover']]);
    $dueTrack = (new Store($config))->saveTrack($dueBase + ['title' => 'Due track fixture',
        'slug' => 'due-track-' . bin2hex(random_bytes(4)), 'audioAssetId' => $seed['audio']['id'],
        'coverAssetId' => $assets['cover'], 'accessMode' => 'subscription', 'previewSeconds' => 0]);
    $videoAsset = (new Store($config))->addMedia(['title' => 'Private video fixture', 'type' => 'video',
        'file' => 'private:videos/media-acceptance/clip.mp4', 'mime' => 'video/mp4', 'private' => true]);
    $dueVideo = (new Store($config))->saveVideo($dueBase + ['title' => 'Due video fixture',
        'slug' => 'due-video-' . bin2hex(random_bytes(4)), 'fullAssetId' => $videoAsset['id'],
        'posterAssetId' => $assets['cover'], 'accessMode' => 'ppv', 'standardPrice' => 12]);
    $held = [];
    foreach (['draft', 'unlisted', 'archived'] as $status) {
        $held[$status] = (new Store($config))->saveGallery(['title' => 'Held ' . $status, 'status' => $status,
            'slug' => 'held-' . $status . '-' . bin2hex(random_bytes(4)), 'accessMode' => 'public',
            'scheduledAt' => '2001-01-01 00:00:00', 'items' => [['assetId' => $assets['c'], 'caption' => 'Held']]]);
    }
    $assert((new Store($config))->publishDueMedia(100, $clock->modify('-1 second'))['published'] === 0,
        'An existing media type published early, or a draft/unlisted/archived record was promoted.');
    $assert((new Store($config))->publishDueMedia(100, $clock) === ['published' => 3, 'albums' => 1, 'tracks' => 1, 'playlists' => 0, 'galleries' => 0, 'videos' => 1],
        'Due album/track/video counts are not exact.');
    foreach (['album' => $dueAlbum, 'track' => $dueTrack, 'video' => $dueVideo] as $type => $record) {
        $freshRecord = (new Store($config))->{$type}($record['id']);
        $assert($freshRecord['status'] === 'published' && $freshRecord['scheduledAt'] === $schedule && $freshRecord['publishedAt'] === $priorDate,
            'Due ' . $type . ' lost its saved publication date or schedule.');
        if ($type !== 'album') $assert(!$access->resolve($type, $freshRecord, null, true)['allowed'], 'Due ' . $type . ' bypassed protected access.');
        if ($database !== null) {
            $table = ['album' => 'audio_albums', 'track' => 'audio_tracks', 'video' => 'videos'][$type];
            $query = $database->prepare('SELECT status,scheduled_at,published_at FROM ' . $table . ' WHERE id=?');
            $query->execute([$record['id']]);
            $assert($query->fetch(PDO::FETCH_ASSOC) === ['status' => 'published', 'scheduled_at' => $schedule, 'published_at' => $priorDate],
                'Separate MySQL read did not find the exact ' . $type . ' publication columns.');
        }
    }
    $assert((string) (new Store($config))->track($dueTrack['id'])['audioAssetId'] === (string) $seed['audio']['id']
        && (string) (new Store($config))->video($dueVideo['id'])['fullAssetId'] === (string) $videoAsset['id'],
        'Scheduled publication changed protected asset references.');
    foreach ($held as $status => $record) $assert((new Store($config))->gallery($record['id'])['status'] === $status,
        'Scheduler changed an explicitly ' . $status . ' record.');
    $assert((new Store($config))->publishDueMedia(100, $clock)['published'] === 0, 'Repeated existing-media publication is not idempotent.');
    if ($database === null) {
        (new Store($config))->mutateFileStore(static function (array &$data) use ($future): void {
            $data['mediaCenter']['audioPlaylists'][$future['id']]['scheduledAt'] = '2031-02-30 12:00:00';
            $data['mediaCenter']['audioPlaylists'][$future['id']]['publishedAt'] = null;
        });
        $assert((new Store($config))->publishDueMedia(100, $clock)['published'] === 0
            && (new Store($config))->audioPlaylist($future['id'])['status'] === 'scheduled',
            'A malformed legacy date became permission to publish.');
    }
    foreach ([0, -1, 501] as $limit) $reject(static fn() => $store->publishDueMedia($limit, $clock), 'Scheduler accepted an unsafe batch limit.');
    return $checks;
}

/** Reproduce stale legacy writes and real shared-lock refusal, without mocks. */
function vvx_media_file_concurrency(Config $config): int
{
    $checks = 0;
    $assert = static function (bool $okay, string $message) use (&$checks): void {
        $checks++;
        if (!$okay) throw new RuntimeException($message);
    };
    $reject = static function (callable $operation, string $message) use ($assert): void {
        $failed = false;
        try { $operation(); } catch (RuntimeException $error) { $failed = true; }
        $assert($failed, $message);
    };
    $path = root_path((string) $config->get('file_store_path'));
    $first = new Store($config);
    $captured = $first->rawFileData();
    (new Store($config))->setSetting('concurrent_writer', 'must survive');
    $first->setting('concurrent_writer'); // A later nested read must not bless $captured.
    $captured['settings']['stale_writer'] = 'must not appear';
    $writer = new ReflectionMethod(Store::class, 'writeFileStore');
    $reject(static fn() => $writer->invoke($first, $captured), 'A nested read allowed an old array to overwrite a concurrent change.');
    $assert((new Store($config))->setting('concurrent_writer') === 'must survive'
        && (new Store($config))->setting('stale_writer') === null, 'Rejected stale write lost another writer or leaked its own data.');

    $one = new Store($config); $two = new Store($config);
    $one->rawFileData(); $two->rawFileData();
    $gallery = $one->galleries(false)[0];
    $playlist = array_values(array_filter($two->audioPlaylists(false), static fn(array $record): bool => $record['status'] === 'draft'))[0];
    (new MediaAdminService($one))->saveGallery(vvx_media_gallery_post($gallery, ['title' => 'Concurrent gallery edit']), []);
    (new MediaAdminService($two))->saveAudioPlaylist(vvx_media_playlist_post($playlist, ['title' => 'Concurrent playlist edit']));
    $fresh = new Store($config);
    $assert($fresh->gallery($gallery['id'])['title'] === 'Concurrent gallery edit' && $fresh->audioPlaylist($playlist['id'])['title'] === 'Concurrent playlist edit'
        && $fresh->setting('concurrent_writer') === 'must survive', 'Fresh media mutations did not merge disjoint gallery/playlist changes and unrelated data.');
    $before = (string) file_get_contents($path);
    $lock = fopen($path . '.lock', 'c+b');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Unable to hold the real application sidecar lock.');
    try {
        $reject(static fn() => (new Store($config))->mutateFileStore(static function (array &$data): void { $data['settings']['locked_write'] = true; }),
            'A mutation succeeded while another writer held the common lock.');
        $reject(static fn() => (new Store($config))->setSetting('locked_legacy_write', true), 'A legacy writer bypassed the common lock.');
        $assert(file_get_contents($path) === $before, 'Refused locked writes changed the file.');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    $nested = new Store($config);
    $reject(static fn() => $nested->mutateFileStore(static function (array &$data) use ($nested): void {
        $data['settings']['outer_uncommitted'] = true;
        $nested->setSetting('nested_uncommitted', true);
    }), 'A nested legacy writer was accepted inside a mutation.');
    $assert(file_get_contents($path) === $before, 'A failed nested mutation partially committed data.');
    $reject(static fn() => $nested->mutateFileStore(static function (array &$data) use ($nested): void {
        $nested->mutateFileStore(static function (array &$inner): void { $inner['settings']['nested_uncommitted'] = true; });
    }), 'A nested mutation was accepted.');
    $assert(file_get_contents($path) === $before, 'A rejected nested mutation changed storage.');

    // Use this disposable file only, then restore its bytes for cleanup.
    try {
        file_put_contents($path, '{broken JSON');
        $reject(static fn() => (new Store($config))->setSetting('reset', true), 'A corrupt file was silently reset.');
        $reject(static fn() => (new Store($config))->mutateFileStore(static function (array &$data): void { $data = ['settings' => []]; }),
            'A corrupt file was accepted by a fresh mutation.');
        $assert(file_get_contents($path) === '{broken JSON', 'A rejected corrupt-file operation changed the original bytes.');
        unlink($path);
        $target = $path . '.untouched'; file_put_contents($target, $before);
        if (!symlink($target, $path)) throw new RuntimeException('Unable to create the disposable symlink negative control.');
        $reject(static fn() => (new Store($config))->mutateFileStore(static function (array &$data): void { $data['settings']['symlink_write'] = true; }),
            'A symlink data file accepted a mutation.');
        $assert(is_link($path) && file_get_contents($target) === $before, 'A refused symlink write changed its target.');
    } finally {
        if (is_link($path)) unlink($path);
        file_put_contents($path, $before);
        if (is_file($path . '.untouched')) unlink($path . '.untouched');
    }
    $assert((glob($path . '.tmp.*') ?: []) === [], 'File-store mutations left unfinished activation files.');
    return $checks;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $source = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir() . '/vvx-media-state-' . bin2hex(random_bytes(6));
    mkdir($temporary . '/storage', 0755, true);
    define('ROOT_PATH', $temporary);
    spl_autoload_register(static function (string $class) use ($source): void {
        if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    });
    require_once $source . '/app/Support/functions.php';
    date_default_timezone_set('UTC');
    try {
        $config = new Config($temporary . '/config/app.php');
        $config->write(['storage_mode' => 'file', 'file_store_path' => 'storage/data.json', 'timezone' => 'UTC',
            'base_url' => 'http://127.0.0.1:8765', 'mail' => ['mode' => 'disabled'],
            'security' => ['app_key' => bin2hex(random_bytes(32))]]);
        file_put_contents($temporary . '/storage/data.json', json_encode(['settings' => [], 'admins' => [],
            'media' => [], 'mediaCenter' => ['albums' => [], 'tracks' => [], 'audioPlaylists' => [], 'galleries' => [], 'videos' => []], 'audit' => []], JSON_THROW_ON_ERROR));
        $checks = vvx_media_acceptance($config) + vvx_media_file_concurrency($config);
        echo "Media Center file persistence passed ({$checks} assertions; exact membership/captions/tags, stale edits and due publication).\n";
    } finally {
        $remove = static function (string $path) use (&$remove): void {
            if (is_file($path) || is_link($path)) { unlink($path); return; }
            foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
            rmdir($path);
        };
        $remove($temporary);
    }
}

<?php
declare(strict_types=1);

use Velvet\Core\MarketingHistory as History;
use Velvet\Core\MarketingPublication as Publication;
use Velvet\Core\MarketingRecovery as Recovery;
use Velvet\Core\MarketingStore;

$source = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/vvx-editorial-recovery-source-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) throw new RuntimeException('The owned recovery fixture could not be created.');
file_put_contents($root . '/.vvx-test-runtime', 'editorial-recovery-source');
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
require_once $source . '/app/Support/functions.php';
require_once $source . '/app/Core/MarketingStore.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$reject = static function (callable $operation, string $message, ?string $file = null) use ($assert): void {
    $before = $file === null ? null : file_get_contents($file);
    $caught = false;
    try { $operation(); } catch (InvalidArgumentException|RuntimeException) { $caught = true; }
    $assert($caught, $message);
    if ($file !== null) $assert(file_get_contents($file) === $before, $message . ' Refusal changed saved bytes.');
};
$zone = date_default_timezone_get();
try {
    date_default_timezone_set('America/Chicago');
    $untouched = $root . '/untouched'; mkdir($untouched, 0700);
    $empty = MarketingStore::previewDeletedPublications(null, null, $untouched);
    $assert($empty['entries'] === [] && $empty['selected'] === null && $empty['total'] === 0
        && $empty['limit'] === 50 && $empty['bytes'] === 0 && scandir($untouched) === ['.', '..'], 'Recovery review initialized untouched storage.');
    mkdir($root . '/config', 0700); mkdir($root . '/storage', 0700);
    file_put_contents($root . '/config/app.php', '<?php return ' . var_export(['storage_mode' => 'file', 'file_store_path' => 'storage/data.json'], true) . ';');
    file_put_contents($root . '/storage/data.json', '{}');
    $store = new MarketingStore($root);
    $file = $root . '/storage/private/marketing/data.json';
    $records = [
        'posts' => ['id' => 'recover-post', 'slug' => 'recover-post', 'title' => 'Recover <post>', 'category' => 'Updates',
            'excerpt' => 'Saved excerpt', 'body' => 'PRIVATE_RECOVERY_BODY<script>saved text</script>', 'status' => 'published',
            'publish_at' => '2024-01-01T12:30:00', 'expires_at' => '2030-12-31T12:30:00-06:00',
            'access' => ['version' => 1, 'mode' => 'members', 'plan_ids' => []], 'tags' => ['Café', 'Saved'], 'author_name' => 'Saved byline',
            'related_post_ids' => [], 'featured_asset_id' => '', 'featured_alt' => '', 'seo_title' => 'Saved search title', 'seo_description' => 'Saved description'],
        'banners' => ['id' => 'recover-banner', 'text' => 'Recover <banner>', 'url' => '/membership/', 'enabled' => true,
            'starts_at' => '2024-01-01T12:30:00-06:00', 'ends_at' => '', 'access' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []]],
        'landing_pages' => ['id' => 'recover-page', 'slug' => 'recover-page', 'title' => 'Recover <page>', 'eyebrow' => 'Saved introduction',
            'excerpt' => 'Page excerpt', 'body' => 'PRIVATE_RECOVERY_PAGE', 'cta_label' => 'Join', 'cta_url' => '/membership/',
            'enabled' => true, 'starts_at' => '', 'ends_at' => '2030-12-31T00:00:00Z', 'access' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []],
            'featured_asset_id' => '', 'featured_alt' => '', 'seo_title' => 'Page search title', 'seo_description' => 'Page description'],
    ];
    foreach ($records as &$record) $record += ['created_at' => '2020-01-01T00:00:00Z', 'updated_at' => '2024-01-01T00:00:00Z',
        'edit_version' => 3, 'unknown_private' => ['nested' => ['preserve' => 'RECOVERY_UNKNOWN_PRIVATE']]];
    unset($record);
    $store->update(static function (array &$data) use ($records): void {
        foreach ($records as $collection => $record) $data[$collection] = [$record];
        $data['campaigns'] = [['id' => 'recovery-campaign', 'delivery_snapshot' => ['body' => 'Frozen body']]];
        $data['campaign_queue'] = [['id' => 'recovery-queue', 'status' => 'unknown', 'attempts' => 2]];
        $data['contacts'] = [['id' => 'recovery-contact', 'email' => 'reserved@example.test', 'status' => 'active']];
        $data['consents'] = [['id' => 'recovery-consent', 'granted' => true]];
        $data['suppressions'] = [['id' => 'recovery-suppression', 'active' => true]];
        $data['unrelated_header'] = ['exact' => ['nested' => 12.5]];
    });
    $original = $store->all();
    $protected = static fn(array $data): array => array_diff_key($data, array_flip(['meta', 'posts', 'banners', 'landing_pages', Recovery::KEY]));
    $assert(!array_key_exists(Recovery::KEY, $original), 'An ordinary legacy store was given an archive on initialization.');
    $deny = static function (array $record): void { throw new RuntimeException('Owner was revoked.', 403); };
    $deleted = [];
    foreach ($records as $collection => $record) {
        $saved = $store->savePublication($collection, ['id' => $record['id'], 'expected_revision' => $store->publicationRecord($collection, $record['id'])['revision'],
            $collection === 'banners' ? 'text' : 'title' => 'Saved recovery ' . $collection]);
        $before = $store->all()[$collection][0];
        $assert(count(History::entries($collection, $before)) === 1, 'The recovery candidate lacks a real prior edit.');
        $reject(static fn() => $store->deletePublication($collection, $record['id'], str_repeat('0', 64)), 'Stale deletion succeeded.', $file);
        $reject(static fn() => $store->deletePublication($collection, $record['id'], $saved['revision'], $deny), 'Revoked owner deleted a publication.', $file);
        $deleted[$collection] = $store->deletePublication($collection, $record['id'], $saved['revision']);
        $review = MarketingStore::previewDeletedPublications($collection, $deleted[$collection]['id'], $root);
        $assert($review['selected']['record'] === $before && $review['selected']['position'] === 0
            && $review['selected']['timezone'] === 'America/Chicago', 'Deletion changed the private record, history or captured context.');
        $assert($store->publicationRecord($collection, $record['id']) === null && $store->publicationHistory($collection, $record['id']) === null
            && MarketingStore::previewPublication($collection, $record['id'], $root) === null, 'Deleted content leaked into an active editor, history or preview.');
        $assert(!isset($deleted[$collection]['record'], $deleted[$collection]['body'], $deleted[$collection]['unknown_private']), 'A delete summary exposed private content.');
        $assert(preg_match('/^[a-f0-9]{32}$/D', $deleted[$collection]['id']) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $deleted[$collection]['revision']) === 1, 'Archive identity or revision is invalid.');
        $reject(static fn() => $store->restorePublication($collection, $record['id'], History::entries($collection, $before)[0]['id'], $saved['revision']), 'Normal history resurrected deleted content.', $file);
        $assert($protected($store->all()) === $protected($original), 'Deletion changed unrelated saved marketing/private data.');
    }
    $assert($store->publishedPosts() === [] && $store->activeBanners() === [] && $store->activeLandingPage('recover-page') === null, 'Deleted content remained eligible for public readers.');
    $review = MarketingStore::previewDeletedPublications(null, null, $root);
    $assert($review['total'] === 3 && count($review['entries']) === 3 && $review['entries'][0]['collection'] === 'landing_pages', 'Archive listing order/count is wrong.');
    $assert(count(MarketingStore::previewDeletedPublications('posts', null, $root)['entries']) === 1, 'Archive type filter failed.');
    $reject(static fn() => MarketingStore::previewDeletedPublications(null, $deleted['posts']['id'], $root), 'An item review accepted an absent content type.');
    foreach (['campaigns', '../posts', 'Posts', ''] as $invalid) {
        $reject(static fn() => MarketingStore::previewDeletedPublications($invalid, null, $root), 'Invalid archive content type was accepted.');
    }
    foreach (['', '../item', str_repeat('a', 31), str_repeat('G', 32)] as $invalid) {
        $reject(static fn() => $store->restoreDeletedPublication('posts', $invalid, $deleted['posts']['revision']), 'Invalid archive identity was restored.', $file);
    }
    $reject(static fn() => $store->restoreDeletedPublication('banners', $deleted['posts']['id'], $deleted['posts']['revision']), 'A foreign collection restored the selected record.', $file);
    $reject(static fn() => $store->restoreDeletedPublication('posts', $deleted['posts']['id'], str_repeat('0', 64)), 'A stale archive revision restored content.', $file);
    $reject(static fn() => $store->restoreDeletedPublication('posts', $deleted['posts']['id'], $deleted['posts']['revision'], $deny), 'A revoked owner restored content.', $file);
    $reject(static fn() => $store->purgeDeletedPublication('posts', $deleted['posts']['id'], $deleted['posts']['revision'], $deny), 'A revoked owner permanently removed content.', $file);
    $reject(static fn() => $store->purgeDeletedPublication('posts', $deleted['posts']['id'], str_repeat('0', 64)), 'A stale archive revision permanently removed content.', $file);
    $reject(static fn() => $store->savePublication('posts', ['title' => 'Conflicting new post', 'slug' => 'recover-post']), 'A new post reused a recoverable permalink.', $file);
    foreach (array_keys($records) as $collection) $reject(static fn() => $store->delete($collection, 'anything'), 'Generic deletion bypassed private recovery.', $file);

    // Imported active collisions must never be overwritten by restoration.
    foreach (['id', 'slug'] as $collision) {
        $row = array_replace($records['posts'], ['id' => $collision === 'id' ? 'recover-post' : 'other-post',
            'slug' => $collision === 'slug' ? 'recover-post' : 'other-post', 'title' => 'Existing imported record']);
        $store->update(static function (array &$data) use ($row): void { $data['posts'] = [$row]; });
        $reject(static fn() => $store->restoreDeletedPublication('posts', $deleted['posts']['id'], $deleted['posts']['revision']), 'Restoration overwrote an active ' . $collision . ' collision.', $file);
        $store->update(static function (array &$data): void { $data['posts'] = []; });
    }

    // A timezone change invalidates the old form; reloaded restoration retains exact instants.
    date_default_timezone_set('UTC');
    $reject(static fn() => $store->restoreDeletedPublication('posts', $deleted['posts']['id'], $deleted['posts']['revision']), 'A stale timezone form restored local dates.', $file);
    $fresh = MarketingStore::previewDeletedPublications('posts', $deleted['posts']['id'], $root);
    $restored = $store->restoreDeletedPublication('posts', $deleted['posts']['id'], Recovery::revision($fresh['selected']));
    $assert($restored['status'] === 'draft' && $restored['id'] === 'recover-post' && $restored['slug'] === 'recover-post'
        && $restored['created_at'] === $records['posts']['created_at'] && $restored['unknown_private'] === $records['posts']['unknown_private'], 'Restore changed identity or unknown data, or published the record.');
    $assert($restored['publish_at'] === '2024-01-01T18:30:00+00:00' && $restored['expires_at'] === $records['posts']['expires_at'], 'Restoration changed a saved local/explicit-offset instant.');
    $history = $store->publicationHistory('posts', 'recover-post');
    $prior = $store->publicationHistory('posts', 'recover-post', $history['entries'][0]['id']);
    $assert($prior['selected']['snapshot']['status'] === 'published'
        && Publication::storedDate($prior['selected']['snapshot']['publish_at'], $prior['selected']['timezone'])->getTimestamp() === 1704133800,
        'Restoration captured the original publication using the wrong timezone.');
    $assert(count($history['entries']) === 2 && $store->findPost('recover-post') === null, 'Restored draft lost history or became public.');
    $reject(static fn() => $store->restoreDeletedPublication('posts', $deleted['posts']['id'], $deleted['posts']['revision']), 'Replayed restoration duplicated a publication.', $file);
    date_default_timezone_set('America/Chicago');
    foreach (['banners', 'landing_pages'] as $collection) {
        $restored = $store->restoreDeletedPublication($collection, $deleted[$collection]['id'], $deleted[$collection]['revision']);
        $assert($restored['enabled'] === false && $restored['id'] === $records[$collection]['id']
            && $restored['access'] === $records[$collection]['access'], 'Restored non-post became public or changed its saved audience.');
    }
    $assert(MarketingStore::previewDeletedPublications(null, null, $root)['total'] === 0, 'Restoration left duplicate archive entries.');
    $assert($protected($store->all()) === $protected($original), 'Restoration changed unrelated saved marketing/private data.');

    // Each deletion has a new archive identity, even with identical editorial content.
    $current = $store->publicationRecord('posts', 'recover-post');
    $second = $store->deletePublication('posts', 'recover-post', $current['revision']);
    $assert($second['id'] !== $deleted['posts']['id'], 'A later deletion reused its older recovery identity.');
    $reject(static fn() => $store->purgeDeletedPublication('posts', $deleted['posts']['id'], $deleted['posts']['revision']), 'An old purge form removed a later deletion.', $file);
    $store->purgeDeletedPublication('posts', $second['id'], $second['revision']);
    $assert(MarketingStore::previewDeletedPublications('posts', $second['id'], $root)['selected'] === null && $store->publicationRecord('posts', 'recover-post') === null,
        'Permanent removal retained a recoverable entry or created an active publication.');
    $new = $store->savePublication('posts', ['title' => 'A deliberate new post', 'slug' => 'recover-post']);
    $assert($new['id'] !== 'recover-post' && $new['status'] === 'draft', 'Permanent removal did not free the permalink for a deliberate new draft.');

    // Missing references stay exact and offline so the normal editor can repair them.
    $missing = array_replace($records['posts'], ['id' => 'missing-refs', 'slug' => 'missing-refs',
        'access' => ['version' => 1, 'mode' => 'tiers', 'plan_ids' => ['missing-plan']],
        'featured_asset_id' => 'missing-image', 'related_post_ids' => ['missing-related']]);
    $store->update(static function (array &$data) use ($missing): void { $data['posts'][] = $missing; });
    $entry = $store->deletePublication('posts', $missing['id'], $store->publicationRecord('posts', $missing['id'])['revision']);
    $back = $store->restoreDeletedPublication('posts', $entry['id'], $entry['revision']);
    $assert($back['status'] === 'draft' && $back['access'] === $missing['access'] && $back['featured_asset_id'] === 'missing-image'
        && $back['related_post_ids'] === ['missing-related'], 'Missing references prevented offline recovery or widened/replaced saved selections.');

    // Bounded damaged history can be withdrawn without being destroyed, but not restored as valid.
    $bad = array_replace($records['posts'], ['id' => 'damaged-history', 'slug' => 'damaged-history', History::KEY => ['schema' => 'bad', 'entries' => 'PRIVATE_DAMAGED']]);
    $store->update(static function (array &$data) use ($bad): void { $data['posts'][] = $bad; });
    $badEntry = $store->deletePublication('posts', $bad['id'], $store->publicationRecord('posts', $bad['id'])['revision']);
    $assert(MarketingStore::previewDeletedPublications('posts', $badEntry['id'], $root)['selected']['record'] === $bad, 'Damaged history was reset during safe removal.');
    $reject(static fn() => $store->restoreDeletedPublication('posts', $badEntry['id'], $badEntry['revision']), 'Malformed history was restored as valid.', $file);
    $store->purgeDeletedPublication('posts', $badEntry['id'], $badEntry['revision']);

    // Malformed archive envelopes are refused without overwriting any saved state.
    $entry = $store->deletePublication('posts', $new['id'], $store->publicationRecord('posts', $new['id'])['revision']);
    $valid = $store->all()[Recovery::KEY];
    $badEnvelopes = [null, [], ['schema' => 2, 'entries' => []], ['schema' => 1, 'entries' => 'invalid'],
        ['schema' => 1, 'entries' => [], 'extra' => true], ['schema' => 1, 'entries' => ['key' => $valid['entries'][0]]],
        ['schema' => 1, 'entries' => [$valid['entries'][0], $valid['entries'][0]]]];
    foreach (['id' => '../bad', 'collection' => 'campaigns', 'deleted_at' => '2026-02-30T00:00:00+00:00',
        'timezone' => 'Invalid/Zone', 'position' => -1, 'record_hash' => str_repeat('0', 64), 'record' => ['id' => '../invalid']] as $key => $value) {
        $badEnvelopes[] = ['schema' => 1, 'entries' => [array_replace($valid['entries'][0], [$key => $value])]];
    }
    foreach ($badEnvelopes as $envelope) {
        $store->update(static function (array &$data) use ($envelope): void { $data[Recovery::KEY] = $envelope; });
        $reject(static fn() => MarketingStore::previewDeletedPublications(null, null, $root), 'Malformed archive was shown as valid.', $file);
        $reject(static fn() => $store->restoreDeletedPublication('posts', $entry['id'], $entry['revision']), 'Malformed archive was restored.', $file);
        $reject(static fn() => $store->purgeDeletedPublication('posts', $entry['id'], $entry['revision']), 'Malformed archive was silently reset by removal.', $file);
    }
    $store->update(static function (array &$data) use ($valid): void { $data[Recovery::KEY] = $valid; });

    // Capacity has no time-based eviction; reaching either bound leaves the live item intact.
    $full = [];
    for ($i = 0; $i < Recovery::LIMIT; $i++) {
        $record = array_replace($records['posts'], ['id' => 'capacity-' . $i, 'slug' => 'capacity-' . $i]);
        array_unshift($full, Recovery::capture('posts', $record, 0, $full));
    }
    $store->update(static function (array &$data) use ($full): void { $data[Recovery::KEY] = Recovery::envelope($full); });
    $current = $store->publicationRecord('banners', 'recover-banner');
    $reject(static fn() => $store->deletePublication('banners', 'recover-banner', $current['revision']), 'A full archive evicted existing content.', $file);
    $assert(count(Recovery::entries($store->all())) === 50 && $store->publicationRecord('banners', 'recover-banner') !== null, 'Capacity refusal lost live or deleted content.');
    $store->purgeDeletedPublication('posts', $full[0]['id'], Recovery::revision($full[0]));
    $store->deletePublication('banners', 'recover-banner', $current['revision']);
    $assert(count(Recovery::entries($store->all())) === 50, 'Explicit permanent removal failed to make room for another item.');
    $oversize = array_replace($records['posts'], ['unknown_large' => str_repeat('x', Recovery::MAX_RECORD_BYTES)]);
    $reject(static fn() => Recovery::capture('posts', $oversize, 0, []), 'An oversized individual record entered the bounded archive.');
    $largeEntries = [];
    for ($i = 0; $i < 4; $i++) {
        $large = array_replace($records['posts'], ['id' => 'bytes-' . $i, 'unknown_large' => str_repeat('x', 7000000)]);
        array_unshift($largeEntries, Recovery::capture('posts', $large, 0, $largeEntries));
    }
    $large = array_replace($records['posts'], ['id' => 'bytes-overflow', 'unknown_large' => str_repeat('x', 7000000)]);
    $reject(static fn() => Recovery::capture('posts', $large, 0, $largeEntries), 'The aggregate archive byte bound was ignored.');
    unset($largeEntries, $large, $oversize);

    $before = file_get_contents($file); $lock = dirname($file) . '/data.lock';
    chmod($lock, 0440); clearstatcache(true, $lock);
    $review = MarketingStore::previewDeletedPublications(null, null, $root); clearstatcache(true, $lock);
    $assert(count($review['entries']) === 50 && file_get_contents($file) === $before && (fileperms($lock) & 0777) === 0440, 'Read-only review changed saved bytes or lock permissions.');
    unlink($lock);
    $review = MarketingStore::previewDeletedPublications(null, null, $root);
    $assert(count($review['entries']) === 50 && !file_exists($lock) && file_get_contents($file) === $before
        && !file_exists(dirname($file) . '/sql-cutover.json'), 'Read-only review recreated missing lock/guard files.');
    $assert($protected($store->all()) === $protected($original), 'Recovery operations changed unrelated private data.');
    echo 'Editorial recovery assertions: ' . $checks . "\n";
} finally {
    date_default_timezone_set($zone);
    if (!is_file($root . '/.vvx-test-runtime') || is_link($root)) throw new RuntimeException('Refusing cleanup without fixture ownership.');
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname()); else unlink($item->getPathname()); }
    rmdir($root);
}

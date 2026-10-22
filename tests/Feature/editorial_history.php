<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Store;
use Velvet\Core\MarketingHistory as History;
use Velvet\Core\MarketingMetadata as Metadata;
use Velvet\Core\MarketingPublication as Publication;
use Velvet\Core\MarketingStore;

$source = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/vvx-history-source-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700) || file_put_contents($root . '/history.fixture', 'editorial-history-source') === false) {
    throw new RuntimeException('The owned history fixture could not be created.');
}
file_put_contents($root . '/.vvx-test-runtime', 'editorial-history-source');
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
require_once $source . '/app/Support/functions.php';
require_once $source . '/app/Core/MarketingStore.php';
require_once __DIR__ . '/editorial_metadata.php';

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
    mkdir($root . '/config', 0700);
    $configData = ['storage_mode' => 'file', 'file_store_path' => 'storage/data.json'];
    file_put_contents($root . '/config/app.php', '<?php return ' . var_export($configData, true) . ';');
    mkdir($root . '/storage', 0700);
    file_put_contents($root . '/storage/data.json', '{}');
    $config = new Config($root . '/config/app.php');
    $store = new Store($config);
    $assets = vvx_metadata_test_assets($store, $root);
    $marketing = new MarketingStore($root);
    $file = $root . '/storage/private/marketing/data.json';
    $records = [
        'posts' => array_replace(vvx_metadata_test_post('history'), ['id' => 'history-post', 'slug' => 'fixed-history-post',
            'status' => 'draft', 'body' => 'PRIVATE_PRIOR_POST_BODY', 'title' => 'Old post title',
            'publish_at' => '2024-06-01T12:30:00', 'access' => ['version' => 1, 'mode' => 'members', 'plan_ids' => []],
            'featured_asset_id' => (string) $assets['a']['id'], 'related_post_ids' => ['history-related'],
            'owner_unknown' => ['private' => 'NEVER_COPY_UNKNOWN_OLD']]),
        'banners' => ['id' => 'history-banner', 'text' => 'PRIVATE_PRIOR_BANNER_TEXT', 'url' => '/old-banner/', 'enabled' => false,
            'starts_at' => '2024-06-01T12:30:00-05:00', 'ends_at' => '2035-01-01T00:00:00Z',
            'access' => ['version' => 1, 'mode' => 'members', 'plan_ids' => []], 'edit_version' => 3,
            'created_at' => '2020-01-01T00:00:00Z', 'owner_unknown' => ['private' => 'NEVER_COPY_UNKNOWN_OLD']],
        'landing_pages' => ['id' => 'history-page', 'slug' => 'fixed-history-page', 'title' => 'Old page title',
            'eyebrow' => 'Old eyebrow', 'excerpt' => 'Old excerpt', 'body' => 'PRIVATE_PRIOR_PAGE_BODY',
            'cta_label' => 'Old call to action', 'cta_url' => '/old-page/', 'enabled' => false,
            'starts_at' => '2024-06-01T12:30:00-05:00', 'ends_at' => '2035-01-01T00:00:00Z',
            'access' => ['version' => 1, 'mode' => 'members', 'plan_ids' => []], 'edit_version' => 3,
            'featured_asset_id' => (string) $assets['a']['id'], 'featured_alt' => 'Old page artwork',
            'seo_title' => 'Old page search title', 'seo_description' => 'Old page description',
            'created_at' => '2020-01-01T00:00:00Z', 'owner_unknown' => ['private' => 'NEVER_COPY_UNKNOWN_OLD']],
    ];
    $related = array_replace(vvx_metadata_test_post('related'), ['id' => 'history-related', 'slug' => 'history-related']);
    $marketing->update(static function (array &$data) use ($records, $related): void {
        foreach ($records as $collection => $record) $data[$collection] = ['stable-entry-key' => $record];
        $data['posts']['related-entry-key'] = $related;
        $data['history_unknown_header'] = ['keep' => ['nested' => true]];
        $data['contacts'] = [['id' => 'history-contact', 'status' => 'active', 'unsubscribe_token' => 'RESERVED_HISTORY_TOKEN']];
        $data['campaigns'] = [['id' => 'history-campaign', 'delivery_snapshot' => ['body' => 'Frozen history fixture body']]];
        $data['campaign_queue'] = [['id' => 'history-queue', 'status' => 'unknown', 'attempts' => 1]];
        $data['consents'] = [['id' => 'history-consent', 'granted' => true]];
        $data['suppressions'] = [['id' => 'history-suppression', 'active' => true]];
    });
    $original = $marketing->all();
    $save = static function (string $collection, string $id, array $changes) use ($marketing): array {
        $current = $marketing->publicationRecord($collection, $id);
        return $marketing->savePublication($collection, ['id' => $id, 'expected_revision' => $current['revision']] + $changes);
    };
    $selected = [];
    foreach ($records as $collection => $record) {
        $id = $record['id'];
        $empty = $marketing->publicationHistory($collection, $id);
        $assert($empty['entries'] === [] && $empty['selected'] === null && $empty['limit'] === 20, 'A legacy publication fabricated older history.');
        $changes = $collection === 'banners' ? ['text' => 'Current public banner', 'enabled' => true, 'url' => '/current-banner/']
            : ['title' => 'Current public title', 'body' => 'Current public body'];
        $changes += ['access' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []]];
        if ($collection === 'posts') $changes['status'] = 'published';
        if ($collection === 'landing_pages') $changes['enabled'] = true;
        $saved = $save($collection, $id, $changes);
        $history = $marketing->publicationHistory($collection, $id);
        $assert(count($history['entries']) === 1, 'The first real edit did not capture exactly one prior version.');
        $entryId = $history['entries'][0]['id'];
        $selected[$collection] = $entryId;
        $readBefore = file_get_contents($file);
        $review = MarketingStore::previewPublicationHistory($collection, $id, $entryId, $root);
        $assert($review !== null && $review['selected']['id'] === $entryId && file_get_contents($file) === $readBefore,
            'The read-only selected history review changed saved data or lost its server entry.');
        $assert(($review['related_titles']['history-related'] ?? null) === $related['title']
            && array_filter($review['related_titles'], static fn(mixed $title): bool => !is_string($title)) === [],
            'Private history review did not supply current related ID/title labels from its saved snapshot.');
        if ($collection === 'posts') $assert(($review['related_titles'][$id] ?? null) === $saved['title'], 'A related label came from historical title data instead of the current saved record.');
        $entry = $review['selected'];
        $assert($entry['snapshot'] === array_intersect_key($record, array_flip(History::keys($collection))), 'Captured editorial fields differ from the preceding saved record.');
        $assert($entry['timezone'] === 'America/Chicago' && $entry['action'] === 'save'
            && $entry['source_edit_version'] === $record['edit_version'] && str_ends_with($entry['captured_at'], '+00:00'), 'History provenance is not the actual captured version/timezone.');
        $assert(array_intersect(array_keys($entry['snapshot']), ['id', 'slug', 'created_at', 'updated_at', 'edit_version', 'owner_unknown', History::KEY]) === [], 'A history snapshot copied fixed identity or unrelated private fields.');
        $assert(!str_contains(json_encode($history['entries']), 'PRIVATE_PRIOR_POST_BODY')
            && !str_contains(json_encode($history['entries']), 'PRIVATE_PRIOR_PAGE_BODY')
            && !str_contains(json_encode($review['selected']), 'NEVER_COPY_UNKNOWN_OLD'), 'History summaries contain prior bodies or unknown private fields.');
        $assert(!array_key_exists(History::KEY, $saved) && !array_key_exists(History::KEY, $review['current'])
            && !array_key_exists(History::KEY, MarketingStore::previewPublication($collection, $id, $root)), 'An ordinary owner read/preview exposed the envelope.');
        $raw = $marketing->all()[$collection]['stable-entry-key'];
        $assert($saved['revision'] === Publication::revision($raw), 'The returned form revision does not cover private history.');
        $assert(array_keys($marketing->all()[$collection]) === array_keys($original[$collection]), 'Editing a publication changed its entry keys/order.');
        $noop = $save($collection, $id, []);
        $assert($marketing->publicationHistory($collection, $id)['entries'] === $history['entries'], 'A no-op save duplicated history.');
        $assert($noop['revision'] !== $saved['revision'], 'A successful save lost its existing stale-form revision behavior.');
    }
    foreach ([$marketing->publishedPosts(), $marketing->activeBanners(), [$marketing->activeLandingPage('fixed-history-page')],
        $marketing->relatedPosts('history-post'), MarketingStore::previewRelatedPosts('history-post', $root)] as $publicRecords) {
        $encoded = json_encode($publicRecords);
        $assert(!str_contains($encoded, History::KEY) && !str_contains($encoded, 'PRIVATE_PRIOR_'), 'A public/related/saved-preview getter returned private historical content.');
    }

    foreach ($records as $collection => $record) {
        $id = $record['id'];
        $marketing->update(static function (array &$data) use ($collection): void { $data[$collection]['stable-entry-key']['owner_unknown'] = ['private' => 'KEEP_CURRENT_UNKNOWN']; });
        $current = $marketing->publicationRecord($collection, $id);
        $restore = $marketing->restorePublication($collection, $id, $selected[$collection], $current['revision']);
        $assert($restore['id'] === $id && ($restore['slug'] ?? null) === ($record['slug'] ?? null)
            && $restore['created_at'] === $record['created_at'] && $restore['owner_unknown'] === ['private' => 'KEEP_CURRENT_UNKNOWN'], 'Restore replaced current identity, permalink, creation time or unknown fields.');
        $assert(!Publication::enabled($collection, $restore) && !Publication::visibility($collection, $restore)['visible'], 'Restoring a version published or enabled it.');
        $assert($restore[$collection === 'banners' ? 'text' : 'body'] === $record[$collection === 'banners' ? 'text' : 'body']
            && $restore['access'] === $record['access'], 'Restoration lost the selected content or access policy.');
        $history = $marketing->publicationHistory($collection, $id);
        $assert(count($history['entries']) === 2 && $history['entries'][0]['action'] === 'restore', 'Restore did not archive the state it replaced for undo.');
        $assert(array_keys($marketing->all()[$collection]) === array_keys($original[$collection]), 'Restoring a version reordered records.');
        $undo = $marketing->restorePublication($collection, $id, $history['entries'][0]['id'], $restore['revision']);
        $assert($undo[$collection === 'banners' ? 'text' : 'body'] === ($collection === 'banners' ? 'Current public banner' : 'Current public body')
            && !Publication::enabled($collection, $undo), 'Restoring the captured pre-restore state did not undo content while keeping it offline.');
        $beforeToggle = $marketing->publicationHistory($collection, $id);
        $on = $marketing->setPublicationEnabled($collection, $id, true, $undo['revision']);
        $toggleHistory = $marketing->publicationHistory($collection, $id);
        $toggleEntry = $marketing->publicationHistory($collection, $id, $toggleHistory['entries'][0]['id'])['selected'];
        $assert(count($toggleHistory['entries']) === count($beforeToggle['entries']) + 1
            && $toggleEntry['action'] === 'visibility' && !Publication::enabled($collection, $toggleEntry['snapshot']), 'Enabling an item did not archive its preceding offline state.');
        $off = $marketing->setPublicationEnabled($collection, $id, false, $on['revision']);
        $offHistory = $marketing->publicationHistory($collection, $id);
        $offEntry = $marketing->publicationHistory($collection, $id, $offHistory['entries'][0]['id'])['selected'];
        $assert($offEntry['action'] === 'visibility' && Publication::enabled($collection, $offEntry['snapshot']), 'Safe withdrawal did not archive the preceding enabled state.');
        $marketing->setPublicationEnabled($collection, $id, false, $off['revision']);
        $assert($marketing->publicationHistory($collection, $id)['entries'] === $offHistory['entries'], 'An unchanged visibility action duplicated history.');
    }
    $current = $marketing->publicationRecord('posts', 'history-post');
    $stale = $current['revision'];
    $save('posts', 'history-post', ['title' => 'A concurrent current title']);
    $reject(static fn() => $marketing->restorePublication('posts', 'history-post', $selected['posts'], $stale), 'A stale restore replaced a newer edit.', $file);
    $current = $marketing->publicationRecord('posts', 'history-post');
    $reject(static fn() => $marketing->restorePublication('posts', 'history-post', $selected['landing_pages'], $current['revision']), 'A cross-record saved version was accepted.', $file);
    foreach (['', 'bad', str_repeat('f', 32), str_repeat('f', 64)] as $bad) {
        $reject(static fn() => $marketing->restorePublication('posts', 'history-post', $bad, $current['revision']), 'An invalid or unavailable saved version was accepted.', $file);
    }
    $reject(static fn() => $marketing->restorePublication('posts', 'history-post', $selected['posts'], $current['revision'],
        static function (): void { throw new RuntimeException('Owner revoked.', 403); }), 'An owner rejected after reference resolution still restored a version.', $file);

    // An earlier local schedule retains its captured-zone instant after the site zone changes.
    date_default_timezone_set('UTC');
    $reject(static fn() => $marketing->restorePublication('posts', 'history-post', $selected['posts'], $current['revision']), 'A timezone-stale restore was accepted.', $file);
    $current = $marketing->publicationRecord('posts', 'history-post');
    $restored = $marketing->restorePublication('posts', 'history-post', $selected['posts'], $current['revision']);
    $assert($restored['publish_at'] === '2024-06-01T17:30:00+00:00', 'A historical local time used the new site timezone instead of its captured timezone.');
    date_default_timezone_set('America/Chicago');
    $page = $marketing->publicationRecord('landing_pages', 'history-page');
    $pageRestored = $marketing->restorePublication('landing_pages', 'history-page', $selected['landing_pages'], $page['revision']);
    $assert($pageRestored['starts_at'] === '2024-06-01T12:30:00-05:00', 'An explicitly offset historical timestamp was relabeled or shifted.');

    // Restores resolve currently eligible references, not a cached historical media decision.
    rename($root . '/' . $assets['a']['file'], $root . '/' . $assets['a']['file'] . '.held');
    $current = $marketing->publicationRecord('posts', 'history-post');
    $reject(static fn() => $marketing->restorePublication('posts', 'history-post', $selected['posts'], $current['revision']), 'A disappeared historical featured image was restored.', $file);
    symlink($root . '/storage/private/media/images/editorial-metadata-private.png', $root . '/' . $assets['a']['file']);
    $reject(static fn() => $marketing->restorePublication('posts', 'history-post', $selected['posts'], $current['revision']), 'A historical image replaced by a protected symlink was restored.', $file);
    unlink($root . '/' . $assets['a']['file']);
    rename($root . '/' . $assets['a']['file'] . '.held', $root . '/' . $assets['a']['file']);
    $marketing->update(static function (array &$data): void { unset($data['posts']['related-entry-key']); });
    $current = $marketing->publicationRecord('posts', 'history-post');
    $reject(static fn() => $marketing->restorePublication('posts', 'history-post', $selected['posts'], $current['revision']), 'A deleted historical related post was restored.', $file);
    $marketing->update(static function (array &$data) use ($related): void { $data['posts']['related-entry-key'] = $related; });

    $largeBody = str_repeat("\n\"", 50000);
    $large = $marketing->savePublication('posts', ['title' => 'Maximum body fixture', 'slug' => 'maximum-history-body', 'body' => $largeBody, 'excerpt' => str_repeat("\n", 8000)]);
    $largeSaved = $save('posts', $large['id'], ['body' => 'Current small body']);
    $largeHistory = $marketing->publicationHistory('posts', $large['id']);
    $largeRestored = $marketing->restorePublication('posts', $large['id'], $largeHistory['entries'][0]['id'], $largeSaved['revision']);
    $assert($largeRestored['body'] === $largeBody && $largeRestored['excerpt'] === str_repeat("\n", 8000), 'The history byte bound broke the existing maximum body/excerpt limits after JSON escaping.');

    $new = $marketing->savePublication('posts', ['title' => 'History retention fixture', 'slug' => 'history-retention', 'body' => 'Version 0']);
    $assert($marketing->publicationHistory('posts', $new['id'])['entries'] === [], 'Creation invented a nonexistent prior version.');
    $firstRetained = null;
    for ($i = 1; $i <= 25; $i++) {
        $saved = $save('posts', $new['id'], ['body' => 'Version ' . $i]);
        $history = $marketing->publicationHistory('posts', $new['id']);
        $firstRetained ??= $history['entries'][0]['id'];
        $assert(count($history['entries']) === min($i, 20), 'The number of prior versions exceeded or prematurely lost the retention bound.');
    }
    $newest = $marketing->publicationHistory('posts', $new['id'], $history['entries'][0]['id']);
    $oldest = $marketing->publicationHistory('posts', $new['id'], $history['entries'][19]['id']);
    $assert($newest['selected']['snapshot']['body'] === 'Version 24' && $oldest['selected']['snapshot']['body'] === 'Version 5', 'History retention did not keep the newest 20 prior versions in order.');
    $assert($marketing->publicationHistory('posts', $new['id'], $firstRetained) === null, 'An evicted version remained selectable.');
    $reject(static fn() => $marketing->restorePublication('posts', $new['id'], $firstRetained, $saved['revision']), 'An evicted version was silently replaced by another version.', $file);

    $raw = $marketing->all()['posts'];
    $retentionIndex = array_search($new['id'], array_column(array_values($raw), 'id'), true);
    $retentionKey = array_keys($raw)[$retentionIndex];
    $validEnvelope = $raw[$retentionKey][History::KEY];
    $badSnapshot = $validEnvelope; $badSnapshot['entries'][0]['snapshot']['owner_unknown'] = 'Forbidden';
    $duplicate = $validEnvelope; $duplicate['entries'][1]['id'] = $duplicate['entries'][0]['id'];
    $oversized = $validEnvelope; $oversized['entries'][0]['snapshot']['body'] = str_repeat('x', History::MAX_SNAPSHOT_BYTES + 1);
    foreach ([null, ['schema' => 2, 'entries' => []], ['schema' => 1, 'entries' => 'invalid'],
        ['schema' => 1, 'entries' => [] , 'unknown' => true], $badSnapshot, $duplicate, $oversized] as $badEnvelope) {
        $marketing->update(static function (array &$data) use ($retentionKey, $badEnvelope): void { $data['posts'][$retentionKey][History::KEY] = $badEnvelope; });
        $reject(static fn() => $marketing->publicationHistory('posts', $new['id']), 'Malformed history was treated as usable.', $file);
        $reject(static fn() => $save('posts', $new['id'], ['title' => 'Should not replace bad history']), 'A normal save discarded malformed history.', $file);
        $before = $marketing->publicationRecord('posts', $new['id']);
        $reject(static fn() => $marketing->restorePublication('posts', $new['id'], $validEnvelope['entries'][0]['id'], $before['revision']), 'Malformed history was restored.', $file);
        $offline = $marketing->setPublicationEnabled('posts', $new['id'], false, $before['revision']);
        $assert($offline['status'] === 'draft' && $marketing->all()['posts'][$retentionKey][History::KEY] === $badEnvelope, 'Safe withdrawal was blocked by or rewrote malformed history.');
    }
    $marketing->update(static function (array &$data) use ($retentionKey, $validEnvelope): void { $data['posts'][$retentionKey][History::KEY] = $validEnvelope; });
    $preservedHistory = $marketing->all()['posts'][$retentionKey][History::KEY];
    $save('posts', $new['id'], [History::KEY => ['schema' => 1, 'entries' => []]]);
    $assert($marketing->all()['posts'][$retentionKey][History::KEY] === $preservedHistory, 'A submitted envelope replaced server-held history.');

    // Bounded invalid legacy fields remain reviewable but never silently restore.
    $marketing->update(static function (array &$data): void {
        $data['posts']['legacy-invalid'] = ['id' => 'history-invalid', 'slug' => 'history-invalid',
            'title' => ['malformed' => 'legacy'], 'body' => 'Preserve invalid legacy draft', 'status' => 'draft'];
    });
    $repaired = $save('posts', 'history-invalid', ['title' => 'Repaired current title']);
    $invalidHistory = $marketing->publicationHistory('posts', 'history-invalid');
    $assert(count($invalidHistory['entries']) === 1 && $invalidHistory['entries'][0]['title'] === 'Saved publication', 'Repairing malformed bounded legacy content lost its safe history summary.');
    $reject(static fn() => $marketing->restorePublication('posts', 'history-invalid', $invalidHistory['entries'][0]['id'], $repaired['revision']), 'Malformed legacy content was restored.', $file);

    $beforeDelete = $marketing->publicationRecord('posts', $new['id']);
    $deletedRecord = $marketing->all()['posts'][$retentionKey];
    $deleted = $marketing->deletePublication('posts', $new['id'], $beforeDelete['revision']);
    $assert($marketing->publicationHistory('posts', $new['id']) === null
        && MarketingStore::previewPublicationHistory('posts', $new['id'], null, $root) === null, 'Deletion left a selectable active history.');
    $recovery = MarketingStore::previewDeletedPublications('posts', $deleted['id'], $root);
    $assert($recovery['selected']['record'] === $deletedRecord, 'Deletion failed to preserve the complete saved record and history privately.');
    $reject(static fn() => $marketing->restorePublication('posts', $new['id'], $validEnvelope['entries'][0]['id'], $beforeDelete['revision']), 'Restoration resurrected a deleted item.', $file);
    $emptyRoot = $root . '/absent-history'; mkdir($emptyRoot, 0700);
    $assert(MarketingStore::previewPublicationHistory('posts', 'absent-post', null, $emptyRoot) === null && scandir($emptyRoot) === ['.', '..'], 'Private history review initialized an absent saved store.');
    $final = $marketing->all();
    $assert(array_diff_key($final, array_flip(['meta', 'posts', 'banners', 'landing_pages', \Velvet\Core\MarketingRecovery::KEY])) === array_diff_key($original, array_flip(['meta', 'posts', 'banners', 'landing_pages'])),
        'History actions modified frozen messages, queue entries, consent, suppression, contacts, settings or unknown headers.');

    // Related labels cannot trigger a second initializing/chmodding marketing read.
    $marketing->update(static function (array &$data) use ($related): void {
        $data['posts']['duplicate-related-title'] = array_replace($related, ['title' => 'Do not choose this ambiguous title']);
        $data['posts']['invalid-related-title'] = ['id' => 'invalid-related-title', 'title' => ['not' => 'text']];
        $data['posts']['invalid-related-id'] = ['id' => '../bad-id', 'title' => 'Do not expose this invalid ID'];
    });
    $readBefore = file_get_contents($file);
    $dataLock = $root . '/storage/private/marketing/data.lock';
    $sqlGuard = $root . '/storage/private/marketing/sql-cutover.json';
    chmod($dataLock, 0440); clearstatcache(true, $dataLock);
    $lockMode = fileperms($dataLock) & 0777;
    foreach ([static fn() => MarketingStore::previewPublicationHistory('posts', 'history-post', $selected['posts'], $root),
        static fn() => $marketing->publicationHistory('posts', 'history-post', $selected['posts'])] as $privateRead) {
        $review = $privateRead(); clearstatcache(true, $dataLock);
        $assert($review !== null && !array_key_exists('history-related', $review['related_titles'])
            && !array_key_exists('invalid-related-title', $review['related_titles']) && !array_key_exists('../bad-id', $review['related_titles']),
            'Related-title labels admitted duplicate IDs, invalid IDs or malformed titles.');
        $assert((fileperms($dataLock) & 0777) === $lockMode && file_get_contents($file) === $readBefore && !file_exists($sqlGuard),
            'A private history read changed lock permissions, initialized a SQL guard or changed marketing bytes.');
    }
    unlink($dataLock);
    foreach ([static fn() => MarketingStore::previewPublicationHistory('posts', 'history-post', $selected['posts'], $root),
        static fn() => $marketing->publicationHistory('posts', 'history-post', $selected['posts'])] as $privateRead) {
        $review = $privateRead();
        $assert($review !== null && isset($review['related_titles']) && !file_exists($dataLock) && !file_exists($sqlGuard)
            && file_get_contents($file) === $readBefore, 'A private history read recreated a missing data lock/SQL guard or mutated saved bytes.');
    }
    unlink($file);
    $assert($marketing->publicationHistory('posts', 'history-post') === null && !file_exists($file) && !file_exists($dataLock),
        'An existing store instance recreated missing data during history review.');
} finally {
    date_default_timezone_set($zone);
    if (!str_starts_with($root, sys_get_temp_dir() . '/vvx-history-source-') || file_get_contents($root . '/history.fixture') !== 'editorial-history-source') {
        throw new RuntimeException('Refusing history cleanup without its exact owned marker.');
    }
    $remove = static function (string $path) use (&$remove): void {
        if (is_file($path) || is_link($path)) { unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
        rmdir($path);
    };
    $remove($root);
}
echo "Editorial history source acceptance passed ({$checks} assertions; bounded private versions, safe restore, retained identity, fresh references, stale edits and read-only review).\n";

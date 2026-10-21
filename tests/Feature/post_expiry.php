<?php
declare(strict_types=1);

use Velvet\Core\MarketingHistory as History;
use Velvet\Core\MarketingPublication as Publication;
use Velvet\Core\MarketingStore;

require dirname(__DIR__, 2) . '/app/Core/MarketingStore.php';

// A marked disposable file store only. No transport or scheduled worker runs.
$root = sys_get_temp_dir() . '/vvx-post-expiry-source-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700) || file_put_contents($root . '/fixture.marker', 'post-expiry-source-test') === false) {
    throw new RuntimeException('Fixture creation failed.');
}
$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) $failures[] = $message;
};
$reject = static function (callable $operation, string $message, ?string $file = null) use ($assert): void {
    $before = $file === null ? null : file_get_contents($file);
    $caught = false;
    try { $operation(); } catch (InvalidArgumentException|RuntimeException) { $caught = true; }
    $assert($caught, $message);
    if ($file !== null) $assert(file_get_contents($file) === $before, $message . ' Rejection changed saved bytes.');
};
$timezone = date_default_timezone_get();
try {
    date_default_timezone_set('America/Chicago');
    $start = strtotime('2025-06-01T17:30:00Z');
    $end = $start + 60;
    $window = ['status' => 'published', 'publish_at' => '2025-06-01T17:30:00Z', 'expires_at' => '2025-06-01T17:31:00Z'];
    foreach ([[$start - 1, 'Scheduled', false], [$start, 'Active', true], [$end - 1, 'Active', true],
        [$end, 'Expired', false], [$end + 1, 'Expired', false]] as [$now, $state, $visible]) {
        $actual = Publication::visibility('posts', $window, $now);
        $assert($actual['state'] === $state && $actual['visible'] === $visible
            && $actual['start'] === $start && $actual['end'] === $end && $actual['problem'] === null,
            'Post publication/expiration boundary is incorrect at ' . $now . '.');
    }
    $assert(Publication::visibility('posts', array_replace($window, ['status' => 'draft']), $end)['state'] === 'Draft',
        'An expired draft was promoted to a published state.');
    $assert(Publication::visibility('posts', ['status' => 'published', 'expires_at' => $window['expires_at']], $end)['state'] === 'Expired',
        'An expiration without a publish date failed to withdraw the immediate post.');
    foreach ([[], ['expires_at' => ''], ['expires_at' => null]] as $open) {
        $assert(Publication::visibility('posts', ['status' => 'published'] + $open, $end)['visible'],
            'An intentionally absent/blank expiration became a closed window.');
    }
    foreach (['banners', 'landing_pages'] as $collection) {
        $actual = Publication::visibility($collection, ['enabled' => true, 'starts_at' => $window['publish_at'], 'ends_at' => $window['expires_at']], $end);
        $assert($actual['state'] === 'Ended' && !$actual['visible'], 'Post expiry renamed a retained banner/page state.');
    }

    $minimal = ['title' => 'Expiration source fixture', 'status' => 'published'];
    $legacyFields = Publication::fields('posts', $minimal, []);
    $assert(!array_key_exists('expires_at', $legacyFields), 'A legacy form acquired an optional expiration field.');
    $assert(Publication::fields('posts', $minimal, ['expires_at' => null])['expires_at'] === null,
        'An omitted optional null bound changed its saved representation.');
    $assert(Publication::fields('posts', $minimal + ['expires_at' => ''], [])['expires_at'] === '', 'Explicit blank did not clear expiration.');
    $assert(Publication::fields('posts', $minimal + ['expires_at' => null], [])['expires_at'] === '', 'Explicit null did not retain the existing blank-date contract.');
    $assert(Publication::fields('posts', $minimal + ['expires_at' => '2025-06-01T12:31'], [])['expires_at'] === '2025-06-01T17:31:00+00:00',
        'Expiration was not interpreted in site-local summer time.');
    foreach (['UTC' => '2025-12-01T12:30:45+00:00', 'America/Chicago' => '2025-12-01T18:30:45+00:00',
        'Asia/Tokyo' => '2025-12-01T03:30:45+00:00'] as $zone => $expected) {
        date_default_timezone_set($zone);
        $assert(Publication::fields('posts', $minimal + ['expires_at' => '2025-12-01T12:30:45'], [])['expires_at'] === $expected,
            'Expiration used the wrong site timezone for ' . $zone . '.');
    }
    date_default_timezone_set('America/Chicago');
    $badDates = ['tomorrow', '2025-02-30T12:00', '2025-03-09T02:30', '2025-06-01T24:00',
        '2025-06-01T12:60', '2025-06-01T12:30:60', '0000-01-01T00:00', '2025-06-01',
        '2025-06-01 12:00', '2025-06-01T12:00Z', '2025-06-01T12:00+00:00',
        "2025-06-01T12:00\n", ' ', [], false, 0, 1.5];
    foreach ($badDates as $index => $value) {
        $reject(static fn() => Publication::fields('posts', $minimal + ['expires_at' => $value], []),
            'Malformed or normalized expiration input was accepted at case ' . $index . '.');
    }
    foreach (['tomorrow', '2025-02-30T12:00:00Z', '2025-06-01T12:00:00+14:01', '2025-03-09T02:30', [], false, 0] as $index => $value) {
        $actual = Publication::visibility('posts', array_replace($window, ['expires_at' => $value]), $start);
        $assert($actual['state'] === 'Needs review' && !$actual['visible'] && $actual['problem'] !== null,
            'Invalid saved expiration failed open at case ' . $index . '.');
        $reject(static fn() => Publication::fields('posts', $minimal, ['expires_at' => $value]),
            'An omitted malformed saved expiration was silently cleared at case ' . $index . '.');
    }
    foreach (['2025-06-01T12:30', '2025-06-01T12:29:59'] as $invalidEnd) {
        $reject(static fn() => Publication::fields('posts', $minimal + ['publish_at' => '2025-06-01T12:30', 'expires_at' => $invalidEnd], []),
            'An equal/reversed post publication window was accepted.');
    }
    $fold = $minimal + ['expires_at' => '2025-11-02T01:30:25-06:00'];
    $assert(Publication::fields('posts', $minimal, $fold)['expires_at'] === $fold['expires_at'], 'An omitted expiration lost its original offset.');
    $assert(Publication::fields('posts', $minimal + ['expires_at' => '2025-11-02T01:30:25'], $fold)['expires_at'] === $fold['expires_at'],
        'Reopening an unchanged daylight-saving fold shifted the expiration instant.');

    date_default_timezone_set('UTC');
    $store = new MarketingStore($root);
    $second = new MarketingStore($root);
    $file = $root . '/storage/private/marketing/data.json';
    $store->update(static function (array &$data): void {
        $data['owner_unrelated'] = ['preserve' => ['nested' => true]];
        $data['settings']['campaign_delivery_enabled'] = false;
    });
    $contact = $store->subscribe('post-expiry-fixture@example.test', 'Fixture', 'expiry-test', true);
    $store->confirm($contact['token']);
    $segment = $store->saveSegment(['name' => 'Expiry fixture', 'enabled' => true, 'rules' => ['sources' => ['expiry-test']]]);
    $campaign = $store->upsert('campaigns', ['subject' => 'Frozen expiry fixture campaign', 'body' => 'Fixture text.',
        'audience' => ['mode' => 'segment', 'segment_id' => $segment['id'], 'segment_revision' => $segment['revision']]]);
    $store->queueCampaign($campaign['id']);
    $store->recordEvent('page_view', ['path' => '/expiry-fixture/']);
    $preserved = array_intersect_key($store->all(), array_flip(['contacts', 'consents', 'campaigns', 'campaign_queue', 'segments',
        'settings', 'events', 'owner_unrelated', 'links', 'referral_codes']));
    $local = static fn(int $time): string => gmdate('Y-m-d\TH:i:s', $time);
    $now = time();
    $past = $local($now - 86400);
    $pastEnd = $local($now - 3600);
    $future = $local($now + 86400);
    $later = $local($now + 172800);
    $edit = static function (string $id, array $changes, ?callable $authorize = null) use ($store): array {
        $current = $store->publicationRecord('posts', $id);
        return $store->savePublication('posts', ['id' => $id, 'expected_revision' => $current['revision']] + $changes, $authorize);
    };

    $legacy = $store->savePublication('posts', ['title' => 'Legacy open post', 'slug' => 'expiry-legacy', 'body' => 'Original preserved text.',
        'status' => 'published', 'publish_at' => $past]);
    $assert(!array_key_exists('expires_at', $legacy) && !array_key_exists('expires_at', History::snapshot('posts', $legacy)),
        'New optional expiration rewrote a legacy post or history snapshot.');
    $legacy = $edit($legacy['id'], ['title' => 'Legacy open post edited']);
    $assert(!array_key_exists('expires_at', $legacy), 'An unrelated old-form edit created expiration.');
    $nullable = $store->upsert('posts', ['title' => 'Legacy null expiration', 'slug' => 'expiry-null', 'status' => 'published', 'expires_at' => null]);
    $nullable = $edit($nullable['id'], ['title' => 'Legacy null expiration edited']);
    $assert(array_key_exists('expires_at', $nullable) && $nullable['expires_at'] === null,
        'An older form rewrote the saved optional null expiration.');
    $dated = $edit($legacy['id'], ['expires_at' => $future]);
    $initialHistory = $store->publicationHistory('posts', $dated['id']);
    $oldWithoutExpiry = $initialHistory['entries'][0]['id'];
    $assert($dated['expires_at'] === $future . '+00:00' && $dated['visibility']['state'] === 'Active',
        'Saved expiry did not retain its normalized UTC value and active state.');
    $assert($second->publicationRecord('posts', $dated['id']) === $dated, 'A fresh read lost the expiration value.');
    $assert(!array_key_exists('expires_at', $store->publicationHistory('posts', $dated['id'], $oldWithoutExpiry)['selected']['snapshot']),
        'Adding expiration rewrote the preceding historical absence.');
    $old = $dated;
    $dated = $edit($dated['id'], ['expires_at' => $later]);
    $oldWithExpiry = $store->publicationHistory('posts', $dated['id'])['entries'][0]['id'];
    $assert($dated['revision'] !== $old['revision'], 'An expiration-only change did not invalidate stale forms.');
    $reject(fn() => $store->savePublication('posts', ['id' => $old['id'], 'expected_revision' => $old['revision'], 'expires_at' => '']), 'Stale save cleared newer expiration.', $file);
    $reject(fn() => $store->setPublicationEnabled('posts', $old['id'], false, $old['revision']), 'Stale visibility change bypassed an expiration edit.', $file);
    $reject(fn() => $store->deletePublication('posts', $old['id'], $old['revision']), 'Stale delete bypassed an expiration edit.', $file);
    $reject(fn() => $store->restorePublication('posts', $old['id'], $oldWithoutExpiry, $old['revision']), 'Stale restore bypassed an expiration edit.', $file);
    $dated = $edit($dated['id'], ['title' => 'Older form preserving expiration']);
    $assert($dated['expires_at'] === $later . '+00:00', 'An older form omitted and cleared a saved expiration.');
    $beforeHistory = $store->publicationHistory('posts', $dated['id'])['entries'];
    $dated = $edit($dated['id'], ['expires_at' => Publication::dateForForm($dated['expires_at'])]);
    $assert($store->publicationHistory('posts', $dated['id'])['entries'] === $beforeHistory, 'An unchanged expiry added spurious history.');
    date_default_timezone_set('America/Chicago');
    foreach ($badDates as $index => $bad) {
        $reject(fn() => $edit($dated['id'], ['expires_at' => $bad]), 'Invalid saved expiry input changed the store at case ' . $index . '.', $file);
    }
    date_default_timezone_set('UTC');
    foreach ([$past, $local($now - 172800)] as $badEnd) {
        $reject(fn() => $edit($dated['id'], ['expires_at' => $badEnd]), 'Equal/reversed expiry changed the saved post.', $file);
    }

    $policies = ['public' => ['version' => 1, 'mode' => 'public', 'plan_ids' => []],
        'members' => ['version' => 1, 'mode' => 'members', 'plan_ids' => []],
        'tiers' => ['version' => 1, 'mode' => 'tiers', 'plan_ids' => ['gold']]];
    $viewers = ['public' => null, 'members' => ['member' => true, 'plan_ids' => [], 'available_plan_ids' => ['gold']],
        'tiers' => ['member' => true, 'plan_ids' => ['gold'], 'available_plan_ids' => ['gold']]];
    $expiredIds = [];
    foreach ($policies as $key => $policy) {
        $expired = $store->savePublication('posts', ['title' => 'Expired ' . $key, 'slug' => 'expiry-ended-' . $key,
            'body' => 'Expired body ' . $key, 'status' => 'published', 'publish_at' => $past, 'expires_at' => $pastEnd, 'access' => $policy]);
        $active = $store->savePublication('posts', ['title' => 'Active ' . $key, 'slug' => 'expiry-active-' . $key,
            'status' => 'published', 'publish_at' => $past, 'expires_at' => $future, 'access' => $policy]);
        $source = $store->savePublication('posts', ['title' => 'Related source ' . $key, 'slug' => 'expiry-related-' . $key,
            'status' => 'published', 'publish_at' => $past, 'expires_at' => $future, 'access' => $policy,
            'related_post_ids' => [$expired['id'], $active['id']]]);
        $expiredIds[] = $expired['id'];
        $beforeReads = file_get_contents($file);
        foreach ($viewers as $viewerName => $viewer) {
            $assert($store->findPost($expired['slug'], $viewer) === null, 'Expired ' . $key . ' post remained directly visible to ' . $viewerName . '.');
            $assert(!in_array($expired['id'], array_column($store->publishedPosts(null, $viewer), 'id'), true),
                'Expired ' . $key . ' post remained listed for ' . $viewerName . '.');
            $assert(!in_array($expired['id'], array_column($store->relatedPosts($source['id'], $viewer), 'id'), true),
                'Expired related metadata remained visible to ' . $viewerName . '.');
        }
        $assert(array_column($store->relatedPosts($source['id'], $viewers[$key]), 'id') === [$active['id']],
            'An expired related target hid an eligible live target or changed its order.');
        $savedPreview = MarketingStore::previewPublication('posts', $expired['id'], $root);
        $assert($savedPreview['visibility']['state'] === 'Expired' && $savedPreview['status'] === 'published'
            && $savedPreview['body'] === 'Expired body ' . $key, 'Private saved preview lost expired content or mutated its saved status.');
        $assert(array_column(MarketingStore::previewRelatedPosts($source['id'], $root), 'id') === [$expired['id'], $active['id']],
            'Private saved preview hid an intentionally saved expired related post.');
        $assert(file_get_contents($file) === $beforeReads, 'Expiry/public/private reads changed stored records, queues or history.');
        $source = $edit($source['id'], ['expires_at' => $pastEnd]);
        $assert($store->relatedPosts($source['id'], $viewers[$key]) === [], 'An expired source still disclosed related-post metadata.');
        $withdrawn = $store->setPublicationEnabled('posts', $expired['id'], false, $expired['revision']);
        $assert($withdrawn['visibility']['state'] === 'Draft' && $withdrawn['expires_at'] === $expired['expires_at'],
            'Moving expired content to draft discarded its expiration.');
        $republished = $store->setPublicationEnabled('posts', $expired['id'], true, $withdrawn['revision']);
        $assert($republished['visibility']['state'] === 'Expired' && $store->findPost($expired['slug'], $viewers[$key]) === null,
            'Publish bypassed an existing past expiration.');
    }

    $held = $store->upsert('posts', ['title' => 'Held malformed expiry', 'slug' => 'expiry-held', 'body' => 'Preserve held content',
        'status' => 'published', 'expires_at' => 'not-a-date', 'owner_unknown' => ['preserve' => true]]);
    $held = $store->publicationRecord('posts', $held['id']);
    $assert($held['visibility']['state'] === 'Needs review' && $store->findPost('expiry-held') === null, 'Malformed legacy expiry became public.');
    $held = $store->setPublicationEnabled('posts', $held['id'], false, $held['revision']);
    $assert($held['expires_at'] === 'not-a-date' && $held['body'] === 'Preserve held content' && $held['owner_unknown'] === ['preserve' => true],
        'Safe withdrawal erased malformed expiry, content or unrelated fields.');
    $reject(fn() => $store->setPublicationEnabled('posts', $held['id'], true, $held['revision']), 'Malformed expiry was republished without repair.', $file);
    $held = $edit($held['id'], ['expires_at' => '']);
    $assert($held['expires_at'] === '' && $held['visibility']['state'] === 'Draft', 'Explicit blank failed to repair malformed expiry safely.');

    $current = $store->publicationRecord('posts', $dated['id']);
    $review = $store->publicationHistory('posts', $current['id'], $oldWithoutExpiry);
    $projected = History::project('posts', $current, $review['selected']);
    $assert(!array_key_exists('expires_at', $projected), 'Reviewing a historical absence inherited a newer expiry.');
    $restored = $store->restorePublication('posts', $current['id'], $oldWithExpiry, $current['revision']);
    $assert($restored['expires_at'] === $future . '+00:00' && $restored['status'] === 'draft',
        'Restoring saved expiration lost its original instant or published the post.');
    $restored = $store->restorePublication('posts', $restored['id'], $oldWithoutExpiry, $restored['revision']);
    $assert(!array_key_exists('expires_at', $restored) && $restored['status'] === 'draft',
        'Restoring a legacy version retained newer expiry or published the post.');
    $assert(!array_key_exists('expires_at', $second->publicationRecord('posts', $restored['id'])), 'Restored absence did not persist on reload.');
    $undoEntry = $store->publicationHistory('posts', $restored['id'])['entries'][0]['id'];
    $restored = $store->restorePublication('posts', $restored['id'], $undoEntry, $restored['revision']);
    $assert($restored['expires_at'] === $future . '+00:00' && $restored['status'] === 'draft', 'Restore did not capture recoverable prior expiration.');

    date_default_timezone_set('America/Chicago');
    $historicalLocal = $store->upsert('posts', ['title' => 'Historical local expiry', 'slug' => 'expiry-local-history', 'status' => 'published',
        'publish_at' => '2025-06-30 12:00:00', 'expires_at' => '2025-07-01 12:00:25']);
    $historicalLocal = $edit($historicalLocal['id'], ['title' => 'Local expiry edited']);
    $localEntry = $store->publicationHistory('posts', $historicalLocal['id'])['entries'][0]['id'];
    date_default_timezone_set('Asia/Tokyo');
    $historicalLocal = $store->publicationRecord('posts', $historicalLocal['id']);
    $historicalLocal = $store->restorePublication('posts', $historicalLocal['id'], $localEntry, $historicalLocal['revision']);
    $assert($historicalLocal['expires_at'] === '2025-07-01T17:00:25+00:00'
        && Publication::dateForForm($historicalLocal['expires_at']) === '2025-07-02T02:00:25'
        && $historicalLocal['status'] === 'draft', 'History restored expiration in the current timezone instead of its captured timezone.');
    date_default_timezone_set('UTC');

    $authorizationCount = 0;
    $denyOwner = static function (array $candidate) use ($assert, $root, &$authorizationCount): void {
        $authorizationCount++;
        $assert(isset($candidate['id'], $candidate['edit_version']) && !isset($candidate['revision']), 'Expiry authorization did not receive the final candidate.');
        $probe = fopen($root . '/storage/private/marketing/data.lock', 'rb');
        $obtained = flock($probe, LOCK_EX | LOCK_NB);
        if ($obtained) flock($probe, LOCK_UN);
        fclose($probe);
        $assert(!$obtained, 'Expiry authorization ran outside the saved-data lock.');
        throw new RuntimeException('Owner access changed.', 403);
    };
    $restored = $store->publicationRecord('posts', $restored['id']);
    $reject(fn() => $edit($restored['id'], ['expires_at' => ''], $denyOwner), 'A revoked owner cleared expiration.', $file);
    $reject(fn() => $store->setPublicationEnabled('posts', $restored['id'], true, $restored['revision'], $denyOwner), 'A revoked owner republished expired content.', $file);
    $reject(fn() => $store->restorePublication('posts', $restored['id'], $oldWithoutExpiry, $restored['revision'], $denyOwner), 'A revoked owner restored an earlier expiry.', $file);
    $assert($authorizationCount === 3, 'An expiration mutation skipped its final saved-owner callback.');
    date_default_timezone_set('America/Chicago');
    $reject(fn() => $store->savePublication('posts', ['id' => $restored['id'], 'expected_revision' => $restored['revision'], 'expires_at' => '']),
        'A form opened under another timezone changed expiration.', $file);
    date_default_timezone_set('UTC');
    $cleared = $edit($restored['id'], ['expires_at' => '']);
    $assert($cleared['expires_at'] === '' && $cleared['status'] === 'draft', 'Clearing expiry accidentally published the post.');
    $assert(array_intersect_key($store->all(), $preserved) === $preserved,
        'Expiration operations changed recipients, consent, suppression, frozen campaigns, events or unrelated state.');
} finally {
    date_default_timezone_set($timezone);
    if (is_file($root . '/fixture.marker') && file_get_contents($root . '/fixture.marker') === 'post-expiry-source-test') {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $path) $path->isDir() && !$path->isLink() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        rmdir($root);
    }
}
if ($failures !== []) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo 'Post expiration source acceptance passed (' . $assertions . " assertions).\n";

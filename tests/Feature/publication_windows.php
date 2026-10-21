<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/autoload.php';
require_once dirname(__DIR__, 2) . '/app/Support/functions.php';

use Velvet\Core\MarketingPublication as Publication;
use Velvet\Core\MarketingStore;

require dirname(__DIR__, 2) . '/app/Core/MarketingStore.php';
require dirname(__DIR__, 2) . '/includes/marketing_admin.php';

// Only a marked disposable store. No transport, worker or external message.
$root = sys_get_temp_dir() . '/vvx-publication-source-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700) || file_put_contents($root . '/fixture.marker', 'publication-source-test') === false) throw new RuntimeException('Fixture creation failed.');
$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) $failures[] = $message;
};
$reject = static function (callable $action, string $message, ?string $file = null) use ($assert): void {
    $before = $file === null ? null : file_get_contents($file);
    $caught = false;
    try { $action(); } catch (InvalidArgumentException|RuntimeException) { $caught = true; }
    $assert($caught, $message);
    if ($file !== null) $assert(file_get_contents($file) === $before, $message . ' Saved data changed on rejection.');
};
$timezone = date_default_timezone_get();
try {
    date_default_timezone_set('America/New_York');
    $assert(Publication::formDate('2024-06-01T12:30') === '2024-06-01T16:30:00+00:00', 'New dates were not interpreted in the site timezone.');
    $assert(Publication::dateForForm('2024-06-01T16:30:25Z') === '2024-06-01T12:30:25', 'Saved UTC date did not reopen in local time with seconds.');
    $assert(Publication::formDate('2024-01-01T12:30:25') === '2024-01-01T17:30:25+00:00', 'Winter timezone offset was not applied.');
    foreach (['2024-03-10T02:30', '2024-02-30T12:00', '2023-02-29T12:00', '2024-06-01T24:00', '2024-06-01T12:60',
        '2024-06-01T12:30:60', '2024-06-01T12:30Z', 'next Tuesday', '2024-06-01', '2024-06-01 12:30',
        "2024-06-01T12:30\n", "\t2024-06-01T12:30", '  ', [], 0, false] as $bad) {
        $reject(static fn() => Publication::formDate($bad), 'Malformed or normalized owner date was accepted: ' . json_encode($bad));
    }
    $assert(Publication::formDate('') === null && Publication::formDate(null) === null, 'An explicit blank bound did not remain open.');
    $sameInstant = '2024-06-01T16:30:00+00:00';
    foreach (['2024-06-01T16:30Z', '2024-06-01T16:30:00Z', $sameInstant, '2024-06-01T18:30:00+02:00',
        '2024-06-01T12:30', '2024-06-01T12:30:00', '2024-06-01 12:30', '2024-06-01 12:30:00'] as $valid) {
        $assert(Publication::storedDate($valid)?->getTimestamp() === strtotime($sameInstant), 'A valid legacy absolute/local format changed its instant: ' . $valid);
    }
    $assert(Publication::storedDate('2024-06-01')?->format('c') === '2024-06-01T00:00:00-04:00', 'Legacy date-only start was not local midnight.');
    foreach (['tomorrow', '2024-02-30T12:00:00Z', '2024-06-01T12:00:00+14:01', '2024-06-01T12:00:00+15:00',
        '2024-06-01T12:00:00+01:99', '2024-06-01T12:00:00Z junk', "2024-06-01T12:00:00Z\r", '0000-01-01', [], true] as $bad) {
        $reject(static fn() => Publication::storedDate($bad), 'Invalid nonempty legacy date was accepted: ' . json_encode($bad));
    }
    $start = strtotime('2024-06-01T16:30:00Z');
    $end = $start + 60;
    foreach (['banners', 'landing_pages'] as $collection) {
        $window = ['enabled' => true, 'starts_at' => $sameInstant, 'ends_at' => '2024-06-01T16:31:00Z'];
        foreach ([[$start - 1, 'Scheduled', false], [$start, 'Active', true], [$end - 1, 'Active', true], [$end, 'Ended', false], [$end + 1, 'Ended', false]] as [$now, $status, $visible]) {
            $state = Publication::visibility($collection, $window, $now);
            $assert($state['state'] === $status && $state['visible'] === $visible, $collection . ' crossed a before/during/after boundary.');
        }
        $assert(!Publication::visibility($collection, array_replace($window, ['enabled' => false]), $start)['visible'], 'Disabled window became visible.');
        foreach (['starts_at', 'ends_at'] as $field) {
            $assert(Publication::visibility($collection, array_replace($window, [$field => 'bad-date']), $start)['state'] === 'Needs review', 'Invalid saved bound failed open.');
        }
        $assert(!Publication::visibility($collection, array_replace($window, ['ends_at' => $sameInstant]), $start)['visible'], 'Equal bounds became public.');
        $assert(!Publication::visibility($collection, array_replace($window, ['ends_at' => '2024-05-31']), $start)['visible'], 'Reversed bounds became public.');
        $assert(Publication::visibility($collection, ['enabled' => true], $start)['visible'], 'An intentionally open window stopped working.');
    }
    $assert(Publication::visibility('posts', ['status' => 'published', 'publish_at' => $sameInstant], $start - 1)['state'] === 'Scheduled', 'Future post is not scheduled.');
    $assert(Publication::visibility('posts', ['status' => 'published', 'publish_at' => $sameInstant], $start)['visible'], 'Post did not publish at its exact start.');
    $assert(!Publication::visibility('posts', ['status' => 'draft', 'publish_at' => $sameInstant], $start + 1)['visible'], 'Draft post became public after its date.');
    $assert(Publication::visibility('posts', ['status' => 'published', 'publish_at' => ''], $start)['visible'], 'Explicit blank publication date is not immediate.');
    $assert(!Publication::visibility('posts', ['status' => 'published', 'publish_at' => 'not-a-date'], $start)['visible'], 'Invalid published post failed open.');

    $store = new MarketingStore($root);
    $file = $root . '/storage/private/marketing/data.json';
    $second = new MarketingStore($root);
    $store->update(static function (array &$data): void {
        $data['owner_unrelated'] = ['nested' => ['preserve' => true]];
        $data['settings']['campaign_delivery_enabled'] = false;
    });
    $subscriber = $store->subscribe('publication-fixture@example.test', 'Fixture', 'publication-test', true);
    $store->confirm($subscriber['token']);
    $segment = $store->saveSegment(['name' => 'Publication fixture audience', 'enabled' => true,
        'rules' => ['sources' => ['publication-test']]]);
    $campaign = $store->upsert('campaigns', ['subject' => 'Frozen fixture campaign', 'body' => 'Fixture text.',
        'audience' => ['mode' => 'segment', 'segment_id' => $segment['id'], 'segment_revision' => $segment['revision']]]);
    $store->queueCampaign($campaign['id']);
    $preserved = array_intersect_key($store->all(), array_flip(['contacts', 'consents', 'campaigns', 'campaign_queue', 'segments', 'settings', 'owner_unrelated', 'links', 'referral_codes']));

    $post = $store->savePublication('posts', ['title' => 'Stable public post', 'slug' => 'stable-post', 'body' => "First line.\n\nSecond line.  ", 'status' => 'published', 'publish_at' => '2024-06-01T12:30']);
    $assert($post['id'] !== '' && strlen($post['revision']) === 64 && $post['body'] === "First line.\n\nSecond line.  ", 'Post identity or multiline content changed.');
    $assert($second->publicationRecord('posts', $post['id']) === $post, 'A fresh owner read did not retain the complete post.');
    $assert($store->findPost('stable-post')['id'] === $post['id'], 'Published post permalink did not resolve.');
    $store->update(static function (array &$data) use ($post): void {
        foreach ($data['posts'] as &$record) if ($record['id'] === $post['id']) $record['owner_unknown'] = ['retain' => true];
    });
    $post = $store->publicationRecord('posts', $post['id']);
    $old = $post;
    $store->recordEvent('page_view', ['path' => '/blog/']);
    $post = $second->savePublication('posts', ['id' => $post['id'], 'expected_revision' => $post['revision'], 'title' => 'Edited title', 'status' => 'draft']);
    $assert($post['id'] === $old['id'] && $post['slug'] === 'stable-post' && $post['body'] === $old['body'] && $post['owner_unknown'] === ['retain' => true], 'An edit replaced identity, permalink, omitted content or unknown fields.');
    $assert($post['revision'] !== $old['revision'] && $store->findPost('stable-post') === null, 'Draft change did not revise and withdraw the post.');
    $reject(fn() => $store->savePublication('posts', ['id' => $old['id'], 'expected_revision' => $old['revision'], 'title' => 'Stale overwrite']), 'A stale editor overwrote a newer post.', $file);
    $reject(fn() => $store->setPublicationEnabled('posts', $old['id'], true, $old['revision']), 'A stale toggle published a newer post.', $file);
    $reject(fn() => $store->deletePublication('posts', $old['id'], $old['revision']), 'A stale delete removed a newer post.', $file);
    $reject(fn() => $store->savePublication('posts', ['id' => $post['id'], 'title' => 'Missing revision']), 'An existing record accepted no revision.', $file);
    $reject(fn() => $store->savePublication('posts', ['id' => 'invented-post', 'expected_revision' => $post['revision'], 'title' => 'Injected identity']), 'An unknown supplied ID created a record.', $file);
    $reject(fn() => $store->savePublication('posts', ['id' => $post['id'], 'expected_revision' => $post['revision'], 'slug' => 'broken-permalink']), 'A saved permalink changed.', $file);
    $reject(fn() => $store->savePublication('posts', ['title' => 'Duplicate address', 'slug' => 'stable-post']), 'A new duplicate permalink was accepted.', $file);
    $post = $store->setPublicationEnabled('posts', $post['id'], true, $post['revision']);
    $assert($store->findPost('stable-post')['title'] === 'Edited title', 'Republishing did not preserve the edited permalink/content.');
    date_default_timezone_set('America/Chicago');
    $reject(fn() => $store->savePublication('posts', ['id' => $post['id'], 'expected_revision' => $post['revision'], 'publish_at' => '2024-06-01T12:30']), 'A form opened under another site timezone reinterpreted its date.', $file);
    date_default_timezone_set('America/New_York');

    $banner = $store->savePublication('banners', ['text' => 'First promotion', 'url' => '/membership/?fixture=1', 'enabled' => true]);
    $nextBanner = $store->savePublication('banners', ['text' => 'Second promotion', 'url' => '', 'enabled' => true]);
    $assert(array_column($store->activeBanners(), 'id') === [$banner['id'], $nextBanner['id']], 'Saved banner precedence changed.');
    $banner = $second->savePublication('banners', ['id' => $banner['id'], 'expected_revision' => $banner['revision'], 'text' => 'First promotion edited']);
    $assert(array_column($store->activeBanners(), 'id') === [$banner['id'], $nextBanner['id']], 'Editing a banner moved its precedence.');
    $banner = $store->setPublicationEnabled('banners', $banner['id'], false, $banner['revision']);
    $assert(array_column($store->activeBanners(), 'id') === [$nextBanner['id']], 'Disabling the shown banner did not reveal the next active one.');
    $banner = $store->setPublicationEnabled('banners', $banner['id'], true, $banner['revision']);
    $assert($store->activeBanners()[0]['id'] === $banner['id'], 'Reenabling a banner changed its saved precedence.');

    $page = $store->savePublication('landing_pages', ['title' => 'Stable landing page', 'slug' => 'stable-page', 'body' => 'Landing content', 'enabled' => true, 'cta_url' => '/membership/']);
    $other = $second->publicationRecord('posts', $post['id']);
    $page = $store->savePublication('landing_pages', ['id' => $page['id'], 'expected_revision' => $page['revision'], 'body' => "Revised page.\nSecond line.", 'starts_at' => '2024-01-01T00:00', 'ends_at' => '2030-01-01T00:00']);
    $post = $second->savePublication('posts', ['id' => $other['id'], 'expected_revision' => $other['revision'], 'excerpt' => 'Independent record edit']);
    $assert($store->activeLandingPage('stable-page')['body'] === "Revised page.\nSecond line." && $store->findPost('stable-post')['excerpt'] === 'Independent record edit', 'Disjoint edits lost fresh data or incorrectly required a global revision.');
    $assert($page['starts_at'] === '2024-01-01T05:00:00+00:00' && $page['ends_at'] === '2030-01-01T05:00:00+00:00', 'Window dates were not stored as site-timezone UTC instants.');
    $page = $store->setPublicationEnabled('landing_pages', $page['id'], false, $page['revision']);
    $assert($store->activeLandingPage('stable-page') === null && $page['body'] === "Revised page.\nSecond line.", 'Page disable lost content or did not withdraw its permalink.');
    foreach (['title' => [], 'body' => str_repeat('x', 100001), 'cta_url' => 'javascript:alert(1)', 'enabled' => 'yes', 'starts_at' => 'tomorrow', 'ends_at' => '2023-01-01T00:00'] as $key => $bad) {
        $reject(fn() => $store->savePublication('landing_pages', ['id' => $page['id'], 'expected_revision' => $page['revision'], $key => $bad]), 'Invalid ' . $key . ' was accepted.', $file);
    }
    $reject(fn() => $store->savePublication('landing_pages', ['id' => $page['id'], 'expected_revision' => $page['revision'], 'ends_at' => '2024-01-01T00:00']), 'An equal window was saved.', $file);
    $reject(fn() => $store->savePublication('contacts', ['title' => 'Wrong collection']), 'Publication editor modified another collection.', $file);
    $reopened = $store->publicationRecord('landing_pages', $page['id']);
    $page = $store->savePublication('landing_pages', ['id' => $page['id'], 'expected_revision' => $reopened['revision'],
        'contacts' => [], 'campaign_queue' => [], 'audience_snapshot' => [], 'created_at' => 'tampered', 'owner_unknown' => 'injected']);
    $assert(!array_key_exists('audience_snapshot', $page) && !array_key_exists('owner_unknown', $page) && $page['created_at'] === $reopened['created_at'], 'Owner input injected arbitrary record metadata.');

    // Existing malformed records remain editable/withdrawable and never public.
    $invalidPost = $store->upsert('posts', ['title' => 'Invalid legacy post', 'slug' => 'invalid-post', 'status' => 'published', 'publish_at' => 'not-a-date', 'body' => 'Preserve me']);
    $invalidBanner = $store->upsert('banners', ['text' => 'Invalid legacy banner', 'enabled' => true, 'starts_at' => 'bad-start', 'ends_at' => 'bad-end']);
    $invalidPage = $store->upsert('landing_pages', ['title' => 'Invalid legacy page', 'slug' => 'invalid-page', 'enabled' => true, 'ends_at' => 'not-a-date']);
    $beforeReads = file_get_contents($file);
    $assert($store->findPost('invalid-post') === null && $store->activeLandingPage('invalid-page') === null && !in_array($invalidBanner['id'], array_column($store->activeBanners(), 'id'), true), 'Malformed legacy content was public.');
    $assert(file_get_contents($file) === $beforeReads, 'Public visibility reads changed stored status or queue data.');
    foreach (['posts' => $invalidPost, 'banners' => $invalidBanner, 'landing_pages' => $invalidPage] as $collection => $legacy) {
        $described = $store->publicationRecord($collection, $legacy['id']);
        $assert($described['visibility']['state'] === 'Needs review', 'Owner did not see malformed legacy dates.');
        $withdrawn = $store->setPublicationEnabled($collection, $legacy['id'], false, $described['revision']);
        foreach (['publish_at', 'starts_at', 'ends_at', 'body'] as $field) {
            if (array_key_exists($field, $legacy)) $assert($withdrawn[$field] === $legacy[$field], 'Withdrawal silently cleared malformed dates/content.');
        }
        $reject(fn() => $store->setPublicationEnabled($collection, $legacy['id'], true, $withdrawn['revision']), 'Invalid legacy content was enabled without repair.', $file);
    }
    $legacy = $store->upsert('posts', ['title' => 'DST fold', 'slug' => 'dst-fold', 'body' => 'Offset content', 'status' => 'published', 'publish_at' => '2024-11-03T01:30:00-05:00']);
    $legacy = $store->publicationRecord('posts', $legacy['id']);
    $legacy = $store->savePublication('posts', ['id' => $legacy['id'], 'expected_revision' => $legacy['revision'], 'title' => 'DST fold edited', 'publish_at' => Publication::dateForForm($legacy['publish_at'])]);
    $assert($legacy['publish_at'] === '2024-11-03T01:30:00-05:00', 'Reopening an ambiguous local time shifted its saved instant.');
    $blankPost = $store->savePublication('posts', ['title' => 'Immediate post', 'slug' => 'blank-date-post', 'status' => 'published', 'publish_at' => '']);
    $assert($store->findPost('blank-date-post')['id'] === $blankPost['id'] && Publication::storedDate($blankPost['publish_at']) === null, 'Blank immediate date became an epoch timestamp.');
    $store->deletePublication('posts', $blankPost['id'], $blankPost['revision']);
    $assert($store->findPost('blank-date-post') === null && $store->publicationRecord('posts', $blankPost['id']) === null, 'Revision-checked post deletion did not remove the exact record.');
    $assert(array_intersect_key($store->all(), $preserved) === $preserved, 'Publication editing mutated recipients, consent, frozen queue, settings or unrelated modules.');
    $assert(count($store->all()['events']) === 1, 'A fresh event was lost during publication editing.');
    $assert(mm_publication_input('posts', ['id' => $post['id'], 'publication_revision' => $post['revision'], 'title' => 'Form', 'campaigns' => []]) === ['title' => 'Form', 'id' => $post['id'], 'expected_revision' => $post['revision']], 'Owner form allowed unrelated inputs or lost its revision.');
} finally {
    date_default_timezone_set($timezone);
    if (is_file($root . '/fixture.marker') && file_get_contents($root . '/fixture.marker') === 'publication-source-test') {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $path) $path->isDir() && !$path->isLink() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        rmdir($root);
    }
}
if ($failures !== []) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo 'Publication window source acceptance passed (' . $assertions . " assertions).\n";

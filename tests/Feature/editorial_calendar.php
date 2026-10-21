<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Store;
use Velvet\Core\MarketingCalendar as Calendar;

$source = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/vvx-calendar-source-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700) || file_put_contents($root . '/calendar.fixture', 'calendar-source-test') === false) {
    throw new RuntimeException('The disposable calendar fixture could not be created.');
}
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
require_once $source . '/app/Support/functions.php';
require_once $source . '/app/Core/MarketingCalendar.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$reject = static function (callable $operation, string $message) use ($assert): void {
    $caught = false;
    try { $operation(); } catch (InvalidArgumentException) { $caught = true; }
    $assert($caught, $message);
};
$tree = static function (string $directory): array {
    $result = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($walk as $entry) $result[substr($entry->getPathname(), strlen($directory) + 1)] = $entry->isDir() ? 'directory' : hash_file('sha256', $entry->getPathname());
    ksort($result);
    return $result;
};
$matching = static fn(array $rows, string $id): array => array_values(array_filter($rows, static fn(array $row): bool => $row['source_id'] === $id));
$state = static function (array $result, string $id) use ($matching): string {
    $rows = $matching([...$result['events'], ...$result['undated']], $id);
    return $rows[0]['state'] ?? '';
};
$originalZone = date_default_timezone_get();
try {
    date_default_timezone_set('America/Chicago');
    $now = new DateTimeImmutable('2030-02-10T12:00:00-06:00');
    $empty = Calendar::build([], [], [], null, null, $now);
    $assert($empty['month'] === '2030-02' && $empty['today'] === '2030-02-10'
        && $empty['timezone'] === 'America/Chicago' && $empty['type'] === 'all', 'Default selection did not use the site timezone.');
    $assert($empty['events'] === [] && $empty['undated'] === [] && count($empty['types']) === 8, 'An empty source invented saved calendar content.');
    foreach ([null, false, 203002, [], ['2030-02'], '', '2030-2', '2030-02 ', "2030-02\n", '1969-12', '9999-01', '2030-00', '2030-13', '../2030-02'] as $bad) {
        if ($bad === null) continue;
        $reject(static fn() => Calendar::build([], [], [], $bad, 'all', $now), 'Malformed calendar month was accepted.');
    }
    foreach ([false, 1, [], '', 'ALL', 'posts ', 'post', 'contacts', 'javascript:alert(1)'] as $bad) {
        $reject(static fn() => Calendar::build([], [], [], '2030-02', $bad, $now), 'Malformed calendar filter was accepted.');
    }
    foreach (['1970-01', '2028-02', '9998-12'] as $month) {
        $assert(Calendar::build([], [], [], $month, 'all', $now)['month'] === $month, 'A supported month boundary was rejected.');
    }
    $shifted = Calendar::build([], [], [], null, null, new DateTimeImmutable('2030-03-01T05:59:59Z'));
    $assert($shifted['month'] === '2030-02' && $shifted['today'] === '2030-02-28', 'UTC now crossed a site-local month too early.');

    $post = ['id' => 'post-main', 'title' => '<img data-injection="title"> & café', 'status' => 'published',
        'publish_at' => '2030-02-02T18:00:00Z', 'expires_at' => '2030-02-12T18:00:00Z',
        'body' => 'PRIVATE_CALENDAR_BODY', 'excerpt' => 'PRIVATE_CALENDAR_EXCERPT',
        'seo_title' => 'PRIVATE_CALENDAR_SEO', 'author_name' => 'PRIVATE_CALENDAR_AUTHOR',
        'history' => ['PRIVATE_CALENDAR_HISTORY'], 'unknown' => ['PRIVATE_CALENDAR_UNKNOWN']];
    $marketing = ['posts' => [$post, ['id' => 'post-undated', 'title' => 'Undated draft', 'status' => 'draft'],
        array_replace($post, ['id' => 'post-invalid', 'expires_at' => ['PRIVATE_CALENDAR_BAD_DATE']])],
        'banners' => [['id' => 'banner-main', 'text' => 'Banner', 'enabled' => true, 'starts_at' => '2030-02-03T12:00:00', 'ends_at' => '2030-02-13T12:00:00']],
        'landing_pages' => [['id' => 'page-main', 'title' => 'Landing page', 'enabled' => false, 'starts_at' => '2030-02-04T12:00:00', 'ends_at' => '2030-02-14T12:00:00', 'body' => 'PRIVATE_CALENDAR_PAGE']],
        'campaigns' => [['id' => 'campaign-draft', 'subject' => 'Draft email', 'status' => 'draft', 'scheduled_at' => '2030-02-07T18:00:00Z',
            'body' => 'PRIVATE_CALENDAR_EMAIL', 'preheader' => 'PRIVATE_CALENDAR_PREHEADER', 'delivery_snapshot' => ['body' => 'PRIVATE_CALENDAR_FROZEN']],
            ['id' => 'campaign-due', 'subject' => 'Due email', 'status' => 'queued', 'scheduled_at' => '2030-02-08T18:00:00Z'],
            ['id' => 'campaign-future', 'subject' => 'Future email', 'status' => 'queued', 'scheduled_at' => '2030-02-11T18:00:00Z'],
            ['id' => 'campaign-paused', 'subject' => 'Paused email', 'status' => 'queued', 'delivery_paused' => true, 'scheduled_at' => '2030-02-06T18:00:00Z']],
        'contacts' => [['email' => 'PRIVATE_CALENDAR_CONTACT']], 'deliveries' => [['email' => 'PRIVATE_CALENDAR_RECIPIENT']],
        'consents' => [['token' => 'PRIVATE_CALENDAR_CONSENT']], 'events' => [['address' => 'PRIVATE_CALENDAR_VISIT']]];
    $media = [];
    foreach (['galleries', 'videos', 'albums', 'tracks', 'audioPlaylists'] as $index => $collection) {
        $media[$collection] = [['id' => $collection . '-main', 'title' => $collection, 'status' => 'scheduled',
            'scheduledAt' => '2030-02-' . (15 + $index) . 'T12:00:00', 'publishedAt' => null, 'accessMode' => 'public',
            'description' => 'PRIVATE_CALENDAR_DESCRIPTION', 'items' => ['PRIVATE_CALENDAR_ASSET'], 'lyrics' => 'PRIVATE_CALENDAR_LYRICS']];
    }
    $scenes = ['hero' => ['draft' => true, 'versions' => [['id' => 'hero-v1', 'publishedAt' => '2030-02-20T18:00:00Z', 'scene' => ['PRIVATE_CALENDAR_SCENE']]]],
        'footer' => ['draft' => false, 'versions' => [['id' => 42, 'publishedAt' => '2030-02-21T18:00:00Z', 'scene' => ['PRIVATE_CALENDAR_SCENE']]]]];
    $saved = serialize([$marketing, $media, $scenes]);
    $all = Calendar::build($marketing, $media, $scenes, '2030-02', 'all', $now);
    $assert(serialize([$marketing, $media, $scenes]) === $saved, 'Pure calendar aggregation mutated its inputs.');
    $assert(!str_contains(json_encode($all, JSON_THROW_ON_ERROR), 'PRIVATE_CALENDAR_'), 'The calendar exposed bodies, recipients, scene JSON or other private content.');
    $assert(count(array_unique(array_column($all['events'], 'type'))) === 8, 'The calendar omitted a supported source family.');
    $assert(count($matching($all['events'], 'post-main')) === 2 && count($matching($all['events'], 'banner-main')) === 2
        && count($matching($all['events'], 'page-main')) === 2, 'Publication starts and ends did not have separate events.');
    $assert($state($all, 'post-main') === 'Active' && $state($all, 'banner-main') === 'Active'
        && $state($all, 'page-main') === 'Disabled' && $state($all, 'post-undated') === 'Draft', 'Current publication states were misleading.');
    $assert($state($all, 'post-invalid') === 'Needs review' && $matching($all['events'], 'post-invalid') === [], 'A malformed expiry was presented as a verified event.');
    $assert($matching($all['events'], 'post-main')[0]['title'] === $post['title'], 'Display metadata was interpreted rather than left as plain escaped text.');
    $assert($state($all, 'campaign-draft') === 'Draft' && $state($all, 'campaign-due') === 'Due — awaiting delivery'
        && $state($all, 'campaign-future') === 'Queued' && $state($all, 'campaign-paused') === 'Paused', 'Campaign state confused a plan with delivery.');
    $assert($matching($all['events'], 'campaign-draft')[0]['edit_path'] === 'admin/?page=marketing&tab=campaigns', 'A frozen campaign was offered an ordinary draft edit link.');
    $assert(str_contains(strtolower(implode(' ', $all['notices'])), 'composer scheduling'), 'The scene scheduling boundary was not disclosed.');
    foreach ([...$all['events'], ...$all['undated']] as $row) {
        $assert(count(array_filter($row, static fn(mixed $value): bool => !is_string($value))) === 0, 'Calendar display fields are not all strings.');
        $assert($row['edit_path'] === '' || str_starts_with($row['edit_path'], 'admin/?page='), 'An unverified/external editor destination escaped projection.');
    }
    foreach (array_keys($all['types']) as $type) {
        $filtered = Calendar::build($marketing, $media, $scenes, '2030-02', $type, $now);
        $assert($filtered['events'] !== [] && count(array_filter([...$filtered['events'], ...$filtered['undated']], static fn(array $row): bool => $row['type'] !== $type)) === 0,
            'A type filter omitted its content or leaked another family.');
    }
    $times = array_map(static fn(array $row): int => (new DateTimeImmutable($row['at']))->getTimestamp(), $all['events']);
    $sorted = $times; sort($sorted);
    $assert($times === $sorted && count(array_unique(array_column($all['events'], 'key'))) === count($all['events']), 'Event ordering or identity is unstable.');

    $edges = [];
    foreach (['previous' => '2030-02-01T05:59:59Z', 'first' => '2030-02-01T06:00:00Z',
        'last' => '2030-03-01T05:59:59Z', 'next' => '2030-03-01T06:00:00Z'] as $id => $at) {
        $edges[] = ['id' => $id, 'title' => $id, 'status' => 'published', 'publish_at' => $at];
    }
    $edgeResult = Calendar::build(['posts' => $edges], [], [], '2030-02', 'posts', $now);
    $assert(array_column($edgeResult['events'], 'source_id') === ['first', 'last'], 'Local month boundaries were not inclusive/exclusive.');
    $assert($edgeResult['events'][0]['at'] === '2030-02-01T00:00:00-06:00' && $edgeResult['events'][1]['date'] === '2030-02-28', 'Calendar dates lost their site-local offset.');
    $fold = Calendar::build(['posts' => [
        ['id' => 'fold-second', 'title' => 'Second', 'status' => 'published', 'publish_at' => '2026-11-01T01:30:00-06:00', 'expires_at' => '2026-11-01T02:30:00-06:00'],
        ['id' => 'fold-first', 'title' => 'First', 'status' => 'published', 'publish_at' => '2026-11-01T01:30:00-05:00', 'expires_at' => '2026-11-01T02:00:00-06:00'],
    ]], [], [], '2026-11', 'posts', $now);
    $assert(count($fold['events']) === 4 && $fold['events'][0]['source_id'] === 'fold-first' && $fold['events'][1]['source_id'] === 'fold-second', 'Repeated DST wall times did not sort by actual instant.');
    $assert(strtotime($fold['events'][1]['at']) - strtotime($fold['events'][0]['at']) === 3600, 'Distinct DST fold instants were collapsed.');
    foreach (['2028-02-29T12:00:00' => true, '2030-02-29T12:00:00' => false, '2026-03-08T02:30:00' => false] as $date => $valid) {
        $result = Calendar::build(['posts' => [['id' => 'date-test', 'title' => 'Date', 'status' => 'published', 'publish_at' => $date]]], [], [], substr($date, 0, 7), 'posts', $now);
        $assert(($result['events'] !== []) === $valid && ($valid || $state($result, 'date-test') === 'Needs review'), 'An impossible date or DST gap was normalized silently.');
    }
    foreach (['2030-02-02T17:59:59Z' => 'Scheduled', '2030-02-02T18:00:00Z' => 'Active',
        '2030-02-12T17:59:59Z' => 'Active', '2030-02-12T18:00:00Z' => 'Expired'] as $at => $expected) {
        $result = Calendar::build(['posts' => [$post]], [], [], '2030-02', 'posts', new DateTimeImmutable($at));
        $assert($state($result, 'post-main') === $expected, 'Calendar post visibility differs at an exact publication/expiry boundary.');
    }
    $identities = Calendar::build(['posts' => [
        array_replace($post, ['id' => 'duplicate']), array_replace($post, ['id' => 'duplicate']),
        array_replace($post, ['id' => '../unsafe']), array_replace($post, ['id' => []]),
        array_replace($post, ['id' => 'review-2', 'expires_at' => false]),
    ]], [], [], '2030-02', 'posts', $now);
    $assert($identities['events'] === [] && count($identities['undated']) === 5, 'Ambiguous identities were accepted as verified publications.');
    $assert(count(array_unique(array_column($identities['undated'], 'key'))) === 5, 'Review row identities collide with saved record identifiers.');
    foreach ($identities['undated'] as $row) {
        $assert($row['state'] === 'Needs review' && ($row['source_id'] === 'review-2' || $row['edit_path'] === ''), 'An invalid or duplicate identity retained a misleading editor link.');
    }
    $badRows = Calendar::build(['posts' => false, 'banners' => [['id' => 'bad-enabled', 'enabled' => []]],
        'landing_pages' => [['id' => 'bad-access', 'access' => null]], 'campaigns' => [false]], ['videos' => 'invalid'], ['hero' => ['versions' => false]], '2030-02', 'all', $now);
    $assert($badRows['events'] === [] && count($badRows['undated']) === 6, 'Malformed source structures were dropped or promoted into valid dates.');

    $due = ['id' => 'media-due', 'title' => 'Due media', 'status' => 'scheduled', 'scheduledAt' => '2030-02-09T12:00:00', 'publishedAt' => null];
    foreach ([
        [$due, 'Due — awaiting publication'],
        [array_replace($due, ['publishedAt' => '2030-02-11T12:00:00']), 'Scheduled'],
        [array_replace($due, ['publishedAt' => '2030-02-10T12:00:00', 'scheduledAt' => '2030-02-10T12:00:00']), 'Due — awaiting publication'],
        [array_replace($due, ['scheduledAt' => '2030-02-11T12:00:00']), 'Scheduled'],
        [array_replace($due, ['scheduledAt' => null]), 'Needs review'],
        [array_replace($due, ['publishedAt' => ['invalid']]), 'Needs review'],
        [array_replace($due, ['status' => 'draft']), 'Draft'],
        [array_replace($due, ['status' => 'published', 'accessMode' => 'subscription']), 'Saved as published'],
        [array_replace($due, ['accessMode' => ['invalid']]), 'Needs review'],
    ] as [$row, $expected]) {
        $result = Calendar::build([], ['galleries' => [$row]], [], '2030-02', 'galleries', $now);
        $assert($state($result, 'media-due') === $expected, 'Media status ignored a date bound, readiness or access validation.');
    }
    $deleted = Calendar::build([], ['galleries' => [$due + ['deletedAt' => '2030-02-01']], 'videos' => [$due + ['deletedAt' => '2030-02-01']]], [], '2030-02', 'all', $now);
    $assert($deleted['events'] === [] && $deleted['undated'] === [], 'Deleted media entered the calendar.');
    foreach ([['status' => 'sent'], ['status' => []], ['delivery_paused' => 'false'], ['scheduled_at' => ['invalid']]] as $changes) {
        $row = array_replace($marketing['campaigns'][0], $changes);
        $result = Calendar::build(['campaigns' => [$row]], [], [], '2030-02', 'campaigns', $now);
        $assert($result['events'] === [] && $state($result, 'campaign-draft') === 'Needs review', 'Malformed campaign data claimed a successful or planned delivery.');
    }

    $versions = [];
    foreach (range(1, 25) as $number) $versions[] = ['id' => 'v' . $number, 'publishedAt' => sprintf('2030-02-%02dT12:00:00-06:00', $number), 'scene' => ['PRIVATE_CALENDAR_SCENE']];
    $history = Calendar::build([], [], ['hero' => ['draft' => true, 'versions' => $versions]], '2030-02', 'scenes', $now);
    $assert(count($history['events']) === 20 && count($history['undated']) === 1 && $history['events'][0]['date'] === '2030-02-06', 'Scene history did not retain only the latest 20 publications and saved draft.');
    foreach ($history['events'] as $row) {
        $assert($row['source_id'] === 'hero' && $row['state'] === 'Publication history' && $row['action'] === 'published_version'
            && $row['edit_path'] === 'admin/?page=composer', 'A scene history entry claimed an executable schedule or unsupported editor deep link.');
    }
    $brokenHistory = Calendar::build([], [], ['footer' => ['versions' => [['id' => 1, 'publishedAt' => false], ['id' => 2, 'publishedAt' => null]]]], '2030-02', 'scenes', $now);
    $assert($brokenHistory['events'] === [] && count($brokenHistory['undated']) === 2, 'Missing/unrepresentable SQL scene dates silently became epoch events.');

    $scheduledScene = ['id' => 'schedule-main', 'section' => 'hero', 'snapshotTitle' => 'Weekend <scene>',
        'startsAt' => '2030-02-11T18:00:00Z', 'endsAt' => '2030-02-12T18:00:00Z',
        'returnPolicy' => 'restore_previous', 'status' => 'scheduled',
        'snapshot' => ['PRIVATE_CALENDAR_FROZEN_SCENE'], 'owner' => 'PRIVATE_CALENDAR_OWNER',
        'claim' => ['PRIVATE_CALENDAR_CLAIM'], 'reason' => 'PRIVATE_CALENDAR_EXTRA_FIELD'];
    $scenePlan = Calendar::build([], [], ['hero' => ['schedules' => [$scheduledScene]]], '2030-02', 'scenes', $now);
    $assert(count($scenePlan['events']) === 2 && array_column($scenePlan['events'], 'action') === ['scene_start', 'scene_end'], 'Scene scheduling omitted its separate activation and ending dates.');
    $assert($scenePlan['events'][0]['title'] === 'Weekend <scene>' && $scenePlan['events'][0]['state'] === 'Scheduled'
        && $scenePlan['events'][0]['edit_path'] === 'admin/?page=scene-schedules&section=hero', 'Scheduled scene title, state or owner destination is incorrect.');
    $assert(!str_contains(json_encode($scenePlan, JSON_THROW_ON_ERROR), 'PRIVATE_CALENDAR_'), 'Scene schedule projection exposed frozen scenes, owner data, claims or unrelated fields.');
    foreach (['scheduled' => 'Due — awaiting scene runner', 'processing' => 'In progress', 'active' => 'Live — awaiting end processing',
        'completed' => 'Completed', 'expired' => 'Ended', 'cancelled' => 'Cancelled',
        'superseded' => 'Replaced by a manual publication', 'skipped' => 'Skipped', 'held' => 'Needs attention'] as $savedState => $expected) {
        $result = Calendar::build([], [], ['hero' => ['schedules' => [array_replace($scheduledScene, ['status' => $savedState])]]],
            '2030-02', 'scenes', new DateTimeImmutable('2030-02-13T18:00:00Z'));
        $assert($state($result, 'schedule-main') === $expected, 'Scene schedule dates incorrectly implied a completed worker transition.');
    }
    foreach ([['startsAt' => null], ['endsAt' => '2030-02-11T18:00:00Z'], ['returnPolicy' => 'automatic'],
        ['returnPolicy' => 'restore_previous', 'endsAt' => null], ['status' => 'published'], ['section' => 'footer'], ['id' => '../unsafe']] as $invalid) {
        $result = Calendar::build([], [], ['hero' => ['schedules' => [array_replace($scheduledScene, $invalid)]]], '2030-02', 'scenes', $now);
        $assert($result['events'] === [] && count($result['undated']) === 1 && $result['undated'][0]['state'] === 'Needs review', 'Invalid scene schedule metadata was promoted to a valid calendar plan.');
    }
    $result = Calendar::build([], [], ['hero' => ['schedules' => [$scheduledScene, $scheduledScene]]], '2030-02', 'scenes', $now);
    $assert($result['events'] === [] && count($result['undated']) === 2
        && $result['undated'][0]['edit_path'] === '' && $result['undated'][1]['edit_path'] === '', 'Duplicate scene schedule identities retained actionable calendar links.');
    $result = Calendar::build([], [], ['hero' => ['schedules' => [array_replace($scheduledScene, ['endsAt' => null, 'returnPolicy' => 'keep'])]]], '2030-02', 'scenes', $now);
    $assert(count($result['events']) === 1 && $result['events'][0]['action'] === 'scene_start', 'An unbounded keep schedule invented an end time.');

    $config = new Config($root . '/config/app.php');
    $config->write(['storage_mode' => 'file', 'file_store_path' => 'storage/data.json', 'timezone' => 'America/Chicago',
        'mail' => ['mode' => 'disabled'], 'security' => ['app_key' => bin2hex(random_bytes(32))]]);
    $store = new Store($config);
    $digest = new ReflectionProperty(Store::class, 'fileReadDigest');
    $before = $tree($root);
    $missing = $store->calendarRecords();
    $read = Calendar::read($store, '2030-02', 'all');
    $assert($tree($root) === $before && $digest->getValue($store) === null, 'Reading missing stores created files, defaults, guards or a write digest.');
    $assert($read['events'] === [] && $read['undated'] === [] && count($missing['media']) === 5, 'Missing stores produced content or omitted the projection shape.');
    mkdir($root . '/storage', 0700, true);
    $fileData = ['settings' => ['unrelated' => 'PRIVATE_CALENDAR_SETTING'], 'admins' => [['name' => 'PRIVATE_CALENDAR_ADMIN']],
        'mediaCenter' => $media, 'scenes' => ['hero' => ['draft' => ['PRIVATE_CALENDAR_SCENE'], 'published' => ['PRIVATE_CALENDAR_SCENE'], 'versions' => $versions]]];
    $fileData['mediaCenter']['videos'][] = $due + ['deletedAt' => '2030-02-01', 'description' => 'PRIVATE_CALENDAR_DELETED'];
    file_put_contents($root . '/storage/data.json', json_encode($fileData, JSON_THROW_ON_ERROR));
    $before = $tree($root);
    $records = $store->calendarRecords();
    $assert($tree($root) === $before && $digest->getValue($store) === null, 'Metadata projection changed saved bytes or established an unrelated write digest.');
    $assert(!str_contains(json_encode($records, JSON_THROW_ON_ERROR), 'PRIVATE_CALENDAR_'), 'Store metadata hydration disclosed noncalendar fields.');
    $assert(count($records['media']['videos']) === 1 && count($records['scenes']['hero']['versions']) === 20
        && $records['scenes']['hero']['draft'] === true && $records['scenes']['footer']['draft'] === false, 'File metadata projection mishandled deletions or scene retention.');
    foreach ($records['media'] as $rows) foreach ($rows as $row) {
        $assert(array_diff(array_keys($row), ['id', 'title', 'status', 'publishedAt', 'scheduledAt', 'accessMode']) === [], 'Store returned a media field outside the metadata allowlist.');
    }
    $store->setting('unrelated');
    $originalDigest = $digest->getValue($store);
    $fileData['settings']['changed'] = true;
    file_put_contents($root . '/storage/data.json', json_encode($fileData, JSON_THROW_ON_ERROR));
    $store->calendarRecords();
    $assert($digest->getValue($store) === $originalDigest, 'A calendar read blessed a newer file for a stale pending write.');
    mkdir($root . '/storage/private/marketing', 0700, true);
    file_put_contents($root . '/storage/private/marketing/data.json', json_encode($marketing, JSON_THROW_ON_ERROR));
    $before = $tree($root);
    $read = Calendar::read($store, '2030-02', 'all');
    $assert(count($matching($read['events'], 'post-main')) === 2 && count($matching($read['events'], 'galleries-main')) === 1, 'The read path did not combine saved marketing and media metadata.');
    $assert($tree($root) === $before && $digest->getValue($store) === $originalDigest, 'A populated calendar read initialized locks, changed saved files or altered the write digest.');
    file_put_contents($root . '/storage/data.json', 'invalid JSON deliberately blocks source reads');
    $reject(static fn() => Calendar::read($store, 'invalid-month', 'all'), 'Invalid selection touched sources before refusing the request.');
    $assert($digest->getValue($store) === $originalDigest, 'Rejected calendar selection changed the write digest.');
    echo 'PASS editorial calendar: ' . $checks . " assertions\n";
} finally {
    date_default_timezone_set($originalZone);
    if (is_file($root . '/calendar.fixture') && file_get_contents($root . '/calendar.fixture') === 'calendar-source-test') {
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($walk as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($root);
    }
}

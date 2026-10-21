<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Store;
use Velvet\Core\MarketingAccess as Access;
use Velvet\Core\MarketingPublication as Publication;
use Velvet\Core\MarketingStorage;
use Velvet\Core\MarketingStore;

$source = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/vvx-editorial-source-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700) || file_put_contents($root . '/fixture.marker', 'editorial-access-source') === false) {
    throw new RuntimeException('The owned editorial fixture could not be created.');
}
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
require_once $source . '/app/Support/functions.php';
require_once $source . '/app/Core/MarketingStore.php';

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
$tree = static function (string $path) use (&$tree): array {
    clearstatcache(true, $path);
    if (!file_exists($path) && !is_link($path)) return [];
    if (is_link($path)) return ['link' => readlink($path)];
    if (is_file($path)) return ['sha256' => hash_file('sha256', $path), 'mode' => fileperms($path) & 0777];
    $result = ['mode' => fileperms($path) & 0777, 'children' => []];
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $result['children'][$entry] = $tree($path . '/' . $entry);
    return $result;
};
$timezone = date_default_timezone_get();
$previousSession = $_SESSION ?? null;
try {
    date_default_timezone_set('UTC');
    $public = ['version' => 1, 'mode' => 'public', 'plan_ids' => []];
    $members = ['version' => 1, 'mode' => 'members', 'plan_ids' => []];
    $tiers = ['version' => 1, 'mode' => 'tiers', 'plan_ids' => ['gold', 'silver']];
    $guest = Access::guest();
    $free = ['member' => true, 'plan_ids' => [], 'available_plan_ids' => ['gold', 'silver', 'other']];
    $paid = array_replace($free, ['plan_ids' => ['gold']]);
    foreach ([$public, $members, $tiers] as $policy) $assert(Access::validate($policy) === $policy, 'A canonical policy changed during validation.');
    $assert(Access::allows([]) && Access::policy([]) === $public, 'Absent legacy access did not remain public.');
    $assert(Access::allows(['access' => $public]) && !Access::allows(['access' => $members]), 'Guest public/member access is incorrect.');
    $assert(Access::allows(['access' => $members], $free) && !Access::allows(['access' => $tiers], $free), 'Verified free membership did not remain independent of paid tiers.');
    $assert(Access::allows(['access' => $tiers], $paid), 'An exact eligible selected tier did not allow access.');
    $assert(!Access::allows(['access' => $tiers], array_replace($paid, ['available_plan_ids' => ['gold']])), 'A missing selected tier was hidden by another matching tier.');
    $assert(!Access::allows(['access' => $tiers], array_replace($paid, ['plan_ids' => ['other']])), 'An unrelated higher/other tier broadened an explicit allowlist.');
    $assert(!Access::allows(['access' => $tiers], ['member' => true, 'entitlements' => ['all'], 'plan_ids' => [], 'available_plan_ids' => ['gold', 'silver']]), 'Individual entitlements broadened editorial tier access.');
    $assert(!Access::allows(['access' => $members], ['member' => 1]), 'A coerced member flag was trusted.');
    $assert(!Access::allows(['access' => $tiers], ['member' => true, 'plan_ids' => ['gold']]), 'A viewer without a current catalogue bypassed selected-plan validation.');
    $numeric = ['version' => 1, 'mode' => 'tiers', 'plan_ids' => ['01']];
    $assert(!Access::allows(['access' => $numeric], ['member' => true, 'plan_ids' => ['1'], 'available_plan_ids' => ['01', '1']]), 'Exact marketing plan IDs were coerced through numeric identity.');
    $assert(Access::allows(['access' => $numeric], ['member' => true, 'plan_ids' => ['01'], 'available_plan_ids' => ['01']]), 'A canonical exact numeric string was lost.');

    $badPolicies = [null, false, '', [], ['version' => 1], ['version' => '1', 'mode' => 'public', 'plan_ids' => []],
        ['version' => 2, 'mode' => 'public', 'plan_ids' => []], ['version' => 1, 'mode' => 'unknown', 'plan_ids' => []],
        ['version' => 1, 'mode' => 'public', 'plan_ids' => ['gold']], ['version' => 1, 'mode' => 'members', 'plan_ids' => ['gold']],
        ['version' => 1, 'mode' => 'tiers', 'plan_ids' => []], ['version' => 1, 'mode' => 'tiers', 'plan_ids' => 'gold'],
        ['version' => 1, 'mode' => 'tiers', 'plan_ids' => ['gold', 'gold']], ['version' => 1, 'mode' => 'tiers', 'plan_ids' => [2 => 'gold']],
        $public + ['future_rule' => 'must not ignore']];
    foreach ([1, false, null, [], '', ' gold', 'gold ', '../gold', 'gold/silver', 'gold.silver', "gold\n", 'gold💜', str_repeat('g', 81)] as $id) {
        $badPolicies[] = ['version' => 1, 'mode' => 'tiers', 'plan_ids' => [$id]];
    }
    $badPolicies[] = ['version' => 1, 'mode' => 'tiers', 'plan_ids' => array_map(static fn(int $id): string => 'tier-' . $id, range(0, 100))];
    foreach ($badPolicies as $index => $policy) {
        $reject(static fn() => Access::validate($policy), 'Malformed policy was accepted at case ' . $index . '.');
        $assert(!Access::allows(['access' => $policy], $paid), 'Malformed saved policy became visible at case ' . $index . '.');
        $description = Access::describe(['access' => $policy]);
        $assert(!$description['valid'] && !$description['legacy'] && $description['mode'] === '' && $description['plan_ids'] === [], 'Malformed policy presentation silently selected an access mode.');
    }
    $maximum = ['version' => 1, 'mode' => 'tiers', 'plan_ids' => array_map(static fn(int $id): string => 'tier-' . $id, range(1, 100))];
    $assert(Access::validate($maximum) === $maximum && Access::validate(['version' => 1, 'mode' => 'tiers', 'plan_ids' => [str_repeat('x', 80)]])['plan_ids'][0] === str_repeat('x', 80), 'Documented policy size boundary was rejected.');
    $missingDescription = Access::describe(['access' => $tiers], ['gold']);
    $assert(!$missingDescription['valid'] && $missingDescription['mode'] === 'tiers' && $missingDescription['plan_ids'] === ['gold', 'silver'], 'Owner presentation erased an unavailable saved tier.');
    $assert(Access::describe([])['legacy'] && Access::describe([])['valid'], 'Absent legacy policy was presented as malformed.');
    $assert(Access::validateAvailable($tiers, ['silver', 'gold']) === $tiers, 'Available-tier validation changed selected order.');
    $reject(static fn() => Access::validateAvailable($tiers, ['gold']), 'Unavailable selected tier was accepted for saving.');

    // Subscription boundaries are evaluated independently of wall-clock timing.
    $now = strtotime('2025-01-10T12:00:00Z');
    $subscription = ['planId' => 'gold', 'status' => 'active', 'currentPeriodStart' => gmdate('c', $now - 60), 'currentPeriodEnd' => gmdate('c', $now + 60)];
    foreach (['active', 'trialing'] as $status) {
        $current = array_replace($subscription, ['status' => $status]);
        $assert(Access::eligibleSubscription($current, $now), 'Current active/trial subscription was denied.');
        $assert(Access::eligibleSubscription(array_replace($current, ['currentPeriodStart' => gmdate('c', $now)]), $now), 'An exact period start was treated as future.');
        $assert(!Access::eligibleSubscription(array_replace($current, ['currentPeriodStart' => gmdate('c', $now + 1)]), $now), 'A future subscription period broadened access.');
        $assert(!Access::eligibleSubscription(array_replace($current, ['currentPeriodEnd' => gmdate('c', $now)]), $now), 'An exact expired period end still allowed access.');
        foreach (['', null] as $open) $assert(Access::eligibleSubscription(array_replace($current, ['currentPeriodStart' => $open, 'currentPeriodEnd' => $open]), $now), 'A supported open subscription bound was rejected.');
    }
    $pastDue = array_replace($subscription, ['status' => 'past_due', 'currentPeriodEnd' => gmdate('c', $now - 1), 'graceEndsAt' => gmdate('c', $now + 1)]);
    $assert(Access::eligibleSubscription($pastDue, $now), 'A legitimate grace period stopped at the paid period end.');
    $assert(!Access::eligibleSubscription(array_replace($pastDue, ['graceEndsAt' => gmdate('c', $now)]), $now), 'Grace remained eligible at its exact end.');
    foreach (['', null] as $missingGrace) $assert(!Access::eligibleSubscription(array_replace($pastDue, ['graceEndsAt' => $missingGrace]), $now), 'Missing past-due grace became unlimited access.');
    foreach (['active', 'trialing', 'past_due'] as $status) {
        $eligibleBounds = $status === 'past_due' ? $pastDue
            : array_replace($subscription, ['status' => $status, 'graceEndsAt' => gmdate('c', $now + 1)]);
        foreach (['currentPeriodStart', 'currentPeriodEnd', 'graceEndsAt'] as $field) {
            foreach (['tomorrow', '2025-02-30T12:00:00Z', "2025-01-10T12:00:00Z\n", [], 0, false] as $value) {
                $assert(!Access::eligibleSubscription(array_replace($eligibleBounds, [$field => $value]), $now), 'Malformed ' . $field . ' broadened ' . $status . ' access.');
            }
        }
    }
    foreach (['paused', 'cancelled', 'expired', 'unknown', '', null] as $status) $assert(!Access::eligibleSubscription(array_replace($subscription, ['status' => $status]), $now), 'An ineligible status gained tier access.');
    $assert(!Access::eligibleSubscription(array_replace($subscription, ['currentPeriodEnd' => $subscription['currentPeriodStart']]), $now), 'An equal/reversed period was accepted.');

    // SQL DATETIME bounds come from mysqlDate() in UTC, independent of site timezone.
    // Legacy file strings retain their existing local/explicit-offset interpretation.
    foreach (['America/Chicago', 'Asia/Tokyo'] as $zone) {
        date_default_timezone_set($zone);
        $sqlBounds = ['planId' => 'gold', 'currentPeriodStart' => gmdate('Y-m-d H:i:s', $now - 86400),
            'currentPeriodEnd' => gmdate('Y-m-d H:i:s', $now + 60), 'graceEndsAt' => null];
        foreach (['active', 'trialing'] as $status) {
            $current = $sqlBounds + ['status' => $status];
            $assert(Access::eligibleSubscription($current, $now, true), $zone . ': a live UTC SQL period was interpreted in site time.');
            $assert(Access::eligibleSubscription(array_replace($current, ['currentPeriodStart' => gmdate('Y-m-d H:i:s', $now)]), $now, true), $zone . ': a UTC SQL subscription did not begin at its exact start.');
            $assert(!Access::eligibleSubscription(array_replace($current, ['currentPeriodEnd' => gmdate('Y-m-d H:i:s', $now)]), $now, true), $zone . ': a UTC SQL period remained eligible at its exact end.');
            $assert(!Access::eligibleSubscription(array_replace($current, ['currentPeriodStart' => gmdate('Y-m-d H:i:s', $now + 60),
                'currentPeriodEnd' => gmdate('Y-m-d H:i:s', $now + 86400)]), $now, true), $zone . ': a future UTC SQL period started early.');
            $local = array_replace($current, ['currentPeriodStart' => date('Y-m-d H:i:s', $now - 60), 'currentPeriodEnd' => date('Y-m-d H:i:s', $now + 60)]);
            $assert(Access::eligibleSubscription($local, $now), $zone . ': a legacy local file period was reinterpreted as UTC.');
        }
        $grace = array_replace($sqlBounds, ['status' => 'past_due', 'currentPeriodEnd' => gmdate('Y-m-d H:i:s', $now - 60),
            'graceEndsAt' => gmdate('Y-m-d H:i:s', $now + 60)]);
        $assert(Access::eligibleSubscription($grace, $now, true), $zone . ': current UTC SQL grace was discarded by site-time filtering.');
        $assert(!Access::eligibleSubscription(array_replace($grace, ['graceEndsAt' => gmdate('Y-m-d H:i:s', $now)]), $now, true), $zone . ': UTC SQL grace remained active at its exact end.');
        foreach (['+05:45', '-07:00'] as $offset) {
            $explicit = array_replace($sqlBounds, ['status' => 'active',
                'currentPeriodStart' => (new DateTimeImmutable('@' . ($now - 60)))->setTimezone(new DateTimeZone($offset))->format('c'),
                'currentPeriodEnd' => (new DateTimeImmutable('@' . ($now + 60)))->setTimezone(new DateTimeZone($offset))->format('c')]);
            $assert(Access::eligibleSubscription($explicit, $now) && Access::eligibleSubscription($explicit, $now, true), $zone . ': an explicit membership offset changed with storage mode.');
        }
        foreach (['currentPeriodStart', 'currentPeriodEnd', 'graceEndsAt'] as $field) {
            foreach (['2025-02-30 12:00:00', '2025-01-10 25:00:00', '2025-01-10 12:00:00 junk', '2025-01-10T12:00:00+15:00'] as $bad) {
                $assert(!Access::eligibleSubscription(array_replace($grace, [$field => $bad]), $now, true), $zone . ': malformed UTC SQL membership bound was accepted.');
            }
        }
        $expectedPublication = $now + ($zone === 'America/Chicago' ? 21600 : -32400);
        $assert(Publication::storedDate('2025-01-10 12:00:00')?->getTimestamp() === $expectedPublication,
            $zone . ': correcting membership SQL dates changed site-local publication scheduling.');
    }
    date_default_timezone_set('America/Chicago');
    $utcGap = strtotime('2025-03-09T02:30:00Z');
    $assert(Access::eligibleSubscription(['planId' => 'gold', 'status' => 'active', 'currentPeriodStart' => '2025-03-09 02:00:00',
        'currentPeriodEnd' => '2025-03-09 03:00:00'], $utcGap, true), 'A valid UTC SQL period was mistaken for a Chicago daylight-saving gap.');
    $reject(static fn() => Publication::formDate('2025-03-09T02:30'), 'Membership date correction removed the publication daylight-saving gap guard.');
    date_default_timezone_set('UTC');

    // Preview never initializes a missing store, even its directory or mutex.
    $before = $tree($root);
    $assert(MarketingStore::previewPublication('posts', 'missing-post', $root) === null, 'Missing source preview was not empty.');
    $assert((new MarketingStorage($root))->peek() === null && $tree($root) === $before, 'Missing source preview created directories, lock/default files or guard state.');
    $reject(static fn() => MarketingStore::previewPublication('contacts', 'x', $root), 'Private preview accepted a non-editorial collection.');
    $reject(static fn() => MarketingStore::previewPublication('posts', '../x', $root), 'Private preview accepted an unsafe identity.');
    $assert($tree($root) === $before, 'Invalid preview input initialized storage.');
    $legacyRoot = $root . '/legacy-preview';
    mkdir($legacyRoot . '/storage/private/marketing', 0700, true);
    $legacyFile = $legacyRoot . '/storage/private/marketing/data.json';
    $legacyRecord = ['id' => 'legacy-draft', 'title' => 'Saved private draft', 'slug' => 'legacy-draft', 'status' => 'draft',
        'body' => "Exact private text.\n  ", 'access' => null, 'unknown' => ['preserved' => true]];
    file_put_contents($legacyFile, json_encode(['posts' => [$legacyRecord], 'owner_metadata' => ['unchanged' => 7]], JSON_THROW_ON_ERROR) . "\n");
    $before = $tree($legacyRoot);
    $preview = MarketingStore::previewPublication('posts', 'legacy-draft', $legacyRoot);
    $assert($preview !== null && array_intersect_key($preview, $legacyRecord) === $legacyRecord && $preview['visibility']['state'] === 'Needs review', 'Private saved preview lost held/draft content or unknown fields.');
    $assert(MarketingStore::previewPublication('posts', 'absent', $legacyRoot) === null && $tree($legacyRoot) === $before, 'Legacy preview created a mutex/default/guard or modified saved bytes.');
    file_put_contents($legacyRoot . '/storage/private/marketing/sql-cutover.json', json_encode(['version' => 1, 'installation_id' => 'owned-fixture',
        'storage_id' => str_repeat('a', 32), 'database_hash' => str_repeat('b', 64), 'source_sha256' => hash_file('sha256', $legacyFile)], JSON_THROW_ON_ERROR));
    $before = $tree($legacyRoot);
    $reject(static fn() => MarketingStore::previewPublication('posts', 'legacy-draft', $legacyRoot), 'SQL-required preview revived an archived file with missing configuration.', $legacyFile);
    $assert($tree($legacyRoot) === $before, 'SQL-required preview refusal created or changed files.');

    $config = new Config($root . '/config/app.php');
    $config->write(['storage_mode' => 'file', 'file_store_path' => 'storage/data.json', 'timezone' => 'UTC',
        'mail' => ['mode' => 'disabled'], 'security' => ['app_key' => bin2hex(random_bytes(32))]]);
    if (!is_dir($root . '/storage')) mkdir($root . '/storage', 0700);
    file_put_contents($root . '/storage/data.json', '{"settings":[],"admins":[],"members":[],"membershipPlans":[],"subscriptions":[],"memberSessions":[]}');
    $marketing = new MarketingStore($root);
    $marketingFile = $root . '/storage/private/marketing/data.json';
    $marketing->update(static function (array &$data): void {
        $data['owner_unknown'] = ['nested' => ['keep' => true]];
        $data['settings']['campaign_delivery_enabled'] = false;
    });
    $subscriber = $marketing->subscribe('editorial-fixture@example.test', 'Fixture', 'editorial-test', true);
    $marketing->confirm($subscriber['token']);
    $segment = $marketing->saveSegment(['name' => 'Untouched audience', 'enabled' => true, 'rules' => ['sources' => ['editorial-test']]]);
    $campaign = $marketing->upsert('campaigns', ['subject' => 'Untouched frozen message', 'body' => 'No transport in this source test.',
        'audience' => ['mode' => 'segment', 'segment_id' => $segment['id'], 'segment_revision' => $segment['revision']]]);
    $marketing->queueCampaign($campaign['id']);
    $preserved = array_diff_key($marketing->all(), array_flip(['meta', 'posts', 'banners', 'landing_pages']));

    $created = [];
    $policies = ['legacy' => null, 'public' => $public, 'members' => $members, 'tiers' => $tiers,
        'other' => ['version' => 1, 'mode' => 'tiers', 'plan_ids' => ['other']]];
    $offset = 600;
    foreach ($policies as $key => $policy) {
        $input = ['title' => $key . ' title', 'slug' => $key . '-post', 'excerpt' => $key . ' excerpt',
            'body' => $key . ' private or public body', 'status' => 'published', 'publish_at' => gmdate('Y-m-d\TH:i:s', time() - $offset)];
        if ($policy !== null) $input['access'] = $policy;
        $created[$key] = $marketing->savePublication('posts', $input);
        $offset -= 100;
    }
    $assert(array_column($marketing->publishedPosts(), 'slug') === ['public-post', 'legacy-post'], 'Default guest listing disclosed restricted posts.');
    $assert($marketing->publishedPosts(1)[0]['id'] === $created['public']['id'], 'Guest filtering happened after the listing limit.');
    $assert($marketing->publishedPosts(1, $free)[0]['id'] === $created['members']['id'], 'Free member filtering happened after the listing limit.');
    $assert($marketing->publishedPosts(1, $paid)[0]['id'] === $created['tiers']['id'], 'Selected tier filtering happened after the listing limit.');
    foreach (['members', 'tiers', 'other'] as $key) $assert($marketing->findPost($key . '-post') === null, 'Default guest slug lookup disclosed ' . $key . ' content.');
    $assert($marketing->findPost('members-post', $free)['id'] === $created['members']['id'] && $marketing->findPost('tiers-post', $free) === null, 'Member/selected-tier slug access was not separate.');
    $assert($marketing->findPost('tiers-post', $paid)['id'] === $created['tiers']['id'], 'Eligible tier slug lookup failed.');
    $future = $marketing->savePublication('posts', ['title' => 'Scheduled member post', 'slug' => 'future-post', 'status' => 'published',
        'publish_at' => gmdate('Y-m-d\TH:i:s', time() + 3600), 'access' => $members]);
    $draft = $marketing->savePublication('posts', ['title' => 'Private draft', 'slug' => 'draft-post', 'status' => 'draft', 'access' => $tiers]);
    $assert($marketing->findPost('future-post', $paid) === null && $marketing->findPost('draft-post', $paid) === null, 'Membership bypassed scheduled/draft publication state.');
    $banners = [];
    foreach (['tiers' => $tiers, 'members' => $members, 'public' => $public] as $key => $policy) {
        $banners[$key] = $marketing->savePublication('banners', ['text' => $key . ' banner', 'url' => '/membership/', 'enabled' => true, 'access' => $policy]);
    }
    $assert(array_column($marketing->activeBanners(), 'id') === [$banners['public']['id']], 'Default guest banner selection exposed a restricted banner.');
    $assert(array_column($marketing->activeBanners($free), 'id') === [$banners['members']['id'], $banners['public']['id']], 'Member banner filtering changed saved precedence.');
    $assert(array_column($marketing->activeBanners($paid), 'id') === array_column($banners, 'id'), 'Eligible banner filtering changed saved precedence.');
    $ended = $marketing->savePublication('banners', ['text' => 'Ended member banner', 'enabled' => true, 'access' => $members,
        'ends_at' => gmdate('Y-m-d\TH:i:s', time() - 1)]);
    $assert(!in_array($ended['id'], array_column($marketing->activeBanners($paid), 'id'), true), 'Membership bypassed an expired banner window.');
    foreach (['legacy' => null, 'members' => $members, 'tiers' => $tiers] as $key => $policy) {
        $input = ['title' => $key . ' landing', 'slug' => $key . '-landing', 'enabled' => true, 'body' => 'Saved landing content'];
        if ($policy !== null) $input['access'] = $policy;
        $created['page_' . $key] = $marketing->savePublication('landing_pages', $input);
    }
    $assert($marketing->activeLandingPage('legacy-landing') !== null && $marketing->activeLandingPage('members-landing') === null && $marketing->activeLandingPage('tiers-landing') === null, 'Default guest landing access is incorrect.');
    $assert($marketing->activeLandingPage('members-landing', $free) !== null && $marketing->activeLandingPage('tiers-landing', $free) === null
        && $marketing->activeLandingPage('tiers-landing', $paid) !== null, 'Member/selected-tier landing access is incorrect.');

    // Omissions preserve exact policy bytes/order, and policy-only edits invalidate revisions.
    $legacy = $created['legacy'];
    $legacy = $marketing->savePublication('posts', ['id' => $legacy['id'], 'expected_revision' => $legacy['revision'], 'title' => 'Legacy revised']);
    $assert(!array_key_exists('access', $legacy), 'An older form rewrote absent legacy access.');
    $restricted = $created['tiers'];
    $restricted = $marketing->savePublication('posts', ['id' => $restricted['id'], 'expected_revision' => $restricted['revision'], 'title' => 'Restricted revised']);
    $assert($restricted['access'] === $tiers && $marketing->findPost('tiers-post') === null, 'An older form cleared a saved restriction.');
    $old = $restricted;
    $restricted = $marketing->savePublication('posts', ['id' => $restricted['id'], 'expected_revision' => $restricted['revision'], 'access' => $members]);
    $assert($restricted['revision'] !== $old['revision'], 'An access-only edit did not change the publication revision.');
    $reject(fn() => $marketing->savePublication('posts', ['id' => $old['id'], 'expected_revision' => $old['revision'], 'title' => 'Stale']), 'A stale save overwrote a policy edit.', $marketingFile);
    $reject(fn() => $marketing->setPublicationEnabled('posts', $old['id'], true, $old['revision']), 'A stale toggle bypassed a policy edit.', $marketingFile);
    $reject(fn() => $marketing->deletePublication('posts', $old['id'], $old['revision']), 'A stale delete bypassed a policy edit.', $marketingFile);
    foreach ($badPolicies as $index => $policy) {
        $reject(fn() => $marketing->savePublication('posts', ['id' => $restricted['id'], 'expected_revision' => $restricted['revision'], 'access' => $policy]), 'Explicit invalid save policy was accepted at case ' . $index . '.', $marketingFile);
    }
    $deletedRecords = [];
    foreach (['posts', 'banners', 'landing_pages'] as $collection) {
        $heldInput = $collection === 'banners' ? ['text' => 'Held banner', 'enabled' => true]
            : ['title' => 'Held content', 'slug' => 'held-' . $collection, 'body' => "Preserve held text.\n", 'status' => 'published', 'enabled' => true];
        $held = $marketing->upsert($collection, $heldInput + ['access' => ['version' => 99, 'mode' => 'future', 'plan_ids' => ['gold']], 'private_unknown' => ['original' => true]]);
        $held = $marketing->publicationRecord($collection, $held['id']);
        $assert($held['visibility']['state'] === 'Needs review', 'Malformed saved policy did not show an owner hold.');
        $held = $marketing->savePublication($collection, ['id' => $held['id'], 'expected_revision' => $held['revision']]);
        $assert($held['access'] === ['version' => 99, 'mode' => 'future', 'plan_ids' => ['gold']]
            && !$held['visibility']['visible'], 'Omitted malformed legacy policy was reset or released from its hold.');
        $withdrawn = $marketing->setPublicationEnabled($collection, $held['id'], false, $held['revision']);
        $assert($withdrawn['access'] === $held['access'] && $withdrawn['private_unknown'] === $held['private_unknown'], 'Withdrawal changed held policy or unrelated fields.');
        $reject(fn() => $marketing->setPublicationEnabled($collection, $held['id'], true, $withdrawn['revision']), 'Held content was enabled without deliberate repair.', $marketingFile);
        $repaired = $marketing->savePublication($collection, ['id' => $held['id'], 'expected_revision' => $withdrawn['revision'], 'access' => $members]);
        $assert($repaired['access'] === $members && $repaired['private_unknown'] === $held['private_unknown'], 'A deliberate policy repair did not preserve the saved content.');
        foreach ($marketing->all()[$collection] as $record) {
            if ((string) $record['id'] === (string) $held['id']) $deletedRecords[$collection] = $record;
        }
        $marketing->deletePublication($collection, $held['id'], $repaired['revision']);
        $assert($marketing->publicationRecord($collection, $held['id']) === null, 'Revision-checked withdrawal deletion did not remove the exact held record.');
    }
    $authorizationCount = 0;
    $checkLocked = static function (array $candidate) use ($assert, $root, &$authorizationCount): void {
        $authorizationCount++;
        $assert(isset($candidate['id'], $candidate['edit_version']) && !isset($candidate['revision']), 'Authorization did not receive the final saved candidate.');
        $probe = fopen($root . '/storage/private/marketing/data.lock', 'rb');
        $obtained = flock($probe, LOCK_EX | LOCK_NB);
        if ($obtained) flock($probe, LOCK_UN);
        fclose($probe);
        $assert(!$obtained, 'Publication authorization ran outside the data lock.');
    };
    $restricted = $marketing->savePublication('posts', ['id' => $restricted['id'], 'expected_revision' => $restricted['revision'], 'excerpt' => 'Authorized saved excerpt'], $checkLocked);
    $restricted = $marketing->setPublicationEnabled('posts', $restricted['id'], false, $restricted['revision'], $checkLocked);
    $rejectOwner = static function (array $candidate) use ($checkLocked): void { $checkLocked($candidate); throw new RuntimeException('Owner access changed.', 403); };
    $reject(fn() => $marketing->savePublication('posts', ['id' => $restricted['id'], 'expected_revision' => $restricted['revision'], 'access' => $public], $rejectOwner), 'A revoked owner saved access changes.', $marketingFile);
    $reject(fn() => $marketing->setPublicationEnabled('posts', $restricted['id'], true, $restricted['revision'], $rejectOwner), 'A revoked owner enabled a publication.', $marketingFile);
    $reject(fn() => $marketing->deletePublication('posts', $restricted['id'], $restricted['revision'], $rejectOwner), 'A revoked owner deleted a publication.', $marketingFile);
    $assert($authorizationCount === 5, 'One or more publication mutations skipped the locked authorization callback.');
    $before = $tree($root . '/storage/private/marketing');
    $savedPreview = MarketingStore::previewPublication('posts', $draft['id'], $root);
    $assert($savedPreview === $marketing->publicationRecord('posts', $draft['id']) && $tree($root . '/storage/private/marketing') === $before, 'Saved draft preview did not match exact owner state or changed marketing files.');
    $lockPath = $root . '/storage/private/marketing/data.lock';
    chmod($lockPath, 0440);
    $before = $tree($root . '/storage/private/marketing');
    $assert(MarketingStore::previewPublication('posts', $future['id'], $root)['visibility']['state'] === 'Scheduled'
        && $tree($root . '/storage/private/marketing') === $before, 'Preview required a writable lock, changed its permissions or hid saved scheduled content.');
    chmod($lockPath, 0640);
    $finalMarketing = $marketing->all();
    $assert(array_diff_key($finalMarketing, array_flip(['meta', 'posts', 'banners', 'landing_pages', \Velvet\Core\MarketingRecovery::KEY])) === $preserved, 'Editorial access or preview mutated consent, frozen audiences/messages, queue state, tokens, events or unknown data.');
    $deletedEntries = \Velvet\Core\MarketingRecovery::entries($finalMarketing);
    $assert(count($deletedEntries) === 3, 'Only the three deliberate deletions may change the private recovery archive.');
    foreach ($deletedEntries as $entry) {
        $assert(($deletedRecords[$entry['collection']] ?? null) === $entry['record'], 'Deletion failed to preserve the exact saved access policy, private fields and history.');
    }

    // Resolve real file-backed accounts/devices/catalogues afresh, with no heartbeat or event writes.
    $fresh = static fn(): Store => new Store(new Config($config->path()));
    $account = $fresh()->createMember(['displayName' => 'Editorial member', 'email' => 'editorial-member@example.test',
        'password' => 'fixture-only-password-unguessable', 'status' => 'pending', 'emailVerifiedAt' => null]);
    $account = $fresh()->markMemberVerified($account['id']);
    $token = bin2hex(random_bytes(24));
    $device = $fresh()->createMemberSession($account['id'], $token, ['deviceName' => 'Owned editorial fixture']);
    $goldPlan = $fresh()->saveMembershipPlan(['name' => 'Gold', 'key' => 'gold-key', 'isActive' => true]);
    $silverPlan = $fresh()->saveMembershipPlan(['name' => 'Silver', 'key' => 'silver-key', 'isActive' => true]);
    $memberStore = $fresh();
    $session = ['id' => $account['id'], 'token' => $token];
    $_SESSION = ['member' => $session, 'unrelated' => ['preserve' => true]];
    $memberFile = $root . '/storage/data.json';
    $reject(fn() => $memberStore->databaseMemberVerificationIsCurrent($account['id']), 'File storage attempted a database-time verification comparison.', $memberFile);
    $before = file_get_contents($memberFile); $sessionBefore = $_SESSION;
    $viewer = Access::viewer($memberStore);
    $assert($viewer['member'] && $viewer['plan_ids'] === [] && in_array($goldPlan['id'], $viewer['available_plan_ids'], true), 'A real verified free member did not resolve from the persisted device.');
    $assert(file_get_contents($memberFile) === $before && $_SESSION === $sessionBefore, 'Viewer resolution changed heartbeat, member events, file state or PHP session.');
    $assert($marketing->findPost('members-post') === null, 'A reader with no trusted viewer argument implicitly adopted the ambient member session.');
    $assert(Access::viewer($memberStore, []) === $guest && Access::viewer($memberStore, ['id' => $account['id'], 'token' => 'wrong-token']) === $guest, 'A missing/invalid member device was trusted.');
    $subscription = $fresh()->saveSubscription(['memberId' => $account['id'], 'planId' => $goldPlan['id'], 'status' => 'active',
        'currentPeriodStart' => gmdate('c', time() - 60), 'currentPeriodEnd' => gmdate('c', time() + 3600)]);
    $assert(Access::viewer($memberStore, $session)['plan_ids'] === [$goldPlan['id']], 'An existing Store object did not read a separately saved subscription.');
    $fresh()->saveSubscription(array_replace($subscription, ['status' => 'cancelled']));
    $assert(Access::viewer($memberStore, $session)['member'] && Access::viewer($memberStore, $session)['plan_ids'] === [], 'A cancelled subscription stayed cached or removed free membership.');
    $fresh()->saveSubscription($subscription);
    $fresh()->updateMember($account['id'], ['status' => 'suspended']);
    $assert(Access::viewer($memberStore, $session) === $guest, 'A suspended member remained authorized through a cached Store.');
    $fresh()->updateMember($account['id'], ['status' => 'active']);
    $assert(Access::viewer($memberStore, $session)['plan_ids'] === [$goldPlan['id']], 'Restored current membership did not resolve.');
    foreach ([null, '', 'bad-verification', gmdate('c', time() + 3600)] as $verification) {
        $fresh()->updateMember($account['id'], ['emailVerifiedAt' => $verification]);
        $assert(Access::viewer($memberStore, $session) === $guest, 'Missing/malformed/future verification allowed editorial membership.');
    }
    $fresh()->updateMember($account['id'], ['emailVerifiedAt' => gmdate('c', time() - 60)]);
    $fresh()->mutateFileStore(static function (array &$data) use ($account): void { $data['members'][$account['id']]['deletedAt'] = gmdate('c'); });
    $assert(Access::viewer($memberStore, $session) === $guest, 'A deleted file-mode member remained eligible.');
    $fresh()->mutateFileStore(static function (array &$data) use ($account): void { $data['members'][$account['id']]['deletedAt'] = null; });
    $fresh()->saveMembershipPlan(array_replace($goldPlan, ['isActive' => false]));
    $assert(!in_array($goldPlan['id'], Access::availablePlanIds($memberStore), true) && Access::viewer($memberStore, $session)['plan_ids'] === [], 'A disabled tier remained available or entitled.');
    $fresh()->saveMembershipPlan($goldPlan);
    $realPolicy = ['version' => 1, 'mode' => 'tiers', 'plan_ids' => [$goldPlan['id'], $silverPlan['id']]];
    $assert(Access::allows(['access' => $realPolicy], Access::viewer($memberStore, $session)), 'Current real selected-tier viewer failed.');
    $validateCatalogue = static function (array $candidate) use ($fresh): void {
        Access::validateAvailable(Access::policy($candidate), Access::availablePlanIds($fresh()));
    };
    $catalogueDraft = $marketing->savePublication('posts', ['title' => 'Catalogue protected draft', 'slug' => 'catalogue-draft',
        'status' => 'draft', 'access' => $realPolicy], $validateCatalogue);
    $fresh()->saveMembershipPlan(array_replace($silverPlan, ['isActive' => false]));
    $assert(!Access::allows(['access' => $realPolicy], Access::viewer($memberStore, $session)), 'A disabled other selected tier was silently ignored.');
    $reject(fn() => $marketing->savePublication('posts', ['id' => $catalogueDraft['id'], 'expected_revision' => $catalogueDraft['revision'], 'title' => 'Stale catalogue edit'], $validateCatalogue), 'An edit saved a tier disabled after its form opened.', $marketingFile);
    $reject(fn() => $marketing->setPublicationEnabled('posts', $catalogueDraft['id'], true, $catalogueDraft['revision'], $validateCatalogue), 'Enabling ignored a tier disabled after its form opened.', $marketingFile);
    $fresh()->saveMembershipPlan($silverPlan);
    $assert(!Access::allows(['access' => ['version' => 1, 'mode' => 'tiers', 'plan_ids' => ['gold-key']]], Access::viewer($memberStore, $session)), 'A plan key was accepted in place of its exact saved ID.');
    foreach (['id' => 1, 'isActive' => 'yes', 'name' => '', 'deletedAt' => gmdate('c')] as $field => $value) {
        $fresh()->mutateFileStore(static function (array &$data) use ($goldPlan, $field, $value): void { $data['membershipPlans'][$goldPlan['id']][$field] = $value; });
        $assert(!in_array($goldPlan['id'], Access::availablePlanIds($memberStore), true) && Access::viewer($memberStore, $session)['plan_ids'] === [], 'Malformed tier identity/state broadened access.');
        $fresh()->mutateFileStore(static function (array &$data) use ($goldPlan): void { $data['membershipPlans'][$goldPlan['id']] = $goldPlan; });
    }
    $fresh()->mutateFileStore(static function (array &$data) use ($goldPlan): void { $data['membershipPlans']['duplicate-fixture'] = $goldPlan; });
    $assert(!in_array($goldPlan['id'], Access::availablePlanIds($memberStore), true), 'A duplicate exact plan identity remained available.');
    $fresh()->mutateFileStore(static function (array &$data): void { unset($data['membershipPlans']['duplicate-fixture']); });
    foreach (['currentPeriodEnd' => 'not-a-date', 'currentPeriodStart' => gmdate('c', time() + 3600), 'graceEndsAt' => 'not-a-date'] as $field => $value) {
        $fresh()->mutateFileStore(static function (array &$data) use ($subscription, $field, $value): void { $data['subscriptions'][$subscription['id']][$field] = $value; });
        $assert(Access::viewer($memberStore, $session)['plan_ids'] === [], 'Legacy activeSubscriptions normalization broadened malformed/future editorial dates.');
        $fresh()->mutateFileStore(static function (array &$data) use ($subscription): void { $data['subscriptions'][$subscription['id']] = $subscription; });
    }
    $fresh()->revokeMemberSession($account['id'], $device['id']);
    $assert(Access::viewer($memberStore, $session) === $guest, 'Revoked persisted device remained eligible.');
    $fresh()->mutateFileStore(static function (array &$data) use ($device): void { $data['memberSessions'][$device['id']] = $device; });
    $fresh()->mutateFileStore(static function (array &$data) use ($device): void { $data['memberSessions'][$device['id']]['memberId'] = 'different-member'; });
    $assert(Access::viewer($memberStore, $session) === $guest, 'A device belonging to another member was trusted.');
    $fresh()->mutateFileStore(static function (array &$data) use ($device): void { $data['memberSessions'][$device['id']] = $device; });
    $before = file_get_contents($memberFile);
    file_put_contents($memberFile, '{corrupt membership fixture');
    $assert(Access::viewer($memberStore, $session) === $guest && Access::allows(['access' => $public], Access::viewer($memberStore, $session)), 'Membership-store failure granted restrictions or blocked valid public legacy content.');
    $assert(file_get_contents($memberFile) === '{corrupt membership fixture', 'Viewer failure replaced corrupt membership state.');
    $reject(static fn() => Access::availablePlanIds($memberStore), 'An unavailable catalogue appeared safe to save against.');
    file_put_contents($memberFile, $before);
    $before = file_get_contents($memberFile);
    $assert(Access::viewer($memberStore, $session)['plan_ids'] === [$goldPlan['id']] && file_get_contents($memberFile) === $before, 'Final current viewer was not fresh/read-only.');
    foreach (['America/Chicago', 'Asia/Tokyo'] as $zone) {
        date_default_timezone_set($zone);
        $assert(Access::viewer($memberStore, $session)['plan_ids'] === [$goldPlan['id']]
            && file_get_contents($memberFile) === $before, $zone . ': real file member verification/subscription ISO timestamps changed access or wrote state.');
    }
} finally {
    date_default_timezone_set($timezone);
    if ($previousSession === null) unset($_SESSION); else $_SESSION = $previousSession;
    if (!str_starts_with($root, sys_get_temp_dir() . '/vvx-editorial-source-')
        || !is_file($root . '/fixture.marker') || file_get_contents($root . '/fixture.marker') !== 'editorial-access-source') {
        throw new RuntimeException('Refusing cleanup without the owned editorial fixture marker.');
    }
    $remove = static function (string $path) use (&$remove): void {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
        rmdir($path);
    };
    $remove($root);
}
if ($failures !== []) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Editorial access source acceptance passed ({$assertions} assertions; strict policies, fresh members, preservation, locked authorization and read-only saved preview).\n";

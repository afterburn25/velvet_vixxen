<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/Core/MarketingStore.php';

$root = sys_get_temp_dir() . '/vvx-marketing-test-' . bin2hex(random_bytes(6));
$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void { if (!$ok) $failures[] = $message; };
$reject = static function (callable $operation, string $message) use (&$failures): void {
    try { $operation(); $failures[] = $message; } catch (InvalidArgumentException|RuntimeException) {}
};
try {
    $store = new Velvet\Core\MarketingStore($root);
    $reject(fn() => $store->subscribe('bad-email', 'Test', 'test', true), 'Invalid newsletter address was accepted.');
    $reject(fn() => $store->subscribe('test@example.test', 'Test', 'test', false), 'Newsletter consent was bypassed.');
    $pending = $store->subscribe(' Pending@Example.Test ', 'Pending', 'test', true);
    $active = $store->subscribe('active@example.test', 'Active', 'test', true);
    $assert($pending['contact']['email'] === 'pending@example.test', 'Newsletter address normalization failed.');
    $assert($active['contact']['status'] === 'pending', 'Unconfirmed contact was activated.');
    $assert(!str_contains((string) file_get_contents($root . '/storage/private/marketing/data.json'), $active['token']), 'Confirmation token was stored in plaintext.');
    $assert(!$store->confirm('invalid-token'), 'Invalid confirmation token was accepted.');
    $assert($store->confirm($active['token']), 'Valid confirmation token was rejected.');
    $assert(!$store->confirm($active['token']), 'Confirmation token replay was accepted.');
    $campaign = $store->upsert('campaigns', ['name' => 'Fixture campaign', 'status' => 'draft']);
    $assert($store->queueCampaign($campaign['id']) === 1, 'Campaign included an unconfirmed contact or excluded a confirmed one.');
    $assert($store->unsubscribe($active['contact']['unsubscribe_token']), 'Valid unsubscribe token was rejected.');
    $assert(!$store->unsubscribe('invalid-token'), 'Invalid unsubscribe token was accepted.');
    $second = $store->upsert('campaigns', ['name' => 'After unsubscribe', 'status' => 'draft']);
    $assert($store->queueCampaign($second['id']) === 0, 'Unsubscribed contact entered a new campaign queue.');
    $assert(count($store->all()['consents']) === 3, 'Consent grant/revocation history was not retained.');

    $store->upsert('posts', ['slug' => 'public-post', 'status' => 'published', 'publish_at' => gmdate('c', time() - 60)]);
    $store->upsert('posts', ['slug' => 'draft-post', 'status' => 'draft']);
    $store->upsert('posts', ['slug' => 'future-post', 'status' => 'published', 'publish_at' => gmdate('c', time() + 3600)]);
    $assert($store->findPost('public-post') !== null && $store->findPost('draft-post') === null && $store->findPost('future-post') === null, 'Publication status/timing boundary failed.');
    $store->upsert('banners', ['enabled' => true, 'starts_at' => gmdate('c', time() - 60), 'ends_at' => gmdate('c', time() + 60)]);
    $store->upsert('banners', ['enabled' => true, 'ends_at' => gmdate('c', time() - 60)]);
    $assert(count($store->activeBanners()) === 1, 'Expired promotional banner remained active.');
    $reject(fn() => $store->upsert('contacts', ['status' => 'active']), 'Unrestricted collection editing bypassed newsletter confirmation.');
    $store->recordEvent('page_view', ['path' => '/blog/', 'private_message' => 'never-store-this-message', 'card_number' => 'never-store-this-payment']);
    $store->recordEvent('unapproved-event', ['path' => '/']);
    $events = $store->all()['events'];
    $assert(count($events) === 1 && strlen($events[0]['session_hash']) === 64, 'Analytics event allowlist/session hashing failed.');
    $assert(!str_contains(json_encode($events), 'never-store-this'), 'Analytics retained prohibited extra payload fields.');
    $assert($store->analyticsSummary()['counts']['page_view'] === 1, 'Analytics summary did not count the accepted event.');
    $assert(count((new Velvet\Core\MarketingStore($root))->publishedPosts()) === 1, 'Marketing state did not persist across instances.');
} catch (Throwable $e) { $failures[] = $e->getMessage(); }
finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }
}
if ($failures !== []) { fwrite(STDERR, "Marketing regression failed:\n- " . implode("\n- ", $failures) . "\n"); exit(1); }
echo "Marketing consent, publishing, campaign and analytics regressions passed.\n";

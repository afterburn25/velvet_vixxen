<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\MailProviderService;
use App\Core\NotificationService;
use App\Core\PrivateState;
use App\Core\SendGridSignature;
use App\Core\Store;
use Velvet\Core\MarketingStore;

$source = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/vvx-mail-provider-' . bin2hex(random_bytes(8));
mkdir($root . '/config', 0700, true); mkdir($root . '/storage', 0700, true);
define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) require_once $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
});
require $source . '/app/Support/functions.php';
require $source . '/app/Core/MarketingStore.php';
$config = new Config($root . '/config/app.php');
$config->write(['storage_mode' => 'file', 'file_store_path' => 'storage/data.json', 'base_url' => 'http://127.0.0.1/nested/site',
    'mail' => ['mode' => 'disabled'], 'security' => ['app_key' => bin2hex(random_bytes(32))]]);
$store = new Store($config);
$owner = ['id' => 'provider-owner', 'email' => 'owner@example.test'];
$store->mutateFileStore(static function (array &$data) use ($owner): void {
    $data['admins'][$owner['id']] = $owner + ['role' => 'owner', 'is_active' => 1];
    $data['provider_sentinel'] = ['untouched' => 781];
});
$checks = 0;
$assert = static function (bool $okay, string $message) use (&$checks): void { ++$checks; if (!$okay) throw new RuntimeException($message); };
$reject = static function (callable $operation, string $message, ?int $code = null) use ($assert): void {
    try { $operation(); } catch (InvalidArgumentException|RuntimeException $error) { $assert($code === null || $code === $error->getCode(), $message . ' (expected rejection)'); return; }
    $assert(false, $message);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
    rmdir($path);
};
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
if (!$key) throw new RuntimeException('An actual ephemeral ECDSA signing key is required.');
if (!openssl_pkey_export($key, $ephemeralPrivate)) throw new RuntimeException('The ephemeral key rejection fixture is unavailable.');
$public = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', openssl_pkey_get_details($key)['key']);
$sign = static function (string $body, string $timestamp) use ($key): string {
    if (!openssl_sign($timestamp . $body, $signature, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Fixture signing failed.');
    return base64_encode($signature);
};
try {
    // Official sendgrid-php helper vector; its trailing CRLF is signed data.
    $vectorKey = 'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE83T4O/n84iotIvIW4mdBgQ/7dAfSmpqIM8kF9mN1flpVKS3GRqe62gw+2fNNRaINXvVpiglSI8eNEc6wEA3F+g==';
    $vectorSignature = 'MEUCIGHQVtGj+Y3LkG9fLcxf3qfI10QysgDWmMOVmxG0u6ZUAiEAyBiXDWzM+uOe5W0JuG+luQAbPIqHh89M15TluLtEZtM=';
    $vectorBody = json_encode([['email' => 'hello@world.com', 'event' => 'dropped', 'reason' => 'Bounced Address',
        'sg_event_id' => 'ZHJvcC0xMDk5NDkxOS1MUnpYbF9OSFN0T0doUTRrb2ZTbV9BLTA',
        'sg_message_id' => 'LRzXl_NHStOGhQ4kofSm_A.filterdrecv-p3mdw1-756b745b58-kmzbl-18-5F5FC76C-9.0',
        'smtp-id' => '<LRzXl_NHStOGhQ4kofSm_A@ismtpd0039p1iad1.sendgrid.net>', 'timestamp' => 1600112492]]) . "\r\n";
    $assert(SendGridSignature::verify($vectorKey, $vectorBody, $vectorSignature, '1600112502'), 'Official provider signature vector failed.');
    $assert(!SendGridSignature::verify($vectorKey, rtrim($vectorBody), $vectorSignature, '1600112502'), 'Re-encoded provider payload passed an untouched-byte signature.');
    $assert(SendGridSignature::publicKey(" \n" . $public . "\n") === $public, 'A pasted actual public verification key did not normalize.');
    foreach (['', 'SG.api-key', $ephemeralPrivate, base64_encode(base64_decode($public) . 'trailing'), str_repeat('x', 2049)] as $bad) {
        $reject(fn() => SendGridSignature::publicKey($bad), 'An invalid public verification key was accepted.');
    }
    $state = new PrivateState($config, $root);
    $service = new MailProviderService($config, $store, $state);
    $initial = $service->overview();
    $assert(!$initial['settings']['enabled'] && $state->read('mail_provider') === [] && !is_dir($root . '/storage/private/operations'), 'Read-only provider overview initialized state.');
    $reject(fn() => $service->receive('[]', 'bad', (string) time()), 'Disabled provider accepted an event.', 503);
    $service->saveSettings(['enabled' => true, 'public_key' => $public], $initial['revision'], $owner);
    $enabled = $state->read('mail_provider');
    $reject(fn() => $service->saveSettings(['enabled' => false, 'public_key' => ''], $initial['revision'], $owner), 'A stale form overwrote provider authorization.', 409);
    $assert($state->read('mail_provider') === $enabled, 'Rejected settings changed the verified journal.');
    $accept = static function (array $events, ?string $timestamp = null) use ($service, $sign): array {
        $body = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\r\n";
        $timestamp ??= (string) time();
        return $service->receive($body, $sign($body, $timestamp), $timestamp);
    };
    $event = static fn(string $id, string $kind, string $message, string $email = 'alpha@example.test', array $extra = []): array => array_replace([
        'sg_event_id' => $id, 'event' => $kind, 'smtp-id' => $message, 'email' => $email, 'timestamp' => time(),
        'ip' => '192.0.2.781', 'url' => 'https://example.test/PRIVATE_URL_SENTINEL', 'useragent' => 'PRIVATE_UA_SENTINEL', 'reason' => 'PRIVATE_REASON_SENTINEL'], $extra);
    $marketing = new MarketingStore($root);
    foreach (['alpha', 'beta', 'unknown'] as $name) { $contact = $marketing->subscribe($name . '@example.test', $name, 'provider source fixture', true); $marketing->confirm($contact['token']); }
    $campaign = $marketing->saveCampaignMessage(['subject' => 'Provider source', 'body' => 'Safe message', 'audience' => ['mode' => 'all']], $owner);
    $marketing->queueCampaign($campaign['id']);
    // Correlation-only source fixtures. HTTP acceptance proves actual SMTP IDs.
    $marketing->update(static function (array &$data): void {
        foreach ($data['campaign_queue'] as &$delivery) $delivery['status'] = $delivery['email'] === 'unknown@example.test' ? 'unknown' : 'sent';
    });
    $deliveries = array_column($marketing->all()['campaign_queue'], null, 'email');
    $alpha = $deliveries['alpha@example.test']['message_id']; $beta = $deliveries['beta@example.test']['message_id'];
    $queued = $marketing->saveCampaignMessage(['subject' => 'Unsent future', 'body' => 'Future message', 'audience' => ['mode' => 'all']], $owner);
    $marketing->queueCampaign($queued['id']);
    $newQueue = array_values(array_filter($marketing->all()['campaign_queue'], fn(array $row): bool => $row['campaign_id'] === $queued['id']));
    $assert($accept([$event('queued-cannot-prove-sending', 'bounce', $newQueue[0]['message_id'], 'alpha@example.test', ['type' => 'bounce'])])['unmatched'] === 1 && !$marketing->emailSuppressed('alpha@example.test'), 'An unsent delivery was trusted as provider proof.');
    $assert($accept([$event('wrong-recipient', 'bounce', $alpha, 'stranger@example.test', ['type' => 'bounce'])])['unmatched'] === 1 && !$marketing->emailSuppressed('stranger@example.test'), 'A foreign recipient inherited a saved Message-ID.');
    $assert($accept([$event('missing-message', 'bounce', '', 'alpha@example.test', ['type' => 'bounce'])])['unmatched'] === 1, 'Missing Message-ID was guessed from an address.');
    $blocked = $event('temporary-block', 'bounce', $alpha, 'alpha@example.test', ['type' => 'blocked']);
    $assert($accept([$blocked])['processed'] === 1 && !$marketing->emailSuppressed('alpha@example.test'), 'Temporary provider block became permanent suppression.');
    $assert($accept([$event('deferred', 'deferred', $beta, 'beta@example.test')])['processed'] === 1 && !$marketing->emailSuppressed('beta@example.test'), 'Deferral became permanent suppression.');
    $hard = $event('hard-bounce', 'bounce', $alpha, 'alpha@example.test', ['type' => 'bounce']);
    $assert($accept([$hard])['processed'] === 1 && $marketing->emailSuppressed('alpha@example.test'), 'Verified permanent bounce failed to suppress the exact recipient.');
    $before = [$state->read('mail_provider'), $marketing->all()];
    $assert($accept([$hard])['duplicates'] === 1 && [$state->read('mail_provider'), $marketing->all()] === $before, 'A repeated signed provider event changed committed effects.');
    $reject(fn() => $accept([array_replace($hard, ['event' => 'delivered'])]), 'A reused provider identity changed its meaning.', 409);
    $assert([$state->read('mail_provider'), $marketing->all()] === $before, 'Conflicting provider identity partially changed data.');
    $accept([$event('late-success', 'delivered', $alpha)]);
    $alphaDelivery = array_values(array_filter($marketing->all()['campaign_queue'], fn(array $row): bool => $row['message_id'] === $alpha))[0];
    $assert($alphaDelivery['provider_status'] === 'hard_bounce' && $alphaDelivery['status'] === 'sent' && $marketing->emailSuppressed('alpha@example.test'), 'Later delivery erased a bounce or rewrote the SMTP outcome.');
    $assert(count(array_filter($marketing->all()['campaign_queue'], fn(array $row): bool => $row['campaign_id'] === $queued['id'] && $row['email'] === 'alpha@example.test' && $row['status'] === 'suppressed')) === 1, 'Pending campaign survived permanent suppression.');
    $accept([$event('spam', 'spamreport', $beta, 'beta@example.test')]);
    $assert($marketing->emailSuppressed('beta@example.test'), 'Verified complaint failed to suppress.');
    $accept([$event('unsubscribe', 'unsubscribe', $alpha)]);
    $unknown = $deliveries['unknown@example.test']['message_id'];
    $accept([$event('unknown-delivered', 'delivered', $unknown, 'unknown@example.test')]);
    $unknownAfter = array_values(array_filter($marketing->all()['campaign_queue'], fn(array $row): bool => $row['message_id'] === $unknown))[0];
    $assert($unknownAfter['status'] === 'unknown' && $marketing->retryCampaign($campaign['id']) === 0, 'Provider feedback made an unknown SMTP outcome retryable.');
    $before = [$state->read('mail_provider'), $marketing->all()];
    $badBody = json_encode([$event('bad-signature', 'delivered', $beta, 'beta@example.test')]);
    foreach (['bad', base64_encode(random_bytes(70))] as $badSignature) $reject(fn() => $service->receive($badBody, $badSignature, (string) time()), 'Invalid signature accepted.', 401);
    foreach ([time() - SendGridSignature::MAX_SIGNATURE_AGE - 1, time() + SendGridSignature::MAX_CLOCK_SKEW + 1] as $date) $reject(fn() => $accept([$event('bad-clock', 'delivered', $alpha)], (string) $date), 'Out-of-window signature accepted.', 401);
    $reject(fn() => $accept([$event('partial-batch', 'delivered', $alpha), ['invalid' => true]]), 'Malformed batch partially staged valid rows.', 400);
    $reject(fn() => $accept([$event('old-event', 'delivered', $alpha, 'alpha@example.test', ['timestamp' => time() - 8 * 86400])]), 'Expired event metadata was accepted.', 400);
    $reject(fn() => $accept(array_fill(0, 1001, $event('too-many', 'delivered', $alpha))), 'Unbounded event batch was accepted.', 400);
    $reject(fn() => $service->receive(str_repeat('x', SendGridSignature::MAX_BODY_BYTES + 1), 'invalid', (string) time()), 'Oversized raw payload was accepted.', 413);
    $assert([$state->read('mail_provider'), $marketing->all()] === $before, 'Rejected signatures/batches mutated durable effects.');
    $accept([$event('ignore-open', 'open', $alpha), $event('ignore-click', 'click', $alpha)]);
    $privateBytes = json_encode($state->read('mail_provider'));
    $assert(!str_contains($privateBytes, 'PRIVATE_') && !str_contains($privateBytes, '@example.test') && !str_contains($privateBytes, '192.0.2.'), 'Provider journal retained sensitive/raw event fields.');
    $assert($service->overview()['counts']['ignored'] === 2, 'Engagement events entered delivery/suppression processing.');

    // Verified staging commits before effect storage; a real storage failure
    // leaves a recoverable pending event, with its recipient encrypted only.
    $path = $root . '/storage/private/marketing/data.json'; $saved = file_get_contents($path);
    file_put_contents($path, '{broken');
    $recover = $event('interrupted-effect', 'unsubscribe', $unknown, 'unknown@example.test');
    $reject(fn() => $accept([$recover]), 'Effect-storage failure was reported as completed.');
    $staged = $state->read('mail_provider')['events'][hash('sha256', 'sendgrid|interrupted-effect')];
    $assert($staged['status'] === 'pending' && is_string($staged['recipient_ciphertext']) && !str_contains($staged['recipient_ciphertext'], '@example.test'), 'Interrupted verified event lost its resumable encrypted recipient.');
    // Age only this owned fixture's already authenticated metadata. The runtime
    // receipt/worker accepts no caller-supplied current clock or retention bypass.
    $expiredId = hash('sha256', 'sendgrid|ignore-open');
    $state->update('mail_provider', static function (array &$data) use ($staged, $expiredId): void {
        foreach ([$staged['id'], $expiredId] as $id) {
            $row = &$data['events'][$id]; $row['received_at'] = time() - 9 * 86400; $row['event_at'] = time() - 9 * 86400;
            $semantic = array_intersect_key($row, array_flip(['id', 'event', 'event_at', 'email_hash', 'message_id']));
            $row['fingerprint'] = hash('sha256', json_encode($semantic, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); unset($row);
        }
    });
    $accept([$event('retention-trigger', 'open', $alpha)]);
    $retained = $state->read('mail_provider');
    $assert(!isset($retained['events'][$expiredId]), 'Completed provider metadata did not expire during later receipt maintenance.');
    $assert(($retained['events'][$staged['id']]['status'] ?? '') === 'pending', 'Retention deleted an interrupted verified event.');
    $state->update('mail_provider', static function (array &$data) use ($staged): void { $data['events'][$staged['id']] = $staged; });
    file_put_contents($path, $saved);
    $assert($marketing->emailSuppressed('alpha@example.test') && $marketing->emailSuppressed('beta@example.test'), 'Metadata retention removed active recipient suppressions.');
    $assert($service->reconcile(10, $owner)['processed'] === 1 && $marketing->emailSuppressed('unknown@example.test'), 'Verified interrupted effects could not resume idempotently.');
    $complete = $state->read('mail_provider')['events'][$staged['id']];
    $assert($complete['recipient_ciphertext'] === null && $service->reconcile()['examined'] === 0, 'Completed event retained recipient ciphertext or ran twice.');
    $before = $state->read('mail_provider');
    $store->mutateFileStore(static function (array &$data) use ($owner): void { $data['admins'][$owner['id']]['role'] = 'editor'; });
    $reject(fn() => $service->saveSettings(['enabled' => false, 'public_key' => ''], $service->overview()['revision'], $owner), 'Revoked owner replaced provider authorization.', 403);
    $reject(fn() => $service->reconcile(10, $owner), 'Revoked owner processed pending effects.', 403);
    $assert($state->read('mail_provider') === $before, 'Owner rejection changed the journal.');
    $assert(json_decode(file_get_contents($root . '/storage/data.json'), true)['provider_sentinel'] === ['untouched' => 781], 'Provider effects changed unrelated core state.');
    echo 'Mail-provider source acceptance passed (' . $checks . " checks; official signature vector + real ephemeral signatures; no network/mail).\n";
} finally { $remove($root); }

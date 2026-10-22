<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Velvet\Core\CampaignMessage;
use Velvet\Core\MarketingStore;

require_once __DIR__ . '/MarketingStore.php';

/** Verified SendGrid delivery events, with durable replay-safe suppression effects. */
final class MailProviderService
{
    private readonly PrivateState $state;
    private readonly SecureValue $secrets;
    private readonly string $root;
    private ?MarketingStore $marketing = null;

    public function __construct(private readonly Config $config, private readonly Store $store, ?PrivateState $state = null)
    {
        $this->root = dirname($config->path(), 2);
        $this->state = $state ?? new PrivateState($config, $this->root);
        $this->secrets = new SecureValue($config);
    }

    /** No recipient ciphertext or correlation identifier is exposed by the export projector. */
    public function privacyRows(string $emailHash): array
    {
        $data = $this->normalized($this->state->read('mail_provider'));
        return array_values(array_filter($data['events'], static fn(array $row): bool => ($row['email_hash'] ?? '') === $emailHash));
    }

    public function privacyForget(string $emailHash, callable $authorize): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $emailHash) !== 1) throw new InvalidArgumentException('A privacy recipient identity is required.');
        $this->mutate(static function (array &$data) use ($emailHash, $authorize): void {
            $authorize(); $data['erased_emails'][$emailHash] ??= time();
            foreach ($data['events'] as &$event) {
                if ($event['email_hash'] !== $emailHash) continue;
                if ($event['status'] === 'pending') { $event['status'] = 'unmatched'; $event['processed_at'] = time(); $event['delivery_kind'] = null; }
                $event['recipient_ciphertext'] = null;
            }
            unset($event); $authorize();
        });
    }

    public function overview(): array
    {
        $data = $this->normalized($this->state->read('mail_provider'));
        $counts = ['pending' => 0, 'processed' => 0, 'unmatched' => 0, 'ignored' => 0];
        foreach ($data['events'] as $event) ++$counts[$event['status']];
        $events = array_values($data['events']);
        usort($events, static fn(array $a, array $b): int => $b['received_at'] <=> $a['received_at']);
        $events = array_map(static function (array $event): array {
            return array_intersect_key($event, array_flip(['id', 'event', 'status', 'event_at', 'received_at', 'processed_at', 'delivery_kind']));
        }, array_slice($events, 0, 100));
        return ['settings' => $data['settings'], 'revision' => self::revision($data['settings']), 'counts' => $counts, 'events' => $events];
    }

    public function saveSettings(array $input, string $revision, array $owner): void
    {
        if (array_diff(array_keys($input), ['enabled', 'public_key']) !== [] || !is_bool($input['enabled'] ?? null) || !is_string($input['public_key'] ?? null)) throw new InvalidArgumentException('Choose valid provider-event settings.');
        $key = trim($input['public_key']) === '' && !$input['enabled'] ? '' : SendGridSignature::publicKey($input['public_key']);
        $this->mutate(function (array &$data) use ($input, $revision, $owner, $key): void {
            $this->requireOwner($owner);
            if (!hash_equals(self::revision($data['settings']), $revision)) throw new RuntimeException('Provider settings changed. Reload before saving.', 409);
            $data['settings'] = ['enabled' => $input['enabled'], 'public_key' => $key, 'revision' => $data['settings']['revision'] + 1];
            $this->requireOwner($owner);
        });
    }

    public function receive(string $rawBody, string $signature, string $timestamp): array
    {
        $current = $this->normalized($this->state->read('mail_provider'));
        if (!$current['settings']['enabled']) throw new RuntimeException('Provider events are disabled.', 503);
        if (strlen($rawBody) < 2 || strlen($rawBody) > SendGridSignature::MAX_BODY_BYTES) throw new RuntimeException('Provider payload size is invalid.', 413);
        if (preg_match('/\A[0-9]{10,12}\z/D', $timestamp) !== 1 || (int) $timestamp > time() + SendGridSignature::MAX_CLOCK_SKEW
            || (int) $timestamp < time() - SendGridSignature::MAX_SIGNATURE_AGE
            || !SendGridSignature::verify($current['settings']['public_key'], $rawBody, $signature, $timestamp)) throw new RuntimeException('Provider signature verification failed.', 401);
        try { $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new InvalidArgumentException('Provider JSON is invalid.', 400); }
        if (!is_array($payload) || !array_is_list($payload) || $payload === [] || count($payload) > SendGridSignature::MAX_BATCH_EVENTS) throw new InvalidArgumentException('A bounded provider event batch is required.', 400);
        $events = array_map(fn(mixed $row): array => $this->normalizeEvent($row), $payload);
        $expected = self::revision($current['settings']);
        $staged = $this->mutate(function (array &$data) use ($events, $expected): array {
            if (!$data['settings']['enabled'] || !hash_equals(self::revision($data['settings']), $expected)) throw new RuntimeException('Provider authorization changed during verification.', 401);
            $this->prune($data);
            $ids = []; $duplicates = 0;
            foreach ($events as $event) {
                if (isset($data['erased_emails'][$event['email_hash']]) && $event['status'] === 'pending') {
                    $event['status'] = 'unmatched'; $event['processed_at'] = time(); $event['recipient_ciphertext'] = null;
                }
                if (isset($data['events'][$event['id']])) {
                    if (!hash_equals($data['events'][$event['id']]['fingerprint'], $event['fingerprint'])) throw new RuntimeException('A provider event identity conflicts with a previous event.', 409);
                    ++$duplicates;
                } else {
                    if (count($data['events']) >= 20000) throw new RuntimeException('Provider event storage is full.', 503);
                    $data['events'][$event['id']] = $event;
                }
                $ids[$event['id']] = true;
            }
            return ['ids' => array_keys($ids), 'duplicates' => $duplicates];
        });
        $stats = ['accepted' => count($events), 'duplicates' => $staged['duplicates'], 'processed' => 0, 'unmatched' => 0, 'ignored' => 0];
        foreach ($staged['ids'] as $id) {
            $outcome = $this->apply($id);
            if (isset($stats[$outcome])) ++$stats[$outcome];
        }
        return $stats;
    }

    /** Resume already verified pending effects after an interrupted HTTP request. */
    public function reconcile(int $limit = 100, ?array $owner = null): array
    {
        if ($limit < 1 || $limit > 250) throw new InvalidArgumentException('Choose a provider reconciliation batch of 1 to 250.');
        if ($owner !== null) $this->requireOwner($owner);
        $data = $this->mutate(function (array &$data) use ($owner): array {
            if ($owner !== null) $this->requireOwner($owner);
            $this->prune($data);
            return $data;
        });
        if ($owner !== null) $this->requireOwner($owner);
        $stats = ['processed' => 0, 'unmatched' => 0, 'examined' => 0];
        foreach ($data['events'] as $id => $event) {
            if ($event['status'] !== 'pending' || $stats['examined'] >= $limit) continue;
            ++$stats['examined'];
            $outcome = $this->apply($id, $owner);
            if (isset($stats[$outcome])) ++$stats[$outcome];
        }
        return $stats;
    }

    private function apply(string $id, ?array $owner = null): string
    {
        $current = $this->normalized($this->state->read('mail_provider'));
        $event = $current['events'][$id] ?? null;
        if (!is_array($event)) throw new RuntimeException('The verified provider event is unavailable.');
        if ($event['status'] !== 'pending') return $event['status'];
        if ($owner !== null) $this->requireOwner($owner);
        $email = $this->secrets->decrypt($event['recipient_ciphertext']);
        if (!EmailAddress::isValid($email) || !hash_equals(hash('sha256', $email), $event['email_hash'])) throw new RuntimeException('The verified provider recipient needs review.');
        $delivery = $this->marketing()->providerDelivery($event['message_id'], $event['email_hash']);
        $notifications = new NotificationService($this->config, $this->store, null, $this->state);
        $delivery ??= $notifications->providerDelivery($event['message_id'], $event['email_hash']);
        if ($delivery !== null && $event['event_at'] < $delivery['created_at'] - SendGridSignature::MAX_CLOCK_SKEW) $delivery = null;
        if ($owner !== null) $this->requireOwner($owner);
        if ($delivery !== null) {
            $delivery['email'] = $email;
            $authorize = $owner === null ? null : fn(): mixed => $this->requireOwner($owner);
            $this->marketing()->applyProviderEvent($event, $delivery, $authorize);
            $notifications->recordProviderEvent($event, $owner);
        }
        return $this->mutate(function (array &$data) use ($event, $delivery, $owner): string {
            if ($owner !== null) $this->requireOwner($owner);
            if (!isset($data['events'][$event['id']])) throw new RuntimeException('The verified provider event is unavailable.');
            $saved = &$data['events'][$event['id']];
            if (!is_array($saved) || !hash_equals($saved['fingerprint'], $event['fingerprint'])) throw new RuntimeException('Provider event changed during processing.');
            if ($saved['status'] !== 'pending') return $saved['status'];
            $saved['status'] = $delivery === null ? 'unmatched' : 'processed';
            $saved['delivery_kind'] = $delivery['kind'] ?? null;
            $saved['processed_at'] = time();
            $saved['recipient_ciphertext'] = null;
            return $saved['status'];
        });
    }

    private function normalizeEvent(mixed $row): array
    {
        if (!is_array($row) || array_is_list($row) || !is_string($row['sg_event_id'] ?? null)
            || preg_match('/\A[A-Za-z0-9_+=.\/-]{1,512}\z/D', $row['sg_event_id']) !== 1
            || !is_string($row['event'] ?? null) || preg_match('/\A[a-z_]{1,40}\z/D', $row['event']) !== 1
            || !is_int($row['timestamp'] ?? null) || $row['timestamp'] > time() + SendGridSignature::MAX_CLOCK_SKEW
            || $row['timestamp'] < time() - 7 * 86400) throw new InvalidArgumentException('A provider event has invalid identity, type or date.', 400);
        $event = $row['event'];
        if ($event === 'bounce') {
            if (!in_array($row['type'] ?? null, ['bounce', 'blocked'], true)) throw new InvalidArgumentException('A bounce must identify its permanent or temporary type.', 400);
            $event = $row['type'] === 'bounce' ? 'hard_bounce' : 'blocked';
        }
        $supported = in_array($event, ['hard_bounce', 'blocked', 'spamreport', 'unsubscribe', 'delivered', 'deferred', 'dropped', 'processed'], true);
        $email = is_string($row['email'] ?? null) ? EmailAddress::normalize($row['email']) : '';
        if ($supported && !EmailAddress::isValid($email)) throw new InvalidArgumentException('A supported provider event requires a valid recipient.', 400);
        $messageId = is_string($row['smtp-id'] ?? null) ? $row['smtp-id'] : '';
        // Missing/foreign Message-IDs are recorded as unmatched, never guessed.
        if (preg_match('/\A<vvx\.[a-f0-9]{64}@mail\.velvet-vixxen\.invalid>\z/D', $messageId) !== 1) $messageId = '';
        $normalized = ['id' => hash('sha256', 'sendgrid|' . $row['sg_event_id']), 'event' => $supported ? $event : 'ignored',
            'event_at' => $row['timestamp'], 'email_hash' => $supported ? hash('sha256', $email) : '',
            'message_id' => $supported ? $messageId : ''];
        $fingerprint = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $pending = $supported && $messageId !== '';
        return $normalized + ['fingerprint' => $fingerprint, 'received_at' => time(), 'processed_at' => $pending ? null : time(),
            'recipient_ciphertext' => $pending ? $this->secrets->encrypt($email) : null,
            'status' => $supported ? ($pending ? 'pending' : 'unmatched') : 'ignored', 'delivery_kind' => null];
    }

    private static function revision(array $settings): string { return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR)); }
    private function prune(array &$data): void
    {
        foreach ($data['events'] as $key => $event) {
            if ($event['status'] !== 'pending' && $event['received_at'] < time() - 8 * 86400 && $event['event_at'] < time() - 8 * 86400) unset($data['events'][$key]);
        }
    }
    private function marketing(): MarketingStore { return $this->marketing ??= new MarketingStore($this->root); }
    private function requireOwner(array $identity): void
    {
        if (!is_string($identity['email'] ?? null) || !is_scalar($identity['id'] ?? null)) throw new RuntimeException('Current owner authentication is required.', 403);
        $owner = (new Store($this->config))->findAdminByEmail($identity['email']);
        if (!$owner || empty($owner['is_active']) || ($owner['role'] ?? '') !== 'owner' || (string) $owner['id'] !== (string) $identity['id']) throw new RuntimeException('Current owner authentication is required.', 403);
    }

    private function normalized(array $data): array
    {
        if ($data === []) return ['schema' => 1, 'settings' => ['enabled' => false, 'public_key' => '', 'revision' => 0], 'events' => []];
        if (($data['schema'] ?? null) !== 1 || !is_array($data['settings'] ?? null) || !is_bool($data['settings']['enabled'] ?? null)
            || !is_string($data['settings']['public_key'] ?? null) || !is_int($data['settings']['revision'] ?? null)
            || !is_array($data['events'] ?? null)) throw new RuntimeException('Provider event storage needs review.');
        if ($data['settings']['enabled']) SendGridSignature::publicKey($data['settings']['public_key']);
        foreach ($data['erased_emails'] ?? [] as $hash => $at) if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1 || !is_int($at) || $at < 1) throw new RuntimeException('Provider privacy markers need review.');
        foreach ($data['events'] as $key => $event) {
            if (!is_array($event) || $key !== ($event['id'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', (string) $key) !== 1
                || !is_string($event['fingerprint'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $event['fingerprint']) !== 1
                || !in_array($event['status'] ?? '', ['pending', 'processed', 'unmatched', 'ignored'], true)
                || !in_array($event['event'] ?? '', ['hard_bounce', 'blocked', 'spamreport', 'unsubscribe', 'delivered', 'deferred', 'dropped', 'processed', 'ignored'], true)
                || !is_int($event['event_at'] ?? null) || !is_int($event['received_at'] ?? null) || !is_string($event['email_hash'] ?? null)
                || !is_string($event['message_id'] ?? null) || !array_key_exists('recipient_ciphertext', $event)
                || ($event['status'] === 'pending' ? !is_string($event['recipient_ciphertext']) : $event['recipient_ciphertext'] !== null)
                || !array_key_exists('processed_at', $event) || ($event['status'] === 'pending' ? $event['processed_at'] !== null : !is_int($event['processed_at']))
                || !array_key_exists('delivery_kind', $event) || !in_array($event['delivery_kind'], [null, 'campaign', 'notification'], true)) throw new RuntimeException('A stored provider event needs review.');
            if (($event['event'] === 'ignored' && ($event['email_hash'] !== '' || $event['message_id'] !== '' || $event['status'] !== 'ignored'))
                || ($event['event'] !== 'ignored' && preg_match('/\A[a-f0-9]{64}\z/D', $event['email_hash']) !== 1)
                || ($event['message_id'] !== '' && preg_match('/\A<vvx\.[a-f0-9]{64}@mail\.velvet-vixxen\.invalid>\z/D', $event['message_id']) !== 1)
                || ($event['status'] === 'pending' && ($event['message_id'] === '' || strlen($event['recipient_ciphertext']) > 1000))) throw new RuntimeException('A stored provider correlation needs review.');
            $semantic = ['id' => $event['id'], 'event' => $event['event'], 'event_at' => $event['event_at'], 'email_hash' => $event['email_hash'], 'message_id' => $event['message_id']];
            if (!hash_equals($event['fingerprint'], hash('sha256', json_encode($semantic, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))) throw new RuntimeException('A stored provider event failed its integrity check.');
        }
        return $data;
    }

    private function mutate(callable $operation): mixed
    {
        return $this->state->update('mail_provider', function (array &$data) use ($operation): mixed {
            $data = $this->normalized($data);
            $erased = $data['erased_emails'] ?? [];
            $result = $operation($data);
            $markers = array_replace($data['erased_emails'] ?? [], $erased);
            if ($markers !== []) $data['erased_emails'] = $markers;
            foreach ($data['events'] as &$event) if (isset($markers[$event['email_hash']])) {
                if ($event['status'] === 'pending') { $event['status'] = 'unmatched'; $event['processed_at'] = time(); $event['delivery_kind'] = null; }
                $event['recipient_ciphertext'] = null;
            }
            unset($event);
            $this->normalized($data);
            return $result;
        });
    }
}

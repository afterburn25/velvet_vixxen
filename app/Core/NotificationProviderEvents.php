<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

trait NotificationProviderEvents
{
    public function providerDelivery(string $messageId, string $emailHash): ?array
    {
        $data = $this->normalized($this->state->read('notifications'));
        foreach ($data['jobs'] as $job) {
            if ($job['message_id'] === $messageId && in_array($job['status'], ['sending', 'sent', 'unknown'], true)
                && is_string($job['recipient_hash']) && hash_equals($emailHash, $job['recipient_hash'])) {
                return ['kind' => 'notification', 'id' => $job['id'], 'created_at' => $job['created_at']];
            }
        }
        return null;
    }

    public function recordProviderEvent(array $event, ?array $owner = null): void
    {
        if (!is_string($event['email_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $event['email_hash']) !== 1
            || !in_array($event['event'] ?? '', ['hard_bounce', 'blocked', 'spamreport', 'unsubscribe', 'delivered', 'deferred', 'dropped', 'processed'], true)
            || !is_string($event['message_id'] ?? null) || !is_int($event['event_at'] ?? null)) throw new InvalidArgumentException('A verified provider event is required.');
        $this->mutate(function (array &$data) use ($event, $owner): void {
            if ($owner !== null) $this->requireOwner($owner);
            $suppress = in_array($event['event'], ['hard_bounce', 'spamreport', 'unsubscribe'], true);
            foreach ($data['jobs'] as &$job) {
                if (!hash_equals($job['email_hash'], $event['email_hash'])) continue;
                if ($suppress && in_array($job['status'], ['queued', 'claimed', 'failed'], true)) {
                    $job['status'] = 'skipped'; $job['claim'] = null; $job['updated_at'] = time();
                    $job['last_error'] = 'A verified provider suppression blocks further optional notification delivery.';
                }
                if ($job['message_id'] === $event['message_id']) {
                    $prior = (string) ($job['provider_status'] ?? '');
                    if ($suppress || (!in_array($prior, ['hard_bounce', 'spamreport', 'unsubscribe'], true) && $event['event_at'] >= (int) ($job['provider_event_at'] ?? 0))) {
                        $job['provider_status'] = $event['event']; $job['provider_event_at'] = $event['event_at'];
                    }
                }
            }
            unset($job);
            if ($owner !== null) $this->requireOwner($owner);
        });
    }
}

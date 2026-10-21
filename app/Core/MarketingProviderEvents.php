<?php
declare(strict_types=1);

namespace Velvet\Core;

use App\Core\EmailAddress;
use InvalidArgumentException;
use RuntimeException;

/** Private provider effects share the same atomic marketing write as suppression. */
trait MarketingProviderEvents
{
    public function providerDelivery(string $messageId, string $emailHash): ?array
    {
        foreach ($this->all()['campaign_queue'] as $delivery) {
            if (!is_array($delivery) || ($delivery['message_id'] ?? null) !== $messageId
                || !in_array($delivery['status'] ?? '', ['sending', 'sent', 'unknown'], true)) continue;
            $email = EmailAddress::normalize((string) ($delivery['email'] ?? ''));
            if (!EmailAddress::isValid($email) || !hash_equals($emailHash, hash('sha256', $email))) continue;
            return ['kind' => 'campaign', 'id' => (string) $delivery['id'], 'email' => $email,
                'created_at' => (int) (strtotime((string) ($delivery['created_at'] ?? '')) ?: 0)];
        }
        return null;
    }

    /** Called only after signature verification and exact saved outbound correlation. */
    public function applyProviderEvent(array $event, array $delivery, ?callable $authorize = null): void
    {
        $email = EmailAddress::normalize((string) ($delivery['email'] ?? ''));
        if (!EmailAddress::isValid($email) || preg_match('/\A[a-f0-9]{64}\z/D', $event['id'] ?? '') !== 1
            || !hash_equals(hash('sha256', $email), $event['email_hash'] ?? '')
            || !in_array($event['event'] ?? '', ['hard_bounce', 'blocked', 'spamreport', 'unsubscribe', 'delivered', 'deferred', 'dropped', 'processed'], true)) {
            throw new InvalidArgumentException('A verified, correlated provider event is required.');
        }
        $this->update(static function (array &$data) use ($event, $delivery, $email, $authorize): void {
            if ($authorize !== null) $authorize();
            $effectKey = $event['id'];
            $effects = $data['settings']['mail_provider_effects'] ?? [];
            if (!is_array($effects)) throw new RuntimeException('Provider effects need storage review.');
            if (isset($effects[$effectKey])) {
                if (!is_array($effects[$effectKey]) || ($effects[$effectKey]['fingerprint'] ?? '') !== $event['fingerprint']) throw new RuntimeException('A provider event identity conflicts with its saved effect.', 409);
                return;
            }
            // Raw event metadata expires after eight days. Suppressions and
            // saved delivery outcomes remain; they are never removed by cleanup.
            foreach ($effects as $key => $effect) if (is_array($effect) && is_int($effect['recorded_at'] ?? null) && $effect['recorded_at'] < time() - 8 * 86400) unset($effects[$key]);
            if (count($effects) >= 20000) throw new RuntimeException('Provider effect storage is full; review delivery volume before accepting further events.');
            $suppress = in_array($event['event'], ['hard_bounce', 'spamreport', 'unsubscribe'], true);
            if ($suppress) {
                $suppressionId = 'sendgrid_' . hash('sha256', $email);
                $active = false;
                foreach ($data['suppressions'] as $suppression) {
                    if (EmailAddress::normalize((string) ($suppression['email'] ?? '')) === $email && (!array_key_exists('active', $suppression) || (bool) $suppression['active'])) $active = true;
                }
                if (!$active) {
                    $suppression = ['id' => $suppressionId, 'email' => $email, 'active' => true,
                        'reason' => 'sendgrid_' . $event['event'], 'created_at' => gmdate('c'), 'provider_event' => $event['id']];
                    $replaced = false;
                    foreach ($data['suppressions'] as &$saved) {
                        if (($saved['id'] ?? '') === $suppressionId) { $saved = $suppression; $replaced = true; break; }
                    }
                    unset($saved);
                    if (!$replaced) $data['suppressions'][] = $suppression;
                }
                foreach ($data['contacts'] as &$contact) {
                    if (EmailAddress::normalize((string) ($contact['email'] ?? '')) !== $email) continue;
                    $contact['suppressed'] = true;
                    $contact['updated_at'] = gmdate('c');
                    if ($event['event'] === 'hard_bounce') $contact['bounced'] = true;
                    if ($event['event'] === 'spamreport') $contact['complained'] = true;
                }
                unset($contact);
            }
            foreach ($data['campaign_queue'] as &$row) {
                if (EmailAddress::normalize((string) ($row['email'] ?? '')) !== $email) continue;
                if ($suppress && in_array($row['status'] ?? '', ['prepared', 'queued', 'claimed', 'failed'], true)) {
                    $row['status'] = 'suppressed'; $row['last_error'] = 'A verified provider suppression blocks further delivery.';
                    $row['updated_at'] = gmdate('c'); unset($row['claim_token']);
                }
                if (($row['message_id'] ?? '') === $event['message_id'] && ($delivery['kind'] ?? '') === 'campaign') {
                    $recorded = (int) ($row['provider_event_at'] ?? 0);
                    $prior = (string) ($row['provider_status'] ?? '');
                    if ($suppress || (!in_array($prior, ['hard_bounce', 'spamreport', 'unsubscribe'], true) && $event['event_at'] >= $recorded)) {
                        $row['provider_status'] = $event['event']; $row['provider_event_at'] = $event['event_at'];
                    }
                }
            }
            unset($row);
            $effects[$effectKey] = ['fingerprint' => $event['fingerprint'], 'recorded_at' => time()];
            $data['settings']['mail_provider_effects'] = $effects;
            if ($authorize !== null) $authorize();
        });
    }
}

<?php
declare(strict_types=1);

namespace App\Core;

use Velvet\Core\MarketingStore;

/** Bounded, resumable delivery of explicitly queued newsletter campaigns. */
final class CampaignDeliveryService
{
    public const MAX_BATCH = 25;
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly Config $config,
        private readonly MarketingStore $marketing,
        private readonly MailService $mail,
    ) {
    }

    public function holdReason(): ?string
    {
        if (empty($this->marketing->settings()['campaign_delivery_enabled'])) return 'Campaign email delivery is paused in Marketing settings.';
        if (($problem = $this->mail->configurationProblem()) !== null) return $problem;
        if ($this->publicBaseUrl() === null) return 'Configure the public website URL so every campaign has a working unsubscribe link.';
        return null;
    }

    /** Counts only; no recipient addresses, tokens or message bodies in CLI output. */
    public function run(int $limit = 10, ?string $campaignId = null): array
    {
        if ($limit < 1 || $limit > self::MAX_BATCH) throw new \InvalidArgumentException('Batch size must be between 1 and ' . self::MAX_BATCH . '.');
        $stats = ['processed' => 0, 'accepted' => 0, 'failed' => 0, 'unknown' => 0, 'suppressed' => 0,
            'held' => null, 'busy' => false, 'recovered_claims' => 0];
        $result = $this->marketing->withDeliveryLock(function () use ($limit, $campaignId, $stats): array {
            $this->config->reload();
            $recovered = $this->recoverInterrupted();
            $stats['recovered_claims'] = $recovered['claims'];
            $stats['unknown'] = $recovered['unknown'];
            if (($stats['held'] = $this->holdReason()) !== null) return $stats;
            for ($i = 0; $i < $limit; $i++) {
                $this->config->reload();
                if (($stats['held'] = $this->holdReason()) !== null) break;
                $claim = $this->claimNext($campaignId);
                if ($claim === null) break;
                $stats['processed']++;
                if ($claim['status'] !== 'claimed') {
                    $stats[$claim['status']]++;
                    continue;
                }
                $delivery = $claim['delivery'];
                $snapshot = $claim['snapshot'];
                $unsubscribe = $this->publicBaseUrl() . '/' . \vvx_public_route_relative_url('newsletter/unsubscribe.php?token=' . rawurlencode($claim['unsubscribe_token']));
                $body = trim((string) ($snapshot['preheader'] ?? ''));
                if ($body !== '') $body .= "\n\n";
                $body .= trim((string) $snapshot['body']) . "\n\nUnsubscribe from campaign emails: " . $unsubscribe;
                $outcome = $this->mail->deliver((string) $delivery['email'], (string) $snapshot['subject'], $body,
                    fn(): bool => $this->beginSending((string) $delivery['id'], (string) $delivery['claim_token']));
                $status = $this->finish((string) $delivery['id'], (string) $delivery['claim_token'], $outcome);
                if ($status === 'sent') $stats['accepted']++;
                elseif (isset($stats[$status]) && is_int($stats[$status])) $stats[$status]++;
            }
            return $stats;
        });
        if ($result === null) $stats['busy'] = true;
        return $result ?? $stats;
    }

    private function publicBaseUrl(): ?string
    {
        $base = rtrim(trim((string) $this->config->get('base_url', '')), '/');
        $parts = parse_url($base);
        if (!filter_var($base, FILTER_VALIDATE_URL) || !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) return null;
        return $base;
    }

    private function recoverInterrupted(): array
    {
        return $this->marketing->update(static function (array &$data): array {
            $count = 0;
            $unknown = 0;
            foreach ($data['campaign_queue'] as &$delivery) {
                if (($delivery['status'] ?? '') === 'sending') {
                    $unknown++;
                    $delivery['status'] = 'unknown';
                    $delivery['last_error'] = 'The worker stopped after transmission was authorized. Acceptance is unknown; automatic retry is blocked.';
                } elseif (($delivery['status'] ?? '') === 'claimed') {
                    $delivery['status'] = 'queued';
                    $delivery['last_error'] = 'The worker stopped before message transmission; the claim was released.';
                    $delivery['attempts'] = max(0, (int) ($delivery['attempts'] ?? 0) - 1);
                } else continue;
                $delivery['updated_at'] = gmdate('c');
                unset($delivery['claim_token']);
                $count++;
            }
            return ['claims' => $count, 'unknown' => $unknown];
        });
    }

    private function claimNext(?string $campaignId): ?array
    {
        return $this->marketing->update(function (array &$data) use ($campaignId): ?array {
            if (empty($data['settings']['campaign_delivery_enabled'])) return null;
            foreach ($data['campaign_queue'] as $index => &$delivery) {
                if (($delivery['status'] ?? '') !== 'queued' || ($campaignId !== null && ($delivery['campaign_id'] ?? '') !== $campaignId)) continue;
                $campaignIndex = array_search($delivery['campaign_id'] ?? '', array_column($data['campaigns'], 'id'), true);
                if ($campaignIndex === false) return $this->skip($delivery, 'suppressed', 'The campaign no longer exists.');
                $campaign = &$data['campaigns'][$campaignIndex];
                if (!empty($campaign['delivery_paused'])) continue;
                $schedule = trim((string) ($campaign['scheduled_at'] ?? ''));
                $scheduledAt = $schedule === '' ? 0 : strtotime($schedule);
                if ($scheduledAt === false) return $this->skip($delivery, 'failed', 'The campaign schedule is invalid. Correct it before retrying.');
                if ($scheduledAt > time() || (strtotime((string) ($delivery['next_attempt_at'] ?? '')) ?: 0) > time()) continue;
                if ((int) ($delivery['attempts'] ?? 0) >= self::MAX_ATTEMPTS) return $this->skip($delivery, 'failed', 'The limit of three delivery attempts was reached.');
                $contact = $this->marketing->eligibleContact($data, (string) ($delivery['contact_id'] ?? ''), (string) ($delivery['email'] ?? ''));
                if ($contact === null) return $this->skip($delivery, 'suppressed', 'The recipient is no longer confirmed, consented or eligible.');
                foreach ($data['campaign_queue'] as $otherIndex => $other) {
                    if ($otherIndex === $index || ($other['campaign_id'] ?? '') !== ($delivery['campaign_id'] ?? '')
                        || strtolower((string) ($other['email'] ?? '')) !== strtolower((string) ($delivery['email'] ?? ''))) continue;
                    if ($otherIndex < $index || in_array($other['status'] ?? '', ['sent', 'unknown', 'sending', 'claimed'], true)) {
                        return $this->skip($delivery, 'suppressed', 'A delivery already exists for this campaign and address.');
                    }
                }
                $campaign['delivery_snapshot'] ??= ['subject' => (string) ($campaign['subject'] ?? ''),
                    'preheader' => (string) ($campaign['preheader'] ?? ''), 'body' => (string) ($campaign['body'] ?? '')];
                $snapshot = $campaign['delivery_snapshot'];
                if (trim((string) $snapshot['subject']) === '' || trim((string) $snapshot['body']) === '') {
                    return $this->skip($delivery, 'failed', 'The campaign needs a subject and message.');
                }
                $delivery['status'] = 'claimed';
                $delivery['claim_token'] = bin2hex(random_bytes(24));
                $delivery['claimed_at'] = gmdate('c');
                $delivery['updated_at'] = gmdate('c');
                $delivery['attempts'] = (int) ($delivery['attempts'] ?? 0) + 1;
                $delivery['last_error'] = null;
                return ['status' => 'claimed', 'delivery' => $delivery, 'snapshot' => $snapshot,
                    'unsubscribe_token' => (string) $contact['unsubscribe_token']];
            }
            return null;
        });
    }

    private function skip(array &$delivery, string $status, string $reason): array
    {
        $delivery['status'] = $status;
        $delivery['last_error'] = $reason;
        $delivery['updated_at'] = gmdate('c');
        unset($delivery['claim_token']);
        return ['status' => $status];
    }

    private function beginSending(string $id, string $token): bool
    {
        $this->config->reload();
        $configurationReady = $this->mail->configurationProblem() === null && $this->publicBaseUrl() !== null;
        return $this->marketing->update(function (array &$data) use ($id, $token, $configurationReady): bool {
            foreach ($data['campaign_queue'] as &$delivery) {
                if (($delivery['id'] ?? '') !== $id || ($delivery['status'] ?? '') !== 'claimed'
                    || !hash_equals((string) ($delivery['claim_token'] ?? ''), $token)) continue;
                $campaignIndex = array_search($delivery['campaign_id'] ?? '', array_column($data['campaigns'], 'id'), true);
                if ($campaignIndex === false || !$this->marketing->eligibleContact($data, (string) $delivery['contact_id'], (string) $delivery['email'])) {
                    $this->skip($delivery, 'suppressed', 'Consent, suppression or campaign state changed before transmission.');
                    return false;
                }
                $campaign = $data['campaigns'][$campaignIndex];
                $scheduledAt = empty($campaign['scheduled_at']) ? 0 : strtotime((string) $campaign['scheduled_at']);
                if (!$configurationReady || empty($data['settings']['campaign_delivery_enabled']) || !empty($campaign['delivery_paused'])
                    || $scheduledAt === false || $scheduledAt > time()) {
                    $delivery['attempts'] = max(0, (int) ($delivery['attempts'] ?? 0) - 1);
                    $this->skip($delivery, 'queued', 'Delivery was held before transmission because its settings or schedule changed.');
                    return false;
                }
                $delivery['status'] = 'sending';
                $delivery['transmission_started_at'] = gmdate('c');
                return true;
            }
            return false;
        });
    }

    private function finish(string $id, string $token, array $outcome): string
    {
        return $this->marketing->update(function (array &$data) use ($id, $token, $outcome): string {
            foreach ($data['campaign_queue'] as &$delivery) {
                if (($delivery['id'] ?? '') !== $id) continue;
                if (!in_array($delivery['status'] ?? '', ['claimed', 'sending'], true)
                    || !hash_equals((string) ($delivery['claim_token'] ?? ''), $token)) return (string) $delivery['status'];
                $status = (string) ($outcome['status'] ?? 'unknown');
                if (!in_array($status, ['sent', 'failed', 'unknown', 'skipped'], true)) $status = 'unknown';
                if ($status === 'unknown' && $delivery['status'] === 'claimed') {
                    $status = 'failed'; // Our callback was never reached; no message body was transmitted.
                    $outcome['lastError'] = 'Delivery failed before message transmission.';
                }
                if ($status === 'skipped') $delivery['attempts'] = max(0, (int) ($delivery['attempts'] ?? 0) - 1);
                $delivery['status'] = $status === 'skipped' ? 'queued' : $status;
                $delivery['last_error'] = $outcome['lastError'] ?? null;
                $delivery['updated_at'] = gmdate('c');
                if ($status === 'sent') $delivery['accepted_at'] = gmdate('c');
                if ($status === 'failed') $delivery['next_attempt_at'] = gmdate('c', time() + 60 * max(1, (int) $delivery['attempts']));
                unset($delivery['claim_token']);
                return (string) $delivery['status'];
            }
            return 'unknown';
        });
    }
}

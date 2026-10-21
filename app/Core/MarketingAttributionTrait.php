<?php
declare(strict_types=1);

namespace Velvet\Core;

use App\Core\Config;
use App\Core\Store;
use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/MarketingAttribution.php';

trait MarketingAttributionTrait
{
    public function attributionVisitor(?string $token, ?string $referralCode, bool $consent): ?array
    {
        if (!$consent) return null;
        return $this->update(function (array &$data) use ($token, $referralCode): ?array {
            if (($data['settings']['analytics_enabled'] ?? true) !== true) return null;
            $state = MarketingAttribution::state($data);
            $now = time();
            $existing = $this->visitorFromState($state, $token, $now);
            $referral = $referralCode !== null ? MarketingAttribution::referral($data, $referralCode) : null;
            if ($referralCode !== null && $referral === null) return $existing === null ? null : ['token' => $token, 'expires_at' => $existing['expires_at']];
            if ($existing !== null && ($referral === null || ($existing['referral_id'] === $referral['id'] && $existing['referral_code'] === $referral['code']))) {
                return ['token' => $token, 'expires_at' => $existing['expires_at']];
            }
            if ($existing === null) {
                $this->expireAttributionVisitors($state, $now);
                if (count($state['visits']) >= MarketingAttribution::MAX_VISITS) return null;
                $token = bin2hex(random_bytes(32));
                $existing = ['version' => 1, 'actor' => hash_hmac('sha256', $token, (string) $data['meta']['analytics_salt']),
                    'referral_id' => null, 'referral_code' => null, 'campaign_id' => null, 'delivery_id' => null,
                    'captured_at' => $now, 'expires_at' => $now + MarketingAttribution::LIFETIME];
            }
            if ($referral !== null) {
                $existing['referral_id'] = $referral['id']; $existing['referral_code'] = $referral['code'];
                $existing['captured_at'] = $now; $existing['expires_at'] = $now + MarketingAttribution::LIFETIME;
            }
            $state['visits'][hash('sha256', (string) $token)] = $existing;
            $data[MarketingAttribution::KEY] = $state;
            return ['token' => $token, 'expires_at' => $existing['expires_at']];
        });
    }

    /** No caller-supplied member, recipient, referral label or destination is accepted by the public resolver. */
    public function deliveryTrackedUrl(string $campaignId, string $deliveryId, string $destination): string
    {
        MarketingAudience::id($campaignId); MarketingAudience::id($deliveryId);
        $baseUrl = $this->attributionBaseUrl();
        $destination = MarketingAttribution::destination($destination, $baseUrl);
        $token = $this->update(function (array &$data) use ($campaignId, $deliveryId, $destination): string {
            $campaign = null; $delivery = null;
            foreach ($data['campaigns'] as $record) if (($record['id'] ?? '') === $campaignId) $campaign = $record;
            foreach ($data['campaign_queue'] as $record) if (($record['id'] ?? '') === $deliveryId && ($record['campaign_id'] ?? '') === $campaignId) $delivery = $record;
            if ($campaign === null || $delivery === null || !isset($campaign['delivery_snapshot'])) throw new RuntimeException('Tracking requires an actual saved campaign delivery.');
            $state = MarketingAttribution::state($data);
            $identity = hash('sha256', $campaignId . "\0" . $deliveryId . "\0" . $destination);
            foreach ($state['links'] as $hash => $record) {
                $record = $this->validTrackedLink($hash, $record);
                if (hash_equals($record['identity'], $identity)) return $record['token'];
            }
            if (count($state['links']) >= MarketingAttribution::MAX_LINKS) throw new RuntimeException('The campaign link limit was reached. Review retention before sending more campaigns.');
            $token = bin2hex(random_bytes(32));
            $state['links'][hash('sha256', $token)] = ['token' => $token, 'identity' => $identity, 'campaign_id' => $campaignId,
                'delivery_id' => $deliveryId, 'destination' => $destination, 'created_at' => time()];
            $data[MarketingAttribution::KEY] = $state;
            return $token;
        });
        return rtrim($baseUrl, '/') . '/' . \vvx_public_route_relative_url('campaign/click.php?t=' . rawurlencode($token));
    }

    public function resolveTrackedLink(string $token, bool $consent, array $requestContext = []): ?array
    {
        try { MarketingAttribution::token($token); } catch (InvalidArgumentException) { return null; }
        $visitorToken = is_string($requestContext['visitor_token'] ?? null) ? $requestContext['visitor_token'] : '';
        return $this->update(function (array &$data) use ($token, $consent, $visitorToken): ?array {
            $state = MarketingAttribution::state($data); $hash = hash('sha256', $token);
            if (!isset($state['links'][$hash])) return null;
            $link = $this->validTrackedLink($hash, $state['links'][$hash]);
            $result = ['destination' => $link['destination'], 'visitor_token' => null, 'expires_at' => null];
            if (!$consent || ($data['settings']['analytics_enabled'] ?? true) !== true) return $result;
            $campaignFound = false;
            foreach ($data['campaigns'] as $campaign) if (($campaign['id'] ?? '') === $link['campaign_id']) $campaignFound = true;
            if (!$campaignFound) return $result; // Removed campaigns keep safe links usable, but create no new attribution.
            $now = time(); $visitor = $this->visitorFromState($state, $visitorToken, $now);
            if ($visitor === null) {
                $this->expireAttributionVisitors($state, $now);
                if (count($state['visits']) >= MarketingAttribution::MAX_VISITS) return $result;
                $visitorToken = bin2hex(random_bytes(32));
                $visitor = ['version' => 1, 'actor' => hash_hmac('sha256', $visitorToken, (string) $data['meta']['analytics_salt']),
                    'referral_id' => null, 'referral_code' => null, 'campaign_id' => null, 'delivery_id' => null,
                    'captured_at' => $now, 'expires_at' => $now + MarketingAttribution::LIFETIME];
            }
            $visitor['campaign_id'] = $link['campaign_id']; $visitor['delivery_id'] = $link['delivery_id'];
            $visitor['captured_at'] = $now; $visitor['expires_at'] = $now + MarketingAttribution::LIFETIME;
            $state['visits'][hash('sha256', $visitorToken)] = $visitor;
            $data[MarketingAttribution::KEY] = $state;
            $this->appendMeasurement($data, 'campaign_click', $visitor, ['source_key' => 'click:' . $hash . ':' . $visitor['actor']]);
            return ['destination' => $link['destination'], 'visitor_token' => $visitorToken, 'expires_at' => $visitor['expires_at']];
        });
    }

    public function attributionSnapshot(string $token, bool $consent): ?array
    {
        if (!$consent) return null;
        $data = $this->all();
        if (($data['settings']['analytics_enabled'] ?? true) !== true) return null;
        $snapshot = $this->visitorFromState(MarketingAttribution::state($data), $token, time());
        if ($snapshot === null) return null;
        if ($snapshot['referral_code'] !== null) {
            $definition = MarketingAttribution::referral($data, $snapshot['referral_code']);
            if ($definition === null || $definition['id'] !== $snapshot['referral_id']) {
                $snapshot['referral_code'] = null; $snapshot['referral_id'] = null;
            }
        }
        if ($snapshot['campaign_id'] !== null && !in_array($snapshot['campaign_id'], array_column($data['campaigns'], 'id'), true)) {
            $snapshot['campaign_id'] = null; $snapshot['delivery_id'] = null;
        }
        return $snapshot;
    }

    public function requestAttribution(): ?array
    {
        return $this->attributionSnapshot(MarketingPrivacy::visitor(), MarketingPrivacy::choice($this->root) === true);
    }

    /** This snapshot is persisted with the order before charging, so reporting failures cannot lose its origin. */
    public function orderAttribution(array $member, string $couponCode): ?array
    {
        if (MarketingPrivacy::choice($this->root) !== true) return null;
        $snapshot = $this->requestAttribution();
        if ($snapshot === null) {
            try { $snapshot = MarketingAttribution::snapshot($member['preferences']['_attribution'] ?? null); }
            catch (InvalidArgumentException) { return null; }
            if ($snapshot !== null && $snapshot['expires_at'] <= time()) $snapshot = null;
        }
        if ($snapshot === null) return null;
        $data = $this->all(); $state = MarketingAttribution::state($data);
        if (($data['settings']['analytics_enabled'] ?? true) !== true || isset($state['erased_actors'][$snapshot['actor']])) return null;
        if ($snapshot['referral_code'] !== null) {
            $referral = MarketingAttribution::referral($data, $snapshot['referral_code']);
            if ($referral === null || $referral['id'] !== $snapshot['referral_id']) { $snapshot['referral_code'] = null; $snapshot['referral_id'] = null; }
        }
        if ($snapshot['campaign_id'] !== null && !in_array($snapshot['campaign_id'], array_column($data['campaigns'], 'id'), true)) { $snapshot['campaign_id'] = null; $snapshot['delivery_id'] = null; }
        $assignment = $state['coupons'][strtoupper(trim($couponCode))] ?? null;
        if (is_array($assignment) && ($assignment['enabled'] ?? false) === true
            && is_string($assignment['campaign_id'] ?? null) && in_array($assignment['campaign_id'], array_column($data['campaigns'], 'id'), true)) {
            $snapshot['campaign_id'] = $assignment['campaign_id']; $snapshot['delivery_id'] = null;
        }
        return MarketingAttribution::snapshot($snapshot);
    }

    public function recordRegistrationAttribution(Store $store, string $memberId): bool
    {
        $member = $store->marketingAttributionSource('member', $memberId);
        if ($member === null) return false;
        $snapshot = MarketingAttribution::snapshot($member['preferences']['_attribution'] ?? null);
        if ($snapshot === null) return false;
        return $this->saveAttributionConversion('registration', $memberId, $memberId, $snapshot, ['created_at' => (string) $member['createdAt']]);
    }

    public function recordOrderAttribution(Store $store, string $orderId): bool
    {
        $order = $store->marketingAttributionSource('order', $orderId);
        if ($order === null) return false;
        $snapshot = MarketingAttribution::snapshot($order['metadata']['attribution'] ?? null);
        if ($snapshot === null) return false;
        if (!in_array($order['status'] ?? null, ['pending','processing','failed','cancelled','paid','partially_refunded','refunded','chargeback'], true)
            || !is_numeric($order['grandTotal'] ?? null) || !is_finite((float) $order['grandTotal']) || (float) $order['grandTotal'] < 0
            || !is_string($order['currency'] ?? null) || !preg_match('/\A[A-Z]{3}\z/D', $order['currency'])) {
            throw new RuntimeException('The business payment outcome needs review before measurement.');
        }
        $kind = in_array($order['status'] ?? '', ['paid','partially_refunded','refunded','chargeback'], true) ? 'purchase' : 'checkout';
        if ($kind === 'purchase' && !is_string($order['paidAt'] ?? null)) throw new RuntimeException('A successful payment needs its confirmed payment time before measurement.');
        // A recovered paid order also restores its earlier checkout stage exactly once.
        if ($kind === 'purchase') $this->saveAttributionConversion('checkout', $orderId, (string) $order['memberId'], $snapshot,
            ['created_at' => (string) $order['createdAt'], 'currency' => (string) $order['currency'], 'status' => 'pending']);
        return $this->saveAttributionConversion($kind, $orderId, (string) $order['memberId'], $snapshot,
            ['created_at' => (string) ($kind === 'purchase' ? $order['paidAt'] : $order['createdAt']), 'amount_minor' => (int) round((float) $order['grandTotal'] * 100),
                'currency' => (string) $order['currency'], 'coupon' => (string) ($order['couponCode'] ?? ''), 'status' => (string) $order['status']]);
    }

    private function saveAttributionConversion(string $type, string $sourceId, string $memberId, array $snapshot, array $facts): bool
    {
        return $this->update(function (array &$data) use ($type, $sourceId, $memberId, $snapshot, $facts): bool {
            if (isset($data['settings']['member_privacy_erased_members'][hash('sha256', $memberId)])
                || (new Store(new \App\Core\Config($this->root . '/config/app.php')))->privacyMemberErased($memberId)) return false;
            $state = MarketingAttribution::state($data); $key = hash('sha256', $type . ':' . $sourceId);
            $epoch = isset($facts['created_at']) && is_string($facts['created_at']) ? strtotime($facts['created_at']) : false;
            if ($epoch === false || $epoch < time() - MarketingAnalytics::state($data)['retention_days'] * 86400
                || ($data['settings']['analytics_enabled'] ?? true) !== true) return false;
            if (isset($state['erased_actors'][$snapshot['actor']])) return false;
            if (isset($state['conversions'][$key])) return false;
            if (count($state['conversions']) >= 100000) throw new RuntimeException('Attribution reporting needs retention review. Original business records remain preserved.');
            if (!$this->appendMeasurement($data, $type, $snapshot, $facts + ['source_key' => $key])) return false;
            $state['conversions'][$key] = ['type' => $type, 'source_id' => $sourceId, 'member_id' => $memberId,
                'attribution' => $snapshot, 'facts' => $facts, 'recorded_at' => time()];
            $data[MarketingAttribution::KEY] = $state;
            return true;
        });
    }

    /** Browser revocation removes its retained measurements, never newsletter consent/suppression. */
    public function forgetAttributionVisitor(string $token): int
    {
        try { MarketingAttribution::token($token); } catch (InvalidArgumentException) { return 0; }
        return $this->update(function (array &$data) use ($token): int {
            $actor = hash_hmac('sha256', $token, (string) $data['meta']['analytics_salt']);
            return $this->eraseMeasurementActors($data, [$actor]);
        });
    }

    /** The authenticated account-deletion service calls this before pseudonymizing source records. */
    public function forgetMemberAttribution(Store $store, string $memberId, ?callable $authorize = null): int
    {
        $actors = $store->marketingAttributionActors($memberId);
        return $this->update(function (array &$data) use ($actors, $memberId, $authorize): int {
            if ($authorize !== null) $authorize();
            $state = MarketingAttribution::state($data); $own = []; $shared = [];
            foreach ($state['conversions'] as $key => $conversion) {
                $actor = $conversion['attribution']['actor'] ?? null;
                if (!is_string($actor)) continue;
                if (($conversion['member_id'] ?? '') === $memberId) { $actors[] = $actor; $own[$key] = true; }
                else $shared[$actor] = true;
            }
            // A browser may have been shared by different members. Their linked
            // business conversions stay intact; only this member's sources are removed.
            $exclusive = array_values(array_filter(array_unique($actors), static fn(string $actor): bool => !isset($shared[$actor])));
            $removed = $this->eraseMeasurementActors($data, $exclusive, true);
            $state = MarketingAttribution::state($data); $analytics = MarketingAnalytics::state($data);
            foreach ($state['conversions'] as $key => $conversion) if (isset($own[$key])) { unset($state['conversions'][$key]); ++$removed; }
            $sourceHashes = array_fill_keys(array_map(static fn(string $key): string => hash('sha256', $key), array_keys($own)), true);
            $kept = [];
            foreach ($data['events'] as $event) {
                if (!is_array($event) || !isset($sourceHashes[$event['source_key'] ?? ''])) { $kept[] = $event; continue; }
                $event = MarketingAnalytics::event($event); $key = MarketingAnalytics::rollupKey($event);
                if (isset($analytics['rollups'][$key])) {
                    $row = MarketingAnalytics::rollup($analytics['rollups'][$key]);
                    $row['count'] = max(0, $row['count'] - 1); $row['amount_minor'] = max(0, $row['amount_minor'] - $event['amount_minor']);
                    if ($row['count'] === 0) unset($analytics['rollups'][$key]); else $analytics['rollups'][$key] = $row;
                }
                ++$removed;
            }
            $data['events'] = $kept; $data[MarketingAttribution::KEY] = $state; $data[MarketingAnalytics::KEY] = $analytics;
            $data['settings']['member_privacy_erased_members'][hash('sha256', $memberId)] ??= time();
            if ($authorize !== null) $authorize();
            return $removed;
        });
    }

    private function eraseMeasurementActors(array &$data, array $actors, bool $sourceKnown = false): int
    {
        foreach ($actors as $actor) MarketingAttribution::token($actor);
        $actors = array_fill_keys(array_unique($actors), true);
        $state = MarketingAttribution::state($data); $analytics = MarketingAnalytics::state($data); $removed = 0;
        $found = $sourceKnown ? $actors : [];
        foreach ($state['visits'] as $key => $snapshot) {
            $snapshot = MarketingAttribution::snapshot($snapshot);
            if ($snapshot !== null && isset($actors[$snapshot['actor']])) {
                $found[$snapshot['actor']] = true; unset($state['visits'][$key]); $removed++;
            }
        }
        foreach ($state['conversions'] as $key => $conversion) {
            $actor = $conversion['attribution']['actor'] ?? null;
            if (is_string($actor) && isset($actors[$actor])) { $found[$actor] = true; unset($state['conversions'][$key]); $removed++; }
        }
        $kept = [];
        foreach ($data['events'] as $event) {
            if (!is_array($event) || !is_string($event['actor'] ?? null) || !isset($actors[$event['actor']])) { $kept[] = $event; continue; }
            $event = MarketingAnalytics::event($event); $key = MarketingAnalytics::rollupKey($event); $found[$event['actor']] = true;
            if (isset($analytics['rollups'][$key])) {
                $row = MarketingAnalytics::rollup($analytics['rollups'][$key]);
                $row['count'] = max(0, $row['count'] - 1); $row['amount_minor'] = max(0, $row['amount_minor'] - $event['amount_minor']);
                if ($row['count'] === 0) unset($analytics['rollups'][$key]); else $analytics['rollups'][$key] = $row;
            }
            $removed++;
        }
        if ($found === []) return 0;
        foreach ($found as $actor => $_) $state['erased_actors'][$actor] = time();
        MarketingAttribution::state([MarketingAttribution::KEY => $state]);
        $data['events'] = $kept; $data[MarketingAnalytics::KEY] = $analytics; $data[MarketingAttribution::KEY] = $state;
        return $removed;
    }

    private function visitorFromState(array $state, ?string $token, int $now, bool $requireCurrent = true): ?array
    {
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/D', $token)) return null;
        $record = $state['visits'][hash('sha256', $token)] ?? null;
        if ($record === null) return null;
        $record = MarketingAttribution::snapshot($record);
        return $record !== null && !isset($state['erased_actors'][$record['actor']]) && $record['captured_at'] <= $now
            && (!$requireCurrent || $record['expires_at'] > $now) ? $record : null;
    }

    private function expireAttributionVisitors(array &$state, int $now): void
    {
        foreach ($state['visits'] as $hash => $record) {
            if (!is_string($hash) || !preg_match('/\A[a-f0-9]{64}\z/D', $hash)) throw new RuntimeException('Saved attribution needs review.');
            $record = MarketingAttribution::snapshot($record);
            if ($record !== null && $record['expires_at'] <= $now) unset($state['visits'][$hash]);
        }
    }

    private function validTrackedLink(mixed $hash, mixed $record): array
    {
        if (!is_string($hash) || !is_array($record) || array_diff(array_keys($record), ['token','identity','campaign_id','delivery_id','destination','created_at']) !== []
            || !is_string($record['token'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $record['token'])
            || !hash_equals(hash('sha256', $record['token']), $hash) || !is_string($record['identity'] ?? null)
            || !is_int($record['created_at'] ?? null)) throw new RuntimeException('Saved campaign tracking link needs review.');
        MarketingAudience::id($record['campaign_id'] ?? null); MarketingAudience::id($record['delivery_id'] ?? null);
        $record['destination'] = MarketingAttribution::destination($record['destination'] ?? null, $this->attributionBaseUrl());
        if (!hash_equals(hash('sha256', $record['campaign_id'] . "\0" . $record['delivery_id'] . "\0" . $record['destination']), $record['identity'])) throw new RuntimeException('Saved campaign tracking link needs review.');
        return $record;
    }

    private function attributionBaseUrl(): string
    {
        $base = rtrim((string) (new Config($this->root . '/config/app.php'))->get('base_url', ''), '/');
        if (!preg_match('#\Ahttps?://#i', $base) || parse_url($base, PHP_URL_HOST) === null) throw new RuntimeException('Configure this installation URL before creating campaign links.');
        return $base;
    }
}

<?php
declare(strict_types=1);

namespace Velvet\Core;

use App\Core\Store;
use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/MarketingAnalytics.php';

trait MarketingAnalyticsTrait
{
    /** All HTTP collection goes through the independently signed measurement choice. */
    public function recordMeasuredEvent(string $name, array $properties = []): bool
    {
        if (MarketingPrivacy::choice($this->root) !== true || !in_array($name, MarketingAnalytics::EVENTS, true)
            || in_array($name, ['registration', 'checkout', 'purchase', 'campaign_click'], true)) return false;
        $path = MarketingAnalytics::path($properties['path'] ?? ($_SERVER['REQUEST_URI'] ?? '/'), $this->attributionBaseUrl());
        if ($path === null) return false;
        if (MarketingPrivacy::visitor() === '') return false;
        $visitor = $this->attributionVisitor(MarketingPrivacy::visitor(), null, true);
        if ($visitor === null) return false;
        // The public helper sets this cookie before output. A low-level call must
        // never manufacture an unreturnable identifier for anonymous requests.
        if (MarketingPrivacy::visitor() !== $visitor['token']) return false;
        return $this->update(function (array &$data) use ($name, $properties, $path, $visitor): bool {
            $snapshot = $this->visitorFromState(MarketingAttribution::state($data), $visitor['token'], time());
            return $snapshot !== null && $this->appendMeasurement($data, $name, $snapshot, ['path' => $path] + $properties);
        });
    }

    private function appendMeasurement(array &$data, string $name, array $snapshot, array $properties = []): bool
    {
        if (($data['settings']['analytics_enabled'] ?? true) !== true || !in_array($name, MarketingAnalytics::EVENTS, true)) return false;
        $snapshot = MarketingAttribution::snapshot($snapshot);
        if ($snapshot === null || isset(MarketingAttribution::state($data)['erased_actors'][$snapshot['actor']])) return false;
        $state = MarketingAnalytics::state($data); $now = time();
        $epoch = isset($properties['created_at']) && is_string($properties['created_at']) ? strtotime($properties['created_at']) : $now;
        if ($epoch === false || $epoch > $now + 60 || $epoch < $now - $state['retention_days'] * 86400) return false;
        $path = MarketingAnalytics::path($properties['path'] ?? (in_array($name, ['registration','checkout','purchase'], true) ? '/shop/' : '/campaign/'));
        if ($path === null) return false;
        $source = isset($properties['source_key']) && is_string($properties['source_key']) ? hash('sha256', $properties['source_key']) : null;
        foreach ($data['events'] as $event) {
            if ($source !== null && is_array($event) && ($event['measurement_version'] ?? null) === 1 && ($event['source_key'] ?? null) === $source) return false;
        }
        $data['events'] = array_values(array_filter($data['events'], static fn($event): bool => !is_array($event)
            || (strtotime(is_string($event['created_at'] ?? null) ? $event['created_at'] : '') ?: 0) >= $now - $state['retention_days'] * 86400));
        if (count($data['events']) >= MarketingAnalytics::MAX_EVENTS) return false;
        $event = MarketingAnalytics::event(['measurement_version' => 1, 'id' => bin2hex(random_bytes(16)), 'name' => $name,
            'path' => $path, 'actor' => $snapshot['actor'], 'referral' => $snapshot['referral_code'], 'campaign' => $snapshot['campaign_id'],
            'currency' => $properties['currency'] ?? null, 'amount_minor' => $name === 'purchase' ? ($properties['amount_minor'] ?? 0) : 0,
            'source_key' => $source, 'epoch' => $epoch, 'created_at' => gmdate('c', $epoch)]);
        $key = MarketingAnalytics::rollupKey($event);
        if (!isset($state['rollups'][$key]) && count($state['rollups']) >= MarketingAnalytics::MAX_ROLLUPS) return false;
        $rollup = $state['rollups'][$key] ?? ['date' => gmdate('Y-m-d', $epoch), 'name' => $name, 'path' => $path,
            'referral' => $event['referral'], 'campaign' => $event['campaign'], 'currency' => $event['currency'], 'count' => 0, 'amount_minor' => 0];
        $rollup = MarketingAnalytics::rollup($rollup); $rollup['count']++; $rollup['amount_minor'] += $event['amount_minor'];
        $state['rollups'][$key] = $rollup; $data[MarketingAnalytics::KEY] = $state; $data['events'][] = $event;
        return true;
    }

    public function analyticsConfiguration(): array
    {
        return $this->analyticsConfigurationFromData($this->all());
    }

    private function analyticsConfigurationFromData(array $data): array
    {
        return MarketingAnalytics::configuration($data);
    }

    public function saveAnalyticsConfiguration(array $input, string $revision, callable $authorize): array
    {
        if (array_diff(array_keys($input), ['enabled','retention_days','rollup_days']) !== [] || !is_bool($input['enabled'] ?? null)
            || !is_int($input['retention_days'] ?? null) || !is_int($input['rollup_days'] ?? null)) throw new InvalidArgumentException('Choose valid measurement and retention settings.');
        return $this->update(function (array &$data) use ($input, $revision, $authorize): array {
            $authorize(); $current = $this->analyticsConfigurationFromData($data);
            if (!MarketingAudience::validRevision($revision) || !hash_equals($current['revision'], $revision)) throw new RuntimeException('Measurement settings changed. Reload before saving.');
            $state = MarketingAnalytics::state($data); $state['retention_days'] = $input['retention_days']; $state['rollup_days'] = $input['rollup_days'];
            MarketingAnalytics::state([MarketingAnalytics::KEY => $state]);
            $data['settings']['analytics_enabled'] = $input['enabled']; $data[MarketingAnalytics::KEY] = $state;
            $authorize(); return $this->analyticsConfigurationFromData($data);
        });
    }

    public function measurementReport(array $input = []): array
    {
        $filters = MarketingAnalytics::filters($input);
        return $this->storage->measurementReport($filters);
    }

    public static function savedMeasurementReport(array $input = [], ?string $root = null): array
    {
        return (new MarketingStorage($root))->measurementReport(MarketingAnalytics::filters($input));
    }

    /** Bounded business projection plus explicit retention. Never performs mail or payments. */
    public function runAnalyticsMaintenance(Store $store, int $limit = 100, ?callable $authorize = null): array
    {
        if ($limit < 1 || $limit > 250) throw new InvalidArgumentException('Choose a maintenance batch of 1–250 records.');
        if ($authorize !== null) $authorize();
        $retention = $this->update(function (array &$data) use ($authorize): array {
            if ($authorize !== null) $authorize();
            $state = MarketingAnalytics::state($data); $attribution = MarketingAttribution::state($data); $now = time();
            $cutoff = $now - $state['retention_days'] * 86400;
            $before = count($data['events']);
            $data['events'] = array_values(array_filter($data['events'], static fn($e): bool => is_array($e)
                && is_string($e['created_at'] ?? null) && (strtotime($e['created_at']) ?: 0) >= $cutoff));
            $removed = $before - count($data['events']);
            foreach ($state['rollups'] as $key => $row) {
                $row = MarketingAnalytics::rollup($row);
                if (!hash_equals(MarketingAnalytics::rollupKey($row), (string) $key)) throw new RuntimeException('Saved measurement totals need review.');
                if ($row['date'] < gmdate('Y-m-d', $now - $state['rollup_days'] * 86400)) { unset($state['rollups'][$key]); $removed++; }
            }
            $this->expireAttributionVisitors($attribution, $now);
            foreach ($attribution['conversions'] as $key => $conversion) {
                if (!is_array($conversion) || !is_int($conversion['recorded_at'] ?? null)) throw new RuntimeException('Saved attribution needs review.');
                if ($conversion['recorded_at'] < $cutoff) { unset($attribution['conversions'][$key]); $removed++; }
            }
            $data[MarketingAnalytics::KEY] = $state; $data[MarketingAttribution::KEY] = $attribution;
            return ['removed' => $removed, 'cursor' => $state['reconcile_cursor']];
        });
        $cursor = $retention['cursor'] ?? ['kind' => 'member', 'after' => ''];
        if (!is_array($cursor) || !in_array($cursor['kind'] ?? null, ['member','order'], true) || !is_string($cursor['after'] ?? null)) throw new RuntimeException('The attribution reconciliation cursor needs review.');
        $batch = $store->marketingAttributionBatch($cursor['kind'], $cursor['after'], $limit);
        $projected = 0;
        foreach ($batch['ids'] as $id) {
            if ($authorize !== null) $authorize();
            if ($cursor['kind'] === 'member' ? $this->recordRegistrationAttribution($store, $id) : $this->recordOrderAttribution($store, $id)) $projected++;
        }
        $next = $batch['complete'] ? ['kind' => $cursor['kind'] === 'member' ? 'order' : 'member', 'after' => '']
            : ['kind' => $cursor['kind'], 'after' => $batch['last']];
        $this->update(function (array &$data) use ($cursor, $next, $authorize): void {
            if ($authorize !== null) $authorize();
            $state = MarketingAnalytics::state($data);
            if (($state['reconcile_cursor'] ?? ['kind' => 'member', 'after' => '']) === $cursor) {
                $state['reconcile_cursor'] = $next; $data[MarketingAnalytics::KEY] = $state;
            }
        });
        return ['examined' => count($batch['ids']), 'projected' => $projected, 'removed' => $retention['removed'], 'next' => $next];
    }
}

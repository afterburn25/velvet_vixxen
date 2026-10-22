<?php
declare(strict_types=1);

namespace Velvet\Core;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/MarketingAudience.php';
require_once __DIR__ . '/MarketingAttribution.php';
require_once dirname(__DIR__) . '/Support/public_routes.php';

/** Measurement payloads are an allowlist; business identities never enter reports. */
final class MarketingAnalytics
{
    public const KEY = 'analytics_v1';
    public const EVENTS = ['page_view', 'newsletter_signup', 'newsletter_confirmed', 'cta_click', 'campaign_view',
        'referral_visit', 'purchase_intent', 'campaign_click', 'registration', 'checkout', 'purchase'];
    public const MAX_EVENTS = 20000;
    public const MAX_ROLLUPS = 50000;

    public static function state(array $data): array
    {
        $state = $data[self::KEY] ?? ['version' => 1, 'retention_days' => 90, 'rollup_days' => 730, 'rollups' => [], 'reconcile_cursor' => null];
        if (!is_array($state) || ($state['version'] ?? null) !== 1
            || array_diff(array_keys($state), ['version','retention_days','rollup_days','rollups','reconcile_cursor']) !== []
            || !is_int($state['retention_days'] ?? null) || $state['retention_days'] < 7 || $state['retention_days'] > 365
            || !is_int($state['rollup_days'] ?? null) || $state['rollup_days'] < $state['retention_days'] || $state['rollup_days'] > 1095
            || !is_array($state['rollups'] ?? null) || count($state['rollups']) > self::MAX_ROLLUPS) {
            throw new RuntimeException('Saved measurement settings need review. Existing records were preserved.');
        }
        return $state;
    }

    public static function path(mixed $path, string $baseUrl = ''): ?string
    {
        if (!is_string($path) || strlen($path) > 4096 || $path === '' || $path[0] !== '/'
            || str_starts_with($path, '//') || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)) return null;
        $path = \vvx_public_request_path($path);
        $prefix = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
        if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) $path = substr($path, strlen($prefix)) ?: '/';
        if (strlen($path) > 500 || preg_match('#/(?:admin|account|messages|requests|communication|receipt|install|api|privacy|campaign/click|newsletter/(?:confirm|unsubscribe))(/|\\.|$)#i', $path)) return null;
        // An encoded secret path segment is not useful for aggregate reporting.
        if (preg_match('/%[0-9a-f]{2}/i', $path)) return null;
        if (in_array($path, ['/', '/index.php', '/public/home.php'], true)) return '/';
        $route = trim($path, '/');
        $implementation = \vvx_public_routes()[$route] ?? null;
        // Only named public content routes are retained. Arbitrary slugs, tokens,
        // email addresses or other caller-created path segments never become data.
        if (!is_string($implementation) || !preg_match('#\A(?:about|blog|booking|campaign|collections|links|membership|music|newsletter|photos|shop|videos)/#', $implementation)) return null;
        return str_ends_with($implementation, '/index.php') ? '/' . substr($implementation, 0, -9) : '/' . $implementation;
    }

    public static function configuration(array $data): array
    {
        $state = self::state($data);
        $config = ['enabled' => ($data['settings']['analytics_enabled'] ?? true) === true,
            'retention_days' => $state['retention_days'], 'rollup_days' => $state['rollup_days']];
        return $config + ['revision' => hash('sha256', json_encode($config, JSON_THROW_ON_ERROR))];
    }

    public static function event(mixed $event): array
    {
        $keys = ['measurement_version','id','name','path','actor','referral','campaign','currency','amount_minor','source_key','epoch','created_at'];
        if (!is_array($event) || ($event['measurement_version'] ?? null) !== 1 || array_diff(array_keys($event), $keys) !== []
            || array_diff($keys, array_keys($event)) !== [] || !is_string($event['id']) || !preg_match('/\A[a-f0-9]{32}\z/D', $event['id'])
            || !in_array($event['name'], self::EVENTS, true) || self::path($event['path']) !== $event['path']
            || !is_string($event['actor']) || !preg_match('/\A[a-f0-9]{64}\z/D', $event['actor'])
            || !is_int($event['epoch']) || $event['epoch'] < 1 || !is_string($event['created_at']) || gmdate('c', $event['epoch']) !== $event['created_at']
            || !is_int($event['amount_minor']) || $event['amount_minor'] < 0 || $event['amount_minor'] > 100000000000
            || ($event['source_key'] !== null && (!is_string($event['source_key']) || !preg_match('/\A[a-f0-9]{64}\z/D', $event['source_key'])))
            || ($event['currency'] !== null && (!is_string($event['currency']) || !preg_match('/\A[A-Z]{3}\z/D', $event['currency'])))) {
            throw new RuntimeException('A saved measurement needs review. It was not included in a report.');
        }
        if ($event['referral'] !== null && MarketingAttribution::code($event['referral']) !== $event['referral']) throw new RuntimeException('A saved measurement referral needs review.');
        if ($event['campaign'] !== null) MarketingAudience::id($event['campaign']);
        return $event;
    }

    public static function rollup(mixed $row): array
    {
        $keys = ['date','name','path','referral','campaign','currency','count','amount_minor'];
        if (!is_array($row) || array_diff(array_keys($row), $keys) !== [] || array_diff($keys, array_keys($row)) !== []
            || !is_string($row['date']) || self::date($row['date'])->format('Y-m-d') !== $row['date']
            || !in_array($row['name'], self::EVENTS, true) || self::path($row['path']) !== $row['path']
            || !is_int($row['count']) || $row['count'] < 0 || !is_int($row['amount_minor']) || $row['amount_minor'] < 0
            || ($row['currency'] !== null && (!is_string($row['currency']) || !preg_match('/\A[A-Z]{3}\z/D', $row['currency'])))) throw new RuntimeException('Saved measurement totals need review.');
        if ($row['referral'] !== null && MarketingAttribution::code($row['referral']) !== $row['referral']) throw new RuntimeException('Saved measurement totals need review.');
        if ($row['campaign'] !== null) MarketingAudience::id($row['campaign']);
        return $row;
    }

    public static function rollupKey(array $event): string
    {
        return hash('sha256', json_encode([$event['date'] ?? gmdate('Y-m-d', $event['epoch']), $event['name'], $event['path'],
            $event['referral'], $event['campaign'], $event['currency']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Reports use explicit UTC calendar days. End date includes the whole selected day. */
    public static function filters(array $input): array
    {
        if (array_diff(array_keys($input), ['start','end','campaign','referral','event','compare']) !== []) throw new InvalidArgumentException('Unsupported measurement filter.');
        $start = self::date($input['start'] ?? gmdate('Y-m-d', time() - 29 * 86400));
        $end = self::date($input['end'] ?? gmdate('Y-m-d'));
        $days = (int) $start->diff($end)->format('%r%a') + 1;
        if ($days < 1 || $days > 366) throw new InvalidArgumentException('Choose an ordered date range of at most 366 days.');
        $campaign = $input['campaign'] ?? ''; $referral = $input['referral'] ?? ''; $event = $input['event'] ?? '';
        if ($campaign !== '') MarketingAudience::id($campaign);
        if ($referral !== '') $referral = MarketingAttribution::code($referral);
        if (!is_string($event) || ($event !== '' && !in_array($event, self::EVENTS, true))) throw new InvalidArgumentException('Choose a supported measurement event.');
        $compare = $input['compare'] ?? false;
        if (!is_bool($compare)) throw new InvalidArgumentException('Choose whether to compare the previous period.');
        return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'from' => $start->getTimestamp(),
            'until' => $end->getTimestamp() + 86400, 'days' => $days, 'campaign' => $campaign, 'referral' => $referral, 'event' => $event, 'compare' => $compare];
    }

    public static function previous(array $filters): array
    {
        $seconds = $filters['days'] * 86400;
        return array_replace($filters, ['from' => $filters['from'] - $seconds, 'until' => $filters['from'],
            'start' => gmdate('Y-m-d', $filters['from'] - $seconds), 'end' => gmdate('Y-m-d', $filters['from'] - 86400), 'compare' => false]);
    }

    public static function matches(array $row, array $filters, bool $rollup = false): bool
    {
        $epoch = $rollup ? self::date($row['date'])->getTimestamp() : $row['epoch'];
        return $epoch >= $filters['from'] && $epoch < $filters['until']
            && ($filters['campaign'] === '' || $row['campaign'] === $filters['campaign'])
            && ($filters['referral'] === '' || $row['referral'] === $filters['referral'])
            && ($filters['event'] === '' || $row['name'] === $filters['event']);
    }

    public static function report(array $rows, array $events, array $filters, string $backend, int $retentionDays): array
    {
        $counts = []; $paths = []; $days = []; $currencies = []; $actors = []; $stages = [];
        foreach ($rows as $row) {
            $row = self::rollup($row);
            if (!self::matches($row, $filters, true)) continue;
            $counts[$row['name']] = ($counts[$row['name']] ?? 0) + $row['count'];
            $days[$row['date']] = ($days[$row['date']] ?? 0) + $row['count'];
            if ($row['name'] === 'page_view') $paths[$row['path']] = ($paths[$row['path']] ?? 0) + $row['count'];
            if ($row['name'] === 'purchase' && $row['currency'] !== null) $currencies[$row['currency']] = ($currencies[$row['currency']] ?? 0) + $row['amount_minor'];
        }
        foreach ($events as $event) {
            $event = self::event($event);
            if (!self::matches($event, $filters)) continue;
            $actors[$event['actor']] = true;
            if (in_array($event['name'], ['page_view','registration','checkout','purchase'], true)) {
                $prior = $stages[$event['actor']][$event['name']] ?? PHP_INT_MAX;
                $stages[$event['actor']][$event['name']] = min($prior, $event['epoch']);
            }
        }
        $funnel = ['page_view' => 0, 'registration' => 0, 'checkout' => 0, 'purchase' => 0];
        foreach ($stages as $times) {
            $previous = 0;
            foreach (array_keys($funnel) as $name) {
                if (!isset($times[$name]) || $times[$name] < $previous) break;
                $funnel[$name]++; $previous = $times[$name];
            }
        }
        ksort($counts); ksort($days); ksort($currencies); arsort($paths);
        return ['filters' => $filters, 'backend' => $backend, 'counts' => $counts, 'total' => array_sum($counts),
            'daily' => $days, 'top_paths' => array_slice($paths, 0, 25, true), 'gross_purchase_minor' => $currencies,
            'retained_unique_visitors' => count($actors), 'retained_funnel' => $funnel, 'raw_retention_days' => $retentionDays,
            'funnel_note' => 'Ordered public view → registration → checkout → purchase for the same consenting browser, within the selected retained raw period. Gross purchase values do not subtract refunds. Legacy unverified events are excluded.'];
    }

    public static function csv(array $report): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new RuntimeException('Could not prepare the measurement export.');
        $write = static function (array $row) use ($stream): void {
            $row = array_map(static function ($value): string {
                $text = (string) $value;
                return preg_match('/\A[\s]*[=+@\-]/u', $text) || preg_match('/[\x00-\x1f\x7f]/', $text) ? "'" . str_replace(["\r", "\n", "\t", "\0"], ' ', $text) : $text;
            }, $row);
            fputcsv($stream, $row, ',', '"', '');
        };
        $write(['section','label','count_or_minor_units','currency','start_utc','end_utc']);
        foreach ($report['counts'] as $name => $count) $write(['events',$name,$count,'',$report['filters']['start'],$report['filters']['end']]);
        foreach ($report['top_paths'] as $path => $count) $write(['public_path',$path,$count,'',$report['filters']['start'],$report['filters']['end']]);
        foreach ($report['gross_purchase_minor'] as $currency => $minor) $write(['gross_purchase','purchase',$minor,$currency,$report['filters']['start'],$report['filters']['end']]);
        foreach ($report['retained_funnel'] as $stage => $count) $write(['retained_ordered_funnel',$stage,$count,'',$report['filters']['start'],$report['filters']['end']]);
        rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
        return $csv === false ? '' : $csv;
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value)) throw new InvalidArgumentException('Use an exact calendar date.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value || (int) $date->format('Y') < 2000) throw new InvalidArgumentException('Choose a real date from 2000 onwards.');
        return $date;
    }
}

<?php
declare(strict_types=1);

namespace Velvet\Core;

use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/MarketingPublication.php';

/** Bounded private prior versions, stored atomically with their publication. */
final class MarketingHistory
{
    public const KEY = 'editorial_history';
    public const LIMIT = 20;
    // Preserve every existing field limit even when JSON doubles quotes, slashes
    // and line breaks in the 100,000-byte body and 8,000-byte excerpt.
    public const MAX_SNAPSHOT_BYTES = 262144;
    private const MAX_ENVELOPE_BYTES = 5264384;

    public static function id(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{32}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Choose a valid saved version.');
        }
        return $value;
    }

    /** Fixed identity, bookkeeping, unknown fields and nested history never enter a snapshot. */
    public static function keys(string $collection): array
    {
        MarketingPublication::collection($collection);
        $keys = match ($collection) {
            'posts' => ['title', 'category', 'excerpt', 'body', 'status', 'publish_at', 'access'],
            'banners' => ['text', 'url', 'enabled', 'starts_at', 'ends_at', 'access'],
            'landing_pages' => ['title', 'eyebrow', 'excerpt', 'body', 'cta_label', 'cta_url', 'enabled', 'starts_at', 'ends_at', 'access'],
        };
        return array_merge($keys, array_keys(MarketingMetadata::defaults($collection)));
    }

    public static function withoutHistory(array $record): array
    {
        unset($record[self::KEY]);
        return $record;
    }

    /** Revision still covers the complete saved record, including its private history. */
    public static function describe(string $collection, array $record): array
    {
        return self::withoutHistory(MarketingPublication::describe($collection, $record));
    }

    public static function snapshot(string $collection, array $record): array
    {
        $snapshot = array_intersect_key($record, array_flip(self::keys($collection)));
        self::validateSnapshot($collection, $snapshot);
        return $snapshot;
    }

    private static function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR, 16);
        } catch (Throwable $error) {
            throw new RuntimeException('Publication history needs review. Existing versions were preserved.', 0, $error);
        }
    }

    private static function validateSnapshot(string $collection, mixed $snapshot): void
    {
        if (!is_array($snapshot) || array_diff(array_keys($snapshot), self::keys($collection)) !== []
            || strlen(self::encode($snapshot)) > self::MAX_SNAPSHOT_BYTES) {
            throw new RuntimeException('Publication history needs review. Existing versions were preserved.');
        }
    }

    /** Malformed history is never silently reset, truncated or echoed to public readers. */
    public static function entries(string $collection, array $record): array
    {
        MarketingPublication::collection($collection);
        if (!array_key_exists(self::KEY, $record)) return [];
        $envelope = $record[self::KEY];
        if (!is_array($envelope) || count($envelope) !== 2 || ($envelope['schema'] ?? null) !== 1
            || !is_array($envelope['entries'] ?? null) || !array_is_list($envelope['entries'])
            || count($envelope['entries']) > self::LIMIT || strlen(self::encode($envelope)) > self::MAX_ENVELOPE_BYTES) {
            throw new RuntimeException('Publication history needs review. Existing versions were preserved.');
        }
        $seen = [];
        foreach ($envelope['entries'] as $entry) {
            if (!is_array($entry) || count($entry) !== 6
                || array_diff(array_keys($entry), ['id', 'captured_at', 'timezone', 'action', 'source_edit_version', 'snapshot']) !== []) {
                throw new RuntimeException('Publication history needs review. Existing versions were preserved.');
            }
            try {
                $id = self::id($entry['id'] ?? null);
                if (isset($seen[$id]) || !is_string($entry['captured_at'] ?? null)
                    || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/D', $entry['captured_at']) !== 1
                    || MarketingPublication::storedDate($entry['captured_at']) === null
                    || !is_string($entry['timezone'] ?? null) || strlen($entry['timezone']) > 100
                    || !in_array($entry['action'] ?? null, ['save', 'visibility', 'restore'], true)
                    || !is_int($entry['source_edit_version'] ?? null) || $entry['source_edit_version'] < 0) {
                    throw new InvalidArgumentException('Invalid saved version.');
                }
                new DateTimeZone($entry['timezone']);
                self::validateSnapshot($collection, $entry['snapshot'] ?? null);
            } catch (Throwable $error) {
                throw new RuntimeException('Publication history needs review. Existing versions were preserved.', 0, $error);
            }
            $seen[$id] = true;
        }
        return $envelope['entries'];
    }

    /** Archive the state before a genuine editorial change; newest entries are first. */
    public static function capture(string $collection, array $previous, array $next, string $action, bool $safeWithdrawal = false): array
    {
        if ($previous === []) return $next;
        if (!in_array($action, ['save', 'visibility', 'restore'], true)) throw new InvalidArgumentException('Choose a valid history action.');
        try {
            $entries = self::entries($collection, $previous);
            $snapshot = self::snapshot($collection, $previous);
            if ($snapshot === self::snapshot($collection, $next)) return $next;
            $entry = ['id' => bin2hex(random_bytes(16)), 'captured_at' => gmdate('c'),
                'timezone' => date_default_timezone_get(), 'action' => $action,
                'source_edit_version' => is_int($previous['edit_version'] ?? null) && $previous['edit_version'] >= 0 ? $previous['edit_version'] : 0,
                'snapshot' => $snapshot];
            array_unshift($entries, $entry);
            $candidate = array_replace($next, [self::KEY => ['schema' => 1, 'entries' => array_slice($entries, 0, self::LIMIT)]]);
            self::entries($collection, $candidate);
            $next = $candidate;
        } catch (RuntimeException $error) {
            if (!$safeWithdrawal) throw $error;
            // History damage must never prevent taking a publication offline.
            // The existing envelope stays untouched; no new version is claimed.
        }
        return $next;
    }

    public static function summary(array $entry): array
    {
        $title = $entry['snapshot']['title'] ?? $entry['snapshot']['text'] ?? '';
        try { $title = MarketingAudience::text($title, 1000, 'Saved title'); }
        catch (InvalidArgumentException) { $title = ''; }
        return array_intersect_key($entry, array_flip(['id', 'captured_at', 'timezone', 'action', 'source_edit_version']))
            + ['title' => $title !== '' ? $title : 'Saved publication'];
    }

    /** Private review shape. Callers authorize the active owner after all dependent reads. */
    public static function review(string $collection, array $record, ?string $entryId = null): ?array
    {
        if ($entryId !== null) self::id($entryId);
        $entries = self::entries($collection, $record);
        $selected = null;
        foreach ($entries as $entry) if ($entry['id'] === $entryId) $selected = $entry;
        if ($entryId !== null && $selected === null) return null;
        return ['current' => self::describe($collection, $record),
            'entries' => array_map(self::summary(...), $entries), 'selected' => $selected, 'limit' => self::LIMIT];
    }

    /**
     * Validate historical editorial fields for display/restore without trusting arbitrary
     * stored keys. Legacy local dates use the timezone captured with that prior version.
     */
    public static function project(string $collection, array $current, array $entry): array
    {
        self::validateSnapshot($collection, $entry['snapshot'] ?? null);
        if (!is_string($entry['timezone'] ?? null)) throw new InvalidArgumentException('This saved version needs review.');
        try { new DateTimeZone($entry['timezone']); }
        catch (Throwable $error) { throw new InvalidArgumentException('This saved version needs review.', 0, $error); }
        $snapshot = $entry['snapshot'];
        $defaults = match ($collection) {
            'posts' => ['title' => '', 'category' => '', 'excerpt' => '', 'body' => '', 'status' => 'draft', 'publish_at' => ''],
            'banners' => ['text' => '', 'url' => '', 'enabled' => false, 'starts_at' => '', 'ends_at' => ''],
            'landing_pages' => ['title' => '', 'eyebrow' => '', 'excerpt' => '', 'body' => '', 'cta_label' => '', 'cta_url' => '', 'enabled' => false, 'starts_at' => '', 'ends_at' => ''],
        };
        $fields = array_replace($defaults, MarketingMetadata::defaults($collection), $snapshot);
        $fields['access'] = MarketingAccess::policy($snapshot);
        $dates = $collection === 'posts' ? ['publish_at'] : ['starts_at', 'ends_at'];
        foreach ($dates as $key) {
            $value = $fields[$key];
            $date = MarketingPublication::storedDate($value, $entry['timezone']);
            // Explicit offsets retain their original exact representation and instant.
            $fields[$key] = $date === null ? '' : (is_string($value) && preg_match('/(?:Z|[+-]\d{2}:\d{2})\z/D', $value)
                ? $value : $date->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM));
        }
        $visibility = MarketingPublication::visibility($collection, $fields);
        if ($visibility['problem'] !== null) throw new InvalidArgumentException($visibility['problem']);
        if ($collection !== 'posts') $fields['enabled'] = MarketingPublication::enabled($collection, $fields);
        $context = array_replace($fields, ['slug' => $current['slug'] ?? '']);
        $input = array_diff_key($fields, array_flip($dates));
        if ($collection !== 'banners') $input['slug'] = $context['slug'];
        $validated = MarketingPublication::fields($collection, $input, $context);
        // Unknown current data remains authoritative but is never copied into history.
        $projected = array_replace(self::withoutHistory($current), $validated);
        unset($projected['revision'], $projected['visibility']);
        return $projected;
    }
}

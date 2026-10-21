<?php
declare(strict_types=1);

namespace Velvet\Core;

use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/MarketingHistory.php';

/** Immutable deleted publications, committed with the active editorial rows. */
final class MarketingRecovery
{
    public const KEY = 'editorial_recovery';
    public const LIMIT = 50;
    public const MAX_BYTES = 33554432;
    public const MAX_RECORD_BYTES = 8388608;

    public static function id(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{32}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Choose a valid deleted item.');
        }
        return $value;
    }

    private static function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR, 64);
        } catch (Throwable $error) {
            throw new RuntimeException('Deleted content needs a storage review. Existing content was preserved.', 0, $error);
        }
    }

    /** A missing envelope is a legacy empty archive; malformed state is never reset. */
    public static function entries(array $data): array
    {
        if (!array_key_exists(self::KEY, $data)) return [];
        $envelope = $data[self::KEY];
        if (!is_array($envelope) || count($envelope) !== 2 || ($envelope['schema'] ?? null) !== 1
            || !is_array($envelope['entries'] ?? null) || !array_is_list($envelope['entries'])
            || count($envelope['entries']) > self::LIMIT || strlen(self::encode($envelope)) > self::MAX_BYTES) {
            throw new RuntimeException('Deleted content needs a storage review. Existing content was preserved.');
        }
        $seen = []; $identities = [];
        foreach ($envelope['entries'] as $entry) {
            try {
                if (!is_array($entry) || count($entry) !== 7 || array_diff(array_keys($entry),
                    ['id', 'collection', 'deleted_at', 'timezone', 'position', 'record', 'record_hash']) !== []) {
                    throw new InvalidArgumentException('Invalid deleted item.');
                }
                $id = self::id($entry['id'] ?? null);
                if (!is_string($entry['collection'] ?? null)) throw new InvalidArgumentException('Invalid content type.');
                $collection = MarketingPublication::collection($entry['collection']);
                if (isset($seen[$id]) || !is_string($entry['deleted_at'] ?? null)
                    || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/D', $entry['deleted_at']) !== 1
                    || MarketingPublication::storedDate($entry['deleted_at']) === null
                    || !is_string($entry['timezone'] ?? null) || strlen($entry['timezone']) > 100
                    || !is_int($entry['position'] ?? null) || $entry['position'] < 0
                    || !is_array($entry['record'] ?? null) || !is_string($entry['record_hash'] ?? null)
                    || preg_match('/\A[a-f0-9]{64}\z/D', $entry['record_hash']) !== 1) {
                    throw new InvalidArgumentException('Invalid deleted item.');
                }
                new DateTimeZone($entry['timezone']);
                $publicationId = MarketingPublication::id($entry['record']['id'] ?? null);
                $identity = $collection . ':' . $publicationId;
                $encoded = self::encode($entry['record']);
                if (isset($identities[$identity]) || strlen($encoded) > self::MAX_RECORD_BYTES
                    || !hash_equals($entry['record_hash'], hash('sha256', $encoded))) {
                    throw new InvalidArgumentException('Invalid deleted item.');
                }
                $seen[$id] = true; $identities[$identity] = true;
            } catch (Throwable $error) {
                throw new RuntimeException('Deleted content needs a storage review. Existing content was preserved.', 0, $error);
            }
        }
        return $envelope['entries'];
    }

    public static function envelope(array $entries): array
    {
        $envelope = ['schema' => 1, 'entries' => array_values($entries)];
        self::entries([self::KEY => $envelope]);
        return $envelope;
    }

    /** Full private record capture preserves identity, prior history and unknown legacy fields. */
    public static function capture(string $collection, array $record, int $position, array $entries): array
    {
        MarketingPublication::collection($collection);
        MarketingPublication::id($record['id'] ?? null);
        if (count($entries) >= self::LIMIT) {
            throw new RuntimeException('Deleted content is full. Restore or permanently remove an item there before deleting another. You can still move this item to draft or disable it.');
        }
        $encoded = self::encode($record);
        if (strlen($encoded) > self::MAX_RECORD_BYTES) {
            throw new RuntimeException('This item is too large for Deleted content. It was preserved. Move it to draft or disable it while its saved data is reviewed.');
        }
        $entry = ['id' => bin2hex(random_bytes(16)), 'collection' => $collection,
            'deleted_at' => gmdate('c'), 'timezone' => date_default_timezone_get(),
            'position' => $position, 'record' => $record, 'record_hash' => hash('sha256', $encoded)];
        array_unshift($entries, $entry);
        if (strlen(self::encode(['schema' => 1, 'entries' => $entries])) > self::MAX_BYTES) {
            throw new RuntimeException('Deleted content has reached its storage limit. Restore or permanently remove an item there before deleting another. This item was preserved.');
        }
        self::envelope($entries);
        return $entry;
    }

    /** A new deletion has a new identity, so an older form cannot restore a later deletion. */
    public static function revision(array $entry): string
    {
        return hash('sha256', self::encode(['timezone' => date_default_timezone_get(), 'entry' => $entry]));
    }

    public static function requireRevision(array $entry, mixed $revision): void
    {
        if (!MarketingAudience::validRevision($revision) || !hash_equals(self::revision($entry), $revision)) {
            throw new RuntimeException('This deleted item changed. Reload its saved review before restoring or permanently removing it.');
        }
    }

    public static function summary(array $entry): array
    {
        $record = $entry['record'];
        try { $title = MarketingAudience::text($record[$entry['collection'] === 'banners' ? 'text' : 'title'] ?? '', 1000, 'Title'); }
        catch (InvalidArgumentException) { $title = ''; }
        return ['id' => $entry['id'], 'collection' => $entry['collection'], 'publication_id' => $record['id'],
            'title' => $title !== '' ? $title : 'Saved publication', 'deleted_at' => $entry['deleted_at'],
            'timezone' => $entry['timezone'], 'revision' => self::revision($entry)];
    }

    /** Validate saved fields without broadening access or silently replacing references. */
    public static function project(array $entry): array
    {
        $collection = $entry['collection']; $record = $entry['record'];
        MarketingHistory::entries($collection, $record);
        if ($collection !== 'banners' && (!is_string($record['slug'] ?? null)
            || strlen($record['slug']) > 160 || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $record['slug']) !== 1)) {
            throw new InvalidArgumentException('The saved permalink needs repair before this item can be restored.');
        }
        $projected = MarketingHistory::project($collection, $record,
            ['snapshot' => MarketingHistory::snapshot($collection, $record), 'timezone' => $entry['timezone']]);
        return array_replace($record, $projected);
    }

    /** Saved-state-only private review. The controller authorizes after its dependent reads. */
    public static function review(array $data, ?string $collection = null, ?string $id = null): array
    {
        if ($collection !== null) MarketingPublication::collection($collection);
        if ($id !== null) self::id($id);
        if ($id !== null && $collection === null) throw new InvalidArgumentException('Choose a content type to review a deleted item.');
        $entries = self::entries($data); $selected = null; $summaries = [];
        foreach ($entries as $entry) {
            if ($collection !== null && $entry['collection'] !== $collection) continue;
            $summaries[] = self::summary($entry);
            if ($entry['id'] === $id) $selected = $entry;
        }
        $titles = []; $seen = [];
        foreach ($data['posts'] ?? [] as $post) {
            if (!is_array($post)) continue;
            try {
                $postId = MarketingPublication::id($post['id'] ?? null);
                if (isset($seen[$postId])) { unset($titles[$postId]); continue; }
                $seen[$postId] = true;
                $titles[$postId] = MarketingAudience::text($post['title'] ?? '', 255, 'Related post title');
            } catch (InvalidArgumentException) { /* Only valid saved labels are rendered. */ }
        }
        return ['entries' => $summaries, 'selected' => $selected, 'total' => count($entries), 'limit' => self::LIMIT,
            'bytes' => array_key_exists(self::KEY, $data) ? strlen(self::encode($data[self::KEY])) : 0,
            'max_bytes' => self::MAX_BYTES, 'related_titles' => $titles];
    }
}

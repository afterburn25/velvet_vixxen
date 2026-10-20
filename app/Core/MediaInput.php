<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Bounded owner input and canonical revision data for the Media Center. */
final class MediaInput
{
    public const MAX_GALLERY_ITEMS = 200;
    public const MAX_PLAYLIST_TRACKS = 500;
    public const MAX_CAPTION = 2000;
    public const MAX_TAGS = 30;
    public const MAX_TAG_LENGTH = 64;

    public static function id(mixed $value, bool $allowBlank = true): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new RuntimeException('A media identifier is invalid. Reload the editor.');
        }
        $id = (string) $value;
        if ($id === '' && $allowBlank) {
            return '';
        }
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,99}\z/D', $id)) {
            throw new RuntimeException('A media identifier is invalid. Reload the editor.');
        }
        return $id;
    }

    /** @return list<string> */
    public static function ids(mixed $value, int $maximum, string $label): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new RuntimeException($label . ' must be an ordered list with at most ' . $maximum . ' items.');
        }
        $result = [];
        foreach ($value as $id) {
            $id = self::id($id, false);
            if (in_array($id, $result, true)) {
                throw new RuntimeException($label . ' contains a repeated item.');
            }
            $result[] = $id;
        }
        return $result;
    }

    public static function text(mixed $value, int $maximum, string $label, bool $multiline = false): string
    {
        if (!is_string($value) || strlen($value) > $maximum * 4 || preg_match('//u', $value) !== 1) {
            throw new RuntimeException($label . ' must be valid text of at most ' . $maximum . ' characters.');
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $controls = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u' : '/[\p{Cc}\p{Zl}\p{Zp}]/u';
        if (preg_match($controls, $value) || mb_strlen($value, 'UTF-8') > $maximum) {
            throw new RuntimeException($label . ' contains invalid text or exceeds ' . $maximum . ' characters.');
        }
        return trim($value);
    }

    public static function caption(mixed $value): string
    {
        return self::text($value, self::MAX_CAPTION, 'Photo caption', true);
    }

    /** @return list<string> */
    public static function tags(mixed $value): array
    {
        if (is_string($value)) {
            if (strlen($value) > self::MAX_TAGS * (self::MAX_TAG_LENGTH * 4 + 1)) {
                throw new RuntimeException('Photo tags are too long.');
            }
            $value = explode(',', $value);
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_TAGS) {
            throw new RuntimeException('Use at most ' . self::MAX_TAGS . ' comma-separated photo tags.');
        }
        $tags = [];
        $seen = [];
        foreach ($value as $tag) {
            $tag = self::text($tag, self::MAX_TAG_LENGTH, 'Photo tag');
            if ($tag === '') {
                continue;
            }
            if (str_contains($tag, ',')) {
                throw new RuntimeException('Separate photo tags with commas.');
            }
            $key = mb_strtolower($tag, 'UTF-8');
            if (!isset($seen[$key])) {
                $tags[] = $tag;
                $seen[$key] = true;
            }
        }
        return $tags;
    }

    /** @return array<string|int,mixed> */
    public static function itemFields(mixed $value, string $label): array
    {
        if (!is_array($value) || count($value) > self::MAX_GALLERY_ITEMS) {
            throw new RuntimeException($label . ' must contain fields for the displayed gallery photos.');
        }
        foreach ($value as $id => $_) {
            self::id($id, false);
        }
        return $value;
    }

    public static function count(mixed $value, int $maximum, string $label): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/\A\d{1,6}\z/D', (string) $value)) {
            throw new RuntimeException($label . ' must be a whole number between 0 and ' . $maximum . '.');
        }
        $number = (int) $value;
        if ($number > $maximum) {
            throw new RuntimeException($label . ' must be a whole number between 0 and ' . $maximum . '.');
        }
        return $number;
    }

    public static function status(mixed $value): string
    {
        $status = self::text($value, 20, 'Publication status');
        if (!in_array($status, ['draft', 'scheduled', 'published', 'archived', 'unlisted'], true)) {
            throw new RuntimeException('Choose a valid publication status.');
        }
        return $status;
    }

    public static function access(mixed $value): string
    {
        $access = self::text($value, 40, 'Media access');
        if (!in_array($access, MediaAccess::MODES, true)) {
            throw new RuntimeException('Choose a valid media access setting.');
        }
        return $access;
    }

    public static function price(mixed $value): ?float
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !preg_match('/\A\d{1,10}(?:\.\d{1,2})?\z/D', (string) $value)) {
            throw new RuntimeException('Prices must be positive amounts with at most two decimal places.');
        }
        return round((float) $value, 2);
    }

    public static function currency(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Za-z]{3}\z/D', $value)) {
            throw new RuntimeException('Use a three-letter currency code.');
        }
        return strtoupper($value);
    }

    /** A submitted datetime-local is interpreted in the site's configured timezone. */
    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/\A(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?\z/D', $value, $parts)) {
            throw new RuntimeException('Use a valid date and time in the site timezone.');
        }
        $normalized = $parts[1] . ' ' . $parts[2] . ($parts[3] ?? ':00');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $normalized, new DateTimeZone(date_default_timezone_get()));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d H:i:s') !== $normalized || (int) $date->format('Y') < 1000) {
            throw new RuntimeException('Use a valid date and time in the site timezone.');
        }
        return $normalized;
    }

    /** Existing file records can contain strict ISO timestamps from now_iso(). */
    public static function storedDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new RuntimeException('A saved media date is invalid. Edit its schedule before publishing.');
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?\z/D', $value)) {
            return new DateTimeImmutable((string) self::date($value), new DateTimeZone(date_default_timezone_get()));
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $value)) {
            $canonical = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $canonical);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format('Y-m-d\TH:i:sP') === $canonical && (int) $date->format('Y') >= 1000) {
                return $date;
            }
        }
        throw new RuntimeException('A saved media date is invalid. Edit its schedule before publishing.');
    }

    public static function revision(array $record, string $type): string
    {
        $keys = ['id', 'slug', 'title', 'description', 'coverAssetId', 'status', 'accessMode', 'publishedAt', 'scheduledAt'];
        if ($type === 'gallery') {
            $keys = [...$keys, 'standardPrice', 'subscriberPrice', 'rentalPrice', 'rentalHours', 'currency', 'previewCount'];
        }
        $data = ['type' => $type];
        foreach ($keys as $key) {
            $data[$key] = $record[$key] ?? null;
        }
        if ($type === 'gallery') {
            $data['items'] = array_map(static fn(array $item): array => [
                'assetId' => (string) ($item['assetId'] ?? ''),
                'caption' => (string) ($item['caption'] ?? ''),
                'sortOrder' => (int) ($item['sortOrder'] ?? 0),
                'isPreview' => !empty($item['isPreview']),
                'tags' => array_values((array) ($item['asset']['tags'] ?? [])),
            ], (array) ($record['items'] ?? []));
        } else {
            $data['trackIds'] = array_map('strval', (array) ($record['trackIds'] ?? []));
        }
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function assertRevision(mixed $expected, array $current, string $type): void
    {
        if (!is_string($expected) || !preg_match('/\A[a-f0-9]{64}\z/D', $expected) || !hash_equals(self::revision($current, $type), $expected)) {
            throw new RuntimeException('This media item changed or the edit form expired. Reload the editor and try again.');
        }
    }
}

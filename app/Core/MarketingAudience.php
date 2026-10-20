<?php
declare(strict_types=1);

namespace Velvet\Core;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Newsletter selection criteria only; consent and suppression remain separate. */
final class MarketingAudience
{
    public const MAX_SEGMENTS = 100;
    public const MAX_SOURCES = 20;

    public static function text(mixed $value, int $limit, string $label): string
    {
        if (!is_string($value) || strlen($value) > $limit * 4 || preg_match('//u', $value) !== 1
            || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value)) {
            throw new InvalidArgumentException($label . ' must be plain text of at most ' . $limit . ' characters.');
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : preg_match_all('/./us', $value);
        if ($length > $limit) throw new InvalidArgumentException($label . ' is too long.');
        return trim($value);
    }

    public static function id(mixed $value, bool $blank = false): string
    {
        if ($blank && $value === '') return '';
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $value)) {
            throw new InvalidArgumentException('Choose a valid saved audience.');
        }
        return $value;
    }

    public static function validRevision(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    /** @return array{version:int,sources:list<string>,confirmed_after:?string,confirmed_before:?string} */
    public static function rules(mixed $value): array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['version', 'sources', 'confirmed_after', 'confirmed_before']) !== []
            || (array_key_exists('version', $value) && $value['version'] !== 1)) {
            throw new InvalidArgumentException('The audience contains unsupported criteria. Edit it before queueing.');
        }
        $sources = array_key_exists('sources', $value) ? $value['sources'] : [];
        if (!is_array($sources) || !array_is_list($sources) || count($sources) > self::MAX_SOURCES) {
            throw new InvalidArgumentException('Choose at most ' . self::MAX_SOURCES . ' exact signup sources.');
        }
        $normalized = [];
        foreach ($sources as $source) {
            $source = self::text($source, 120, 'Signup source');
            if ($source === '') throw new InvalidArgumentException('A signup source cannot be blank.');
            if (!in_array($source, $normalized, true)) $normalized[] = $source;
        }
        sort($normalized, SORT_STRING);
        $after = self::timestamp($value['confirmed_after'] ?? null);
        $before = self::timestamp($value['confirmed_before'] ?? null);
        if ($after !== null && $before !== null && $after >= $before) {
            throw new InvalidArgumentException('The confirmation start must be before the end.');
        }
        return ['version' => 1, 'sources' => $normalized,
            'confirmed_after' => $after?->format('Y-m-d\TH:i:s\Z'),
            'confirmed_before' => $before?->format('Y-m-d\TH:i:s\Z')];
    }

    public static function restricted(array $rules): bool
    {
        return $rules['sources'] !== [] || $rules['confirmed_after'] !== null || $rules['confirmed_before'] !== null;
    }

    public static function sourcesFromText(mixed $value): array
    {
        if (!is_string($value) || strlen($value) > 10000) throw new InvalidArgumentException('Enter one signup source per line.');
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $value));
        $lines = array_map(static fn(string $line): string => self::text($line, 120, 'Signup source'), $lines);
        return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
    }

    /** Strict datetime-local input in the configured site timezone, saved in UTC. */
    public static function formDate(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?\z/D', $value)) {
            throw new InvalidArgumentException('Enter a valid confirmation date and time in the site timezone.');
        }
        $canonical = strlen($value) === 16 ? $value . ':00' : $value;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $canonical, new DateTimeZone(date_default_timezone_get()));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $date->format('Y-m-d\TH:i:s') !== $canonical || (int) $date->format('Y') < 1000) {
            throw new InvalidArgumentException('Enter a valid confirmation date and time in the site timezone.');
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function dateForForm(mixed $value): string
    {
        return self::timestamp($value)?->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i:s') ?? '';
    }

    /** Only absolute ISO timestamps are accepted; relative or impossible dates fail. */
    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-](\d{2}):(\d{2}))\z/D', $value, $parts)
            || (isset($parts[2]) && ((int) $parts[2] > 14 || (int) $parts[3] > 59 || ((int) $parts[2] === 14 && (int) $parts[3] !== 0)))) {
            throw new InvalidArgumentException('Audience dates must be valid absolute timestamps.');
        }
        $canonical = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $canonical);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $date->format('Y-m-d\TH:i:sP') !== $canonical || (int) $date->format('Y') < 1000) {
            throw new InvalidArgumentException('Audience dates must be valid absolute timestamps.');
        }
        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    /** $rules must already have passed rules(). */
    public static function matches(array $contact, array $rules): bool
    {
        if ($rules['sources'] !== [] && !in_array($contact['source'] ?? null, $rules['sources'], true)) return false;
        if ($rules['confirmed_after'] === null && $rules['confirmed_before'] === null) return true;
        try { $confirmed = self::timestamp($contact['confirmed_at'] ?? null); }
        catch (InvalidArgumentException) { return false; }
        if ($confirmed === null) return false;
        return ($rules['confirmed_after'] === null || $confirmed >= self::timestamp($rules['confirmed_after']))
            && ($rules['confirmed_before'] === null || $confirmed < self::timestamp($rules['confirmed_before']));
    }

    public static function selection(mixed $value): array
    {
        if (!is_array($value) || !is_string($value['mode'] ?? null)) throw new InvalidArgumentException('Choose a campaign audience.');
        if ($value['mode'] === 'all' && array_diff(array_keys($value), ['mode']) === []) return ['mode' => 'all'];
        if ($value['mode'] !== 'segment' || array_diff(array_keys($value), ['mode', 'segment_id', 'segment_revision']) !== []
            || !self::validRevision($value['segment_revision'] ?? null)) throw new InvalidArgumentException('Choose a valid saved audience.');
        return ['mode' => 'segment', 'segment_id' => self::id($value['segment_id'] ?? null), 'segment_revision' => $value['segment_revision']];
    }

    /** A single select option carries its displayed audience's edit revision. */
    public static function selectionFromForm(mixed $value): array
    {
        if ($value === 'all') return ['mode' => 'all'];
        if (!is_string($value) || strlen($value) > 145 || substr_count($value, ':') !== 1) throw new InvalidArgumentException('Choose a campaign audience.');
        [$id, $revision] = explode(':', $value, 2);
        return self::selection(['mode' => 'segment', 'segment_id' => $id, 'segment_revision' => $revision]);
    }

    public static function segmentRevision(array $record): string
    {
        $content = [];
        foreach (['id', 'name', 'enabled', 'rules', 'edit_version'] as $key) $content[$key] = $record[$key] ?? null;
        return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function snapshot(mixed $value): array
    {
        if (!is_array($value) || ($value['version'] ?? null) !== 1
            || array_diff(array_keys($value), ['version', 'mode', 'label', 'segment_id', 'segment_revision', 'rules', 'queued_at']) !== []) {
            throw new InvalidArgumentException('The saved campaign audience is invalid. Create a new campaign.');
        }
        $label = self::text($value['label'] ?? null, 120, 'Audience name');
        $rules = self::rules($value['rules'] ?? null);
        if ($label === '' || !in_array($value['mode'] ?? null, ['all', 'segment'], true)) throw new InvalidArgumentException('The saved campaign audience is invalid.');
        if ($value['mode'] === 'all') {
            if (self::restricted($rules) || ($value['segment_id'] ?? null) !== null || ($value['segment_revision'] ?? null) !== null) {
                throw new InvalidArgumentException('The saved all-contact audience is invalid.');
            }
            return ['version' => 1, 'mode' => 'all', 'label' => $label, 'segment_id' => null, 'segment_revision' => null, 'rules' => $rules];
        }
        if (!self::restricted($rules) || !self::validRevision($value['segment_revision'] ?? null)) throw new InvalidArgumentException('The saved segment audience is invalid.');
        return ['version' => 1, 'mode' => 'segment', 'label' => $label, 'segment_id' => self::id($value['segment_id'] ?? null),
            'segment_revision' => $value['segment_revision'], 'rules' => $rules];
    }
}

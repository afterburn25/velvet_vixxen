<?php
declare(strict_types=1);

namespace Velvet\Core;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

require_once __DIR__ . '/SceneButton.php';
require_once __DIR__ . '/MarketingAudience.php';

/** Publication windows are evaluated on read; they never run email or media jobs. */
final class MarketingPublication
{
    public static function collection(string $value): string
    {
        if (!in_array($value, ['posts', 'banners', 'landing_pages'], true)) {
            throw new InvalidArgumentException('Choose a post, banner or landing page.');
        }
        return $value;
    }

    public static function id(mixed $value, bool $blank = false): string
    {
        if ($blank && $value === '') return '';
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $value)) {
            throw new InvalidArgumentException('Choose a valid saved publication.');
        }
        return $value;
    }

    public static function revision(array $record): string
    {
        // Local date inputs must be reloaded if the site's timezone changes.
        return hash('sha256', json_encode(['timezone' => date_default_timezone_get(), 'record' => $record],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Strict datetime-local input. Impossible dates and DST gaps are rejected. */
    public static function formDate(mixed $value): ?string
    {
        if ($value === '' || $value === null) return null;
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?\z/D', $value)) {
            throw new InvalidArgumentException('Enter a valid publication date and time in the site timezone.');
        }
        return self::storedDate($value)?->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }

    /**
     * Preserve the stable local/absolute formats written by older versions.
     * Nonempty malformed values must never turn into an unrestricted window.
     */
    public static function storedDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === '' || $value === null) return null;
        if (!is_string($value) || strlen($value) > 35) throw new InvalidArgumentException('A saved publication date needs review.');
        $zone = new DateTimeZone(date_default_timezone_get());
        $canonical = $value;
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(Z|[+-](\d{2}):(\d{2}))\z/D', $value, $parts)) {
            if (isset($parts[2]) && ((int) $parts[2] > 14 || (int) $parts[3] > 59 || ((int) $parts[2] === 14 && (int) $parts[3] !== 0))) {
                throw new InvalidArgumentException('A saved publication date needs review.');
            }
            $local = substr($value, 0, -strlen($parts[1]));
            $canonical = $local . (strlen($local) === 16 ? ':00' : '') . ($parts[1] === 'Z' ? '+00:00' : $parts[1]);
            $format = 'Y-m-d\TH:i:sP';
        } elseif (preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?\z/D', $value)) {
            $canonical = str_replace(' ', 'T', $value) . (strlen($value) === 16 ? ':00' : '');
            $format = 'Y-m-d\TH:i:s';
        } elseif (preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) {
            $format = 'Y-m-d';
        } else {
            throw new InvalidArgumentException('A saved publication date needs review.');
        }
        $date = DateTimeImmutable::createFromFormat('!' . $format, $canonical, $zone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $date->format($format) !== $canonical || (int) $date->format('Y') < 1000) {
            throw new InvalidArgumentException('Enter a real publication date and time in the site timezone.');
        }
        return $date;
    }

    public static function dateForForm(mixed $value): string
    {
        return self::storedDate($value)?->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i:s') ?? '';
    }

    public static function enabled(string $collection, array $record): bool
    {
        return $collection === 'posts' ? ($record['status'] ?? 'draft') === 'published'
            : in_array($record['enabled'] ?? false, [true, 1, '1'], true);
    }

    /** @return array{state:string,visible:bool,problem:?string,start:?int,end:?int} */
    public static function visibility(string $collection, array $record, ?int $now = null): array
    {
        self::collection($collection);
        $now ??= time();
        $result = ['state' => 'Needs review', 'visible' => false, 'problem' => null, 'start' => null, 'end' => null];
        try {
            $start = self::storedDate($record[$collection === 'posts' ? 'publish_at' : 'starts_at'] ?? null)?->getTimestamp();
            $end = $collection === 'posts' ? null : self::storedDate($record['ends_at'] ?? null)?->getTimestamp();
            if ($start !== null && $end !== null && $end <= $start) throw new InvalidArgumentException('The end must be after the start.');
            $result['start'] = $start;
            $result['end'] = $end;
            if ($collection === 'posts' && !in_array($record['status'] ?? 'draft', ['draft', 'published'], true)) throw new InvalidArgumentException('Choose draft or published.');
            if ($collection !== 'posts' && !in_array($record['enabled'] ?? false, [true, false, 1, 0, '1', '0'], true)) throw new InvalidArgumentException('The enabled setting needs review.');
            $result['state'] = !self::enabled($collection, $record) ? ($collection === 'posts' ? 'Draft' : 'Disabled')
                : ($start !== null && $now < $start ? 'Scheduled' : ($end !== null && $now >= $end ? 'Ended' : 'Active'));
            $result['visible'] = $result['state'] === 'Active';
        } catch (InvalidArgumentException $error) {
            $result['problem'] = $error->getMessage();
        }
        return $result;
    }

    public static function describe(string $collection, array $record, ?int $now = null): array
    {
        return array_replace($record, ['revision' => self::revision($record), 'visibility' => self::visibility($collection, $record, $now)]);
    }

    public static function body(mixed $value, int $limit, string $label): string
    {
        if (!is_string($value) || strlen($value) > $limit || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}]/u', $value)) {
            throw new InvalidArgumentException($label . ' must be valid text of at most ' . number_format($limit) . ' bytes.');
        }
        return $value;
    }

    public static function url(mixed $value): string
    {
        if (!is_string($value) || ($value !== '' && !\App\Core\SceneButton::validUrl($value))) {
            throw new InvalidArgumentException('Enter a valid site path or an HTTP or HTTPS link.');
        }
        return $value;
    }

    /** Normalize editable fields only. Never accept arbitrary record metadata. */
    public static function fields(string $collection, array $input, array $previous): array
    {
        self::collection($collection);
        $result = [];
        $single = $collection === 'banners' ? ['text' => 1000] : ['title' => 255];
        if ($collection === 'posts') $single['category'] = 120;
        if ($collection === 'landing_pages') $single += ['eyebrow' => 120, 'cta_label' => 120];
        foreach ($single as $key => $maximum) {
            $result[$key] = MarketingAudience::text($input[$key] ?? $previous[$key] ?? '', $maximum, ucfirst(str_replace('_', ' ', $key)));
        }
        if ($result[$collection === 'banners' ? 'text' : 'title'] === '') throw new InvalidArgumentException('Enter a title or banner text.');
        if ($collection !== 'banners') {
            foreach (['excerpt' => 8000, 'body' => 100000] as $key => $limit) $result[$key] = self::body($input[$key] ?? $previous[$key] ?? '', $limit, ucfirst($key));
            $slug = $input['slug'] ?? $previous['slug'] ?? '';
            if (!is_string($slug)) throw new InvalidArgumentException('Enter a valid permalink slug.');
            if (is_string($previous['slug'] ?? null) && $previous['slug'] !== '') {
                if ($slug !== $previous['slug']) throw new InvalidArgumentException('The saved permalink cannot be changed. Create a new publication for a different address.');
            } else {
                if ($slug === '') $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($result['title'])) ?? '', '-');
                if (strlen($slug) > 160 || !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug)) throw new InvalidArgumentException('Use a permalink of at most 160 lowercase letters, numbers and separating hyphens.');
            }
            $result['slug'] = $slug;
        }
        if ($collection === 'posts') {
            $result['status'] = $input['status'] ?? $previous['status'] ?? 'draft';
            if (!in_array($result['status'], ['draft', 'published'], true)) throw new InvalidArgumentException('Choose draft or published.');
        } else {
            $result['enabled'] = $input['enabled'] ?? $previous['enabled'] ?? false;
            if (!is_bool($result['enabled'])) throw new InvalidArgumentException('Choose whether this publication is enabled.');
            $key = $collection === 'banners' ? 'url' : 'cta_url';
            $result[$key] = self::url($input[$key] ?? $previous[$key] ?? '');
        }
        foreach ($collection === 'posts' ? ['publish_at'] : ['starts_at', 'ends_at'] as $key) {
            if (!array_key_exists($key, $input)) {
                self::storedDate($previous[$key] ?? null);
                $result[$key] = $previous[$key] ?? '';
                continue;
            }
            $result[$key] = self::formDate($input[$key]) ?? '';
            // Retain the exact existing instant, including a DST fold's offset,
            // when the owner has left the displayed local date unchanged.
            try {
                $shown = self::dateForForm($previous[$key] ?? null);
                $submitted = is_string($input[$key]) && strlen($input[$key]) === 16 ? $input[$key] . ':00' : $input[$key];
                if ($shown !== '' && $shown === $submitted) $result[$key] = $previous[$key];
            } catch (InvalidArgumentException) { /* The edited date repairs invalid legacy data. */ }
        }
        $visibility = self::visibility($collection, $result);
        if ($visibility['problem'] !== null) throw new InvalidArgumentException($visibility['problem']);
        return $result;
    }
}

<?php
declare(strict_types=1);

namespace Velvet\Core;

use App\Core\MediaAccess;
use App\Core\MediaInput;
use App\Core\Store;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/MarketingStorage.php';
require_once __DIR__ . '/MarketingPublication.php';
require_once __DIR__ . '/MediaInput.php';
require_once __DIR__ . '/MediaAccess.php';

/** Owner-only calendar projection. Reading never publishes, queues or sends anything. */
final class MarketingCalendar
{
    private const TYPES = ['posts' => 'Posts', 'landing_pages' => 'Landing pages', 'banners' => 'Banners',
        'galleries' => 'Galleries', 'videos' => 'Videos', 'music' => 'Music',
        'campaigns' => 'Email campaigns', 'scenes' => 'Hero / Footer'];

    public static function read(Store $store, mixed $month, mixed $type): array
    {
        $now = new DateTimeImmutable('now');
        [$month, $type] = self::selection($month, $type, $now);
        $marketing = (new MarketingStorage(defined('ROOT_PATH') ? ROOT_PATH : null))->peek() ?? [];
        $records = $store->calendarRecords();
        return self::build($marketing, $records['media'], $records['scenes'], $month, $type, $now);
    }

    /** Pure metadata projection, also used by deterministic boundary tests. */
    public static function build(array $marketing, array $media, array $scenes, mixed $month, mixed $type, ?DateTimeImmutable $now = null): array
    {
        $zone = new DateTimeZone(date_default_timezone_get());
        $now = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
        [$month, $type] = self::selection($month, $type, $now);
        $result = ['month' => $month, 'timezone' => $zone->getName(), 'today' => $now->format('Y-m-d'),
            'type' => $type, 'types' => self::TYPES, 'events' => [], 'undated' => [], 'notices' => [
                'This calendar reads saved dates and current states. Opening it does not publish content, queue recipients or send email.',
                'Scheduled media needs Publish Due or the configured media worker. Both its scheduled date and publish date must be due.',
                'Campaign dates are plans. Drafts must be queued, paused campaigns stay paused, and queued delivery still requires the email runner and current consent checks.',
                'Hero and Footer show retained publication history and saved drafts. Automatic Composer scheduling, activation and expiry are separately pending.',
            ]];

        foreach (['posts', 'landing_pages', 'banners'] as $collection) {
            if ($type !== 'all' && $type !== $collection) continue;
            [$rows, $counts] = self::records($marketing[$collection] ?? [], false);
            foreach ($rows as $index => $row) {
                $title = $row[$collection === 'banners' ? 'text' : 'title'] ?? null;
                $base = self::record($collection, $collection, $row, $counts, $index, $title, false);
                $visibility = MarketingPublication::visibility($collection, $row, $now->getTimestamp());
                $base['state'] = $visibility['state'];
                if ($visibility['problem'] !== null || $base['edit_path'] === '') {
                    self::review($result, $base, $visibility['problem'] ?? 'The saved identity is missing, invalid or repeated. No editor link can be verified.');
                    continue;
                }
                $access = MarketingAccess::policy($row)['mode'];
                $base['detail'] = match ($access) {
                    'members' => 'Member access applies.', 'tiers' => 'Selected membership tiers apply.', default => 'Public access.',
                };
                $base['detail'] .= $collection === 'posts'
                    ? ' A published post starts at its publish date and is hidden at its expiry; a draft stays hidden.'
                    : ' An enabled item starts at its start date and is hidden at its end; a disabled item stays hidden.';
                $start = MarketingPublication::storedDate($row[$collection === 'posts' ? 'publish_at' : 'starts_at'] ?? null);
                $end = MarketingPublication::storedDate($row[$collection === 'posts' ? 'expires_at' : 'ends_at'] ?? null);
                if ($start !== null) self::append($result, $base, $collection === 'posts' ? 'publish' : 'start', $start, $zone);
                if ($end !== null) self::append($result, $base, $collection === 'posts' ? 'expire' : 'end', $end, $zone);
                if ($start === null && $end === null) self::append($result, $base, 'no_dates', null, $zone);
            }
        }

        foreach (['galleries' => 'galleries', 'videos' => 'videos', 'albums' => 'music', 'tracks' => 'music', 'audioPlaylists' => 'music'] as $collection => $contentType) {
            if ($type !== 'all' && $type !== $contentType) continue;
            [$rows, $counts] = self::records($media[$collection] ?? [], true);
            foreach ($rows as $index => $row) {
                if (!empty($row['deletedAt'])) continue;
                $base = self::record($contentType, $collection, $row, $counts, $index, $row['title'] ?? null, true);
                try {
                    if ($base['edit_path'] === '') throw new InvalidArgumentException('The saved identity is missing, invalid or repeated. No editor link can be verified.');
                    $published = MediaInput::storedDate($row['publishedAt'] ?? null);
                    $scheduled = MediaInput::storedDate($row['scheduledAt'] ?? null);
                    $status = $row['status'] ?? 'draft';
                    if (!in_array($status, ['draft', 'processing', 'scheduled', 'published', 'archived', 'unlisted', 'failed'], true)) {
                        throw new InvalidArgumentException('The saved media status needs review.');
                    }
                    $access = $row['accessMode'] ?? 'public';
                    if (!in_array($access, MediaAccess::MODES, true)) throw new InvalidArgumentException('The saved media access mode needs review.');
                    if ($status === 'scheduled' && $scheduled === null) throw new InvalidArgumentException('Scheduled media needs a valid scheduled date.');
                    $due = $scheduled !== null && $scheduled <= $now && ($published === null || $published <= $now);
                    $base['state'] = $status === 'scheduled' ? ($due ? 'Due — awaiting publication' : 'Scheduled')
                        : ($status === 'published' ? 'Saved as published' : ucfirst($status));
                    $kind = match ($collection) { 'albums' => 'Album', 'tracks' => 'Track', 'audioPlaylists' => 'Playlist', 'galleries' => 'Gallery', default => 'Video' };
                    $base['detail'] = $kind . '. ' . MediaAccess::label($access) . ' access. Dates reflect saved metadata; access and media readiness still apply.';
                    if ($status === 'scheduled') $base['detail'] .= ' Publication requires both date bounds to be due and an explicit Publish Due action or configured worker.';
                    if ($published !== null) self::append($result, $base, 'publish_date', $published, $zone);
                    if ($scheduled !== null) self::append($result, $base, 'scheduled_publication', $scheduled, $zone);
                    if ($published === null && $scheduled === null) self::append($result, $base, 'no_dates', null, $zone);
                } catch (InvalidArgumentException|RuntimeException $error) {
                    self::review($result, $base, $error->getMessage());
                }
            }
        }

        if ($type === 'all' || $type === 'campaigns') {
            [$rows, $counts] = self::records($marketing['campaigns'] ?? [], false);
            foreach ($rows as $index => $row) {
                $base = self::record('campaigns', 'campaigns', $row, $counts, $index, $row['subject'] ?? null, false);
                try {
                    if ($base['edit_path'] === '') throw new InvalidArgumentException('The saved identity is missing, invalid or repeated. No editor link can be verified.');
                    $at = MarketingPublication::storedDate($row['scheduled_at'] ?? null);
                    $status = $row['status'] ?? 'draft';
                    $paused = $row['delivery_paused'] ?? false;
                    if (!in_array($status, ['draft', 'queued'], true) || !is_bool($paused)) throw new InvalidArgumentException('The saved campaign state needs review.');
                    $base['state'] = $paused ? 'Paused' : ($status === 'draft' ? 'Draft'
                        : ($at === null || $at <= $now ? 'Due — awaiting delivery' : 'Queued'));
                    $base['detail'] = $status === 'draft' ? 'Planned send date only. This draft has not been queued.'
                        : 'Queued campaign. This date does not confirm delivery; the email runner and current consent checks still apply.';
                    if ($paused) $base['detail'] .= ' Delivery is paused.';
                    if (isset($row['delivery_snapshot']) || isset($row['audience_snapshot'])) {
                        $base['edit_path'] = 'admin/?page=marketing&tab=campaigns';
                        $base['edit_label'] = 'Open campaigns';
                    }
                    self::append($result, $base, $at === null ? 'no_date' : 'planned_send', $at, $zone);
                } catch (InvalidArgumentException $error) { self::review($result, $base, $error->getMessage()); }
            }
        }

        if ($type === 'all' || $type === 'scenes') {
            foreach (['hero' => 'Hero', 'footer' => 'Footer'] as $section => $title) {
                $saved = $scenes[$section] ?? [];
                $versions = is_array($saved) ? ($saved['versions'] ?? []) : [[]];
                [$rows, $counts] = self::records(is_array($versions) ? array_slice($versions, -20) : $versions, true);
                foreach ($rows as $index => $row) {
                    $base = self::record('scenes', 'scenes-' . $section, $row, $counts, $index, $title, true);
                    $base['source_id'] = $section;
                    $base['state'] = 'Publication history';
                    $base['detail'] = 'Published version retained in Composer history. It may have been replaced by a later publication.';
                    try {
                        if ($base['edit_path'] === '') throw new InvalidArgumentException('The retained version identity is missing, invalid or repeated.');
                        $at = MarketingPublication::storedDate($row['publishedAt'] ?? null);
                        if ($at === null) throw new InvalidArgumentException('The retained publication date is missing.');
                        self::append($result, $base, 'published_version', $at, $zone);
                    } catch (InvalidArgumentException $error) { self::review($result, $base, $error->getMessage()); }
                }
                if (($saved['draft'] ?? false) === true) {
                    self::append($result, ['key' => 'scenes:' . $section . ':draft', 'source_id' => $section,
                        'type' => 'scenes', 'type_label' => self::TYPES['scenes'], 'title' => $title,
                        'state' => 'Draft', 'detail' => 'Saved Composer draft. Publish deliberately in Composer; there is no automatic scene schedule.',
                        'edit_path' => 'admin/?page=composer', 'edit_label' => 'Open Composer'], 'draft', null, $zone);
                }
            }
        }

        usort($result['events'], static fn(array $a, array $b): int => $a['_instant'] <=> $b['_instant'] ?: strcmp($a['key'], $b['key']));
        foreach ($result['events'] as &$event) unset($event['_instant']);
        unset($event);
        usort($result['undated'], static fn(array $a, array $b): int => strcmp($a['type'], $b['type']) ?: strcmp($a['title'], $b['title']) ?: strcmp($a['key'], $b['key']));
        return $result;
    }

    private static function selection(mixed $month, mixed $type, DateTimeImmutable $now): array
    {
        $month ??= $now->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m');
        $type ??= 'all';
        if (!is_string($month) || !preg_match('/\A([0-9]{4})-(0[1-9]|1[0-2])\z/D', $month, $parts)
            || (int) $parts[1] < 1970 || (int) $parts[1] > 9998) {
            throw new InvalidArgumentException('Choose a month from January 1970 through December 9998.');
        }
        if (!is_string($type) || ($type !== 'all' && !array_key_exists($type, self::TYPES))) {
            throw new InvalidArgumentException('Choose a valid calendar content type.');
        }
        return [$month, $type];
    }

    private static function records(mixed $value, bool $media): array
    {
        $rows = is_array($value) ? array_values($value) : [[]];
        $counts = [];
        foreach ($rows as &$row) {
            if (!is_array($row)) $row = [];
            $id = self::identity($row['id'] ?? null, $media);
            if ($id !== '') $counts['id:' . $id] = ($counts['id:' . $id] ?? 0) + 1;
        }
        unset($row);
        return [$rows, $counts];
    }

    private static function identity(mixed $value, bool $media): string
    {
        try { return $media ? MediaInput::id($value, false) : MarketingPublication::id($value); }
        catch (InvalidArgumentException|RuntimeException) { return ''; }
    }

    private static function record(string $type, string $collection, array $row, array $counts, int $index, mixed $title, bool $media): array
    {
        $id = self::identity($row['id'] ?? null, $media);
        $unique = $id !== '' && ($counts['id:' . $id] ?? 0) === 1;
        $title = is_string($title) && strlen($title) <= 1200 && preg_match('//u', $title) === 1
            && !preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $title) ? trim($title) : '';
        $path = match ($collection) {
            'posts' => 'admin/?page=marketing&tab=posts&edit_post=',
            'banners' => 'admin/?page=marketing&tab=banners&edit_banner=',
            'landing_pages' => 'admin/?page=marketing&tab=campaigns&edit_page=',
            'campaigns' => 'admin/?page=marketing&tab=campaigns&edit_campaign=',
            'galleries' => 'admin/?page=galleries&edit=', 'videos' => 'admin/?page=videos&edit=',
            'albums' => 'admin/?page=music&edit_album=', 'tracks' => 'admin/?page=music&edit_track=',
            'audioPlaylists' => 'admin/?page=music&edit_playlist=', default => 'admin/?page=composer',
        };
        return ['key' => $collection . ':' . ($unique ? 'record:' . $id : 'review:' . $index), 'source_id' => $id,
            'type' => $type, 'type_label' => self::TYPES[$type], 'title' => $title !== '' ? $title : 'Untitled ' . strtolower(self::TYPES[$type]),
            'state' => '', 'detail' => '', 'edit_path' => $unique ? $path . ($type === 'scenes' ? '' : rawurlencode($id)) : '',
            'edit_label' => $type === 'scenes' ? 'Open Composer' : 'Edit'];
    }

    private static function review(array &$result, array $base, string $detail): void
    {
        $base['state'] = 'Needs review';
        $base['detail'] = $detail;
        self::append($result, $base, 'review', null, new DateTimeZone($result['timezone']));
    }

    private static function append(array &$result, array $base, string $action, ?DateTimeImmutable $at, DateTimeZone $zone): void
    {
        $base['key'] .= ':' . $action;
        $base['action'] = $action;
        if ($at === null) { $result['undated'][] = $base; return; }
        $local = $at->setTimezone($zone);
        if ($local->format('Y-m') !== $result['month']) return;
        $result['events'][] = $base + ['at' => $local->format(DateTimeInterface::ATOM), 'date' => $local->format('Y-m-d'), '_instant' => $local->getTimestamp()];
    }
}

<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Validation and private-state projection for bounded Composer schedules. */
final class SceneSchedule
{
    public const MAX_SCENE_BYTES = 1_800_000;
    public const MAX_STATE_BYTES = 4_000_000;
    public const CLAIM_SECONDS = 60;
    public const MAX_HISTORY = 20;

    public static function section(mixed $section): string
    {
        if (!is_string($section) || !in_array($section, ['hero', 'footer'], true)) {
            throw new InvalidArgumentException('Choose Homepage Hero or Visual Footer.');
        }
        return $section;
    }

    public static function emptyState(): array
    {
        return ['schema' => 1, 'revision' => 0, 'schedule' => null, 'history' => []];
    }

    public static function state(mixed $value): array
    {
        if ($value === null) return self::emptyState();
        if (is_string($value)) {
            if (strlen($value) > self::MAX_STATE_BYTES) throw new RuntimeException('Saved scene scheduling needs review.');
            try { $value = json_decode($value, true, 128, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new RuntimeException('Saved scene scheduling needs review.'); }
        }
        if (!is_array($value) || ($value['schema'] ?? null) !== 1
            || !is_int($value['revision'] ?? null) || $value['revision'] < 0 || $value['revision'] >= PHP_INT_MAX - 1
            || !array_key_exists('schedule', $value) || ($value['schedule'] !== null && !is_array($value['schedule']))
            || !is_array($value['history'] ?? null) || !array_is_list($value['history']) || count($value['history']) > self::MAX_HISTORY) {
            throw new RuntimeException('Saved scene scheduling needs review.');
        }
        foreach ($value['history'] as $entry) if (!is_array($entry)) throw new RuntimeException('Saved scene scheduling history needs review.');
        if (strlen(self::json($value)) > self::MAX_STATE_BYTES) throw new RuntimeException('Saved scene scheduling needs review.');
        return $value;
    }

    /** Persisted data crosses the same fail-closed boundary on every worker phase. */
    public static function job(array $job, string $section): array
    {
        $invalid = static function (): never { throw new InvalidArgumentException('The saved scene schedule needs review.'); };
        if (!is_string($job['id'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/D', $job['id'])
            || ($job['section'] ?? null) !== $section
            || !in_array($job['status'] ?? null, ['scheduled', 'active', 'processing'], true)
            || !in_array($job['returnPolicy'] ?? null, ['keep', 'restore_previous'], true)
            || !is_array($job['scene'] ?? null) || $job['scene'] === []
            || !is_string($job['snapshotRevision'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $job['snapshotRevision'])
            || !hash_equals($job['snapshotRevision'], self::sceneRevision($job['scene']))
            || !is_string($job['baselineRevision'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $job['baselineRevision'])
            || !is_array($job['owner'] ?? null)) $invalid();
        $owner = $job['owner'];
        if ((!is_int($owner['id'] ?? null) && !is_string($owner['id'] ?? null)) || (string) $owner['id'] === '' || strlen((string) $owner['id']) > 80
            || !is_string($owner['email'] ?? null) || $owner['email'] === '' || strlen($owner['email']) > 254) $invalid();
        try {
            $start = self::epoch($job['startsAt'] ?? null);
            $end = ($job['endsAt'] ?? null) === null ? null : self::epoch($job['endsAt']);
            self::epoch($job['createdAt'] ?? null);
            self::epoch($job['updatedAt'] ?? null);
        } catch (RuntimeException) { $invalid(); }
        if (($end !== null && $end <= $start) || ($job['returnPolicy'] === 'restore_previous' && $end === null)) $invalid();
        $stage = $job['status'];
        if ($stage === 'processing') {
            $claim = $job['claim'] ?? null;
            if (!is_array($claim) || !is_string($claim['token'] ?? null) || !preg_match('/\A[a-f0-9]{48}\z/D', $claim['token'])
                || !is_int($claim['until'] ?? null) || $claim['until'] < 0
                || !in_array($claim['previousStatus'] ?? null, ['scheduled', 'active'], true)
                || ($claim['operation'] ?? null) !== ($claim['previousStatus'] === 'scheduled' ? 'activate' : 'expire')) $invalid();
            $stage = $claim['previousStatus'];
        } elseif (($job['claim'] ?? null) !== null) $invalid();
        if ($stage === 'active') {
            if ($end === null || !is_string($job['activatedRevision'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $job['activatedRevision'])) $invalid();
            try { self::epoch($job['activatedAt'] ?? null); } catch (RuntimeException) { $invalid(); }
            $activated = $job['scene'];
            $activated['updatedAt'] = $job['activatedAt'];
            if (!hash_equals($job['activatedRevision'], self::sceneRevision($activated))) $invalid();
            if ($job['returnPolicy'] === 'restore_previous' && (!is_array($job['previousScene'] ?? null) || $job['previousScene'] === []
                || !is_string($job['previousRevision'] ?? null) || !hash_equals($job['previousRevision'], self::sceneRevision($job['previousScene'])))) $invalid();
        } elseif (($job['previousScene'] ?? null) !== null || ($job['previousRevision'] ?? null) !== null
            || ($job['activatedRevision'] ?? null) !== null || isset($job['activatedAt'])) $invalid();
        return $job;
    }

    public static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function revision(array $state): string
    {
        return hash('sha256', self::json(['timezone' => date_default_timezone_get(), 'state' => $state]));
    }

    public static function sceneRevision(array $scene): string
    {
        return hash('sha256', self::json($scene));
    }

    public static function expect(mixed $expected, string $actual, string $label): void
    {
        if (!is_string($expected) || !preg_match('/\A[a-f0-9]{64}\z/D', $expected) || !hash_equals($actual, $expected)) {
            throw new RuntimeException($label . ' changed after this page opened. Reload the saved state before trying again.', 409);
        }
    }

    /** Accept real, unambiguous local wall times only; store explicit UTC instants. */
    public static function formDate(mixed $value, bool $optional = false): ?string
    {
        if ($optional && ($value === '' || $value === null)) return null;
        if (!is_string($value) || !preg_match('/\A(?:19[7-9][0-9]|[2-9][0-9]{3})-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?\z/D', $value)) {
            throw new InvalidArgumentException('Enter a scene date and time in the site timezone.');
        }
        $local = strlen($value) === 16 ? $value . ':00' : $value;
        $zone = new DateTimeZone(date_default_timezone_get());
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $local, $zone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $date->format('Y-m-d\TH:i:s') !== $local || (int) $date->format('Y') > 9998) {
            throw new InvalidArgumentException('Choose a real scene date and time; skipped daylight-saving times are not valid.');
        }
        $wall = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $local, new DateTimeZone('UTC'));
        $offsets = [$zone->getOffset($date)];
        $transitions = $zone->getTransitions($date->getTimestamp() - 172800, $date->getTimestamp() + 172800);
        foreach (is_array($transitions) ? $transitions : [] as $transition) $offsets[] = (int) $transition['offset'];
        $matches = [];
        foreach (array_unique($offsets) as $offset) {
            $candidate = (new DateTimeImmutable('@' . ($wall->getTimestamp() - $offset)))->setTimezone($zone);
            if ($candidate->format('Y-m-d\TH:i:s') === $local) $matches[$candidate->getTimestamp()] = true;
        }
        if (count($matches) !== 1) {
            throw new InvalidArgumentException('This scene time occurs twice when clocks change. Choose a time outside the repeated hour.');
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }

    public static function epoch(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/D', $value)) {
            throw new RuntimeException('A saved scene time needs review.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format(DateTimeInterface::ATOM) !== $value) {
            throw new RuntimeException('A saved scene time needs review.');
        }
        return $date->getTimestamp();
    }

    public static function at(int $epoch): string
    {
        return (new DateTimeImmutable('@' . $epoch))->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }

    public static function scene(array $scene, string $section): array
    {
        if ($scene === [] || strlen(self::json($scene)) > self::MAX_SCENE_BYTES) {
            throw new InvalidArgumentException('Save a composition of less than 1.8 MB before scheduling it.');
        }
        $scene = SceneDepth::validateScene(SceneButton::validateScene($scene), self::section($section));
        $scene['section'] = $section;
        return $scene;
    }

    public static function title(array $scene, string $section): string
    {
        $title = $scene['title'] ?? $scene['name'] ?? null;
        return is_string($title) && trim($title) !== '' ? mb_substr(trim($title), 0, 160)
            : ($section === 'hero' ? 'Homepage Hero' : 'Visual Footer');
    }

    /** Never disclose scene snapshots, owner identity, claim tokens or return snapshots. */
    public static function metadata(array $job): array
    {
        $result = [];
        foreach (['id' => 80, 'section' => 20, 'status' => 30, 'snapshotTitle' => 640, 'startsAt' => 40, 'endsAt' => 40,
            'returnPolicy' => 30, 'createdAt' => 40, 'updatedAt' => 40, 'activatedAt' => 40, 'finishedAt' => 40, 'reason' => 1000] as $key => $maximum) {
            $value = $job[$key] ?? null;
            if ($value === null) { if (array_key_exists($key, $job)) $result[$key] = null; continue; }
            if (is_string($value) && strlen($value) <= $maximum && preg_match('//u', $value) === 1) $result[$key] = $value;
        }
        return $result;
    }

    public static function snapshot(array $state, array $draft, array $published, string $section): array
    {
        return ['revision' => self::revision($state), 'draftRevision' => self::sceneRevision($draft),
            'publishedRevision' => self::sceneRevision($published), 'hasDraft' => $draft !== [],
            'draftTitle' => self::title($draft, $section), 'publishedTitle' => $published !== [] ? self::title($published, $section) : '',
            'schedule' => is_array($state['schedule']) ? self::metadata($state['schedule']) : null,
            'history' => array_reverse(array_map(static fn(array $job): array => self::metadata($job), $state['history']))];
    }
}

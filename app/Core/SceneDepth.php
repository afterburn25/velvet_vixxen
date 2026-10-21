<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/** Optional depth and source-image foreground data; legacy scenes stay untouched. */
final class SceneDepth
{
    public const MAX_POINTS = 64;
    private const EPSILON = 1.0e-9;
    private const MIN_AREA = 1.0e-7;

    public static function validateScene(array $scene, string $section = 'hero'): array
    {
        foreach ((array) ($scene['devices'] ?? []) as $view) {
            if (!is_array($view)) continue;
            if (array_key_exists('perspective', $view)) {
                if (!self::point($view['perspective'])) {
                    throw new InvalidArgumentException('The perspective point needs numeric X and Y positions between 0 and 100.');
                }
            }
            if (array_key_exists('foreground', $view)) {
                $mask = $view['foreground'];
                if (!is_array($mask) || !self::keys($mask, ['enabled', 'source', 'points'])
                    || !is_bool($mask['enabled']) || !self::assetPath($mask['source'])
                    || !self::validPolygon($mask['points'])) {
                    throw new InvalidArgumentException('Foreground furniture needs a valid image and a simple outline of 3 to ' . self::MAX_POINTS . ' distinct points.');
                }
                $background = $view['background']['src'] ?? $scene['background']['src']
                    ?? ('images/defaults/' . ($section === 'footer' ? 'footer' : 'hero') . '-pink-room.jpg');
                if ($mask['enabled'] && $mask['source'] !== $background) {
                    throw new InvalidArgumentException('The foreground outline belongs to a different background. Disable it or redraw it for this image.');
                }
            }
            foreach ((array) ($view['layers'] ?? []) as $layer) {
                if (!is_array($layer)) continue;
                if (array_key_exists('depth', $layer) && !self::percentage($layer['depth'])) {
                    throw new InvalidArgumentException('Photo depth must be a number between 0 and 100.');
                }
                if (array_key_exists('behindForeground', $layer) && !is_bool($layer['behindForeground'])) {
                    throw new InvalidArgumentException('The photo foreground placement must be on or off.');
                }
                if (($layer['type'] ?? '') === 'button'
                    && (($layer['depth'] ?? 0) != 0 || ($layer['behindForeground'] ?? false))) {
                    throw new InvalidArgumentException('Depth and foreground placement apply to photos. Buttons stay in front.');
                }
            }
        }
        return $scene;
    }

    public static function validPolygon(mixed $points): bool
    {
        if (!is_array($points) || !array_is_list($points) || count($points) < 3 || count($points) > self::MAX_POINTS) return false;
        $count = count($points);
        foreach ($points as $index => $point) {
            if (!self::point($point)) return false;
            for ($other = 0; $other < $index; $other++) {
                if (abs($point['x'] - $points[$other]['x']) <= self::EPSILON
                    && abs($point['y'] - $points[$other]['y']) <= self::EPSILON) return false;
            }
        }
        $area = 0.0;
        for ($index = 0; $index < $count; $index++) {
            $next = ($index + 1) % $count;
            $area += $points[$index]['x'] * $points[$next]['y'] - $points[$next]['x'] * $points[$index]['y'];
            for ($other = $index + 1; $other < $count; $other++) {
                $after = ($other + 1) % $count;
                if ($next === $other || $after === $index) continue;
                if (self::intersects($points[$index], $points[$next], $points[$other], $points[$after])) return false;
            }
        }
        return abs($area) / 2 > self::MIN_AREA;
    }

    private static function percentage(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 100;
    }

    private static function keys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && array_diff($keys, array_keys($value)) === [];
    }

    private static function point(mixed $point): bool
    {
        return is_array($point) && self::keys($point, ['x', 'y'])
            && self::percentage($point['x']) && self::percentage($point['y']);
    }

    private static function assetPath(mixed $source): bool
    {
        if (!is_string($source) || $source === '' || strlen($source) > 2048
            || preg_match('/%(?![0-9a-f]{2})/i', $source)) return false;
        $decoded = rawurldecode($source);
        return !str_starts_with($decoded, '/') && !preg_match('/[\x00-\x20\x7f\\\\:?#]/', $decoded)
            && !preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $decoded);
    }

    private static function cross(array $a, array $b, array $c): float
    {
        return (float) (($b['x'] - $a['x']) * ($c['y'] - $a['y']) - ($b['y'] - $a['y']) * ($c['x'] - $a['x']));
    }

    private static function onSegment(array $a, array $b, array $point): bool
    {
        return abs(self::cross($a, $b, $point)) <= self::EPSILON
            && $point['x'] >= min($a['x'], $b['x']) - self::EPSILON
            && $point['x'] <= max($a['x'], $b['x']) + self::EPSILON
            && $point['y'] >= min($a['y'], $b['y']) - self::EPSILON
            && $point['y'] <= max($a['y'], $b['y']) + self::EPSILON;
    }

    private static function intersects(array $a, array $b, array $c, array $d): bool
    {
        $abC = self::cross($a, $b, $c);
        $abD = self::cross($a, $b, $d);
        $cdA = self::cross($c, $d, $a);
        $cdB = self::cross($c, $d, $b);
        return (($abC > self::EPSILON && $abD < -self::EPSILON) || ($abC < -self::EPSILON && $abD > self::EPSILON))
            && (($cdA > self::EPSILON && $cdB < -self::EPSILON) || ($cdA < -self::EPSILON && $cdB > self::EPSILON))
            || self::onSegment($a, $b, $c) || self::onSegment($a, $b, $d)
            || self::onSegment($c, $d, $a) || self::onSegment($c, $d, $b);
    }
}

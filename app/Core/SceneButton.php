<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/** Validate live button layers without rewriting existing photo compositions. */
final class SceneButton
{
    public static function validateScene(array $scene): array
    {
        foreach ((array) ($scene['devices'] ?? []) as $device => $view) {
            if (!is_array($view) || !is_array($view['layers'] ?? null)) continue;
            foreach ($view['layers'] as $index => $layer) {
                if (!is_array($layer) || ($layer['type'] ?? '') !== 'button') continue;
                $scene['devices'][$device]['layers'][$index] = self::validate($layer);
            }
        }
        return $scene;
    }

    public static function validate(array $layer): array
    {
        $label = self::text($layer['label'] ?? 'Button', 'label', 120);
        if ($label === '') throw new InvalidArgumentException('Enter a label for the button.');
        $url = self::text($layer['url'] ?? 'booking/', 'destination', 2048);
        if (!self::validUrl((string) ($layer['url'] ?? 'booking/'))) {
            throw new InvalidArgumentException('Use a page on this site or a complete http:// or https:// button destination.');
        }
        $style = $layer['style'] ?? 'solid';
        if (!in_array($style, ['solid', 'outline', 'pill'], true)) {
            throw new InvalidArgumentException('Choose a solid, outline or pill button style.');
        }
        $validated = array_replace($layer, [
            'type' => 'button', 'label' => $label, 'url' => $url, 'style' => $style,
            'name' => self::text($layer['name'] ?? $label, 'name', 160),
            'color' => self::color($layer['color'] ?? '#ff2d8d'),
            'textColor' => self::color($layer['textColor'] ?? '#ffffff'),
            'borderColor' => self::color($layer['borderColor'] ?? '#ff2d8d'),
        ]);
        foreach ([
            'x' => [50, -400, 500], 'y' => [80, -400, 500],
            'width' => [20, 0.5, 400], 'height' => [8, 0.5, 400],
            'rotation' => [0, -180, 180], 'opacity' => [100, 0, 100],
            'fontSize' => [18, 8, 96], 'radius' => [10, 0, 100],
            'z' => [1, -100000, 100000],
        ] as $key => [$default, $minimum, $maximum]) {
            $value = $layer[$key] ?? $default;
            if ((!is_int($value) && !is_float($value) && !is_string($value))
                || !is_numeric($value) || !is_finite((float) $value)
                || (float) $value < $minimum || (float) $value > $maximum) {
                throw new InvalidArgumentException('Button ' . $key . ' must be between ' . $minimum . ' and ' . $maximum . '.');
            }
            $validated[$key] = (float) $value;
        }
        foreach (['newTab' => false, 'visible' => true, 'locked' => false] as $key => $default) {
            $value = $layer[$key] ?? $default;
            if (!is_bool($value)) throw new InvalidArgumentException('The button ' . $key . ' setting is invalid.');
            $validated[$key] = $value;
        }
        return $validated;
    }

    public static function validUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $url)
            || preg_match('/%(?![0-9a-f]{2})/i', $url)) return false;
        $decoded = rawurldecode($url);
        if (str_starts_with($url, '//') || str_starts_with($decoded, '//') || str_contains($decoded, '\\')) return false;
        if (preg_match('#^https?://#i', $url)) {
            $parts = parse_url($url);
            return filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts)
                && !isset($parts['user']) && !isset($parts['pass']);
        }
        // Relative page paths, query strings and fragment links remain on this site.
        if (preg_match('/^[^\/?#]*:/', $url) || preg_match('/^[^\/?#]*:/', $decoded)) return false;
        $path = (string) (parse_url($decoded, PHP_URL_PATH) ?? '');
        return !preg_match('#(?:^|/)\.\.(?:/|$)#', $path);
    }

    private static function text(mixed $value, string $name, int $maximum): string
    {
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > $maximum || str_contains($value, "\0")) {
            throw new InvalidArgumentException('The button ' . $name . ' is invalid or too long.');
        }
        return trim($value);
    }

    private static function color(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^#[0-9a-f]{6}$/i', $value)) {
            throw new InvalidArgumentException('Choose a valid six-digit button color.');
        }
        return strtolower($value);
    }
}

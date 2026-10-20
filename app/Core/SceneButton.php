<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/** Validate live buttons without rewriting existing photo compositions. */
final class SceneButton
{
    public const MAX_CONTENT_BUTTONS = 8;

    public static function validateScene(array $scene): array
    {
        // Missing contentButtons is the legacy automatic group, not an empty group.
        if (array_key_exists('contentButtons', $scene)) {
            $buttons = $scene['contentButtons'];
            if (!is_array($buttons) || !array_is_list($buttons) || count($buttons) > self::MAX_CONTENT_BUTTONS) {
                throw new InvalidArgumentException('The live text group can contain up to ' . self::MAX_CONTENT_BUTTONS . ' buttons.');
            }
            $ids = [];
            foreach ($buttons as $index => $button) {
                if (!is_array($button)) throw new InvalidArgumentException('The live group button is invalid.');
                $button = self::validateContentButton($button);
                if (isset($ids[$button['id']])) throw new InvalidArgumentException('Each live group button needs its own identity.');
                $ids[$button['id']] = true;
                $scene['contentButtons'][$index] = $button;
            }
        }
        foreach ((array) ($scene['devices'] ?? []) as $device => $view) {
            if (!is_array($view) || !is_array($view['layers'] ?? null)) continue;
            foreach ($view['layers'] as $index => $layer) {
                if (!is_array($layer) || ($layer['type'] ?? '') !== 'button') continue;
                $scene['devices'][$device]['layers'][$index] = self::validate($layer);
            }
        }
        return $scene;
    }

    public static function defaultContentButtons(string $section): array
    {
        $account = ['id' => 'content-account', 'action' => 'account', 'label' => '', 'url' => '', 'style' => 'primary', 'newTab' => false];
        return $section === 'footer' ? [$account] : [$account,
            ['id' => 'content-trailer', 'action' => 'trailer', 'label' => '', 'url' => '', 'style' => 'glass', 'newTab' => false],
        ];
    }

    /** Use automatic legacy actions until the owner first edits this scene's group. */
    public static function contentButtons(array $scene, string $section): array
    {
        if (!array_key_exists('contentButtons', $scene)) return self::defaultContentButtons($section);
        if (!is_array($scene['contentButtons']) || !array_is_list($scene['contentButtons'])) return [];
        $buttons = [];
        foreach (array_slice($scene['contentButtons'], 0, self::MAX_CONTENT_BUTTONS) as $button) {
            if (!is_array($button)) continue;
            try { $buttons[] = self::validateContentButton($button); } catch (InvalidArgumentException) {
                // Never emit an unsafe destination from an older/imported composition.
            }
        }
        return $buttons;
    }

    public static function validateContentButton(array $button): array
    {
        $id = $button['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-z0-9_-]{1,80}$/i', $id)) {
            throw new InvalidArgumentException('The live group button identity is invalid.');
        }
        $action = $button['action'] ?? 'link';
        if (!in_array($action, ['link', 'account', 'trailer'], true)) {
            throw new InvalidArgumentException('Choose a website link, account button or trailer button.');
        }
        $label = self::text($button['label'] ?? '', 'label', 120);
        $url = self::text($button['url'] ?? '', 'destination', 2048);
        if ($action === 'link') {
            if ($label === '') throw new InvalidArgumentException('Enter a label for the live group button.');
            if (!self::validUrl((string) ($button['url'] ?? ''))) {
                throw new InvalidArgumentException('Use a page on this site or a complete http:// or https:// button destination.');
            }
        } elseif ($url !== '') {
            throw new InvalidArgumentException('Choose Website link to set a custom button destination.');
        }
        $style = $button['style'] ?? 'primary';
        if (!in_array($style, ['primary', 'glass', 'ghost'], true)) {
            throw new InvalidArgumentException('Choose a pink, glass or subtle button style.');
        }
        $newTab = $button['newTab'] ?? false;
        if (!is_bool($newTab)) throw new InvalidArgumentException('The button new-tab setting is invalid.');
        return ['id' => $id, 'action' => $action, 'label' => $label, 'url' => $url, 'style' => $style, 'newTab' => $newTab];
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

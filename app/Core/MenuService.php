<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;

/** Bounded, ordinary website navigation; account and cart controls stay separate. */
final class MenuService
{
    public const MAX_ITEMS = 50;
    public const MAX_DEPTH = 3;
    public const MAX_LABEL = 80;
    public const MAX_URL = 2048;
    public const MAX_JSON_BYTES = 262144;
    public const MAX_REVISION = 9007199254740991;

    public static function location(mixed $location): string
    {
        if (!is_string($location) || !in_array($location, ['header', 'footer'], true)) {
            throw new InvalidArgumentException('Choose the Header or Footer menu.');
        }
        return $location;
    }

    public static function revision(mixed $revision): int
    {
        if (is_int($revision) && $revision >= 0 && $revision <= self::MAX_REVISION) return $revision;
        if (is_string($revision) && preg_match('/^(?:0|[1-9][0-9]{0,15})$/D', $revision)
            && (string) (int) $revision === $revision && (int) $revision <= self::MAX_REVISION) return (int) $revision;
        throw new InvalidArgumentException('The menu revision is invalid. Reload the saved menu before trying again.');
    }

    /** Exact existing defaults remain read-only until an owner saves a location. */
    public static function defaults(string $location, string $context = 'common'): array
    {
        self::location($location);
        $links = [
            'home' => ['Home', $location === 'footer' && $context === 'home' ? '#top' : '/'],
            'music' => ['Music', 'music/'], 'photos' => ['Photos', 'photos/'],
            'videos' => ['Videos', 'videos/'], 'membership' => ['Membership', 'membership/'],
            'shop' => ['Store', 'shop/'],
        ];
        if ($location === 'footer' && $context !== 'home') $links['messages'] = ['Messages', 'messages/'];
        $links['booking'] = ['Booking', 'booking/'];
        $links['about'] = ['About Me', 'about/'];
        $items = [];
        foreach ($links as $id => [$label, $url]) {
            $items[] = ['id' => $id, 'label' => $label, 'url' => $url, 'target' => 'self', 'enabled' => true, 'children' => []];
        }
        return $items;
    }

    public static function parseItems(string $json): array
    {
        if (strlen($json) > self::MAX_JSON_BYTES) throw new InvalidArgumentException('This menu is too large to save.');
        try { $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new InvalidArgumentException('The menu could not be read. Reload the saved menu before trying again.'); }
        $convert = static function (mixed $nodes) use (&$convert): array {
            if (!is_array($nodes)) throw new InvalidArgumentException('Menu items must be an ordered list.');
            $items = [];
            foreach ($nodes as $node) {
                if (!$node instanceof \stdClass) throw new InvalidArgumentException('A menu item has an invalid structure.');
                $item = get_object_vars($node);
                $item['children'] = $convert($item['children'] ?? null);
                $items[] = $item;
            }
            return $items;
        };
        return self::validateItems($convert($decoded));
    }

    /** Reject the whole submitted edit rather than silently discarding an item. */
    public static function validateItems(mixed $items): array
    {
        $ids = [];
        $count = 0;
        $walk = static function (mixed $nodes, int $depth) use (&$walk, &$ids, &$count): array {
            if (!is_array($nodes) || !array_is_list($nodes)) throw new InvalidArgumentException('Menu items must be an ordered list.');
            if ($nodes !== [] && $depth > self::MAX_DEPTH) throw new InvalidArgumentException('Menus can have up to three levels.');
            $result = [];
            foreach ($nodes as $node) {
                if (++$count > self::MAX_ITEMS) throw new InvalidArgumentException('Use no more than 50 items in each menu.');
                if (!is_array($node) || array_is_list($node)
                    || array_diff(array_keys($node), ['id', 'label', 'url', 'target', 'enabled', 'children']) !== []) {
                    throw new InvalidArgumentException('A menu item has an invalid structure.');
                }
                $id = $node['id'] ?? null;
                if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $id) || isset($ids[$id])) {
                    throw new InvalidArgumentException('Every menu item needs its own valid identity.');
                }
                $ids[$id] = true;
                $label = $node['label'] ?? null;
                if (!is_string($label) || !mb_check_encoding($label, 'UTF-8')
                    || mb_strlen($label, 'UTF-8') > self::MAX_LABEL || preg_match('/[\x00-\x1f\x7f]/', $label)
                    || trim($label) === '') throw new InvalidArgumentException('Enter a menu label of 1–80 characters.');
                $url = $node['url'] ?? null;
                if (!is_string($url) || !mb_check_encoding($url, 'UTF-8') || strlen($url) > self::MAX_URL
                    || preg_match('/[\x00-\x1f\x7f]/', $url)) {
                    throw new InvalidArgumentException('Enter a valid menu destination of no more than 2048 bytes.');
                }
                $url = trim($url);
                $target = $node['target'] ?? null;
                if (!is_string($target) || !in_array($target, ['self', 'tab', 'window'], true)) {
                    throw new InvalidArgumentException('Choose Same tab, New tab or New window.');
                }
                $enabled = $node['enabled'] ?? null;
                if (!is_bool($enabled)) throw new InvalidArgumentException('The menu item visibility setting is invalid.');
                $children = $walk($node['children'] ?? null, $depth + 1);
                if ($url === '') {
                    if ($children === [] || $target !== 'self') {
                        throw new InvalidArgumentException('An item without a destination needs submenu items and Same tab selected.');
                    }
                } elseif (!SceneButton::validUrl($url)) {
                    throw new InvalidArgumentException('Use a page on this site, a fragment, or a complete http:// or https:// menu destination.');
                }
                $result[] = ['id' => $id, 'label' => trim($label), 'url' => $url, 'target' => $target,
                    'enabled' => $enabled, 'children' => $children];
            }
            return $result;
        };
        return $walk($items, 1);
    }

    /** A bad imported tree never becomes permission to render unsafe links. */
    public static function publicItems(array $snapshot): array
    {
        try { $items = self::validateItems($snapshot['items'] ?? null); }
        catch (InvalidArgumentException) { return []; }
        $walk = static function (array $nodes) use (&$walk): array {
            $result = [];
            foreach ($nodes as $node) {
                if (!$node['enabled']) continue;
                $node['children'] = $walk($node['children']);
                if ($node['url'] === '' && $node['children'] === []) continue;
                $result[] = $node;
            }
            return $result;
        };
        return $walk($items);
    }

    public static function snapshot(mixed $record, bool $exists, string $location, string $context = 'common'): array
    {
        self::location($location);
        if (!$exists) return ['revision' => 0, 'configured' => false, 'items' => self::defaults($location, $context)];
        try {
            if (!is_array($record) || ($record['schema'] ?? null) !== 1 || !is_int($record['revision'] ?? null)
                || !is_bool($record['configured'] ?? null) || !array_key_exists('items', $record)) {
                throw new InvalidArgumentException('Invalid saved menu.');
            }
            $revision = self::revision($record['revision']);
            $items = self::validateItems($record['items']);
            if (!$record['configured'] && $items !== []) throw new InvalidArgumentException('Invalid default menu state.');
        } catch (InvalidArgumentException) {
            throw new RuntimeException('The saved menu needs attention. Restore a verified copy before changing it.');
        }
        return ['revision' => $revision, 'configured' => $record['configured'],
            'items' => $record['configured'] ? $items : self::defaults($location, $context)];
    }

    public static function itemCount(array $items): int
    {
        $count = 0;
        foreach ($items as $item) $count += 1 + self::itemCount($item['children']);
        return $count;
    }
}

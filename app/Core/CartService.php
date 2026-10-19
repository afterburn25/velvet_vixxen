<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class CartService
{
    private const SESSION_KEY = 'velvet_cart';

    /** @return list<array{key:string,type:string,id:string,mode:string,quantity:int}> */
    public function items(): array
    {
        $rows = $_SESSION[self::SESSION_KEY] ?? [];
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    public function count(): int
    {
        return count($this->items());
    }

    public function add(string $type, string|int $id, string $mode = 'purchase'): array
    {
        $type = strtolower(trim($type));
        $mode = strtolower(trim($mode));
        $allowedTypes = ['plan', 'gallery', 'video', 'bundle'];
        if (!in_array($type, $allowedTypes, true)) {
            throw new RuntimeException('This item cannot be added to the cart.');
        }
        if (!in_array($mode, ['subscribe', 'purchase', 'rental'], true)) {
            $mode = $type === 'plan' ? 'subscribe' : 'purchase';
        }
        if ($type === 'plan') {
            $mode = 'subscribe';
        }
        $id = trim((string) $id);
        if ($id === '') {
            throw new RuntimeException('Choose an item before adding it to the cart.');
        }
        $key = $type . ':' . $id . ':' . $mode;
        $items = $this->items();
        if ($type === 'plan') {
            $items = array_values(array_filter($items, static fn(array $item): bool => (string) ($item['type'] ?? '') !== 'plan'));
        }
        foreach ($items as $item) {
            if ((string) ($item['key'] ?? '') === $key) {
                return $item;
            }
        }
        $record = ['key' => $key, 'type' => $type, 'id' => $id, 'mode' => $mode, 'quantity' => 1];
        $items[] = $record;
        $_SESSION[self::SESSION_KEY] = $items;
        return $record;
    }

    public function remove(string $key): void
    {
        $_SESSION[self::SESSION_KEY] = array_values(array_filter(
            $this->items(),
            static fn(array $item): bool => (string) ($item['key'] ?? '') !== $key
        ));
    }

    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}

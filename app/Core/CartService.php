<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class CartService
{
    private const SESSION_KEY = 'velvet_cart';

    /** The merchandise cart never contains membership or media entitlements. */
    public function items(): array
    {
        $rows = $_SESSION[self::SESSION_KEY] ?? [];
        return is_array($rows) ? array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row) && ($row['type'] ?? '') === 'merchandise')) : [];
    }

    public function count(): int
    {
        return array_sum(array_map(static fn(array $row): int => (int) ($row['quantity'] ?? 1), $this->items()));
    }

    public function add(string $type, string|int $id, string $mode = 'purchase', string $variantId = '', int $quantity = 1): array
    {
        if ($type !== 'merchandise' || $mode !== 'purchase') throw new RuntimeException('Memberships and PPV use their own direct checkout. Only merchandise can be added to the cart.');
        $id = trim((string) $id);
        $variantId = trim($variantId);
        if ($id === '' || $variantId === '' || strlen($id) > 100 || strlen($variantId) > 100) throw new RuntimeException('Choose a product and option before adding it.');
        if ($quantity < 1 || $quantity > 99) throw new RuntimeException('Choose a quantity from one to 99.');
        $key = 'merchandise:' . $id . ':' . $variantId;
        $items = $this->items();
        foreach ($items as &$item) {
            if ((string) $item['key'] !== $key) continue;
            if ((int) $item['quantity'] + $quantity > 99) throw new RuntimeException('A cart line cannot contain more than 99 items.');
            $item['quantity'] += $quantity;
            $_SESSION[self::SESSION_KEY] = $items;
            return $item;
        }
        unset($item);
        if (count($items) >= 50) throw new RuntimeException('The cart can contain up to fifty product options.');
        $record = ['key' => $key, 'type' => 'merchandise', 'id' => $id, 'mode' => 'purchase', 'variantId' => $variantId, 'quantity' => $quantity];
        $items[] = $record;
        $_SESSION[self::SESSION_KEY] = $items;
        return $record;
    }

    public function update(string $key, int $quantity): void
    {
        if ($quantity < 1 || $quantity > 99) throw new RuntimeException('Choose a quantity from one to 99.');
        $items = $this->items();
        foreach ($items as &$item) {
            if ((string) $item['key'] !== $key) continue;
            $item['quantity'] = $quantity;
            $_SESSION[self::SESSION_KEY] = $items;
            return;
        }
        throw new RuntimeException('Cart item not found.');
    }

    public function remove(string $key): void
    {
        $_SESSION[self::SESSION_KEY] = array_values(array_filter($this->items(), static fn(array $item): bool => (string) $item['key'] !== $key));
    }

    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}

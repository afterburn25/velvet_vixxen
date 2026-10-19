<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** A direct purchase is isolated from both the merchandise cart and other tabs. */
final class CheckoutIntent
{
    private const SESSION_KEY = 'velvet_direct_checkouts';

    public function start(string $type, string $id, string $mode): string
    {
        if (!in_array($type, ['plan', 'gallery', 'video', 'bundle'], true) || trim($id) === '' || strlen($id) > 100) throw new RuntimeException('Choose a membership or content release.');
        if ($type === 'plan') $mode = 'subscribe';
        if ($type === 'bundle') $mode = 'purchase';
        if (!in_array($mode, $type === 'plan' ? ['subscribe'] : ['purchase', 'rental'], true)) throw new RuntimeException('Invalid purchase mode.');
        $rows = (array) ($_SESSION[self::SESSION_KEY] ?? []);
        $rows = array_filter($rows, static fn(array $row): bool => (int) ($row['expiresAt'] ?? 0) > time());
        if (count($rows) >= 10) $rows = array_slice($rows, -9, null, true);
        $token = bin2hex(random_bytes(16));
        $rows[$token] = ['items' => [['type' => $type, 'id' => $id, 'mode' => $mode, 'quantity' => 1]], 'coupon' => '', 'expiresAt' => time() + 3600];
        $_SESSION[self::SESSION_KEY] = $rows;
        return $token;
    }

    public function get(string $token): array
    {
        $intent = $_SESSION[self::SESSION_KEY][$token] ?? null;
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_array($intent) || (int) $intent['expiresAt'] <= time()) throw new RuntimeException('This checkout has expired. Choose the content or membership again.');
        return $intent;
    }

    public function coupon(string $token, string $code): void
    {
        $this->get($token);
        $_SESSION[self::SESSION_KEY][$token]['coupon'] = strtoupper(trim($code));
    }

    public function finish(string $token): void
    {
        unset($_SESSION[self::SESSION_KEY][$token]);
    }
}

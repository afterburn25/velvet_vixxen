<?php
declare(strict_types=1);

namespace App\Core;

use App\Payments\PaymentContract;
use RuntimeException;

/** Versioned commercial facts only. Current publication, protection and member policy still apply. */
final class PurchasedTerms
{
    public static function capture(array $row, array $catalogue, array $quote): array
    {
        $terms = ['schema' => 1, 'kind' => $row['itemType'], 'itemId' => (string) $row['itemId'],
            'title' => (string) $row['title'], 'currency' => (string) $row['currency'],
            'unitMinor' => PaymentContract::minor($row['unitPrice']),
            'discountMinor' => PaymentContract::minor($row['discountTotal']),
            'netMinor' => PaymentContract::minor($row['lineTotal'])];
        if ($terms['kind'] === 'plan') {
            $terms += ['planKey' => (string) $catalogue['key'], 'interval' => $catalogue['billingInterval'],
                'intervalCount' => (int) $catalogue['intervalCount'], 'trialDays' => (int) $catalogue['trialDays'],
                'initialTotalMinor' => PaymentContract::minor($quote['grandTotal']),
                'recurringMinor' => PaymentContract::minor($catalogue['price']),
                'discountPolicy' => 'initial_only', 'taxPolicy' => 'sandbox_initial_only',
                'trialPolicy' => 'initial_charge_then_trial',
                'ppvDiscountBasisPoints' => (int) round((float) $catalogue['ppvDiscountPercent'] * 100),
                'benefits' => array_values($catalogue['benefits'])];
        } elseif ($terms['kind'] === 'bundle') {
            $items = []; $seen = [];
            $catalogueItems = $catalogue['items'];
            usort($catalogueItems, static fn(array $a, array $b): int => (int) ($a['sortOrder'] ?? 100) <=> (int) ($b['sortOrder'] ?? 100));
            foreach ($catalogueItems as $item) {
                $key = $item['itemType'] . ':' . $item['itemId'];
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $items[] = ['itemType' => (string) $item['itemType'], 'itemId' => (string) $item['itemId']];
            }
            $terms['items'] = $items;
        }
        $snapshot = ['terms' => $terms, 'hash' => self::hash($terms)];
        return self::validate($snapshot);
    }

    public static function validate(mixed $snapshot): array
    {
        if (!is_array($snapshot)) self::invalid();
        self::keys($snapshot, ['terms', 'hash']);
        $terms = $snapshot['terms'];
        if (!is_array($terms) || ($terms['schema'] ?? null) !== 1 || !in_array($terms['kind'] ?? null, ['plan', 'bundle'], true)) self::invalid();
        $fields = ['schema','kind','itemId','title','currency','unitMinor','discountMinor','netMinor'];
        self::keys($terms, array_merge($fields, $terms['kind'] === 'plan'
            ? ['planKey','interval','intervalCount','trialDays','initialTotalMinor','recurringMinor','discountPolicy','taxPolicy','trialPolicy','ppvDiscountBasisPoints','benefits'] : ['items']));
        self::text($terms['itemId'], 64); self::text($terms['title'], 250);
        if (!is_string($terms['currency']) || !preg_match('/\A[A-Z]{3}\z/D', $terms['currency'])) self::invalid();
        foreach (['unitMinor','discountMinor','netMinor'] as $key) self::integer($terms[$key], 0, 999999999999);
        if ($terms['unitMinor'] - $terms['discountMinor'] !== $terms['netMinor']) self::invalid();
        if ($terms['kind'] === 'plan') {
            self::text($terms['planKey'], 128);
            if (!in_array($terms['interval'], ['month','year','one_time'], true)
                || $terms['discountPolicy'] !== 'initial_only' || $terms['taxPolicy'] !== 'sandbox_initial_only'
                || $terms['trialPolicy'] !== 'initial_charge_then_trial') self::invalid();
            self::integer($terms['intervalCount'], 1, 120); self::integer($terms['trialDays'], 0, 3650);
            self::integer($terms['initialTotalMinor'], 0, 999999999999); self::integer($terms['recurringMinor'], 0, 999999999999);
            if ($terms['recurringMinor'] !== $terms['unitMinor'] || $terms['initialTotalMinor'] < $terms['netMinor']) self::invalid();
            self::integer($terms['ppvDiscountBasisPoints'], 0, 10000);
            if (!is_array($terms['benefits']) || !array_is_list($terms['benefits']) || count($terms['benefits']) > 50) self::invalid();
            foreach ($terms['benefits'] as $benefit) self::text($benefit, 500);
        } else {
            if (!is_array($terms['items']) || !array_is_list($terms['items']) || count($terms['items']) < 1 || count($terms['items']) > 500) self::invalid();
            $seen = [];
            foreach ($terms['items'] as $item) {
                if (!is_array($item)) self::invalid();
                self::keys($item, ['itemType','itemId']); self::text($item['itemId'], 64);
                if (!in_array($item['itemType'], ['gallery','video'], true)) self::invalid();
                $key = $item['itemType'] . ':' . $item['itemId'];
                if (isset($seen[$key])) self::invalid();
                $seen[$key] = true;
            }
        }
        if (!is_string($snapshot['hash']) || !preg_match('/\A[a-f0-9]{64}\z/D', $snapshot['hash']) || !hash_equals(self::hash($terms), $snapshot['hash'])) self::invalid();
        return $snapshot;
    }

    /** Missing historical terms are distinct from missing required or corrupt new terms. */
    public static function forItem(array $order, array $item): ?array
    {
        $metadata = (array) ($item['metadata'] ?? []);
        if (!array_key_exists('purchaseTerms', $metadata)) {
            if (array_key_exists('purchaseTermsVersion', (array) ($order['metadata'] ?? []))) self::invalid();
            return null;
        }
        $snapshot = self::validate($metadata['purchaseTerms']); $terms = $snapshot['terms'];
        if (($order['metadata']['purchaseTermsVersion'] ?? null) !== 1 || $terms['kind'] !== $item['itemType']
            || $terms['itemId'] !== (string) $item['itemId'] || $terms['currency'] !== $order['currency']
            || (int) $item['quantity'] !== 1 || $terms['title'] !== $item['title']
            || $terms['unitMinor'] !== PaymentContract::minor($item['unitPrice'])
            || $terms['discountMinor'] !== PaymentContract::minor($item['discountTotal'])
            || $terms['netMinor'] !== PaymentContract::minor($item['lineTotal'])
            || ($terms['kind'] === 'plan' && $terms['initialTotalMinor'] !== PaymentContract::minor($order['grandTotal']))) self::invalid();
        return $snapshot;
    }

    public static function protectMetadata(array $existing, array $changes): void
    {
        foreach (['purchaseTerms','subscriptionId'] as $key) {
            if (!array_key_exists($key, $changes)) continue;
            if ($key === 'subscriptionId' && !array_key_exists($key, $existing)) continue;
            if (!array_key_exists($key, $existing) || $changes[$key] !== $existing[$key]) {
                throw new RuntimeException('Purchased terms and their subscription binding cannot be replaced.');
            }
        }
    }

    public static function quoteRevision(array $quote): string
    {
        return self::hash(['items' => array_map(static fn(array $row): array => [
            'kind' => $row['itemType'], 'id' => $row['itemId'], 'quantity' => $row['quantity'],
            'unitMinor' => PaymentContract::minor($row['unitPrice']), 'netMinor' => PaymentContract::minor($row['lineTotal']),
            'snapshot' => $row['metadata']['purchaseTerms'] ?? null,
        ], $quote['items']), 'currency' => $quote['currency'], 'totalMinor' => PaymentContract::minor($quote['grandTotal']),
            'coupon' => $quote['couponCode'], 'shippingMinor' => PaymentContract::minor($quote['shippingTotal'])]);
    }

    public static function hash(array $terms): string
    {
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            return array_map($canonical, $value);
        };
        return hash('sha256', json_encode($canonical($terms), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function keys(array $value, array $expected): void
    {
        $keys = array_keys($value); sort($keys); sort($expected);
        if ($keys !== $expected) self::invalid();
    }
    private static function text(mixed $value, int $max): void
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || trim($value) === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) self::invalid();
    }
    private static function integer(mixed $value, int $min, int $max): void
    {
        if (!is_int($value) || $value < $min || $value > $max) self::invalid();
    }
    private static function invalid(): never
    {
        throw new RuntimeException('This purchase cannot be completed until its saved terms are reviewed. Please contact support.');
    }
}

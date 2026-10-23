<?php
declare(strict_types=1);

namespace Velvet\CCBill;

use InvalidArgumentException;
use SensitiveParameter;

/** Pure protocol preparation. This is not a registered payment provider. */
final class FlexForms
{
    private const CURRENCIES = ['USD'=>'840', 'EUR'=>'978', 'GBP'=>'826', 'CAD'=>'124', 'AUD'=>'036'];

    /**
     * Explicit day-based prices only. Never convert calendar months/years to days.
     * The returned request is not payment evidence and must not grant access.
     */
    public static function request(string $flexId, string $subaccount, array $price, #[SensitiveParameter] string $salt): array
    {
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $flexId)
            || !preg_match('/\A[0-9]{4}\z/D', $subaccount)) {
            throw new InvalidArgumentException('A canonical Flex ID and four-digit subaccount are required.');
        }
        self::salt($salt);
        $single = ['initialMinor', 'initialDays', 'currency'];
        $recurring = ['recurringMinor', 'recurringDays', 'rebills'];
        $keys = array_keys($price); sort($keys);
        $expected = array_key_exists('recurringMinor', $price) ? array_merge($single, $recurring) : $single;
        sort($expected);
        if ($keys !== $expected || !is_string($price['currency']) || !isset(self::CURRENCIES[$price['currency']])) {
            throw new InvalidArgumentException('Unsupported or incomplete CCBill pricing fields.');
        }
        $parameters = ['clientSubacc'=>$subaccount, 'initialPrice'=>self::money($price['initialMinor']),
            'initialPeriod'=>(string)self::integer($price['initialDays'], 2, 365), 'currencyCode'=>self::CURRENCIES[$price['currency']]];
        $digest = $parameters['initialPrice'].$parameters['initialPeriod'];
        if (array_key_exists('recurringMinor', $price)) {
            $days = self::integer($price['recurringDays'], 30, 90);
            if (!in_array($days, [30, 60, 90], true)) throw new InvalidArgumentException('Unsupported default recurring period.');
            $parameters['recurringPrice'] = self::money($price['recurringMinor']);
            $parameters['recurringPeriod'] = (string)$days;
            $parameters['numRebills'] = (string)self::integer($price['rebills'], 1, 99);
            $digest .= $parameters['recurringPrice'].$parameters['recurringPeriod'].$parameters['numRebills'];
        }
        $parameters['formDigest'] = md5($digest.$parameters['currencyCode'].$salt);
        return ['method'=>'POST', 'action'=>'https://api.ccbill.com/wap-frontflex/flexforms/'.$flexId, 'parameters'=>$parameters];
    }

    /** Exact conversion only; the caller must separately disclose the accepted day-based price. */
    public static function exactDays(string $interval, int $count): int
    {
        self::integer($count, 1, 365);
        $days = match ($interval) {
            'day' => $count,
            'week' => $count * 7,
            default => throw new InvalidArgumentException('Calendar or indefinite periods cannot be converted to a CCBill day price.'),
        };
        return self::integer($days, 2, 365);
    }

    /**
     * Checks only the documented ID/outcome digest. This DOES NOT authenticate
     * amount, currency, account, transaction, event, time, or custom variables.
     * Never use this boolean alone to settle an order or accept a webhook.
     */
    public static function matchesSaleResponseDigest(string $subscriptionId, bool $approved, string $digest, #[SensitiveParameter] string $salt): bool
    {
        self::salt($salt);
        if (!preg_match('/\A[0-9]{1,20}\z/D', $subscriptionId) || !preg_match('/\A[0-9a-f]{32}\z/D', $digest)) return false;
        return hash_equals(md5($subscriptionId.($approved ? '1' : '0').$salt), $digest);
    }

    /** Direct peer only. Forwarded headers and this range test alone are not authenticated evidence. */
    public static function isDocumentedWebhookPeer(string $peer): bool
    {
        if (filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
        $octets = array_map('intval', explode('.', $peer));
        return $octets[0] === 64 && $octets[1] === 38 && in_array($octets[2], [212, 215, 240, 241], true)
            && $octets[3] >= 1 && $octets[3] <= 254;
    }

    private static function salt(#[SensitiveParameter] string $salt): void
    {
        if ($salt === '' || strlen($salt) > 1024 || preg_match('/[\x00-\x20\x7f]/', $salt)) {
            throw new InvalidArgumentException('The configured CCBill salt is unavailable or malformed.');
        }
    }

    private static function integer(mixed $value, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) throw new InvalidArgumentException('CCBill integer is outside the supported range.');
        return $value;
    }

    private static function money(mixed $minor): string
    {
        self::integer($minor, 1, 9999999);
        return intdiv($minor, 100).'.'.str_pad((string)($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}

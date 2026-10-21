<?php
declare(strict_types=1);

namespace Velvet\Core;

use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/MarketingPrivacy.php';
require_once __DIR__ . '/SceneButton.php';

/** Strict private attribution records; no email address or member identifier belongs in a public link. */
final class MarketingAttribution
{
    public const KEY = 'attribution_v1';
    public const LIFETIME = 2592000;
    public const MAX_LINKS = 20000;
    public const MAX_VISITS = 20000;

    public static function state(array $data): array
    {
        $state = $data[self::KEY] ?? ['version' => 1, 'links' => [], 'visits' => [], 'coupons' => [], 'conversions' => [], 'erased_actors' => []];
        if (!is_array($state) || ($state['version'] ?? null) !== 1
            || array_diff(array_keys($state), ['version', 'links', 'visits', 'coupons', 'conversions', 'erased_actors']) !== []) {
            throw new RuntimeException('Saved attribution needs review. Existing records were preserved.');
        }
        foreach (['links', 'visits', 'coupons', 'conversions', 'erased_actors'] as $key) {
            if (!is_array($state[$key] ?? null)) throw new RuntimeException('Saved attribution needs review. Existing records were preserved.');
        }
        if (count($state['links']) > self::MAX_LINKS || count($state['visits']) > self::MAX_VISITS || count($state['coupons']) > 1000 || count($state['conversions']) > 100000 || count($state['erased_actors']) > 100000) {
            throw new RuntimeException('Saved attribution exceeds its supported limits. Existing records were preserved.');
        }
        foreach ($state['erased_actors'] as $actor => $time) {
            if (!is_string($actor) || !preg_match('/\A[a-f0-9]{64}\z/D', $actor) || !is_int($time) || $time < 1) throw new RuntimeException('Saved attribution deletion markers need review.');
        }
        foreach ($state['coupons'] as $code => $assignment) {
            if (!is_string($code) || !preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,63}\z/D', $code)
                || !is_array($assignment) || array_keys($assignment) !== ['campaign_id', 'enabled']
                || !is_string($assignment['campaign_id']) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $assignment['campaign_id'])
                || !is_bool($assignment['enabled'])) throw new RuntimeException('Saved coupon attribution needs review. Existing records were preserved.');
        }
        return $state;
    }

    public static function code(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $value)) {
            throw new InvalidArgumentException('Use a referral code of 1–64 letters, numbers, hyphens or underscores.');
        }
        return strtolower($value);
    }

    public static function token(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[a-f0-9]{64}\z/D', $value)) throw new InvalidArgumentException('This attribution link is invalid.');
        return $value;
    }

    public static function referral(array $data, mixed $code): ?array
    {
        try { $code = self::code($code); } catch (InvalidArgumentException) { return null; }
        $match = null;
        foreach ($data['referral_codes'] ?? [] as $record) {
            if (!is_array($record) || !is_string($record['code'] ?? null)) continue;
            try { $saved = self::code($record['code']); } catch (InvalidArgumentException) { continue; }
            if ($saved !== $code) continue;
            // Ambiguous definitions fail closed, including enabled/disabled duplicates.
            if ($match !== null) return null;
            $match = $record;
        }
        if ($match === null || ($match['enabled'] ?? false) !== true
            || !is_string($match['id'] ?? null) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $match['id'])) return null;
        return ['id' => $match['id'], 'code' => $code];
    }

    public static function destination(mixed $value, string $baseUrl): string
    {
        if (!is_string($value) || strlen($value) > 2048 || $value === ''
            || preg_match('/[\x00-\x20\x7f\\\\]/', $value) || preg_match('/[\x00-\x1f\x7f\\\\]/', rawurldecode($value))
            || str_starts_with($value, '//') || !\App\Core\SceneButton::validUrl($value)) {
            throw new InvalidArgumentException('Choose a safe HTTP or HTTPS destination.');
        }
        $parts = parse_url($value);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) throw new InvalidArgumentException('Choose a safe destination without embedded credentials.');
        if (isset($parts['scheme'])) {
            if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true) || empty($parts['host'])) throw new InvalidArgumentException('Choose an HTTP or HTTPS destination.');
            return $value;
        }
        if (!str_starts_with($value, '/') || str_starts_with($value, '/\\')) throw new InvalidArgumentException('Choose a full URL or a site path beginning with /.');
        return \vvx_public_destination($baseUrl, $value);
    }

    /** Only server-issued snapshots may be stored with member/order business records. */
    public static function snapshot(mixed $value): ?array
    {
        if ($value === null || $value === []) return null;
        $keys = ['version','actor','referral_id','referral_code','campaign_id','delivery_id','captured_at','expires_at'];
        if (!is_array($value) || ($value['version'] ?? null) !== 1 || array_diff($keys, array_keys($value)) !== []
            || array_diff(array_keys($value), ['version','actor','referral_id','referral_code','campaign_id','delivery_id','captured_at','expires_at']) !== []
            || !is_string($value['actor'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $value['actor'])
            || !is_int($value['captured_at'] ?? null) || !is_int($value['expires_at'] ?? null)
            || $value['captured_at'] < 1 || $value['expires_at'] <= $value['captured_at']
            || $value['expires_at'] - $value['captured_at'] > self::LIFETIME) throw new InvalidArgumentException('Saved attribution needs review.');
        foreach (['referral_id','campaign_id','delivery_id'] as $key) {
            if (($value[$key] ?? null) !== null && (!is_string($value[$key]) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $value[$key]))) {
                throw new InvalidArgumentException('Saved attribution needs review.');
            }
        }
        if (($value['referral_code'] ?? null) !== null && self::code($value['referral_code']) !== $value['referral_code']) throw new InvalidArgumentException('Saved attribution needs review.');
        if (($value['referral_id'] === null) !== ($value['referral_code'] === null)
            || ($value['delivery_id'] !== null && $value['campaign_id'] === null)) throw new InvalidArgumentException('Saved attribution needs review.');
        return $value;
    }
}

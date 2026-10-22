<?php
declare(strict_types=1);

namespace Velvet\Core;

use App\Core\Config;

require_once __DIR__ . '/Config.php';

/** A browser's voluntary measurement choice is separate from newsletter consent. */
final class MarketingPrivacy
{
    public const COOKIE = 'velvet_measurement';
    public const VISITOR_COOKIE = 'velvet_attribution';
    public const CHOICE_SECONDS = 15552000;

    public static function choice(?string $root = null, ?string $cookie = null, ?int $now = null): ?bool
    {
        $cookie ??= is_string($_COOKIE[self::COOKIE] ?? null) ? $_COOKIE[self::COOKIE] : '';
        if (!preg_match('/\A(allow|deny)\.([0-9]{10})\.([a-f0-9]{64})\z/D', $cookie, $parts)) return null;
        $now ??= time();
        $expires = (int) $parts[2];
        if ($expires <= $now || $expires > $now + self::CHOICE_SECONDS + 60) return null;
        try { $expected = hash_hmac('sha256', $parts[1] . '.' . $parts[2], self::key($root)); }
        catch (\Throwable) { return null; }
        return hash_equals($expected, $parts[3]) ? $parts[1] === 'allow' : null;
    }

    public static function cookie(bool $allow, ?string $root = null, ?int $now = null): string
    {
        $payload = ($allow ? 'allow' : 'deny') . '.' . (($now ?? time()) + self::CHOICE_SECONDS);
        return $payload . '.' . hash_hmac('sha256', $payload, self::key($root));
    }

    public static function visitor(): string
    {
        $value = $_COOKIE[self::VISITOR_COOKIE] ?? '';
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) ? $value : '';
    }

    public static function cookiePath(string $baseUrl): string
    {
        $path = parse_url($baseUrl, PHP_URL_PATH);
        return '/' . (is_string($path) && trim($path, '/') !== '' ? trim($path, '/') . '/' : '');
    }

    private static function key(?string $root): string
    {
        $config = new Config(rtrim($root ?: dirname(__DIR__, 2), '/') . '/config/app.php');
        $key = $config->get('security.app_key', '');
        if (!is_string($key) || strlen($key) < 16) throw new \RuntimeException('Measurement choices need the installation security key.');
        return hash_hmac('sha256', 'velvet-measurement-choice-v1|' . (string) $config->get('base_url', ''), $key, true);
    }
}

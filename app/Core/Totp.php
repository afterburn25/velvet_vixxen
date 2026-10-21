<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/** RFC 4226 / RFC 6238: six digits, SHA-1, 30-second step. */
final class Totp
{
    public static function secret(): string { return self::encode(random_bytes(20)); }

    public static function encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $buffer = 0; $bits = 0; $out = '';
        foreach (unpack('C*', $bytes) ?: [] as $byte) {
            $buffer = ($buffer << 8) | $byte; $bits += 8;
            while ($bits >= 5) { $bits -= 5; $out .= $alphabet[($buffer >> $bits) & 31]; }
            $buffer &= (1 << $bits) - 1;
        }
        if ($bits) $out .= $alphabet[($buffer << (5 - $bits)) & 31];
        return $out;
    }

    private static function decode(string $secret): string
    {
        if (!preg_match('/\A[A-Z2-7]{16,128}\z/D', $secret)) throw new InvalidArgumentException('Invalid authenticator secret.');
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $buffer = 0; $bits = 0; $out = '';
        foreach (str_split($secret) as $character) {
            $buffer = ($buffer << 5) | strpos($alphabet, $character); $bits += 5;
            if ($bits >= 8) { $bits -= 8; $out .= chr(($buffer >> $bits) & 255); }
            $buffer &= (1 << $bits) - 1;
        }
        if ($buffer !== 0) throw new InvalidArgumentException('Invalid authenticator padding.');
        return $out;
    }

    public static function code(string $secret, int $counter, int $digits = 6, string $algorithm = 'sha1'): string
    {
        if ($counter < 0 || !in_array($digits, [6, 8], true) || !in_array($algorithm, ['sha1', 'sha256', 'sha512'], true)) throw new InvalidArgumentException('Invalid authenticator parameters.');
        $hash = hash_hmac($algorithm, pack('N2', intdiv($counter, 4294967296), $counter & 0xffffffff), self::decode($secret), true);
        $offset = ord($hash[strlen($hash) - 1]) & 15;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function match(string $secret, string $code, int $now, int $lastCounter = -1): ?int
    {
        if (!preg_match('/\A[0-9]{6}\z/D', $code)) return null;
        $current = intdiv($now, 30);
        foreach ([$current, $current - 1, $current + 1] as $counter) {
            if ($counter > $lastCounter && $counter >= 0 && hash_equals(self::code($secret, $counter), $code)) return $counter;
        }
        return null;
    }
}

<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Canonical JSON and Ed25519 signing for license/update envelopes. */
final class SignedPayload
{
    /** @param array<string,mixed> $payload */
    public static function canonicalJson(array $payload): string
    {
        $normalized = self::normalize($payload);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        return $json;
    }

    /** @param array<string,mixed> $payload */
    public static function sign(array $payload, string $privateKeyBase64): string
    {
        self::requireSodium();
        $private = base64_decode($privateKeyBase64, true);
        if (!is_string($private) || strlen($private) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The licensing private signing key is invalid.');
        }
        return base64_encode(sodium_crypto_sign_detached(self::canonicalJson($payload), $private));
    }

    /** @param array<string,mixed> $payload */
    public static function verify(array $payload, string $signatureBase64, string $publicKeyBase64): bool
    {
        self::requireSodium();
        $signature = base64_decode($signatureBase64, true);
        $public = base64_decode($publicKeyBase64, true);
        if (!is_string($signature) || !is_string($public)
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($signature, self::canonicalJson($payload), $public);
    }

    /** @return array{private:string,public:string,fingerprint:string} */
    public static function generateKeyPair(): array
    {
        self::requireSodium();
        $pair = sodium_crypto_sign_keypair();
        $private = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        return [
            'private' => base64_encode($private),
            'public' => base64_encode($public),
            'fingerprint' => self::fingerprint(base64_encode($public)),
        ];
    }

    public static function fingerprint(string $publicKeyBase64): string
    {
        $raw = base64_decode($publicKeyBase64, true);
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        return implode(':', str_split(strtoupper(substr(hash('sha256', $raw), 0, 32)), 4));
    }

    private static function requireSodium(): void
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException('The PHP Sodium extension is required for signed licensing and update responses.');
        }
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'normalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }
        return $value;
    }
}

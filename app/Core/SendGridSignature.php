<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;

/** Twilio SendGrid Signed Event Webhook: SHA-256 over timestamp + untouched body bytes. */
final class SendGridSignature
{
    public const MAX_BODY_BYTES = 1048576;
    public const MAX_BATCH_EVENTS = 1000;
    public const MAX_CLOCK_SKEW = 300;
    // SendGrid retries events for up to 24 hours. Durable event IDs provide
    // replay protection throughout that retry interval.
    public const MAX_SIGNATURE_AGE = 86700;

    /** The provider supplies a Base64 DER public verification key, never a private/API key. */
    public static function publicKey(string $input): string
    {
        if (!extension_loaded('openssl')) throw new RuntimeException('OpenSSL is required to verify provider event signatures.');
        if (strlen($input) > 2048 || str_contains($input, 'PRIVATE') || str_contains($input, '-----')) throw new InvalidArgumentException('Paste the Base64 public verification key from the SendGrid Signed Event Webhook settings.');
        $encoded = preg_replace('/[ \t\r\n]+/', '', $input);
        if (!is_string($encoded) || preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/D', $encoded) !== 1) throw new InvalidArgumentException('A Base64 public verification key is required.');
        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded) || strlen($decoded) < 80 || strlen($decoded) > 160 || base64_encode($decoded) !== $encoded) throw new InvalidArgumentException('The public verification key is malformed.');
        $key = self::opensslKey($encoded);
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['bits'] ?? null) !== 256
            || ($details['ec']['curve_name'] ?? '') !== 'prime256v1') throw new InvalidArgumentException('A SendGrid ECDSA P-256 public verification key is required.');
        // Reject appended DER data that OpenSSL might otherwise silently ignore.
        $canonical = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', (string) $details['key']);
        if ($canonical !== $encoded) throw new InvalidArgumentException('The public verification key contains unsupported data.');
        return $encoded;
    }

    /** Cryptographic primitive. The HTTP service separately enforces the live replay window. */
    public static function verify(string $publicKey, string $payload, string $signature, string $timestamp): bool
    {
        if (strlen($payload) > self::MAX_BODY_BYTES || preg_match('/\A[0-9]{10,12}\z/D', $timestamp) !== 1
            || strlen($signature) > 128 || preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/D', $signature) !== 1) return false;
        $decoded = base64_decode($signature, true);
        if (!is_string($decoded) || strlen($decoded) < 64 || strlen($decoded) > 80 || base64_encode($decoded) !== $signature) return false;
        try {
            $canonical = self::publicKey($publicKey);
            return openssl_verify($timestamp . $payload, $decoded, self::opensslKey($canonical), OPENSSL_ALGO_SHA256) === 1;
        } catch (\Throwable) { return false; }
    }

    private static function opensslKey(string $encoded): OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($encoded, 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        if (!$key instanceof OpenSSLAsymmetricKey) throw new InvalidArgumentException('The public verification key could not be decoded.');
        return $key;
    }
}

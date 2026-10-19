<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Encrypts small secrets for local persistence using the installation app key.
 * The ciphertext is portable only with the same installation key and is never
 * intended to replace a dedicated secrets manager for a hosted licensing hub.
 */
final class SecureValue
{
    private string $key;

    public function __construct(private readonly Config $config)
    {
        $material = trim((string) $config->get('security.app_key', ''));
        if ($material === '') {
            throw new RuntimeException('The application encryption key is unavailable.');
        }
        $this->key = hash('sha256', $material, true);
    }

    public function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $this->key);
            return 'vvx1:sodium:' . base64_encode($nonce . $cipher);
        }
        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, 'velvet-vixxen-license');
            if (!is_string($cipher) || strlen($tag) !== 16) {
                throw new RuntimeException('The local secret could not be encrypted.');
            }
            return 'vvx1:aesgcm:' . base64_encode($iv . $tag . $cipher);
        }
        throw new RuntimeException('Neither Sodium nor OpenSSL is available for local secret encryption.');
    }

    public function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }
        $parts = explode(':', $encoded, 3);
        if (count($parts) !== 3 || $parts[0] !== 'vvx1') {
            throw new RuntimeException('The stored secret has an unsupported format.');
        }
        $payload = base64_decode($parts[2], true);
        if (!is_string($payload)) {
            throw new RuntimeException('The stored secret is not valid base64.');
        }
        if ($parts[1] === 'sodium') {
            if (!function_exists('sodium_crypto_secretbox_open') || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                throw new RuntimeException('The stored Sodium secret cannot be decrypted on this server.');
            }
            $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
            if (!is_string($plain)) {
                throw new RuntimeException('The stored secret could not be decrypted.');
            }
            return $plain;
        }
        if ($parts[1] === 'aesgcm') {
            if (!function_exists('openssl_decrypt') || strlen($payload) <= 28) {
                throw new RuntimeException('The stored OpenSSL secret cannot be decrypted on this server.');
            }
            $iv = substr($payload, 0, 12);
            $tag = substr($payload, 12, 16);
            $cipher = substr($payload, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, 'velvet-vixxen-license');
            if (!is_string($plain)) {
                throw new RuntimeException('The stored secret could not be decrypted.');
            }
            return $plain;
        }
        throw new RuntimeException('The stored secret uses an unknown encryption method.');
    }
}

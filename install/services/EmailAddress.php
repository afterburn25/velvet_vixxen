<?php
declare(strict_types=1);

namespace Velvet\Installer;

use RuntimeException;

/**
 * Normalizes email input entered or pasted into the browser installer.
 *
 * Some mobile keyboards, password managers, rich-text clipboards, and web
 * panels insert non-breaking spaces, zero-width characters, a mailto: prefix,
 * or full-width punctuation. Those characters are invisible to the user but
 * cause PHP's FILTER_VALIDATE_EMAIL check to fail. This helper removes only
 * formatting artifacts that cannot be part of an ordinary mailbox address.
 */
final class EmailAddress
{
    public static function normalize(mixed $value): string
    {
        // Do this before trim, display-name extraction or whitespace cleanup:
        // removing a line break would turn a rejected header into a valid one.
        if ((!is_string($value) && $value !== null) || self::hasForbiddenControls($value)) {
            return '';
        }
        $email = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $email = trim($email);

        // Accept a pasted display-name form such as: Nikki <name@example.com>.
        if (preg_match('/<([^<>]+)>/u', $email, $match) === 1) {
            $email = (string) $match[1];
        }

        $email = preg_replace('/^mailto\s*:/i', '', $email) ?? $email;
        $email = strtr($email, [
            '＠' => '@',
            '﹫' => '@',
            '。' => '.',
            '．' => '.',
            '｡' => '.',
        ]);

        // Preserve benign pasted spacing/formatting; controls were rejected.
        $normalized = preg_replace('/[\p{Z}\p{Cf}]+/u', '', $email);
        if (is_string($normalized)) {
            $email = $normalized;
        } else {
            return '';
        }

        $email = function_exists('mb_strtolower')
            ? mb_strtolower($email, 'UTF-8')
            : strtolower($email);

        // Convert an internationalized domain when ext-intl is available.
        $separator = strrpos($email, '@');
        if ($separator !== false && function_exists('idn_to_ascii')) {
            $local = substr($email, 0, $separator);
            $domain = substr($email, $separator + 1);
            if ($domain !== '') {
                $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
                $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
                $ascii = @idn_to_ascii($domain, $flags, $variant);
                if (is_string($ascii) && $ascii !== '') {
                    $email = $local . '@' . strtolower($ascii);
                }
            }
        }

        return $email;
    }

    public static function isValid(mixed $value): bool
    {
        if (self::hasForbiddenControls($value)) {
            return false;
        }
        $email = self::normalize($value);
        return $email !== ''
            && strlen($email) <= 254
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function hasForbiddenControls(mixed $value): bool
    {
        if (!is_string($value) && $value !== null) {
            return true;
        }
        $raw = (string) $value;
        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach ([$raw, $decoded] as $text) {
            // PHP intentionally leaves some numeric control references (such
            // as &#13;) undecoded. Reject them explicitly as well.
            if (preg_match_all('/&#(?:x([0-9a-f]+)|([0-9]+));?/i', $text, $references, PREG_SET_ORDER)) {
                foreach ($references as $reference) {
                    $point = $reference[1] !== '' ? hexdec($reference[1]) : (int) ($reference[2] ?? 0);
                    if ($point < 32 || ($point >= 127 && $point <= 159) || $point === 8232 || $point === 8233) {
                        return true;
                    }
                }
            }
            // A malformed UTF-8 sequence also fails closed instead of using an
            // ASCII fallback that could erase bytes before validation.
            if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $text) !== 0) {
                return true;
            }
        }
        return false;
    }

    public static function isBlank(mixed $value): bool
    {
        if (self::hasForbiddenControls($value)) {
            return false;
        }
        $decoded = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_replace('/[\p{Z}\p{Cf}]+/u', '', $decoded) === '';
    }

    /** Empty From uses an SMTP mailbox first, then the verified owner mailbox. */
    public static function resolveSender(mixed $from, mixed $smtpUsername, string $ownerEmail): string
    {
        if (!self::isBlank($from)) {
            if (!self::isValid($from)) {
                throw new RuntimeException('Enter a valid From email address without line breaks or control characters.');
            }
            return self::normalize($from);
        }
        if (self::isValid($smtpUsername)) {
            return self::normalize($smtpUsername);
        }
        if (!self::isValid($ownerEmail)) {
            throw new RuntimeException('Enter a valid From email address or owner email address.');
        }
        return self::normalize($ownerEmail);
    }
}

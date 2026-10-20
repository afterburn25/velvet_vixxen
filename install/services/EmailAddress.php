<?php
declare(strict_types=1);

namespace Velvet\Installer;

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

        // Remove invisible formatting, Unicode spacing, and control characters.
        $normalized = preg_replace('/[\p{Z}\p{Cf}\x{0000}-\x{001F}\x{007F}]+/u', '', $email);
        if (is_string($normalized)) {
            $email = $normalized;
        } else {
            // Safe ASCII fallback for malformed UTF-8 input.
            $email = preg_replace('/[\x00-\x20\x7F]+/', '', $email) ?? $email;
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
        $email = self::normalize($value);
        return $email !== ''
            && strlen($email) <= 254
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

<?php
declare(strict_types=1);

namespace App\Core;

/** Mailbox input shared by installed-site mail settings and transport validation. */
final class EmailAddress
{
    /** Reject controls before any clipboard normalization can erase them. */
    public static function hasForbiddenControls(mixed $value): bool
    {
        if ($value === null) return false;
        if (!is_string($value) && !is_int($value) && !is_float($value)) return true;
        $raw = (string) $value;
        foreach ([$raw, html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')] as $candidate) {
            if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $candidate) !== 0) return true;
            // HTML5 deliberately leaves some numeric C0 references undecoded.
            // They must fail here instead of depending on decoder behavior.
            if (preg_match_all('/&#(?:x([0-9a-f]+)|([0-9]+));?/i', $candidate, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $point = ($match[1] ?? '') !== '' ? hexdec($match[1]) : (int) ($match[2] ?? -1);
                    if ($point < 32 || ($point >= 127 && $point <= 159) || $point === 8232 || $point === 8233) return true;
                }
            }
        }
        return false;
    }

    public static function normalize(mixed $value): string
    {
        if (self::hasForbiddenControls($value)) return '';
        $email = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('/<([^<>]+)>/u', $email, $match) === 1) $email = $match[1];
        $email = preg_replace('/^mailto\s*:/i', '', $email) ?? $email;
        $email = strtr($email, ['＠' => '@', '﹫' => '@', '。' => '.', '．' => '.', '｡' => '.']);
        // Spaces and zero-width formatting can be harmless paste artifacts;
        // line separators and control bytes have already failed closed above.
        $email = preg_replace('/[\p{Zs}\p{Cf}]+/u', '', $email) ?? '';
        $email = function_exists('mb_strtolower') ? mb_strtolower($email, 'UTF-8') : strtolower($email);
        $separator = strrpos($email, '@');
        if ($separator !== false && function_exists('idn_to_ascii')) {
            $domain = substr($email, $separator + 1);
            $ascii = @idn_to_ascii($domain, defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0,
                defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0);
            if (is_string($ascii) && $ascii !== '') $email = substr($email, 0, $separator + 1) . strtolower($ascii);
        }
        return $email;
    }

    /** Empty formatting alone is blank; invalid mailto/display-name text is not. */
    public static function isBlank(mixed $value): bool
    {
        if (self::hasForbiddenControls($value)) return false;
        $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_replace('/[\p{Zs}\p{Cf}]+/u', '', $text) === '';
    }

    public static function isValid(mixed $value): bool
    {
        if (self::hasForbiddenControls($value)) return false;
        $email = self::normalize($value);
        return $email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

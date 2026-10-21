<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/** Country headers are evidence only from an explicitly trusted immediate peer. */
final class GeographicPolicy
{
    public const HEADERS = ['HTTP_CF_IPCOUNTRY' => 'CF-IPCountry', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY' => 'CloudFront-Viewer-Country', 'HTTP_X_COUNTRY_CODE' => 'X-Country-Code'];
    private const COUNTRIES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW';

    public static function validCountry(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Z]{2}\z/D', $value) === 1
            && in_array($value, explode(' ', self::COUNTRIES), true);
    }

    public static function countries(mixed $input): array
    {
        if (!is_string($input) || strlen($input) > 1500) throw new InvalidArgumentException('Enter country codes separated by spaces or commas.');
        $values = preg_split('/[\s,]+/', strtoupper(trim($input)), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($values as $value) if (!self::validCountry($value)) throw new InvalidArgumentException('Use recognised two-letter country codes. Unknown locations are always blocked when location rules are enabled.');
        $values = array_values(array_unique($values)); sort($values, SORT_STRING); return $values;
    }

    public static function networks(mixed $input): array
    {
        if (!is_string($input) || strlen($input) > 2500) throw new InvalidArgumentException('Enter up to 32 immediate proxy addresses or CIDR networks.');
        $values = preg_split('/[\s,]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY);
        if (count($values) > 32) throw new InvalidArgumentException('Enter up to 32 trusted immediate proxy networks.');
        $result = [];
        foreach ($values as $value) {
            [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);
            $packed = @inet_pton($address);
            if ($packed === false || ($prefix !== null && preg_match('/\A(?:[1-9][0-9]{0,2})\z/D', $prefix) !== 1)) throw new InvalidArgumentException('Use explicit IPv4 or IPv6 addresses or nonzero CIDR networks.');
            $bits = strlen($packed) * 8; $prefix = $prefix === null ? $bits : (int) $prefix;
            if ($prefix < 1 || $prefix > $bits) throw new InvalidArgumentException('Trust only explicit proxy networks. An all-network wildcard is not allowed.');
            $network = '';
            for ($i = 0; $i < strlen($packed); ++$i) {
                $remaining = max(0, min(8, $prefix - $i * 8));
                $network .= chr(ord($packed[$i]) & ($remaining === 0 ? 0 : (255 << (8 - $remaining)) & 255));
            }
            $result[] = inet_ntop($network) . '/' . $prefix;
        }
        $result = array_values(array_unique($result)); sort($result, SORT_STRING); return $result;
    }

    public static function contains(string $network, string $peer): bool
    {
        $parts = explode('/', $network, 2); $packed = @inet_pton($parts[0]); $actual = @inet_pton($peer);
        if ($packed === false || $actual === false || strlen($packed) !== strlen($actual) || count($parts) !== 2) return false;
        $bits = filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => strlen($packed) * 8]]);
        if ($bits === false) return false;
        for ($i = 0; $i < strlen($packed); ++$i) {
            $remaining = max(0, min(8, $bits - $i * 8)); $mask = $remaining === 0 ? 0 : (255 << (8 - $remaining)) & 255;
            if ((ord($packed[$i]) & $mask) !== (ord($actual[$i]) & $mask)) return false;
        }
        return true;
    }

    public static function allowed(array $settings, array $server): bool
    {
        if (($settings['enabled'] ?? null) === false) return true;
        if (($settings['enabled'] ?? null) !== true || !isset(self::HEADERS[$settings['header'] ?? ''])
            || !is_array($settings['trusted_proxies'] ?? null) || !is_array($settings['blocked_countries'] ?? null)) return false;
        $peer = $server['REMOTE_ADDR'] ?? null;
        if (!is_string($peer) || filter_var($peer, FILTER_VALIDATE_IP) === false) return false;
        $trusted = false;
        foreach ($settings['trusted_proxies'] as $network) if (is_string($network) && self::contains($network, $peer)) { $trusted = true; break; }
        // Never consult X-Forwarded-For, a request parameter, a cookie or language.
        $country = $server[$settings['header']] ?? null;
        return $trusted && self::validCountry($country) && !in_array($country, $settings['blocked_countries'], true);
    }
}

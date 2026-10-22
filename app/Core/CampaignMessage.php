<?php
declare(strict_types=1);

namespace Velvet\Core;

require_once __DIR__ . '/MarketingAudience.php';
require_once dirname(__DIR__) . '/Support/public_routes.php';

/** Pure, shared campaign/notification presentation. No reads, writes or remote assets. */
final class CampaignMessage
{
    public static function template(mixed $input = null): array
    {
        $default = ['schema' => 1, 'brand_name' => 'The Velvet Vixxen', 'tagline' => 'Updates from Nikki Wilder',
            'accent' => '#f52d91', 'footer' => 'Thank you for being part of our community.'];
        if ($input === null) return $default;
        if (!is_array($input) || array_diff(array_keys($input), array_keys($default)) !== []
            || ($input['schema'] ?? 1) !== 1) throw new \InvalidArgumentException('The email brand contains unsupported fields.');
        $value = array_replace($default, $input);
        foreach (['brand_name' => 120, 'tagline' => 200, 'footer' => 1000] as $key => $limit) {
            $value[$key] = MarketingAudience::text($value[$key], $limit, 'Email ' . str_replace('_', ' ', $key));
        }
        if ($value['brand_name'] === '') throw new \InvalidArgumentException('Enter an email brand name.');
        if (!is_string($value['accent']) || preg_match('/\A#[0-9a-fA-F]{6}\z/D', $value['accent']) !== 1) {
            throw new \InvalidArgumentException('Choose a six-digit email accent color.');
        }
        $value['accent'] = strtolower($value['accent']);
        return $value;
    }

    public static function revision(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function fields(array $input): array
    {
        $subject = MarketingAudience::text($input['subject'] ?? '', 255, 'Campaign subject');
        $preheader = MarketingAudience::text($input['preheader'] ?? '', 500, 'Campaign preheader');
        $body = $input['body'] ?? null;
        if ($subject === '' || !is_string($body) || trim($body) === '' || strlen($body) > 100000
            || preg_match('//u', $body) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body)) {
            throw new \InvalidArgumentException('Enter a subject and a message of up to 100,000 bytes of valid text.');
        }
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
        $format = $input['format'] ?? 'branded';
        if (!in_array($format, ['plain', 'branded'], true)) throw new \InvalidArgumentException('Choose a supported email format.');
        $label = MarketingAudience::text($input['cta_label'] ?? '', 120, 'Button label');
        $url = $input['cta_url'] ?? '';
        if (!is_string($url) || strlen($url) > 2048 || ($url !== '' && !self::validDestination($url))) {
            throw new \InvalidArgumentException('Use a local path or an HTTP(S) button destination without credentials.');
        }
        if (($label === '') !== ($url === '')) throw new \InvalidArgumentException('Enter both the button label and its destination, or leave both empty.');
        return ['subject' => $subject, 'preheader' => $preheader, 'body' => $body,
            'format' => $format, 'cta_label' => $label, 'cta_url' => $url, 'message_schema' => 1];
    }

    public static function validDestination(string $url): bool
    {
        if ($url === '' || trim($url) !== $url || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\<>"\']/', $url)
            || preg_match('/%(?:00|0a|0d|5c)/i', $url)) return false;
        if (str_starts_with($url, '/')) return !str_starts_with($url, '//') && !str_starts_with(strtolower($url), '/%2f');
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
    }

    public static function baseUrl(string $base): string
    {
        $base = rtrim($base, '/');
        if (!self::validDestination($base) || !preg_match('#\Ahttps?://#i', $base)
            || isset(parse_url($base)['query']) || isset(parse_url($base)['fragment'])) {
            throw new \InvalidArgumentException('Configure a valid public website URL before previewing or sending email.');
        }
        return $base;
    }

    public static function absoluteUrl(string $url, string $base): string
    {
        if (!self::validDestination($url)) throw new \InvalidArgumentException('An email link is invalid.');
        $base = self::baseUrl($base);
        return str_starts_with($url, '/') ? \vvx_public_destination($base, $url) : $url;
    }

    /** Legacy queued snapshots remain plain text; new messages freeze a validated brand. */
    public static function snapshot(array $campaign, array $template): array
    {
        $value = ['subject' => (string) ($campaign['subject'] ?? $campaign['name'] ?? ''),
            'preheader' => (string) ($campaign['preheader'] ?? ''), 'body' => (string) ($campaign['body'] ?? '')];
        if (($campaign['message_schema'] ?? null) !== 1) return $value;
        $value = self::fields($campaign);
        $value['schema'] = 1;
        $value['template'] = self::template($template);
        $value['template_revision'] = self::revision($value['template']);
        return $value;
    }

    /** $linkMapper is used only during a real saved delivery, never during preview/test. */
    public static function render(array $snapshot, string $unsubscribeUrl, string $baseUrl, ?callable $linkMapper = null): array
    {
        $baseUrl = self::baseUrl($baseUrl);
        if (!self::validDestination($unsubscribeUrl)) throw new \InvalidArgumentException('The unsubscribe destination is invalid.');
        $fields = self::fields(array_replace(['format' => 'plain'], $snapshot));
        $template = self::template($snapshot['template'] ?? null);
        if (isset($snapshot['template_revision']) && (!is_string($snapshot['template_revision'])
            || !hash_equals(self::revision($template), $snapshot['template_revision']))) {
            throw new \InvalidArgumentException('The queued email template no longer matches its saved revision.');
        }
        $map = static function (string $url) use ($baseUrl, $linkMapper): string {
            $absolute = self::absoluteUrl($url, $baseUrl);
            if ($linkMapper === null) return $absolute;
            $mapped = $linkMapper($absolute);
            if (!is_string($mapped) || !self::validDestination($mapped) || str_starts_with($mapped, '/')) {
                throw new \InvalidArgumentException('The tracked email destination is invalid.');
            }
            return $mapped;
        };
        $body = $fields['body'];
        $textBody = '';
        $htmlBody = '';
        $offset = 0;
        preg_match_all('~https?://[^\s<>"\']+~iu', $body, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$candidate, $position]) {
            $url = rtrim($candidate, '.,;!?)');
            $before = substr($body, $offset, $position - $offset);
            $textBody .= $before; $htmlBody .= self::escape($before);
            if (self::validDestination($url)) {
                $mapped = $map($url);
                $textBody .= $mapped;
                $htmlBody .= '<a href="' . self::escape($mapped) . '" style="color:#a70d56;overflow-wrap:anywhere">' . self::escape($url) . '</a>';
            } else { $textBody .= $url; $htmlBody .= self::escape($url); }
            $offset = $position + strlen($url);
        }
        $textBody .= substr($body, $offset); $htmlBody .= self::escape(substr($body, $offset));
        $text = ($fields['preheader'] !== '' ? $fields['preheader'] . "\n\n" : '') . $textBody;
        $button = '';
        if ($fields['cta_url'] !== '') {
            $cta = $map($fields['cta_url']);
            $text .= "\n\n" . $fields['cta_label'] . ': ' . $cta;
            $button = '<p style="margin:28px 0"><a href="' . self::escape($cta) . '" style="display:inline-block;padding:14px 22px;border-radius:8px;background:'
                . $template['accent'] . ';color:' . self::contrast($template['accent']) . ';font-weight:bold;text-decoration:none;overflow-wrap:anywhere">'
                . self::escape($fields['cta_label']) . '</a></p>';
        }
        $branded = ($snapshot['schema'] ?? null) === 1 && $fields['format'] === 'branded';
        if ($branded && $template['footer'] !== '') $text .= "\n\n" . $template['footer'];
        $text .= "\n\nUnsubscribe from campaign emails: " . $unsubscribeUrl;
        $html = null;
        if ($branded) {
            $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
                . self::escape($fields['subject']) . '</title></head><body style="margin:0;padding:24px 12px;background:#0d0810;font-family:Arial,sans-serif;color:#231b25">'
                . '<div style="display:none;max-height:0;overflow:hidden">' . self::escape($fields['preheader']) . '</div>'
                . '<main style="max-width:620px;margin:auto;background:#fff;border-radius:12px;overflow:hidden">'
                . '<header style="padding:28px;background:#161019;border-top:6px solid ' . $template['accent'] . ';color:#fff">'
                . '<strong style="font-size:24px;overflow-wrap:anywhere">' . self::escape($template['brand_name']) . '</strong>'
                . ($template['tagline'] !== '' ? '<p style="margin-bottom:0;color:#ded4e1">' . self::escape($template['tagline']) . '</p>' : '') . '</header>'
                . '<section style="padding:28px"><h1 style="font-size:26px;line-height:1.25;overflow-wrap:anywhere">' . self::escape($fields['subject']) . '</h1>'
                . '<div style="font-size:16px;line-height:1.7;overflow-wrap:anywhere">' . nl2br($htmlBody, false) . '</div>' . $button . '</section>'
                . '<footer style="padding:24px 28px;background:#f5eff5;font-size:13px;line-height:1.6;overflow-wrap:anywhere">'
                . ($template['footer'] !== '' ? '<p>' . self::escape($template['footer']) . '</p>' : '')
                . '<a href="' . self::escape($unsubscribeUrl) . '" style="color:#74204a">Unsubscribe from campaign emails</a></footer></main></body></html>';
        }
        return ['subject' => $fields['subject'], 'text' => $text, 'html' => $html];
    }

    public static function messageId(string $namespace, string $id): string
    {
        return '<vvx.' . hash('sha256', $namespace . '|' . $id) . '@mail.velvet-vixxen.invalid>';
    }

    private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private static function contrast(string $color): string
    {
        $luminance = 0.0;
        foreach ([0.2126, 0.7152, 0.0722] as $i => $weight) {
            $channel = hexdec(substr($color, 1 + $i * 2, 2)) / 255;
            $luminance += $weight * ($channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4);
        }
        return $luminance > 0.179 ? '#111111' : '#ffffff';
    }
}

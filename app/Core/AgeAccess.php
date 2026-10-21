<?php
declare(strict_types=1);

namespace App\Core;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Opt-in access policy. Client cookies, query results and the legacy notice never grant access. */
final class AgeAccess
{
    private readonly ?Closure $transport;
    private readonly PrivateState $state;

    public function __construct(private readonly Config $config, ?callable $transport = null, ?PrivateState $state = null)
    {
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
        $this->state = $state ?? new PrivateState($config);
    }

    public function settings(): array
    {
        $this->config->reload(); $raw = $this->config->get('age_access');
        if (!array_key_exists('age_access', $this->config->all())) return ['schema' => 1, 'mode' => 'disabled', 'provider' => 'yoti', 'minimum_age' => 18, 'session_minutes' => 60,
            'sdk_id' => '', 'api_key_encrypted' => '', 'geographic' => ['enabled' => false, 'header' => 'HTTP_CF_IPCOUNTRY', 'trusted_proxies' => [], 'blocked_countries' => []]];
        try {
            if (!is_array($raw) || ($raw['schema'] ?? null) !== 1 || !in_array($raw['mode'] ?? null, ['disabled', 'required'], true)
                || ($raw['provider'] ?? null) !== 'yoti' || !is_int($raw['minimum_age'] ?? null) || $raw['minimum_age'] < 18 || $raw['minimum_age'] > 99
                || !is_int($raw['session_minutes'] ?? null) || $raw['session_minutes'] < 15 || $raw['session_minutes'] > 1440
                || !is_string($raw['sdk_id'] ?? null) || !is_string($raw['api_key_encrypted'] ?? null) || strlen($raw['api_key_encrypted']) > 12000) throw new RuntimeException();
            if ($raw['sdk_id'] !== '' && YotiAgeProvider::uuid($raw['sdk_id']) !== $raw['sdk_id']) throw new RuntimeException();
            if ($raw['api_key_encrypted'] !== '' && !str_starts_with($raw['api_key_encrypted'], 'vvx1:')) throw new RuntimeException();
            $geo = $raw['geographic'] ?? null;
            if (!is_array($geo) || !is_bool($geo['enabled'] ?? null) || !isset(GeographicPolicy::HEADERS[$geo['header'] ?? ''])
                || !is_array($geo['trusted_proxies'] ?? null) || !array_is_list($geo['trusted_proxies'])
                || !is_array($geo['blocked_countries'] ?? null) || !array_is_list($geo['blocked_countries'])) throw new RuntimeException();
            foreach (array_merge($geo['trusted_proxies'], $geo['blocked_countries']) as $entry) if (!is_string($entry)) throw new RuntimeException();
            if (GeographicPolicy::networks(implode(' ', $geo['trusted_proxies'])) !== $geo['trusted_proxies']
                || GeographicPolicy::countries(implode(' ', $geo['blocked_countries'])) !== $geo['blocked_countries']
                || ($geo['enabled'] && ($geo['trusted_proxies'] === [] || $geo['blocked_countries'] === []))) throw new RuntimeException();
        } catch (Throwable $error) { throw new RuntimeException('Age and location settings need an owner review. Content remains unavailable.', 503, $error); }
        return $raw;
    }

    public function configuration(array $owner): array
    {
        $records = new ProtectionRecords($this->config); $records->requireOwner($owner); $settings = $this->settings();
        $settings['api_key_saved'] = $settings['api_key_encrypted'] !== ''; unset($settings['api_key_encrypted']);
        $settings['revision'] = $this->config->revision(); $settings['protected_delivery'] = $this->protectedDelivery();
        $records->requireOwner($owner); return $settings;
    }

    public function saveConfiguration(array $input, string $expected, array $owner, callable $privileged): void
    {
        $records = new ProtectionRecords($this->config); $records->requireOwner($owner); $privileged(); $previous = $this->settings();
        if (($input['reviewed_rules'] ?? false) !== true) throw new InvalidArgumentException('Review the access rules and confirm this deliberate change.');
        if (!in_array($input['mode'] ?? null, ['disabled', 'required'], true)) throw new InvalidArgumentException('Choose Disabled or Require verified age.');
        $number = static function (mixed $value, int $minimum, int $maximum, string $label): int {
            if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,3}\z/D', $value) !== 1 || (int) $value < $minimum || (int) $value > $maximum) throw new InvalidArgumentException($label . ' is outside its allowed range.');
            return (int) $value;
        };
        $sdk = $input['sdk_id'] ?? null;
        if (!is_string($sdk)) throw new InvalidArgumentException('Enter the Yoti SDK ID.'); $sdk = strtolower(trim($sdk));
        if ($sdk !== '') { try { YotiAgeProvider::uuid($sdk); } catch (Throwable) { throw new InvalidArgumentException('Use the UUID SDK ID supplied by Yoti.'); } }
        $key = $input['api_key'] ?? null;
        if (!is_string($key) || strlen($key) > 4096 || ($key !== '' && (strlen($key) < 8 || preg_match('/\A[A-Za-z0-9._~+\/-]+=*\z/D', $key) !== 1))) throw new InvalidArgumentException('Enter the API key supplied by Yoti without spaces.');
        $clear = ($input['clear_api_key'] ?? false) === true;
        if ($clear && $key !== '') throw new InvalidArgumentException('Choose either a replacement key or Clear saved key.');
        $cipher = $clear ? '' : ($key === '' ? $previous['api_key_encrypted'] : (new SecureValue($this->config))->encrypt($key));
        $header = $input['country_header'] ?? null;
        if (!is_string($header) || !isset(GeographicPolicy::HEADERS[$header])) throw new InvalidArgumentException('Choose a supported country header.');
        $next = ['schema' => 1, 'mode' => $input['mode'], 'provider' => 'yoti',
            'minimum_age' => $number($input['minimum_age'] ?? null, 18, 99, 'The adult threshold'),
            'session_minutes' => $number($input['session_minutes'] ?? null, 15, 1440, 'The verified session duration'),
            'sdk_id' => $sdk, 'api_key_encrypted' => $cipher,
            'geographic' => ['enabled' => ($input['geographic_enabled'] ?? false) === true, 'header' => $header,
                'trusted_proxies' => GeographicPolicy::networks($input['trusted_proxies'] ?? null), 'blocked_countries' => GeographicPolicy::countries($input['blocked_countries'] ?? null)]];
        if ($next['geographic']['enabled'] && ($next['geographic']['trusted_proxies'] === [] || $next['geographic']['blocked_countries'] === [])) throw new InvalidArgumentException('Location rules need explicit trusted immediate proxies and at least one blocked country.');
        $this->config->update(function (array &$values) use ($next, $records, $owner, $privileged): void {
            $records->requireOwner($owner); $privileged();
            if (($next['mode'] === 'required' || $next['geographic']['enabled']) && !$this->protectedDelivery()) throw new RuntimeException('Enable and finish Protection & policies before requiring age or location checks. Managed media needs protected delivery first.', 409);
            $values['age_access'] = $next; $records->requireOwner($owner); $privileged();
        }, $expected);
    }

    public function protectedDelivery(): bool
    {
        try {
            $records = new ProtectionRecords($this->config);
            return $records->enabled() && $this->config->get('protection.setup_pending', false) === false && $records->snapshot()['ready'] === true;
        } catch (Throwable) { return false; }
    }

    public function decision(array $session, array $server): string
    {
        try {
            $settings = $this->settings();
            if (($settings['mode'] === 'required' || $settings['geographic']['enabled']) && !$this->protectedDelivery()) return 'unavailable';
            if (!GeographicPolicy::allowed($settings['geographic'], $server)) return 'location';
            if ($settings['mode'] === 'disabled') return 'allowed';
            if (!$this->configured($settings)) return 'unavailable';
            return $this->grant($session['vvx_age_grant'] ?? null, $settings) ? 'allowed' : 'age';
        } catch (Throwable) { return 'unavailable'; }
    }

    public function begin(array &$session, array $server): string
    {
        $this->requirePost($server); $settings = $this->settings(); $this->requireAvailable($settings, $server);
        $now = time(); $this->rate($server, $now);
        unset($session['vvx_age_grant'], $session['vvx_age_attempt']);
        $attempt = ['reference' => 'vvx-' . bin2hex(random_bytes(24)), 'started_at' => $now,
            'threshold' => $settings['minimum_age'], 'policy' => $this->revision($settings), 'checked_at' => 0];
        $provider = $this->provider($settings);
        $created = $provider->create($attempt['reference'], $attempt['threshold'], $this->callback(), $now);
        // A slow provider call cannot grant a session made under replaced rules.
        if (!hash_equals($attempt['policy'], $this->revision($this->settings()))) throw new RuntimeException('Access rules changed. Start a new check.', 409);
        $attempt += $created; $session['vvx_age_attempt'] = $this->sign($attempt);
        return $provider->browserUrl($created['id']);
    }

    public function complete(array &$session, array $server): bool
    {
        $this->requirePost($server); $settings = $this->settings(); $this->requireAvailable($settings, $server);
        $attempt = $this->pending($session, $settings);
        if ($attempt === null) throw new RuntimeException('Start a new age check in this browser before checking its result.', 403);
        if ($attempt['checked_at'] > time() - 5) throw new RuntimeException('Wait a few seconds before checking the result again.', 429);
        $attempt['checked_at'] = time(); $session['vvx_age_attempt'] = $this->sign($attempt);
        if (!$this->provider($settings)->verified($attempt, time())) return false;
        $current = $this->settings();
        if (!hash_equals($attempt['policy'], $this->revision($current)) || !$this->protectedDelivery()
            || !GeographicPolicy::allowed($current['geographic'], $server)) throw new RuntimeException('Access rules changed. Start a new check.', 409);
        $now = time();
        $session['vvx_age_grant'] = $this->sign(['policy' => $attempt['policy'], 'threshold' => $attempt['threshold'], 'verified_at' => $now,
            'expires_at' => $now + $settings['session_minutes'] * 60, 'provider_session' => $attempt['id']]);
        unset($session['vvx_age_attempt']); return true;
    }

    public function pending(array $session, ?array $settings = null): ?array
    {
        try {
            $settings ??= $this->settings(); $attempt = $this->verifiedState($session['vvx_age_attempt'] ?? null);
            if ($attempt === null || ($attempt['policy'] ?? '') !== $this->revision($settings) || ($attempt['threshold'] ?? null) !== $settings['minimum_age']
                || !is_int($attempt['started_at'] ?? null) || !is_int($attempt['expires_at'] ?? null) || !is_int($attempt['checked_at'] ?? null)
                || $attempt['started_at'] > time() + 30 || $attempt['started_at'] < time() - YotiAgeProvider::SESSION_TTL - 30
                || $attempt['expires_at'] <= time() || $attempt['expires_at'] > $attempt['started_at'] + YotiAgeProvider::SESSION_TTL + 30
                || preg_match('/\Avvx-[a-f0-9]{48}\z/D', $attempt['reference'] ?? '') !== 1) return null;
            YotiAgeProvider::uuid($attempt['id'] ?? null); return $attempt;
        } catch (Throwable) { return null; }
    }

    public static function helpRoute(string $route): bool
    {
        $routes = function_exists('vvx_public_routes') ? \vvx_public_routes() : [];
        $route = $routes[$route] ?? $route;
        return in_array($route, ['age/index.php', 'policies/index.php', 'reports/index.php', 'privacy/choices.php',
            'account/login.php', 'account/logout.php', 'account/forgot.php', 'account/reset.php', 'account/verify.php', 'account/security.php',
            'account/privacy.php', 'communication/privacy-download.php', 'newsletter/unsubscribe.php',
            'api/payment-webhook.php', 'api/mail-events.php', 'api/addon-license.php', 'api/upload.php'], true);
    }

    private function requirePost(array $server): void
    {
        if (($server['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('Use the age check form to continue.', 405);
    }

    private function configured(array $settings): bool
    {
        if ($settings['sdk_id'] === '' || $settings['api_key_encrypted'] === '') return false;
        try { return (new SecureValue($this->config))->decrypt($settings['api_key_encrypted']) !== '' && $this->callback() !== ''; }
        catch (Throwable) { return false; }
    }

    private function callback(): string
    {
        $base = $this->config->get('base_url', ''); $parts = is_string($base) ? parse_url($base) : false;
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $base)) throw new RuntimeException('Age verification requires the configured HTTPS website address.', 503);
        return rtrim($base, '/') . '/route.php?vvx_route=age/index.php';
    }

    private function requireAvailable(array $settings, array $server): void
    {
        if (!GeographicPolicy::allowed($settings['geographic'], $server)) throw new RuntimeException('Access is unavailable for this location. Account privacy and reporting remain available.', 403);
        if ($settings['mode'] !== 'required' || !$this->protectedDelivery() || !$this->configured($settings)) throw new RuntimeException('Age verification is not available. No access was granted.', 503);
    }

    private function provider(array $settings): YotiAgeProvider { return new YotiAgeProvider($this->config, $settings['sdk_id'], $settings['api_key_encrypted'], $this->transport); }
    private function revision(array $settings): string { return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR)); }

    private function signingKey(): string
    {
        $material = $this->config->get('security.app_key');
        if (!is_string($material) || strlen($material) < 32) throw new RuntimeException('The age session signing key is unavailable.', 503);
        return hash_hmac('sha256', 'age-access-v1|' . (string) $this->config->get('installation_id') . '|' . session_id(), $material, true);
    }

    private function sign(array $data): array
    {
        return ['data' => $data, 'signature' => hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), $this->signingKey())];
    }

    private function verifiedState(mixed $value): ?array
    {
        if (!is_array($value) || !is_array($value['data'] ?? null) || !is_string($value['signature'] ?? null)) return null;
        return hash_equals($this->sign($value['data'])['signature'], $value['signature']) ? $value['data'] : null;
    }

    private function grant(mixed $raw, array $settings): bool
    {
        $value = $this->verifiedState($raw); $now = time();
        return $value !== null && ($value['policy'] ?? null) === $this->revision($settings) && ($value['threshold'] ?? null) === $settings['minimum_age']
            && is_int($value['verified_at'] ?? null) && $value['verified_at'] <= $now && $value['verified_at'] >= $now - $settings['session_minutes'] * 60
            && is_int($value['expires_at'] ?? null) && $value['expires_at'] > $now && $value['expires_at'] === $value['verified_at'] + $settings['session_minutes'] * 60
            && is_string($value['provider_session'] ?? null) && preg_match('/\A[0-9a-f-]{36}\z/D', $value['provider_session']) === 1;
    }

    private function rate(array $server, int $now): void
    {
        $peer = $server['REMOTE_ADDR'] ?? null;
        if (!is_string($peer) || filter_var($peer, FILTER_VALIDATE_IP) === false) throw new RuntimeException('Age verification needs a valid connection address.', 503);
        $key = hash_hmac('sha256', 'age-provider-start|' . $peer, (string) $this->config->get('security.app_key'));
        $this->state->update('age_requests', static function (array &$data) use ($now, $key): void {
            if ($data !== [] && (($data['schema'] ?? null) !== 1 || !is_array($data['requests'] ?? null))) throw new RuntimeException('Age verification is temporarily unavailable.', 503);
            $data += ['schema' => 1, 'requests' => []];
            foreach ($data['requests'] as $hash => $times) {
                if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1 || !is_array($times) || !array_is_list($times) || count($times) > 6) throw new RuntimeException('Age verification is temporarily unavailable.', 503);
                foreach ($times as $at) if (!is_int($at) || $at > $now + 30) throw new RuntimeException('Age verification is temporarily unavailable.', 503);
                $times = array_values(array_filter($times, static fn(int $at): bool => $at > $now - 3600));
                if ($times === []) unset($data['requests'][$hash]); else $data['requests'][$hash] = $times;
            }
            $times = $data['requests'][$key] ?? [];
            if (count($times) >= 6 || ($times !== [] && end($times) > $now - 30)) throw new RuntimeException('Please wait before starting another age check.', 429);
            if (count($data['requests']) >= 1000 && !isset($data['requests'][$key])) throw new RuntimeException('Age verification is busy. Please try again later.', 503);
            $times[] = $now; $data['requests'][$key] = $times;
        });
    }
}

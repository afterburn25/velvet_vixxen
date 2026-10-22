<?php
declare(strict_types=1);
namespace App\Core;

use RuntimeException;

final class AdminSecurity
{
    public function __construct(private readonly Store $store) {}
    public function fingerprint(string $value): string { return hash_hmac('sha256', $value, (string) $this->store->configuration()->get('security.app_key', '')); }
    public function record(string $id): array
    {
        $record = $this->store->adminSecurityState()['admins'][$id] ?? [];
        if (!is_array($record)) throw new RuntimeException('Administrator security record is invalid.');
        return $record;
    }
    public function enabled(string $id): bool { return !empty($this->record($id)['secret']); }

    /** Persistent account and peer limits include unknown accounts and successful attempts. */
    public function allowAttempt(string $identity, string $scope = 'login', ?int $at = null): bool
    {
        $now = $at ?? time();
        $account = $this->fingerprint($scope . ':account:' . strtolower(trim($identity)));
        $peer = $this->fingerprint($scope . ':peer:' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return $this->store->mutateAdminSecurity(static function (array &$data) use ($account, $peer, $now): bool {
            foreach ($data['attempts'] as $key => $bucket) if (!is_array($bucket) || (int) ($bucket['until'] ?? 0) <= $now) unset($data['attempts'][$key]);
            if (count($data['attempts']) > 2046 && (!isset($data['attempts'][$account]) || !isset($data['attempts'][$peer]))) return false;
            $allowed = true;
            foreach ([$account => 8, $peer => 40] as $key => $limit) {
                $bucket = $data['attempts'][$key] ?? ['count' => 0, 'until' => $now + 900];
                if ((int) $bucket['count'] >= $limit) $allowed = false;
                $bucket['count'] = min($limit + 1, (int) $bucket['count'] + 1); $data['attempts'][$key] = $bucket;
            }
            return $allowed;
        });
    }
    public function clearSuccessfulAttempt(string $identity, string $scope): void
    {
        $account = $this->fingerprint($scope . ':account:' . strtolower(trim($identity)));
        $this->store->mutateAdminSecurity(static function (array &$data) use ($account): void { unset($data['attempts'][$account]); });
    }
    private static function event(array &$data, string $id, string $action, array $fields = []): void
    {
        $data['events'][] = array_merge(['id' => bin2hex(random_bytes(12)), 'admin' => $id, 'action' => $action, 'at' => gmdate('c')], $fields);
        $data['events'] = array_slice($data['events'], -1000);
    }
    public function consumeFactor(string $id, string $input, ?callable $authorize = null, ?int $at = null): bool
    {
        $now = $at ?? time();
        return $this->store->mutateAdminSecurity(function (array &$data) use ($id, $input, $authorize, $now): bool {
            $record = $data['admins'][$id] ?? [];
            if (!is_array($record) || empty($record['secret'])) return false;
            $secret = (new SecureValue($this->store->configuration()))->decrypt((string) $record['secret']);
            $counter = Totp::match($secret, trim($input), $now, (int) ($record['last_counter'] ?? -1));
            $recovery = preg_replace('/[\s-]/', '', strtolower($input)) ?? ''; $index = null;
            if ($counter === null && preg_match('/\A[a-f0-9]{24}\z/D', $recovery)) {
                $hash = $this->fingerprint('recovery:' . $id . ':' . $recovery);
                foreach ((array) ($record['recovery'] ?? []) as $i => $saved) if (is_string($saved) && hash_equals($saved, $hash)) $index = $i;
            }
            if ($counter === null && $index === null) return false;
            if ($authorize !== null) $authorize();
            if ($counter !== null) $record['last_counter'] = $counter;
            else { unset($record['recovery'][$index]); $record['recovery'] = array_values($record['recovery']); }
            $data['admins'][$id] = $record;
            self::event($data, $id, $counter === null ? 'admin.recovery_code.used' : 'admin.two_factor.accepted');
            return true;
        });
    }
    public function enable(string $id, string $secret, string $code, callable $authorize): array
    {
        $counter = Totp::match($secret, $code, time());
        if ($counter === null) throw new RuntimeException('The authenticator code was not accepted. Check your device clock and try again.');
        $codes = []; $hashes = [];
        for ($i = 0; $i < 10; $i++) {
            $raw = bin2hex(random_bytes(12)); $codes[] = implode('-', str_split($raw, 6));
            $hashes[] = $this->fingerprint('recovery:' . $id . ':' . $raw);
        }
        $encrypted = (new SecureValue($this->store->configuration()))->encrypt($secret);
        $this->store->mutateAdminSecurity(static function (array &$data) use ($id, $encrypted, $counter, $hashes, $authorize): void {
            $record = $data['admins'][$id] ?? [];
            if (!empty($record['secret'])) throw new RuntimeException('Two-factor authentication is already enabled. Reload the saved security settings.');
            $authorize();
            $data['admins'][$id] = array_replace($record, ['secret' => $encrypted, 'last_counter' => $counter, 'recovery' => $hashes,
                'generation' => (int) ($record['generation'] ?? 0) + 1, 'sessions' => []]);
            self::event($data, $id, 'admin.two_factor.enabled');
        });
        return $codes;
    }
    public function disable(string $id, callable $authorize): void
    {
        $this->store->mutateAdminSecurity(static function (array &$data) use ($id, $authorize): void {
            $record = $data['admins'][$id] ?? []; $authorize();
            unset($record['secret'], $record['last_counter'], $record['recovery']);
            $record['generation'] = (int) ($record['generation'] ?? 0) + 1; $record['sessions'] = [];
            $data['admins'][$id] = $record; self::event($data, $id, 'admin.two_factor.disabled');
        });
    }
    public function startSession(array $admin, string $token, int $expectedGeneration, bool $factorSatisfied): array
    {
        $id = (string) $admin['id']; $now = time(); $key = hash('sha256', $token);
        $client = $this->fingerprint((string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $ua = $this->fingerprint((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return $this->store->mutateAdminSecurity(function (array &$data) use ($admin, $id, $key, $client, $ua, $now, $expectedGeneration, $factorSatisfied): array {
            $fresh = (new Store(new Config($this->store->configuration()->path())))->findAdminByEmail((string) $admin['email']);
            if (!$fresh || !(bool) ($fresh['is_active'] ?? false) || (string) $fresh['id'] !== $id || !hash_equals((string) $admin['password_hash'], (string) $fresh['password_hash'])) throw new RuntimeException('The administrator account changed. Sign in again.');
            $record = $data['admins'][$id] ?? [];
            if ((int) ($record['generation'] ?? 0) !== $expectedGeneration || (!empty($record['secret']) && !$factorSatisfied)) throw new RuntimeException('Security settings changed. Sign in again.');
            $sessions = (array) ($record['sessions'] ?? []);
            foreach ($sessions as $hash => $session) if ((int) ($session['expires'] ?? 0) <= $now) unset($sessions[$hash]);
            $known = (array) ($record['clients'] ?? []); $suspicious = $known !== [] && !in_array($client, $known, true);
            $record['clients'] = array_slice(array_values(array_unique(array_merge($known, [$client]))), -20);
            $sessions[$key] = ['created' => $now, 'expires' => $now + 43200, 'ua' => $ua, 'client' => $client];
            $record['sessions'] = array_slice($sessions, -20, null, true); $data['admins'][$id] = $record;
            self::event($data, $id, $suspicious ? 'admin.login.new_client' : 'admin.login', ['session' => substr($key, 0, 12)]);
            return ['generation' => (int) ($record['generation'] ?? 0), 'suspicious' => $suspicious];
        });
    }
    public function revoke(string $id, ?string $keepToken, callable $authorize): void
    {
        $keep = $keepToken === null ? null : hash('sha256', $keepToken);
        $this->store->mutateAdminSecurity(static function (array &$data) use ($id, $keep, $authorize): void {
            $record = $data['admins'][$id] ?? []; $authorize(); $session = $keep === null ? null : ($record['sessions'][$keep] ?? null);
            $record['sessions'] = $session === null ? [] : [$keep => $session]; $data['admins'][$id] = $record; self::event($data, $id, 'admin.sessions.revoked');
        });
    }
    public function endSession(string $id, string $token): void
    {
        $key = hash('sha256', $token);
        $this->store->mutateAdminSecurity(static function (array &$data) use ($id, $key): void {
            unset($data['admins'][$id]['sessions'][$key]); self::event($data, $id, 'admin.logout');
        });
    }
    public function overview(string $id): array
    {
        $state = $this->store->adminSecurityState(); $record = $state['admins'][$id] ?? []; $sessions = [];
        foreach ((array) ($record['sessions'] ?? []) as $key => $row) if ((int) ($row['expires'] ?? 0) > time()) $sessions[] = [
            'id' => substr((string) $key, 0, 12), 'created' => gmdate('c', (int) $row['created']), 'expires' => gmdate('c', (int) $row['expires'])];
        return ['enabled' => !empty($record['secret']), 'recovery_count' => count((array) ($record['recovery'] ?? [])),
            'sessions' => $sessions, 'events' => array_reverse(array_slice((array) ($state['events'] ?? []), -100))];
    }
}

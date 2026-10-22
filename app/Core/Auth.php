<?php
declare(strict_types=1);
namespace App\Core;

use RuntimeException;
use Throwable;

final class Auth
{
    private readonly AdminSecurity $security;
    public function __construct(private readonly Store $store) { $this->security = new AdminSecurity($store); }
    public function security(): AdminSecurity { return $this->security; }

    public function attempt(string $email, string $password): bool
    {
        unset($_SESSION['admin'], $_SESSION['admin_pending']);
        if (!$this->security->allowAttempt($email)) return false;
        $admin = $this->freshAdmin($email);
        if (!$admin || !(bool) ($admin['is_active'] ?? false) || !password_verify($password, (string) ($admin['password_hash'] ?? ''))) {
            if (!$admin) password_verify($password, '$2y$10$3EUcY4AOA72k0wuR6S.4euc3PHL2MP.4UpiywhXG9N61i8Gjd72sW');
            return false;
        }
        session_regenerate_id(true);
        $record = $this->security->record((string) $admin['id']);
        if (!empty($record['secret'])) {
            $_SESSION['admin_pending'] = ['email' => $admin['email'], 'id' => (string) $admin['id'],
                'stamp' => hash('sha256', (string) $admin['password_hash']), 'generation' => (int) ($record['generation'] ?? 0), 'expires' => time() + 300];
            return false;
        }
        $this->start($admin, (int) ($record['generation'] ?? 0), false); return true;
    }
    public function pending(): bool
    {
        if ((int) ($_SESSION['admin_pending']['expires'] ?? 0) <= time()) unset($_SESSION['admin_pending']);
        return isset($_SESSION['admin_pending']['id']);
    }
    public function completeChallenge(string $code): bool
    {
        if (!$this->pending()) return false;
        $pending = $_SESSION['admin_pending'];
        if (!$this->security->allowAttempt((string) $pending['id'], 'factor')) return false;
        $check = function () use ($pending): array {
            $admin = $this->freshAdmin((string) $pending['email']);
            if (!$admin || !(bool) ($admin['is_active'] ?? false) || (string) $admin['id'] !== $pending['id']
                || !hash_equals($pending['stamp'], hash('sha256', (string) $admin['password_hash']))) throw new RuntimeException('Sign in again.');
            return $admin;
        };
        $check();
        if (!$this->security->consumeFactor((string) $pending['id'], $code, $check)) return false;
        $admin = $check(); unset($_SESSION['admin_pending']); $this->start($admin, (int) $pending['generation'], true); return true;
    }
    private function start(array $admin, int $generation, bool $factorSatisfied): void
    {
        $token = bin2hex(random_bytes(32)); $result = $this->security->startSession($admin, $token, $generation, $factorSatisfied);
        session_regenerate_id(true);
        $_SESSION['admin'] = ['id' => $admin['id'], 'name' => $admin['name'] ?? 'Owner', 'email' => $admin['email'],
            'role' => $admin['role'] ?? 'owner', 'token' => $token, 'stamp' => hash('sha256', (string) $admin['password_hash']),
            'generation' => $result['generation'], 'authenticated_at' => time()];
        $this->security->clearSuccessfulAttempt((string) $admin['email'], 'login');
        $this->security->clearSuccessfulAttempt((string) $admin['id'], 'factor');
        if ($result['suspicious'] && class_exists(NotificationService::class)) {
            try {
                (new NotificationService($this->store->configuration(), $this->store))->enqueueOwnerAlert('suspicious_admin_login',
                    hash('sha256', $token), ['when' => gmdate('c'), 'url' => 'admin/?page=security']);
            } catch (Throwable) {
                $this->store->audit(is_numeric($admin['id']) ? (int) $admin['id'] : null, 'admin.alert.pending', ['event' => 'new_client']);
            }
        }
    }
    private function freshAdmin(string $email): ?array
    {
        return (new Store(new Config($this->store->configuration()->path())))->findAdminByEmail($email);
    }
    public function check(): bool
    {
        $session = $_SESSION['admin'] ?? null;
        if (!is_array($session) || !is_string($session['token'] ?? null) || !is_string($session['stamp'] ?? null)) return false;
        try {
            $record = $this->security->record((string) ($session['id'] ?? ''));
            $saved = $record['sessions'][hash('sha256', $session['token'])] ?? null;
            $admin = $this->freshAdmin((string) ($session['email'] ?? ''));
            $current = $this->security->record((string) ($session['id'] ?? ''));
            if (!$admin || !(bool) ($admin['is_active'] ?? false) || (string) $admin['id'] !== (string) $session['id']
                || !hash_equals($session['stamp'], hash('sha256', (string) $admin['password_hash']))
                || (int) ($session['generation'] ?? -1) !== (int) ($current['generation'] ?? 0)
                || ($current['sessions'][hash('sha256', $session['token'])] ?? null) !== $saved
                || !is_array($saved) || (int) ($saved['expires'] ?? 0) <= time()
                || !hash_equals((string) ($saved['ua'] ?? ''), $this->security->fingerprint((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')))) {
                unset($_SESSION['admin']); return false;
            }
            $_SESSION['admin']['name'] = $admin['name'] ?? 'Owner'; $_SESSION['admin']['role'] = $admin['role'] ?? '';
            return true;
        } catch (Throwable) { unset($_SESSION['admin']); return false; }
    }
    public function user(): ?array
    {
        if (!$this->check()) return null;
        return array_intersect_key($_SESSION['admin'], array_flip(['id', 'name', 'email', 'role']));
    }
    public function id(): ?int { $id = $this->user()['id'] ?? null; return is_numeric($id) ? (int) $id : null; }
    public function requireRecentOwner(): void
    {
        $user = $this->user();
        if (!$user || ($user['role'] ?? '') !== 'owner') throw new RuntimeException('Only the active site owner can perform this action.', 403);
        $at = (int) ($_SESSION['admin']['authenticated_at'] ?? 0);
        if ($at > time() + 30 || $at < time() - 900) throw new RuntimeException('Confirm your password in Security before continuing with this protected action.', 428);
    }
    public function reauthenticate(string $password, string $code = ''): bool
    {
        $user = $this->user();
        if (!$user || !$this->security->allowAttempt((string) $user['id'], 'reauth')) return false;
        $admin = $this->freshAdmin((string) $user['email']);
        if (!$admin || !password_verify($password, (string) $admin['password_hash'])) return false;
        if ($this->security->enabled((string) $user['id']) && !$this->security->consumeFactor((string) $user['id'], $code, function (): void {
            if (!$this->check()) throw new RuntimeException('Sign in again.', 403);
        })) return false;
        if (!$this->check()) return false;
        $_SESSION['admin']['authenticated_at'] = time();
        $this->security->clearSuccessfulAttempt((string) $user['id'], 'reauth'); return true;
    }
    public function refreshAfterSecurityChange(array $priorIdentity): void
    {
        $admin = $this->freshAdmin((string) $priorIdentity['email']);
        $session = $_SESSION['admin'] ?? [];
        if (!$admin || !(bool) ($admin['is_active'] ?? false) || ($admin['role'] ?? '') !== 'owner'
            || (string) $admin['id'] !== (string) $priorIdentity['id'] || (string) ($session['id'] ?? '') !== (string) $admin['id']
            || !hash_equals((string) ($session['stamp'] ?? ''), hash('sha256', (string) $admin['password_hash']))
            || (int) ($session['authenticated_at'] ?? 0) < time() - 900) throw new RuntimeException('Sign in again.');
        $this->start($admin, (int) ($this->security->record((string) $admin['id'])['generation'] ?? 0), true);
    }
    public function logout(): void
    {
        $session = $_SESSION['admin'] ?? [];
        unset($_SESSION['admin'], $_SESSION['admin_pending'], $_SESSION['admin_enrollment']);
        if (!empty($session['token']) && !empty($session['id'])) $this->security->endSession((string) $session['id'], (string) $session['token']);
        session_regenerate_id(true);
    }
}

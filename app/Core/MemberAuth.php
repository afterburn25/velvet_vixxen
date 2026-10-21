<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class MemberAuth
{
    private ?array $cachedMember = null;
    private bool $resolved = false;
    private bool $touched = false;

    public function __construct(
        private readonly Store $store,
        private readonly Config $config,
        private readonly MailService $mailer,
    ) {
    }

    /** @return array{member:array,verificationUrl:string,mailStatus:string} */
    public function register(string $displayName, string $email, string $password, bool $registrationReminder = false): array
    {
        $this->validatePassword($password);
        $attribution = MarketingAttributionBridge::registration($this->config);
        $member = $this->store->createMember([
            'displayName' => $displayName,
            'email' => $email,
            'password' => $password,
            'status' => 'pending',
            'preferences' => array_merge($attribution === null ? [] : ['_attribution' => $attribution], [
                'registrationReminder' => $registrationReminder,
                'registrationReminderConsentedAt' => $registrationReminder ? gmdate('c') : null]),
        ]);
        if ($attribution !== null) MarketingAttributionBridge::project($this->config, $this->store, 'registration', (string) $member['id']);
        $delivery = $this->sendVerification((string) $member['id']);
        if (function_exists('addon_event') && function_exists('is_installed') && is_installed()) {
            addon_event('member.registered', ['memberId'=>(string)$member['id'],'email'=>(string)$member['email']]);
        }
        return [
            'member' => $member,
            'verificationUrl' => (string) ($delivery['debugUrl'] ?? ''),
            'mailStatus' => (string) ($delivery['mailStatus'] ?? 'queued'),
        ];
    }

    /** @return array{ok:bool,message:string,requiresVerification?:bool} */
    public function attempt(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $member = $this->store->member($email);
        $generic = 'The email address or password was not accepted.';
        if (!$member) {
            password_verify($password, '$2y$10$3EUcY4AOA72k0wuR6S.4euc3PHL2MP.4UpiywhXG9N61i8Gjd72sW');
            return ['ok' => false, 'message' => $generic];
        }
        $lockedUntil = strtotime((string) ($member['lockedUntil'] ?? '')) ?: 0;
        if ($lockedUntil > time()) {
            return ['ok' => false, 'message' => 'Too many sign-in attempts. Try again in about 15 minutes.'];
        }
        if (!password_verify($password, (string) ($member['passwordHash'] ?? ''))) {
            $this->store->recordMemberLoginFailure($email);
            return ['ok' => false, 'message' => $generic];
        }
        if ((string) ($member['status'] ?? '') === 'pending' || empty($member['emailVerifiedAt'])) {
            return ['ok' => false, 'message' => 'Confirm your email address before signing in.', 'requiresVerification' => true];
        }
        if ((string) ($member['status'] ?? '') !== 'active') {
            return ['ok' => false, 'message' => 'This account is not currently available. Contact support for help.'];
        }
        $this->startSession($member);
        $this->store->recordMemberLoginSuccess((string) $member['id']);
        return ['ok' => true, 'message' => 'Signed in.'];
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function user(): ?array
    {
        // A long-running request may outlive account closure or device revocation.
        // Resolve the durable identity every time; only the activity touch is cached.
        $this->cachedMember = null;
        $this->resolved = true;
        $session = (array) ($_SESSION['member'] ?? []);
        $token = (string) ($session['token'] ?? '');
        $memberId = (string) ($session['id'] ?? '');
        if ($token === '' || $memberId === '') {
            return null;
        }
        $deviceSession = $this->store->memberSession($token);
        if (!$deviceSession || (string) ($deviceSession['memberId'] ?? '') !== $memberId) {
            unset($_SESSION['member']);
            return null;
        }
        $member = $this->store->member($memberId);
        if (!$member || (string) ($member['status'] ?? '') !== 'active' || empty($member['emailVerifiedAt'])) {
            unset($_SESSION['member']);
            return null;
        }
        if (!$this->touched) {
            $this->store->touchMemberSession($token);
            $this->touched = true;
        }
        unset($member['passwordHash']);
        $this->cachedMember = $member;
        return $member;
    }

    public function id(): ?string
    {
        $member = $this->user();
        return $member ? (string) $member['id'] : null;
    }

    public function rawSessionToken(): string
    {
        return (string) ($_SESSION['member']['token'] ?? '');
    }

    public function currentSession(): ?array
    {
        $token = $this->rawSessionToken();
        return $token !== '' ? $this->store->memberSession($token) : null;
    }

    public function logout(): void
    {
        $memberId = (string) ($_SESSION['member']['id'] ?? '');
        $session = $this->currentSession();
        if ($memberId !== '' && $session) {
            $this->store->revokeMemberSession($memberId, (string) $session['id']);
            $this->store->recordMemberEvent($memberId, 'member.logout');
        }
        unset($_SESSION['member']);
        session_regenerate_id(true);
        $this->cachedMember = null;
        $this->resolved = true;
    }

    /** @return array{debugUrl:string,mailStatus:string,expiresAt:string} */
    public function sendVerification(string|int $memberId): array
    {
        $member = $this->store->member($memberId);
        if (!$member) {
            throw new RuntimeException('Member account not found.');
        }
        if (!empty($member['emailVerifiedAt'])) {
            return ['debugUrl' => '', 'mailStatus' => 'not-needed', 'expiresAt' => ''];
        }
        $token = $this->store->createMemberToken((string) $member['id'], 'email_verification', 24 * 3600);
        $url = base_url('account/verify.php?token=' . rawurlencode($token['token']));
        $body = "Hi " . (string) $member['displayName'] . ",\n\nConfirm your Velvet Vixxen account by opening this link:\n" . $url . "\n\nThis link expires in 24 hours. If you did not create this account, you can ignore this message.";
        $mail = $this->mailer->send((string) $member['id'], (string) $member['email'], 'Confirm your Velvet Vixxen account', 'member_verification', $body, ['verificationUrl' => $url]);
        $this->store->recordMemberEvent((string) $member['id'], 'member.verification.sent', ['mailStatus' => $mail['status'] ?? 'queued']);
        return [
            'debugUrl' => $this->debugLinksAllowed() ? $url : '',
            'mailStatus' => (string) ($mail['status'] ?? 'queued'),
            'expiresAt' => $token['expiresAt'],
        ];
    }

    public function verify(string $rawToken): ?array
    {
        $member = $this->store->memberForToken($rawToken, 'email_verification', true);
        if (!$member) {
            return null;
        }
        $member = $this->store->markMemberVerified((string) $member['id']);
        $this->startSession($member);
        return $this->user();
    }

    /** @return array{debugUrl:string,mailStatus:string} */
    public function requestPasswordReset(string $email): array
    {
        $member = $this->store->member(strtolower(trim($email)));
        if (!$member || (string) ($member['status'] ?? '') === 'closed') {
            return ['debugUrl' => '', 'mailStatus' => 'not-created'];
        }
        $token = $this->store->createMemberToken((string) $member['id'], 'password_reset', 3600);
        $url = base_url('account/reset.php?token=' . rawurlencode($token['token']));
        $body = "Hi " . (string) $member['displayName'] . ",\n\nReset your Velvet Vixxen password using this link:\n" . $url . "\n\nThis link expires in one hour. If you did not request a reset, ignore this message and your password will remain unchanged.";
        $mail = $this->mailer->send((string) $member['id'], (string) $member['email'], 'Reset your Velvet Vixxen password', 'member_password_reset', $body, ['resetUrl' => $url]);
        $this->store->recordMemberEvent((string) $member['id'], 'member.password_reset.requested', ['mailStatus' => $mail['status'] ?? 'queued']);
        return ['debugUrl' => $this->debugLinksAllowed() ? $url : '', 'mailStatus' => (string) ($mail['status'] ?? 'queued')];
    }

    public function resetPassword(string $rawToken, string $password): bool
    {
        $this->validatePassword($password);
        $member = $this->store->memberForToken($rawToken, 'password_reset', true);
        if (!$member) {
            return false;
        }
        $this->store->setMemberPassword((string) $member['id'], $password);
        foreach ($this->store->memberSessions((string) $member['id']) as $session) {
            $this->store->revokeMemberSession((string) $member['id'], (string) $session['id']);
        }
        $updatedMember = $this->store->member((string) $member['id']) ?? $member;
        if ((string) ($updatedMember['status'] ?? '') === 'active' && !empty($updatedMember['emailVerifiedAt'])) {
            $this->startSession($updatedMember);
        } else {
            unset($_SESSION['member']);
            $this->resolved = false;
            $this->cachedMember = null;
            $this->touched = false;
        }
        $this->store->recordMemberEvent((string) $member['id'], 'member.password_reset.completed');
        return true;
    }

    public function validatePassword(string $password): void
    {
        if ((function_exists('mb_strlen') ? mb_strlen($password) : strlen($password)) < 12) {
            throw new RuntimeException('Your password must be at least 12 characters.');
        }
        if ((function_exists('mb_strlen') ? mb_strlen($password) : strlen($password)) > 255) {
            throw new RuntimeException('Your password is too long.');
        }
        $common = ['password1234', '123456789012', 'qwertyuiop12', 'letmein123456', 'velvetvixxen'];
        if (in_array(strtolower($password), $common, true)) {
            throw new RuntimeException('Choose a less common password or a longer passphrase.');
        }
    }

    private function startSession(array $member): void
    {
        session_regenerate_id(true);
        $rawToken = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
        $deviceSession = $this->store->createMemberSession((string) $member['id'], $rawToken);
        $_SESSION['member'] = [
            'id' => (string) $member['id'],
            'token' => $rawToken,
            'sessionId' => (string) $deviceSession['id'],
            'startedAt' => now_iso(),
        ];
        $this->resolved = false;
        $this->cachedMember = null;
        $this->touched = false;
    }

    private function debugLinksAllowed(): bool
    {
        return (string) $this->config->get('storage_mode', 'mysql') === 'file';
    }
}

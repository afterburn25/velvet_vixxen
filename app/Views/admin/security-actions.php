<?php
declare(strict_types=1);
$securityIdentity = app_auth()->user();
if (!$securityIdentity || ($securityIdentity['role'] ?? '') !== 'owner') throw new RuntimeException('Only the active owner can manage administrator security.', 403);
$securityAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$securityService = app_auth()->security();
$securityId = (string) $securityIdentity['id'];
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
try {
    if (in_array($securityAction, ['security_confirm', 'security_begin', 'security_disable'], true)) {
        if (!app_auth()->reauthenticate($password, $code)) throw new RuntimeException('Your password or authentication code was not accepted. Wait before retrying after repeated failures.', 403);
    }
    app_auth()->requireRecentOwner();
    if ($securityAction === 'security_confirm') {
        flash('success', 'Your identity is confirmed for protected actions for 15 minutes.');
        redirect(base_url('admin/?page=security'));
    } elseif ($securityAction === 'security_begin') {
        if ($securityService->enabled($securityId)) throw new RuntimeException('An authenticator is already connected.');
        $_SESSION['admin_enrollment'] = ['id' => $securityId, 'secret' => App\Core\Totp::secret(), 'expires' => time() + 600];
    } elseif ($securityAction === 'security_enable') {
        $enrollment = $_SESSION['admin_enrollment'] ?? [];
        if (($enrollment['id'] ?? '') !== $securityId || (int) ($enrollment['expires'] ?? 0) <= time() || !is_string($enrollment['secret'] ?? null)) throw new RuntimeException('Authenticator setup expired. Start again.');
        if (!$securityService->allowAttempt($securityId, 'enrollment')) throw new RuntimeException('Too many setup attempts. Wait 15 minutes before trying again.', 429);
        $securityRecoveryCodes = $securityService->enable($securityId, $enrollment['secret'], $code, static fn() => app_auth()->requireRecentOwner());
        unset($_SESSION['admin_enrollment']);
        app_auth()->refreshAfterSecurityChange($securityIdentity);
    } elseif ($securityAction === 'security_disable') {
        if (($_POST['confirm_disable'] ?? '') !== '1') throw new RuntimeException('Confirm that you want to remove the authenticator.');
        $securityService->disable($securityId, static fn() => app_auth()->requireRecentOwner());
        unset($_SESSION['admin_enrollment']); app_auth()->refreshAfterSecurityChange($securityIdentity);
        flash('success', 'The authenticator was removed and all other administrator sessions were signed out.');
        redirect(base_url('admin/?page=security'));
    } elseif ($securityAction === 'security_revoke') {
        $securityService->revoke($securityId, (string) $_SESSION['admin']['token'], static fn() => app_auth()->requireRecentOwner());
        flash('success', 'All your other administrator sessions were signed out.');
        redirect(base_url('admin/?page=security'));
    } elseif ($securityAction !== 'security_begin') throw new RuntimeException('Choose a security action.');
} finally { unset($_POST['password'], $_POST['code'], $password, $code); }

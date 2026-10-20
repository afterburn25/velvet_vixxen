<?php
declare(strict_types=1);

require dirname(__DIR__) . '/TestEnvironment.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$env = velvet_test_environment('member-auth');
try {
    $auth = $env['auth'];
    $store = $env['store'];

    try {
        $auth->validatePassword('TooShort11');
        $failures[] = 'An 11-character password was accepted.';
    } catch (Throwable) {
    }
    try {
        $auth->validatePassword('password1234');
        $failures[] = 'A known common password was accepted.';
    } catch (Throwable) {
    }

    $registration = $auth->register('Test Member', 'member@example.test', 'VelvetPass12!');
    $member = $registration['member'];
    $assert((string) ($member['status'] ?? '') === 'pending', 'New member was not pending verification.');
    $assert((string) ($registration['mailStatus'] ?? '') === 'skipped', 'Disabled mail mode did not mark verification mail skipped.');
    $verificationUrl = (string) ($registration['verificationUrl'] ?? '');
    parse_str((string) parse_url($verificationUrl, PHP_URL_QUERY), $verifyQuery);
    $verificationToken = (string) ($verifyQuery['token'] ?? '');
    $assert($verificationToken !== '', 'File-mode registration did not expose a verification URL for testing.');

    $beforeVerification = $auth->attempt('member@example.test', 'VelvetPass12!');
    $assert(empty($beforeVerification['ok']) && !empty($beforeVerification['requiresVerification']), 'Pending account was allowed to sign in.');

    $verified = $auth->verify($verificationToken);
    $assert(is_array($verified) && (string) ($verified['status'] ?? '') === 'active', 'Verification did not activate the account.');
    $assert($auth->verify($verificationToken) === null, 'Verification token was accepted more than once.');
    $assert($auth->check(), 'Verification did not start a member session.');
    $assert(!array_key_exists('passwordHash', (array) $auth->user()), 'Public member session exposed the password hash.');
    $assert(count($store->memberSessions((string) $member['id'])) === 1, 'Member session was not persisted.');

    $auth->logout();
    $assert(!$auth->check(), 'Logout left the member signed in.');
    $assert($store->memberSessions((string) $member['id']) === [], 'Logout did not revoke the active device session.');

    $login = $auth->attempt('member@example.test', 'VelvetPass12!');
    $assert(!empty($login['ok']), 'Verified member could not sign in.');
    $reset = $auth->requestPasswordReset('member@example.test');
    parse_str((string) parse_url((string) ($reset['debugUrl'] ?? ''), PHP_URL_QUERY), $resetQuery);
    $resetToken = (string) ($resetQuery['token'] ?? '');
    $assert($resetToken !== '', 'File-mode password reset did not expose a test URL.');
    $assert($auth->resetPassword($resetToken, 'NewVelvetPass12!'), 'Password reset token was not accepted.');
    $assert(!$auth->resetPassword($resetToken, 'AnotherVelvet12!'), 'Password reset token was accepted more than once.');
    $auth->logout();
    $oldPassword = $auth->attempt('member@example.test', 'VelvetPass12!');
    $assert(empty($oldPassword['ok']), 'Old password still worked after reset.');
    $newPassword = $auth->attempt('member@example.test', 'NewVelvetPass12!');
    $assert(!empty($newPassword['ok']), 'New password did not work after reset.');

    $events = $store->memberEvents((string) $member['id'], 100);
    $eventNames = array_column($events, 'event');
    foreach (['member.registered','member.email.verified','member.login','member.password_reset.completed'] as $eventName) {
        $assert(in_array($eventName, $eventNames, true), 'Expected account event missing: ' . $eventName);
    }
    $outbox = $store->mailOutbox();
    $assert(count($outbox) >= 2, 'Verification and password-reset mail were not recorded.');
} catch (Throwable $e) {
    $failures[] = $e::class . ': ' . $e->getMessage();
} finally {
    velvet_test_cleanup($env);
}
velvet_finish_test('Member authentication test', $failures);

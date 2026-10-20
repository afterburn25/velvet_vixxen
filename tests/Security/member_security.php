<?php
declare(strict_types=1);

require dirname(__DIR__) . '/TestEnvironment.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$env = velvet_test_environment('member-security');
try {
    $store = $env['store'];
    $auth = $env['auth'];
    $registration = $auth->register('Security Member', 'security@example.test', 'SecureVelvet12!');
    parse_str((string) parse_url((string) $registration['verificationUrl'], PHP_URL_QUERY), $query);
    $rawVerificationToken = (string) ($query['token'] ?? '');
    $rawStore = (string) file_get_contents($env['dataPath']);
    $assert($rawVerificationToken !== '' && !str_contains($rawStore, $rawVerificationToken), 'Raw verification token was stored in the file database.');
    $assert(str_contains($rawStore, hash('sha256', $rawVerificationToken)), 'Verification token hash was not stored.');

    $auth->verify($rawVerificationToken);
    $rawSessionToken = $auth->rawSessionToken();
    $rawStore = (string) file_get_contents($env['dataPath']);
    $assert($rawSessionToken !== '' && !str_contains($rawStore, $rawSessionToken), 'Raw member session token was stored.');
    $assert(str_contains($rawStore, hash('sha256', $rawSessionToken)), 'Member session token hash was not stored.');
    $auth->logout();

    for ($i = 0; $i < 8; $i++) {
        $auth->attempt('security@example.test', 'DefinitelyWrong12!');
    }
    $locked = $store->member('security@example.test');
    $assert((int) ($locked['failedLoginCount'] ?? 0) >= 8, 'Failed sign-in counter did not reach the lockout threshold.');
    $assert((strtotime((string) ($locked['lockedUntil'] ?? '')) ?: 0) > time(), 'Repeated sign-in failures did not lock the account temporarily.');
    $lockedAttempt = $auth->attempt('security@example.test', 'SecureVelvet12!');
    $assert(empty($lockedAttempt['ok']) && str_contains(strtolower((string) ($lockedAttempt['message'] ?? '')), 'too many'), 'Locked account was not blocked with a neutral message.');

    $api = (string) file_get_contents($env['root'] . '/public/routes/api/member-state.php');
    $assert(str_contains($api, 'require_member(true)'), 'Member-state API is not authentication protected.');
    $assert(str_contains($api, 'require_csrf()'), 'Member-state writes are not CSRF protected.');
    $memberAuthSource = (string) file_get_contents($env['root'] . '/app/Core/MemberAuth.php');
    $memberStoreSource = (string) file_get_contents($env['root'] . '/app/Core/MemberStoreTrait.php');
    $assert(str_contains($memberAuthSource, 'password_verify'), 'Member authentication is not using password_verify.');
    $assert(str_contains($memberStoreSource, 'rowCount() !== 1'), 'Database token consumption does not verify that the one-time token was claimed atomically.');
    $assert(str_contains($memberStoreSource, 'expires_at>NOW()'), 'Database token consumption does not re-check expiration during the atomic claim.');
} catch (Throwable $e) {
    $failures[] = $e::class . ': ' . $e->getMessage();
} finally {
    velvet_test_cleanup($env);
}
velvet_finish_test('Member security test', $failures);

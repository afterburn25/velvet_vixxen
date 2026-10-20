<?php
declare(strict_types=1);

$mailOwner = vvx_mail_settings_require_owner();
$mailSettingsService = new App\Core\MailSettingsService(app_config(), (string) $mailOwner['email']);
$mailRevision = is_string($_POST['mail_revision'] ?? null) ? $_POST['mail_revision'] : '';

if ($action === 'save_mail_settings') {
    $mailInput = [];
    foreach (['mode', 'from_email', 'from_name', 'host', 'port', 'encryption', 'username', 'password'] as $key) {
        $mailInput[$key] = $_POST[$key] ?? '';
    }
    $mailInput['clear_password'] = isset($_POST['clear_password']);
    try {
        $savedMail = $mailSettingsService->save($mailInput, $mailRevision);
    } finally {
        unset($mailInput['password'], $_POST['password']);
    }
    vvx_mail_settings_audit('system.mail.updated', ['mode' => $savedMail['mode']]);
    unset($_SESSION['_mail_test_result'][(string) $mailOwner['id']]);
    flash('success', 'Email settings saved. Future email uses these settings. No test email was sent.');
    redirect(base_url('admin/?page=mail-settings'));
}

if ($action !== 'send_mail_test') {
    http_response_code(400);
    throw new RuntimeException('Choose a valid email settings action.');
}

$mailSnapshot = $mailSettingsService->snapshot();
if ($mailRevision === '' || !hash_equals($mailSnapshot['revision'], $mailRevision)) {
    http_response_code(409);
    throw new RuntimeException('Email settings changed after this page opened. Reload before sending a test.');
}
$testToken = is_string($_POST['mail_test_token'] ?? null) ? $_POST['mail_test_token'] : '';
$testClaim = $_SESSION['_mail_test_tokens'][$testToken] ?? null;
if (!is_array($testClaim) || ($testClaim['owner'] ?? '') !== (string) $mailOwner['id']
    || ($testClaim['revision'] ?? '') !== $mailRevision || (int) ($testClaim['issued'] ?? 0) < time() - 1800) {
    http_response_code(409);
    throw new RuntimeException('This test request expired or was already used. Reload to start a new test.');
}
$mailTransport = new App\Core\MailService(app_config(), app_store());
if (($problem = $mailTransport->configurationProblem()) !== null) {
    throw new RuntimeException($problem);
}
$testOwnerId = (string) $mailOwner['id'];
$lastTest = (int) ($_SESSION['_mail_test_at'][$testOwnerId] ?? 0);
if ($lastTest > time() - 60) {
    http_response_code(429);
    throw new RuntimeException('Please wait one minute between test emails.');
}

// Consume the one-use request before any network activity, then release the
// session so Save/Disable and other owner requests remain responsive.
unset($_SESSION['_mail_test_tokens'][$testToken]);
$_SESSION['_mail_test_at'][$testOwnerId] = time();
$testRecipient = (string) $mailOwner['email'];
$configPath = app_config()->path();
$sessionWasOpen = session_status() === PHP_SESSION_ACTIVE;
if ($sessionWasOpen) session_write_close();
try {
    $result = $mailTransport->deliver($testRecipient, 'Velvet Vixxen email settings test',
        "This is the test email you requested from Velvet Vixxen administration.\n\nYour saved email transport accepted a request to send this message.\nNo private messages, subscriber data, or SMTP credentials are included.\n",
        static function () use ($configPath, $testRecipient, $testOwnerId, $mailRevision): bool {
            try {
                $freshConfig = new App\Core\Config($configPath);
                $freshOwner = (new App\Core\Store($freshConfig))->findAdminByEmail($testRecipient);
                if (!$freshOwner || empty($freshOwner['is_active']) || ($freshOwner['role'] ?? '') !== 'owner'
                    || (string) $freshOwner['id'] !== $testOwnerId) return false;
                $freshSnapshot = (new App\Core\MailSettingsService($freshConfig, $testRecipient))->snapshot();
                return hash_equals($freshSnapshot['revision'], $mailRevision);
            } catch (Throwable) {
                return false;
            }
        });
} finally {
    if ($sessionWasOpen) session_start();
}
$testStatus = in_array($result['status'] ?? '', ['sent', 'failed', 'unknown', 'skipped'], true) ? $result['status'] : 'unknown';
$_SESSION['_mail_test_result'][$testOwnerId] = [
    'status' => $testStatus,
    'revision' => $mailRevision,
    'at' => gmdate('c'),
    'message' => $testStatus === 'sent'
        ? 'The saved mail transport accepted your test email. Check your owner inbox and spam folder.'
        : (string) ($result['lastError'] ?? 'The test outcome is unknown. Check your mail provider before sending another test.'),
];
vvx_mail_settings_audit('system.mail.test', ['status' => $testStatus]);
redirect(base_url('admin/?page=mail-settings#mail-test'));

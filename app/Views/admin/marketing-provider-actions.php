<?php
declare(strict_types=1);

if (($_GET['tab'] ?? null) !== 'provider') throw new InvalidArgumentException('Open Provider events before changing event handling.');
$providerService = new \App\Core\MailProviderService(app_config(), app_store());
$providerOwner = (array) app_auth()->user();
if ($action === 'mail_provider_settings') {
    $providerAttempt = $_POST;
    if (!is_string($_POST['provider_revision'] ?? null) || !is_string($_POST['public_key'] ?? null)) throw new InvalidArgumentException('Reload provider settings before saving.');
    $providerService->saveSettings(['enabled' => ($_POST['enabled'] ?? null) === '1', 'public_key' => $_POST['public_key']], $_POST['provider_revision'], $providerOwner);
    $notice = 'Provider-event settings saved. Only correctly signed, recent SendGrid requests can be accepted.';
} elseif ($action === 'mail_provider_reconcile') {
    $stats = $providerService->reconcile(100, $providerOwner);
    $notice = sprintf('Verified pending events reviewed: %d processed, %d unmatched. No email was sent.', $stats['processed'], $stats['unmatched']);
} else throw new InvalidArgumentException('Choose a supported provider-event action.');

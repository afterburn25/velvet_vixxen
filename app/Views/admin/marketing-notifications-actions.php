<?php
declare(strict_types=1);

if (($_GET['tab'] ?? null) !== 'notifications') throw new InvalidArgumentException('Open the notifications page before changing notification delivery.');
$notificationService = new \App\Core\NotificationService(app_config(), app_store());
$notificationOwner = (array) app_auth()->user();
if ($action === 'notification_settings') {
    $notificationAttempt = $_POST;
    $types = $_POST['types'] ?? [];
    if (!is_array($types) || !is_string($_POST['notification_revision'] ?? null)) throw new InvalidArgumentException('Reload notification settings before saving.');
    $notificationService->saveSettings(['enabled' => ($_POST['enabled'] ?? null) === '1', 'types' => $types], $_POST['notification_revision'], $notificationOwner);
    $notice = 'Notification settings saved. The worker sends only permitted, currently eligible notices.';
} elseif ($action === 'notification_discover') {
    $stats = $notificationService->discover(100, $notificationOwner);
    $notice = $stats['held'] ?? sprintf('Discovery finished: %d new notices queued; %d member records and %d content recipients examined.', $stats['queued'], $stats['examined_members'], $stats['examined_content_recipients']);
} elseif ($action === 'notification_run') {
    $limit = filter_var($_POST['batch_limit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
    if ($limit === false) throw new InvalidArgumentException('Choose a batch size between 1 and 10.');
    $sessionOpen = session_status() === PHP_SESSION_ACTIVE;
    if ($sessionOpen) session_write_close();
    try { $stats = $notificationService->run($limit, $notificationOwner); }
    finally { if ($sessionOpen) session_start(); }
    if (!velvet_marketing_is_owner()) throw new RuntimeException('Current owner authentication is required.', 403);
    $notice = $stats['held'] ?? sprintf('Notification batch finished: %d accepted by the mail transport, %d failed, %d unknown, %d skipped.', $stats['accepted'], $stats['failed'], $stats['unknown'], $stats['skipped']);
} elseif (in_array($action, ['notification_cancel', 'notification_retry'], true)) {
    if (!is_string($_POST['notification_id'] ?? null) || !is_string($_POST['notification_revision'] ?? null)) throw new InvalidArgumentException('Reload the saved notification before changing it.');
    if ($action === 'notification_cancel') {
        $notificationService->cancel($_POST['notification_id'], $_POST['notification_revision'], $notificationOwner);
        $notice = 'Notification canceled before transmission.';
    } else {
        $notificationService->retry($_POST['notification_id'], $_POST['notification_revision'], $notificationOwner);
        $notice = 'The definitely failed notification is queued for one more attempt. Current consent and eligibility will be checked again.';
    }
} else throw new InvalidArgumentException('Choose a supported notification action.');

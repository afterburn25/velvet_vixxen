<?php
declare(strict_types=1);

$source = dirname(__DIR__, 2);
require_once $source . '/includes/version.php';
require_once $source . '/app/Core/RuntimeBuildService.php';
$fixture = sys_get_temp_dir() . '/vvx-runtime-build-' . bin2hex(random_bytes(8));
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$reject = static function (callable $action, string $message) use ($assert): void {
    $rejected = false;
    try { $action(); } catch (RuntimeException) { $rejected = true; }
    $assert($rejected, $message);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
    @rmdir($path);
};
try {
    $assert(trim((string) file_get_contents($source . '/VERSION')) === VELVET_VIXXEN_VERSION, 'Visible runtime version differs from canonical VERSION.');
    $assert(VELVET_VIXXEN_ASSET_VERSION === VELVET_VIXXEN_VERSION . '-' . VELVET_VIXXEN_BUILD_ID, 'Browser assets do not identify the same canonical release/build.');
    $files = ['admin/update.php', 'admin/index.php', 'app/Core/RuntimeBuildService.php', 'includes/version.php',
        'app/Views/admin/composer.php', 'public/assets/js/composer.js', 'public/assets/js/api-client.js', 'public/assets/js/scene-geometry.js',
        'app/Core/SceneDepth.php', 'public/assets/js/scene-depth.js',
        'app/Core/SceneSchedule.php',
        'app/Core/SceneScheduleStoreTrait.php',
        'app/Console/run_scenes.php',
        'app/Support/scene_schedule_admin.php',
        'app/Views/admin/scene-schedules-actions.php',
        'app/Views/admin/scene-schedules.php',
        'public/assets/css/scene-schedules.css',
        'app/Core/MarketingCalendar.php',
        'app/Views/admin/media-center.php',
        'app/Core/MarketingPublication.php',
        'app/Core/MarketingHistory.php',
        'app/Core/MarketingStorage.php',
        'app/Core/MarketingStore.php',
        'app/Core/Store.php',
        'app/Views/admin/marketing.php',
        'app/Views/admin/marketing-calendar.php',
        'app/Views/admin/marketing-publication-editor.php',
        'app/Views/admin/marketing-publication-list.php',
        'app/Views/admin/marketing-history.php',
        'includes/marketing.php',
        'includes/marketing_admin.php',
        'public/assets/css/marketing-calendar.css',
        'admin/repair.php', 'app/Core/RuntimeInventoryService.php', 'app/Core/RootLayoutService.php',
        'app/Views/admin/repair-status.php', 'route.php', 'public/routes/api/upload.php',
        'public/routes/api/scene.php', 'app/Support/public_routes.php', 'public/router.php',
        'app/Core/MenuService.php',
        'app/Core/MenuStoreTrait.php',
        'app/Support/menu_admin.php',
        'app/Views/admin/menus-actions.php',
        'app/Views/admin/menus.php',
        'app/Views/public/partials/menu-items.php',
        'public/assets/css/menu-admin.css',
        'public/assets/css/navigation.css',
        'public/assets/css/site-ux.css',
        'public/assets/js/menu-admin.js',
        'public/assets/js/navigation.js',
    ];
    $v09Dependencies = [
        'admin/repair-current.php',
        'app/Console/operations.php',
        'app/Console/run_analytics.php',
        'app/Console/run_backups.php',
        'app/Console/run_mail_events.php',
        'app/Console/run_notifications.php',
        'app/Console/run_privacy.php',
        'app/Core/AdminSecurity.php',
        'app/Core/AdminSecurityStoreTrait.php',
        'app/Core/AgeAccess.php',
        'app/Core/BackupArchive.php',
        'app/Core/BackupDatabase.php',
        'app/Core/BackupJobs.php',
        'app/Core/BackupRestore.php',
        'app/Core/BackupService.php',
        'app/Core/CampaignMessage.php',
        'app/Core/CampaignTestService.php',
        'app/Core/GeographicPolicy.php',
        'app/Core/MailProviderService.php',
        'app/Core/MarketingAnalytics.php',
        'app/Core/MarketingAnalyticsTrait.php',
        'app/Core/MarketingAttribution.php',
        'app/Core/MarketingAttributionAdminTrait.php',
        'app/Core/MarketingAttributionBridge.php',
        'app/Core/MarketingAttributionTrait.php',
        'app/Core/MarketingBusinessStoreTrait.php',
        'app/Core/MarketingCampaignMessages.php',
        'app/Core/MarketingMeasurementSqlRepository.php',
        'app/Core/MarketingPrivacy.php',
        'app/Core/MarketingPrivacyErasure.php',
        'app/Core/MarketingProviderEvents.php',
        'app/Core/MarketingRecovery.php',
        'app/Core/MemberPrivacyArchive.php',
        'app/Core/MemberPrivacyDomains.php',
        'app/Core/MemberPrivacyProjection.php',
        'app/Core/MemberPrivacyService.php',
        'app/Core/MemberPrivacyStoreTrait.php',
        'app/Core/NotificationDiscovery.php',
        'app/Core/NotificationProviderEvents.php',
        'app/Core/NotificationService.php',
        'app/Core/NotificationStoreTrait.php',
        'app/Core/OperationsFiles.php',
        'app/Core/OperationsGate.php',
        'app/Core/PrivateCommunicationFile.php',
        'app/Core/PrivateMediaResponse.php',
        'app/Core/PrivateState.php',
        'app/Core/ProtectionDelivery.php',
        'app/Core/ProtectionRecords.php',
        'app/Core/ProtectionResources.php',
        'app/Core/ProtectionStoreTrait.php',
        'app/Core/ProtectionUploads.php',
        'app/Core/PublicPolicies.php',
        'app/Core/RepairAccess.php',
        'app/Core/SendGridSignature.php',
        'app/Core/Totp.php',
        'app/Core/YotiAgeProvider.php',
        'app/Docs/BACKUP_OPERATIONS.md',
        'app/Docs/MEMBER_PRIVACY.md',
        'app/Support/marketing_recovery_admin.php',
        'app/Support/member_privacy.php',
        'app/Support/operations_admin.php',
        'app/Support/policies_admin.php',
        'app/Support/protection_admin.php',
        'app/Support/repair_entry.php',
        'app/Views/admin/campaign-message-preview.php',
        'app/Views/admin/login.php',
        'app/Views/admin/marketing-analytics.php',
        'app/Views/admin/marketing-attribution.php',
        'app/Views/admin/marketing-deleted-actions.php',
        'app/Views/admin/marketing-deleted.php',
        'app/Views/admin/marketing-email-brand.php',
        'app/Views/admin/marketing-link-bio.php',
        'app/Views/admin/marketing-measurement-actions.php',
        'app/Views/admin/marketing-notifications-actions.php',
        'app/Views/admin/marketing-notifications.php',
        'app/Views/admin/marketing-provider-actions.php',
        'app/Views/admin/marketing-provider.php',
        'app/Views/admin/operations.php',
        'app/Views/admin/policies-actions.php',
        'app/Views/admin/policies.php',
        'app/Views/admin/privacy.php',
        'app/Views/admin/protection-actions.php',
        'app/Views/admin/protection.php',
        'app/Views/admin/repair-current-status.php',
        'app/Views/admin/repair-signin.php',
        'app/Views/admin/security-actions.php',
        'app/Views/admin/security.php',
        'app/Views/public/access.php',
        'app/Views/public/partials/legal-links.php',
        'database/migrations/202610210016_marketing_measurements.php',
        'database/migrations/202610210017_private_state.php',
        'includes/age_access.php',
        'public/assets/css/marketing-deleted.css',
        'public/assets/css/member-privacy.css',
        'public/assets/css/operations.css',
        'public/assets/css/policies.css',
        'public/assets/css/privacy-admin.css',
        'public/assets/css/protection-public.css',
        'public/assets/css/protection.css',
        'public/assets/css/security-admin.css',
        'public/routes/account/privacy.php',
        'public/routes/age/index.php',
        'public/routes/api/mail-events.php',
        'public/routes/campaign/click.php',
        'public/routes/communication/privacy-download.php',
        'public/routes/policies/index.php',
        'public/routes/privacy/choices.php',
        'public/routes/protected-asset.php',
        'public/routes/reports/index.php',
    ];
    $paymentDependencies = [
        'admin/index.php',
        'app/Core/AddonRuntime.php',
        'app/Core/CommerceService.php',
        'app/Core/CommerceStoreTrait.php',
        'app/Core/MailService.php',
        'app/Core/MemberPrivacyProjection.php',
        'app/Core/MemberPrivacyService.php',
        'app/Core/MemberPrivacyStoreTrait.php',
        'app/Core/MerchandiseStoreTrait.php',
        'app/Core/PaymentCommerceTrait.php',
        'app/Core/PaymentOperationService.php',
        'app/Core/PaymentRecoveryAuthorizationException.php',
        'app/Core/PaymentRecoveryService.php',
        'app/Core/PaymentStoreTrait.php',
        'app/Core/Store.php',
        'app/Payments/BoundPaymentProviderInterface.php',
        'app/Payments/PaymentContract.php',
        'app/Payments/PaymentProviderInterface.php',
        'app/Payments/PaymentProviderRegistry.php',
        'app/Payments/SandboxPaymentProvider.php',
        'app/Support/payment_recovery_admin.php',
        'app/Views/admin/commerce.php',
        'app/Views/admin/navigation.php',
        'app/Views/admin/payment-recovery-actions.php',
        'app/Views/admin/payment-recovery.php',
        'database/migrations/202610220018_payment_journal.php',
        'public/assets/css/payment-recovery.css',
        'public/routes/api/payment-webhook.php',
    ];
    $purchasedTermsDependencies = ['app/Core/PurchasedTerms.php','app/Core/PurchasedTermsStoreTrait.php','app/Views/public/partials/checkout-terms.php','app/Views/public/partials/purchased-membership.php'];
    $dayDependencies = ['app/Core/MembershipPeriod.php','database/migrations/202610230020_membership_day_periods.php'];
    $recurringDependencies = ['app/Core/RecurringContract.php','app/Core/RecurringStoreTrait.php','app/Core/RecurringCommerceTrait.php','app/Core/RecurringRecoveryService.php','app/Payments/RecurringProviderInterface.php','app/Views/admin/recurring-recovery.php','app/Views/public/partials/recurring-membership.php','database/migrations/202610220019_recurring_lifecycle.php'];
    $files = array_values(array_unique(array_merge($files, $v09Dependencies, $paymentDependencies, $purchasedTermsDependencies, $recurringDependencies, $dayDependencies)));
    $hashes = [];
    foreach ($files as $relative) {
        $directory = dirname($fixture . '/' . $relative);
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        copy($source . '/' . $relative, $fixture . '/' . $relative);
        $hashes[$relative] = hash_file('sha256', $fixture . '/' . $relative);
    }
    foreach (['config/app.php', 'storage/data.json', 'storage/install.lock', 'uploads/owner-photo.txt'] as $private) {
        if (!is_dir(dirname($fixture . '/' . $private))) mkdir(dirname($fixture . '/' . $private), 0700, true);
        file_put_contents($fixture . '/' . $private, 'Synthetic existing site state: ' . $private);
        $preserved[$private] = hash_file('sha256', $fixture . '/' . $private);
    }
    $manifest = ['schema' => 1, 'product' => 'Velvet Vixxen', 'version' => VELVET_VIXXEN_VERSION,
        'build_id' => VELVET_VIXXEN_BUILD_ID, 'files' => $hashes];
    $write = static function (array $data) use ($fixture): void {
        file_put_contents($fixture . '/includes/runtime-build.json', json_encode($data, JSON_THROW_ON_ERROR));
    };
    $write($manifest);
    $service = new App\Core\RuntimeBuildService($fixture);
    $status = $service->inspect();
    $assert($status['files_match'] && $status['files_checked'] === count($files), 'A complete file map must verify.');
    $assert($status['running_build_matches'], 'The running build identity must be reported.');

    // Old files can have the same size and mtime as the replacement. Hash contents, not metadata.
    $target = $fixture . '/app/Views/admin/composer.php';
    $original = file_get_contents($target);
    $mtime = filemtime($target);
    file_put_contents($target, str_replace('Add Button', 'Old Button', $original));
    touch($target, $mtime);
    $status = $service->inspect();
    $assert(!$status['files_match'] && $status['problems'] === [
        ['path' => 'app/Views/admin/composer.php', 'status' => 'Different file contents'],
    ], 'Same-size/same-time old Composer must be identified by contents.');
    $reject(static fn() => $service->refreshPhpCache(), 'Incomplete deployment must not activate via cache refresh.');
    file_put_contents($target, $original);
    unlink($fixture . '/public/routes/api/upload.php');
    $status = $service->inspect();
    $assert(!$status['files_match'] && $status['problems'][0]['path'] === 'public/routes/api/upload.php', 'Missing organized upload handler must be identified.');
    copy($source . '/public/routes/api/upload.php', $fixture . '/public/routes/api/upload.php');
    unlink($fixture . '/public/assets/js/scene-geometry.js');
    $status = $service->inspect();
    $assert(!$status['files_match'] && $status['problems'][0]['path'] === 'public/assets/js/scene-geometry.js', 'Missing shared scene geometry must be identified.');
    $reject(static fn() => $service->refreshPhpCache(), 'A missing scene dependency must block cache activation.');
    copy($source . '/public/assets/js/scene-geometry.js', $fixture . '/public/assets/js/scene-geometry.js');

    unlink($fixture . '/public/assets/js/menu-admin.js');
    $status = $service->inspect();
    $assert(!$status['files_match'] && $status['problems'][0]['path'] === 'public/assets/js/menu-admin.js', 'A missing menu editor script must be identified.');
    $reject(static fn() => $service->refreshPhpCache(), 'Missing menu dependencies must block cache activation.');
    copy($source . '/public/assets/js/menu-admin.js', $fixture . '/public/assets/js/menu-admin.js');

    foreach ($dayDependencies as $dependency) {
        $incomplete = $manifest; unset($incomplete['files'][$dependency]); $write($incomplete);
        $reject(static fn() => $service->refreshPhpCache(), 'A manifest omitting a day-period dependency must block activation: ' . $dependency);
        $write($manifest);
        unlink($fixture . '/' . $dependency);
        $assert(!$service->inspect()['files_match'], 'Missing day-period dependency was accepted: ' . $dependency);
        copy($source . '/' . $dependency, $fixture . '/' . $dependency);
    }

    $depthDependencies = ['app/Core/SceneDepth.php', 'public/assets/js/scene-depth.js'];
    foreach ($depthDependencies as $dependency) {
        unlink($fixture . '/' . $dependency);
        $status = $service->inspect();
        $assert(!$status['files_match'] && $status['problems'][0]['path'] === $dependency, 'Missing scene depth dependency must be identified: ' . $dependency);
        $reject(static fn() => $service->refreshPhpCache(), 'Missing scene depth dependencies must block cache activation.');
        copy($source . '/' . $dependency, $fixture . '/' . $dependency);
    }

    $scheduleDependencies = [
        'app/Core/SceneSchedule.php',
        'app/Core/SceneScheduleStoreTrait.php',
        'app/Console/run_scenes.php',
        'app/Support/scene_schedule_admin.php',
        'app/Views/admin/scene-schedules-actions.php',
        'app/Views/admin/scene-schedules.php',
        'public/assets/css/scene-schedules.css',
    ];
    foreach ($scheduleDependencies as $dependency) {
        unlink($fixture . '/' . $dependency);
        $status = $service->inspect();
        $assert(!$status['files_match'] && $status['problems'][0]['path'] === $dependency, 'Missing scene scheduling dependency must be identified: ' . $dependency);
        $reject(static fn() => $service->refreshPhpCache(), 'Missing scene scheduling dependencies must block cache activation.');
        copy($source . '/' . $dependency, $fixture . '/' . $dependency);
    }

    $calendarDependencies = [
        'app/Core/MarketingCalendar.php',
        'app/Views/admin/media-center.php',
        'app/Core/MarketingPublication.php',
        'app/Core/MarketingHistory.php',
        'app/Core/MarketingStorage.php',
        'app/Core/MarketingStore.php',
        'app/Core/Store.php',
        'app/Views/admin/marketing.php',
        'app/Views/admin/marketing-calendar.php',
        'app/Views/admin/marketing-publication-editor.php',
        'app/Views/admin/marketing-publication-list.php',
        'app/Views/admin/marketing-history.php',
        'includes/marketing.php',
        'includes/marketing_admin.php',
        'public/assets/css/marketing-calendar.css',
    ];
    foreach ($calendarDependencies as $dependency) {
        unlink($fixture . '/' . $dependency);
        $status = $service->inspect();
        $assert(!$status['files_match'] && $status['problems'][0]['path'] === $dependency, 'Missing calendar dependency must be identified: ' . $dependency);
        $reject(static fn() => $service->refreshPhpCache(), 'Missing calendar dependencies must block cache activation.');
        copy($source . '/' . $dependency, $fixture . '/' . $dependency);
    }

    foreach ($paymentDependencies as $dependency) {
        unlink($fixture . '/' . $dependency);
        $status = $service->inspect();
        $assert(!$status['files_match'] && $status['problems'][0]['path'] === $dependency, 'Missing payment dependency must be identified: ' . $dependency);
        $reject(static fn() => $service->refreshPhpCache(), 'Missing payment dependencies must block cache activation.');
        copy($source . '/' . $dependency, $fixture . '/' . $dependency);
        $incomplete = $manifest;
        unset($incomplete['files'][$dependency]);
        $write($incomplete);
        $reject(static fn() => $service->inspect(), 'A 0.9.1 inventory cannot omit payment dependency: ' . $dependency);
        $write($manifest);
    }
    $beforePayments = $manifest;
    foreach ([
        'app/Core/PaymentCommerceTrait.php', 'app/Core/PaymentOperationService.php',
        'app/Core/PaymentRecoveryAuthorizationException.php', 'app/Core/PaymentRecoveryService.php',
        'app/Core/PaymentStoreTrait.php', 'app/Payments/BoundPaymentProviderInterface.php',
        'app/Payments/PaymentContract.php', 'app/Support/payment_recovery_admin.php',
        'app/Views/admin/payment-recovery-actions.php', 'app/Views/admin/payment-recovery.php',
        'database/migrations/202610220018_payment_journal.php', 'public/assets/css/payment-recovery.css',
    ] as $dependency) unset($beforePayments['files'][$dependency]);
    $beforePayments['version'] = '0.9.0';
    $beforePayments['build_id'] = '20261021.4';
    $write($beforePayments);
    $legacyPayments = $service->inspect();
    $assert($legacyPayments['files_match'] && !$legacyPayments['running_build_matches'] && $legacyPayments['version'] === '0.9.0',
        'The prior 0.9 inventory remains inspectable without later payment dependencies.');
    $write($manifest);

    rename($target, $fixture . '/composer-outside.php');
    symlink($fixture . '/composer-outside.php', $target);
    $assert(!$service->inspect()['files_match'], 'A linked runtime file must not be read as a verified replacement.');
    $reject(static fn() => $service->refreshPhpCache(), 'A linked runtime file must block cache activation.');
    unlink($target);
    rename($fixture . '/composer-outside.php', $target);

    foreach (['config/app.php', 'storage/data.json', 'uploads/owner-photo.txt', '../foreign.php', '/tmp/foreign.php',
        'app/../config/app.php', 'app\\Core\\Other.php', 'addons/installed/private.php', 'includes/runtime-build.json', 'api/upload.php', 'media.php', 'account/index.php'] as $unsafe) {
        $invalid = $manifest;
        $invalid['files'][$unsafe] = str_repeat('0', 64);
        $write($invalid);
        $reject(static fn() => $service->inspect(), 'Private or escaping manifest path must fail: ' . $unsafe);
    }
    $invalid = $manifest;
    unset($invalid['files']['public/routes/api/scene.php']);
    $write($invalid);
    $reject(static fn() => $service->inspect(), 'Manifest cannot omit the scene endpoint and claim a complete update.');
    $invalid = $manifest;
    unset($invalid['files']['public/assets/js/scene-geometry.js']);
    $write($invalid);
    $reject(static fn() => $service->inspect(), 'Current manifest cannot omit the shared scene geometry dependency.');
    $invalid['version'] = '0.8.9';
    $invalid['build_id'] = '20261020.10';
    $write($invalid);
    $legacy = $service->inspect();
    $assert($legacy['files_match'] && !$legacy['running_build_matches'] && $legacy['version'] === '0.8.9', 'Older inventories must remain inspectable without a dependency introduced later.');
    $menuDependencies = [
        'app/Core/MenuService.php',
        'app/Core/MenuStoreTrait.php',
        'app/Support/menu_admin.php',
        'app/Views/admin/menus-actions.php',
        'app/Views/admin/menus.php',
        'app/Views/public/partials/menu-items.php',
        'public/assets/css/menu-admin.css',
        'public/assets/css/navigation.css',
        'public/assets/css/site-ux.css',
        'public/assets/js/menu-admin.js',
        'public/assets/js/navigation.js',
    ];
    foreach ($menuDependencies as $dependency) {
        $incomplete = $manifest;
        unset($incomplete['files'][$dependency]);
        $write($incomplete);
        $reject(static fn() => $service->inspect(), 'Current inventory cannot omit menu dependency: ' . $dependency);
    }
    $beforeMenus = $manifest;
    foreach ($menuDependencies as $dependency) unset($beforeMenus['files'][$dependency]);
    $beforeMenus['version'] = '0.8.10';
    $beforeMenus['build_id'] = '20261020.11';
    $write($beforeMenus);
    $legacyMenus = $service->inspect();
    $assert($legacyMenus['files_match'] && !$legacyMenus['running_build_matches'] && $legacyMenus['version'] === '0.8.10', 'A pre-menu inventory remains inspectable without later menu dependencies.');
    foreach ($depthDependencies as $dependency) {
        $incomplete = $manifest;
        unset($incomplete['files'][$dependency]);
        $write($incomplete);
        $reject(static fn() => $service->inspect(), 'Current inventory cannot omit scene depth dependency: ' . $dependency);
    }
    $beforeDepth = $manifest;
    foreach ($depthDependencies as $dependency) unset($beforeDepth['files'][$dependency]);
    $beforeDepth['version'] = '0.8.11';
    $beforeDepth['build_id'] = '20261020.12';
    $write($beforeDepth);
    $legacyDepth = $service->inspect();
    $assert($legacyDepth['files_match'] && !$legacyDepth['running_build_matches'] && $legacyDepth['version'] === '0.8.11', 'A pre-depth inventory remains inspectable without later masking dependencies.');
    foreach ($calendarDependencies as $dependency) {
        $incomplete = $manifest;
        unset($incomplete['files'][$dependency]);
        $write($incomplete);
        $reject(static fn() => $service->inspect(), 'Current inventory cannot omit calendar dependency: ' . $dependency);
    }
    $beforeCalendar = $manifest;
    foreach ($calendarDependencies as $dependency) unset($beforeCalendar['files'][$dependency]);
    $beforeCalendar['version'] = '0.8.12';
    $beforeCalendar['build_id'] = '20261021.1';
    $write($beforeCalendar);
    $legacyCalendar = $service->inspect();
    $assert($legacyCalendar['files_match'] && !$legacyCalendar['running_build_matches'] && $legacyCalendar['version'] === '0.8.12', 'A pre-calendar inventory remains inspectable without the later calendar dependency floor.');
    foreach ($scheduleDependencies as $dependency) {
        $incomplete = $manifest;
        unset($incomplete['files'][$dependency]);
        $write($incomplete);
        $reject(static fn() => $service->inspect(), 'Current inventory cannot omit a scheduling dependency: ' . $dependency);
    }
    $beforeSchedules = $manifest;
    foreach ($scheduleDependencies as $dependency) unset($beforeSchedules['files'][$dependency]);
    $beforeSchedules['version'] = '0.8.13';
    $beforeSchedules['build_id'] = '20261021.2';
    $write($beforeSchedules);
    $legacySchedules = $service->inspect();
    $assert($legacySchedules['files_match'] && !$legacySchedules['running_build_matches'] && $legacySchedules['version'] === '0.8.13', 'A pre-scheduling inventory remains inspectable without the new dependency floor.');
    file_put_contents($fixture . '/includes/runtime-build.json', '{invalid json');
    $reject(static fn() => $service->inspect(), 'Malformed manifest must fail readably.');
    unlink($fixture . '/includes/runtime-build.json');
    $reject(static fn() => $service->inspect(), 'Missing manifest must not imply successful deployment.');
    $write($manifest);
    rename($fixture . '/includes', $fixture . '/outside-includes');
    symlink($fixture . '/outside-includes', $fixture . '/includes');
    $reject(static fn() => $service->inspect(), 'Linked parent must not expose or verify a manifest.');
    unlink($fixture . '/includes');
    rename($fixture . '/outside-includes', $fixture . '/includes');
    $assert($service->inspect()['files_match'], 'Restoring all intended runtime bytes must restore verification.');
    foreach ($preserved as $path => $hash) {
        $assert(hash_file('sha256', $fixture . '/' . $path) === $hash, 'Build checks changed private installed state: ' . $path);
    }
    echo "PASS runtime build verification: {$checks} assertions\n";
} finally {
    $remove($fixture);
}

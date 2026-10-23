#!/usr/bin/env python3
"""Exercise real distributions and upgrade overlays against disposable state."""
from __future__ import annotations

import importlib.util
import hashlib
import json
import re
import shutil
import subprocess
import tempfile
from pathlib import Path
from unittest.mock import patch
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("velvet_package", ROOT / "scripts/package.py")
package = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(package)

REPAIR_FILES = {
    "app/Core/MigrationRunner.php", "app/Core/MigrationResumeSchema.php",
    "includes/installation_recovery.php",
    "install/services/FileSystemService.php", "install/services/InstallerService.php",
    "install/index.php", "install/services/EmailAddress.php", "install/services/SmtpTester.php",
    "install/views/single-page.php",
    "public/assets/css/installer.css",
    "public/assets/js/installer.js",
}
APPROVED_ROOT_FILES = {".htaccess", "VERSION", "index.php", "route.php"}
APPROVED_FRESH_ROOTS = {
    "addons", "admin", "app", "config", "database", "images", "includes",
    "install", "public", "storage", "templates", "uploads", "vendor",
}
APPROVED_UPGRADE_ROOTS = APPROVED_FRESH_ROOTS - {"config", "storage", "uploads", "install"}
REJECTED_BUILD5_ROOTS = {
    "about", "account", "api", "blog", "booking", "campaign", "cart", "checkout",
    "collections", "communication", "create-account", "extension", "join", "links",
    "login", "membership", "messages", "music", "newsletter", "photos", "receipt",
    "recover", "requests", "shop", "videos",
}
PUBLIC_HANDLER_FILES = {"public/routes/" + relative for relative in {
    "about/index.php", "account/billing.php", "account/forgot.php", "account/index.php",
    "account/library.php", "account/login.php", "account/logout.php", "account/register.php",
    "account/reset.php", "account/security.php", "account/verify.php",
    "api/addon-license.php", "api/mail-events.php", "api/marketing-event.php", "api/member-state.php",
    "api/payment-webhook.php", "api/scene.php", "api/upload.php",
    "blog/index.php", "blog/post.php", "booking/index.php", "campaign/index.php",
    "cart/index.php", "checkout/index.php", "checkout/success.php", "collections/index.php",
    "communication/download.php", "extension/index.php", "links/index.php", "media.php",
    "membership/index.php", "messages/index.php", "music/index.php", "newsletter/confirm.php",
    "newsletter/index.php", "newsletter/unsubscribe.php", "photos/index.php", "photos/view.php",
    "protected-asset.php", "reports/index.php",
    "receipt/index.php", "requests/index.php", "shop/index.php",
    "videos/index.php", "videos/watch.php", "privacy/choices.php", "campaign/click.php",
}}
COMPOSER_REPAIR_FILES = {
    *PUBLIC_HANDLER_FILES, ".htaccess", "route.php", "admin/index.php", "admin/update.php",
    "admin/repair.php", "public/router.php", "app/Support/public_routes.php",
    "app/Core/SceneButton.php", "app/Core/Store.php", "app/Core/RepairBackupService.php",
    "app/Core/RuntimeBuildService.php", "app/Core/RuntimeInventoryService.php",
    "app/Core/RootLayoutService.php", "app/Support/functions.php",
    "app/Core/AddonLicenseClient.php", "app/Core/MarketingStore.php",
    "app/Core/MailService.php", "app/Core/MailDeliveryException.php",
    "app/Core/MailSettingsService.php", "app/Core/EmailAddress.php",
    "app/Support/mail_settings_admin.php", "app/Views/admin/mail-settings.php",
    "app/Views/admin/mail-settings-actions.php", "app/Views/admin/communications.php",
    "public/assets/css/mail-settings.css", "public/assets/js/mail-settings.js",
    "app/Core/CampaignDeliveryService.php", "app/Console/run_marketing.php",
    "app/Views/admin/composer.php", "app/Views/admin/media-library.php",
    "app/Views/admin/navigation.php", "app/Views/admin/update-status.php",
    "app/Views/admin/repair-backups.php",
    "app/Views/admin/repair-status.php",
    "app/Views/public/partials/footer.php", "public/home.php",
    "app/Views/public/partials/scene-buttons.php", "app/Views/admin/marketing-actions.php",
    "app/Views/admin/marketing.php", "includes/marketing.php", "includes/marketing_public_shell.php",
    "includes/version.php", "includes/runtime-build.json", "public/routes/links/index.php", "public/routes/campaign/index.php",
    "public/assets/css/site.css", "public/assets/css/composer.css", "public/assets/css/site-ux.css",
    "public/assets/js/api-client.js", "public/assets/js/admin.js",
    "public/assets/js/composer.js", "public/assets/js/site.js", "public/assets/js/marketing.js",
    "public/assets/js/media-center.js",
}
EDITORIAL_RUNTIME_FILES = {
    "admin/index.php", "app/Core/MarketingAccess.php", "app/Core/MarketingAudience.php",
    "app/Core/MarketingHistory.php", "app/Core/MarketingMetadata.php",
    "app/Core/MarketingPublication.php", "app/Core/MarketingStore.php",
    "app/Core/MediaStoreTrait.php", "app/Core/Store.php",
    "app/Views/admin/marketing-actions.php", "app/Views/admin/marketing-history.php",
    "app/Views/admin/marketing-preview.php", "app/Views/admin/marketing-publication-editor.php",
    "app/Views/admin/marketing-publication-list.php", "app/Views/public/partials/header.php",
    "includes/marketing.php", "includes/marketing_admin.php", "includes/marketing_public_shell.php",
    "public/assets/css/marketing.css", "public/assets/js/marketing-editorial.js",
    "public/routes/blog/index.php", "public/routes/blog/post.php", "public/routes/campaign/index.php",
}
VERSION = (ROOT / "VERSION").read_text().strip()
BUILD_ID = re.search(r"const VELVET_VIXXEN_BUILD_ID = '([0-9.]+)';", (ROOT / "includes/version.php").read_text())[1]
RUNTIME_BUILD_PATH = "includes/runtime-build.json"
VERIFIED_BASELINES = [
    "e5b04daa132521be69c943151f8112c7cd2017e8",
    "db759342e401617c9933c13b81a147ad9f8fd515",
    "2bc178013c9c528f45a5d0447624f3cbaf4395f2",
]
LEGACY_TIMESTAMP = (2026, 1, 1, 0, 0, 0)
REPAIR_TIMESTAMPS = {
    relative: ((2026, 10, 19, 0, 0, 0) if relative == "install/services/FileSystemService.php"
               else (2026, 10, 19, 16, 0, 0))
    for relative in REPAIR_FILES
}
REPAIR_TIMESTAMPS.update({
    relative: (2026, 10, 20, 2, 24, 0) for relative in COMPOSER_REPAIR_FILES
})
CALENDAR_RUNTIME_FILES = {
    'app/Views/admin/media-center.php',
    'app/Core/MarketingCalendar.php',
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
}
SCENE_SCHEDULE_RUNTIME_FILES = {
    'app/Core/SceneSchedule.php',
    'app/Core/SceneScheduleStoreTrait.php',
    'app/Console/run_scenes.php',
    'app/Support/scene_schedule_admin.php',
    'app/Views/admin/scene-schedules-actions.php',
    'app/Views/admin/scene-schedules.php',
    'public/assets/css/scene-schedules.css',
}
DEPTH_RUNTIME_FILES = {
    'app/Core/SceneDepth.php',
    'public/assets/js/scene-depth.js',
}
MENU_RUNTIME_FILES = {
    'admin/index.php',
    'app/Core/MenuService.php',
    'app/Core/MenuStoreTrait.php',
    'app/Core/Store.php',
    'app/Support/menu_admin.php',
    'app/Views/admin/menus-actions.php',
    'app/Views/admin/menus.php',
    'app/Views/admin/navigation.php',
    'app/Views/public/partials/footer.php',
    'app/Views/public/partials/header.php',
    'app/Views/public/partials/menu-items.php',
    'app/Views/public/partials/navigation.php',
    'public/assets/css/menu-admin.css',
    'public/assets/css/navigation.css',
    'public/assets/css/site-ux.css',
    'public/assets/js/menu-admin.js',
    'public/assets/js/navigation.js',
    'public/assets/js/site.js',
    'public/home.php',
}
V09_RUNTIME_FILES = {
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
}
# Retain the complete 0.9 release floor while advancing the payment candidate.
V09_RELEASE_FILES = {
    '.htaccess',
    'VERSION',
    'admin/addon-recovery.php',
    'admin/index.php',
    'admin/repair-current.php',
    'admin/repair.php',
    'app/Console/migrate.php',
    'app/Console/operations.php',
    'app/Console/run_analytics.php',
    'app/Console/run_backups.php',
    'app/Console/run_mail_events.php',
    'app/Console/run_notifications.php',
    'app/Console/run_privacy.php',
    'app/Core/AdminSecurity.php',
    'app/Core/AdminSecurityStoreTrait.php',
    'app/Core/AgeAccess.php',
    'app/Core/Auth.php',
    'app/Core/BackupArchive.php',
    'app/Core/BackupDatabase.php',
    'app/Core/BackupJobs.php',
    'app/Core/BackupRestore.php',
    'app/Core/BackupService.php',
    'app/Core/CampaignDeliveryService.php',
    'app/Core/CampaignMessage.php',
    'app/Core/CampaignTestService.php',
    'app/Core/CommerceService.php',
    'app/Core/CommerceStoreTrait.php',
    'app/Core/CommunicationStoreTrait.php',
    'app/Core/Config.php',
    'app/Core/EntitlementResolver.php',
    'app/Core/GeographicPolicy.php',
    'app/Core/MailProviderService.php',
    'app/Core/MailService.php',
    'app/Core/MarketingAnalytics.php',
    'app/Core/MarketingAnalyticsTrait.php',
    'app/Core/MarketingAttribution.php',
    'app/Core/MarketingAttributionAdminTrait.php',
    'app/Core/MarketingAttributionBridge.php',
    'app/Core/MarketingAttributionTrait.php',
    'app/Core/MarketingAudience.php',
    'app/Core/MarketingBusinessStoreTrait.php',
    'app/Core/MarketingCampaignMessages.php',
    'app/Core/MarketingMeasurementSqlRepository.php',
    'app/Core/MarketingMetadata.php',
    'app/Core/MarketingPrivacy.php',
    'app/Core/MarketingPrivacyErasure.php',
    'app/Core/MarketingProviderEvents.php',
    'app/Core/MarketingRecovery.php',
    'app/Core/MarketingSqlRepository.php',
    'app/Core/MarketingStorage.php',
    'app/Core/MarketingStore.php',
    'app/Core/MediaStoreTrait.php',
    'app/Core/MediaUploadService.php',
    'app/Core/MemberAuth.php',
    'app/Core/MemberPrivacyArchive.php',
    'app/Core/MemberPrivacyDomains.php',
    'app/Core/MemberPrivacyProjection.php',
    'app/Core/MemberPrivacyService.php',
    'app/Core/MemberPrivacyStoreTrait.php',
    'app/Core/MemberStoreTrait.php',
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
    'app/Core/RuntimeInventoryService.php',
    'app/Core/SendGridSignature.php',
    'app/Core/Store.php',
    'app/Core/Totp.php',
    'app/Core/UploadService.php',
    'app/Core/YotiAgeProvider.php',
    'app/Docs/BACKUP_OPERATIONS.md',
    'app/Docs/MEMBER_PRIVACY.md',
    'app/Support/functions.php',
    'app/Support/marketing_recovery_admin.php',
    'app/Support/member_privacy.php',
    'app/Support/operations_admin.php',
    'app/Support/policies_admin.php',
    'app/Support/protection_admin.php',
    'app/Support/public_routes.php',
    'app/Support/repair_entry.php',
    'app/Views/admin/campaign-message-preview.php',
    'app/Views/admin/communications.php',
    'app/Views/admin/login.php',
    'app/Views/admin/marketing-actions.php',
    'app/Views/admin/marketing-analytics.php',
    'app/Views/admin/marketing-attribution.php',
    'app/Views/admin/marketing-audiences.php',
    'app/Views/admin/marketing-campaign-form.php',
    'app/Views/admin/marketing-deleted-actions.php',
    'app/Views/admin/marketing-deleted.php',
    'app/Views/admin/marketing-email-brand.php',
    'app/Views/admin/marketing-link-bio.php',
    'app/Views/admin/marketing-measurement-actions.php',
    'app/Views/admin/marketing-notifications-actions.php',
    'app/Views/admin/marketing-notifications.php',
    'app/Views/admin/marketing-provider-actions.php',
    'app/Views/admin/marketing-provider.php',
    'app/Views/admin/marketing-publication-editor.php',
    'app/Views/admin/marketing-publication-list.php',
    'app/Views/admin/marketing.php',
    'app/Views/admin/media-library.php',
    'app/Views/admin/navigation.php',
    'app/Views/admin/operations.php',
    'app/Views/admin/policies-actions.php',
    'app/Views/admin/policies.php',
    'app/Views/admin/privacy.php',
    'app/Views/admin/protection-actions.php',
    'app/Views/admin/protection.php',
    'app/Views/admin/repair-backups.php',
    'app/Views/admin/repair-current-status.php',
    'app/Views/admin/repair-signin.php',
    'app/Views/admin/repair-status.php',
    'app/Views/admin/security-actions.php',
    'app/Views/admin/security.php',
    'app/Views/public/access.php',
    'app/Views/public/partials/footer.php',
    'app/Views/public/partials/header.php',
    'app/Views/public/partials/legal-links.php',
    'app/bootstrap.php',
    'database/migrations/202610210016_marketing_measurements.php',
    'database/migrations/202610210017_private_state.php',
    'includes/age_access.php',
    'includes/bootstrap.php',
    'includes/marketing.php',
    'includes/marketing_admin.php',
    'includes/runtime-build.json',
    'includes/version.php',
    'public/assets/css/admin-ux.css',
    'public/assets/css/marketing-deleted.css',
    'public/assets/css/marketing.css',
    'public/assets/css/member-privacy.css',
    'public/assets/css/member.css',
    'public/assets/css/navigation.css',
    'public/assets/css/operations.css',
    'public/assets/css/policies.css',
    'public/assets/css/privacy-admin.css',
    'public/assets/css/protection-public.css',
    'public/assets/css/protection.css',
    'public/assets/css/security-admin.css',
    'public/assets/css/site-ux.css',
    'public/assets/css/site.css',
    'public/assets/js/admin-ux.js',
    'public/assets/js/admin.js',
    'public/assets/js/navigation.js',
    'public/assets/js/site.js',
    'public/home.php',
    'public/routes/account/index.php',
    'public/routes/account/privacy.php',
    'public/routes/account/register.php',
    'public/routes/account/security.php',
    'public/routes/age/index.php',
    'public/routes/api/mail-events.php',
    'public/routes/api/marketing-event.php',
    'public/routes/campaign/click.php',
    'public/routes/campaign/index.php',
    'public/routes/communication/download.php',
    'public/routes/communication/privacy-download.php',
    'public/routes/links/index.php',
    'public/routes/media.php',
    'public/routes/messages/index.php',
    'public/routes/newsletter/confirm.php',
    'public/routes/newsletter/index.php',
    'public/routes/photos/view.php',
    'public/routes/policies/index.php',
    'public/routes/privacy/choices.php',
    'public/routes/protected-asset.php',
    'public/routes/reports/index.php',
    'public/routes/requests/index.php',
    'public/routes/videos/watch.php',
    'storage/private/.htaccess',
}
V091_RUNTIME_FILES = {
    'app/Core/PaymentCommerceTrait.php',
    'app/Core/PaymentOperationService.php',
    'app/Core/PaymentRecoveryAuthorizationException.php',
    'app/Core/PaymentRecoveryService.php',
    'app/Core/PaymentStoreTrait.php',
    'app/Payments/BoundPaymentProviderInterface.php',
    'app/Payments/PaymentContract.php',
    'app/Support/payment_recovery_admin.php',
    'app/Views/admin/payment-recovery-actions.php',
    'app/Views/admin/payment-recovery.php',
    'database/migrations/202610220018_payment_journal.php',
    'public/assets/css/payment-recovery.css',
}
PAYMENT_RUNTIME_FILES = {
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
}
V093_RUNTIME_FILES = {'app/Core/RecurringCommerceTrait.php',
 'app/Core/RecurringContract.php',
 'app/Core/RecurringRecoveryService.php',
 'app/Core/RecurringStoreTrait.php',
 'app/Payments/RecurringProviderInterface.php',
 'app/Views/admin/recurring-recovery.php',
 'app/Views/public/partials/recurring-membership.php',
 'database/migrations/202610220019_recurring_lifecycle.php'}
V092_RUNTIME_FILES = {'app/Core/PurchasedTerms.php',
 'app/Core/PurchasedTermsStoreTrait.php',
 'app/Views/public/partials/checkout-terms.php',
 'app/Views/public/partials/purchased-membership.php'}
PRIOR_RELEASE_TIMESTAMPS = {'.htaccess': (2026, 10, 21, 20, 0, 0),
 'VERSION': (2026, 10, 22, 20, 0, 0),
 'admin/addon-recovery.php': (2026, 10, 21, 20, 0, 0),
 'admin/index.php': (2026, 10, 22, 0, 0, 0),
 'admin/repair-current.php': (2026, 10, 21, 20, 0, 0),
 'admin/repair.php': (2026, 10, 21, 20, 0, 0),
 'admin/update.php': (2026, 10, 20, 2, 24, 0),
 'app/Console/migrate.php': (2026, 10, 21, 20, 0, 0),
 'app/Console/operations.php': (2026, 10, 21, 20, 0, 0),
 'app/Console/run_analytics.php': (2026, 10, 21, 20, 0, 0),
 'app/Console/run_backups.php': (2026, 10, 21, 20, 0, 0),
 'app/Console/run_mail_events.php': (2026, 10, 21, 20, 0, 0),
 'app/Console/run_marketing.php': (2026, 10, 20, 2, 24, 0),
 'app/Console/run_media.php': (2026, 10, 20, 3, 48, 0),
 'app/Console/run_notifications.php': (2026, 10, 21, 20, 0, 0),
 'app/Console/run_privacy.php': (2026, 10, 21, 20, 0, 0),
 'app/Console/run_scenes.php': (2026, 10, 21, 16, 0, 0),
 'app/Core/AddonLicenseClient.php': (2026, 10, 20, 2, 24, 0),
 'app/Core/AddonManager.php': (2026, 10, 20, 5, 0, 0),
 'app/Core/AddonMigrationException.php': (2026, 10, 20, 5, 0, 0),
 'app/Core/AddonMigrationPlan.php': (2026, 10, 20, 5, 0, 0),
 'app/Core/AddonMigrationRecovery.php': (2026, 10, 20, 5, 0, 0),
 'app/Core/AddonRuntime.php': (2026, 10, 22, 0, 0, 0),
 'app/Core/AddonStoreTrait.php': (2026, 10, 20, 5, 0, 0),
 'app/Core/AdminSecurity.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/AdminSecurityStoreTrait.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/AgeAccess.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/Auth.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/BackupArchive.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/BackupDatabase.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/BackupJobs.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/BackupRestore.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/BackupService.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/CampaignDeliveryService.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/CampaignMessage.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/CampaignTestService.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/CommerceService.php': (2026, 10, 22, 20, 0, 0),
 'app/Core/CommerceStoreTrait.php': (2026, 10, 22, 20, 0, 0),
 'app/Core/CommunicationStoreTrait.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/Config.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/EmailAddress.php': (2026, 10, 20, 2, 24, 0),
 'app/Core/EntitlementResolver.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/GeographicPolicy.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MailDeliveryException.php': (2026, 10, 20, 2, 24, 0),
 'app/Core/MailProviderService.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MailService.php': (2026, 10, 22, 0, 0, 0),
 'app/Core/MailSettingsService.php': (2026, 10, 20, 2, 24, 0),
 'app/Core/MarketingAccess.php': (2026, 10, 20, 17, 20, 22),
 'app/Core/MarketingAnalytics.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingAnalyticsTrait.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingAttribution.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingAttributionAdminTrait.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingAttributionBridge.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingAttributionTrait.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingAudience.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingBusinessStoreTrait.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingCalendar.php': (2026, 10, 21, 16, 0, 0),
 'app/Core/MarketingCampaignMessages.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingHistory.php': (2026, 10, 21, 8, 37, 20),
 'app/Core/MarketingMeasurementSqlRepository.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingMetadata.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingPrivacy.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingPrivacyErasure.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingProviderEvents.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingPublication.php': (2026, 10, 21, 8, 37, 20),
 'app/Core/MarketingRecovery.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingSqlRepository.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingStorage.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MarketingStorageException.php': (2026, 10, 20, 14, 16, 8),
 'app/Core/MarketingStore.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MediaAdminService.php': (2026, 10, 20, 3, 48, 0),
 'app/Core/MediaInput.php': (2026, 10, 20, 3, 48, 0),
 'app/Core/MediaStoreTrait.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MediaUploadService.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MemberAuth.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MemberPrivacyArchive.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MemberPrivacyDomains.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/MemberPrivacyProjection.php': (2026, 10, 22, 20, 0, 0),
 'app/Core/MemberPrivacyService.php': (2026, 10, 22, 0, 0, 0),
 'app/Core/MemberPrivacyStoreTrait.php': (2026, 10, 22, 0, 0, 0),
 'app/Core/MemberStoreTrait.php': (2026, 10, 22, 20, 0, 0),
 'app/Core/MenuService.php': (2026, 10, 21, 0, 41, 28),
 'app/Core/MenuStoreTrait.php': (2026, 10, 21, 0, 41, 28),
 'app/Core/MerchandiseStoreTrait.php': (2026, 10, 22, 0, 0, 0),
 'app/Core/MigrationResumeSchema.php': (2026, 10, 19, 16, 0, 0),
 'app/Core/MigrationRunner.php': (2026, 10, 19, 16, 0, 0),
 'app/Core/NotificationDiscovery.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/NotificationProviderEvents.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/NotificationService.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/NotificationStoreTrait.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/OperationsFiles.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/OperationsGate.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/PaymentCommerceTrait.php': (2026, 10, 22, 0, 0, 0),
 'app/Core/PaymentOperationService.php': (2026, 10, 22, 0, 0, 0),
 'app/Core/PaymentRecoveryAuthorizationException.php': (2026, 10, 22, 0, 0, 0),
 'app/Core/PaymentRecoveryService.php': (2026, 10, 22, 0, 0, 0),
 'app/Core/PaymentStoreTrait.php': (2026, 10, 22, 0, 0, 0),
 'app/Core/PrivateCommunicationFile.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/PrivateMediaResponse.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/PrivateState.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/ProtectionDelivery.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/ProtectionRecords.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/ProtectionResources.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/ProtectionStoreTrait.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/ProtectionUploads.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/PublicPolicies.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/PurchasedTerms.php': (2026, 10, 22, 20, 0, 0),
 'app/Core/PurchasedTermsStoreTrait.php': (2026, 10, 22, 20, 0, 0),
 'app/Core/RepairAccess.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/RepairBackupService.php': (2026, 10, 20, 2, 24, 0),
 'app/Core/RootLayoutService.php': (2026, 10, 20, 2, 24, 0),
 'app/Core/RuntimeBuildService.php': (2026, 10, 20, 2, 24, 0),
 'app/Core/RuntimeInventoryService.php': (2026, 10, 22, 20, 0, 0),
 'app/Core/SceneButton.php': (2026, 10, 20, 2, 24, 0),
 'app/Core/SceneDepth.php': (2026, 10, 21, 5, 46, 42),
 'app/Core/SceneSchedule.php': (2026, 10, 21, 16, 0, 0),
 'app/Core/SceneScheduleStoreTrait.php': (2026, 10, 21, 16, 0, 0),
 'app/Core/SendGridSignature.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/Store.php': (2026, 10, 22, 20, 0, 0),
 'app/Core/Totp.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/UploadService.php': (2026, 10, 21, 20, 0, 0),
 'app/Core/YotiAgeProvider.php': (2026, 10, 21, 20, 0, 0),
 'app/Docs/BACKUP_OPERATIONS.md': (2026, 10, 21, 20, 0, 0),
 'app/Docs/MEMBER_PRIVACY.md': (2026, 10, 21, 20, 0, 0),
 'app/Payments/BoundPaymentProviderInterface.php': (2026, 10, 22, 0, 0, 0),
 'app/Payments/PaymentContract.php': (2026, 10, 22, 0, 0, 0),
 'app/Payments/SandboxPaymentProvider.php': (2026, 10, 22, 0, 0, 0),
 'app/Support/functions.php': (2026, 10, 21, 20, 0, 0),
 'app/Support/mail_settings_admin.php': (2026, 10, 20, 2, 24, 0),
 'app/Support/marketing_recovery_admin.php': (2026, 10, 21, 20, 0, 0),
 'app/Support/marketing_storage_admin.php': (2026, 10, 20, 14, 16, 8),
 'app/Support/media_admin.php': (2026, 10, 20, 3, 48, 0),
 'app/Support/member_privacy.php': (2026, 10, 21, 20, 0, 0),
 'app/Support/menu_admin.php': (2026, 10, 21, 0, 41, 28),
 'app/Support/operations_admin.php': (2026, 10, 21, 20, 0, 0),
 'app/Support/payment_recovery_admin.php': (2026, 10, 22, 0, 0, 0),
 'app/Support/policies_admin.php': (2026, 10, 21, 20, 0, 0),
 'app/Support/protection_admin.php': (2026, 10, 21, 20, 0, 0),
 'app/Support/public_routes.php': (2026, 10, 21, 20, 0, 0),
 'app/Support/repair_entry.php': (2026, 10, 21, 20, 0, 0),
 'app/Support/scene_schedule_admin.php': (2026, 10, 21, 16, 0, 0),
 'app/Views/admin/addon-licensing.php': (2026, 10, 20, 3, 48, 0),
 'app/Views/admin/addons.php': (2026, 10, 20, 5, 0, 0),
 'app/Views/admin/campaign-message-preview.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/commerce.php': (2026, 10, 22, 0, 0, 0),
 'app/Views/admin/communications.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/composer.php': (2026, 10, 21, 16, 0, 0),
 'app/Views/admin/login.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/mail-settings-actions.php': (2026, 10, 20, 2, 24, 0),
 'app/Views/admin/mail-settings.php': (2026, 10, 20, 2, 24, 0),
 'app/Views/admin/marketing-actions.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-analytics.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-attribution.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-audiences.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-calendar.php': (2026, 10, 21, 16, 0, 0),
 'app/Views/admin/marketing-campaign-form.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-deleted-actions.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-deleted.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-email-brand.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-history.php': (2026, 10, 21, 8, 37, 20),
 'app/Views/admin/marketing-link-bio.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-measurement-actions.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-notifications-actions.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-notifications.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-preview.php': (2026, 10, 20, 19, 9, 10),
 'app/Views/admin/marketing-provider-actions.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-provider.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-publication-editor.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-publication-list.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/marketing-storage-actions.php': (2026, 10, 20, 14, 16, 8),
 'app/Views/admin/marketing-storage.php': (2026, 10, 20, 14, 16, 8),
 'app/Views/admin/marketing.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/media-center.php': (2026, 10, 21, 8, 37, 20),
 'app/Views/admin/media-library.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/members.php': (2026, 10, 20, 3, 48, 0),
 'app/Views/admin/menus-actions.php': (2026, 10, 21, 0, 41, 28),
 'app/Views/admin/menus.php': (2026, 10, 21, 0, 41, 28),
 'app/Views/admin/navigation.php': (2026, 10, 22, 0, 0, 0),
 'app/Views/admin/operations.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/payment-recovery-actions.php': (2026, 10, 22, 0, 0, 0),
 'app/Views/admin/payment-recovery.php': (2026, 10, 22, 0, 0, 0),
 'app/Views/admin/policies-actions.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/policies.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/privacy.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/protection-actions.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/protection.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/repair-backups.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/repair-current-status.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/repair-signin.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/repair-status.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/scene-schedules-actions.php': (2026, 10, 21, 16, 0, 0),
 'app/Views/admin/scene-schedules.php': (2026, 10, 21, 16, 0, 0),
 'app/Views/admin/security-actions.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/security.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/admin/update-status.php': (2026, 10, 20, 2, 24, 0),
 'app/Views/public/access.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/public/partials/checkout-terms.php': (2026, 10, 22, 20, 0, 0),
 'app/Views/public/partials/footer.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/public/partials/header.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/public/partials/legal-links.php': (2026, 10, 21, 20, 0, 0),
 'app/Views/public/partials/menu-items.php': (2026, 10, 21, 0, 41, 28),
 'app/Views/public/partials/navigation.php': (2026, 10, 21, 0, 41, 28),
 'app/Views/public/partials/purchased-membership.php': (2026, 10, 22, 20, 0, 0),
 'app/Views/public/partials/scene-buttons.php': (2026, 10, 20, 2, 24, 0),
 'app/bootstrap.php': (2026, 10, 21, 20, 0, 0),
 'config/app.example.php': (2026, 10, 20, 3, 48, 0),
 'database/migrations/202610200015_marketing_sql_storage.php': (2026, 10, 20, 14, 16, 8),
 'database/migrations/202610210016_marketing_measurements.php': (2026, 10, 21, 20, 0, 0),
 'database/migrations/202610210017_private_state.php': (2026, 10, 21, 20, 0, 0),
 'database/migrations/202610220018_payment_journal.php': (2026, 10, 22, 0, 0, 0),
 'includes/age_access.php': (2026, 10, 21, 20, 0, 0),
 'includes/bootstrap.php': (2026, 10, 21, 20, 0, 0),
 'includes/installation_recovery.php': (2026, 10, 19, 16, 0, 0),
 'includes/marketing.php': (2026, 10, 21, 20, 0, 0),
 'includes/marketing_admin.php': (2026, 10, 21, 20, 0, 0),
 'includes/marketing_public_shell.php': (2026, 10, 20, 19, 9, 10),
 'includes/runtime-build.json': (2026, 10, 22, 20, 0, 0),
 'includes/version.php': (2026, 10, 22, 20, 0, 0),
 'install/index.php': (2026, 10, 20, 2, 24, 0),
 'install/services/EmailAddress.php': (2026, 10, 20, 2, 24, 0),
 'install/services/FileSystemService.php': (2026, 10, 19, 0, 0, 0),
 'install/services/InstallerService.php': (2026, 10, 20, 3, 48, 0),
 'install/services/SmtpTester.php': (2026, 10, 20, 2, 24, 0),
 'install/views/single-page.php': (2026, 10, 20, 3, 48, 0),
 'public/assets/css/admin-ux.css': (2026, 10, 21, 20, 0, 0),
 'public/assets/css/composer.css': (2026, 10, 21, 5, 46, 42),
 'public/assets/css/installer.css': (2026, 10, 19, 16, 0, 0),
 'public/assets/css/mail-settings.css': (2026, 10, 20, 2, 24, 0),
 'public/assets/css/marketing-calendar.css': (2026, 10, 21, 8, 37, 20),
 'public/assets/css/marketing-deleted.css': (2026, 10, 21, 20, 0, 0),
 'public/assets/css/marketing.css': (2026, 10, 21, 20, 0, 0),
 'public/assets/css/media-admin-edit.css': (2026, 10, 20, 3, 48, 0),
 'public/assets/css/member-privacy.css': (2026, 10, 21, 20, 0, 0),
 'public/assets/css/member.css': (2026, 10, 21, 20, 0, 0),
 'public/assets/css/menu-admin.css': (2026, 10, 21, 0, 41, 28),
 'public/assets/css/navigation.css': (2026, 10, 21, 20, 0, 0),
 'public/assets/css/operations.css': (2026, 10, 21, 20, 0, 0),
 'public/assets/css/payment-recovery.css': (2026, 10, 22, 0, 0, 0),
 'public/assets/css/policies.css': (2026, 10, 21, 20, 0, 0),
 'public/assets/css/privacy-admin.css': (2026, 10, 21, 20, 0, 0),
 'public/assets/css/protection-public.css': (2026, 10, 21, 20, 0, 0),
 'public/assets/css/protection.css': (2026, 10, 21, 20, 0, 0),
 'public/assets/css/scene-schedules.css': (2026, 10, 21, 16, 0, 0),
 'public/assets/css/security-admin.css': (2026, 10, 21, 20, 0, 0),
 'public/assets/css/site-ux.css': (2026, 10, 21, 20, 0, 0),
 'public/assets/css/site.css': (2026, 10, 21, 20, 0, 0),
 'public/assets/js/admin-media.js': (2026, 10, 20, 3, 48, 0),
 'public/assets/js/admin-ux.js': (2026, 10, 21, 20, 0, 0),
 'public/assets/js/admin.js': (2026, 10, 21, 20, 0, 0),
 'public/assets/js/api-client.js': (2026, 10, 20, 2, 24, 0),
 'public/assets/js/composer.js': (2026, 10, 21, 16, 0, 0),
 'public/assets/js/installer.js': (2026, 10, 19, 16, 0, 0),
 'public/assets/js/mail-settings.js': (2026, 10, 20, 2, 24, 0),
 'public/assets/js/marketing-editorial.js': (2026, 10, 20, 19, 9, 10),
 'public/assets/js/marketing.js': (2026, 10, 20, 2, 24, 0),
 'public/assets/js/media-center.js': (2026, 10, 20, 23, 38, 0),
 'public/assets/js/menu-admin.js': (2026, 10, 21, 0, 41, 28),
 'public/assets/js/navigation.js': (2026, 10, 21, 20, 0, 0),
 'public/assets/js/scene-depth.js': (2026, 10, 21, 5, 46, 42),
 'public/assets/js/scene-geometry.js': (2026, 10, 20, 23, 38, 0),
 'public/assets/js/site.js': (2026, 10, 21, 20, 0, 0),
 'public/home.php': (2026, 10, 21, 20, 0, 0),
 'public/router.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/about/index.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/account/billing.php': (2026, 10, 22, 20, 0, 0),
 'public/routes/account/forgot.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/account/index.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/account/library.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/account/login.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/account/logout.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/account/privacy.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/account/register.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/account/reset.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/account/security.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/account/verify.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/age/index.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/api/addon-license.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/api/mail-events.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/api/marketing-event.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/api/member-state.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/api/payment-webhook.php': (2026, 10, 22, 0, 0, 0),
 'public/routes/api/scene.php': (2026, 10, 21, 16, 0, 0),
 'public/routes/api/upload.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/blog/index.php': (2026, 10, 20, 19, 9, 10),
 'public/routes/blog/post.php': (2026, 10, 20, 19, 9, 10),
 'public/routes/booking/index.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/campaign/click.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/campaign/index.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/cart/index.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/checkout/index.php': (2026, 10, 22, 20, 0, 0),
 'public/routes/checkout/success.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/collections/index.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/communication/download.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/communication/privacy-download.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/extension/index.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/links/index.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/media.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/membership/index.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/messages/index.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/music/index.php': (2026, 10, 20, 3, 48, 0),
 'public/routes/newsletter/confirm.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/newsletter/index.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/newsletter/unsubscribe.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/photos/index.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/photos/view.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/policies/index.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/privacy/choices.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/protected-asset.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/receipt/index.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/reports/index.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/requests/index.php': (2026, 10, 21, 20, 0, 0),
 'public/routes/shop/index.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/videos/index.php': (2026, 10, 20, 2, 24, 0),
 'public/routes/videos/watch.php': (2026, 10, 21, 20, 0, 0),
 'route.php': (2026, 10, 20, 2, 24, 0),
 'storage/private/.htaccess': (2026, 10, 21, 20, 0, 0)}
RELEASE_TIMESTAMP = (2026, 10, 22, 22, 30, 0)
RELEASE_FILES = {
    'VERSION',
    'app/Core/CommerceService.php',
    'app/Core/MarketingBusinessStoreTrait.php',
    'app/Core/MemberPrivacyProjection.php',
    'app/Core/MemberPrivacyService.php',
    'app/Core/MemberPrivacyStoreTrait.php',
    'app/Core/MemberStoreTrait.php',
    'app/Core/PaymentCommerceTrait.php',
    'app/Core/RecurringCommerceTrait.php',
    'app/Core/RecurringContract.php',
    'app/Core/RecurringRecoveryService.php',
    'app/Core/RecurringStoreTrait.php',
    'app/Core/RuntimeInventoryService.php',
    'app/Core/Store.php',
    'app/Payments/RecurringProviderInterface.php',
    'app/Payments/SandboxPaymentProvider.php',
    'app/Support/payment_recovery_admin.php',
    'app/Views/admin/payment-recovery-actions.php',
    'app/Views/admin/payment-recovery.php',
    'app/Views/admin/recurring-recovery.php',
    'app/Views/public/partials/recurring-membership.php',
    'database/migrations/202610220019_recurring_lifecycle.php',
    'includes/runtime-build.json',
    'includes/version.php',
    'public/routes/account/billing.php',
}
REPAIR_TIMESTAMPS.update({
    relative: (2026, 10, 20, 2, 24, 0) for relative in {
        "install/index.php", "install/services/EmailAddress.php",
        "install/services/SmtpTester.php", "install/services/InstallerService.php",
    }
})
REPAIR_TIMESTAMPS.update(PRIOR_RELEASE_TIMESTAMPS)
REPAIR_TIMESTAMPS.update({relative: RELEASE_TIMESTAMP for relative in RELEASE_FILES})


def metadata(entry) -> tuple:
    return entry.date_time, entry.external_attr, entry.create_system, entry.compress_type


def installer_identity(site: Path) -> dict:
    """Execute the actual packaged view and version verification in a fresh PHP process.

    The deliberately invalid DSN is rejected before networking. A successful
    canonical-version check must reach that next verification stage, while the
    wrong version must fail earlier. This does not substitute for MySQL install.
    """
    code = r'''
define('ROOT_PATH', $argv[1]);
$_SERVER['REQUEST_METHOD'] = 'GET';
require ROOT_PATH . '/install/bootstrap.php';
$installed = false; $lockData = []; $systemChecks = []; $fileResults = [];
$requirementsReady = true; $installId = 'version-fixture';
$baseGuess = 'http://127.0.0.1'; $error = ''; $notice = ''; $form = [];
ob_start(); require ROOT_PATH . '/install/views/single-page.php'; $html = ob_get_clean();
preg_match('/<span class="version-chip">Version ([^<]+)<\/span>/', $html, $match);
$template = require ROOT_PATH . '/config/app.example.php';
copy(ROOT_PATH . '/config/app.example.php', ROOT_PATH . '/config/app.php');
$copiedTemplate = require ROOT_PATH . '/config/app.php';
$service = new \Velvet\Installer\InstallerService(ROOT_PATH,
    new \Velvet\Installer\InstallerLog(ROOT_PATH, 'version-fixture'));
$verify = new ReflectionMethod($service, 'verifyBoot');
$verify->setAccessible(true);
$config = ['installed' => true, 'installation_id' => 'version-fixture',
    'base_url' => 'http://127.0.0.1', 'db' => ['host' => 'invalid;host'],
    'security' => ['app_key' => str_repeat('v', 48), 'signing_key' => str_repeat('v', 48), 'media_key' => str_repeat('v', 48)]];
$messages = [];
foreach (['0.0.0', VELVET_VIXXEN_VERSION] as $version) {
    $config['version'] = $version;
    file_put_contents(ROOT_PATH . '/config/app.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    try { $verify->invoke($service, $config); $messages[] = ''; }
    catch (RuntimeException $error) { $messages[] = $error->getMessage(); }
}
echo json_encode(['file_version' => trim(file_get_contents(ROOT_PATH . '/VERSION')),
    'template_version' => $template['version'], 'copied_template_version' => $copiedTemplate['version'],
    'runtime_version' => VELVET_VIXXEN_VERSION, 'build_id' => VELVET_VIXXEN_BUILD_ID,
    'asset_version' => VELVET_VIXXEN_ASSET_VERSION, 'display_version' => $match[1] ?? '',
    'wrong_version_rejected' => str_contains($messages[0], 'wrong application version'),
    'canonical_version_verified' => str_contains($messages[1], 'could not reconnect')], JSON_THROW_ON_ERROR);
'''
    result = subprocess.run(["php", "-r", code, str(site)], text=True, capture_output=True, timeout=30)
    assert result.returncode == 0, result.stderr + result.stdout
    identity = json.loads(result.stdout)
    assert identity["file_version"] == identity["runtime_version"] == identity["display_version"] == identity["template_version"] == identity["copied_template_version"], "The actual installer page or copied configuration template differs from the packaged canonical version"
    assert identity["asset_version"] == f'{identity["runtime_version"]}-{identity["build_id"]}', "Executed PHP asset identity differs from its release/build"
    assert identity["wrong_version_rejected"] and identity["canonical_version_verified"], "Installer startup verification still uses a pinned release instead of the site's canonical version"
    return identity


def run() -> None:
    prior_release = json.loads((ROOT / "tests/Packaging/fixtures/release-0.8.14-build-20261021.3.json").read_text())
    assert prior_release["source_commit"] == "e339a230757ad9fb7529bf0c1fc73e9f60af446a"
    assert prior_release["version"] == "0.8.14" and prior_release["build_id"] == "20261021.3"
    assert prior_release["sha256"] == "5365f363d4448ad40201a3db9e59f3b1c8573031126c6bbab16ababa22be84fb"
    assert prior_release["files"] == len(prior_release["entries"]) == 385, "Prior-release fixture must retain the exact delivered Fresh inventory"
    historical_prior_release = prior_release
    prior_release = json.loads((ROOT / "tests/Packaging/fixtures/release-0.9.0-build-20261021.4.json").read_text())
    assert prior_release["source_commit"] == "6e9a670c64aa02213a4f91eb619f2cf7500efde0"
    assert prior_release["source_tree"] == "77c0bcb8b076ff8eabb867881990bc477621ba64"
    assert prior_release["version"] == "0.9.0" and prior_release["build_id"] == "20261021.4"
    assert prior_release["sha256"] == "7f633f01ebd59068acbcbe63e7f191882ef454e129bf668dab87093a43881798"
    assert prior_release["files"] == len(prior_release["entries"]) == 494
    assert set(prior_release["entries"]) - set(historical_prior_release["entries"]) == V09_RUNTIME_FILES
    assert {name for name, entry in prior_release["entries"].items()
            if entry["sha256"] != historical_prior_release["entries"].get(name, {}).get("sha256")} == V09_RELEASE_FILES
    prior_release = json.loads((ROOT / "tests/Packaging/fixtures/release-0.9.1-build-20261022.1.json").read_text())
    assert prior_release["source_commit"] == "2b66fc3c6b3409cf94086df6430ee060b24ce0ea"
    assert prior_release["sha256"] == "27a4bc4b084f0bff4187744704c043d32a8f2891f3eca96da4c7c1473432754d" and prior_release["files"] == 506
    prior_release = json.loads((ROOT / "tests/Packaging/fixtures/release-0.9.2-build-20261022.2.json").read_text())
    assert prior_release["source_commit"] == "e932fea2e8894c692d66e9994541edec9c1aa6ec"
    assert prior_release["sha256"] == "66bef99d5a436c86ef3233f80f3e96f845360d2cda9870a68532e1890ba4d9a3" and prior_release["files"] == 510
    historical = json.loads((ROOT / "tests/Packaging/fixtures/historical-runtime-layouts.json").read_text())
    assert historical["schema"] == 1
    baselines = historical["upgrade_baselines"]
    assert [baseline["source_commit"] for baseline in baselines] == VERIFIED_BASELINES
    assert [(baseline["files"], baseline["sha256"]) for baseline in baselines] == [
        (273, "e7f6c52d4d7de217b647e41f44827381cfe4a86c58b794947a5a0fd8bfa57028"),
        (321, "234b7b4379e8418fb4d862d9eb5c4dd25081a6bb22188d30922488dbb24599c6"),
        (325, "080e91477917171cf3bbc4438101507ac9bf9d597db61727797a82aa738b027d"),
    ], "Historical fixture no longer identifies the actual delivered Upgrade packages"
    rejected_fresh = historical["rejected_build5_fresh"]
    assert rejected_fresh["sha256"] == "dcd89db762e58541160ffdcdf4901d0bb5f07c9f3c0d66b99a6480701006bfc9"
    assert len(rejected_fresh["entry_paths"]) == rejected_fresh["files"] == 366
    assert package.ROOT_FILES == APPROVED_ROOT_FILES, "Package root file allowlist was expanded"
    assert package.RUNTIME_ROOTS == APPROVED_FRESH_ROOTS, "Package root directory allowlist was expanded"
    for kind, paths in [("fresh", rejected_fresh["entry_paths"]), ("upgrade", baselines[-1]["entry_sha256"])]:
        directories = {name.split("/", 1)[0] for name in paths if "/" in name}
        expected = APPROVED_FRESH_ROOTS if kind == "fresh" else APPROVED_UPGRADE_ROOTS
        assert directories - expected == REJECTED_BUILD5_ROOTS and "media.php" in paths
        try:
            package.validate_runtime_layout(paths, kind)
        except ValueError as error:
            assert "unapproved root entries" in str(error) and "media.php" in str(error)
            assert all(directory in str(error) for directory in REJECTED_BUILD5_ROOTS)
        else:
            raise AssertionError("The actual delivered Build .5 root-directory regression passed the restored layout gate")
    for baseline in baselines:
        assert len(baseline["entry_sha256"]) == baseline["files"]
    with tempfile.TemporaryDirectory(prefix="vvx-package-test-") as temporary:
        work = Path(temporary)
        source = work / "source"
        source.mkdir()
        for relative, content in package.runtime_files(ROOT, "fresh").items():
            target = source / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content)
        stale_inventory = b'{"fixture":"STALE INVENTORY MUST BE REGENERATED"}\n'
        (source / RUNTIME_BUILD_PATH).write_bytes(stale_inventory)

        protected = {
            "config/app.php": b"<?php return ['installed'=>true,'installation_id'=>'test-installation'];\n",
            "storage/install.lock": b'{"installed":true,"installation_id":"test-installation"}\n',
            "storage/data.json": b'{"fixture":"existing member and order data"}\n',
            "storage/private/member-proof.txt": b"PRIVATE FIXTURE MUST NEVER SHIP\n",
            "storage/logs/production.log": b"PRIVATE LOG FIXTURE\n",
            "storage/sessions/session-test": b"PRIVATE SESSION FIXTURE\n",
            "uploads/images/customer-photo.bin": b"UNIQUE UPLOAD FIXTURE\n",
            "config/app.php.previous": b"PREVIOUS CONFIG FIXTURE\n",
            "database/backups/customer-data.sql": b"PRIVATE DATABASE BACKUP FIXTURE\n",
        }
        for relative, content in protected.items():
            target = source / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content)

        version = (source / "VERSION").read_text().strip()
        first = package.build(source, work / "first", version)
        second = package.build(source, work / "second", version)
        with patch.object(package, "INSTALLER_FILESYSTEM_TIMESTAMP", LEGACY_TIMESTAMP), \
                patch.object(package, "INSTALLER_DATABASE_TIMESTAMP", LEGACY_TIMESTAMP), \
                patch.object(package, "INSTALLER_MAIL_TIMESTAMP", LEGACY_TIMESTAMP), \
                patch.object(package, "COMPOSER_REPAIR_TIMESTAMP", LEGACY_TIMESTAMP), \
                patch.object(package, "RELEASE_TIMESTAMP", LEGACY_TIMESTAMP), \
                patch.object(package, "PRIOR_RELEASE_TIMESTAMPS", {name: LEGACY_TIMESTAMP for name in PRIOR_RELEASE_TIMESTAMPS}):
            legacy_dates = package.build(source, work / "legacy-dates", version)
        releases = {release["kind"]: release for release in first["packages"]}
        assert first["build_id"] == BUILD_ID == second["build_id"], "Package build identity is missing or ambiguous"
        assert first["manifest_filename"] == f"Velvet-Vixxen-{version}-Build-{BUILD_ID}-package-manifest.json"
        assert json.loads((work / "first" / first["manifest_filename"]).read_text()) == first, "Separate manifest differs from the returned build inventory"
        assert (source / RUNTIME_BUILD_PATH).read_bytes() == stale_inventory, "Packaging wrote generated state back into its source checkout"
        assert tuple(map(int, version.split("."))) > tuple(map(int, prior_release["version"].split("."))), "A new release must advance the visible version beyond the exact previous delivered release"
        assert set(releases) == {"fresh", "upgrade", "installer-repair"}, "Require two full current-version distributions and the explicit 0.8.1 unfinished-installer overlay; no unsupported focused Media repair"
        assert package.INSTALLER_REPAIR_FILES == REPAIR_FILES, "Installer repair dependencies differ from the reviewed allowlist"
        assert package.PUBLIC_HANDLER_FILES == PUBLIC_HANDLER_FILES, "Organized public handlers differ from the reviewed route scope"
        assert package.COMPOSER_REPAIR_FILES == COMPOSER_REPAIR_FILES, "Media and Composer repair dependencies differ from the reviewed allowlist"
        assert package.EDITORIAL_RUNTIME_FILES == EDITORIAL_RUNTIME_FILES, "Editorial history/access/metadata dependencies differ from the reviewed allowlist"
        assert package.CALENDAR_RUNTIME_FILES == CALENDAR_RUNTIME_FILES, "Calendar dependencies differ from the reviewed allowlist"
        assert package.V09_RUNTIME_FILES == V09_RUNTIME_FILES, "0.9 dependencies differ from the reviewed allowlist"
        assert package.V09_RELEASE_FILES == V09_RELEASE_FILES, "Historical 0.9 changed-file floor differs from its frozen package"
        assert package.V091_RUNTIME_FILES == V091_RUNTIME_FILES and package.PAYMENT_RUNTIME_FILES == PAYMENT_RUNTIME_FILES, "Payment runtime dependencies differ from the reviewed allowlist"
        assert package.SCENE_SCHEDULE_RUNTIME_FILES == SCENE_SCHEDULE_RUNTIME_FILES, "Scene schedule dependencies differ from the reviewed allowlist"
        assert package.RELEASE_FILES == RELEASE_FILES and package.RELEASE_TIMESTAMP == RELEASE_TIMESTAMP
        assert package.PRIOR_RELEASE_TIMESTAMPS == PRIOR_RELEASE_TIMESTAMPS
        assert package.MENU_RUNTIME_FILES == MENU_RUNTIME_FILES
        with ZipFile(work / "first" / releases["fresh"]["filename"]) as fresh:
            fresh.extractall(work / "current-version")
        current_identity = installer_identity(work / "current-version")
        assert current_identity["runtime_version"] == version and current_identity["build_id"] == BUILD_ID
        installer_baseline = json.loads((ROOT / "tests/Packaging/fixtures/installer-mail-baseline.json").read_text())
        assert installer_baseline["source_commit"] == "6d9b5677d6374ddb1b6dd7a93c6d307f982b877a"
        assert set(installer_baseline["files"]) == REPAIR_FILES
        changed_installer = {
            name for name, digest in installer_baseline["files"].items()
            if hashlib.sha256((source / name).read_bytes()).hexdigest() != digest
        }
        assert set(installer_baseline["changed_sender_dependencies"]) == {
            "install/index.php", "install/services/EmailAddress.php",
            "install/services/InstallerService.php", "install/services/SmtpTester.php",
        } <= changed_installer, "The historical sender corrections are missing from the current installer"
        version_baseline = json.loads((ROOT / "tests/Packaging/fixtures/installer-version-baseline.json").read_text())
        assert version_baseline["source_commit"] == "7b5966108d7e311ce11d95bafb06abef4e9e4442"
        assert version_baseline["version"] == "0.8.1" and version_baseline["build_id"] == "20261020.2"
        changed_version_dependencies = {
            name for name, digest in version_baseline["files"].items()
            if hashlib.sha256((source / name).read_bytes()).hexdigest() != digest
        }
        assert changed_version_dependencies == {"install/services/InstallerService.php", "install/views/single-page.php"}, "Canonical-version repair changed outside its reviewed installer dependencies"
        with ZipFile(work / "first" / releases["upgrade"]["filename"]) as upgrade:
            expected_inventory = {
                "schema": 1, "product": "Velvet Vixxen", "version": version, "build_id": BUILD_ID,
                "files": {
                    name: hashlib.sha256(upgrade.read(name)).hexdigest()
                    for name in sorted(upgrade.namelist()) if name != RUNTIME_BUILD_PATH
                },
            }
            inventory_bytes = upgrade.read(RUNTIME_BUILD_PATH)
            assert json.loads(inventory_bytes) == expected_inventory, "Runtime inventory does not describe the complete actual Upgrade ZIP"
            assert inventory_bytes != stale_inventory, "A stale source inventory entered the package"
            assert len(expected_inventory["files"]) > len(COMPOSER_REPAIR_FILES), "Repair-only hashes cannot verify an unknown installed baseline"
            assert "app/Core/UploadService.php" in expected_inventory["files"], "Inventory omitted an unchanged upload dependency"
            assert RUNTIME_BUILD_PATH not in expected_inventory["files"], "Runtime inventory cannot hash itself"
            assert not any(Path(name).parts[0] in package.PRESERVED_UPGRADE_ROOTS or name.startswith("registry/")
                           for name in expected_inventory["files"]), "Runtime inventory includes installed or private state"
            for baseline in baselines:
                changed = {name for name, digest in expected_inventory["files"].items()
                           if baseline["entry_sha256"].get(name) != digest}
                assert "VERSION" in changed and changed - COMPOSER_REPAIR_FILES, "The old focused overlay must not be claimed as a complete cross-version upgrade"
        for release in first["packages"]:
            archive = work / "first" / release["filename"]
            assert archive.read_bytes() == (work / "second" / release["filename"]).read_bytes(), "Package is not reproducible"
            with ZipFile(archive) as zipped:
                entries = set(zipped.namelist())
                compatibility = json.loads(zipped.comment)
                assert release["version"] == compatibility["version"]
                if release["kind"] != "installer-repair":
                    assert release["version"] == version
                    assert release["build_id"] == compatibility["build_id"] == BUILD_ID
                    assert f"-{version}-Build-{BUILD_ID}-" in release["filename"], "Customer ZIP filename hides its actual build"
                    assert zipped.read(RUNTIME_BUILD_PATH) == inventory_bytes, "Fresh/Upgrade/Repair inventories must have identical bytes"
                    assert f"const VELVET_VIXXEN_BUILD_ID = '{BUILD_ID}';".encode() in zipped.read("includes/version.php")
                    assert f"const VELVET_VIXXEN_ASSET_VERSION = '{version}-{BUILD_ID}';".encode() in zipped.read("includes/version.php")
                else:
                    assert RUNTIME_BUILD_PATH not in entries and "build_id" not in compatibility and "build_id" not in release, "Installer-only repair was mislabeled as a new complete runtime build"
                assert not entries.intersection(protected), "Installed/private state was packaged"
                assert not any(name.startswith((".github/", "docs/", "tests/", "scripts/", "addons/sdk/")) for name in entries), "Development files entered runtime ZIP"
                directories = {name.split("/", 1)[0] for name in entries if "/" in name}
                root_files = {name for name in entries if "/" not in name}
                assert not directories.intersection(REJECTED_BUILD5_ROOTS) and "media.php" not in root_files, "Retired page/API entry points reintroduced root clutter"
                if release["kind"] in {"fresh", "upgrade"}:
                    approved = APPROVED_FRESH_ROOTS if release["kind"] == "fresh" else APPROVED_UPGRADE_ROOTS
                    assert directories == approved and root_files == APPROVED_ROOT_FILES, "Actual customer ZIP does not have the exact approved root layout"
                    assert PUBLIC_HANDLER_FILES <= entries, "Consolidated public handler was removed along with a root forwarding file"
                    assert EDITORIAL_RUNTIME_FILES <= entries, "Editorial history or a retained access/metadata dependency is missing from a full package"
                    assert MENU_RUNTIME_FILES <= entries, "The menu editor or a public navigation dependency is missing from a full package"
                    assert DEPTH_RUNTIME_FILES <= entries, "Scene depth validation or shared foreground rendering is missing from a full package"
                    assert CALENDAR_RUNTIME_FILES | SCENE_SCHEDULE_RUNTIME_FILES | V09_RUNTIME_FILES | PAYMENT_RUNTIME_FILES <= entries, "Calendar or scene schedule dependencies are missing from a full package"
                assert zipped.testzip() is None, "Archive integrity failure"
                assert all(not Path(name).is_absolute() and ".." not in Path(name).parts for name in entries), "Unsafe archive path"
                assert all(entry.create_system == 3 and entry.external_attr >> 16 == 0o100644 for entry in zipped.infolist()), "Runtime ZIP entries must be regular files with safe 0644 permissions"
                with ZipFile(work / "legacy-dates" / release["filename"]) as previous:
                    assert zipped.comment == previous.comment and zipped.namelist() == previous.namelist(), "Timestamp correction changed package metadata or scope"
                    for entry in zipped.infolist():
                        old = previous.getinfo(entry.filename)
                        assert zipped.read(entry) == previous.read(old), "Timestamp correction changed runtime file content"
                        assert (entry.external_attr, entry.create_system, entry.compress_type) == (old.external_attr, old.create_system, old.compress_type), "Timestamp correction changed runtime file permissions or archive format"
                        if entry.filename in REPAIR_TIMESTAMPS:
                            assert entry.date_time == REPAIR_TIMESTAMPS[entry.filename] > old.date_time == LEGACY_TIMESTAMP, "Corrected runtime dependency lacks its reviewed advanced component timestamp"
                        else:
                            assert entry.date_time == old.date_time == LEGACY_TIMESTAMP, "Focused repairs changed another component's timestamp"
                if release["kind"] == "fresh":
                    assert "config/app.example.php" in entries and "install/index.php" in entries
                    changed = {name for name in entries if hashlib.sha256(zipped.read(name)).hexdigest()
                               != prior_release["entries"].get(name, {}).get("sha256")}
                    assert set(prior_release["entries"]) <= entries, "A previously delivered runtime file disappeared without an explicit removal review"
                    assert entries - set(prior_release["entries"]) == V093_RUNTIME_FILES, "The 0.9.3 candidate added runtime files outside its reviewed scope"
                    assert changed == RELEASE_FILES, "Changed runtime files lack reviewed release timestamps, or unchanged files were relabeled: " + ", ".join(sorted(changed ^ RELEASE_FILES))
                    for entry in zipped.infolist():
                        prior = prior_release["entries"].get(entry.filename)
                        if prior is not None:
                            if entry.filename in changed:
                                assert entry.date_time > tuple(prior["timestamp"]), "Changed PHP/assets would retain a stale delivered timestamp: " + entry.filename
                            else:
                                assert entry.date_time == tuple(prior["timestamp"]), "Unchanged runtime file timestamp differs from the prior delivery: " + entry.filename
                elif release["kind"] == "upgrade":
                    release_major, release_minor, release_patch = map(int, version.split("."))
                    assert (release_major, release_minor) in {(0, 8), (0, 9)}, "Unreviewed upgrade release series"
                    expected_from = ([f"0.8.{patch}" for patch in range(release_patch + 1)] if release_minor == 8
                                     else [f"0.8.{patch}" for patch in range(15)] + [f"0.9.{patch}" for patch in range(release_patch + 1)])
                    assert release["supported_from"] == compatibility["supported_from"] == expected_from, "Upgrade compatibility omitted a supported prior patch or current-version repair"
                    assert not any(Path(name).parts[0] in package.PRESERVED_UPGRADE_ROOTS for name in entries)
                    with ZipFile(work / "first" / releases["fresh"]["filename"]) as fresh:
                        for relative in REPAIR_FILES | COMPOSER_REPAIR_FILES | EDITORIAL_RUNTIME_FILES | MENU_RUNTIME_FILES | DEPTH_RUNTIME_FILES | CALENDAR_RUNTIME_FILES | SCENE_SCHEDULE_RUNTIME_FILES | V09_RUNTIME_FILES | PAYMENT_RUNTIME_FILES | {"public/assets/js/scene-geometry.js"}:
                            if Path(relative).parts[0] not in package.PRESERVED_UPGRADE_ROOTS:
                                assert relative in entries, "Upgrade is missing a required runtime repair dependency"
                                assert zipped.read(relative) == fresh.read(relative), "Upgrade repair dependency differs from the complete fresh package"
                                assert metadata(zipped.getinfo(relative)) == metadata(fresh.getinfo(relative)), "Fresh and upgrade repair dependencies have different timestamps, permissions, or format"
                    installed = work / "installed"
                    installed.mkdir()
                    for relative, content in protected.items():
                        target = installed / relative
                        target.parent.mkdir(parents=True, exist_ok=True)
                        target.write_bytes(content)
                    zipped.extractall(installed)
                    assert all((installed / relative).read_bytes() == content for relative, content in protected.items()), "Upgrade altered installed state"
                    assert not (installed / "install").exists(), "Upgrade reintroduced installer"
                    assert (installed / "VERSION").read_text().strip() == version
                    lock = json.loads((installed / "storage/install.lock").read_text())
                    assert lock["installed"] and lock["installation_id"] == "test-installation"
                elif release["kind"] == "installer-repair":
                    assert entries == REPAIR_FILES, "Installer repair must contain exactly the reviewed database and filesystem dependencies"
                    assert release["filename"] == "Nikki-Wilder-Velvet-Vixxen-0.8.1-Build-20261020.3-Installer-Database-Repair.zip"
                    assert release["files"] == 11 == len(REPAIR_FILES) and release["php_files"] == 9 and release["javascript_files"] == 1
                    assert release["repair_build_id"] == package.INSTALLER_REPAIR_BUILD_ID == "20261020.3", "Unchanged installer repair must retain its delivered identity"
                    assert release["sha256"] == "f1a391e471c97492a5f075f7fb587ce513df636cb331947bca62a438efb4c297", "Unchanged installer repair bytes were relabeled or rebuilt differently"
                    assert release["sha256"] != "9cf165faf4f324e4682875bcfde50300a42382467bca700dac0d4e4fd835fc38", "Sender correction was not included in the actual installer repair"
                    compatibility = json.loads(zipped.comment)
                    assert compatibility["kind"] == "installer-repair" and compatibility["version"] == "0.8.1"
                    assert compatibility["repair_build_id"] == "20261020.3"
                    assert compatibility["supported_from"] == ["0.8.1"] and compatibility["installation_state"] == "incomplete", "Installer repair compatibility must stay limited to an unfinished 0.8.1 installation"
                    assert release["supported_from"] == ["0.8.1"] and release["installation_state"] == "incomplete"

                    # Apply the small repair to an already extracted, unfinished
                    # installation. Every other file must remain byte-identical.
                    incomplete = work / "incomplete"
                    with ZipFile(work / "first" / releases["fresh"]["filename"]) as fresh:
                        current_dependencies = {relative: fresh.read(relative) for relative in REPAIR_FILES}
                        for relative, content in current_dependencies.items():
                            assert zipped.read(relative) == content, "Repair dependency differs from the complete fresh package"
                            assert metadata(zipped.getinfo(relative)) == metadata(fresh.getinfo(relative)), "Fresh and repair dependencies have different timestamps, permissions, or format"
                        fresh.extractall(incomplete)
                    unfinished_state = {
                        "VERSION": (version_baseline["version"] + "\n").encode(),
                        "includes/version.php": version_baseline["version_source"].encode(),
                        "config/app.php": b"<?php return ['installed'=>false,'fixture'=>'unfinished setup'];\n",
                        "storage/temp/installer-fixture.json": b'{"stage":"database","fixture":true}\n',
                        "uploads/images/owner-cutout.bin": b"PRIVATE UNFINISHED-INSTALL UPLOAD FIXTURE\n",
                        "database/backups/preserved-fixture.bin": b"PRE-EXISTING BACKUP FIXTURE\n",
                    }
                    for relative, content in unfinished_state.items():
                        target = incomplete / relative
                        target.parent.mkdir(parents=True, exist_ok=True)
                        target.write_bytes(content)
                    for relative in REPAIR_FILES:
                        target = incomplete / relative
                        if relative == "app/Core/MigrationResumeSchema.php":
                            target.unlink()  # Older installations do not contain the new helper.
                        elif relative.endswith(".css"):
                            target.write_bytes(b"/* prior installer stylesheet fixture */\n")
                        elif relative.endswith(".js"):
                            target.write_bytes(b"// prior installer script fixture\n")
                        else:
                            target.write_bytes(b"<?php /* prior installer dependency fixture */\n")
                    before = {path.relative_to(incomplete).as_posix(): path.read_bytes() for path in incomplete.rglob("*") if path.is_file()}
                    assert "install/index.php" in before and "install/services/RequirementChecker.php" in before
                    zipped.extractall(incomplete)
                    after = {path.relative_to(incomplete).as_posix(): path.read_bytes() for path in incomplete.rglob("*") if path.is_file()}
                    expected = dict(before)
                    expected.update(current_dependencies)
                    assert after == expected, "Installer repair changed existing configuration, storage, uploads, application assets, database files, or other installer files"
                    assert not (incomplete / "storage/install.lock").exists(), "Repair unexpectedly installed or locked the unfinished site"
                    old_identity = installer_identity(incomplete)
                    assert old_identity["runtime_version"] == "0.8.1" and old_identity["build_id"] == "20261020.2", "Applying the installer-only overlay falsely advanced the unfinished site's runtime identity"
                else:
                    raise AssertionError("An unsupported current-release distribution was emitted")

        repair_files = {relative: (source / relative).read_bytes() for relative in REPAIR_FILES}
        package.validate(repair_files, "installer-repair", "0.8.1")
        for files, repair_version in [
            ({}, "0.8.1"),
            *[(dict((path, content) for path, content in repair_files.items() if path != omitted), "0.8.1")
              for omitted in REPAIR_FILES],
            ({**repair_files, "install/services/RequirementChecker.php": b"<?php /* extra file */\n"}, "0.8.1"),
            (repair_files, "0.8.0"),
            (repair_files, "0.8.2"),
        ]:
            try:
                package.validate(files, "installer-repair", repair_version)
            except ValueError:
                pass
            else:
                raise AssertionError("Invalid installer repair contents or version were accepted")

        # The current source may not masquerade as a small installed-site
        # overlay, either with its own version or with the old compatibility label.
        composer_files = package.runtime_files(source, "composer-repair")
        for repair_version in [version, "0.8.1"]:
            try:
                package.validate(composer_files, "composer-repair", repair_version)
            except ValueError:
                pass
            else:
                raise AssertionError("An unsupported Media/Composer cross-version repair was accepted")

        # A partial overwrite must never be certified by the inventory. Both
        # changed repair files and unchanged full-runtime dependencies matter.
        full_files = package.runtime_files(source, "upgrade")
        for kind, original in [("upgrade", full_files), ("fresh", package.runtime_files(source, "fresh"))]:
            # Rebuild an internally consistent inventory after removing a
            # dependency: even that incomplete package must fail the floor.
            for omitted in EDITORIAL_RUNTIME_FILES | MENU_RUNTIME_FILES | DEPTH_RUNTIME_FILES | CALENDAR_RUNTIME_FILES | SCENE_SCHEDULE_RUNTIME_FILES | V09_RUNTIME_FILES | PAYMENT_RUNTIME_FILES | {"public/assets/js/scene-geometry.js"}:
                broken = {name: content for name, content in original.items() if name != omitted}
                broken[RUNTIME_BUILD_PATH] = package.runtime_build_manifest(broken)
                try:
                    package.validate(broken, kind, version)
                except ValueError as error:
                    assert "Required runtime source is missing" in str(error) and omitted in str(error)
                else:
                    raise AssertionError("An incomplete required runtime dependency was accepted: " + omitted)
            malformed_inventories = [
                {**expected_inventory, "build_id": "20261019.4"},
                {**expected_inventory, "schema": True},
                {**expected_inventory, "files": {name: digest for name, digest in expected_inventory["files"].items() if name != "app/bootstrap.php"}},
                {**expected_inventory, "files": {**expected_inventory["files"], "config/app.php": "0" * 64}},
                {**expected_inventory, "files": {**expected_inventory["files"], RUNTIME_BUILD_PATH: "0" * 64}},
                {**expected_inventory, "files": {**expected_inventory["files"], "public/routes/api/upload.php": "0" * 64}},
                {**expected_inventory, "files": {**expected_inventory["files"], "api/upload.php": "0" * 64}},
            ]
            for inventory in malformed_inventories:
                broken = {**original, RUNTIME_BUILD_PATH: json.dumps(inventory).encode()}
                try:
                    package.validate(broken, kind, version)
                except ValueError:
                    pass
                else:
                    raise AssertionError("An invalid or mismatched runtime build inventory was accepted")
            changed = {**original, "admin/index.php": b"<?php /* prior installed template */\n"}
            try:
                package.validate(changed, kind, version)
            except ValueError:
                pass
            else:
                raise AssertionError("Mixed old/new runtime bytes were accepted with a current inventory")

        dependency = source / "app/Core/UploadService.php"
        original_dependency = dependency.read_bytes()
        dependency.write_bytes(original_dependency + b"\n// Disposable inventory change fixture.\n")
        updated_inventory = json.loads(package.runtime_files(source, "upgrade")[RUNTIME_BUILD_PATH])
        expected_updated = json.loads(inventory_bytes)
        expected_updated["files"]["app/Core/UploadService.php"] = hashlib.sha256(dependency.read_bytes()).hexdigest()
        assert updated_inventory == expected_updated, "Full-runtime packaging reused a stale inventory after a dependency changed"
        dependency.write_bytes(original_dependency)

        version_source = source / "includes/version.php"
        original_version = version_source.read_bytes()
        for invalid_version in [
            original_version.replace(f"const VELVET_VIXXEN_BUILD_ID = '{BUILD_ID}';\n".encode(), b""),
            original_version.replace(f"'{version}-{BUILD_ID}'".encode(), b"'0.8.1-20261019.4'"),
            original_version.replace(f"const VELVET_VIXXEN_BUILD_ID = '{BUILD_ID}';".encode(), b"const VELVET_VIXXEN_BUILD_ID = '2026.5';"),
            original_version.replace(f"const VELVET_VIXXEN_VERSION = '{version}';".encode(), b"const VELVET_VIXXEN_VERSION = '0.0.0';"),
        ]:
            version_source.write_bytes(invalid_version)
            try:
                package.runtime_files(source, "upgrade")
            except ValueError:
                pass
            else:
                raise AssertionError("A missing, inconsistent or malformed runtime build identity was accepted")
        version_source.write_bytes(original_version)

        # Advancing the canonical identifiers alone must carry through to an
        # actual next-patch package, installer display and startup verification.
        next_version = ".".join([*version.split(".")[:2], str(int(version.split(".")[2]) + 1)])
        (source / "VERSION").write_text(next_version + "\n")
        version_source.write_bytes(original_version.replace(version.encode(), next_version.encode()))
        future = package.build(source, work / "next-version", next_version)
        future_fresh = next(entry for entry in future["packages"] if entry["kind"] == "fresh")
        with ZipFile(work / "next-version" / future_fresh["filename"]) as fresh:
            fresh.extractall(work / "next-version-runtime")
        assert installer_identity(work / "next-version-runtime")["runtime_version"] == next_version
        assert next(entry for entry in future["packages"] if entry["kind"] == "installer-repair")["version"] == "0.8.1", "A patch increment relabeled the historical installer-repair target"
        (source / "VERSION").write_text(version + "\n")
        version_source.write_bytes(original_version)

        # Refuse a source symlink even when the target would otherwise ship.
        outside = work / "outside.php"
        outside.write_text("<?php /* fixture outside source */\n")
        link = source / "app" / "unsafe.php"
        link.symlink_to(outside)
        try:
            package.build(source, work / "unsafe", version)
        except ValueError as error:
            assert "symlink" in str(error)
        else:
            raise AssertionError("Symlinked source was accepted")
    print("Runtime packages passed: exact 13-folder Fresh/9-folder Upgrade root layout, rejection of actual Build .5 root expansion, retired unsupported cross-version Media overlay, distinct canonical release/build identities and filenames, identical complete generated runtime inventory with stale/mixed/invalid rejection, clean fresh install, protected upgrade overlay, exact eleven-file sender/database/folder repair, separate historical-version repair support boundaries, matching dependencies and advanced timestamps, safe 0644 entries, reproducibility, archive integrity, and symlink rejection.")
    for release in first["packages"]:
        print(f"{release['kind']}: {release['size_bytes']} bytes, SHA-256 {release['sha256']}")


if __name__ == "__main__":
    run()

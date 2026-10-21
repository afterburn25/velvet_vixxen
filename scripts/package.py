#!/usr/bin/env python3
"""Build reproducible runtime installation, upgrade, and targeted installer repair packages."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path
from typing import Iterable
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

ROOT_FILES = {".htaccess", "index.php", "route.php", "VERSION"}
RUNTIME_ROOTS = {
    "admin", "app", "addons", "config", "database", "images", "includes",
    "install", "public", "storage", "templates", "uploads", "vendor",
}
PRESERVED_UPGRADE_ROOTS = {"config", "storage", "uploads", "install"}
# Public page/API implementations stay in one protected, organized tree. The
# physical route.php dispatcher handles generated URLs even without rewriting.
PUBLIC_HANDLER_FILES = {"public/routes/" + relative for relative in {
    "about/index.php", "account/billing.php", "account/forgot.php", "account/index.php",
    "account/library.php", "account/login.php", "account/logout.php", "account/register.php",
    "account/reset.php", "account/security.php", "account/verify.php",
    "api/addon-license.php", "api/marketing-event.php", "api/member-state.php",
    "api/payment-webhook.php", "api/scene.php", "api/upload.php",
    "blog/index.php", "blog/post.php", "booking/index.php", "campaign/index.php",
    "cart/index.php", "checkout/index.php", "checkout/success.php", "collections/index.php",
    "communication/download.php", "extension/index.php", "links/index.php", "media.php",
    "membership/index.php", "messages/index.php", "music/index.php", "newsletter/confirm.php",
    "newsletter/index.php", "newsletter/unsubscribe.php", "photos/index.php", "photos/view.php",
    "receipt/index.php", "requests/index.php", "shop/index.php",
    "videos/index.php", "videos/watch.php",
}}
INSTALLER_REPAIR_VERSION = "0.8.1"
INSTALLER_REPAIR_BUILD_ID = "20261020.3"
# The unfinished 0.8.1 overlay reads that installation's canonical version. It
# never carries VERSION, includes/version.php, or an installed-runtime inventory.
INSTALLER_DATABASE_REPAIR_FILES = {
    "app/Core/MigrationRunner.php", "app/Core/MigrationResumeSchema.php",
    "includes/installation_recovery.php",
    "install/services/InstallerService.php", "install/views/single-page.php",
    "public/assets/css/installer.css",
    "public/assets/js/installer.js",
}
INSTALLER_MAIL_REPAIR_FILES = {
    "install/index.php", "install/services/InstallerService.php",
    "install/services/EmailAddress.php", "install/services/SmtpTester.php",
}
INSTALLER_REPAIR_FILES = {
    "install/services/FileSystemService.php", *INSTALLER_DATABASE_REPAIR_FILES,
    *INSTALLER_MAIL_REPAIR_FILES,
}
RUNTIME_BUILD_PATH = "includes/runtime-build.json"
# Preserve the historical dependency floor in full distributions. The former
# installed-site overlay is retired; rebuild older releases from their pinned
# source instead of claiming that this changed runtime matches their baselines.
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
    "includes/version.php", RUNTIME_BUILD_PATH, "public/routes/links/index.php", "public/routes/campaign/index.php",
    "public/assets/css/site.css", "public/assets/css/composer.css", "public/assets/css/site-ux.css",
    "public/assets/js/api-client.js", "public/assets/js/admin.js",
    "public/assets/js/composer.js", "public/assets/js/site.js", "public/assets/js/marketing.js",
    "public/assets/js/media-center.js",
}
# Retain the editorial dependency floor when a later release no longer changes
# those files. History supplements the shipped access, metadata and previews.
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
# Keep this deterministic, but advance it for each unfinished-installer service
# correction: ZIP extraction restores the mtime that OPcache uses to notice changes.
INSTALLER_FILESYSTEM_TIMESTAMP = (2026, 10, 19, 0, 0, 0)
INSTALLER_DATABASE_TIMESTAMP = (2026, 10, 19, 16, 0, 0)
INSTALLER_MAIL_TIMESTAMP = (2026, 10, 20, 2, 24, 0)
COMPOSER_REPAIR_TIMESTAMP = (2026, 10, 20, 2, 24, 0)
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
PRIOR_RELEASE_TIMESTAMPS = {
    '.htaccess': (2026, 10, 20, 2, 24, 0),
    'VERSION': (2026, 10, 21, 5, 46, 42),
    'admin/addon-recovery.php': (2026, 10, 20, 5, 0, 0),
    'admin/index.php': (2026, 10, 21, 5, 46, 42),
    'admin/repair.php': (2026, 10, 20, 2, 24, 0),
    'admin/update.php': (2026, 10, 20, 2, 24, 0),
    'app/Console/run_marketing.php': (2026, 10, 20, 2, 24, 0),
    'app/Console/run_media.php': (2026, 10, 20, 3, 48, 0),
    'app/Core/AddonLicenseClient.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/AddonManager.php': (2026, 10, 20, 5, 0, 0),
    'app/Core/AddonMigrationException.php': (2026, 10, 20, 5, 0, 0),
    'app/Core/AddonMigrationPlan.php': (2026, 10, 20, 5, 0, 0),
    'app/Core/AddonMigrationRecovery.php': (2026, 10, 20, 5, 0, 0),
    'app/Core/AddonRuntime.php': (2026, 10, 20, 5, 0, 0),
    'app/Core/AddonStoreTrait.php': (2026, 10, 20, 5, 0, 0),
    'app/Core/CampaignDeliveryService.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/CommerceService.php': (2026, 10, 20, 3, 48, 0),
    'app/Core/EmailAddress.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/MailDeliveryException.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/MailService.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/MailSettingsService.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/MarketingAccess.php': (2026, 10, 20, 17, 20, 22),
    'app/Core/MarketingAudience.php': (2026, 10, 20, 5, 20, 0),
    'app/Core/MarketingHistory.php': (2026, 10, 20, 21, 30, 0),
    'app/Core/MarketingMetadata.php': (2026, 10, 20, 19, 9, 10),
    'app/Core/MarketingPublication.php': (2026, 10, 20, 21, 30, 0),
    'app/Core/MarketingSqlRepository.php': (2026, 10, 20, 14, 16, 8),
    'app/Core/MarketingStorage.php': (2026, 10, 20, 17, 20, 22),
    'app/Core/MarketingStorageException.php': (2026, 10, 20, 14, 16, 8),
    'app/Core/MarketingStore.php': (2026, 10, 20, 21, 30, 0),
    'app/Core/MediaAdminService.php': (2026, 10, 20, 3, 48, 0),
    'app/Core/MediaInput.php': (2026, 10, 20, 3, 48, 0),
    'app/Core/MediaStoreTrait.php': (2026, 10, 20, 19, 9, 10),
    'app/Core/MemberStoreTrait.php': (2026, 10, 20, 17, 20, 22),
    'app/Core/MenuService.php': (2026, 10, 21, 0, 41, 28),
    'app/Core/MenuStoreTrait.php': (2026, 10, 21, 0, 41, 28),
    'app/Core/MigrationResumeSchema.php': (2026, 10, 19, 16, 0, 0),
    'app/Core/MigrationRunner.php': (2026, 10, 19, 16, 0, 0),
    'app/Core/RepairBackupService.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/RootLayoutService.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/RuntimeBuildService.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/RuntimeInventoryService.php': (2026, 10, 21, 5, 46, 42),
    'app/Core/SceneButton.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/SceneDepth.php': (2026, 10, 21, 5, 46, 42),
    'app/Core/Store.php': (2026, 10, 21, 5, 46, 42),
    'app/Support/functions.php': (2026, 10, 20, 2, 24, 0),
    'app/Support/mail_settings_admin.php': (2026, 10, 20, 2, 24, 0),
    'app/Support/marketing_storage_admin.php': (2026, 10, 20, 14, 16, 8),
    'app/Support/media_admin.php': (2026, 10, 20, 3, 48, 0),
    'app/Support/menu_admin.php': (2026, 10, 21, 0, 41, 28),
    'app/Support/public_routes.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/admin/addon-licensing.php': (2026, 10, 20, 3, 48, 0),
    'app/Views/admin/addons.php': (2026, 10, 20, 5, 0, 0),
    'app/Views/admin/commerce.php': (2026, 10, 20, 3, 48, 0),
    'app/Views/admin/communications.php': (2026, 10, 20, 3, 48, 0),
    'app/Views/admin/composer.php': (2026, 10, 21, 5, 46, 42),
    'app/Views/admin/mail-settings-actions.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/admin/mail-settings.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/admin/marketing-actions.php': (2026, 10, 20, 21, 30, 0),
    'app/Views/admin/marketing-audiences.php': (2026, 10, 20, 5, 20, 0),
    'app/Views/admin/marketing-campaign-form.php': (2026, 10, 20, 5, 20, 0),
    'app/Views/admin/marketing-history.php': (2026, 10, 20, 21, 30, 0),
    'app/Views/admin/marketing-preview.php': (2026, 10, 20, 19, 9, 10),
    'app/Views/admin/marketing-publication-editor.php': (2026, 10, 20, 21, 30, 0),
    'app/Views/admin/marketing-publication-list.php': (2026, 10, 20, 21, 30, 0),
    'app/Views/admin/marketing-storage-actions.php': (2026, 10, 20, 14, 16, 8),
    'app/Views/admin/marketing-storage.php': (2026, 10, 20, 14, 16, 8),
    'app/Views/admin/marketing.php': (2026, 10, 20, 14, 16, 8),
    'app/Views/admin/media-center.php': (2026, 10, 20, 3, 48, 0),
    'app/Views/admin/media-library.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/admin/members.php': (2026, 10, 20, 3, 48, 0),
    'app/Views/admin/menus-actions.php': (2026, 10, 21, 0, 41, 28),
    'app/Views/admin/menus.php': (2026, 10, 21, 0, 41, 28),
    'app/Views/admin/navigation.php': (2026, 10, 21, 0, 41, 28),
    'app/Views/admin/repair-backups.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/admin/repair-status.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/admin/update-status.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/public/partials/footer.php': (2026, 10, 21, 5, 46, 42),
    'app/Views/public/partials/header.php': (2026, 10, 21, 0, 41, 28),
    'app/Views/public/partials/menu-items.php': (2026, 10, 21, 0, 41, 28),
    'app/Views/public/partials/navigation.php': (2026, 10, 21, 0, 41, 28),
    'app/Views/public/partials/scene-buttons.php': (2026, 10, 20, 2, 24, 0),
    'config/app.example.php': (2026, 10, 20, 3, 48, 0),
    'database/migrations/202610200015_marketing_sql_storage.php': (2026, 10, 20, 14, 16, 8),
    'includes/installation_recovery.php': (2026, 10, 19, 16, 0, 0),
    'includes/marketing.php': (2026, 10, 20, 19, 9, 10),
    'includes/marketing_admin.php': (2026, 10, 20, 21, 30, 0),
    'includes/marketing_public_shell.php': (2026, 10, 20, 19, 9, 10),
    'includes/runtime-build.json': (2026, 10, 21, 5, 46, 42),
    'includes/version.php': (2026, 10, 21, 5, 46, 42),
    'install/index.php': (2026, 10, 20, 2, 24, 0),
    'install/services/EmailAddress.php': (2026, 10, 20, 2, 24, 0),
    'install/services/FileSystemService.php': (2026, 10, 19, 0, 0, 0),
    'install/services/InstallerService.php': (2026, 10, 20, 3, 48, 0),
    'install/services/SmtpTester.php': (2026, 10, 20, 2, 24, 0),
    'install/views/single-page.php': (2026, 10, 20, 3, 48, 0),
    'public/assets/css/composer.css': (2026, 10, 21, 5, 46, 42),
    'public/assets/css/installer.css': (2026, 10, 19, 16, 0, 0),
    'public/assets/css/mail-settings.css': (2026, 10, 20, 2, 24, 0),
    'public/assets/css/marketing.css': (2026, 10, 20, 21, 30, 0),
    'public/assets/css/media-admin-edit.css': (2026, 10, 20, 3, 48, 0),
    'public/assets/css/menu-admin.css': (2026, 10, 21, 0, 41, 28),
    'public/assets/css/navigation.css': (2026, 10, 21, 0, 41, 28),
    'public/assets/css/site-ux.css': (2026, 10, 21, 0, 41, 28),
    'public/assets/css/site.css': (2026, 10, 20, 23, 38, 0),
    'public/assets/js/admin-media.js': (2026, 10, 20, 3, 48, 0),
    'public/assets/js/admin.js': (2026, 10, 20, 2, 24, 0),
    'public/assets/js/api-client.js': (2026, 10, 20, 2, 24, 0),
    'public/assets/js/composer.js': (2026, 10, 21, 5, 46, 42),
    'public/assets/js/installer.js': (2026, 10, 19, 16, 0, 0),
    'public/assets/js/mail-settings.js': (2026, 10, 20, 2, 24, 0),
    'public/assets/js/marketing-editorial.js': (2026, 10, 20, 19, 9, 10),
    'public/assets/js/marketing.js': (2026, 10, 20, 2, 24, 0),
    'public/assets/js/media-center.js': (2026, 10, 20, 23, 38, 0),
    'public/assets/js/menu-admin.js': (2026, 10, 21, 0, 41, 28),
    'public/assets/js/navigation.js': (2026, 10, 21, 0, 41, 28),
    'public/assets/js/scene-depth.js': (2026, 10, 21, 5, 46, 42),
    'public/assets/js/scene-geometry.js': (2026, 10, 20, 23, 38, 0),
    'public/assets/js/site.js': (2026, 10, 21, 5, 46, 42),
    'public/home.php': (2026, 10, 21, 5, 46, 42),
    'public/router.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/about/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/account/billing.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/account/forgot.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/account/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/account/library.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/account/login.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/account/logout.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/account/register.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/account/reset.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/account/security.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/account/verify.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/api/addon-license.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/api/marketing-event.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/api/member-state.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/api/payment-webhook.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/api/scene.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/api/upload.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/blog/index.php': (2026, 10, 20, 19, 9, 10),
    'public/routes/blog/post.php': (2026, 10, 20, 19, 9, 10),
    'public/routes/booking/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/campaign/index.php': (2026, 10, 20, 19, 9, 10),
    'public/routes/cart/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/checkout/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/checkout/success.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/collections/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/communication/download.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/extension/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/links/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/media.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/membership/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/messages/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/music/index.php': (2026, 10, 20, 3, 48, 0),
    'public/routes/newsletter/confirm.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/newsletter/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/newsletter/unsubscribe.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/photos/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/photos/view.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/receipt/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/requests/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/shop/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/videos/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/videos/watch.php': (2026, 10, 20, 2, 24, 0),
    'route.php': (2026, 10, 20, 2, 24, 0),
}
RELEASE_TIMESTAMP = (2026, 10, 21, 8, 37, 20)
RELEASE_FILES = {
    'VERSION',
    'admin/index.php',
    'app/Core/MarketingCalendar.php',
    'app/Core/MarketingHistory.php',
    'app/Core/MarketingPublication.php',
    'app/Core/MarketingStore.php',
    'app/Core/RuntimeInventoryService.php',
    'app/Core/Store.php',
    'app/Views/admin/marketing-calendar.php',
    'app/Views/admin/marketing-history.php',
    'app/Views/admin/marketing-publication-editor.php',
    'app/Views/admin/marketing-publication-list.php',
    'app/Views/admin/marketing.php',
    'app/Views/admin/media-center.php',
    'includes/marketing_admin.php',
    'includes/runtime-build.json',
    'includes/version.php',
    'public/assets/css/marketing-calendar.css',
}
APPROVED_UPLOADS = {
    "uploads/.htaccess", "uploads/index.html",
    "uploads/audio/demo/neon-heartbeat.mp3",
    "uploads/audio/demo/pink-room-dreams.mp3",
    "uploads/audio/demo/velvet-after-dark.mp3",
    "uploads/captions/demo/velvet-trailer.vtt",
    "uploads/videos/demo/velvet-trailer.mp4",
    "uploads/videos/demo/velvet-feature.mp4",
    "uploads/images/demo/album-cover.jpg", "uploads/images/demo/video-poster.jpg",
    *(f"uploads/images/demo/gallery-{number}.jpg" for number in range(1, 6)),
}
FORBIDDEN_SUFFIXES = (".log", ".tmp", ".previous", ".backup", ".bak", ".pem", ".key", ".sql")


def validate_runtime_layout(paths: Iterable[str], kind: str) -> None:
    """Enforce the approved root shape independently of individual dependencies."""
    if kind not in {"fresh", "upgrade", "installer-repair", "composer-repair"}:
        raise ValueError(f"Unknown runtime package kind: {kind}")
    names = set(paths)
    if any(not name or Path(name).is_absolute() or ".." in Path(name).parts for name in names):
        raise ValueError("Runtime layout contains an unsafe path")
    root_files = {name for name in names if "/" not in name}
    roots = {name.split("/", 1)[0] for name in names if "/" in name}
    allowed_roots = RUNTIME_ROOTS - PRESERVED_UPGRADE_ROOTS if kind in {"upgrade", "composer-repair"} else RUNTIME_ROOTS
    unexpected = sorted((root_files - ROOT_FILES) | (roots - allowed_roots))
    if unexpected:
        raise ValueError("Runtime layout contains unapproved root entries: " + ", ".join(unexpected))
    if kind in {"fresh", "upgrade"}:
        missing = sorted((ROOT_FILES - root_files) | (allowed_roots - roots))
        if missing:
            raise ValueError("Runtime layout is missing approved root entries: " + ", ".join(missing))


def eligible(relative: str, kind: str) -> bool:
    """An allowlist prevents runtime data from entering any distribution."""
    if kind == "installer-repair":
        return relative in INSTALLER_REPAIR_FILES
    if kind == "composer-repair":
        return relative in COMPOSER_REPAIR_FILES
    path = Path(relative)
    parts = path.parts
    if not parts or path.is_absolute() or ".." in parts:
        return False
    if len(parts) == 1:
        return relative in ROOT_FILES
    top = parts[0]
    if top not in RUNTIME_ROOTS:
        return False
    if any(part.startswith(".") and part != ".htaccess" for part in parts):
        return False
    if path.name.endswith(FORBIDDEN_SUFFIXES) or ".tmp-" in path.name:
        return False
    if kind == "upgrade" and top in PRESERVED_UPGRADE_ROOTS:
        return False
    if top == "config":
        return relative in {"config/.htaccess", "config/app.example.php"}
    if top == "storage":
        return path.name == ".htaccess" or relative == "storage/defaults/data.json"
    if top == "uploads":
        return relative in APPROVED_UPLOADS
    if top == "database":
        return relative == "database/.htaccess" or (
            len(parts) == 3 and parts[1] == "migrations" and path.suffix == ".php"
        ) or relative == "database/backups/.htaccess"
    if top == "addons":
        return relative == "addons/.htaccess" or relative in {
            "addons/staging/.htaccess", "addons/installed/.htaccess",
            "addons/packages/velvet-welcome-banner-1.0.0.zip",
            "addons/packages/velvet-welcome-banner-1.0.1.zip",
        }
    return True


def _source_runtime_files(root: Path, kind: str) -> dict[str, bytes]:
    """Read only allowed source, never a previously generated runtime inventory."""
    files: dict[str, bytes] = {}
    root = root.resolve()
    for path in sorted(root.rglob("*")):
        relative = path.relative_to(root).as_posix()
        if relative == RUNTIME_BUILD_PATH or not eligible(relative, kind):
            continue
        if path.is_symlink() or any(parent.is_symlink() for parent in path.parents if parent != root):
            raise ValueError(f"Package source contains a symlink: {relative}")
        if path.is_file():
            if not path.resolve().is_relative_to(root):
                raise ValueError(f"Package file escapes source root: {relative}")
            files[relative] = path.read_bytes()
    return files


def package_identity(files: dict[str, bytes]) -> tuple[str, str]:
    """Read literal public identifiers without executing packaged PHP."""
    source = files.get("includes/version.php", b"").decode("utf-8")

    def constant(name: str) -> str:
        matches = re.findall(r"(?m)^const\s+" + re.escape(name)
                             + r"\s*=\s*(['\"])([0-9.-]+)\1\s*;\s*$", source)
        if len(matches) != 1:
            raise ValueError(f"includes/version.php needs one literal {name}")
        return matches[0][1]

    version = constant("VELVET_VIXXEN_VERSION")
    build_id = constant("VELVET_VIXXEN_BUILD_ID")
    asset_version = constant("VELVET_VIXXEN_ASSET_VERSION")
    if not re.fullmatch(r"\d+\.\d+\.\d+", version) or not re.fullmatch(r"\d{8}\.\d+", build_id):
        raise ValueError("Runtime version or build ID is invalid")
    if asset_version != f"{version}-{build_id}":
        raise ValueError("Runtime asset version does not match the build ID")
    if "VERSION" in files and files["VERSION"].decode().strip() != version:
        raise ValueError("includes/version.php does not match VERSION")
    return version, build_id


def runtime_build_manifest(files: dict[str, bytes]) -> bytes:
    """Inventory the complete upgrade runtime, excluding private state and itself."""
    version, build_id = package_identity(files)
    hashes = {
        name: hashlib.sha256(content).hexdigest()
        for name, content in sorted(files.items())
        if name != RUNTIME_BUILD_PATH and eligible(name, "upgrade")
    }
    return (json.dumps({
        "schema": 1, "product": "Velvet Vixxen", "version": version,
        "build_id": build_id, "files": hashes,
    }, indent=2) + "\n").encode("utf-8")


def _files_for_kind(source: dict[str, bytes], kind: str, manifest: bytes | None = None) -> dict[str, bytes]:
    if kind not in {"fresh", "upgrade", "installer-repair", "composer-repair"}:
        raise ValueError(f"Unknown runtime package kind: {kind}")
    files = {name: content for name, content in source.items() if eligible(name, kind)}
    if kind != "installer-repair":
        files[RUNTIME_BUILD_PATH] = manifest if manifest is not None else runtime_build_manifest(source)
    return dict(sorted(files.items()))


def runtime_files(root: Path, kind: str) -> dict[str, bytes]:
    source_kind = kind if kind in {"fresh", "installer-repair"} else "upgrade"
    return _files_for_kind(_source_runtime_files(root, source_kind), kind)


def required_files(kind: str, version: str) -> set[str]:
    if kind == "installer-repair":
        if version != INSTALLER_REPAIR_VERSION:
            raise ValueError("The installer database repair applies only to unfinished 0.8.1 installations")
        required = INSTALLER_REPAIR_FILES
    elif kind == "composer-repair":
        raise ValueError("The focused Media and Composer repair is retired; use the complete Upgrade ZIP")
    else:
        required = ROOT_FILES | {
            "app/bootstrap.php", "includes/bootstrap.php", "includes/version.php",
            "includes/installation_recovery.php", "public/router.php", "public/home.php",
            "app/Core/RepairBackupService.php", "public/assets/js/composer.js", "public/assets/js/scene-geometry.js",
            "app/Core/MigrationRunner.php", "app/Core/MigrationResumeSchema.php",
        } | COMPOSER_REPAIR_FILES | EDITORIAL_RUNTIME_FILES | MENU_RUNTIME_FILES | DEPTH_RUNTIME_FILES | CALENDAR_RUNTIME_FILES
        if kind == "fresh":
            required |= {"config/app.example.php", "install/index.php", "storage/defaults/data.json"} | INSTALLER_REPAIR_FILES
        required |= {relative for relative in RELEASE_FILES if eligible(relative, kind)}
    return required


def validate(files: dict[str, bytes], kind: str, version: str) -> None:
    validate_runtime_layout(files, kind)
    required = required_files(kind, version)
    missing = required - files.keys()
    if missing:
        raise ValueError("Required runtime source is missing: " + ", ".join(sorted(missing)))
    if kind not in {"installer-repair", "composer-repair"}:
        if files["VERSION"].decode().strip() != version:
            raise ValueError("Package version does not match VERSION")
    if kind != "installer-repair":
        runtime_version, build_id = package_identity(files)
        if runtime_version != version:
            raise ValueError("Runtime version does not match the requested package version")
        manifest = json.loads(files[RUNTIME_BUILD_PATH])
        if not isinstance(manifest, dict) or set(manifest) != {"schema", "product", "version", "build_id", "files"} \
                or type(manifest["schema"]) is not int or manifest["schema"] != 1 \
                or manifest["product"] != "Velvet Vixxen" or manifest["version"] != version \
                or manifest["build_id"] != build_id or not isinstance(manifest["files"], dict):
            raise ValueError("Runtime build inventory does not match the package identity")
        hashes = manifest["files"]
        if (required_files("upgrade", version) - {RUNTIME_BUILD_PATH}) - hashes.keys():
            raise ValueError("Runtime build inventory is missing required upgrade files")
        for name, digest in hashes.items():
            if name == RUNTIME_BUILD_PATH or not eligible(name, "upgrade") \
                    or not isinstance(digest, str) or not re.fullmatch(r"[a-f0-9]{64}", digest):
                raise ValueError("Runtime build inventory contains an invalid entry")
        for name, content in files.items():
            if name != RUNTIME_BUILD_PATH and eligible(name, "upgrade") \
                    and hashes.get(name) != hashlib.sha256(content).hexdigest():
                raise ValueError(f"Runtime build inventory does not match {name}")
        if kind in {"fresh", "upgrade"} and files[RUNTIME_BUILD_PATH] != runtime_build_manifest(files):
            raise ValueError("Runtime build inventory is not the complete generated upgrade inventory")
    for name, content in files.items():
        if not eligible(name, kind):
            raise ValueError(f"Disallowed package entry: {name}")
        if re.search(rb"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----\r?\n", content):
            raise ValueError(f"Private key material detected in {name}")
    if "config/app.php" in files or "storage/install.lock" in files:
        raise ValueError("Installed state must never enter a distribution")
    if kind == "fresh":
        defaults = json.loads(files["storage/defaults/data.json"])
        private_collections = {
            "admins", "members", "orders", "orderItems", "subscriptions", "entitlements",
            "memberSessions", "memberTokens", "memberEvents", "memberMediaState",
            "messages", "messageThreads", "messageAttachments", "contentRequests",
            "bookingRequests", "requestAttachments", "memberNotifications", "mailOutbox",
            "paymentTransactions", "paymentWebhookEvents", "couponRedemptions", "audit",
            "addonLicenses", "issuedAddonLicenses", "addonLicenseActivations",
            "addonLicenseTransactions", "addonDownloadTokens", "addonBackups",
        }
        if any(defaults.get(key) for key in private_collections):
            raise ValueError("Fresh-install defaults contain runtime or private records")


def build(root: Path, output: Path, version: str) -> dict[str, object]:
    if not re.fullmatch(r"\d+\.\d+\.\d+", version):
        raise ValueError("Use a numeric major.minor.patch version")
    # One immutable source snapshot supplies every variant and its identical
    # installed-file inventory, even when the caller requests several packages.
    source = _source_runtime_files(root, "fresh")
    runtime_version, build_id = package_identity(source)
    if version != runtime_version:
        raise ValueError("Requested package version does not match runtime source")
    manifest = runtime_build_manifest(source)
    manifest_filename = f"Velvet-Vixxen-{version}-Build-{build_id}-package-manifest.json"
    output.mkdir(parents=True, exist_ok=True)
    result: dict[str, object] = {
        "version": version, "build_id": build_id, "manifest_filename": manifest_filename, "packages": [],
    }
    major, minor, patch = map(int, version.split("."))
    if (major, minor) != (0, 8):
        raise ValueError("Upgrade compatibility must be reviewed before leaving the 0.8.x release series")
    supported_upgrades = [f"0.8.{previous}" for previous in range(patch + 1)]
    variants = [("fresh", "Fresh-Install", version), ("upgrade", "Upgrade-from-0.8.x", version),
                ("installer-repair", "Installer-Database-Repair", INSTALLER_REPAIR_VERSION)]
    for kind, suffix, package_version in variants:
        files = _files_for_kind(source, kind, manifest)
        validate(files, kind, package_version)
        # A new installer-only repair has its own explicit identity without
        # claiming to update the installed site's complete runtime inventory.
        package_build = INSTALLER_REPAIR_BUILD_ID if kind == "installer-repair" else build_id
        identity = {"repair_build_id": package_build} if kind == "installer-repair" else {"build_id": build_id}
        build_tag = f"-Build-{package_build}"
        filename = f"Nikki-Wilder-Velvet-Vixxen-{package_version}{build_tag}-{suffix}.zip"
        archive = output / filename
        compatibility = {
            "supported_from": supported_upgrades if kind == "upgrade" else [],
        }
        if kind == "installer-repair":
            compatibility = {
                "supported_from": [INSTALLER_REPAIR_VERSION],
                "installation_state": "incomplete",
            }
        with ZipFile(archive, "w", compression=ZIP_DEFLATED, compresslevel=9) as zipped:
            zipped.comment = json.dumps({
                "product": "Velvet Vixxen", "version": package_version, "kind": kind,
                **identity, **compatibility,
            }, separators=(",", ":")).encode()
            for relative, content in files.items():
                timestamp = (RELEASE_TIMESTAMP
                             if relative in RELEASE_FILES
                             else PRIOR_RELEASE_TIMESTAMPS[relative]
                             if relative in PRIOR_RELEASE_TIMESTAMPS
                             else INSTALLER_FILESYSTEM_TIMESTAMP
                             if relative == "install/services/FileSystemService.php"
                             else INSTALLER_MAIL_TIMESTAMP
                             if relative in INSTALLER_MAIL_REPAIR_FILES
                             else INSTALLER_DATABASE_TIMESTAMP
                             if relative in INSTALLER_DATABASE_REPAIR_FILES
                             else COMPOSER_REPAIR_TIMESTAMP
                             if relative in COMPOSER_REPAIR_FILES
                             else (2026, 1, 1, 0, 0, 0))
                entry = ZipInfo(relative, date_time=timestamp)
                entry.create_system = 3
                entry.external_attr = 0o100644 << 16
                entry.compress_type = ZIP_DEFLATED
                zipped.writestr(entry, content, compress_type=ZIP_DEFLATED, compresslevel=9)
        with ZipFile(archive) as zipped:
            if zipped.testzip() is not None:
                raise ValueError(f"Archive integrity check failed: {filename}")
            if set(zipped.namelist()) != set(files):
                raise ValueError(f"Archive entry verification failed: {filename}")
        result["packages"].append({
            "kind": kind, "version": package_version, "filename": filename, **identity, "size_bytes": archive.stat().st_size,
            "sha256": hashlib.sha256(archive.read_bytes()).hexdigest(),
            "files": len(files), "php_files": sum(name.endswith(".php") for name in files),
            "javascript_files": sum(name.endswith(".js") for name in files),
            "entry_sha256": {name: hashlib.sha256(data).hexdigest() for name, data in files.items()},
            **compatibility,
        })
    (output / manifest_filename).write_text(
        json.dumps(result, indent=2) + "\n"
    )
    return result


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    root = args.root.resolve()
    version = (root / "VERSION").read_text().strip()
    result = build(root, args.output.resolve(), version)
    print(json.dumps({"version": result["version"], "build_id": result["build_id"],
                     "manifest_filename": result["manifest_filename"], "packages": [
        {key: value for key, value in entry.items() if key != "entry_sha256"}
        for entry in result["packages"]
    ]}, indent=2))


if __name__ == "__main__":
    main()

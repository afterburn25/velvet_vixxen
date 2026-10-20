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
PRIOR_RELEASE_TIMESTAMPS = {
    '.htaccess': (2026, 10, 20, 2, 24, 0),
    'VERSION': (2026, 10, 20, 5, 20, 0),
    'admin/addon-recovery.php': (2026, 10, 20, 5, 0, 0),
    'admin/index.php': (2026, 10, 20, 3, 48, 0),
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
    'app/Core/MarketingAudience.php': (2026, 10, 20, 5, 20, 0),
    'app/Core/MarketingStore.php': (2026, 10, 20, 5, 20, 0),
    'app/Core/MediaAdminService.php': (2026, 10, 20, 3, 48, 0),
    'app/Core/MediaInput.php': (2026, 10, 20, 3, 48, 0),
    'app/Core/MediaStoreTrait.php': (2026, 10, 20, 3, 48, 0),
    'app/Core/MemberStoreTrait.php': (2026, 10, 20, 3, 48, 0),
    'app/Core/MigrationResumeSchema.php': (2026, 10, 19, 16, 0, 0),
    'app/Core/MigrationRunner.php': (2026, 10, 19, 16, 0, 0),
    'app/Core/RepairBackupService.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/RootLayoutService.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/RuntimeBuildService.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/RuntimeInventoryService.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/SceneButton.php': (2026, 10, 20, 2, 24, 0),
    'app/Core/Store.php': (2026, 10, 20, 3, 48, 0),
    'app/Support/functions.php': (2026, 10, 20, 2, 24, 0),
    'app/Support/mail_settings_admin.php': (2026, 10, 20, 2, 24, 0),
    'app/Support/media_admin.php': (2026, 10, 20, 3, 48, 0),
    'app/Support/public_routes.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/admin/addon-licensing.php': (2026, 10, 20, 3, 48, 0),
    'app/Views/admin/addons.php': (2026, 10, 20, 5, 0, 0),
    'app/Views/admin/commerce.php': (2026, 10, 20, 3, 48, 0),
    'app/Views/admin/communications.php': (2026, 10, 20, 3, 48, 0),
    'app/Views/admin/composer.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/admin/mail-settings-actions.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/admin/mail-settings.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/admin/marketing-actions.php': (2026, 10, 20, 5, 20, 0),
    'app/Views/admin/marketing-audiences.php': (2026, 10, 20, 5, 20, 0),
    'app/Views/admin/marketing-campaign-form.php': (2026, 10, 20, 5, 20, 0),
    'app/Views/admin/marketing.php': (2026, 10, 20, 5, 20, 0),
    'app/Views/admin/media-center.php': (2026, 10, 20, 3, 48, 0),
    'app/Views/admin/media-library.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/admin/members.php': (2026, 10, 20, 3, 48, 0),
    'app/Views/admin/navigation.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/admin/repair-backups.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/admin/repair-status.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/admin/update-status.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/public/partials/footer.php': (2026, 10, 20, 2, 24, 0),
    'app/Views/public/partials/scene-buttons.php': (2026, 10, 20, 2, 24, 0),
    'config/app.example.php': (2026, 10, 20, 3, 48, 0),
    'includes/installation_recovery.php': (2026, 10, 19, 16, 0, 0),
    'includes/marketing.php': (2026, 10, 20, 2, 24, 0),
    'includes/marketing_admin.php': (2026, 10, 20, 5, 20, 0),
    'includes/marketing_public_shell.php': (2026, 10, 20, 2, 24, 0),
    'includes/runtime-build.json': (2026, 10, 20, 5, 20, 0),
    'includes/version.php': (2026, 10, 20, 5, 20, 0),
    'install/index.php': (2026, 10, 20, 2, 24, 0),
    'install/services/EmailAddress.php': (2026, 10, 20, 2, 24, 0),
    'install/services/FileSystemService.php': (2026, 10, 19, 0, 0, 0),
    'install/services/InstallerService.php': (2026, 10, 20, 3, 48, 0),
    'install/services/SmtpTester.php': (2026, 10, 20, 2, 24, 0),
    'install/views/single-page.php': (2026, 10, 20, 3, 48, 0),
    'public/assets/css/composer.css': (2026, 10, 20, 2, 24, 0),
    'public/assets/css/installer.css': (2026, 10, 19, 16, 0, 0),
    'public/assets/css/mail-settings.css': (2026, 10, 20, 2, 24, 0),
    'public/assets/css/media-admin-edit.css': (2026, 10, 20, 3, 48, 0),
    'public/assets/css/site-ux.css': (2026, 10, 20, 2, 24, 0),
    'public/assets/css/site.css': (2026, 10, 20, 2, 24, 0),
    'public/assets/js/admin-media.js': (2026, 10, 20, 3, 48, 0),
    'public/assets/js/admin.js': (2026, 10, 20, 2, 24, 0),
    'public/assets/js/api-client.js': (2026, 10, 20, 2, 24, 0),
    'public/assets/js/composer.js': (2026, 10, 20, 2, 24, 0),
    'public/assets/js/installer.js': (2026, 10, 19, 16, 0, 0),
    'public/assets/js/mail-settings.js': (2026, 10, 20, 2, 24, 0),
    'public/assets/js/marketing.js': (2026, 10, 20, 2, 24, 0),
    'public/assets/js/media-center.js': (2026, 10, 20, 2, 24, 0),
    'public/assets/js/site.js': (2026, 10, 20, 2, 24, 0),
    'public/home.php': (2026, 10, 20, 2, 24, 0),
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
    'public/routes/blog/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/blog/post.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/booking/index.php': (2026, 10, 20, 2, 24, 0),
    'public/routes/campaign/index.php': (2026, 10, 20, 2, 24, 0),
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
RELEASE_TIMESTAMP = (2026, 10, 20, 5, 46, 48)
RELEASE_FILES = {
    'VERSION',
    'admin/index.php',
    'app/Core/MarketingPublication.php',
    'app/Core/MarketingStore.php',
    'app/Views/admin/marketing-actions.php',
    'app/Views/admin/marketing-publication-editor.php',
    'app/Views/admin/marketing-publication-list.php',
    'app/Views/admin/marketing.php',
    'includes/marketing_admin.php',
    'includes/runtime-build.json',
    'includes/version.php',
    'public/assets/css/marketing.css',
    'public/routes/blog/index.php',
    'public/routes/blog/post.php',
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
    prior_release = json.loads((ROOT / "tests/Packaging/fixtures/release-0.8.4-build-20261020.5.json").read_text())
    assert prior_release["source_commit"] == "b7a7559b7fe44c97d8604af24d56539bb009098b"
    assert prior_release["version"] == "0.8.4" and prior_release["build_id"] == "20261020.5"
    assert prior_release["sha256"] == "c9b861cc87ea3ee0850a81df48c1081ea44e6f5bfc12d0224f989abdc0ea4220"
    assert prior_release["files"] == len(prior_release["entries"]) == 346, "Prior-release fixture must retain the exact delivered Fresh inventory"
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
        assert package.RELEASE_FILES == RELEASE_FILES and package.RELEASE_TIMESTAMP == RELEASE_TIMESTAMP
        assert package.PRIOR_RELEASE_TIMESTAMPS == PRIOR_RELEASE_TIMESTAMPS
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
                    assert changed == RELEASE_FILES, "Changed runtime files lack reviewed release timestamps, or unchanged files were relabeled: " + ", ".join(sorted(changed ^ RELEASE_FILES))
                    for entry in zipped.infolist():
                        prior = prior_release["entries"].get(entry.filename)
                        if prior is not None:
                            if entry.filename in changed:
                                assert entry.date_time > tuple(prior["timestamp"]), "Changed PHP/assets would retain a stale delivered timestamp: " + entry.filename
                            else:
                                assert entry.date_time == tuple(prior["timestamp"]), "Unchanged runtime file timestamp differs from the prior delivery: " + entry.filename
                elif release["kind"] == "upgrade":
                    expected_from = [f"0.8.{patch}" for patch in range(int(version.split(".")[2]) + 1)]
                    assert release["supported_from"] == compatibility["supported_from"] == expected_from, "Upgrade compatibility omitted a supported prior patch or current-version repair"
                    assert not any(Path(name).parts[0] in package.PRESERVED_UPGRADE_ROOTS for name in entries)
                    with ZipFile(work / "first" / releases["fresh"]["filename"]) as fresh:
                        for relative in REPAIR_FILES | COMPOSER_REPAIR_FILES:
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

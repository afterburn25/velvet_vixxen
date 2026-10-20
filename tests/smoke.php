<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'index.php', 'admin/index.php', 'install/index.php', 'install/bootstrap.php',
    'install/views/single-page.php',
    'install/services/InstallerService.php', 'install/services/FileSystemService.php',
    'install/services/InstallerProgress.php', 'install/services/SmtpTester.php',
    'public/routes/api/scene.php', 'public/routes/api/upload.php', 'public/routes/api/member-state.php', 'includes/bootstrap.php',
    'database/migrations/202610170001_core.php',
    'database/migrations/202610170004_media_and_commerce_foundation.php',
    'database/migrations/202610180005_media_center.php',
    'database/migrations/202610180006_media_center_metadata.php',
    'database/migrations/202610180007_members_and_entitlements.php',
    'app/Core/MemberStoreTrait.php', 'app/Core/MemberAuth.php', 'app/Core/MailService.php',
    'app/Core/EntitlementResolver.php', 'app/Views/admin/members.php',
    'public/assets/css/site.css', 'public/assets/css/installer.css', 'public/assets/css/member.css',
    'public/assets/js/composer.js', 'public/assets/js/installer.js',
    'public/assets/css/media-center.css', 'public/assets/js/media-center.js', 'public/assets/js/admin-media.js',
    'public/routes/music/index.php', 'public/routes/photos/index.php', 'public/routes/photos/view.php',
    'public/routes/videos/index.php', 'public/routes/videos/watch.php', 'public/routes/media.php',
    'public/routes/membership/index.php', 'public/routes/account/index.php', 'public/routes/account/register.php', 'public/routes/account/login.php',
    'public/routes/account/verify.php', 'public/routes/account/forgot.php', 'public/routes/account/reset.php', 'public/routes/account/logout.php',
    'public/routes/account/library.php', 'public/routes/account/security.php',
    'scripts/build_canva_pptx.js', 'scripts/create_demo.php',
    'images/defaults/hero-pink-room.jpg', 'images/defaults/footer-pink-room.jpg',
];
$missing = array_values(array_filter($required, static fn(string $file): bool => !is_file($root . '/' . $file)));
if ($missing !== []) {
    fwrite(STDERR, "Missing files:\n- " . implode("\n- ", $missing) . "\n");
    exit(1);
}

$version = trim((string) file_get_contents($root . '/VERSION'));
if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $version) || version_compare($version, '0.8.1', '<')) {
    fwrite(STDERR, "VERSION must be a valid recovered release at least 0.8.1; found {$version}.\n");
    exit(1);
}

$defaults = json_decode((string) file_get_contents($root . '/storage/defaults/data.json'), true);
if (!is_array($defaults) || empty($defaults['scenes']['hero']['published']) || empty($defaults['templates'])) {
    fwrite(STDERR, "Default data is invalid.\n");
    exit(1);
}
if (!str_starts_with((string) $defaults['scenes']['hero']['published']['background']['src'], 'images/defaults/')) {
    fwrite(STDERR, "Hero default is not using the organized /images folder.\n");
    exit(1);
}
if (empty($defaults['mediaCenter']['tracks']) || empty($defaults['mediaCenter']['galleries']) || empty($defaults['mediaCenter']['videos'])) {
    fwrite(STDERR, "Default Media Center data is missing.\n");
    exit(1);
}
if (count((array) ($defaults['membershipPlans'] ?? [])) < 3) {
    fwrite(STDERR, "Default membership plans are missing.\n");
    exit(1);
}
foreach (['members','subscriptions','entitlements','memberMediaState','memberSessions','memberTokens','memberEvents','mailOutbox','contentAccessRules'] as $key) {
    if (!array_key_exists($key, $defaults)) {
        fwrite(STDERR, "Default member-system key is missing: {$key}.\n");
        exit(1);
    }
}

$installerIndex = (string) file_get_contents($root . '/install/index.php');
$installerView = (string) file_get_contents($root . '/install/views/single-page.php');
if (!str_contains($installerView, 'minlength="12"')) {
    fwrite(STDERR, "The installer does not expose the 12-character minimum.\n");
    exit(1);
}
if (preg_match('/name=["\'](?:install_key|setup_key)["\']/i', $installerIndex . $installerView)) {
    fwrite(STDERR, "An install-key input was found.\n");
    exit(1);
}
if (str_contains($installerIndex, '?step=') || is_dir($root . '/install/steps')) {
    fwrite(STDERR, "Legacy multi-step installer routing is still present.\n");
    exit(1);
}
foreach (['readiness', 'database', 'owner-site', 'install-review'] as $section) {
    if (!str_contains($installerView, 'id="' . $section . '"')) {
        fwrite(STDERR, "Single-page installer section missing: {$section}.\n");
        exit(1);
    }
}

$config = require $root . '/config/app.php';
if (($config['mail']['mode'] ?? null) !== 'disabled') {
    fwrite(STDERR, "Default mail mode must be disabled.\n");
    exit(1);
}
require_once $root . '/includes/version.php';
if (($config['version'] ?? null) !== $version || VELVET_VIXXEN_VERSION !== $version) {
    fwrite(STDERR, "VERSION, version constant, and default configuration must match.\n");
    exit(1);
}

echo "Smoke test passed.\n";

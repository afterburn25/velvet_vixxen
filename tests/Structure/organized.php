<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$requiredDirectories = ['admin','addons','app','config','database','docs','images','includes','install','public','public/routes','scripts','storage','templates','tests','uploads','vendor'];
foreach ($requiredDirectories as $directory) {
    if (!is_dir($root . '/' . $directory)) {
        fwrite(STDERR, "Missing required directory: {$directory}\n");
        exit(1);
    }
}
// media.php is a thin compatibility entrypoint for existing signed media URLs;
// all delivery and authorization logic stays in public/routes/media.php.
$allowedRootPhp = ['index.php','media.php','route.php'];
$rootPhp = array_map('basename', glob($root . '/*.php') ?: []);
sort($rootPhp); sort($allowedRootPhp);
if ($rootPhp !== $allowedRootPhp) {
    fwrite(STDERR, 'Unexpected PHP files in repository root: ' . implode(', ', array_diff($rootPhp, $allowedRootPhp)) . PHP_EOL);
    exit(1);
}
$protected = ['app','config','database','docs','includes','scripts','storage','templates','tests','vendor'];
foreach ($protected as $directory) {
    if (!is_file($root . '/' . $directory . '/.htaccess')) {
        fwrite(STDERR, "Protected directory lacks .htaccess: {$directory}\n");
        exit(1);
    }
}

$requiredAddonFiles = [
    'app/Core/AddonManifest.php','app/Core/AddonPackageInspector.php','app/Core/AddonManager.php',
    'app/Core/AddonRuntime.php','app/Core/AddonContext.php','app/Core/AddonStoreTrait.php',
    'app/Views/admin/addons.php','admin/download.php','addons/packages/velvet-welcome-banner-1.0.0.zip',
    'addons/sdk/velvet-welcome-banner/addon.json','public/routes/extension/index.php',
    'database/migrations/202610180009_addon_framework.php',
];
foreach ($requiredAddonFiles as $file) {
    if (!is_file($root . '/' . $file)) {
        fwrite(STDERR, "Missing add-on framework file: {$file}\n");
        exit(1);
    }
}

echo "Repository structure test passed.\n";

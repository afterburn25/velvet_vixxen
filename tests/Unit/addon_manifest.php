<?php
declare(strict_types=1);

use App\Core\AddonManifest;
use App\Core\AddonPermissionCatalog;

$root = dirname(__DIR__, 2);
define('ROOT_PATH', $root);
require $root . '/includes/bootstrap.php';

$failures = [];
$raw = json_decode((string) file_get_contents($root . '/addons/sdk/velvet-welcome-banner/addon.json'), true);
if (!is_array($raw)) {
    fwrite(STDERR, "Starter add-on manifest is invalid JSON.\n");
    exit(1);
}

try {
    $manifest = AddonManifest::fromArray($raw);
    if ($manifest->id() !== 'velvet.welcome-banner') $failures[] = 'Starter manifest id was not normalized.';
    if ($manifest->version() !== '1.0.0') $failures[] = 'Starter manifest semantic version was not preserved.';
    if (!$manifest->supportsCore('0.6.0') || !$manifest->supportsCore('0.9.9') || $manifest->supportsCore('1.0.0')) {
        $failures[] = 'Core compatibility range was evaluated incorrectly.';
    }
    if (!$manifest->supportsPhp(PHP_VERSION)) $failures[] = 'Starter manifest incorrectly rejects the active PHP version.';
    if (count($manifest->permissions()) !== 9) $failures[] = 'Starter manifest permissions were not retained.';
} catch (Throwable $e) {
    $failures[] = 'Valid starter manifest was rejected: ' . $e->getMessage();
}

$expectFailure = static function (array $manifest, string $label) use (&$failures): void {
    try {
        AddonManifest::fromArray($manifest);
        $failures[] = $label . ' was accepted.';
    } catch (RuntimeException) {
    }
};

$invalid = $raw;
$invalid['version'] = 'release-one';
$expectFailure($invalid, 'Invalid semantic version');
$invalid = $raw;
$invalid['permissions'][] = 'server.everything';
$expectFailure($invalid, 'Unknown permission');
$invalid = $raw;
$invalid['bootstrap'] = '../outside.php';
$expectFailure($invalid, 'Traversal bootstrap path');
$invalid = $raw;
$invalid['dependencies'] = ['velvet.welcome-banner' => '*'];
$expectFailure($invalid, 'Self dependency');
$invalid = $raw;
$invalid['id'] = 'UPPER CASE';
$expectFailure($invalid, 'Invalid package id');

try {
    AddonManifest::safeRelativePath('/absolute/path.php');
    $failures[] = 'Absolute package path was accepted.';
} catch (RuntimeException) {
}
try {
    AddonManifest::safeRelativePath('views/../../secret.php');
    $failures[] = 'Traversal package path was accepted.';
} catch (RuntimeException) {
}

$described = AddonPermissionCatalog::describe(['settings.read', 'members.read']);
if (count($described) !== 2 || ($described[1]['risk'] ?? '') !== 'high') {
    $failures[] = 'Permission catalog did not expose expected risk metadata.';
}

if ($failures !== []) {
    fwrite(STDERR, "Add-on manifest test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Add-on manifest test passed.\n";

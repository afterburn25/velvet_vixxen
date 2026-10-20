<?php
declare(strict_types=1);

use App\Core\AddonArchive;
use App\Core\AddonPackageInspector;

$root = dirname(__DIR__, 2);
define('ROOT_PATH', $root);
require $root . '/includes/bootstrap.php';

$failures = [];
$inspector = new AddonPackageInspector();
$starter = $root . '/addons/packages/velvet-welcome-banner-1.0.0.zip';
try {
    $review = $inspector->inspect($starter);
    if (($review['manifest']['id'] ?? '') !== 'velvet.welcome-banner') $failures[] = 'Starter package identity was not read.';
    if (($review['checksums']['status'] ?? '') !== 'verified') $failures[] = 'Starter package checksums were not verified.';
    if (($review['signature']['status'] ?? '') !== 'unsigned') $failures[] = 'Starter package signature state was not reported accurately.';
    if (($review['operation'] ?? '') !== 'install') $failures[] = 'Fresh package was not classified as an install.';
    if (count((array) ($review['permissions'] ?? [])) !== 9) $failures[] = 'Permission review did not describe all requested permissions.';
} catch (Throwable $e) {
    $failures[] = 'Starter package inspection failed: ' . $e->getMessage();
}

$tempRoot = $root . '/storage/temp/addon-package-' . bin2hex(random_bytes(5));
@mkdir($tempRoot . '/package', 0775, true);
$source = $root . '/addons/sdk/velvet-welcome-banner';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($iterator as $entry) {
    $relative = substr($entry->getPathname(), strlen($source) + 1);
    $target = $tempRoot . '/package/' . $relative;
    if ($entry->isDir()) @mkdir($target, 0775, true); else { @mkdir(dirname($target), 0775, true); copy($entry->getPathname(), $target); }
}
file_put_contents($tempRoot . '/package/views/widget.php', "<?php echo 'tampered';\n");
$tampered = $tempRoot . '/tampered.zip';
AddonArchive::createFromDirectory($tempRoot . '/package', $tampered);
try {
    $inspector->inspect($tampered);
    $failures[] = 'A package with a tampered checksummed file was accepted.';
} catch (RuntimeException $e) {
    if (!str_contains(strtolower($e->getMessage()), 'checksum')) {
        $failures[] = 'Tampered package failed for an unexpected reason: ' . $e->getMessage();
    }
}

// A local backup archive must omit runtime installation metadata and remain inspectable.
file_put_contents($tempRoot . '/package/views/widget.php', (string) file_get_contents($source . '/views/widget.php'));
file_put_contents($tempRoot . '/package/.package.json', '{"installedAt":"test"}');
$backup = $tempRoot . '/backup.zip';
AddonArchive::createFromDirectory($tempRoot . '/package', $backup);
try {
    $backupReview = $inspector->inspect($backup);
    $names = array_column((array) $backupReview['files'], 'name');
    if (in_array('.package.json', $names, true)) $failures[] = 'Runtime .package.json leaked into an add-on backup.';
} catch (Throwable $e) {
    $failures[] = 'A local add-on backup was not re-inspectable: ' . $e->getMessage();
}

$delete = static function (string $path) use (&$delete): void {
    if (!file_exists($path)) return;
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $child) $delete($child->getPathname());
        @rmdir($path);
    } else {
        @unlink($path);
    }
};
$delete($tempRoot);

if ($failures !== []) {
    fwrite(STDERR, "Add-on package test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Add-on package test passed.\n";

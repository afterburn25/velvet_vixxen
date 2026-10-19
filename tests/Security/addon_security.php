<?php
declare(strict_types=1);

use App\Core\AddonContext;
use App\Core\AddonManager;
use App\Core\AddonRuntime;

require dirname(__DIR__) . '/TestEnvironment.php';

$environment = velvet_test_environment('addon-security');
$root = $environment['root'];
$store = $environment['store'];
$failures = [];
$tempRoot = $root . '/storage/temp/addon-security-' . bin2hex(random_bytes(5));
@mkdir($tempRoot . '/views', 0775, true);
file_put_contents($tempRoot . '/views/test.php', '<?php echo "test";');

try {
    $runtime = new AddonRuntime($store);
    $record = ['addonKey' => 'security.test-addon', 'version' => '1.0.0', 'permissions' => [], 'manifest' => []];
    $context = new AddonContext($runtime, $store, $record, $tempRoot);
    $blocked = false;
    try {
        $context->registerAdminPage('test-page', 'Test', 'T', 'views/test.php');
    } catch (RuntimeException $e) {
        $blocked = str_contains($e->getMessage(), 'undeclared permission');
    }
    if (!$blocked) $failures[] = 'Runtime capability was available without its declared permission.';

    foreach (['../secret.php', '/etc/passwd', 'C:/windows/system.ini'] as $path) {
        try {
            $context->path($path);
            $failures[] = 'Unsafe add-on path was accepted: ' . $path;
        } catch (RuntimeException) {
        }
    }

    $manager = new AddonManager($store);
    $review = $manager->stageBundledStarter();
    $blocked = false;
    try {
        $manager->install((string) $review['token'], false, false);
    } catch (RuntimeException $e) {
        $blocked = str_contains(strtolower($e->getMessage()), 'trust');
    }
    if (!$blocked) $failures[] = 'Unsigned package trust confirmation was bypassed.';

    $admin = (string) file_get_contents($root . '/admin/index.php');
    foreach (['verify_csrf()', 'require_admin()', 'inspect_addon_upload', 'trust_package', 'toggle_addon_safe_mode'] as $needle) {
        if (!str_contains($admin, $needle)) $failures[] = 'Admin add-on boundary is missing: ' . $needle;
    }
    $inspector = (string) file_get_contents($root . '/app/Core/AddonPackageInspector.php');
    foreach (['MAX_PACKAGE_BYTES', 'MAX_UNCOMPRESSED_BYTES', 'Symbolic links are not allowed', 'checksums.json does not cover every package file', 'sodium_crypto_sign_verify_detached'] as $needle) {
        if (!str_contains($inspector, $needle)) $failures[] = 'Package inspector is missing safety behavior: ' . $needle;
    }
} catch (Throwable $e) {
    $failures[] = 'Unexpected add-on security exception: ' . $e->getMessage();
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION = [];
    $delete = static function (string $path) use (&$delete): void {
        if (!file_exists($path)) return;
        if (is_dir($path) && !is_link($path)) { foreach (new FilesystemIterator($path) as $child) $delete($child->getPathname()); @rmdir($path); }
        else @unlink($path);
    };
    $delete($tempRoot);
    velvet_test_cleanup($environment);
}

velvet_finish_test('Add-on security test', $failures);

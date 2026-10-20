<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('ROOT_PATH', $root);
require $root . '/app/bootstrap.php';
require_once $root . '/install/services/InstallerLog.php';
require_once $root . '/install/services/FileSystemService.php';
require_once $root . '/install/services/RequirementChecker.php';
require_once $root . '/install/services/EmailAddress.php';
require_once $root . '/install/services/InstallerService.php';

use Velvet\Installer\FileSystemService;
use Velvet\Installer\InstallerLog;
use Velvet\Installer\InstallerService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$temp = sys_get_temp_dir() . '/vvx-installer-test-' . bin2hex(random_bytes(5));
mkdir($temp, 0775, true);
mkdir($temp . '/storage/logs', 0775, true);
mkdir($temp . '/storage/private', 0775, true);
mkdir($temp . '/install/sub', 0775, true);
file_put_contents($temp . '/install/index.php', '<?php echo "installer";');
file_put_contents($temp . '/install/sub/test.txt', 'test');

$log = new InstallerLog($temp, 'test');
$fs = new FileSystemService($temp, $log);
// This unit fixture stands in for a fully extracted package. Static runtime
// directories must already exist; the installer only creates writable state.
foreach ($fs->directoryManifest() as $relative => $definition) {
    if (!$definition['writable'] && !is_dir($temp . '/' . $relative)) {
        mkdir($temp . '/' . $relative, 0755, true);
    }
}
$rows = $fs->prepareAndProbe();
$assert($fs->allRequiredWritable($rows), 'Required folder probe did not pass in a writable temporary directory.');
foreach ($rows as $row) {
    $assert($row['mode'] !== '0777', 'A folder was assigned chmod 0777: ' . $row['path']);
}
$cleanup = $fs->retireInstaller('test');
$assert($cleanup['public_removed'] === true, 'The public /install directory was not removed.');
$assert(!is_dir($temp . '/install'), 'The /install path still exists after cleanup.');

// Verify the exact password boundary without performing a database install.
$service = new InstallerService($temp, $log);
$method = new ReflectionMethod($service, 'validateOwner');
$method->setAccessible(true);
try {
    $method->invoke($service, ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => '12345678901']);
    $failures[] = 'An 11-character password was accepted.';
} catch (Throwable) {
    // Expected.
}
try {
    $accepted = $method->invoke($service, ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => '123456789012']);
    $assert(is_array($accepted), 'A 12-character password was not accepted.');
} catch (Throwable $e) {
    $failures[] = 'A 12-character password was rejected: ' . $e->getMessage();
}


// Verify installer logs redact nested secrets.
$log->write('sensitive.test', [
    'password' => 'plain-secret-value',
    'database' => ['password' => 'nested-secret-value'],
    'token' => 'token-secret-value',
]);
$logContents = (string) file_get_contents($log->path());
$assert(!str_contains($logContents, 'plain-secret-value'), 'Installer log exposed a top-level password.');
$assert(!str_contains($logContents, 'nested-secret-value'), 'Installer log exposed a nested database password.');
$assert(!str_contains($logContents, 'token-secret-value'), 'Installer log exposed a token.');
$assert(str_contains($logContents, '[redacted]'), 'Installer log did not mark secrets as redacted.');

// Verify DSN delimiter/control input is rejected before a connection attempt.
$dbMethod = new ReflectionMethod($service, 'validateDatabase');
$dbMethod->setAccessible(true);
try {
    $dbMethod->invoke($service, ['host' => 'localhost;unix_socket=/tmp/mysql.sock', 'port' => 3306, 'name' => 'site', 'user' => 'owner']);
    $failures[] = 'A database host containing a DSN delimiter was accepted.';
} catch (Throwable) {
    // Expected.
}
try {
    $dbMethod->invoke($service, ['host' => 'localhost', 'port' => 3306, 'name' => 'site; charset=latin1', 'user' => 'owner']);
    $failures[] = 'A database name containing a DSN delimiter was accepted.';
} catch (Throwable) {
    // Expected.
}
try {
    $acceptedDb = $dbMethod->invoke($service, ['host' => 'db.example.com', 'port' => 3306, 'name' => 'velvet_site-1', 'user' => 'owner']);
    $assert(is_array($acceptedDb), 'A normal database configuration was rejected.');
} catch (Throwable $e) {
    $failures[] = 'A normal database configuration was rejected: ' . $e->getMessage();
}

$delete = static function (string $path) use (&$delete): void {
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $delete($path . '/' . $item);
    }
    @rmdir($path);
};
$delete($temp);

if ($failures !== []) {
    fwrite(STDERR, "Installer foundation test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Installer foundation test passed.\n";

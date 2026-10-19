<?php
declare(strict_types=1);

// Invoked by packaged_preflight.py outside the customer ZIP. Never boot a site.
$root = $argv[1] ?? '';
$action = $argv[2] ?? '';
if ($root === '' || !is_file(dirname($root) . '/.vvx-packaged-preflight-fixture')) {
    throw new RuntimeException('An isolated package fixture is required.');
}
require $root . '/install/services/InstallerLog.php';
require $root . '/install/services/FileSystemService.php';

if ($action === 'manifest') {
    // Reading the manifest must not initialize logging or runtime directories.
    $service = (new ReflectionClass(\Velvet\Installer\FileSystemService::class))->newInstanceWithoutConstructor();
    echo json_encode($service->directoryManifest(), JSON_THROW_ON_ERROR);
    exit;
}
if ($action !== 'probe') {
    throw new RuntimeException('Unknown package fixture action.');
}

$guards = [];
foreach (['images', 'public/assets', 'templates'] as $relative) {
    $directory = $root . '/' . $relative;
    clearstatcache(true, $directory);
    $mode = fileperms($directory) & 0777;
    $probe = $directory . '/.vvx-package-permission-guard';
    if ($mode !== 0755 || !is_readable($directory) || is_writable($directory)) {
        throw new RuntimeException('Ineffective foreign-owner 0755 fixture: ' . $relative);
    }
    if (@chmod($directory, 0755)) {
        throw new RuntimeException('PHP can change the panel-owned directory: ' . $relative);
    }
    if (@mkdir($probe, 0755)) {
        @rmdir($probe);
        throw new RuntimeException('PHP can create a directory in the supposedly protected asset tree: ' . $relative);
    }
    $guards[$relative] = ['mode' => '0755', 'readable' => true, 'write_denied' => true, 'chmod_denied' => true];
}

$log = new \Velvet\Installer\InstallerLog($root, 'packaged-preflight');
$service = new \Velvet\Installer\FileSystemService($root, $log);
$rows = $service->prepareAndProbe();
echo json_encode([
    'guards' => $guards,
    'ready' => $service->allRequiredWritable($rows),
    'rows' => $rows,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

<?php
declare(strict_types=1);

// This helper is served only by the disposable localhost test, never shipped.
if (!is_file(dirname(__DIR__) . '/.vvx-opcache-fixture')) {
    throw new RuntimeException('An isolated OPcache package fixture is required.');
}
if (!function_exists('opcache_get_status')) {
    throw new RuntimeException('The real OPcache extension is required.');
}
header('Content-Type: application/json');
$source = __DIR__ . '/install/services/FileSystemService.php';
require $source;
$service = (new ReflectionClass(\Velvet\Installer\FileSystemService::class))->newInstanceWithoutConstructor();
$manifest = $service->directoryManifest();
clearstatcache(true, $source);
$status = opcache_get_status(true);
echo json_encode([
    'php_version' => PHP_VERSION,
    'pid' => getmypid(),
    'legacy_fixture' => defined(\Velvet\Installer\FileSystemService::class . '::LEGACY_OVERLAY_FIXTURE'),
    'old_manifest' => isset($manifest['images/backgrounds']),
    'hero_manifest' => isset($manifest['images/hero']),
    'file_mtime' => filemtime($source),
    'file_size' => filesize($source),
    'source_sha256' => hash_file('sha256', $source),
    'cached' => opcache_is_script_cached($source),
    'cache_timestamp' => $status['scripts'][$source]['timestamp'] ?? null,
    'cache_hits' => $status['scripts'][$source]['hits'] ?? null,
    'validate_timestamps' => ini_get('opcache.validate_timestamps'),
    'revalidate_freq' => ini_get('opcache.revalidate_freq'),
    'opcache_enabled' => $status['opcache_enabled'] ?? false,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

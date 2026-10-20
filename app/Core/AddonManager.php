<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class AddonManager
{
    public function __construct(
        private readonly Store $store,
        private readonly AddonPackageInspector $inspector = new AddonPackageInspector(),
    ) {
    }

    /** @return array<string,mixed> */
    public function stageUpload(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadError($error));
        }
        $source = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? 'addon.zip');
        if (!is_file($source) || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('Choose a valid ZIP add-on package.');
        }
        $token = bin2hex(random_bytes(18));
        $staging = root_path('addons/staging');
        $this->ensureDirectory($staging, 0775);
        $destination = $staging . '/review-' . $token . '.zip';
        $moved = is_uploaded_file($source) ? move_uploaded_file($source, $destination) : copy($source, $destination);
        if (!$moved) {
            throw new RuntimeException('The uploaded add-on package could not be staged.');
        }
        @chmod($destination, 0640);
        return $this->stagePath($destination, $token);
    }


    /** @param array<string,mixed> $sourceMetadata @return array<string,mixed> */
    public function stageRemotePackage(string $path, string $expectedSha256, array $sourceMetadata = []): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('The downloaded update package is missing.');
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals(strtolower($expectedSha256), strtolower($actual))) {
            @unlink($path);
            throw new RuntimeException('The downloaded update package failed checksum verification.');
        }
        $token = 'remote-' . bin2hex(random_bytes(14));
        return $this->stagePath($path, $token, true, $sourceMetadata);
    }

    /** @return array<string,mixed> */
    public function stageBundledStarter(): array
    {
        $path = root_path('addons/packages/velvet-welcome-banner-1.0.0.zip');
        if (!is_file($path)) {
            throw new RuntimeException('The included starter add-on package is missing.');
        }
        return $this->stagePath($path, 'starter-' . bin2hex(random_bytes(12)), false);
    }

    /** @return array<string,mixed> */
    public function review(string $token): array
    {
        $session = $_SESSION['_addon_reviews'][$token] ?? null;
        if (!is_array($session) || empty($session['path']) || !is_file((string) $session['path'])) {
            throw new RuntimeException('The add-on review expired. Inspect the package again.');
        }
        $path = (string) $session['path'];
        $currentSha = hash_file('sha256', $path);
        if (!is_string($currentSha) || !hash_equals((string) $session['sha256'], $currentSha)) {
            unset($_SESSION['_addon_reviews'][$token]);
            throw new RuntimeException('The staged add-on package changed after inspection.');
        }
        $manifestRaw = $this->readManifest($path);
        $installed = $this->store->addon((string) ($manifestRaw['id'] ?? ''));
        $review = $this->inspector->inspect($path, $installed);
        $review['token'] = $token;
        if (!empty($session['source']) && is_array($session['source'])) {
            $review['source'] = $session['source'];
        }
        return $review;
    }

    /** @return array<string,mixed> */
    public function install(string $token, bool $trusted, bool $activate = false): array
    {
        $review = $this->review($token);
        if (($review['signature']['status'] ?? 'unsigned') !== 'verified' && !$trusted) {
            throw new RuntimeException('Confirm that you trust this unsigned PHP package before installation.');
        }
        $manifest = AddonManifest::fromArray((array) $review['manifest']);
        $this->validateRelationships($manifest);
        if ($activate && $manifest->requiresLicense() && !AddonLicenseClient::recordAllowsActivation($this->store->addonLicense($manifest->id()))) {
            throw new RuntimeException('Install this commercial add-on without activation, then enter and verify its license key before activating it.');
        }
        $id = $manifest->id();
        $current = $this->store->addon($id);
        $currentPath = root_path('addons/installed/' . $id);
        $this->store->logAddonActivity($id, 'package.install.started', ['version' => $manifest->version(), 'operation' => $review['operation']]);

        $backup = null;
        $oldPath = null;
        $migrations = [];
        $appliedMigrationKeys = [];
        $newPath = root_path('addons/staging/install-' . $id . '-' . bin2hex(random_bytes(6)));
        try {
            if ($current !== null && is_dir($currentPath)) {
                $backup = $this->backup($current, 'pre-' . (string) $review['operation']);
            }
            $this->extract((string) $review['packagePath'], $newPath);
            $this->validateExtracted($newPath, $manifest, (string) $review['packageSha256']);
            $migrations = $this->discoverMigrations($newPath);
            if ($migrations !== []) {
                if (!in_array('database.migrate', $manifest->permissions(), true)) {
                    throw new RuntimeException('The package contains database migrations but did not request database.migrate permission.');
                }
                $appliedMigrationKeys = $this->store->applyAddonMigrations($id, $migrations);
            }

            if (is_dir($currentPath)) {
                $oldPath = root_path('addons/staging/old-' . $id . '-' . bin2hex(random_bytes(5)));
                if (!@rename($currentPath, $oldPath)) {
                    throw new RuntimeException('The current add-on files could not be moved into the update staging area.');
                }
            }
            $this->ensureDirectory(dirname($currentPath), 0775);
            if (!@rename($newPath, $currentPath)) {
                if ($oldPath !== null && is_dir($oldPath)) @rename($oldPath, $currentPath);
                throw new RuntimeException('The validated add-on files could not be activated.');
            }
            if ($oldPath !== null) $this->deleteTree($oldPath);
            $this->invalidateOpcodeCache($currentPath);

            $record = $this->store->saveAddon([
                'id' => $id,
                'name' => $manifest->name(),
                'vendor' => $manifest->vendor(),
                'version' => $manifest->version(),
                'status' => $activate ? 'active' : 'installed',
                'manifest' => $manifest->toArray(),
                'permissions' => $manifest->permissions(),
                'packageSha256' => (string) $review['packageSha256'],
                'signatureStatus' => (string) ($review['signature']['status'] ?? 'unsigned'),
                'installedPath' => 'addons/installed/' . $id,
                'previousVersion' => $current['version'] ?? null,
                'failureCount' => 0,
                'lastError' => null,
                'installedAt' => $current['installedAt'] ?? now_iso(),
                'activatedAt' => $activate ? now_iso() : ($current['activatedAt'] ?? null),
            ]);
            $this->store->logAddonActivity($id, 'package.installed', ['version' => $manifest->version(), 'operation' => $review['operation'], 'backup' => $backup['id'] ?? null]);
            if ($activate) $this->store->logAddonActivity($id, 'addon.activated', ['version' => $manifest->version()]);
            $this->cleanupReview($token);
            return $record;
        } catch (Throwable $e) {
            if ($appliedMigrationKeys !== []) {
                try {
                    $this->store->rollbackAddonMigrationKeys($id, $migrations, $appliedMigrationKeys);
                } catch (Throwable $rollbackError) {
                    $this->store->logAddonActivity($id, 'package.migration.rollback_failed', ['error' => $rollbackError->getMessage()], 'error');
                }
            }
            if (is_dir($newPath)) $this->deleteTree($newPath);
            if (!is_dir($currentPath) && $oldPath !== null && is_dir($oldPath)) @rename($oldPath, $currentPath);
            $this->store->logAddonActivity($id, 'package.install.failed', ['error' => $e->getMessage()], 'error');
            throw $e;
        }
    }

    public function activate(string $addonId): array
    {
        $record = $this->requiredAddon($addonId);
        $manifest = AddonManifest::fromArray((array) $record['manifest']);
        $this->validateRelationships($manifest);
        if ($manifest->requiresLicense() && !AddonLicenseClient::recordAllowsActivation($this->store->addonLicense($addonId))) {
            throw new RuntimeException('Activate a valid commercial license before enabling this add-on. Existing installed files were not removed.');
        }
        if (!is_file(root_path((string) $record['installedPath'] . '/addon.json'))) {
            throw new RuntimeException('The add-on files are missing. Reinstall the package before activation.');
        }
        $record['status'] = 'active';
        $record['activatedAt'] = now_iso();
        $record['failureCount'] = 0;
        $record['lastError'] = null;
        $record = $this->store->saveAddon($record);
        $this->store->logAddonActivity($addonId, 'addon.activated', ['version' => $record['version']]);
        return $record;
    }

    public function deactivate(string $addonId): array
    {
        $record = $this->requiredAddon($addonId);
        $record['status'] = 'inactive';
        $record = $this->store->saveAddon($record);
        $this->store->logAddonActivity($addonId, 'addon.deactivated');
        return $record;
    }

    public function uninstall(string $addonId, bool $purgeData = false): void
    {
        $record = $this->requiredAddon($addonId);
        $root = root_path((string) $record['installedPath']);
        $this->store->logAddonActivity($addonId, 'addon.uninstall.started', ['purgeData' => $purgeData]);
        if (is_dir($root)) {
            $this->backup($record, 'pre-uninstall');
            if ($purgeData) {
                $migrations = $this->discoverMigrations($root);
                if ($migrations !== []) $this->store->rollbackAddonMigrations($addonId, $migrations, true);
            }
            $this->deleteTree($root);
        }
        $this->store->deleteAddon($addonId, $purgeData);
        $this->store->logAddonActivity($addonId, 'addon.uninstalled', ['purgeData' => $purgeData]);
    }

    public function rollbackLatest(string $addonId): array
    {
        $record = $this->requiredAddon($addonId);
        $backups = $this->store->addonBackups($addonId);
        if ($backups === []) throw new RuntimeException('No previous package backup is available for this add-on.');
        $backup = $backups[0];
        $path = root_path((string) $backup['path']);
        if (!is_file($path)) throw new RuntimeException('The selected add-on backup file is missing.');
        $review = $this->stagePath($path, 'rollback-' . bin2hex(random_bytes(12)), false);
        return $this->install((string) $review['token'], true, ($record['status'] ?? '') === 'active');
    }

    public function setSafeMode(bool $enabled): void
    {
        $this->store->setAddonsSafeMode($enabled);
        $this->store->logAddonActivity('', $enabled ? 'safe_mode.enabled' : 'safe_mode.disabled');
    }

    /** @return array<string,mixed> */
    private function stagePath(string $path, string $token, bool $temporary = true, array $sourceMetadata = []): array
    {
        $manifest = $this->readManifest($path);
        $installed = $this->store->addon((string) ($manifest['id'] ?? ''));
        $review = $this->inspector->inspect($path, $installed);
        $sha = (string) $review['packageSha256'];
        $_SESSION['_addon_reviews'][$token] = ['path' => $path, 'sha256' => $sha, 'temporary' => $temporary, 'createdAt' => time(), 'source' => $sourceMetadata];
        $review['token'] = $token;
        if ($sourceMetadata !== []) {
            $review['source'] = $sourceMetadata;
        }
        $this->pruneReviews();
        return $review;
    }

    /** @return array<string,mixed> */
    private function readManifest(string $zipPath): array
    {
        $zip = new AddonArchive($zipPath);
        try {
            $raw = $zip->get('addon.json');
            if (!is_string($raw)) throw new RuntimeException('The add-on ZIP does not contain addon.json.');
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new RuntimeException('addon.json must contain an object.');
            return $data;
        } catch (\JsonException $e) {
            throw new RuntimeException('addon.json is invalid JSON.', 0, $e);
        }
    }

    private function validateRelationships(AddonManifest $manifest): void
    {
        foreach ($manifest->dependencies() as $dependency => $constraint) {
            $installed = $this->store->addon($dependency);
            if ($installed === null || ($installed['status'] ?? '') === 'quarantined' || !$this->versionMatches((string) ($installed['version'] ?? ''), $constraint)) {
                throw new RuntimeException('Missing or incompatible dependency: ' . $dependency . ' ' . $constraint . '.');
            }
        }
        foreach ($manifest->conflicts() as $conflict => $constraint) {
            $installed = $this->store->addon($conflict);
            if ($installed !== null && $this->versionMatches((string) ($installed['version'] ?? ''), $constraint)) {
                throw new RuntimeException('This package conflicts with installed add-on ' . $conflict . '.');
            }
        }
    }

    private function versionMatches(string $version, string $constraint): bool
    {
        if ($constraint === '' || $constraint === '*') return $version !== '';
        if (str_starts_with($constraint, '>=')) return version_compare($version, trim(substr($constraint, 2)), '>=');
        if (str_starts_with($constraint, '<=')) return version_compare($version, trim(substr($constraint, 2)), '<=');
        if (str_starts_with($constraint, '^')) {
            $base = substr($constraint, 1); $major = (int) explode('.', $base)[0];
            return version_compare($version, $base, '>=') && version_compare($version, ($major + 1) . '.0.0', '<');
        }
        if (str_ends_with($constraint, '.x')) return str_starts_with($version, substr($constraint, 0, -1));
        return version_compare($version, $constraint, '==');
    }

    private function extract(string $zipPath, string $destination): void
    {
        $this->deleteTree($destination);
        $this->ensureDirectory($destination, 0755);
        $zip = new AddonArchive($zipPath);
        foreach ($zip->entries() as $entry) {
            $raw = (string) ($entry['name'] ?? '');
            $directory = !empty($entry['directory']);
            $relative = rtrim(str_replace('\\', '/', $raw), '/');
            if ($relative === '') continue;
            AddonManifest::safeRelativePath($relative);
            $target = $destination . '/' . $relative;
            if ($directory) {
                $this->ensureDirectory($target, 0755);
                continue;
            }
            $this->ensureDirectory(dirname($target), 0755);
            $zip->writeEntryTo($relative, $target);
            @chmod($target, 0644);
        }
    }

    private function validateExtracted(string $root, AddonManifest $manifest, string $packageSha): void
    {
        $path = $root . '/addon.json';
        if (!is_file($path)) throw new RuntimeException('Extracted package is missing addon.json.');
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $extracted = AddonManifest::fromArray(is_array($data) ? $data : []);
        if ($extracted->id() !== $manifest->id() || $extracted->version() !== $manifest->version()) {
            throw new RuntimeException('Extracted package identity does not match the inspected package.');
        }
        file_put_contents($root . '/.package.json', json_encode(['sha256' => $packageSha, 'installedAt' => now_iso()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($root . '/.package.json', 0640);
    }

    /** @return list<array{id:string,checksum:string,up:list<string>,down:list<string>}> */
    private function discoverMigrations(string $root): array
    {
        $directory = $root . '/database/migrations';
        if (!is_dir($directory)) return [];
        $files = glob($directory . '/*.json') ?: []; sort($files, SORT_STRING);
        $result = []; $seen = [];
        foreach ($files as $file) {
            $raw = (string) file_get_contents($file);
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new RuntimeException('Invalid add-on migration: ' . basename($file));
            $id = trim((string) ($data['id'] ?? ''));
            if ($id === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $id) !== 1 || isset($seen[$id])) throw new RuntimeException('Invalid or duplicate add-on migration id: ' . $id);
            $seen[$id] = true;
            $up = array_values(array_filter((array) ($data['up'] ?? []), 'is_string'));
            $down = array_values(array_filter((array) ($data['down'] ?? []), 'is_string'));
            if ($up === []) throw new RuntimeException('Add-on migration contains no up statements: ' . $id);
            $result[] = ['id' => $id, 'checksum' => hash('sha256', $raw), 'up' => $up, 'down' => $down];
        }
        return $result;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function backup(array $record, string $reason): array
    {
        $id = (string) $record['addonKey']; $root = root_path((string) $record['installedPath']);
        if (!is_dir($root)) throw new RuntimeException('Cannot back up missing add-on files.');
        $directory = root_path('storage/backups/addons'); $this->ensureDirectory($directory, 0775);
        $safeId = preg_replace('/[^A-Za-z0-9_-]+/', '-', $id) ?: 'addon';
        $safeVersion = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $record['version']) ?: 'version';
        $relative = 'storage/backups/addons/' . $safeId . '-' . $safeVersion . '-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.zip';
        $target = root_path($relative);
        AddonArchive::createFromDirectory($root, $target);
        @chmod($target, 0640);
        $checksum = hash_file('sha256', $target);
        if (!is_string($checksum)) throw new RuntimeException('Could not checksum the add-on backup.');
        return $this->store->saveAddonBackup(['addonKey'=>$id,'version'=>(string)$record['version'],'path'=>$relative,'checksum'=>$checksum,'reason'=>$reason]);
    }

    /** @return array<string,mixed> */
    private function requiredAddon(string $addonId): array
    {
        $addon = $this->store->addon($addonId);
        if ($addon === null) throw new RuntimeException('The selected add-on is not installed.');
        return $addon;
    }

    private function cleanupReview(string $token): void
    {
        $review = $_SESSION['_addon_reviews'][$token] ?? null;
        if (is_array($review) && !empty($review['temporary']) && is_file((string) ($review['path'] ?? ''))) @unlink((string) $review['path']);
        unset($_SESSION['_addon_reviews'][$token]);
    }

    private function pruneReviews(): void
    {
        foreach ((array) ($_SESSION['_addon_reviews'] ?? []) as $token => $review) {
            if (!is_array($review) || (int) ($review['createdAt'] ?? 0) < time() - 3600) $this->cleanupReview((string) $token);
        }
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) throw new RuntimeException('Could not create directory: ' . $path);
        @chmod($path, $mode);
    }

    private function deleteTree(?string $path): void
    {
        if ($path === null || !file_exists($path)) return;
        if (is_file($path) || is_link($path)) { @unlink($path); return; }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) { if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname()); else @unlink($item->getPathname()); }
        @rmdir($path);
    }


    private function invalidateOpcodeCache(string $root): void
    {
        clearstatcache(true);
        if (!function_exists('opcache_invalidate') || !is_dir($root)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            @opcache_invalidate($file->getPathname(), true);
        }
    }

    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The add-on package exceeds the server upload limit.',
            UPLOAD_ERR_PARTIAL => 'The add-on package upload was interrupted.',
            UPLOAD_ERR_NO_FILE => 'Choose an add-on ZIP package.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary directory is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded package.',
            UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload.',
            default => 'The add-on package could not be uploaded.',
        };
    }
}

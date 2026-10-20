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
        $lock = $this->lifecycleLock($manifest->id());
        try {
            return $this->installReviewed($token, $review, $manifest, $activate);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** The prior file tree stays available until the registry write succeeds. */
    private function installReviewed(string $token, array $review, AddonManifest $manifest, bool $activate): array
    {
        $id = $manifest->id();
        $this->assertNoPendingRecovery($id);
        $current = $this->store->addon($id);
        // A different request may have changed the installed version while this
        // package was being reviewed. Recompute the operation under the lock.
        $operation = $current === null ? 'install' : match (version_compare($manifest->version(), (string) $current['version'])) {
            -1 => 'rollback', 0 => 'repair', default => 'update',
        };
        $currentPath = root_path('addons/installed/' . $id);
        $this->store->logAddonActivity($id, 'package.install.started', ['version' => $manifest->version(), 'operation' => $operation]);
        $backup = null;
        $oldPath = root_path('addons/staging/old-' . $id . '-' . bin2hex(random_bytes(5)));
        $migrations = [];
        $currentMigrations = [];
        $appliedMigrationKeys = [];
        $removedMigrationKeys = [];
        $newFilesActive = false;
        $paused = false;
        $newPath = root_path('addons/staging/install-' . $id . '-' . bin2hex(random_bytes(6)));
        $lifecycle = new AddonMigrationRecovery($id, 'lifecycle');
        $lifecycle->lock();
        $intent = null;
        $targetRecord = [
                'id' => $id, 'name' => $manifest->name(), 'vendor' => $manifest->vendor(),
                'version' => $manifest->version(), 'status' => $activate ? 'active' : 'installed',
                'manifest' => $manifest->toArray(), 'permissions' => $manifest->permissions(),
                'packageSha256' => (string) $review['packageSha256'],
                'signatureStatus' => (string) ($review['signature']['status'] ?? 'unsigned'),
                'installedPath' => 'addons/installed/' . $id, 'previousVersion' => $current['version'] ?? null,
                'failureCount' => 0, 'lastError' => null,
                'installedAt' => $current['installedAt'] ?? now_iso(),
                'activatedAt' => $activate ? now_iso() : ($current['activatedAt'] ?? null),
        ];
        try {
            $this->extract((string) $review['packagePath'], $newPath);
            $this->validateExtracted($newPath, $manifest, (string) $review['packageSha256']);
            $migrations = $this->discoverMigrations($newPath);
            if ($migrations !== [] && !in_array('database.migrate', $manifest->permissions(), true)) {
                throw new RuntimeException('The package contains database migrations but did not request database.migrate permission.');
            }
            $applied = $this->store->addonMigrations($id);
            $targetIds = array_column($migrations, 'id');
            $missing = array_values(array_diff(array_keys($applied), $targetIds));
            $this->store->preflightAddonMigrations($id, $migrations);
            if ($operation === 'rollback' && array_diff($targetIds, array_keys($applied)) !== []) {
                throw new RuntimeException('A rollback package must use a subset of the already applied migration history.');
            }
            if ($missing !== []) {
                if ($operation !== 'rollback' || !is_dir($currentPath)) {
                    throw new RuntimeException('The package omits applied migrations. Retain migration history or explicitly restore a compatible older package.');
                }
                $currentMigrations = $this->discoverMigrations($currentPath);
                $this->store->preflightAddonMigrations($id, $currentMigrations);
                if (array_diff($missing, array_column($currentMigrations, 'id')) !== []) {
                    throw new RuntimeException('The installed package is missing the migration definitions required for this rollback.');
                }
            }
            if ($current !== null && is_dir($currentPath)) {
                $backup = $this->backup($current, 'pre-' . $operation);
            }
            $definitions = [];
            foreach (array_merge($currentMigrations, $migrations) as $migration) $definitions[$migration['id']] = $migration;
            $targetChecksums = [];
            foreach ($migrations as $migration) $targetChecksums[$migration['id']] = $migration['checksum'];
            $intent = ['operation' => 'install', 'phase' => 'prepared', 'current' => $current, 'target' => $targetRecord,
                'currentPath' => 'addons/installed/' . $id, 'newPath' => $this->relativePath($newPath), 'oldPath' => $this->relativePath($oldPath),
                'priorFiles' => is_dir($currentPath) ? $this->fileHashes($currentPath) : [], 'targetFiles' => $this->fileHashes($newPath),
                'migrations' => $definitions, 'initialLedger' => $applied, 'targetChecksums' => $targetChecksums,
                'rollbackKeys' => $missing, 'backup' => $backup];
            $lifecycle->write($intent);
            if (($current['status'] ?? '') === 'active') {
                $inactive = $current; $inactive['status'] = 'inactive';
                $this->store->saveAddon($inactive);
                $paused = true;
            }
            $intent['phase'] = 'migrating'; $lifecycle->write($intent);
            if ($missing !== []) {
                $this->store->rollbackAddonMigrationKeys($id, $currentMigrations, $missing);
                $removedMigrationKeys = $missing;
            }
            if ($migrations !== []) $appliedMigrationKeys = $this->store->applyAddonMigrations($id, $migrations);
            $intent['phase'] = 'migrated'; $lifecycle->write($intent);

            if (is_dir($currentPath)) {
                if (!@rename($currentPath, $oldPath)) throw new RuntimeException('The current add-on files could not be moved into the update staging area.');
            }
            $intent['phase'] = 'prior_staged'; $lifecycle->write($intent);
            $this->ensureDirectory(dirname($currentPath), 0775);
            if (!@rename($newPath, $currentPath)) throw new RuntimeException('The validated add-on files could not be activated.');
            $newFilesActive = true;
            $this->invalidateOpcodeCache($currentPath);
            $intent['phase'] = 'files_active'; $lifecycle->write($intent);
            $record = $this->store->saveAddon($targetRecord);
        } catch (Throwable $error) {
            $recoveryErrors = [];
            $fileRecoveryIncomplete = false;
            if ($newFilesActive && is_dir($currentPath) && !@rename($currentPath, $newPath)) {
                $recoveryErrors[] = 'New package files could not be moved out of the active path.';
                $fileRecoveryIncomplete = true;
            }
            if ($oldPath !== null && is_dir($oldPath)) {
                if (is_dir($currentPath) || !@rename($oldPath, $currentPath)) {
                    $recoveryErrors[] = 'Prior package files remain in the staging backup and could not be restored automatically.';
                    $fileRecoveryIncomplete = true;
                } else { $this->invalidateOpcodeCache($currentPath); }
            }
            if ($this->outcomeUnknown($error)) {
                $recoveryErrors[] = 'The database operation has an unknown outcome. Preserve the package and database recovery records for review.';
            } elseif ($appliedMigrationKeys !== []) {
                try { $this->store->rollbackAddonMigrationKeys($id, $migrations, $appliedMigrationKeys); }
                catch (Throwable $rollbackError) { $recoveryErrors[] = $rollbackError->getMessage(); }
            }
            if ($removedMigrationKeys !== []) {
                // Recreating a dropped object cannot recover its former data.
                // Keep the selected rollback held instead of claiming that an
                // automatic up replay would restore the pre-rollback database.
                $recoveryErrors[] = 'The explicit schema rollback committed before package activation failed. Restore the pre-change database backup before reactivating the prior package.';
            }
            if ($paused && $recoveryErrors === [] && !$this->store->hasPendingAddonMigrationRecovery($id)) {
                try { $this->store->saveAddon($current); }
                catch (Throwable $registryError) { $recoveryErrors[] = 'The prior add-on registry state could not be restored: ' . $registryError->getMessage(); }
            }
            $pending = $this->store->hasPendingAddonMigrationRecovery($id) || $recoveryErrors !== [];
            if ($pending) {
                if ($intent !== null) {
                    $intent['phase'] = 'failed';
                    $journal = (new AddonMigrationRecovery($id))->read();
                    // A rollback journal can also belong to compensation after
                    // a rejected update registry write. Only an explicitly
                    // selected older package should finish that activation.
                    $intent['recovery'] = $missing !== [] ? 'activate' : 'restore';
                    if ($fileRecoveryIncomplete || $this->outcomeUnknown($error) || ($journal !== null && !in_array($journal['operation'] ?? '', ['compensate', 'rollback'], true))
                        || !empty($journal['pendingStatement'])) $intent['recovery'] = 'manual';
                    $intent['errors'] = $recoveryErrors;
                    $lifecycle->write($intent);
                }
                if ($current !== null) {
                    try {
                        $current['status'] = 'quarantined';
                        $current['lastError'] = 'Add-on migration or package recovery requires review.';
                        $this->store->saveAddon($current);
                    } catch (Throwable) { /* The migration recovery marker still blocks runtime boot. */ }
                }
            } else {
                if (is_dir($newPath)) $this->deleteTree($newPath);
                $lifecycle->clear();
            }
            $this->logActivitySafely($id, 'package.install.failed', ['error' => $error->getMessage(), 'recoveryRequired' => $pending, 'recoveryErrors' => $recoveryErrors], 'error');
            if ($recoveryErrors !== []) throw new RuntimeException($error->getMessage() . ' Recovery is incomplete: ' . implode(' ', $recoveryErrors), 0, $error);
            throw $error;
        }

        // Audit or cleanup failures after this point cannot undo a committed
        // registry change or leave the old version paired with new files.
        $lifecycle->clear();
        if ($oldPath !== null) $this->deleteTree($oldPath);
        $this->logActivitySafely($id, 'package.installed', ['version' => $manifest->version(), 'operation' => $operation, 'backup' => $backup['id'] ?? null]);
        if ($activate) $this->logActivitySafely($id, 'addon.activated', ['version' => $manifest->version()]);
        $this->cleanupReview($token);
        return $record;
    }

    public function activate(string $addonId): array
    {
        return $this->withLifecycleLock($addonId, fn(): array => $this->activateLocked($addonId));
    }

    private function activateLocked(string $addonId): array
    {
        $this->assertNoPendingRecovery($addonId);
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
        return $this->withLifecycleLock($addonId, fn(): array => $this->deactivateLocked($addonId));
    }

    private function deactivateLocked(string $addonId): array
    {
        $record = $this->requiredAddon($addonId);
        $record['status'] = 'inactive';
        $record = $this->store->saveAddon($record);
        $this->store->logAddonActivity($addonId, 'addon.deactivated');
        return $record;
    }

    public function uninstall(string $addonId, bool $purgeData = false): void
    {
        $this->withLifecycleLock($addonId, fn() => $this->uninstallLocked($addonId, $purgeData));
    }

    private function uninstallLocked(string $addonId, bool $purgeData): void
    {
        $this->assertNoPendingRecovery($addonId);
        $record = $this->requiredAddon($addonId);
        $relativeRoot = 'addons/installed/' . $addonId;
        if (($record['installedPath'] ?? '') !== $relativeRoot) throw new RuntimeException('The installed add-on path requires review before removal.');
        $root = root_path($relativeRoot);
        $migrations = is_dir($root) ? $this->discoverMigrations($root) : [];
        $applied = $this->store->addonMigrations($addonId);
        if ($purgeData) {
            if (array_diff(array_keys($applied), array_column($migrations, 'id')) !== []) {
                throw new RuntimeException('Cannot purge retained add-on data without its original migration definitions. Reinstall the compatible package first.');
            }
            $this->store->preflightAddonMigrations($addonId, $migrations);
        }
        $this->store->logAddonActivity($addonId, 'addon.uninstall.started', ['purgeData' => $purgeData]);
        $backup = is_dir($root) ? $this->backup($record, 'pre-uninstall') : null;
        $definitions = [];
        foreach ($migrations as $migration) $definitions[$migration['id']] = $migration;
        $checksums = [];
        if (!$purgeData) foreach ($applied as $key => $migration) $checksums[$key] = $migration['checksum'];
        $lifecycle = new AddonMigrationRecovery($addonId, 'lifecycle'); $lifecycle->lock();
        $intent = ['operation' => 'uninstall', 'phase' => 'prepared', 'current' => $record, 'currentPath' => $relativeRoot,
            'priorFiles' => $this->fileHashes($root), 'migrations' => $definitions, 'initialLedger' => $applied,
            'targetChecksums' => $checksums, 'purgeData' => $purgeData, 'backup' => $backup];
        $lifecycle->write($intent);
        $paused = false;
        $schemaPurged = false;
        try {
            if (($record['status'] ?? '') === 'active') {
                $inactive = $record; $inactive['status'] = 'inactive';
                $this->store->saveAddon($inactive); $paused = true;
            }
            $intent['phase'] = 'migrating'; $lifecycle->write($intent);
            if ($purgeData && $migrations !== []) {
                $this->store->rollbackAddonMigrations($addonId, $migrations, true);
                $schemaPurged = $applied !== [];
            }
            $intent['phase'] = 'migrated'; $lifecycle->write($intent);
            $this->store->deleteAddon($addonId, $purgeData);
        } catch (Throwable $error) {
            $journal = (new AddonMigrationRecovery($addonId))->read();
            $pending = $schemaPurged || $journal !== null || $this->outcomeUnknown($error);
            if (!$pending && $paused) {
                try { $this->store->saveAddon($record); }
                catch (Throwable) { $pending = true; }
            }
            if ($pending) {
                $intent['phase'] = 'failed';
                $intent['recovery'] = $schemaPurged || $journal !== null ? 'uninstall' : 'restore';
                if ($this->outcomeUnknown($error) || ($journal !== null && (($journal['operation'] ?? '') !== 'rollback' || !empty($journal['pendingStatement'])))) {
                    $intent['recovery'] = 'manual';
                }
                $lifecycle->write($intent);
                $inactive = $record; $inactive['status'] = 'quarantined'; $inactive['lastError'] = 'Add-on removal requires database recovery.';
                try { $this->store->saveAddon($inactive); } catch (Throwable) { /* The durable recovery marker blocks activation. */ }
            } else { $lifecycle->clear(); }
            $this->logActivitySafely($addonId, 'addon.uninstall.failed', ['error' => $error->getMessage(), 'schemaPurged' => $schemaPurged], 'error');
            throw $error;
        }
        $intent['phase'] = 'registry_removed'; $lifecycle->write($intent);
        $this->deleteTree($root);
        $lifecycle->clear();
        $this->logActivitySafely($addonId, 'addon.uninstalled', ['purgeData' => $purgeData]);
    }

    public function rollbackLatest(string $addonId): array
    {
        $record = $this->requiredAddon($addonId);
        $backups = $this->store->addonBackups($addonId);
        if ($backups === []) throw new RuntimeException('No previous package backup is available for this add-on.');
        $backup = $backups[0];
        $path = root_path((string) $backup['path']);
        if (!is_file($path)) throw new RuntimeException('The selected add-on backup file is missing.');
        $checksum = hash_file('sha256', $path);
        if (!is_string($checksum) || !hash_equals((string) ($backup['checksum'] ?? ''), $checksum)) {
            throw new RuntimeException('The selected add-on backup failed checksum verification.');
        }
        $review = $this->stagePath($path, 'rollback-' . bin2hex(random_bytes(12)), false);
        if (($review['manifest']['id'] ?? '') !== $addonId || ($review['manifest']['version'] ?? '') !== ($backup['version'] ?? '')) {
            throw new RuntimeException('The selected backup does not match its recorded add-on and version.');
        }
        return $this->install((string) $review['token'], true, ($record['status'] ?? '') === 'active');
    }

    public function setSafeMode(bool $enabled): void
    {
        $this->store->setAddonsSafeMode($enabled);
        $this->store->logAddonActivity('', $enabled ? 'safe_mode.enabled' : 'safe_mode.disabled');
    }

    /** Only safe display metadata; SQL and private recovery paths never enter the page. */
    public function pendingRecoveries(): array
    {
        $rows = [];
        foreach (['lifecycle', 'migrations'] as $scope) {
            foreach (glob(root_path('storage/backups/addons/' . $scope . '/*.json')) ?: [] as $path) {
                if (is_link($path)) continue;
                $record = json_decode((string) file_get_contents($path), true);
                $id = is_array($record) ? (string) ($record['addonKey'] ?? '') : '';
                if ($id === '' || basename($path) !== hash('sha256', $id) . '.json' || isset($rows[$id])) continue;
                try { $journal = (new AddonMigrationRecovery($id))->read(); }
                catch (Throwable) { $journal = ['operation' => 'invalid', 'pendingStatement' => true]; }
                $retry = $scope === 'lifecycle' && ($record['phase'] ?? '') === 'failed'
                    && in_array($record['recovery'] ?? '', ['restore', 'activate', 'uninstall'], true)
                    && ($journal === null || (in_array($journal['operation'] ?? '', ['compensate', 'rollback'], true) && empty($journal['pendingStatement'])));
                $message = $retry ? match ($record['recovery']) {
                    'restore' => ($record['current'] ?? null) === null
                        ? 'Finish reversing the incomplete installation. Existing site data and other add-ons are preserved.'
                        : 'Retry recovery of the previously installed package. The add-on stays disabled until its database and files agree.',
                    'activate' => 'Complete the selected rollback to version ' . (string) ($record['target']['version'] ?? '') . '. Data removed by the rollback requires a database backup to restore.',
                    'uninstall' => !empty($record['purgeData']) ? 'Complete the selected add-on removal and purge. Already purged data requires a database backup to restore.' : 'Complete the selected add-on removal while retaining its data.',
                } : 'The interrupted change needs database and package review. Preserve its recovery records and use a verified database backup; an uncertain SQL statement is never rerun automatically.';
                $rows[$id] = ['addonKey' => $id, 'canRetry' => $retry, 'operation' => (string) ($record['operation'] ?? 'migration'),
                    'targetVersion' => (string) ($record['target']['version'] ?? ''),
                    'message' => $message];
            }
        }
        return array_values($rows);
    }

    /** Resume a definite failed down boundary, never an unknown SQL or package outcome. */
    public function recoverPending(string $addonId): string
    {
        return $this->withLifecycleLock($addonId, function () use ($addonId): string {
            $lifecycle = new AddonMigrationRecovery($addonId, 'lifecycle'); $lifecycle->lock();
            $intent = $lifecycle->read();
            if ($intent === null || ($intent['phase'] ?? '') !== 'failed' || !in_array($intent['recovery'] ?? '', ['restore', 'activate', 'uninstall'], true)) {
                throw new RuntimeException('This interrupted package change has an unknown outcome. Preserve its records for database and package review.');
            }
            $currentPath = $this->intentPath($intent, 'currentPath', 'addons/installed/' . $addonId, true);
            $newPath = ($intent['operation'] ?? '') === 'install' ? $this->intentPath($intent, 'newPath', 'addons/staging/install-' . $addonId . '-') : null;
            $oldPath = ($intent['operation'] ?? '') === 'install' ? $this->intentPath($intent, 'oldPath', 'addons/staging/old-' . $addonId . '-') : null;
            if ($this->fileHashes($currentPath) !== (array) ($intent['priorFiles'] ?? [])) {
                $intent['recovery'] = 'manual'; $lifecycle->write($intent);
                throw new RuntimeException('The prior package files changed during recovery. Preserve them for review.');
            }
            if ($newPath !== null && $this->fileHashes($newPath) !== (array) ($intent['targetFiles'] ?? [])) {
                $intent['recovery'] = 'manual'; $lifecycle->write($intent);
                throw new RuntimeException('The reviewed package files changed during recovery. Preserve them for review.');
            }
            $current = $this->store->addon($addonId);
            $prior = $intent['current'] ?? null;
            if (($prior === null) !== ($current === null) || ($prior !== null && (($current['version'] ?? '') !== ($prior['version'] ?? '')
                || ($current['packageSha256'] ?? '') !== ($prior['packageSha256'] ?? '')))) {
                throw new RuntimeException('The add-on registry changed during recovery. Preserve the recorded package state for review.');
            }
            $journal = (new AddonMigrationRecovery($addonId))->read();
            if ($journal !== null && (!in_array($journal['operation'] ?? '', ['compensate', 'rollback'], true) || !empty($journal['pendingStatement']))) {
                throw new RuntimeException('A migration has an unknown statement outcome. Restore or reconcile a verified database backup before proceeding.');
            }
            $intent['phase'] = 'recovering'; $lifecycle->write($intent);
            try {
                if ($journal !== null) $this->store->recoverAddonMigrations($addonId, array_values((array) $intent['migrations']));
                $ledger = $this->store->addonMigrations($addonId);
                if ($intent['recovery'] === 'restore') {
                    if ($ledger != (array) ($intent['initialLedger'] ?? [])) throw new RuntimeException('Recovered migration history does not match the preserved previous package.');
                    if ($prior !== null) $this->store->saveAddon($prior);
                    if ($newPath !== null) $this->deleteTree($newPath);
                    $lifecycle->clear();
                    $message = $prior === null ? 'Recovery completed. The incomplete installation was removed and existing site data was preserved.'
                        : 'Recovery completed. The previous add-on package and its retained data are restored.';
                } elseif ($intent['recovery'] === 'activate') {
                    $this->assertTargetLedger($ledger, (array) ($intent['targetChecksums'] ?? []));
                    $target = (array) ($intent['target'] ?? []);
                    $manifest = AddonManifest::fromArray((array) ($target['manifest'] ?? []));
                    if ($manifest->id() !== $addonId) throw new RuntimeException('Recovery package identity does not match the recorded add-on.');
                    $this->validateRelationships($manifest);
                    if (($target['status'] ?? '') === 'active' && $manifest->requiresLicense()
                        && !AddonLicenseClient::recordAllowsActivation($this->store->addonLicense($addonId))) $target['status'] = 'installed';
                    if ($oldPath === null || $newPath === null || file_exists($oldPath)) throw new RuntimeException('The prior package staging location is not available.');
                    $priorMoved = false; $targetMoved = false;
                    try {
                        if (is_dir($currentPath)) {
                            if (!rename($currentPath, $oldPath)) throw new RuntimeException('Could not preserve prior package files during recovery.');
                            $priorMoved = true;
                        }
                        if (!rename($newPath, $currentPath)) throw new RuntimeException('Could not activate the verified recovery package.');
                        $targetMoved = true;
                        $this->invalidateOpcodeCache($currentPath);
                        $this->store->saveAddon($target);
                    } catch (Throwable $error) {
                        if ($targetMoved && !@rename($currentPath, $newPath)) $intent['recovery'] = 'manual';
                        if ($priorMoved && (is_dir($currentPath) || !@rename($oldPath, $currentPath))) $intent['recovery'] = 'manual';
                        $this->invalidateOpcodeCache($currentPath);
                        throw $error;
                    }
                    $lifecycle->clear(); $this->deleteTree($oldPath);
                    $message = 'Recovery completed the selected rollback. Data removed by down SQL can only be restored from a database backup.';
                } else {
                    $this->assertTargetLedger($ledger, (array) ($intent['targetChecksums'] ?? []));
                    $this->store->deleteAddon($addonId, !empty($intent['purgeData']));
                    $this->deleteTree($currentPath); $lifecycle->clear();
                    $message = 'Recovery completed the selected add-on removal. Purged data requires a database backup to restore.';
                }
            } catch (Throwable $error) {
                $intent['phase'] = 'failed';
                $journal = (new AddonMigrationRecovery($addonId))->read();
                if ($this->outcomeUnknown($error) || !empty($journal['pendingStatement'])) $intent['recovery'] = 'manual';
                $lifecycle->write($intent);
                $this->logActivitySafely($addonId, 'package.recovery.failed', ['error' => $error->getMessage()], 'error');
                throw $error;
            }
            $this->logActivitySafely($addonId, 'package.recovery.completed', ['operation' => $intent['recovery']]);
            return $message;
        });
    }

    private function assertTargetLedger(array $ledger, array $checksums): void
    {
        $actual = [];
        foreach ($ledger as $id => $migration) $actual[$id] = $migration['checksum'];
        ksort($actual); ksort($checksums);
        if ($actual !== $checksums) throw new RuntimeException('The database migration history does not match the selected recovery package.');
    }

    private function intentPath(array $intent, string $key, string $prefix, bool $exact = false): string
    {
        $relative = (string) ($intent[$key] ?? '');
        AddonManifest::safeRelativePath($relative);
        if ($exact ? $relative !== $prefix : !str_starts_with($relative, $prefix)) throw new RuntimeException('Invalid recorded add-on recovery path.');
        return root_path($relative);
    }

    private function relativePath(string $path): string
    {
        $root = rtrim(root_path(), '/') . '/';
        if (!str_starts_with($path, $root)) throw new RuntimeException('Add-on recovery path escaped this installation.');
        return substr($path, strlen($root));
    }

    private function fileHashes(string $root): array
    {
        if (is_link($root)) throw new RuntimeException('Symbolic links are not supported in add-on recovery.');
        if (!is_dir($root)) return [];
        $hashes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isLink()) throw new RuntimeException('Symbolic links are not supported in add-on recovery.');
            if ($file->isFile()) $hashes[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
        }
        ksort($hashes);
        return $hashes;
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
            $up = $data['up'] ?? null;
            $down = $data['down'] ?? null;
            if (!is_array($up) || !is_array($down)) throw new RuntimeException('Add-on migration requires explicit up and down statement lists: ' . $id);
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

    /** @return resource */
    private function lifecycleLock(string $addonId)
    {
        $directory = root_path('addons/staging');
        $this->ensureDirectory($directory, 0775);
        $path = $directory . '/lifecycle-' . hash('sha256', $addonId) . '.lock';
        if (is_link($path)) throw new RuntimeException('Unsafe add-on lifecycle lock path.');
        $handle = fopen($path, 'c');
        if (!is_resource($handle) || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new RuntimeException('Another package operation is already running for this add-on.');
        }
        @chmod($path, 0640);
        return $handle;
    }

    private function withLifecycleLock(string $addonId, callable $operation): mixed
    {
        $lock = $this->lifecycleLock($addonId);
        try { return $operation(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function assertNoPendingRecovery(string $addonId): void
    {
        if ($this->store->hasPendingAddonRecovery($addonId)) {
            throw new RuntimeException('This add-on has pending migration recovery. Its files and recovery record must be preserved until the database is recovered.');
        }
    }

    private function logActivitySafely(string $addonId, string $event, array $context = [], string $level = 'info'): void
    {
        try { $this->store->logAddonActivity($addonId, $event, $context, $level); }
        catch (Throwable) { /* Do not reverse a completed lifecycle change because its audit write failed. */ }
    }

    private function outcomeUnknown(Throwable $error): bool
    {
        do {
            if ($error instanceof \PDOException && (str_starts_with((string) $error->getCode(), '08')
                || in_array((int) ($error->errorInfo[1] ?? 0), [2006, 2013, 2055], true))) return true;
        } while (($error = $error->getPrevious()) !== null);
        return false;
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

<?php
declare(strict_types=1);

namespace Velvet\Installer;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class FileSystemService
{
    /**
     * These top-level runtime trees may be safely rebuilt during a fresh install
     * when a hosting control panel extracted them under a different owner than PHP.
     *
     * Rebuilding changes ownership without chmod 0777, chown, shell access, or a
     * manual FTP step. Existing package contents are copied into the PHP-owned tree.
     */
    private const OWNERSHIP_REPAIR_ROOTS = [
        'config',
        'addons',
        'database',
        'storage',
        'uploads',
    ];

    /** @var array<string,string> */
    private array $repairMessages = [];

    private bool $rootProtectionPrepared = false;

    public function __construct(
        private readonly string $root,
        private readonly InstallerLog $log,
    ) {
    }

    /**
     * Runtime-only installation manifest.
     *
     * Only directories used by the packaged application belong here. Empty
     * design scaffolding is not shipped and must not block a shared-host install.
     * Read-only entries must already exist in the extracted runtime package;
     * PHP only creates and repairs the explicitly writable runtime directories.
     * Development-only folders such as /docs and /tests are intentionally absent.
     *
     * @return array<string,array{writable:bool,mode:int,label:string,required:bool}>
     */
    public function directoryManifest(): array
    {
        $writable = [
            'config',
            'addons', 'addons/installed', 'addons/packages', 'addons/staging',
            'database', 'database/backups',
            'storage', 'storage/backups', 'storage/cache', 'storage/logs', 'storage/private', 'storage/private/repair-backups', 'storage/sessions', 'storage/temp', 'storage/temporary', 'storage/updates',
            'uploads', 'uploads/audio', 'uploads/captions', 'uploads/exports', 'uploads/images', 'uploads/media', 'uploads/models', 'uploads/originals', 'uploads/previews', 'uploads/thumbnails', 'uploads/videos', 'uploads/watermarked',
        ];

        $all = [
            'admin',
            'addons', 'addons/installed', 'addons/packages', 'addons/staging',
            'app', 'app/Console', 'app/Core', 'app/Payments', 'app/Support', 'app/Views',
            'config',
            'database', 'database/backups', 'database/migrations',
            'images', 'images/defaults', 'images/hero', 'images/hero/thumbnails',
            'includes',
            'install', 'install/services', 'install/views',
            'public', 'public/assets', 'public/assets/css', 'public/assets/js', 'public/routes',
            'storage', 'storage/backups', 'storage/cache', 'storage/defaults', 'storage/logs', 'storage/private', 'storage/private/repair-backups', 'storage/sessions', 'storage/temp', 'storage/temporary', 'storage/updates',
            'templates', 'templates/canva',
            'uploads', 'uploads/audio', 'uploads/captions', 'uploads/exports', 'uploads/images', 'uploads/media', 'uploads/models', 'uploads/originals', 'uploads/previews', 'uploads/thumbnails', 'uploads/videos', 'uploads/watermarked',
            'vendor',
        ];

        $topLevelMode0755 = ['config', 'addons', 'database', 'storage', 'uploads'];
        $manifest = [];
        foreach ($all as $path) {
            $isWritable = in_array($path, $writable, true);
            $mode = $isWritable ? 0775 : 0755;
            if (in_array($path, $topLevelMode0755, true)) {
                $mode = 0755;
            }
            $manifest[$path] = [
                'writable' => $isWritable,
                'mode' => $mode,
                'label' => str_replace(['-', '/'], [' ', ' / '], $path),
                'required' => true,
            ];
        }

        return $manifest;
    }

    /**
     * Verifies packaged read-only directories without modifying their ownership
     * or permissions. Creates required writable directories, applies safe modes,
     * performs real write/rename/read/delete probes, and repairs control-panel
     * ownership mismatches without ever using mode 0777.
     *
     * @return list<array{path:string,label:string,status:string,message:string,mode:string,writable:bool,required:bool}>
     */
    public function prepareAndProbe(): array
    {
        $results = [];
        $manifest = $this->directoryManifest();

        foreach ($manifest as $relative => $definition) {
            $absolute = $this->root . '/' . $relative;
            clearstatcache(true, $absolute);

            // A control panel may own public assets and application templates.
            // The site only needs to read these trees. Do not try to create empty
            // source folders or chmod/copy existing ones as a writable-dir repair.
            if (!$definition['writable']) {
                $present = is_dir($absolute) && !is_link($absolute);
                $readable = $present && is_readable($absolute);
                $results[] = [
                    'path' => $relative,
                    'label' => $definition['label'],
                    'status' => $readable ? 'pass' : 'fail',
                    'message' => !$present
                        ? 'Required application directory is missing or is not a regular directory. Re-extract the complete fresh-install ZIP.'
                        : ($readable
                            ? 'Present and readable; PHP write access is not required.'
                            : 'Required application directory is not readable by PHP.'),
                    'mode' => $present ? $this->mode($absolute) : '----',
                    'writable' => $present && is_writable($absolute),
                    'required' => $definition['required'],
                ];
                continue;
            }

            $created = false;

            if (!is_dir($absolute)) {
                $created = @mkdir($absolute, $definition['mode'], true);
            }

            if (!is_dir($absolute)) {
                $results[] = [
                    'path' => $relative,
                    'label' => $definition['label'],
                    'status' => 'fail',
                    'message' => 'Directory could not be created.',
                    'mode' => '----',
                    'writable' => false,
                    'required' => $definition['required'],
                ];
                continue;
            }

            @chmod($absolute, $definition['mode']);
            clearstatcache(true, $absolute);
            $writable = is_writable($absolute);
            $message = $created ? 'Created and verified.' : 'Present and verified.';
            $status = 'pass';

            if ($definition['writable']) {
                try {
                    $this->probe($absolute);
                    $writable = true;
                } catch (Throwable $firstFailure) {
                    // First try the normal safe group-writable repair.
                    @chmod($absolute, 0775);
                    clearstatcache(true, $absolute);
                    try {
                        $this->probe($absolute);
                        $writable = true;
                        $message = 'Writable after applying safe mode 0775.';
                    } catch (Throwable $secondFailure) {
                        $topLevel = explode('/', $relative, 2)[0];
                        if ($relative === $topLevel && in_array($topLevel, self::OWNERSHIP_REPAIR_ROOTS, true)) {
                            try {
                                $repair = $this->repairOwnershipMismatch($topLevel, $definition['mode'], $manifest);
                                $this->probe($absolute);
                                $writable = true;
                                $message = $repair;
                            } catch (Throwable $repairFailure) {
                                $status = 'fail';
                                $writable = false;
                                $message = $repairFailure->getMessage();
                            }
                        } else {
                            $status = 'fail';
                            $writable = false;
                            $message = $secondFailure->getMessage();
                        }
                    }
                }
            }

            clearstatcache(true, $absolute);
            $results[] = [
                'path' => $relative,
                'label' => $definition['label'],
                'status' => $status,
                'message' => $message,
                'mode' => $this->mode($absolute),
                'writable' => $writable,
                'required' => $definition['required'],
            ];
        }

        $this->writeProtectionFiles();
        $archivedResidues = $this->archiveRootRepairResidues();
        $this->log->write('filesystem.probed', [
            'archived_repair_residues' => $archivedResidues,
            'ownership_repairs' => $this->repairMessages,
            'failures' => array_values(array_filter($results, static fn(array $row): bool => $row['status'] === 'fail')),
        ]);

        return $results;
    }

    public function allRequiredWritable(array $results): bool
    {
        foreach ($results as $result) {
            if (($result['required'] ?? true) && ($result['status'] ?? 'fail') === 'fail') {
                return false;
            }
        }
        return true;
    }

    /** @return array{public_removed:bool,residual_path:?string,message:string} */
    public function retireInstaller(string $installId): array
    {
        $source = $this->root . '/install';
        if (!is_dir($source)) {
            return ['public_removed' => true, 'residual_path' => null, 'message' => 'Installer directory was already removed.'];
        }

        $private = $this->root . '/storage/private';
        if (!is_dir($private)) {
            @mkdir($private, 0775, true);
        }
        $target = $private . '/retired-installer-' . preg_replace('/[^A-Za-z0-9_-]/', '', $installId);

        $this->makeTreeWritable($source);
        if (@rename($source, $target)) {
            $this->recursiveDelete($target);
            $residual = is_dir($target) ? $target : null;
            if ($residual !== null) {
                @file_put_contents($private . '/installer-cleanup-pending.json', json_encode([
                    'path' => $target,
                    'created_at' => gmdate('c'),
                ], JSON_PRETTY_PRINT));
            }
            $this->log->write('installer.retired', ['method' => 'rename', 'residual' => $residual]);
            return [
                'public_removed' => !is_dir($source),
                'residual_path' => $residual,
                'message' => $residual === null
                    ? 'The public installer was deleted.'
                    : 'The public installer was removed; protected cleanup residue remains for a later retry.',
            ];
        }

        // Ownership-mismatched installers often cannot be moved into another
        // parent, but they can still be renamed inside the writable site root.
        // This removes the public /install URL immediately without chmod 0777.
        try {
            $this->prepareRootProtection();
            $hidden = $this->root . '/.vvx-private-repair-retired-installer-' . preg_replace('/[^A-Za-z0-9_-]/', '', $installId) . '-' . bin2hex(random_bytes(5));
            if (@rename($source, $hidden)) {
                $this->makeTreeWritable($hidden);
                $this->recursiveDelete($hidden);
                $residual = is_dir($hidden) ? $hidden : null;
                $this->log->write('installer.retired', ['method' => 'same-parent-rename', 'residual' => $residual]);
                return [
                    'public_removed' => true,
                    'residual_path' => $residual,
                    'message' => $residual === null
                        ? 'The public installer was deleted.'
                        : 'The public /install path was removed. Protected ownership residue remains hidden for later cleanup.',
                ];
            }
        } catch (Throwable $e) {
            $this->log->write('installer.same_parent_retire_warning', ['error' => $e->getMessage()]);
        }

        $this->recursiveDelete($source);
        $removed = !is_dir($source);
        if (!$removed) {
            $this->lockInstallerDirectory($source);
        }
        $this->log->write('installer.retired', ['method' => 'recursive-delete', 'removed' => $removed]);
        return [
            'public_removed' => $removed,
            'residual_path' => $removed ? null : $source,
            'message' => $removed
                ? 'The public installer was deleted.'
                : 'The installer is locked, but the server would not allow the directory to be deleted.',
        ];
    }

    /**
     * Replaces a panel-owned runtime tree with a PHP-owned copy.
     *
     * Directory renaming is controlled by the writable site root, not by the
     * ownership of the directory being moved. This is the safe shared-hosting
     * fallback when chmod cannot change ownership or group membership.
     *
     * @param array<string,array{writable:bool,mode:int,label:string,required:bool}> $manifest
     */
    private function repairOwnershipMismatch(string $relativeRoot, int $mode, array $manifest): string
    {
        if (!in_array($relativeRoot, self::OWNERSHIP_REPAIR_ROOTS, true)) {
            throw new RuntimeException('PHP cannot write to this directory.');
        }
        if (isset($this->repairMessages[$relativeRoot])) {
            return $this->repairMessages[$relativeRoot];
        }

        $source = $this->root . '/' . $relativeRoot;
        if (!is_dir($source) || is_link($source)) {
            throw new RuntimeException('The runtime directory is missing or is not a safe directory.');
        }

        try {
            $this->probe($this->root);
        } catch (Throwable) {
            throw new RuntimeException(
                'PHP cannot write to /' . $relativeRoot . ' and the site root is not writable enough for automatic ownership repair. ' .
                'The hosting control panel extracted the files under a different owner than PHP.'
            );
        }

        $this->prepareRootProtection();
        // A same-parent rename works on shared hosts where PHP may rename an
        // entry through a writable document root but cannot move a foreign-owned
        // directory into a different parent because its '..' entry would change.
        $backup = $this->root . '/.vvx-private-repair-' . $relativeRoot . '-' . bin2hex(random_bytes(10));

        if (!@rename($source, $backup)) {
            throw new RuntimeException(
                'PHP cannot write to /' . $relativeRoot . ' and could not rotate it for ownership-safe repair.'
            );
        }

        $rolledBack = false;
        try {
            if (!@mkdir($source, $mode, true) && !is_dir($source)) {
                throw new RuntimeException('The replacement runtime directory could not be created.');
            }
            @chmod($source, $mode);
            $this->copyTree($backup, $source);
            $this->applyManifestModes($relativeRoot, $manifest);
            $this->probe($source);

            // Best-effort cleanup. If the old owner prevents deletion, the
            // randomized denied vault remains protected and is reported in logs.
            $this->makeTreeWritable($backup);
            $this->recursiveDelete($backup);
            $residue = is_dir($backup);
            $message = $residue
                ? 'Ownership mismatch repaired automatically; existing contents were preserved. Protected cleanup residue was retained.'
                : 'Ownership mismatch repaired automatically; existing contents were preserved in a PHP-owned directory.';

            $this->repairMessages[$relativeRoot] = $message;
            $this->log->write('filesystem.ownership_repaired', [
                'path' => $relativeRoot,
                'residue' => $residue ? $backup : null,
            ]);
            return $message;
        } catch (Throwable $e) {
            $this->recursiveDelete($source);
            if (!is_dir($source) && @rename($backup, $source)) {
                $rolledBack = true;
            }
            $this->log->write('filesystem.ownership_repair_failed', [
                'path' => $relativeRoot,
                'rolled_back' => $rolledBack,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                'Automatic ownership repair failed for /' . $relativeRoot . '. ' .
                ($rolledBack ? 'The original directory was restored. ' : 'The original directory could not be restored automatically. ') .
                $e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function prepareRootProtection(): void
    {
        if ($this->rootProtectionPrepared) {
            return;
        }

        $path = $this->root . '/.htaccess';
        $existing = is_file($path) ? (string) @file_get_contents($path) : '';
        $marker = '# Velvet Vixxen private installer repair residues';
        if (str_contains($existing, $marker)) {
            $this->rootProtectionPrepared = true;
            return;
        }

        $protection = "\n" . $marker . "\n"
            . "<IfModule mod_rewrite.c>\n"
            . "  RewriteEngine On\n"
            . "  RewriteRule ^\\.vvx-private-repair- - [F,L,NC]\n"
            . "  RewriteRule ^\\.vvx-htaccess-original- - [F,L,NC]\n"
            . "</IfModule>\n"
            . "<FilesMatch \"^\\.vvx-\">\n"
            . "  Require all denied\n"
            . "</FilesMatch>\n";
        $payload = rtrim($existing) . "\n" . $protection;

        if (@file_put_contents($path, $payload, LOCK_EX) !== false) {
            @chmod($path, 0644);
            $this->rootProtectionPrepared = true;
            return;
        }

        // The control panel may own the existing .htaccess. Rotate it in the
        // writable document root, write a PHP-owned replacement, and restore it
        // if anything fails. The new deny rule also hides the rotated original.
        $backup = $this->root . '/.vvx-htaccess-original-' . bin2hex(random_bytes(10));
        if (is_file($path) && !@rename($path, $backup)) {
            throw new RuntimeException('The site root is writable, but its access-control file could not be protected for ownership repair.');
        }
        if (@file_put_contents($path, $payload, LOCK_EX) === false) {
            @unlink($path);
            if (is_file($backup)) {
                @rename($backup, $path);
            }
            throw new RuntimeException('The ownership-repair access-control rules could not be written.');
        }
        @chmod($path, 0644);
        $this->rootProtectionPrepared = true;
    }

    /** @return array{moved:list<string>,remaining:list<string>} */
    private function archiveRootRepairResidues(): array
    {
        $moved = [];
        $remaining = [];
        $archive = $this->root . '/storage/private/repair-backups';
        if (!is_dir($archive) && !@mkdir($archive, 0755, true) && !is_dir($archive)) {
            return ['moved' => [], 'remaining' => array_map('basename', (array) glob($this->root . '/.vvx-private-repair-*'))];
        }
        @file_put_contents($archive . '/.htaccess', "Require all denied
", LOCK_EX);
        foreach ((array) glob($this->root . '/.vvx-private-repair-*') as $source) {
            if (!is_dir($source) || is_link($source)) {
                continue;
            }
            $target = $archive . '/' . basename($source);
            if (file_exists($target)) {
                $target .= '-' . bin2hex(random_bytes(4));
            }
            if (@rename($source, $target)) {
                $moved[] = basename($target);
            } else {
                $remaining[] = basename($source);
            }
        }
        return ['moved' => $moved, 'remaining' => $remaining];
    }

    private function copyTree(string $source, string $destination): void
    {
        $items = @scandir($source);
        if (!is_array($items)) {
            throw new RuntimeException('The existing directory could not be read during ownership repair.');
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.vvx-write-') || str_starts_with($item, '.vvx-rename-')) {
                continue;
            }
            $from = $source . '/' . $item;
            $to = $destination . '/' . $item;

            if (is_link($from)) {
                throw new RuntimeException('Symbolic links are not accepted in runtime directories.');
            }
            if (is_dir($from)) {
                if (!@mkdir($to, 0755, true) && !is_dir($to)) {
                    throw new RuntimeException('A replacement subdirectory could not be created: ' . $item);
                }
                $this->copyTree($from, $to);
                continue;
            }
            if (!is_file($from) || !is_readable($from)) {
                throw new RuntimeException('An existing runtime file could not be read: ' . $item);
            }
            if (!@copy($from, $to)) {
                throw new RuntimeException('An existing runtime file could not be preserved: ' . $item);
            }
            @chmod($to, str_contains(str_replace('\\', '/', $to), '/config/') ? 0640 : 0644);
        }
    }

    /** @param array<string,array{writable:bool,mode:int,label:string,required:bool}> $manifest */
    private function applyManifestModes(string $relativeRoot, array $manifest): void
    {
        foreach ($manifest as $relative => $definition) {
            if ($relative !== $relativeRoot && !str_starts_with($relative, $relativeRoot . '/')) {
                continue;
            }
            $absolute = $this->root . '/' . $relative;
            if (!is_dir($absolute)) {
                if (!$definition['writable']) {
                    throw new RuntimeException('Required application directory is missing from the package: /' . $relative);
                }
                if (!@mkdir($absolute, $definition['mode'], true) && !is_dir($absolute)) {
                    throw new RuntimeException('A required runtime subdirectory could not be created: /' . $relative);
                }
            }
            @chmod($absolute, $definition['mode']);
        }
    }

    private function probe(string $directory): void
    {
        if (!is_writable($directory)) {
            throw new RuntimeException('PHP cannot write to this directory.');
        }
        $id = bin2hex(random_bytes(6));
        $first = $directory . '/.vvx-write-' . $id;
        $second = $directory . '/.vvx-rename-' . $id;
        $payload = random_bytes(48);
        if (@file_put_contents($first, $payload, LOCK_EX) !== strlen($payload)) {
            @unlink($first);
            throw new RuntimeException('The write test failed.');
        }
        if (!@rename($first, $second)) {
            @unlink($first);
            throw new RuntimeException('The rename test failed.');
        }
        $read = @file_get_contents($second);
        if (!is_string($read) || !hash_equals($payload, $read)) {
            @unlink($second);
            throw new RuntimeException('The read-back verification failed.');
        }
        if (!@unlink($second)) {
            throw new RuntimeException('The delete test failed.');
        }
    }

    private function writeProtectionFiles(): void
    {
        $deny = "Options -Indexes\nRequire all denied\n";
        foreach (['app', 'config', 'database', 'includes', 'storage', 'vendor', 'public/routes'] as $path) {
            $file = $this->root . '/' . $path . '/.htaccess';
            if (is_dir(dirname($file)) && (!is_file($file) || !str_contains((string) @file_get_contents($file), 'Require all denied'))) {
                @file_put_contents($file, $deny, LOCK_EX);
                @chmod($file, 0644);
            }
        }
        $addonProtection = "Options -Indexes\n<FilesMatch \"\\.(?:php|phtml|phar|inc)$\">\n  Require all denied\n</FilesMatch>\n";
        @file_put_contents($this->root . '/addons/.htaccess', $addonProtection, LOCK_EX);
        @chmod($this->root . '/addons/.htaccess', 0644);
    }

    private function lockInstallerDirectory(string $directory): void
    {
        @file_put_contents($directory . '/.disabled', gmdate('c') . PHP_EOL, LOCK_EX);
        @file_put_contents($directory . '/.htaccess', "Require all denied\n", LOCK_EX);
    }

    private function makeTreeWritable(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            @chmod($item->getPathname(), $item->isDir() ? 0775 : 0664);
        }
        @chmod($path, 0775);
    }

    private function recursiveDelete(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0664);
            @unlink($path);
            return;
        }
        $items = @scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->recursiveDelete($path . '/' . $item);
        }
        @chmod($path, 0775);
        @rmdir($path);
    }

    private function mode(string $path): string
    {
        $perms = @fileperms($path);
        return is_int($perms) ? substr(sprintf('%o', $perms), -4) : '----';
    }
}

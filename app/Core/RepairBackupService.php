<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class RepairBackupService
{
    private readonly string $root;
    private readonly string $archiveRoot;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('The site root is not available.');
        }
        $this->root = $real;
        $this->archiveRoot = $real . '/storage/private/repair-backups';
    }

    /** @return list<array<string,mixed>> */
    public function backups(): array
    {
        $paths = [];
        foreach (glob($this->root . '/.vvx-private-repair-*') ?: [] as $path) {
            if (is_dir($path) && !is_link($path)) {
                $paths[] = $path;
            }
        }
        if (is_dir($this->archiveRoot)) {
            $this->assertConfined($this->archiveRoot);
            foreach (glob($this->archiveRoot . '/*') ?: [] as $path) {
                if (is_dir($path) && !is_link($path)) {
                    $paths[] = $path;
                }
            }
            // Ownership-repair backups retain their original leading dot when archived.
            foreach (glob($this->archiveRoot . '/.vvx-private-repair-*') ?: [] as $path) {
                if (is_dir($path) && !is_link($path)) {
                    $paths[] = $path;
                }
            }
        }
        $rows = [];
        foreach (array_unique($paths) as $path) {
            $rows[] = $this->inspect($path);
        }
        usort($rows, static fn(array $a, array $b): int => ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0));
        return $rows;
    }

    /** @return array<string,mixed> */
    public function inspect(string $path): array
    {
        $path = $this->resolveBackupPath($path);
        $name = basename($path);
        $type = $this->detectType($name);
        $live = $type !== null ? $this->root . '/' . $type : null;
        $snapshot = $this->snapshot($path);
        $issues = $snapshot['issues'];
        $missing = [];
        $different = [];
        $same = 0;
        $bytes = 0;
        if ($live !== null) {
            try {
                $this->assertConfined($live, true);
                if (!file_exists($live)) {
                    $missing[] = './';
                } elseif (!is_dir($live)) {
                    $different[] = './';
                }
            } catch (Throwable $error) {
                $issues[] = $error->getMessage();
            }
        }
        foreach ($snapshot['files'] as $relative => $meta) {
            $bytes += $meta['size'];
            $target = $live !== null ? $live . '/' . $relative : null;
            if ($target === null) {
                $missing[] = $relative;
                continue;
            }
            try {
                $this->assertConfined($target, true);
                if (!file_exists($target)) {
                    $missing[] = $relative;
                } elseif (!is_file($target)) {
                    $different[] = $relative;
                } else {
                    $hash = @hash_file('sha256', $target);
                    if (!is_string($hash)) {
                        throw new RuntimeException('Unable to read the live copy of ' . $relative . '.');
                    }
                    if (!hash_equals($meta['sha256'], $hash)) {
                        $different[] = $relative;
                    } else {
                        $same++;
                    }
                }
            } catch (Throwable $error) {
                $issues[] = $error->getMessage();
            }
        }
        foreach ($snapshot['directories'] as $relative) {
            if ($live === null) {
                $missing[] = $relative . '/';
                continue;
            }
            try {
                $target = $live . '/' . $relative;
                $this->assertConfined($target, true);
                if (!file_exists($target)) {
                    $missing[] = $relative . '/';
                } elseif (!is_dir($target)) {
                    $different[] = $relative . '/';
                }
            } catch (Throwable $error) {
                $issues[] = $error->getMessage();
            }
        }
        $issues = array_values(array_unique($issues));
        return [
            'id' => $this->idForPath($path),
            'path' => $path,
            'name' => $name,
            'type' => $type ?? 'other',
            'location' => dirname($path) === $this->archiveRoot ? 'archive' : 'root',
            'modified' => @filemtime($path) ?: 0,
            'fileCount' => count($snapshot['files']),
            'bytes' => $bytes,
            'missingCount' => count($missing),
            'differentCount' => count($different),
            'sameCount' => $same,
            'missingPreview' => array_slice($missing, 0, 12),
            'differentPreview' => array_slice($different, 0, 12),
            'verificationIssues' => $issues,
            'canMerge' => $type !== null && $issues === [],
            'safeToDelete' => $type !== null && $issues === [] && $missing === [] && $different === [],
        ];
    }

    /** @return list<string> */
    public function legacyRootEntries(): array
    {
        $entries = [
            'account','api','blog','booking','campaign','cart','checkout','communication','extension','links',
            'membership','messages','music','newsletter','photos','receipt','requests','scripts','shop','videos','media.php',
        ];
        return array_values(array_filter($entries, function (string $entry): bool {
            if (!file_exists($this->root . '/' . $entry) && !is_link($this->root . '/' . $entry)) {
                return false;
            }
            try {
                return $this->legacyEntryParts($entry) !== [];
            } catch (Throwable) {
                // Keep unverifiable entries visible; the archive action reports
                // their failure without moving any part of that root entry.
                return true;
            }
        }));
    }

    /** @return array{moved:list<string>,failed:list<string>,archive:string} */
    public function archiveLegacyRootEntries(): array
    {
        $entries = $this->legacyRootEntries();
        $archive = $this->root . '/storage/private/legacy-root-layout-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5));
        if ($entries === []) {
            return ['moved' => [], 'failed' => [], 'archive' => $archive];
        }
        foreach (['route.php', 'public/router.php'] as $required) {
            $this->assertConfined($this->root . '/' . $required);
            if (!is_file($this->root . '/' . $required)) {
                throw new RuntimeException('The consolidated router is missing. Legacy route folders were not touched.');
            }
        }
        $this->makeDirectory($archive);
        $this->protectDirectory($archive);
        $moved = [];
        $failed = [];
        foreach ($entries as $entry) {
            $source = $this->root . '/' . $entry;
            try {
                $this->assertConfined($source);
                $replacement = $entry === 'scripts' ? $this->root . '/app/Console' : $this->root . '/public/routes/' . $entry;
                $this->assertConfined($replacement);
                if (!file_exists($replacement)) {
                    throw new RuntimeException('The consolidated replacement is missing; the original was preserved.');
                }
                // A modern route folder can also contain leftovers from an
                // older layout. Preserve only verified forwarding files, not
                // the entire folder and any old executable controllers in it.
                $parts = $this->legacyEntryParts($entry, true);
                foreach ($parts as $part) {
                    $source = $this->root . '/' . $part;
                    $target = $archive . '/' . $part;
                    $this->assertConfined($source);
                    $this->makeDirectory(dirname($target));
                    if (is_dir($source)) {
                        $this->moveDirectory($source, $target);
                    } elseif (is_file($source)) {
                        $meta = $this->fileMetadata($source);
                        if (!@rename($source, $target)) {
                            $this->copyVerifiedFile($source, $target, $meta, 0640);
                            $this->removeVerifiedFile($source, $target, $meta);
                        }
                    } else {
                        throw new RuntimeException('This entry is not a regular file or directory.');
                    }
                }
                if ($parts !== []) {
                    $moved[] = $entry;
                }
            } catch (Throwable $error) {
                $failed[] = $entry . ': ' . $error->getMessage();
            }
        }
        return ['moved' => $moved, 'failed' => $failed, 'archive' => $archive];
    }

    /** @return list<string> Root-relative entries that can be archived together. */
    private function legacyEntryParts(string $entry, bool $verifyContents = false): array
    {
        $source = $this->root . '/' . $entry;
        $this->assertConfined($source);
        if (is_file($source)) {
            return $this->isCurrentRouteForwarder($entry) ? [] : [$entry];
        }
        if (!is_dir($source)) {
            throw new RuntimeException('This entry is not a regular file or directory.');
        }
        $items = @scandir($source);
        if (!is_array($items)) {
            throw new RuntimeException('A legacy route folder could not be read.');
        }
        $remaining = [];
        $hasCurrentForwarder = false;
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $relative = $entry . '/' . $name;
            $this->assertConfined($this->root . '/' . $relative);
            if ($this->isCurrentRouteForwarder($relative)) {
                $hasCurrentForwarder = true;
            } else {
                $remaining[] = $relative;
            }
        }
        // Validate all archival contents before moving any sibling, including
        // unknown nested uploads and links. Nothing in an unsafe tree is lost.
        if ($verifyContents && $remaining !== []) {
            $this->requireSnapshot($source);
        }
        return $hasCurrentForwarder ? $remaining : [$entry];
    }

    private function isCurrentRouteForwarder(string $relative): bool
    {
        // Current compatibility files only forward a single, literal public
        // handler. A familiar filename, comment, or partial code match is not
        // sufficient to exempt a legacy controller from private archival.
        if (str_starts_with($relative, 'scripts/')
            || ($relative !== 'media.php' && !preg_match('#^[a-z0-9-]+/[a-z0-9-]+\\.php$#D', $relative))) {
            return false;
        }
        $source = $this->root . '/' . $relative;
        $this->assertConfined($source);
        if (!is_file($source)) {
            return false;
        }
        $contents = @file_get_contents($source);
        if (!is_string($contents)) {
            throw new RuntimeException('A public entrypoint could not be verified.');
        }
        $tokens = static function (string $code): array {
            $result = [];
            foreach (token_get_all($code) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $result[] = [$token[0], $token[0] === T_OPEN_TAG ? '<?php' : $token[1]];
                } else {
                    $result[] = $token;
                }
            }
            return $result;
        };
        $rootExpression = $relative === 'media.php' ? '__DIR__' : 'dirname(__DIR__)';
        $expected = "<?php declare(strict_types=1); if (!defined('ROOT_PATH')) { define('ROOT_PATH', "
            . $rootExpression . "); } require ROOT_PATH . '/public/routes/" . $relative . "';";
        if ($tokens($contents) !== $tokens($expected)) {
            return false;
        }
        $handler = $this->root . '/public/routes/' . $relative;
        $this->assertConfined($handler);
        if (!is_file($handler)) {
            throw new RuntimeException('A forwarding entrypoint has no regular protected handler. Its folder was preserved.');
        }
        return true;
    }

    /** @return array{moved:int,failed:list<string>} */
    public function archiveRootResidues(): array
    {
        $this->ensureArchiveRoot();
        $moved = 0;
        $failed = [];
        foreach (glob($this->root . '/.vvx-private-repair-*') ?: [] as $source) {
            if (!is_dir($source) || is_link($source)) {
                continue;
            }
            $target = $this->archiveRoot . '/' . basename($source);
            if (file_exists($target) || is_link($target)) {
                $target .= '-' . bin2hex(random_bytes(6));
            }
            try {
                $this->resolveBackupPath($source);
                $this->moveDirectory($source, $target);
                $moved++;
            } catch (Throwable $error) {
                $failed[] = basename($source) . ': ' . $error->getMessage();
            }
        }
        return ['moved' => $moved, 'failed' => $failed];
    }

    /** @return array{copied:int,skipped:int} */
    public function mergeMissing(string $id): array
    {
        $path = $this->pathForId($id);
        $type = $this->detectType(basename($path));
        if ($type === null) {
            throw new RuntimeException('This backup is not associated with a mergeable live folder.');
        }
        $snapshot = $this->requireSnapshot($path);
        $live = $this->root . '/' . $type;
        $this->assertConfined($live, true);
        // Validate every destination before the first write, including linked parent directories.
        foreach (array_merge(array_keys($snapshot['files']), $snapshot['directories']) as $relative) {
            $this->assertConfined($live . '/' . $relative, true);
        }
        $this->makeDirectory($live, 0755);
        $copied = 0;
        $skipped = 0;
        foreach ($snapshot['directories'] as $relative) {
            $target = $live . '/' . $relative;
            if (!file_exists($target)) {
                $this->makeDirectory($target, 0755);
            }
        }
        foreach ($snapshot['files'] as $relative => $meta) {
            // Installation identity is restored only by verified config/lock recovery.
            if (($type === 'config' && $relative === 'app.php') || ($type === 'storage' && $relative === 'install.lock')) {
                $skipped++;
                continue;
            }
            $source = $path . '/' . $relative;
            $target = $live . '/' . $relative;
            $this->assertConfined($target, true);
            if (file_exists($target) || is_link($target)) {
                $skipped++;
                continue;
            }
            $this->makeDirectory(dirname($target), 0755);
            $this->copyVerifiedFile($source, $target, $meta, in_array($type, ['config', 'storage'], true) ? 0640 : 0644);
            $copied++;
        }
        return ['copied' => $copied, 'skipped' => $skipped];
    }

    public function delete(string $id, string $confirmation = ''): void
    {
        $path = $this->pathForId($id);
        $info = $this->inspect($path);
        if (!$info['safeToDelete']) {
            throw new RuntimeException('This backup contains unique, different, or unverified entries and cannot be deleted. Restore or preserve it first.');
        }
        if ($confirmation !== 'DELETE VERIFIED BACKUP') {
            throw new RuntimeException('Type DELETE VERIFIED BACKUP to confirm deletion of this verified duplicate.');
        }
        $snapshot = $this->requireSnapshot($path);
        $this->deleteVerifiedTree($path, $this->root . '/' . $info['type'], $snapshot);
    }

    private function assertConfined(string $path, bool $allowMissing = false): void
    {
        if (str_contains($path, "\0") || !str_starts_with($path, $this->root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The selected path is outside the site root.');
        }
        $current = $this->root;
        foreach (explode(DIRECTORY_SEPARATOR, substr($path, strlen($this->root) + 1)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('The selected path contains an unsafe segment.');
            }
            $current .= DIRECTORY_SEPARATOR . $segment;
            clearstatcache(true, $current);
            if (is_link($current)) {
                throw new RuntimeException('Symbolic links are not supported in repair paths.');
            }
            if (!file_exists($current)) {
                if (!$allowMissing) {
                    throw new RuntimeException('The selected path is missing.');
                }
                continue;
            }
            if ($current !== $path && !is_dir($current)) {
                throw new RuntimeException('A repair path parent is not a directory.');
            }
        }
    }

    private function resolveBackupPath(string $path): string
    {
        $this->assertConfined($path);
        if (!is_dir($path)) {
            throw new RuntimeException('Repair backup not found.');
        }
        $isRootResidue = dirname($path) === $this->root && str_starts_with(basename($path), '.vvx-private-repair-');
        $isArchived = dirname($path) === $this->archiveRoot;
        if (!$isRootResidue && !$isArchived) {
            throw new RuntimeException('Select one backup folder; the archive root and nested paths cannot be modified.');
        }
        return $path;
    }

    private function idForPath(string $path): string
    {
        return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
    }

    private function pathForId(string $id): string
    {
        if ($id === '' || strlen($id) > 8192 || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new RuntimeException('Invalid repair-backup identifier.');
        }
        $encoded = strtr($id, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded) || $decoded === '') {
            throw new RuntimeException('Invalid repair-backup identifier.');
        }
        return $this->resolveBackupPath($decoded);
    }

    private function detectType(string $name): ?string
    {
        return preg_match('/^\.vvx-private-repair-(config|addons|database|storage|uploads)-/i', $name, $match)
            ? strtolower($match[1]) : null;
    }

    /** @return array{size:int,sha256:string} */
    private function fileMetadata(string $path): array
    {
        $this->assertConfined($path);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('A repair file cannot be read.');
        }
        $size = @filesize($path);
        $hash = @hash_file('sha256', $path);
        if (!is_int($size) || !is_string($hash)) {
            throw new RuntimeException('A repair file could not be fully verified.');
        }
        return ['size' => $size, 'sha256' => $hash];
    }

    /** @return array{files:array<string,array{size:int,sha256:string}>,directories:list<string>,issues:list<string>} */
    private function snapshot(string $path): array
    {
        $this->assertConfined($path);
        $files = [];
        $directories = [];
        $issues = [];
        $walk = function (string $directory, string $prefix = '') use (&$walk, &$files, &$directories, &$issues): void {
            $items = @scandir($directory);
            if (!is_array($items)) {
                $issues[] = 'A backup directory could not be read: ' . ($prefix !== '' ? $prefix : '.');
                return;
            }
            foreach ($items as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $relative = $prefix . $name;
                $entry = $directory . '/' . $name;
                try {
                    $this->assertConfined($entry);
                    if (is_dir($entry)) {
                        $directories[] = $relative;
                        $walk($entry, $relative . '/');
                    } elseif (is_file($entry)) {
                        $files[$relative] = $this->fileMetadata($entry);
                    } else {
                        $issues[] = 'An entry is not a regular file or directory: ' . $relative;
                    }
                } catch (Throwable $error) {
                    $issues[] = $relative . ': ' . $error->getMessage();
                }
            }
        };
        $walk($path);
        ksort($files);
        sort($directories, SORT_STRING);
        return ['files' => $files, 'directories' => $directories, 'issues' => $issues];
    }

    private function requireSnapshot(string $path): array
    {
        $snapshot = $this->snapshot($path);
        if ($snapshot['issues'] !== []) {
            throw new RuntimeException('The backup could not be fully verified: ' . $snapshot['issues'][0]);
        }
        return $snapshot;
    }

    private function makeDirectory(string $path, int $mode = 0750): void
    {
        $this->assertConfined($path, true);
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new RuntimeException('The destination directory could not be created.');
        }
        $this->assertConfined($path);
    }

    private function protectDirectory(string $path): void
    {
        $target = $path . '/.htaccess';
        $this->assertConfined($target, true);
        if (@file_put_contents($target, "Require all denied\n", LOCK_EX) !== strlen("Require all denied\n")) {
            throw new RuntimeException('The private archive could not be protected.');
        }
        @chmod($target, 0640);
    }

    private function ensureArchiveRoot(): void
    {
        $this->makeDirectory($this->archiveRoot);
        $this->protectDirectory($this->archiveRoot);
    }

    private function copyVerifiedFile(string $source, string $target, array $expected, int $mode): void
    {
        if ($this->fileMetadata($source) !== $expected) {
            throw new RuntimeException('A source file changed during recovery. The source was preserved.');
        }
        $this->assertConfined($target, true);
        $input = @fopen($source, 'rb');
        $output = @fopen($target, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) {
                fclose($output);
                @unlink($target);
            }
            throw new RuntimeException('A missing file could not be copied without replacing an existing entry.');
        }
        @chmod($target, $mode);
        try {
            $written = stream_copy_to_stream($input, $output);
            if ($written !== $expected['size'] || !fflush($output)) {
                throw new RuntimeException('The copied file was incomplete.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        if ($this->fileMetadata($target) !== $expected || $this->fileMetadata($source) !== $expected) {
            throw new RuntimeException('The copied file did not verify. The source was preserved.');
        }
    }

    private function moveDirectory(string $source, string $target): void
    {
        $snapshot = $this->requireSnapshot($source);
        $this->assertConfined($target, true);
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('The archive destination already exists.');
        }
        if (@rename($source, $target)) {
            return;
        }
        $this->makeDirectory($target);
        foreach ($snapshot['directories'] as $relative) {
            $this->makeDirectory($target . '/' . $relative);
        }
        foreach ($snapshot['files'] as $relative => $meta) {
            $this->makeDirectory(dirname($target . '/' . $relative));
            $this->copyVerifiedFile($source . '/' . $relative, $target . '/' . $relative, $meta, 0640);
        }
        if ($this->requireSnapshot($source) !== $snapshot || $this->requireSnapshot($target) !== $snapshot) {
            throw new RuntimeException('The archive did not match the source. Both copies were preserved.');
        }
        $this->deleteVerifiedTree($source, $target, $snapshot);
    }

    private function removeVerifiedFile(string $path, string $reference, array $expected): void
    {
        if ($this->fileMetadata($path) !== $expected || $this->fileMetadata($reference) !== $expected) {
            throw new RuntimeException('A backup or live file changed. Unverified data was preserved.');
        }
        if (!@unlink($path)) {
            throw new RuntimeException('The server did not allow the verified duplicate to be removed.');
        }
    }

    private function deleteVerifiedTree(string $path, string $reference, array $snapshot): void
    {
        if ($this->requireSnapshot($path) !== $snapshot) {
            throw new RuntimeException('The backup changed during verification. It was preserved.');
        }
        foreach ($snapshot['files'] as $relative => $meta) {
            if ($this->fileMetadata($reference . '/' . $relative) !== $meta) {
                throw new RuntimeException('A matching live or archived copy is no longer available.');
            }
        }
        foreach ($snapshot['directories'] as $relative) {
            $this->assertConfined($reference . '/' . $relative);
            if (!is_dir($reference . '/' . $relative)) {
                throw new RuntimeException('A directory no longer has a verified duplicate.');
            }
        }
        // Only directories need write permission for unlink; never widen secret-file permissions.
        $directories = $snapshot['directories'];
        usort($directories, static fn(string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/'));
        foreach (array_merge([$path], array_map(static fn(string $relative): string => $path . '/' . $relative, $directories)) as $directory) {
            $this->assertConfined($directory);
            @chmod($directory, 0750);
        }
        foreach ($snapshot['files'] as $relative => $meta) {
            $this->removeVerifiedFile($path . '/' . $relative, $reference . '/' . $relative, $meta);
        }
        foreach ($directories as $relative) {
            $directory = $path . '/' . $relative;
            $this->assertConfined($directory);
            if (!@rmdir($directory)) {
                throw new RuntimeException('A changed or nonempty backup directory was preserved.');
            }
        }
        if (!@rmdir($path)) {
            throw new RuntimeException('The server did not allow the backup directory to be removed.');
        }
    }
}

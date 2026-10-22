<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Velvet\Core\MarketingStorage;
use Velvet\Core\MarketingStore;
use Velvet\Core\MarketingPublication;

/** Private file preservation and reviewed withdrawal; no work is done on reads. */
final class ProtectionResources
{
    private readonly string $root;
    private readonly Store $store;

    public function __construct(private readonly Config $config)
    {
        $this->root = dirname($config->path(), 2);
        $this->store = new Store($config);
        require_once __DIR__ . '/MarketingStore.php';
    }

    public function catalog(): array
    {
        $records = $this->store->protectionMediaSnapshot();
        $editorial = (new MarketingStorage($this->root))->peek() ?? [];
        foreach (['post' => 'posts', 'landing_page' => 'landing_pages'] as $kind => $collection) {
            $records[$kind] = [];
            foreach (($editorial[$collection] ?? []) as $record) {
                if (!is_array($record) || !is_string($record['id'] ?? null)) throw new RuntimeException('A saved publication needs review before linking it.');
                $records[$kind][$record['id']] = $record;
            }
        }
        return $records;
    }

    public static function assetIds(string $kind, array $record): array
    {
        $ids = $kind === 'asset' ? [(string) ($record['id'] ?? '')] : [];
        foreach (['featured_asset_id', 'coverAssetId', 'audioAssetId', 'posterAssetId', 'trailerAssetId', 'fullAssetId', 'captionAssetId'] as $field) {
            if (is_scalar($record[$field] ?? null) && (string) $record[$field] !== '') $ids[] = (string) $record[$field];
        }
        foreach (($record['items'] ?? []) as $item) if (is_array($item) && is_scalar($item['assetId'] ?? null)) $ids[] = (string) $item['assetId'];
        foreach (($record['tracks'] ?? []) as $track) if (is_array($track)) $ids = array_merge($ids, self::assetIds('track', $track));
        $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));
        foreach ($ids as $id) ProtectionRecords::target(['kind' => 'asset', 'id' => $id]);
        sort($ids, SORT_STRING); return $ids;
    }

    /** A missing target stays missing; its frozen media identifiers remain held. */
    public function currentAssetIds(array $targets, ?array $catalog = null): array
    {
        $catalog ??= $this->catalog(); $ids = [];
        foreach ($targets as $target) {
            $target = ProtectionRecords::target($target);
            $row = $catalog[$target['kind']][$target['id']] ?? null;
            if ($row !== null) $ids = array_merge($ids, self::assetIds($target['kind'], $row));
        }
        $ids = array_values(array_unique($ids)); sort($ids, SORT_STRING); return $ids;
    }

    public function bindings(array $targets): array
    {
        $catalog = $this->catalog(); $assets = [];
        foreach (ProtectionRecords::targets($targets) as $target) {
            if (!isset($catalog[$target['kind']][$target['id']])) throw new RuntimeException('A linked item is no longer saved. Reload the current list.', 409);
            foreach (self::assetIds($target['kind'], $catalog[$target['kind']][$target['id']]) as $id) $assets[$id] = $catalog['asset'][$id] ?? null;
        }
        return $this->assetBindings($assets);
    }

    /** First activation also protects existing managed URLs against later edits. */
    public function allBindings(): array { return $this->assetBindings($this->store->protectionMediaSnapshot()['asset']); }

    public function assetBindings(array $assets): array
    {
        $ids = []; $files = []; $missing = [];
        foreach ($assets as $id => $asset) {
            $ids[] = (string) $id;
            if (!is_array($asset)) { $missing[] = (string) $id; continue; }
            foreach (['file', 'thumbnail'] as $field) {
                $path = self::publicPath((string) ($asset[$field] ?? ''), $this->root);
                if ($path === null) continue;
                $files[$path] = $this->inspect($path);
            }
        }
        sort($ids, SORT_STRING); ksort($files, SORT_STRING);
        return ['asset_ids' => $ids, 'files' => $files, 'missing_assets' => $missing];
    }

    public static function publicPath(string $path, string $root): ?string
    {
        if ($path === '' || str_starts_with($path, 'private:') || str_starts_with($path, 'data:') || str_starts_with($path, 'blob:') || preg_match('#^(?:https?:)?//#i', $path)) return null;
        if (strlen($path) > 1024 || preg_match('/\A[A-Za-z0-9_.\/-]+\z/D', $path) !== 1
            || str_contains($path, '//') || array_intersect(explode('/', $path), ['.', '..']) !== []) throw new RuntimeException('A managed media path needs review before protection can be enabled.');
        $path = ltrim($path, '/');
        // Shipped interface artwork is not an owner upload.
        if (str_starts_with($path, 'images/') && is_file($root . '/' . $path)) return null;
        return str_starts_with($path, 'uploads/') ? $path : 'uploads/' . $path;
    }

    private function inspect(string $path): array
    {
        $absolute = $this->confined($path, false);
        if ($absolute === null) return ['path' => $path, 'missing' => true];
        $size = filesize($absolute); $hash = hash_file('sha256', $absolute);
        if (!is_int($size) || $size < 1 || $size > 2147483648 || !is_string($hash)) throw new RuntimeException('A managed file could not be verified for private preservation.');
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($absolute);
        return ['path' => $path, 'sha256' => $hash, 'bytes' => $size, 'mime' => $mime ?: 'application/octet-stream'];
    }

    /** Called only inside a private-state mutation, after current owner checks. */
    public function preserve(array $bindings, array &$vault, ?callable $authorize = null): void
    {
        self::validateVault($vault);
        foreach ($bindings['files'] as $path => $file) {
            if ($authorize !== null) $authorize();
            $key = hash('sha256', $path);
            $saved = $vault[$key] ?? null;
            if ($saved !== null) {
                $this->vaultFile($saved);
                $public = $this->confined($path, false);
                if ($public !== null) {
                    if (!hash_equals($saved['sha256'], (string) hash_file('sha256', $public))) throw new RuntimeException('A previously protected public file changed. Its saved private copy was preserved; review storage before continuing.', 409);
                    if ($authorize !== null) $authorize();
                    if (!@unlink($public)) throw new RuntimeException('The old public media address could not be withdrawn.', 503);
                }
                continue;
            }
            if (!empty($file['missing'])) {
                // An interruption may have moved bytes before committing their
                // journal. No public copy is recreated; a retry can recover it.
                $candidate = $this->root . '/storage/private/protected-assets/' . $key . '.bin';
                $absolute = $this->confined('storage/private/protected-assets/' . $key . '.bin', false);
                if ($absolute === null) continue; // Already missing media stays unavailable.
                $file = ['path' => $path, 'sha256' => hash_file('sha256', $candidate), 'bytes' => filesize($candidate),
                    'mime' => (new \finfo(FILEINFO_MIME_TYPE))->file($candidate) ?: 'application/octet-stream'];
            }
            $relative = 'storage/private/protected-assets/' . $key . '.bin';
            $destination = $this->root . '/' . $relative;
            $this->privateDirectory();
            $public = $this->confined($path, false);
            if ($public !== null) {
                if (!hash_equals($file['sha256'], (string) hash_file('sha256', $public)) || filesize($public) !== $file['bytes']) throw new RuntimeException('Media changed during the protection review. Reload before continuing.', 409);
                if ($authorize !== null) $authorize();
                if (file_exists($destination) || is_link($destination)) {
                    $existing = $this->confined($relative, false);
                    if ($existing === null || !hash_equals($file['sha256'], (string) hash_file('sha256', $existing))) throw new RuntimeException('An existing private copy needs review before replacing it.', 409);
                    if (!@unlink($public)) throw new RuntimeException('The old public media address could not be withdrawn.', 503);
                } elseif (!@rename($public, $destination)) throw new RuntimeException('The media could not be preserved privately. Publication remains held; review file permissions.', 503);
            }
            if (!@chmod($destination, 0600)) throw new RuntimeException('The preserved media could not be made private.', 503);
            $entry = ['path' => $path, 'storage' => $relative, 'sha256' => $file['sha256'], 'bytes' => $file['bytes'], 'mime' => $file['mime'], 'created_at' => gmdate('c')];
            $this->vaultFile($entry); $vault[$key] = $entry;
        }
        self::validateVault($vault);
        if ($authorize !== null) $authorize();
    }

    public static function validateVault(array $vault): void
    {
        if (count($vault) > 10000) throw new RuntimeException('The private media workspace has reached its limit.');
        foreach ($vault as $key => $row) {
            if (!is_array($row) || count($row) !== 6 || !is_string($row['path'] ?? null) || preg_match('#\Auploads/[A-Za-z0-9_./-]+\z#D', $row['path']) !== 1
                || array_intersect(explode('/', $row['path']), ['.', '..']) !== [] || str_contains($row['path'], '//')
                || hash('sha256', $row['path']) !== $key || ($row['storage'] ?? '') !== 'storage/private/protected-assets/' . $key . '.bin'
                || !is_string($row['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $row['sha256']) !== 1
                || !is_int($row['bytes'] ?? null) || $row['bytes'] < 1 || $row['bytes'] > 2147483648
                || !is_string($row['mime'] ?? null) || preg_match('#\A[a-z0-9.+-]+/[a-z0-9.+-]+\z#D', $row['mime']) !== 1
                || !is_string($row['created_at'] ?? null) || strtotime($row['created_at']) === false) throw new RuntimeException('The protected media journal needs a storage review.');
        }
    }

    public function vaultFile(array $entry): string
    {
        self::validateVault([hash('sha256', $entry['path']) => $entry]);
        $path = $this->confined($entry['storage'], false);
        if ($path === null || filesize($path) !== $entry['bytes'] || !hash_equals($entry['sha256'], (string) hash_file('sha256', $path))) throw new RuntimeException('The preserved media did not match its checked copy.', 503);
        return $path;
    }

    private function confined(string $relative, bool $directory): ?string
    {
        $path = $this->root;
        foreach (explode('/', $relative) as $part) {
            $path .= '/' . $part;
            clearstatcache(true, $path);
            if (is_link($path)) throw new RuntimeException('Managed media must not use linked filesystem paths.', 503);
        }
        $stat = @lstat($path);
        if ($stat === false) return null;
        if (($stat['mode'] & 0170000) !== ($directory ? 0040000 : 0100000) || (!$directory && $stat['nlink'] !== 1)
            || !str_starts_with((string) realpath($path), realpath($this->root) . '/')) throw new RuntimeException('Managed media must remain inside its installation.', 503);
        return $path;
    }

    private function privateDirectory(): void
    {
        $path = $this->root;
        foreach (['storage', 'private', 'protected-assets'] as $part) {
            $path .= '/' . $part;
            if (is_link($path) || (file_exists($path) && !is_dir($path))) throw new RuntimeException('Private media requires real directories.', 503);
            if (!is_dir($path) && !@mkdir($path, 0700)) throw new RuntimeException('Private media storage is unavailable.', 503);
            if ($part !== 'storage' && !@chmod($path, 0700)) throw new RuntimeException('Private media storage permissions need review.', 503);
        }
    }

    public function withdrawalReview(array $targets, array $frozenAssets = []): array
    {
        $catalog = $this->catalog();
        $assets = array_unique(array_merge($frozenAssets, $this->currentAssetIds($targets, $catalog)));
        $linked = []; foreach ($targets as $target) $linked[$target['kind'] . ':' . $target['id']] = true;
        $rows = [];
        foreach ($catalog as $kind => $records) {
            if ($kind === 'asset') continue;
            foreach ($records as $id => $row) {
                if (!isset($linked[$kind . ':' . $id]) && array_intersect($assets, self::assetIds($kind, $row)) === []) continue;
                $rows[] = ['kind' => $kind, 'id' => (string) $id, 'title' => (string) ($row['title'] ?? $row['name'] ?? 'Saved item'),
                    'revision' => ProtectionRecords::revision($row),
                    'editorial_revision' => in_array($kind, ['post', 'landing_page'], true) ? MarketingPublication::revision($row) : null];
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['kind'] . ':' . $a['id'], $b['kind'] . ':' . $b['id']));
        $assetRows = [];
        foreach ($assets as $id) $assetRows[] = ['id' => $id, 'revision' => isset($catalog['asset'][$id]) ? ProtectionRecords::revision($catalog['asset'][$id]) : 'missing'];
        usort($assetRows, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        return ['items' => $rows, 'assets' => $assetRows, 'revision' => ProtectionRecords::revision(['items' => $rows, 'assets' => $assetRows])];
    }

    public function withdraw(array $targets, mixed $expected, array $owner, callable $privileged, array $frozenAssets = []): array
    {
        $review = $this->withdrawalReview($targets, $frozenAssets);
        if (!is_string($expected) || !hash_equals($review['revision'], $expected)) throw new RuntimeException('Linked content changed. Review the current list before lifting this hold.', 409);
        $guard = function () use ($owner, $privileged): void { (new ProtectionRecords($this->config))->requireOwner($owner); $privileged(); };
        $media = array_values(array_filter($review['items'], static fn(array $row): bool => !in_array($row['kind'], ['post', 'landing_page'], true)));
        $this->store->withdrawProtectedMedia($media, $guard);
        foreach ($review['items'] as $row) {
            if (!in_array($row['kind'], ['post', 'landing_page'], true)) continue;
            (new MarketingStore($this->root))->setPublicationEnabled($row['kind'] === 'post' ? 'posts' : 'landing_pages', $row['id'], false, $row['editorial_revision'], $guard);
        }
        $guard();
        $after = $this->withdrawalReview($targets, $frozenAssets);
        return ['targets' => $targets, 'frozen_assets' => $frozenAssets, 'revision' => $after['revision'], 'assets' => $after['assets']];
    }
}

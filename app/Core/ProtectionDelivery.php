<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/** Resolve only current managed paths; no route accepts an arbitrary filename. */
final class ProtectionDelivery
{
    private readonly ProtectionRecords $records;
    private readonly ProtectionResources $resources;
    private readonly string $root;

    public function __construct(private readonly Config $config)
    {
        $this->root = dirname($config->path(), 2);
        $this->records = new ProtectionRecords($config);
        $this->resources = new ProtectionResources($config);
    }

    /** null retains an unmanaged/disabled URL; an empty string denies delivery. */
    public function url(string $path, ?string $assetId = null): ?string
    {
        if (!$this->records->enabled()) return null;
        try {
            if ($assetId !== null && !$this->records->publicationAllowed('asset', $assetId)) return '';
            $relative = ProtectionResources::publicPath($path, $this->root);
            if ($relative === null) return null;
            $entry = $this->records->vaultEntry($relative);
            if ($entry === null) return '';
            $this->resolve(hash('sha256', $relative));
            return 'route.php?vvx_route=protected-asset.php&file=' . hash('sha256', $relative);
        } catch (Throwable) { return ''; }
    }

    public function resolve(string $key): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1 || !$this->records->enabled()) throw new RuntimeException('Media is unavailable.', 404);
        $snapshot = $this->records->snapshot();
        if (!$snapshot['ready'] || $this->config->get('protection.setup_pending', false) === true) throw new RuntimeException('Media is unavailable while protection storage is reviewed.', 503);
        $entry = $snapshot['vault'][$key] ?? null;
        if ($entry === null) throw new RuntimeException('Media is unavailable.', 404);
        $matched = false;
        foreach ((new Store($this->config))->media(true) as $asset) {
            foreach (['file', 'thumbnail'] as $field) {
                if (ProtectionResources::publicPath((string) ($asset[$field] ?? ''), $this->root) !== $entry['path']) continue;
                $matched = true;
                if (!$this->records->publicationAllowed('asset', (string) $asset['id'], $asset)) throw new RuntimeException('This media is unavailable.', 403);
            }
        }
        if (!$matched) throw new RuntimeException('Media is unavailable.', 404);
        return $entry + ['absolute' => $this->resources->vaultFile($entry)];
    }
}

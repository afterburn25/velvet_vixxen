<?php
declare(strict_types=1);

namespace Velvet\Core;

use App\Core\Config;
use App\Core\Store;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/MarketingAudience.php';

/** Optional publication metadata. Access and publication timing remain separate. */
final class MarketingMetadata
{
    public const MAX_TAGS = 30;
    public const MAX_TAG_LENGTH = 64;
    public const MAX_RELATED = 6;
    public const MAX_AUTHOR = 120;
    public const MAX_ALT = 240;
    public const MAX_SEO_TITLE = 120;
    public const MAX_SEO_DESCRIPTION = 320;

    public static function defaults(string $collection): array
    {
        if ($collection === 'banners') return [];
        if (!in_array($collection, ['posts', 'landing_pages'], true)) throw new InvalidArgumentException('Choose a post or landing page.');
        $values = ['featured_asset_id' => '', 'featured_alt' => '', 'seo_title' => '', 'seo_description' => ''];
        return $collection === 'posts' ? $values + ['tags' => [], 'author_name' => '', 'related_post_ids' => []] : $values;
    }

    /** Only explicitly submitted keys are replaced; older callers retain saved metadata. */
    public static function fields(string $collection, array $input): array
    {
        $result = [];
        foreach (self::defaults($collection) as $key => $_) {
            if (array_key_exists($key, $input)) $result[$key] = self::field($key, $input[$key]);
        }
        return $result;
    }

    /** Malformed saved fields are never echoed; owners receive a repair notice. */
    public static function describe(string $collection, array $record): array
    {
        $values = self::defaults($collection);
        $problems = [];
        foreach ($values as $key => $_) {
            if (!array_key_exists($key, $record)) continue;
            try { $values[$key] = self::field($key, $record[$key]); }
            catch (InvalidArgumentException) { $problems[] = ucfirst(str_replace('_', ' ', $key)) . ' needs review. Re-enter this value before saving.'; }
        }
        return ['values' => $values, 'problems' => $problems];
    }

    public static function tagsFromText(mixed $value): array
    {
        if (!is_string($value) || strlen($value) > self::MAX_TAGS * (self::MAX_TAG_LENGTH * 4 + 1)) {
            throw new InvalidArgumentException('Enter at most 30 comma-separated tags.');
        }
        return self::tags($value === '' ? [] : explode(',', $value));
    }

    public static function tags(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_TAGS) {
            throw new InvalidArgumentException('Use at most 30 tags.');
        }
        $tags = []; $seen = [];
        foreach ($value as $tag) {
            $tag = MarketingAudience::text($tag, self::MAX_TAG_LENGTH, 'Tag');
            if ($tag === '') continue;
            if (str_contains($tag, ',')) throw new InvalidArgumentException('Separate tags with commas.');
            $key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
            if (!isset($seen[$key])) { $seen[$key] = true; $tags[] = $tag; }
        }
        return $tags;
    }

    public static function relatedIds(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_RELATED) {
            throw new InvalidArgumentException('Choose at most six related posts in the order to show them.');
        }
        $ids = [];
        foreach ($value as $id) {
            if (!is_string($id) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $id)) {
                throw new InvalidArgumentException('Choose valid saved related posts.');
            }
            if (in_array($id, $ids, true)) throw new InvalidArgumentException('Choose each related post only once.');
            $ids[] = $id;
        }
        return $ids;
    }

    public static function mediaId(mixed $value): string
    {
        if (!is_string($value) || ($value !== '' && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,99}\z/D', $value) !== 1)) {
            throw new InvalidArgumentException('Choose a saved featured image.');
        }
        return $value;
    }

    private static function field(string $key, mixed $value): mixed
    {
        return match ($key) {
            'tags' => self::tags($value),
            'related_post_ids' => self::relatedIds($value),
            'featured_asset_id' => self::mediaId($value),
            'author_name' => MarketingAudience::text($value, self::MAX_AUTHOR, 'Author display name'),
            'featured_alt' => MarketingAudience::text($value, self::MAX_ALT, 'Featured image description'),
            'seo_title' => MarketingAudience::text($value, self::MAX_SEO_TITLE, 'Search title'),
            'seo_description' => MarketingAudience::text($value, self::MAX_SEO_DESCRIPTION, 'Search description'),
            default => throw new InvalidArgumentException('Unsupported editorial metadata.'),
        };
    }

    /**
     * An existing public image original only. A public thumbnail does not make a
     * protected original eligible. Returned paths are local, never signed URLs.
     */
    public static function image(array $asset, string $root, bool $ownerReview = false): ?array
    {
        if (!empty($asset['private']) || !empty($asset['deletedAt']) || !empty($asset['deleted_at'])) return null;
        $id = $asset['id'] ?? null;
        if (!is_string($id) && !is_int($id)) return null;
        try { $id = self::mediaId((string) $id); } catch (InvalidArgumentException) { return null; }
        if ($id === '') return null;
        $file = $asset['file'] ?? null;
        if (!is_string($file) || $file === '' || strlen($file) > 1024
            || preg_match('/[\x00-\x20\x7F\\\\:%?#]/', $file) || str_contains($file, '//')) return null;
        $file = ltrim($file, '/');
        if (preg_match('/\A[A-Za-z0-9_.\/-]+\z/D', $file) !== 1
            || array_intersect(explode('/', $file), ['.', '..']) !== []
            || preg_match('/\.(?:png|jpe?g|webp|gif|avif)\z/iD', $file) !== 1) return null;
        $mime = $asset['mime'] ?? null;
        $hasMime = array_key_exists('mime', $asset) && $mime !== '';
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
        // Older bundled images omit MIME; SQL maps its nullable column to ''.
        // Inspect those actual bytes; explicit null, invalid or mismatched MIME fails.
        if ($hasMime && (!is_string($mime) || !in_array($mime, $allowed, true))) return null;
        $root = realpath($root);
        if ($root === false) return null;
        $protection = new \App\Core\ProtectionRecords(new Config($root . '/config/app.php'));
        if (!$ownerReview && !$protection->publicationAllowed('asset', $id, $asset)) return null;
        $relative = str_starts_with($file, 'uploads/') ? $file
            : (str_starts_with($file, 'images/') && is_file($root . '/' . $file) ? $file : 'uploads/' . $file);
        $parent = $root;
        foreach (explode('/', $relative) as $part) {
            $parent .= '/' . $part;
            if (is_link($parent)) return null;
        }
        $absolute = realpath($root . '/' . $relative);
        $allowedRoot = realpath($root . '/' . (str_starts_with($relative, 'uploads/') ? 'uploads' : 'images'));
        $protected = null;
        if ($protection->enabled()) {
            try {
                $protected = $protection->vaultEntry($relative);
                if ($protected !== null) $absolute = (new \App\Core\ProtectionResources(new Config($root . '/config/app.php')))->vaultFile($protected);
            } catch (Throwable) { return null; }
        }
        if ($protected === null && ($absolute === false || $allowedRoot === false || !is_file($absolute)
            || !str_starts_with($absolute, $allowedRoot . DIRECTORY_SEPARATOR)
            || !str_starts_with($allowedRoot, $root . DIRECTORY_SEPARATOR))) return null;
        $dimensions = @getimagesize($absolute);
        if ($dimensions === false || !in_array($dimensions['mime'] ?? '', $allowed, true)
            || ($hasMime && ($dimensions['mime'] ?? '') !== $mime) || ($dimensions[0] ?? 0) < 1 || ($dimensions[1] ?? 0) < 1) return null;
        try { $title = MarketingAudience::text($asset['title'] ?? '', 255, 'Image title'); }
        catch (InvalidArgumentException) { $title = ''; }
        return ['id' => $id, 'title' => $title !== '' ? $title : 'Saved image', 'path' => $protected === null ? $relative : 'route.php?vvx_route=protected-asset.php&file=' . hash('sha256', $relative),
            'width' => (int) $dimensions[0], 'height' => (int) $dimensions[1]];
    }

    /** Fresh catalogue resolution; missing or changed assets are omitted on reads. */
    public static function resolveImage(string $id, string $root, bool $ownerReview = false): ?array
    {
        if ($id === '') return null;
        try {
            self::mediaId($id);
            $asset = self::assetStore($root)->mediaAsset($id, true);
            return $asset === null ? null : self::image($asset, $root, $ownerReview);
        } catch (Throwable) { return null; }
    }

    public static function imageCatalog(string $root): array
    {
        $images = []; $seen = [];
        foreach (self::assetStore($root)->media(true) as $asset) {
            if (!is_array($asset)) continue;
            $image = self::image($asset, $root);
            if ($image === null) continue;
            if (isset($seen[$image['id']])) throw new RuntimeException('Duplicate media identities need review before choosing an image.');
            $seen[$image['id']] = true;
            $images[] = $image;
        }
        return $images;
    }

    /** Resolve media without creating missing configuration or a missing file store. */
    private static function assetStore(string $root): Store
    {
        $root = realpath($root);
        if ($root === false || !function_exists('root_path') || realpath(\root_path()) !== $root) {
            throw new RuntimeException('The current media catalogue could not be verified.');
        }
        $configPath = $root . '/config/app.php';
        if (!is_file($configPath) || is_link($configPath)) throw new RuntimeException('The current media catalogue could not be verified.');
        $config = new Config($configPath);
        if ($config->get('storage_mode', 'mysql') === 'file') {
            $path = $config->get('file_store_path', 'storage/data.json');
            if (!is_string($path) || !is_file($root . '/' . ltrim($path, '/\\'))) {
                throw new RuntimeException('The saved media catalogue is unavailable.');
            }
        }
        return new Store($config);
    }
}

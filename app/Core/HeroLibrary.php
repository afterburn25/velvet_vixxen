<?php
declare(strict_types=1);

namespace App\Core;

use JsonException;

/** Validates bundled background metadata and files before they enter the picker. */
final class HeroLibrary
{
    public function __construct(private readonly string $root)
    {
    }

    /** @return array{version:int,categories:array,scenes:array,unavailable:array,warnings:array} */
    public function catalog(): array
    {
        $catalog = ['version' => 1, 'categories' => [], 'scenes' => [], 'unavailable' => [], 'warnings' => []];
        $manifest = rtrim($this->root, '/\\') . '/images/hero/manifest.json';
        if (!is_file($manifest) || !is_readable($manifest)) {
            $catalog['warnings'][] = 'The background library manifest is not available.';
            return $catalog;
        }
        if ((int) filesize($manifest) > 5_000_000) {
            $catalog['warnings'][] = 'The background library manifest is too large to load.';
            return $catalog;
        }
        try {
            $data = json_decode((string) file_get_contents($manifest), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $catalog['warnings'][] = 'The background library manifest contains invalid JSON.';
            return $catalog;
        }
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['scenes'] ?? null) || !array_is_list($data['scenes'])) {
            $catalog['warnings'][] = 'The background library manifest has an unsupported format.';
            return $catalog;
        }

        $categories = [];
        foreach ((array) ($data['categories'] ?? []) as $category) {
            if (!is_array($category)) {
                continue;
            }
            $id = is_string($category['id'] ?? null) ? $category['id'] : '';
            if ($this->validId($id)) {
                $categories[$id] = ['id' => $id, 'title' => $this->label($category['title'] ?? $id, 100), 'count' => 0];
            }
        }

        $seen = [];
        foreach (array_slice($data['scenes'], 0, 1000) as $row) {
            if (!is_array($row)) {
                $catalog['unavailable'][] = ['id' => '', 'title' => 'Invalid library entry', 'reason' => 'Scene metadata is not an object.'];
                continue;
            }
            $id = is_string($row['id'] ?? null) ? $row['id'] : '';
            $title = $this->label($row['title'] ?? $id, 160);
            $reason = null;
            if (!$this->validId($id) || isset($seen[$id])) {
                $reason = 'The scene identifier is missing, invalid, or duplicated.';
            }
            $seen[$id] = true;
            $image = $this->image(is_string($row['file'] ?? null) ? $row['file'] : '');
            $thumbnail = $this->image(is_string($row['thumbnail'] ?? null) ? $row['thumbnail'] : '');
            if ($image === null || $thumbnail === null) {
                $reason = 'The scene image or thumbnail is missing or is not a valid bundled image.';
            }
            if ($reason !== null) {
                $catalog['unavailable'][] = ['id' => $id, 'title' => $title !== '' ? $title : 'Unavailable scene', 'reason' => $reason];
                continue;
            }

            $categoryId = is_string($row['category'] ?? null) ? $row['category'] : 'other';
            if (!$this->validId($categoryId)) {
                $categoryId = 'other';
            }
            $categories[$categoryId] ??= ['id' => $categoryId, 'title' => ucwords(str_replace(['-', '_'], ' ', $categoryId)), 'count' => 0];
            $categories[$categoryId]['count']++;
            $keywords = [];
            foreach (array_slice((array) ($row['keywords'] ?? []), 0, 24) as $keyword) {
                if (is_scalar($keyword)) {
                    $keywords[] = $this->label($keyword, 64);
                }
            }
            $catalog['scenes'][] = [
                'id' => $id,
                'title' => $title !== '' ? $title : ucwords(str_replace('-', ' ', $id)),
                'category' => $categoryId,
                'categoryTitle' => $categories[$categoryId]['title'],
                'file' => $image['path'],
                'thumbnail' => $thumbnail['path'],
                'width' => $image['width'],
                'height' => $image['height'],
                'orientation' => $image['width'] === $image['height'] ? 'square' : ($image['width'] > $image['height'] ? 'landscape' : 'portrait'),
                'placement' => $this->label($row['placement'] ?? 'Choose an open foreground area for a separate photo layer.', 280),
                'modelZone' => $this->zone($row['modelZone'] ?? null),
                'textZone' => $this->zone($row['textZone'] ?? null),
                'keywords' => array_values(array_filter($keywords, static fn(string $word): bool => $word !== '')),
            ];
        }
        $catalog['categories'] = array_values(array_filter($categories, static fn(array $category): bool => $category['count'] > 0));
        if (count($data['scenes']) > 1000) {
            $catalog['warnings'][] = 'Only the first 1,000 background entries were loaded.';
        }
        if ($catalog['unavailable'] !== []) {
            $catalog['warnings'][] = count($catalog['unavailable']) . ' background scenes are unavailable. Their images or metadata need attention.';
        }
        return $catalog;
    }

    private function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $id);
    }

    private function label(mixed $value, int $length): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    private function image(string $path): ?array
    {
        if (!str_starts_with($path, 'images/hero/') || str_contains($path, '\\') || str_contains($path, "\0") || str_contains($path, '..')) {
            return null;
        }
        $libraryRoot = realpath(rtrim($this->root, '/\\') . '/images/hero');
        $file = realpath(rtrim($this->root, '/\\') . '/' . $path);
        if ($libraryRoot === false || $file === false || !str_starts_with($file, $libraryRoot . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }
        if ((int) filesize($file) < 1 || (int) filesize($file) > 50_000_000) {
            return null;
        }
        $size = @getimagesize($file);
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'avif' => 'image/avif', 'gif' => 'image/gif'];
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!is_array($size) || !isset($allowed[$extension]) || ($size['mime'] ?? '') !== $allowed[$extension] || $size[0] < 1 || $size[1] < 1 || $size[0] > 16000 || $size[1] > 16000) {
            return null;
        }
        return ['path' => $path, 'width' => (int) $size[0], 'height' => (int) $size[1]];
    }

    private function zone(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $zone = [];
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (!isset($value[$key]) || !is_numeric($value[$key]) || !is_finite((float) $value[$key])) {
                return null;
            }
            $zone[$key] = max(0.0, min(100.0, (float) $value[$key]));
        }
        $zone['width'] = min($zone['width'], 100 - $zone['x']);
        $zone['height'] = min($zone['height'], 100 - $zone['y']);
        return $zone['width'] > 0 && $zone['height'] > 0 ? $zone : null;
    }
}

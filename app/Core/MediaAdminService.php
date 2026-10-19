<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class MediaAdminService
{
    public function __construct(
        private readonly Store $store,
        private readonly MediaUploadService $uploads = new MediaUploadService(),
    ) {
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $files */
    public function saveAlbum(array $post, array $files): array
    {
        $existing = $this->existing('album', $post['id'] ?? '');
        $coverId = (string) ($post['cover_asset_id'] ?? $existing['coverAssetId'] ?? '');
        if ($this->hasUpload($files['cover'] ?? null)) {
            $cover = $this->storeAsset($this->uploads->upload((array) $files['cover'], 'poster', false), 'album-cover');
            $coverId = (string) $cover['id'];
        }

        return $this->store->saveAlbum([
            'id' => (string) ($post['id'] ?? ''),
            'title' => (string) ($post['title'] ?? ''),
            'slug' => (string) ($post['slug'] ?? ''),
            'description' => (string) ($post['description'] ?? ''),
            'coverAssetId' => $coverId,
            'status' => (string) ($post['status'] ?? 'draft'),
            'publishedAt' => $this->nullableDate($post['published_at'] ?? null),
            'scheduledAt' => $this->nullableDate($post['scheduled_at'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $files */
    public function saveTrack(array $post, array $files): array
    {
        $existing = $this->existing('track', $post['id'] ?? '');
        $audioId = (string) ($post['audio_asset_id'] ?? $existing['audioAssetId'] ?? '');
        $coverId = (string) ($post['cover_asset_id'] ?? $existing['coverAssetId'] ?? '');

        if ($this->hasUpload($files['audio'] ?? null)) {
            // Originals stay outside the public web directory. Public tracks are served with signed URLs.
            $audio = $this->storeAsset($this->uploads->upload((array) $files['audio'], 'audio', true), 'audio');
            $audioId = (string) $audio['id'];
        }
        if ($this->hasUpload($files['cover'] ?? null)) {
            $cover = $this->storeAsset($this->uploads->upload((array) $files['cover'], 'poster', false), 'track-cover');
            $coverId = (string) $cover['id'];
        }
        if ($audioId === '') {
            throw new RuntimeException('Upload an audio file before saving the track.');
        }

        return $this->store->saveTrack([
            'id' => (string) ($post['id'] ?? ''),
            'albumId' => (string) ($post['album_id'] ?? ''),
            'title' => (string) ($post['title'] ?? ''),
            'slug' => (string) ($post['slug'] ?? ''),
            'artist' => (string) ($post['artist'] ?? 'Nikki Wilder'),
            'audioAssetId' => $audioId,
            'coverAssetId' => $coverId,
            'lyrics' => (string) ($post['lyrics'] ?? ''),
            'status' => (string) ($post['status'] ?? 'draft'),
            'accessMode' => (string) ($post['access_mode'] ?? 'public'),
            'previewSeconds' => (int) ($post['preview_seconds'] ?? 0),
            'sortOrder' => (int) ($post['sort_order'] ?? 100),
            'publishedAt' => $this->nullableDate($post['published_at'] ?? null),
            'scheduledAt' => $this->nullableDate($post['scheduled_at'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $post */
    public function saveAudioPlaylist(array $post): array
    {
        return $this->store->saveAudioPlaylist([
            'id' => (string) ($post['id'] ?? ''),
            'title' => (string) ($post['title'] ?? ''),
            'slug' => (string) ($post['slug'] ?? ''),
            'description' => (string) ($post['description'] ?? ''),
            'coverAssetId' => (string) ($post['cover_asset_id'] ?? ''),
            'trackIds' => array_values(array_filter(array_map('strval', (array) ($post['track_ids'] ?? [])))),
            'status' => (string) ($post['status'] ?? 'draft'),
            'accessMode' => (string) ($post['access_mode'] ?? 'public'),
            'publishedAt' => $this->nullableDate($post['published_at'] ?? null),
            'scheduledAt' => $this->nullableDate($post['scheduled_at'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $files */
    public function saveGallery(array $post, array $files): array
    {
        $existing = $this->existing('gallery', $post['id'] ?? '');
        $items = [];
        $existingByAsset = [];
        foreach ((array) ($existing['items'] ?? []) as $index => $item) {
            $assetId = (string) ($item['assetId'] ?? '');
            if ($assetId === '') {
                continue;
            }
            $existingByAsset[$assetId] = [
                'assetId' => $assetId,
                'caption' => (string) ($item['caption'] ?? ''),
                'sortOrder' => (int) ($item['sortOrder'] ?? (($index + 1) * 10)),
                'isPreview' => !empty($item['isPreview']),
            ];
        }
        $requestedOrder = json_decode((string) ($post['asset_order'] ?? '[]'), true);
        if (is_array($requestedOrder)) {
            foreach ($requestedOrder as $assetId) {
                $assetId = (string) $assetId;
                if (isset($existingByAsset[$assetId])) {
                    $items[] = $existingByAsset[$assetId];
                    unset($existingByAsset[$assetId]);
                }
            }
        }
        foreach ($existingByAsset as $item) {
            $items[] = $item;
        }

        foreach ($this->normalizeMultipleFiles((array) ($files['photos'] ?? [])) as $upload) {
            $asset = $this->storeAsset($this->uploads->upload($upload, 'gallery', true), 'gallery');
            $items[] = [
                'assetId' => (string) $asset['id'],
                'caption' => '',
                'sortOrder' => (count($items) + 1) * 10,
                'isPreview' => false,
            ];
        }
        if ($items === []) {
            throw new RuntimeException('Upload at least one photo before saving the gallery.');
        }

        $previewCount = max(0, min(count($items), (int) ($post['preview_count'] ?? 0)));
        foreach ($items as $index => &$item) {
            $item['sortOrder'] = ($index + 1) * 10;
            $item['isPreview'] = $index < $previewCount;
        }
        unset($item);

        $coverId = (string) ($post['cover_asset_id'] ?? $existing['coverAssetId'] ?? $items[0]['assetId']);
        if ($this->hasUpload($files['cover'] ?? null)) {
            $cover = $this->storeAsset($this->uploads->upload((array) $files['cover'], 'poster', false), 'gallery-cover');
            $coverId = (string) $cover['id'];
        }

        return $this->store->saveGallery([
            'id' => (string) ($post['id'] ?? ''),
            'title' => (string) ($post['title'] ?? ''),
            'slug' => (string) ($post['slug'] ?? ''),
            'description' => (string) ($post['description'] ?? ''),
            'coverAssetId' => $coverId,
            'status' => (string) ($post['status'] ?? 'draft'),
            'accessMode' => (string) ($post['access_mode'] ?? 'public'),
            'standardPrice' => $post['standard_price'] ?? null,
            'subscriberPrice' => $post['subscriber_price'] ?? null,
            'rentalPrice' => $post['rental_price'] ?? null,
            'rentalHours' => (int) ($post['rental_hours'] ?? 0),
            'currency' => (string) ($post['currency'] ?? 'USD'),
            'previewCount' => $previewCount,
            'items' => $items,
            'publishedAt' => $this->nullableDate($post['published_at'] ?? null),
            'scheduledAt' => $this->nullableDate($post['scheduled_at'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $files */
    public function saveVideo(array $post, array $files): array
    {
        $existing = $this->existing('video', $post['id'] ?? '');
        $ids = [
            'poster' => (string) ($post['poster_asset_id'] ?? $existing['posterAssetId'] ?? ''),
            'trailer' => (string) ($post['trailer_asset_id'] ?? $existing['trailerAssetId'] ?? ''),
            'full' => (string) ($post['full_asset_id'] ?? $existing['fullAssetId'] ?? ''),
            'caption' => (string) ($post['caption_asset_id'] ?? $existing['captionAssetId'] ?? ''),
        ];
        if ($this->hasUpload($files['poster'] ?? null)) {
            $asset = $this->storeAsset($this->uploads->upload((array) $files['poster'], 'poster', false), 'video-poster');
            $ids['poster'] = (string) $asset['id'];
        }
        if ($this->hasUpload($files['trailer'] ?? null)) {
            $asset = $this->storeAsset($this->uploads->upload((array) $files['trailer'], 'trailer', false), 'video-trailer');
            $ids['trailer'] = (string) $asset['id'];
        }
        if ($this->hasUpload($files['full_video'] ?? null)) {
            $asset = $this->storeAsset($this->uploads->upload((array) $files['full_video'], 'video', true), 'video-full');
            $ids['full'] = (string) $asset['id'];
        }
        if ($this->hasUpload($files['caption'] ?? null)) {
            $asset = $this->storeAsset($this->uploads->upload((array) $files['caption'], 'caption', false), 'caption');
            $ids['caption'] = (string) $asset['id'];
        }
        if ($ids['poster'] === '') {
            throw new RuntimeException('Upload a poster image before saving the video.');
        }
        if ($ids['trailer'] === '' && $ids['full'] === '') {
            throw new RuntimeException('Upload a trailer or full video before saving.');
        }

        return $this->store->saveVideo([
            'id' => (string) ($post['id'] ?? ''),
            'title' => (string) ($post['title'] ?? ''),
            'slug' => (string) ($post['slug'] ?? ''),
            'description' => (string) ($post['description'] ?? ''),
            'posterAssetId' => $ids['poster'],
            'trailerAssetId' => $ids['trailer'],
            'fullAssetId' => $ids['full'],
            'captionAssetId' => $ids['caption'],
            'status' => (string) ($post['status'] ?? 'draft'),
            'accessMode' => (string) ($post['access_mode'] ?? 'public'),
            'standardPrice' => $post['standard_price'] ?? null,
            'subscriberPrice' => $post['subscriber_price'] ?? null,
            'rentalPrice' => $post['rental_price'] ?? null,
            'rentalHours' => (int) ($post['rental_hours'] ?? 0),
            'currency' => (string) ($post['currency'] ?? 'USD'),
            'publishedAt' => $this->nullableDate($post['published_at'] ?? null),
            'scheduledAt' => $this->nullableDate($post['scheduled_at'] ?? null),
        ]);
    }

    /** @return array<string,mixed> */
    private function existing(string $type, mixed $id): array
    {
        $id = (string) $id;
        if ($id === '') {
            return [];
        }
        return match ($type) {
            'album' => (array) ($this->store->album($id) ?? []),
            'track' => (array) ($this->store->track($id) ?? []),
            'gallery' => (array) ($this->store->gallery($id) ?? []),
            'video' => (array) ($this->store->video($id) ?? []),
            default => [],
        };
    }

    /** @param array<string,mixed> $asset @return array<string,mixed> */
    private function storeAsset(array $asset, string $type): array
    {
        $asset['type'] = $type;
        return $this->store->addMedia($asset);
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $time = strtotime($value);
        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    private function hasUpload(mixed $file): bool
    {
        return is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /** @param array<string,mixed> $files @return list<array<string,mixed>> */
    private function normalizeMultipleFiles(array $files): array
    {
        if (!isset($files['name']) || !is_array($files['name'])) {
            return [];
        }
        $normalized = [];
        foreach ($files['name'] as $index => $name) {
            $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $normalized[] = [
                'name' => (string) $name,
                'type' => (string) ($files['type'][$index] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$index] ?? ''),
                'error' => $error,
                'size' => (int) ($files['size'][$index] ?? 0),
            ];
        }
        return $normalized;
    }
}

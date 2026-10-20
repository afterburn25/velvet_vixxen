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
            'publishedAt' => $this->dateField($post, 'published_at', $existing, 'publishedAt'),
            'scheduledAt' => $this->dateField($post, 'scheduled_at', $existing, 'scheduledAt'),
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
            'publishedAt' => $this->dateField($post, 'published_at', $existing, 'publishedAt'),
            'scheduledAt' => $this->dateField($post, 'scheduled_at', $existing, 'scheduledAt'),
        ]);
    }

    /** @param array<string,mixed> $post */
    public function saveAudioPlaylist(array $post): array
    {
        $existing = $this->existing('playlist', $post['id'] ?? '');
        if ($existing !== []) {
            MediaInput::assertRevision($post['media_revision'] ?? null, $existing, 'playlist');
        }
        return $this->store->saveAudioPlaylist([
            'id' => MediaInput::id($post['id'] ?? ''),
            'title' => MediaInput::text($post['title'] ?? $existing['title'] ?? '', 190, 'Playlist title'),
            'slug' => MediaInput::text($post['slug'] ?? $existing['slug'] ?? '', 190, 'Playlist slug'),
            'description' => MediaInput::text($post['description'] ?? $existing['description'] ?? '', 20000, 'Playlist description', true),
            'coverAssetId' => MediaInput::id($post['cover_asset_id'] ?? $existing['coverAssetId'] ?? ''),
            'trackIds' => MediaInput::ids($post['track_ids'] ?? [], MediaInput::MAX_PLAYLIST_TRACKS, 'Playlist tracks'),
            'status' => MediaInput::status($post['status'] ?? $existing['status'] ?? 'draft'),
            'accessMode' => MediaInput::access($post['access_mode'] ?? $existing['accessMode'] ?? 'public'),
            'publishedAt' => $this->dateField($post, 'published_at', $existing, 'publishedAt'),
            'scheduledAt' => $this->dateField($post, 'scheduled_at', $existing, 'scheduledAt'),
            ...($existing === [] ? [] : ['expectedRevision' => $post['media_revision']]),
        ]);
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $files */
    public function saveGallery(array $post, array $files): array
    {
        $existing = $this->existing('gallery', $post['id'] ?? '');
        if ($existing !== []) {
            MediaInput::assertRevision($post['media_revision'] ?? null, $existing, 'gallery');
        }
        $existingByAsset = [];
        foreach ((array) ($existing['items'] ?? []) as $item) {
            $assetId = (string) ($item['assetId'] ?? '');
            if ($assetId === '') {
                continue;
            }
            $existingByAsset[$assetId] = [
                'assetId' => $assetId,
                'caption' => (string) ($item['caption'] ?? ''),
            ];
        }
        $requestedOrder = array_map('strval', array_keys($existingByAsset));
        if (array_key_exists('asset_order', $post)) {
            $rawOrder = $post['asset_order'];
            if (!is_string($rawOrder) || strlen($rawOrder) > 25000 || !str_starts_with(ltrim($rawOrder), '[')) {
                throw new RuntimeException('The gallery photo order is invalid. Reload the editor.');
            }
            try {
                $requestedOrder = json_decode($rawOrder, true, 4, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('The gallery photo order is invalid. Reload the editor.');
            }
        }
        $requestedOrder = MediaInput::ids($requestedOrder, MediaInput::MAX_GALLERY_ITEMS, 'Gallery photos');
        $captions = MediaInput::itemFields($post['asset_captions'] ?? [], 'Photo captions');
        $submittedTags = MediaInput::itemFields($post['asset_tags'] ?? [], 'Photo tags');
        foreach (array_unique([...array_keys($captions), ...array_keys($submittedTags)]) as $assetId) {
            if (!in_array((string) $assetId, $requestedOrder, true)) {
                throw new RuntimeException('Photo details must belong to a photo retained in this gallery.');
            }
        }
        $items = [];
        $tags = [];
        foreach ($requestedOrder as $assetId) {
            if (!isset($existingByAsset[$assetId])) {
                throw new RuntimeException('A selected photo does not belong to this gallery. Reload the editor.');
            }
            $item = $existingByAsset[$assetId];
            if (array_key_exists($assetId, $captions)) {
                $item['caption'] = MediaInput::caption($captions[$assetId]);
            }
            if (array_key_exists($assetId, $submittedTags)) {
                $tags[$assetId] = MediaInput::tags($submittedTags[$assetId]);
            }
            $items[] = $item;
        }

        // Validate every editable value before accepting new uploads.
        $record = [
            'id' => MediaInput::id($post['id'] ?? ''),
            'title' => MediaInput::text($post['title'] ?? $existing['title'] ?? '', 190, 'Gallery title'),
            'slug' => MediaInput::text($post['slug'] ?? $existing['slug'] ?? '', 190, 'Gallery slug'),
            'description' => MediaInput::text($post['description'] ?? $existing['description'] ?? '', 20000, 'Gallery description', true),
            'status' => MediaInput::status($post['status'] ?? $existing['status'] ?? 'draft'),
            'accessMode' => MediaInput::access($post['access_mode'] ?? $existing['accessMode'] ?? 'public'),
            'standardPrice' => MediaInput::price($post['standard_price'] ?? $existing['standardPrice'] ?? null),
            'subscriberPrice' => MediaInput::price($post['subscriber_price'] ?? $existing['subscriberPrice'] ?? null),
            'rentalPrice' => MediaInput::price($post['rental_price'] ?? $existing['rentalPrice'] ?? null),
            'rentalHours' => MediaInput::count($post['rental_hours'] ?? $existing['rentalHours'] ?? 0, 999999, 'Rental hours'),
            'currency' => MediaInput::currency($post['currency'] ?? $existing['currency'] ?? 'USD'),
            'previewCount' => MediaInput::count($post['preview_count'] ?? $existing['previewCount'] ?? 0, MediaInput::MAX_GALLERY_ITEMS, 'Preview count'),
            'publishedAt' => $this->dateField($post, 'published_at', $existing, 'publishedAt'),
            'scheduledAt' => $this->dateField($post, 'scheduled_at', $existing, 'scheduledAt'),
            ...($existing === [] ? [] : ['expectedRevision' => $post['media_revision']]),
        ];
        if ($record['title'] === '') {
            throw new RuntimeException('A title is required.');
        }
        if ($record['status'] === 'scheduled' && $record['scheduledAt'] === null) {
            throw new RuntimeException('Choose a scheduled date before scheduling this gallery.');
        }
        $coverId = MediaInput::id($post['cover_asset_id'] ?? $existing['coverAssetId'] ?? '');
        if ($coverId !== '' && $coverId !== (string) ($existing['coverAssetId'] ?? '') && !in_array($coverId, $requestedOrder, true)) {
            throw new RuntimeException('Choose a photo from this gallery or upload a custom cover.');
        }
        $photoUploads = $this->normalizeMultipleFiles((array) ($files['photos'] ?? []));
        if (count($items) + count($photoUploads) > MediaInput::MAX_GALLERY_ITEMS) {
            throw new RuntimeException('A gallery can contain at most ' . MediaInput::MAX_GALLERY_ITEMS . ' photos.');
        }
        foreach ($photoUploads as $upload) {
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

        if ($coverId === '' || (isset($existingByAsset[$coverId]) && !in_array($coverId, $requestedOrder, true))) {
            $coverId = (string) $items[0]['assetId'];
        }
        if ($this->hasUpload($files['cover'] ?? null)) {
            $cover = $this->storeAsset($this->uploads->upload((array) $files['cover'], 'poster', false), 'gallery-cover');
            $coverId = (string) $cover['id'];
        }

        return $this->store->saveGallery(array_merge($record, [
            'coverAssetId' => $coverId,
            'items' => $items,
            'assetTags' => $tags,
        ]));
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
            'publishedAt' => $this->dateField($post, 'published_at', $existing, 'publishedAt'),
            'scheduledAt' => $this->dateField($post, 'scheduled_at', $existing, 'scheduledAt'),
        ]);
    }

    /** @return array<string,mixed> */
    private function existing(string $type, mixed $id): array
    {
        $id = MediaInput::id($id);
        if ($id === '') {
            return [];
        }
        $existing = match ($type) {
            'album' => (array) ($this->store->album($id) ?? []),
            'track' => (array) ($this->store->track($id) ?? []),
            'gallery' => (array) ($this->store->gallery($id) ?? []),
            'playlist' => (array) ($this->store->audioPlaylist($id) ?? []),
            'video' => (array) ($this->store->video($id) ?? []),
            default => [],
        };
        if ($existing === [] || (string) ($existing['id'] ?? '') !== $id) {
            throw new RuntimeException('The media item could not be found. Reload the editor.');
        }
        return $existing;
    }

    /** @param array<string,mixed> $asset @return array<string,mixed> */
    private function storeAsset(array $asset, string $type): array
    {
        $asset['type'] = $type;
        return $this->store->addMedia($asset);
    }

    private function dateField(array $post, string $field, array $existing, string $stored): ?string
    {
        return array_key_exists($field, $post) ? MediaInput::date($post[$field]) : ($existing[$stored] ?? null);
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

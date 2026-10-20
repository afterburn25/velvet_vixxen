<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

trait MediaStoreTrait
{
    public function mediaAsset(string|int $id): ?array
    {
        $id = (string) $id;
        if ($id === '') {
            return null;
        }
        if ($this->mode === 'file') {
            $data = $this->readFileStore();
            $asset = $data['media'][$id] ?? null;
            return is_array($asset) && empty($asset['deletedAt']) ? $asset : null;
        }
        if (!ctype_digit($id)) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM media_assets WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->mapMediaRow($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function albums(bool $publishedOnly = false): array
    {
        if ($this->mode === 'file') {
            return $this->fileCollection('albums', $publishedOnly);
        }
        $where = $publishedOnly ? "WHERE a.status = 'published'" : '';
        $rows = $this->pdo()->query("SELECT a.*, c.file_path AS cover_file, c.thumbnail_path AS cover_thumbnail FROM audio_albums a LEFT JOIN media_assets c ON c.id = a.cover_asset_id {$where} ORDER BY COALESCE(a.published_at, a.created_at) DESC, a.id DESC")->fetchAll();
        return array_map(fn(array $row): array => $this->mapAlbumRow($row), $rows);
    }

    public function album(string|int $identifier): ?array
    {
        foreach ($this->albums(false) as $album) {
            if ((string) ($album['id'] ?? '') === (string) $identifier || (string) ($album['slug'] ?? '') === (string) $identifier) {
                return $album;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $album */
    public function saveAlbum(array $album): array
    {
        $album = $this->normalizeContent($album, 'album');
        if ($this->mode === 'file') {
            return $this->saveFileContent('albums', $album);
        }
        $id = ctype_digit((string) ($album['id'] ?? '')) ? (int) $album['id'] : 0;
        $coverId = $this->numericOrNull($album['coverAssetId'] ?? null);
        if ($id > 0) {
            $stmt = $this->pdo()->prepare('UPDATE audio_albums SET slug=?, title=?, description=?, cover_asset_id=?, status=?, published_at=?, scheduled_at=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([$album['slug'], $album['title'], $album['description'], $coverId, $album['status'], $album['publishedAt'], $album['scheduledAt'], $id]);
        } else {
            $stmt = $this->pdo()->prepare('INSERT INTO audio_albums (slug,title,description,cover_asset_id,status,published_at,scheduled_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())');
            $stmt->execute([$album['slug'], $album['title'], $album['description'], $coverId, $album['status'], $album['publishedAt'], $album['scheduledAt']]);
            $id = (int) $this->pdo()->lastInsertId();
        }
        return $this->album($id) ?? array_merge($album, ['id' => (string) $id]);
    }

    /** @return list<array<string,mixed>> */
    public function tracks(bool $publishedOnly = false): array
    {
        if ($this->mode === 'file') {
            $rows = $this->fileCollection('tracks', $publishedOnly);
            usort($rows, static fn(array $a, array $b): int => ((int) ($a['sortOrder'] ?? 100) <=> (int) ($b['sortOrder'] ?? 100)) ?: strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
            return $rows;
        }
        $where = $publishedOnly ? "WHERE t.status = 'published'" : '';
        $rows = $this->pdo()->query("SELECT t.*, a.title AS album_title, m.file_path AS audio_file, m.thumbnail_path AS audio_thumbnail, m.mime_type AS audio_mime, m.duration_seconds AS asset_duration, c.file_path AS cover_file, c.thumbnail_path AS cover_thumbnail FROM audio_tracks t LEFT JOIN audio_albums a ON a.id=t.album_id LEFT JOIN media_assets m ON m.id=t.audio_asset_id LEFT JOIN media_assets c ON c.id=t.cover_asset_id {$where} ORDER BY t.sort_order, COALESCE(t.published_at,t.created_at) DESC,t.id DESC")->fetchAll();
        return array_map(fn(array $row): array => $this->mapTrackRow($row), $rows);
    }

    public function track(string|int $identifier): ?array
    {
        foreach ($this->tracks(false) as $track) {
            if ((string) ($track['id'] ?? '') === (string) $identifier || (string) ($track['slug'] ?? '') === (string) $identifier) {
                return $track;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $track */
    public function saveTrack(array $track): array
    {
        $track = $this->normalizeContent($track, 'track');
        $track['artist'] = trim((string) ($track['artist'] ?? 'Nikki Wilder')) ?: 'Nikki Wilder';
        $track['sortOrder'] = (int) ($track['sortOrder'] ?? 100);
        $track['previewSeconds'] = max(0, (int) ($track['previewSeconds'] ?? 0));
        if ($this->mode === 'file') {
            $audio = $this->mediaAsset((string) ($track['audioAssetId'] ?? ''));
            if (!$audio) {
                throw new RuntimeException('Choose an uploaded audio file.');
            }
            $track['audioAsset'] = $audio;
            $cover = $this->mediaAsset((string) ($track['coverAssetId'] ?? ''));
            $track['coverAsset'] = $cover;
            $track['duration'] = (float) ($track['duration'] ?? $audio['duration'] ?? 0);
            return $this->saveFileContent('tracks', $track);
        }
        $id = ctype_digit((string) ($track['id'] ?? '')) ? (int) $track['id'] : 0;
        $audioId = $this->numericOrNull($track['audioAssetId'] ?? null);
        if ($audioId === null) {
            throw new RuntimeException('Choose an uploaded audio file.');
        }
        $values = [
            $this->numericOrNull($track['albumId'] ?? null), $track['slug'], $track['title'], $track['artist'], $audioId,
            $this->numericOrNull($track['coverAssetId'] ?? null), (string) ($track['lyrics'] ?? ''), $track['status'],
            $track['accessMode'], $track['previewSeconds'] ?: null, $track['sortOrder'], $track['publishedAt'], $track['scheduledAt'],
        ];
        if ($id > 0) {
            $stmt = $this->pdo()->prepare('UPDATE audio_tracks SET album_id=?,slug=?,title=?,artist=?,audio_asset_id=?,cover_asset_id=?,lyrics=?,status=?,access_mode=?,preview_seconds=?,sort_order=?,published_at=?,scheduled_at=?,updated_at=NOW() WHERE id=?');
            $stmt->execute([...$values, $id]);
        } else {
            $stmt = $this->pdo()->prepare('INSERT INTO audio_tracks (album_id,slug,title,artist,audio_asset_id,cover_asset_id,lyrics,status,access_mode,preview_seconds,sort_order,published_at,scheduled_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
            $stmt->execute($values);
            $id = (int) $this->pdo()->lastInsertId();
        }
        return $this->track($id) ?? array_merge($track, ['id' => (string) $id]);
    }

    /** @return list<array<string,mixed>> */
    public function audioPlaylists(bool $publishedOnly = false): array
    {
        if ($this->mode === 'file') {
            return $this->fileCollection('audioPlaylists', $publishedOnly);
        }
        $where = $publishedOnly ? "WHERE p.status='published'" : '';
        $rows = $this->pdo()->query("SELECT p.*, c.file_path AS cover_file, c.thumbnail_path AS cover_thumbnail FROM audio_playlists p LEFT JOIN media_assets c ON c.id=p.cover_asset_id {$where} ORDER BY COALESCE(p.published_at,p.created_at) DESC,p.id DESC")->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $playlist = [
                'id' => (string) $row['id'], 'slug' => $row['slug'], 'title' => $row['title'], 'description' => $row['description'] ?? '',
                'coverAssetId' => $row['cover_asset_id'] ? (string) $row['cover_asset_id'] : '',
                'coverAsset' => $row['cover_file'] ? ['id'=>(string)$row['cover_asset_id'],'file'=>$row['cover_file'],'thumbnail'=>$row['cover_thumbnail'] ?: $row['cover_file']] : null,
                'status' => $row['status'], 'accessMode' => $row['access_mode'], 'publishedAt' => $row['published_at'], 'scheduledAt' => $row['scheduled_at'],
            ];
            $stmt = $this->pdo()->prepare('SELECT track_id FROM audio_playlist_items WHERE playlist_id=? ORDER BY sort_order');
            $stmt->execute([(int) $row['id']]);
            $playlist['trackIds'] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $playlist['tracks'] = array_values(array_filter(array_map(fn(string $trackId): ?array => $this->track($trackId), $playlist['trackIds'])));
            $playlist['revision'] = MediaInput::revision($playlist, 'playlist');
            $result[] = $playlist;
        }
        return $result;
    }

    public function audioPlaylist(string|int $identifier): ?array
    {
        foreach ($this->audioPlaylists(false) as $playlist) {
            if ((string) $playlist['id'] === (string) $identifier || (string) $playlist['slug'] === (string) $identifier) {
                return $playlist;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $playlist */
    public function saveAudioPlaylist(array $playlist): array
    {
        $id = MediaInput::id($playlist['id'] ?? '');
        if ($this->mode === 'file') {
            return $this->mutateFileStore(function (array &$data) use ($playlist, $id): array {
                $existing = isset($data['mediaCenter']['audioPlaylists'][$id])
                    ? $this->hydrateFileMedia('audioPlaylists', $data['mediaCenter']['audioPlaylists'][$id], $data) : [];
                $this->checkMediaRevision($playlist, $existing, 'playlist');
                $saved = $this->normalizePlaylist($playlist, $existing);
                foreach ($saved['trackIds'] as $trackId) {
                    if (!is_array($data['mediaCenter']['tracks'][$trackId] ?? null)) {
                        throw new RuntimeException('A selected playlist track could not be found.');
                    }
                }
                $this->requireImageAsset($saved['coverAssetId'], (array) ($data['media'] ?? []), true);
                $saved = $this->putMediaFileRecord($data, 'audioPlaylists', $saved);
                return $this->hydrateFileMedia('audioPlaylists', $saved, $data);
            });
        }
        return $this->mediaTransaction(function (PDO $pdo) use ($playlist, $id): array {
            $existing = [];
            if ($id !== '') {
                $this->requireSqlMediaId($id);
                $query = $pdo->prepare('SELECT * FROM audio_playlists WHERE id=? FOR UPDATE');
                $query->execute([(int) $id]);
                $row = $query->fetch();
                if (!is_array($row)) {
                    throw new RuntimeException('The playlist could not be found. Reload the editor.');
                }
                $items = $pdo->prepare('SELECT track_id FROM audio_playlist_items WHERE playlist_id=? ORDER BY sort_order FOR UPDATE');
                $items->execute([(int) $id]);
                $existing = [
                    'id' => $id, 'slug' => $row['slug'], 'title' => $row['title'], 'description' => $row['description'] ?? '',
                    'coverAssetId' => $row['cover_asset_id'] ? (string) $row['cover_asset_id'] : '',
                    'status' => $row['status'], 'accessMode' => $row['access_mode'],
                    'publishedAt' => $row['published_at'], 'scheduledAt' => $row['scheduled_at'],
                    'trackIds' => array_map('strval', $items->fetchAll(PDO::FETCH_COLUMN)),
                ];
            }
            $this->checkMediaRevision($playlist, $existing, 'playlist');
            $saved = $this->normalizePlaylist($playlist, $existing);
            if ($saved['trackIds'] !== []) {
                foreach ($saved['trackIds'] as $trackId) {
                    $this->requireSqlMediaId($trackId);
                }
                $trackIds = $saved['trackIds'];
                sort($trackIds, SORT_NUMERIC);
                $query = $pdo->prepare('SELECT id FROM audio_tracks WHERE id IN (' . implode(',', array_fill(0, count($trackIds), '?')) . ') ORDER BY id FOR UPDATE');
                $query->execute($trackIds);
                $found = array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
                if (array_diff($trackIds, $found) !== []) {
                    throw new RuntimeException('A selected playlist track could not be found.');
                }
            }
            $assets = $this->lockedMediaAssets([$saved['coverAssetId']]);
            $this->requireImageAsset($saved['coverAssetId'], $assets, true);
            $values = [$saved['slug'], $saved['title'], $saved['description'], $this->numericOrNull($saved['coverAssetId']), $saved['status'], $saved['accessMode'], $this->mediaSqlDate($saved['publishedAt']), $this->mediaSqlDate($saved['scheduledAt'])];
            if ($id !== '') {
                $stmt = $pdo->prepare('UPDATE audio_playlists SET slug=?,title=?,description=?,cover_asset_id=?,status=?,access_mode=?,published_at=?,scheduled_at=?,updated_at=NOW() WHERE id=?');
                $stmt->execute([...$values,$id]);
                $pdo->prepare('DELETE FROM audio_playlist_items WHERE playlist_id=?')->execute([$id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO audio_playlists (slug,title,description,cover_asset_id,status,access_mode,published_at,scheduled_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())');
                $stmt->execute($values);
                $id = (string) $pdo->lastInsertId();
            }
            $insert = $pdo->prepare('INSERT INTO audio_playlist_items (playlist_id,track_id,sort_order,created_at) VALUES (?,?,?,NOW())');
            foreach ($saved['trackIds'] as $order => $trackId) {
                $insert->execute([$id, (int) $trackId, ($order + 1) * 10]);
            }
            return $this->audioPlaylist($id) ?? array_merge($saved, ['id' => $id]);
        });
    }

    /** @return list<array<string,mixed>> */
    public function galleries(bool $publishedOnly = false): array
    {
        if ($this->mode === 'file') {
            return $this->fileCollection('galleries', $publishedOnly);
        }
        $where = "WHERE g.deleted_at IS NULL" . ($publishedOnly ? " AND g.status='published'" : '');
        $rows = $this->pdo()->query("SELECT g.*, c.file_path AS cover_file, c.thumbnail_path AS cover_thumbnail FROM photo_galleries g LEFT JOIN media_assets c ON c.id=g.cover_asset_id {$where} ORDER BY COALESCE(g.published_at,g.created_at) DESC,g.id DESC")->fetchAll();
        return array_map(fn(array $row): array => $this->mapGalleryRow($row, true), $rows);
    }

    public function gallery(string|int $identifier): ?array
    {
        foreach ($this->galleries(false) as $gallery) {
            if ((string) ($gallery['id'] ?? '') === (string) $identifier || (string) ($gallery['slug'] ?? '') === (string) $identifier) {
                return $gallery;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $gallery */
    public function saveGallery(array $gallery): array
    {
        $id = MediaInput::id($gallery['id'] ?? '');
        if ($this->mode === 'file') {
            return $this->mutateFileStore(function (array &$data) use ($gallery, $id): array {
                $existing = isset($data['mediaCenter']['galleries'][$id])
                    ? $this->hydrateFileMedia('galleries', $data['mediaCenter']['galleries'][$id], $data) : [];
                $this->checkMediaRevision($gallery, $existing, 'gallery');
                [$saved, $tags] = $this->normalizeGallery($gallery, $existing, (array) ($data['media'] ?? []));
                foreach ($tags as $assetId => $assetTags) {
                    $data['media'][$assetId]['tags'] = $assetTags;
                }
                $saved = $this->putMediaFileRecord($data, 'galleries', $saved);
                return $this->hydrateFileMedia('galleries', $saved, $data);
            });
        }
        return $this->mediaTransaction(function (PDO $pdo) use ($gallery, $id): array {
            $row = null;
            $itemRows = [];
            if ($id !== '') {
                $this->requireSqlMediaId($id);
                $query = $pdo->prepare('SELECT * FROM photo_galleries WHERE id=? AND deleted_at IS NULL FOR UPDATE');
                $query->execute([(int) $id]);
                $row = $query->fetch();
                if (!is_array($row)) {
                    throw new RuntimeException('The gallery could not be found. Reload the editor.');
                }
                $query = $pdo->prepare('SELECT * FROM photo_gallery_items WHERE gallery_id=? ORDER BY sort_order,id FOR UPDATE');
                $query->execute([(int) $id]);
                $itemRows = $query->fetchAll();
            }
            $requestedItems = $gallery['items'] ?? [];
            if (!is_array($requestedItems) || !array_is_list($requestedItems) || count($requestedItems) > MediaInput::MAX_GALLERY_ITEMS) {
                throw new RuntimeException('Gallery photos must be a bounded ordered list.');
            }
            $assetIds = [(string) ($row['cover_asset_id'] ?? '')];
            foreach ($itemRows as $item) {
                $assetIds[] = (string) $item['asset_id'];
            }
            foreach ($requestedItems as $item) {
                if (!is_array($item)) {
                    throw new RuntimeException('A gallery photo is invalid.');
                }
                $assetIds[] = MediaInput::id($item['assetId'] ?? '', false);
            }
            $assetIds[] = MediaInput::id($gallery['coverAssetId'] ?? '');
            $assets = $this->lockedMediaAssets($assetIds);
            $existing = [];
            if (is_array($row)) {
                $cover = $assets[(string) ($row['cover_asset_id'] ?? '')] ?? [];
                $existing = $this->mapGalleryRow(array_merge($row, ['cover_file' => $cover['file'] ?? null, 'cover_thumbnail' => $cover['thumbnail'] ?? null]), false);
                foreach ($itemRows as $item) {
                    $asset = $assets[(string) $item['asset_id']] ?? null;
                    if ($asset !== null) {
                        $existing['items'][] = ['assetId' => (string) $item['asset_id'], 'caption' => $item['caption'] ?? '', 'sortOrder' => (int) $item['sort_order'], 'isPreview' => (bool) $item['is_preview'], 'asset' => $asset];
                    }
                }
            }
            $this->checkMediaRevision($gallery, $existing, 'gallery');
            [$saved, $tags] = $this->normalizeGallery($gallery, $existing, $assets);
            $values = [$saved['slug'], $saved['title'], $saved['description'], $this->numericOrNull($saved['coverAssetId']), $saved['status'], $saved['accessMode'], $saved['standardPrice'], $saved['subscriberPrice'], $saved['rentalPrice'], $saved['rentalHours'] ?: null, $saved['currency'], $saved['previewCount'], $this->mediaSqlDate($saved['publishedAt']), $this->mediaSqlDate($saved['scheduledAt'])];
            if ($id !== '') {
                $stmt=$pdo->prepare('UPDATE photo_galleries SET slug=?,title=?,description=?,cover_asset_id=?,status=?,access_mode=?,standard_price=?,subscriber_price=?,rental_price=?,rental_hours=?,currency=?,preview_count=?,published_at=?,scheduled_at=?,updated_at=NOW() WHERE id=?');
                $stmt->execute([...$values,$id]);
                $pdo->prepare('DELETE FROM photo_gallery_items WHERE gallery_id=?')->execute([$id]);
            } else {
                $stmt=$pdo->prepare('INSERT INTO photo_galleries (slug,title,description,cover_asset_id,status,access_mode,standard_price,subscriber_price,rental_price,rental_hours,currency,preview_count,published_at,scheduled_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
                $stmt->execute($values);
                $id=(string)$pdo->lastInsertId();
            }
            $insert=$pdo->prepare('INSERT INTO photo_gallery_items (gallery_id,asset_id,caption,sort_order,is_preview,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())');
            foreach ($saved['items'] as $item) {
                $insert->execute([$id, (int) $item['assetId'], $item['caption'], $item['sortOrder'], $item['isPreview'] ? 1 : 0]);
            }
            $updateTags = $pdo->prepare('UPDATE media_assets SET tags_json=?,updated_at=NOW() WHERE id=? AND deleted_at IS NULL');
            foreach ($tags as $assetId => $assetTags) {
                $updateTags->execute([json_encode($assetTags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (int) $assetId]);
            }
            return $this->gallery($id) ?? array_merge($saved, ['id' => $id]);
        });
    }

    /** @return list<array<string,mixed>> */
    public function videos(bool $publishedOnly = false): array
    {
        if ($this->mode === 'file') {
            return $this->fileCollection('videos', $publishedOnly);
        }
        $where=$publishedOnly?"WHERE v.status='published'":'';
        $rows=$this->pdo()->query("SELECT v.*, p.file_path AS poster_file,p.thumbnail_path AS poster_thumbnail,t.file_path AS trailer_file,t.mime_type AS trailer_mime,f.file_path AS full_file,f.mime_type AS full_mime,c.file_path AS caption_file,c.mime_type AS caption_mime FROM videos v LEFT JOIN media_assets p ON p.id=v.poster_asset_id LEFT JOIN media_assets t ON t.id=v.trailer_asset_id LEFT JOIN media_assets f ON f.id=v.full_video_asset_id LEFT JOIN media_assets c ON c.id=v.caption_asset_id {$where} ORDER BY COALESCE(v.published_at,v.created_at) DESC,v.id DESC")->fetchAll();
        return array_map(fn(array $row):array=>$this->mapVideoRow($row),$rows);
    }

    public function video(string|int $identifier): ?array
    {
        foreach ($this->videos(false) as $video) {
            if ((string)($video['id']??'') === (string)$identifier || (string)($video['slug']??'') === (string)$identifier) {
                return $video;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $video */
    public function saveVideo(array $video): array
    {
        $video=$this->normalizeContent($video,'video');
        $video['standardPrice']=$this->priceOrNull($video['standardPrice']??null);
        $video['subscriberPrice']=$this->priceOrNull($video['subscriberPrice']??null);
        $video['rentalPrice']=$this->priceOrNull($video['rentalPrice']??null);
        $video['rentalHours']=max(0,(int)($video['rentalHours']??0));
        $video['currency']=strtoupper(substr((string)($video['currency']??'USD'),0,3));
        if ($this->mode==='file') {
            $video['posterAsset']=$this->mediaAsset((string)($video['posterAssetId']??''));
            $video['trailerAsset']=$this->mediaAsset((string)($video['trailerAssetId']??''));
            $video['fullAsset']=$this->mediaAsset((string)($video['fullAssetId']??''));
            $video['captionAsset']=$this->mediaAsset((string)($video['captionAssetId']??''));
            $video['duration']=(float)($video['duration']??$video['fullAsset']['duration']??$video['trailerAsset']['duration']??0);
            return $this->saveFileContent('videos',$video);
        }
        $id=ctype_digit((string)($video['id']??''))?(int)$video['id']:0;
        $values=[$video['slug'],$video['title'],$video['description'],$this->numericOrNull($video['posterAssetId']??null),$this->numericOrNull($video['trailerAssetId']??null),$this->numericOrNull($video['fullAssetId']??null),$this->numericOrNull($video['captionAssetId']??null),$video['status'],$video['accessMode'],$video['standardPrice'],$video['subscriberPrice'],$video['rentalPrice'],$video['rentalHours']?:null,$video['currency'],$video['duration']??null,$video['publishedAt'],$video['scheduledAt']];
        if($id>0){$stmt=$this->pdo()->prepare('UPDATE videos SET slug=?,title=?,description=?,poster_asset_id=?,trailer_asset_id=?,full_video_asset_id=?,caption_asset_id=?,status=?,access_mode=?,standard_price=?,subscriber_price=?,rental_price=?,rental_hours=?,currency=?,duration_seconds=?,published_at=?,scheduled_at=?,updated_at=NOW() WHERE id=?');$stmt->execute([...$values,$id]);}
        else{$stmt=$this->pdo()->prepare('INSERT INTO videos (slug,title,description,poster_asset_id,trailer_asset_id,full_video_asset_id,caption_asset_id,status,access_mode,standard_price,subscriber_price,rental_price,rental_hours,currency,duration_seconds,published_at,scheduled_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');$stmt->execute($values);$id=(int)$this->pdo()->lastInsertId();}
        return $this->video($id)??array_merge($video,['id'=>(string)$id]);
    }

    /** @return array{albums:int,tracks:int,playlists:int,galleries:int,videos:int} */
    public function mediaCenterStats(): array
    {
        return [
            'albums'=>count($this->albums(false)),
            'tracks'=>count($this->tracks(false)),
            'playlists'=>count($this->audioPlaylists(false)),
            'galleries'=>count($this->galleries(false)),
            'videos'=>count($this->videos(false)),
        ];
    }

    /**
     * Publish only due, explicitly scheduled records. This is called by the
     * owner POST action or CLI, never while rendering a public page.
     * @return array{published:int,albums:int,tracks:int,playlists:int,galleries:int,videos:int}
     */
    public function publishDueMedia(int $limit = 100, ?DateTimeImmutable $now = null): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new RuntimeException('Publish between 1 and 500 media items per run.');
        }
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $collections = ['albums' => 'albums', 'tracks' => 'tracks', 'audioPlaylists' => 'playlists', 'galleries' => 'galleries', 'videos' => 'videos'];
        $empty = ['published' => 0, 'albums' => 0, 'tracks' => 0, 'playlists' => 0, 'galleries' => 0, 'videos' => 0];
        if ($this->mode === 'file') {
            return $this->mutateFileStore(function (array &$data) use ($limit, $now, $collections, $empty): array {
                $due = [];
                foreach ($collections as $collection => $type) {
                    foreach ((array) ($data['mediaCenter'][$collection] ?? []) as $id => $record) {
                        if (!is_array($record) || !empty($record['deletedAt']) || ($record['status'] ?? '') !== 'scheduled') {
                            continue;
                        }
                        $when = $this->mediaDueTimestamp($record['scheduledAt'] ?? null, $record['publishedAt'] ?? null, $now);
                        if ($when !== null) {
                            $due[] = ['collection' => $collection, 'type' => $type, 'id' => (string) $id, 'when' => $when];
                        }
                    }
                }
                usort($due, static fn(array $a, array $b): int => ($a['when'] <=> $b['when']) ?: strcmp($a['type'] . ':' . $a['id'], $b['type'] . ':' . $b['id']));
                $stats = $empty;
                foreach (array_slice($due, 0, $limit) as $candidate) {
                    $record = &$data['mediaCenter'][$candidate['collection']][$candidate['id']];
                    $record['status'] = 'published';
                    if (empty($record['publishedAt'])) {
                        $record['publishedAt'] = $record['scheduledAt'];
                    }
                    $record['updatedAt'] = $now->format(DATE_ATOM);
                    unset($record['revision']);
                    unset($record);
                    $stats[$candidate['type']]++;
                    $stats['published']++;
                }
                return $stats;
            });
        }
        return $this->mediaTransaction(function (PDO $pdo) use ($limit, $now, $empty): array {
            $tables = ['albums' => 'audio_albums', 'tracks' => 'audio_tracks', 'playlists' => 'audio_playlists', 'galleries' => 'photo_galleries', 'videos' => 'videos'];
            $nowSql = $now->format('Y-m-d H:i:s');
            $due = [];
            foreach ($tables as $type => $table) {
                $deleted = in_array($type, ['galleries', 'videos'], true) ? ' AND deleted_at IS NULL' : '';
                $query = $pdo->prepare("SELECT id,scheduled_at,published_at FROM {$table} WHERE status='scheduled' AND scheduled_at IS NOT NULL AND scheduled_at<=? AND (published_at IS NULL OR published_at<=?){$deleted} ORDER BY scheduled_at,id LIMIT " . $limit);
                $query->execute([$nowSql, $nowSql]);
                foreach ($query->fetchAll() as $row) {
                    $when = $this->mediaDueTimestamp($row['scheduled_at'], $row['published_at'], $now);
                    if ($when !== null) {
                        $due[] = ['type' => $type, 'table' => $table, 'id' => (string) $row['id'], 'when' => $when, 'scheduledAt' => $row['scheduled_at'], 'publishedAt' => $row['published_at'], 'deleted' => $deleted];
                    }
                }
            }
            usort($due, static fn(array $a, array $b): int => ($a['when'] <=> $b['when']) ?: strcmp($a['type'] . ':' . $a['id'], $b['type'] . ':' . $b['id']));
            $stats = $empty;
            foreach (array_slice($due, 0, $limit) as $candidate) {
                // Conditional update is the claim and publication in one step:
                // concurrent workers or an intervening owner edit cannot reuse it.
                $update = $pdo->prepare("UPDATE {$candidate['table']} SET status='published',published_at=COALESCE(published_at,scheduled_at),updated_at=? WHERE id=? AND status='scheduled' AND scheduled_at=? AND published_at <=> ? AND scheduled_at<=? AND (published_at IS NULL OR published_at<=?){$candidate['deleted']}");
                $update->execute([$nowSql, (int) $candidate['id'], $candidate['scheduledAt'], $candidate['publishedAt'], $nowSql, $nowSql]);
                if ($update->rowCount() === 1) {
                    $stats[$candidate['type']]++;
                    $stats['published']++;
                }
            }
            return $stats;
        });
    }

    private function mediaDueTimestamp(mixed $scheduled, mixed $published, DateTimeImmutable $now): ?int
    {
        try {
            $scheduledAt = MediaInput::storedDate($scheduled);
            $publishedAt = MediaInput::storedDate($published);
        } catch (RuntimeException) {
            // Malformed legacy dates never become permission to publish.
            return null;
        }
        return $scheduledAt !== null && $scheduledAt <= $now && ($publishedAt === null || $publishedAt <= $now)
            ? $scheduledAt->getTimestamp() : null;
    }

    private function mediaTransaction(callable $operation): mixed
    {
        $pdo = $this->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $result = $operation($pdo);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function requireSqlMediaId(string $id): void
    {
        if (!ctype_digit($id) || (int) $id < 1 || (string) (int) $id !== $id) {
            throw new RuntimeException('A selected media item could not be found.');
        }
    }

    /** Lock shared assets in a consistent order before checking gallery revisions. */
    private function lockedMediaAssets(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));
        foreach ($ids as $id) {
            $this->requireSqlMediaId($id);
        }
        if ($ids === []) {
            return [];
        }
        sort($ids, SORT_NUMERIC);
        $query = $this->pdo()->prepare('SELECT * FROM media_assets WHERE deleted_at IS NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE');
        $query->execute($ids);
        $assets = [];
        foreach ($query->fetchAll() as $row) {
            $assets[(string) $row['id']] = $this->mapMediaRow($row);
        }
        return $assets;
    }

    private function requireImageAsset(string $id, array $assets, bool $allowBlank = false): void
    {
        if ($allowBlank && $id === '') {
            return;
        }
        $asset = $assets[$id] ?? null;
        if (!is_array($asset) || !empty($asset['deletedAt']) || !str_starts_with((string) ($asset['mime'] ?? ''), 'image/')) {
            throw new RuntimeException('A selected gallery or cover image could not be found.');
        }
    }

    private function checkMediaRevision(array $input, array $existing, string $type): void
    {
        if (array_key_exists('expectedRevision', $input)) {
            if ($existing === []) {
                throw new RuntimeException('The media item could not be found. Reload the editor.');
            }
            MediaInput::assertRevision($input['expectedRevision'], $existing, $type);
        }
    }

    /** @return array<string,mixed> */
    private function normalizeMediaEdit(array $input, array $existing, string $type): array
    {
        $record = array_merge($existing, $input);
        $record['title'] = MediaInput::text($record['title'] ?? '', 190, ucfirst($type) . ' title');
        $record['slug'] = MediaInput::text($record['slug'] ?? '', 190, ucfirst($type) . ' slug');
        $record['description'] = MediaInput::text($record['description'] ?? '', 20000, ucfirst($type) . ' description', true);
        $record['status'] = MediaInput::status($record['status'] ?? 'draft');
        $record['accessMode'] = MediaInput::access($record['accessMode'] ?? 'public');
        $record['coverAssetId'] = MediaInput::id($record['coverAssetId'] ?? '');
        $record['publishedAt'] ??= null;
        $record['scheduledAt'] ??= null;
        MediaInput::storedDate($record['publishedAt']);
        MediaInput::storedDate($record['scheduledAt']);
        if ($record['status'] === 'scheduled' && empty($record['scheduledAt'])) {
            throw new RuntimeException('Choose a scheduled date before scheduling this ' . $type . '.');
        }
        $record = $this->normalizeContent($record, $type);
        unset($record['expectedRevision'], $record['revision'], $record['coverAsset'], $record['tracks']);
        return $record;
    }

    private function normalizePlaylist(array $input, array $existing): array
    {
        $playlist = $this->normalizeMediaEdit($input, $existing, 'playlist');
        $playlist['trackIds'] = MediaInput::ids($playlist['trackIds'] ?? [], MediaInput::MAX_PLAYLIST_TRACKS, 'Playlist tracks');
        return $playlist;
    }

    /** @return array{0:array<string,mixed>,1:array<string|int,list<string>>} */
    private function normalizeGallery(array $input, array $existing, array $assets): array
    {
        $gallery = $this->normalizeMediaEdit($input, $existing, 'gallery');
        $rawItems = $gallery['items'] ?? [];
        if (!is_array($rawItems) || !array_is_list($rawItems) || $rawItems === [] || count($rawItems) > MediaInput::MAX_GALLERY_ITEMS) {
            throw new RuntimeException('A gallery must contain between 1 and ' . MediaInput::MAX_GALLERY_ITEMS . ' photos.');
        }
        $gallery['standardPrice'] = MediaInput::price($gallery['standardPrice'] ?? null);
        $gallery['subscriberPrice'] = MediaInput::price($gallery['subscriberPrice'] ?? null);
        $gallery['rentalPrice'] = MediaInput::price($gallery['rentalPrice'] ?? null);
        $gallery['rentalHours'] = MediaInput::count($gallery['rentalHours'] ?? 0, 999999, 'Rental hours');
        $gallery['currency'] = MediaInput::currency($gallery['currency'] ?? 'USD');
        $gallery['previewCount'] = min(count($rawItems), MediaInput::count($gallery['previewCount'] ?? 0, MediaInput::MAX_GALLERY_ITEMS, 'Preview count'));
        $gallery['items'] = [];
        $ids = [];
        foreach ($rawItems as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException('A gallery photo is invalid.');
            }
            $assetId = MediaInput::id($item['assetId'] ?? '', false);
            if (in_array($assetId, $ids, true)) {
                throw new RuntimeException('A gallery photo is repeated.');
            }
            $this->requireImageAsset($assetId, $assets);
            $ids[] = $assetId;
            $gallery['items'][] = ['assetId' => $assetId, 'caption' => MediaInput::caption($item['caption'] ?? ''), 'sortOrder' => ($index + 1) * 10, 'isPreview' => $index < $gallery['previewCount']];
        }
        $existingIds = array_map(static fn(array $item): string => (string) ($item['assetId'] ?? ''), (array) ($existing['items'] ?? []));
        if ($gallery['coverAssetId'] === '' || (in_array($gallery['coverAssetId'], $existingIds, true) && !in_array($gallery['coverAssetId'], $ids, true))) {
            $gallery['coverAssetId'] = $ids[0];
        }
        $this->requireImageAsset($gallery['coverAssetId'], $assets);
        $tags = MediaInput::itemFields($input['assetTags'] ?? [], 'Photo tags');
        foreach ($tags as $assetId => &$assetTags) {
            if (!in_array((string) $assetId, $ids, true)) {
                throw new RuntimeException('Photo tags must belong to a retained gallery photo.');
            }
            $assetTags = MediaInput::tags($assetTags);
        }
        unset($assetTags, $gallery['assetTags']);
        return [$gallery, $tags];
    }

    private function mediaSqlDate(mixed $value): ?string
    {
        return MediaInput::storedDate($value)?->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /** Only called inside mutateFileStore; it never writes another snapshot. */
    private function putMediaFileRecord(array &$data, string $collection, array $record): array
    {
        $id = (string) ($record['id'] ?? '');
        if ($id === '') {
            $id = bin2hex(random_bytes(8));
        }
        $existing = (array) ($data['mediaCenter'][$collection][$id] ?? []);
        $record['id'] = $id;
        $record['createdAt'] = $existing['createdAt'] ?? $record['createdAt'] ?? now_iso();
        $record['updatedAt'] = now_iso();
        unset($record['revision'], $record['expectedRevision'], $record['assetTags'], $record['tracks'], $record['coverAsset']);
        $data['mediaCenter'][$collection][$id] = $record;
        return $record;
    }

    /** Hydrate current asset metadata instead of stale embedded file snapshots. */
    private function hydrateFileMedia(string $collection, array $record, array $data): array
    {
        $asset = static function (string $id) use ($data): ?array {
            $value = $data['media'][$id] ?? null;
            return is_array($value) && empty($value['deletedAt']) ? $value : null;
        };
        $record['coverAsset'] = $asset((string) ($record['coverAssetId'] ?? ''));
        if ($collection === 'galleries') {
            $record['items'] = array_values((array) ($record['items'] ?? []));
            foreach ($record['items'] as &$item) {
                $item['asset'] = $asset((string) ($item['assetId'] ?? ''));
            }
            unset($item);
            $record['revision'] = MediaInput::revision($record, 'gallery');
        } elseif ($collection === 'audioPlaylists') {
            $record['trackIds'] = array_map('strval', (array) ($record['trackIds'] ?? []));
            $record['tracks'] = [];
            foreach ($record['trackIds'] as $id) {
                $track = $data['mediaCenter']['tracks'][$id] ?? null;
                if (is_array($track)) {
                    $track['audioAsset'] = $asset((string) ($track['audioAssetId'] ?? ''));
                    $track['coverAsset'] = $asset((string) ($track['coverAssetId'] ?? ''));
                    $record['tracks'][] = $track;
                }
            }
            $record['revision'] = MediaInput::revision($record, 'playlist');
        }
        return $record;
    }

    /** @return list<array<string,mixed>> */
    private function fileCollection(string $key, bool $publishedOnly): array
    {
        $data=$this->readFileStore();
        $rows=array_values((array)($data['mediaCenter'][$key]??[]));
        $rows=array_values(array_filter($rows,static fn(array $row):bool=>empty($row['deletedAt'])));
        if (in_array($key, ['galleries', 'audioPlaylists'], true)) {
            $rows = array_map(fn(array $row): array => $this->hydrateFileMedia($key, $row, $data), $rows);
        }
        if($publishedOnly){$rows=array_values(array_filter($rows,static fn(array $row):bool=>(string)($row['status']??'draft')==='published'));}
        usort($rows,static fn(array $a,array $b):int=>strcmp((string)($b['publishedAt']??$b['createdAt']??''),(string)($a['publishedAt']??$a['createdAt']??'')));
        return $rows;
    }

    /** @param array<string,mixed> $item */
    private function saveFileContent(string $collection,array $item): array
    {
        $data=$this->readFileStore();
        $id=(string)($item['id']??'');
        if($id===''){$id=bin2hex(random_bytes(8));}
        $existing=(array)($data['mediaCenter'][$collection][$id]??[]);
        $createdAt=(string)($existing['createdAt']??$item['createdAt']??now_iso());
        $item=array_merge($existing,$item,['id'=>$id,'createdAt'=>$createdAt,'updatedAt'=>now_iso()]);
        if(($item['status']??'')==='published'&&empty($item['publishedAt'])){$item['publishedAt']=now_iso();}
        $data['mediaCenter'][$collection][$id]=$item;
        $this->writeFileStore($data);
        return $item;
    }

    /** @param array<string,mixed> $content */
    private function normalizeContent(array $content,string $fallbackTitle): array
    {
        $title=trim((string)($content['title']??''));
        if($title===''){throw new RuntimeException('A title is required.');}
        $status=strtolower(trim((string)($content['status']??'draft')));
        if(!in_array($status,['draft','processing','scheduled','published','archived','unlisted','failed'],true)){$status='draft';}
        $slug=trim((string)($content['slug']??''));
        $slug=$slug!==''?slugify($slug):slugify($title);
        return array_merge([
            'id'=>'','slug'=>$slug,'title'=>$title,'description'=>'','status'=>$status,'accessMode'=>MediaAccess::normalize((string)($content['accessMode']??'public')),
            'scheduledAt'=>null,'publishedAt'=>null,'createdAt'=>now_iso(),'updatedAt'=>now_iso(),
        ],$content,['slug'=>$slug,'title'=>$title,'status'=>$status,'accessMode'=>MediaAccess::normalize((string)($content['accessMode']??'public'))]);
    }

    private function numericOrNull(mixed $value): ?int
    {
        $value=(string)$value;
        return ctype_digit($value)&&((int)$value)>0?(int)$value:null;
    }

    private function priceOrNull(mixed $value): ?float
    {
        if($value===''||$value===null){return null;}
        return max(0,round((float)$value,2));
    }

    /** @param array<string,mixed> $row */
    private function mapMediaRow(array $row): array
    {
        return [
            'id'=>(string)$row['id'],'title'=>$row['title'],'type'=>$row['asset_type'],'file'=>$row['file_path'],'thumbnail'=>$row['thumbnail_path']?:$row['file_path'],
            'mime'=>$row['mime_type']??'','size'=>(int)($row['file_size']??0),'width'=>(int)($row['width']??0),'height'=>(int)($row['height']??0),'duration'=>$row['duration_seconds']!==null?(float)$row['duration_seconds']:null,
            'tags'=>safe_json_decode((string)($row['tags_json']??'[]')),'metadata'=>safe_json_decode((string)($row['metadata_json']??'{}')),'private'=>str_starts_with((string)$row['file_path'],'private:'),'createdAt'=>$row['created_at'],
        ];
    }

    /** @param array<string,mixed> $row */
    private function mapAlbumRow(array $row): array
    {
        return ['id'=>(string)$row['id'],'slug'=>$row['slug'],'title'=>$row['title'],'description'=>$row['description']??'','coverAssetId'=>$row['cover_asset_id']?(string)$row['cover_asset_id']:'','coverAsset'=>$row['cover_file']?['id'=>(string)$row['cover_asset_id'],'file'=>$row['cover_file'],'thumbnail'=>$row['cover_thumbnail']?:$row['cover_file']]:null,'status'=>$row['status'],'accessMode'=>'public','publishedAt'=>$row['published_at'],'scheduledAt'=>$row['scheduled_at'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']];
    }

    /** @param array<string,mixed> $row */
    private function mapTrackRow(array $row): array
    {
        return ['id'=>(string)$row['id'],'albumId'=>$row['album_id']?(string)$row['album_id']:'','albumTitle'=>$row['album_title']??'','slug'=>$row['slug'],'title'=>$row['title'],'artist'=>$row['artist'],'audioAssetId'=>(string)$row['audio_asset_id'],'audioAsset'=>['id'=>(string)$row['audio_asset_id'],'file'=>$row['audio_file'],'thumbnail'=>$row['audio_thumbnail']?:$row['audio_file'],'mime'=>$row['audio_mime']??'','duration'=>$row['asset_duration']!==null?(float)$row['asset_duration']:null],'coverAssetId'=>$row['cover_asset_id']?(string)$row['cover_asset_id']:'','coverAsset'=>$row['cover_file']?['id'=>(string)$row['cover_asset_id'],'file'=>$row['cover_file'],'thumbnail'=>$row['cover_thumbnail']?:$row['cover_file']]:null,'lyrics'=>$row['lyrics']??'','status'=>$row['status'],'accessMode'=>$row['access_mode'],'previewSeconds'=>(int)($row['preview_seconds']??0),'sortOrder'=>(int)$row['sort_order'],'duration'=>$row['asset_duration']!==null?(float)$row['asset_duration']:0,'publishedAt'=>$row['published_at'],'scheduledAt'=>$row['scheduled_at'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']];
    }

    /** @param array<string,mixed> $row */
    private function mapGalleryRow(array $row,bool $withItems): array
    {
        $gallery=['id'=>(string)$row['id'],'slug'=>$row['slug'],'title'=>$row['title'],'description'=>$row['description']??'','coverAssetId'=>$row['cover_asset_id']?(string)$row['cover_asset_id']:'','coverAsset'=>$row['cover_file']?['id'=>(string)$row['cover_asset_id'],'file'=>$row['cover_file'],'thumbnail'=>$row['cover_thumbnail']?:$row['cover_file']]:null,'status'=>$row['status'],'accessMode'=>$row['access_mode'],'standardPrice'=>$row['standard_price']!==null?(float)$row['standard_price']:null,'subscriberPrice'=>$row['subscriber_price']!==null?(float)$row['subscriber_price']:null,'rentalPrice'=>$row['rental_price']!==null?(float)$row['rental_price']:null,'rentalHours'=>(int)($row['rental_hours']??0),'currency'=>$row['currency'],'previewCount'=>(int)($row['preview_count']??0),'publishedAt'=>$row['published_at'],'scheduledAt'=>$row['scheduled_at'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at'],'items'=>[]];
        if ($withItems) {
            $stmt = $this->pdo()->prepare('SELECT i.*,m.title AS asset_title,m.file_path,m.thumbnail_path,m.mime_type,m.width,m.height,m.tags_json,m.metadata_json FROM photo_gallery_items i JOIN media_assets m ON m.id=i.asset_id WHERE i.gallery_id=? AND m.deleted_at IS NULL ORDER BY i.sort_order,i.id');
            $stmt->execute([(int) $row['id']]);
            foreach ($stmt->fetchAll() as $item) {
                $gallery['items'][] = [
                    'assetId' => (string) $item['asset_id'], 'caption' => $item['caption'] ?? '',
                    'sortOrder' => (int) $item['sort_order'], 'isPreview' => (bool) $item['is_preview'],
                    'asset' => [
                        'id' => (string) $item['asset_id'], 'title' => $item['asset_title'],
                        'file' => $item['file_path'], 'thumbnail' => $item['thumbnail_path'] ?: $item['file_path'],
                        'mime' => $item['mime_type'], 'width' => (int) $item['width'], 'height' => (int) $item['height'],
                        'tags' => safe_json_decode((string) ($item['tags_json'] ?? '[]')),
                        'metadata' => safe_json_decode((string) ($item['metadata_json'] ?? '{}')),
                        'private' => str_starts_with((string) $item['file_path'], 'private:'),
                    ],
                ];
            }
            $gallery['revision'] = MediaInput::revision($gallery, 'gallery');
        }
        return $gallery;
    }

    /** @param array<string,mixed> $row */
    private function mapVideoRow(array $row): array
    {
        return [
            'id'=>(string)$row['id'],
            'slug'=>$row['slug'],
            'title'=>$row['title'],
            'description'=>$row['description']??'',
            'posterAssetId'=>$row['poster_asset_id']?(string)$row['poster_asset_id']:'',
            'posterAsset'=>$row['poster_file']?['id'=>(string)$row['poster_asset_id'],'file'=>$row['poster_file'],'thumbnail'=>$row['poster_thumbnail']?:$row['poster_file']]:null,
            'trailerAssetId'=>$row['trailer_asset_id']?(string)$row['trailer_asset_id']:'',
            'trailerAsset'=>$row['trailer_file']?['id'=>(string)$row['trailer_asset_id'],'file'=>$row['trailer_file'],'mime'=>$row['trailer_mime']]:null,
            'fullAssetId'=>$row['full_video_asset_id']?(string)$row['full_video_asset_id']:'',
            'fullAsset'=>$row['full_file']?['id'=>(string)$row['full_video_asset_id'],'file'=>$row['full_file'],'mime'=>$row['full_mime']]:null,
            'captionAssetId'=>!empty($row['caption_asset_id'])?(string)$row['caption_asset_id']:'',
            'captionAsset'=>!empty($row['caption_file'])?['id'=>(string)$row['caption_asset_id'],'file'=>$row['caption_file'],'mime'=>$row['caption_mime']]:null,
            'status'=>$row['status'],
            'accessMode'=>$row['access_mode'],
            'standardPrice'=>$row['standard_price']!==null?(float)$row['standard_price']:null,
            'subscriberPrice'=>$row['subscriber_price']!==null?(float)$row['subscriber_price']:null,
            'rentalPrice'=>$row['rental_price']!==null?(float)$row['rental_price']:null,
            'rentalHours'=>(int)($row['rental_hours']??0),
            'currency'=>$row['currency'],
            'duration'=>$row['duration_seconds']!==null?(float)$row['duration_seconds']:0,
            'publishedAt'=>$row['published_at'],
            'scheduledAt'=>$row['scheduled_at'],
            'createdAt'=>$row['created_at'],
            'updatedAt'=>$row['updated_at'],
        ];
    }
}

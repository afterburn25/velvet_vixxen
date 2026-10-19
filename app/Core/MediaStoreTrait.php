<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
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
            return is_array($asset) ? $asset : null;
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
            $result[] = $playlist;
        }
        return $result;
    }

    /** @param array<string,mixed> $playlist */
    public function saveAudioPlaylist(array $playlist): array
    {
        $playlist = $this->normalizeContent($playlist, 'playlist');
        $playlist['trackIds'] = array_values(array_unique(array_filter(array_map('strval', (array) ($playlist['trackIds'] ?? [])))));
        if ($this->mode === 'file') {
            $playlist['tracks'] = array_values(array_filter(array_map(fn(string $id): ?array => $this->track($id), $playlist['trackIds'])));
            return $this->saveFileContent('audioPlaylists', $playlist);
        }
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $id = ctype_digit((string) ($playlist['id'] ?? '')) ? (int) $playlist['id'] : 0;
            $values = [$playlist['slug'],$playlist['title'],$playlist['description'],$this->numericOrNull($playlist['coverAssetId'] ?? null),$playlist['status'],$playlist['accessMode'],$playlist['publishedAt'],$playlist['scheduledAt']];
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE audio_playlists SET slug=?,title=?,description=?,cover_asset_id=?,status=?,access_mode=?,published_at=?,scheduled_at=?,updated_at=NOW() WHERE id=?');
                $stmt->execute([...$values,$id]);
                $pdo->prepare('DELETE FROM audio_playlist_items WHERE playlist_id=?')->execute([$id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO audio_playlists (slug,title,description,cover_asset_id,status,access_mode,published_at,scheduled_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())');
                $stmt->execute($values);
                $id = (int) $pdo->lastInsertId();
            }
            $insert = $pdo->prepare('INSERT INTO audio_playlist_items (playlist_id,track_id,sort_order,created_at) VALUES (?,?,?,NOW())');
            foreach ($playlist['trackIds'] as $order => $trackId) {
                if (ctype_digit($trackId)) {
                    $insert->execute([$id,(int)$trackId,($order+1)*10]);
                }
            }
            $pdo->commit();
            foreach ($this->audioPlaylists(false) as $saved) {
                if ((string)$saved['id'] === (string)$id) {
                    return $saved;
                }
            }
            return array_merge($playlist,['id'=>(string)$id]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function galleries(bool $publishedOnly = false): array
    {
        if ($this->mode === 'file') {
            return $this->fileCollection('galleries', $publishedOnly);
        }
        $where = $publishedOnly ? "WHERE g.status='published'" : '';
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
        $gallery = $this->normalizeContent($gallery, 'gallery');
        $gallery['standardPrice'] = $this->priceOrNull($gallery['standardPrice'] ?? null);
        $gallery['subscriberPrice'] = $this->priceOrNull($gallery['subscriberPrice'] ?? null);
        $gallery['rentalPrice'] = $this->priceOrNull($gallery['rentalPrice'] ?? null);
        $gallery['rentalHours'] = max(0, (int) ($gallery['rentalHours'] ?? 0));
        $gallery['currency'] = strtoupper(substr((string) ($gallery['currency'] ?? 'USD'), 0, 3));
        $gallery['items'] = array_values((array) ($gallery['items'] ?? []));
        $gallery['previewCount'] = max(0, min(count($gallery['items']), (int) ($gallery['previewCount'] ?? 0)));
        if ($this->mode === 'file') {
            foreach ($gallery['items'] as $index => &$item) {
                $assetId = (string) ($item['assetId'] ?? '');
                $asset = $this->mediaAsset($assetId);
                if (!$asset) {
                    throw new RuntimeException('A gallery image could not be found.');
                }
                $item = array_merge(['assetId'=>$assetId,'caption'=>'','sortOrder'=>($index+1)*10,'isPreview'=>false],$item,['asset'=>$asset]);
            }
            unset($item);
            if (empty($gallery['coverAssetId']) && isset($gallery['items'][0]['assetId'])) {
                $gallery['coverAssetId'] = $gallery['items'][0]['assetId'];
            }
            $gallery['coverAsset'] = $this->mediaAsset((string) ($gallery['coverAssetId'] ?? ''));
            return $this->saveFileContent('galleries', $gallery);
        }
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $id = ctype_digit((string) ($gallery['id'] ?? '')) ? (int) $gallery['id'] : 0;
            $values = [$gallery['slug'],$gallery['title'],$gallery['description'],$this->numericOrNull($gallery['coverAssetId'] ?? null),$gallery['status'],$gallery['accessMode'],$gallery['standardPrice'],$gallery['subscriberPrice'],$gallery['rentalPrice'],$gallery['rentalHours'] ?: null,$gallery['currency'],$gallery['previewCount'],$gallery['publishedAt'],$gallery['scheduledAt']];
            if ($id > 0) {
                $stmt=$pdo->prepare('UPDATE photo_galleries SET slug=?,title=?,description=?,cover_asset_id=?,status=?,access_mode=?,standard_price=?,subscriber_price=?,rental_price=?,rental_hours=?,currency=?,preview_count=?,published_at=?,scheduled_at=?,updated_at=NOW() WHERE id=?');
                $stmt->execute([...$values,$id]);
                $pdo->prepare('DELETE FROM photo_gallery_items WHERE gallery_id=?')->execute([$id]);
            } else {
                $stmt=$pdo->prepare('INSERT INTO photo_galleries (slug,title,description,cover_asset_id,status,access_mode,standard_price,subscriber_price,rental_price,rental_hours,currency,preview_count,published_at,scheduled_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
                $stmt->execute($values);
                $id=(int)$pdo->lastInsertId();
            }
            $insert=$pdo->prepare('INSERT INTO photo_gallery_items (gallery_id,asset_id,caption,sort_order,is_preview,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())');
            foreach ($gallery['items'] as $index=>$item) {
                $assetId=$this->numericOrNull($item['assetId']??null);
                if ($assetId !== null) {
                    $insert->execute([$id,$assetId,(string)($item['caption']??''),(int)($item['sortOrder']??(($index+1)*10)),!empty($item['isPreview'])?1:0]);
                }
            }
            $pdo->commit();
            return $this->gallery($id) ?? array_merge($gallery,['id'=>(string)$id]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
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

    /** @return list<array<string,mixed>> */
    private function fileCollection(string $key, bool $publishedOnly): array
    {
        $data=$this->readFileStore();
        $rows=array_values((array)($data['mediaCenter'][$key]??[]));
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
        if($withItems){$stmt=$this->pdo()->prepare('SELECT i.*,m.title AS asset_title,m.file_path,m.thumbnail_path,m.mime_type,m.width,m.height FROM photo_gallery_items i JOIN media_assets m ON m.id=i.asset_id WHERE i.gallery_id=? ORDER BY i.sort_order,i.id');$stmt->execute([(int)$row['id']]);foreach($stmt->fetchAll() as $item){$gallery['items'][]=['assetId'=>(string)$item['asset_id'],'caption'=>$item['caption']??'','sortOrder'=>(int)$item['sort_order'],'isPreview'=>(bool)$item['is_preview'],'asset'=>['id'=>(string)$item['asset_id'],'title'=>$item['asset_title'],'file'=>$item['file_path'],'thumbnail'=>$item['thumbnail_path']?:$item['file_path'],'mime'=>$item['mime_type'],'width'=>(int)$item['width'],'height'=>(int)$item['height']]];}}
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

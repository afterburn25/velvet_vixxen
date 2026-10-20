<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('ROOT_PATH', $root);
require $root . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Store;

$token = bin2hex(random_bytes(5));
$configPath = $root . '/storage/temp/test-media-' . $token . '.php';
$dataRelative = 'storage/temp/test-media-' . $token . '.json';
$dataPath = $root . '/' . $dataRelative;
@mkdir(dirname($configPath), 0775, true);
file_put_contents($configPath, "<?php\ndeclare(strict_types=1);\nreturn " . var_export([
    'storage_mode' => 'file',
    'file_store_path' => $dataRelative,
], true) . ";\n");
file_put_contents($dataPath, json_encode([
    'settings'=>[], 'admins'=>[], 'media'=>[], 'templates'=>[], 'scenes'=>[],
    'mediaCenter'=>['albums'=>[], 'tracks'=>[], 'audioPlaylists'=>[], 'galleries'=>[], 'videos'=>[]], 'audit'=>[],
], JSON_PRETTY_PRINT));

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };

try {
    $store = new Store(new Config($configPath));
    $cover = $store->addMedia(['id'=>'cover','title'=>'Cover','type'=>'poster','file'=>'images/demo/album-cover.jpg','thumbnail'=>'images/demo/album-cover.jpg','mime'=>'image/jpeg']);
    $audio = $store->addMedia(['id'=>'audio','title'=>'Audio','type'=>'audio','file'=>'private:audio/demo/song.mp3','mime'=>'audio/mpeg','duration'=>95.5,'private'=>true]);
    $photo1 = $store->addMedia(['id'=>'photo1','title'=>'Photo 1','type'=>'gallery','file'=>'private:images/demo/1.jpg','thumbnail'=>'thumbnails/demo/1.jpg','mime'=>'image/jpeg','private'=>true]);
    $photo2 = $store->addMedia(['id'=>'photo2','title'=>'Photo 2','type'=>'gallery','file'=>'private:images/demo/2.jpg','thumbnail'=>'thumbnails/demo/2.jpg','mime'=>'image/jpeg','private'=>true]);
    $trailer = $store->addMedia(['id'=>'trailer','title'=>'Trailer','type'=>'video-trailer','file'=>'videos/demo/trailer.mp4','mime'=>'video/mp4','duration'=>15]);
    $full = $store->addMedia(['id'=>'full','title'=>'Full Video','type'=>'video-full','file'=>'private:videos/demo/full.mp4','mime'=>'video/mp4','duration'=>120,'private'=>true]);
    $caption = $store->addMedia(['id'=>'caption','title'=>'Captions','type'=>'caption','file'=>'captions/demo/captions.vtt','mime'=>'text/vtt']);

    $album = $store->saveAlbum(['title'=>'Midnight Pink','coverAssetId'=>$cover['id'],'status'=>'published']);
    $track = $store->saveTrack(['title'=>'Velvet After Dark','artist'=>'Nikki Wilder','albumId'=>$album['id'],'audioAssetId'=>$audio['id'],'coverAssetId'=>$cover['id'],'status'=>'published','accessMode'=>'subscription','previewSeconds'=>30]);
    $playlist = $store->saveAudioPlaylist(['title'=>'After Dark','trackIds'=>[$track['id']],'status'=>'published','accessMode'=>'public']);
    $gallery = $store->saveGallery(['title'=>'Pink Paradise','coverAssetId'=>$photo1['id'],'status'=>'published','accessMode'=>'ppv','standardPrice'=>12.99,'previewCount'=>1,'items'=>[
        ['assetId'=>$photo1['id'],'isPreview'=>true], ['assetId'=>$photo2['id'],'isPreview'=>false],
    ]]);
    $video = $store->saveVideo(['title'=>'Velvet Feature','posterAssetId'=>$cover['id'],'trailerAssetId'=>$trailer['id'],'fullAssetId'=>$full['id'],'captionAssetId'=>$caption['id'],'status'=>'published','accessMode'=>'ppv','standardPrice'=>19.99]);

    $assert((string)($store->album($album['id'])['title']??'') === 'Midnight Pink', 'Album was not persisted.');
    $assert((string)($store->track($track['id'])['audioAsset']['id']??'') === 'audio', 'Track audio asset was not hydrated.');
    $assert(count((array)($playlist['trackIds']??[])) === 1, 'Playlist track order was not persisted.');
    $assert((int)($gallery['previewCount']??-1) === 1, 'Gallery preview count was not persisted.');
    $assert(count((array)($gallery['items']??[])) === 2, 'Gallery items were not persisted.');
    $assert((string)($video['captionAsset']['id']??'') === 'caption', 'Video caption asset was not persisted.');
    $stats = $store->mediaCenterStats();
    $assert($stats === ['albums'=>1,'tracks'=>1,'playlists'=>1,'galleries'=>1,'videos'=>1], 'Media Center stats are incorrect.');
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
} finally {
    @unlink($configPath);
    @unlink($dataPath);
    @unlink($dataPath . '.lock');
}

if ($failures !== []) {
    fwrite(STDERR, "Media store test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Media store test passed.\n";

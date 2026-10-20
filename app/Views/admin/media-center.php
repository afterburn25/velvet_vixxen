<?php
declare(strict_types=1);

use App\Core\MediaAccess;

/** @var App\Core\Store $store */
/** @var string $page */

$statusOptions = [
    'draft' => 'Draft',
    'scheduled' => 'Scheduled',
    'published' => 'Published',
    'unlisted' => 'Unlisted',
    'archived' => 'Archived',
];
$accessOptions = MediaAccess::options();
$media = $store->media();
$images = array_values(array_filter($media, static fn(array $asset): bool => str_starts_with((string) ($asset['mime'] ?? ''), 'image/') || in_array((string) ($asset['type'] ?? ''), ['poster','gallery-cover','track-cover','album-cover','background','model'], true)));

$selected = static function (mixed $actual, mixed $expected): string {
    return (string) $actual === (string) $expected ? ' selected' : '';
};
$checked = static function (bool $value): string { return $value ? ' checked' : ''; };
$dateValue = static function (mixed $value): string {
    if (!$value) return '';
    $time = strtotime((string) $value);
    return $time === false ? '' : date('Y-m-d\TH:i', $time);
};

$mediaValue = static function (string $action, ?array $record, string $postKey, string $recordKey, mixed $default = ''): string {
    $value = $record[$recordKey] ?? $default;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === $action && array_key_exists($postKey, $_POST)) {
        $value = $_POST[$postKey];
    }
    return is_scalar($value) ? (string) $value : '';
};
$scheduleTimezone = date_default_timezone_get();
?>
<section class="admin-card media-schedule-card" aria-labelledby="media-schedule-heading">
  <div><span class="admin-kicker">Publishing schedule</span><h2 id="media-schedule-heading">Publish when you're ready</h2><p>Dates use <strong><?= e($scheduleTimezone) ?></strong>. Scheduled items stay hidden until their date is due and a publishing run completes.</p></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="run_due_media"><input type="hidden" name="return_page" value="<?= e($page) ?>"><button class="admin-button secondary">Run Due Media Now</button></form>
  <details><summary>Set up automatic publishing</summary><p>In your hosting control panel, schedule this command to run every minute from the website folder:</p><code>php app/Console/run_media.php --limit=100</code><p>It publishes due music, playlists, galleries and videos. Each run leaves future, draft, unlisted and archived items unchanged.</p></details>
</section>
<?php
if ($page === 'music'):
    $albums = $store->albums(false);
    $tracks = $store->tracks(false);
    $playlists = $store->audioPlaylists(false);
    $editAlbum = isset($_GET['edit_album']) ? $store->album((string) $_GET['edit_album']) : null;
    $editTrack = isset($_GET['edit_track']) ? $store->track((string) $_GET['edit_track']) : null;
    $editPlaylist = null;
    if (isset($_GET['edit_playlist'])) {
        foreach ($playlists as $candidate) {
            if ((string) ($candidate['id'] ?? '') === (string) $_GET['edit_playlist']) { $editPlaylist = $candidate; break; }
        }
    }
?>
<section class="media-admin-hero">
  <div><span class="admin-kicker"><?= e(VELVET_VIXXEN_VERSION) ?> Media Center</span><h2>Music library &amp; persistent player</h2><p>Upload original songs, organize albums and playlists, set access rules, and publish directly to the site-wide audio player.</p></div>
  <div class="media-admin-stats"><article><strong><?= e((string) count($tracks)) ?></strong><span>Tracks</span></article><article><strong><?= e((string) count($albums)) ?></strong><span>Albums</span></article><article><strong><?= e((string) count($playlists)) ?></strong><span>Playlists</span></article></div>
</section>

<div class="media-admin-tabs" data-admin-tabs>
  <button class="active" type="button" data-tab-target="tracks">Tracks</button>
  <button type="button" data-tab-target="albums">Albums</button>
  <button type="button" data-tab-target="playlists">Playlists</button>
</div>

<section class="media-admin-panel active" data-tab-panel="tracks">
  <div class="media-admin-layout">
    <form method="post" enctype="multipart/form-data" class="admin-card media-editor-card">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_track"><input type="hidden" name="id" value="<?= e((string) ($editTrack['id'] ?? '')) ?>"><input type="hidden" name="audio_asset_id" value="<?= e((string) ($editTrack['audioAssetId'] ?? '')) ?>"><input type="hidden" name="cover_asset_id" value="<?= e((string) ($editTrack['coverAssetId'] ?? '')) ?>">
      <div class="card-head"><div><span class="admin-kicker"><?= $editTrack ? 'Edit track' : 'New track' ?></span><h2><?= e((string) ($editTrack['title'] ?? 'Upload a song')) ?></h2></div><?php if ($editTrack): ?><a class="mini-button" href="<?= e(base_url('admin/?page=music')) ?>">New</a><?php endif; ?></div>
      <div class="form-grid two"><label>Track title<input name="title" value="<?= e((string) ($editTrack['title'] ?? '')) ?>" required></label><label>Artist<input name="artist" value="<?= e((string) ($editTrack['artist'] ?? 'Nikki Wilder')) ?>" required></label><label>Slug <small>optional</small><input name="slug" value="<?= e((string) ($editTrack['slug'] ?? '')) ?>"></label><label>Album<select name="album_id"><option value="">Single / no album</option><?php foreach ($albums as $album): ?><option value="<?= e((string) $album['id']) ?>"<?= $selected($editTrack['albumId'] ?? '', $album['id']) ?>><?= e((string) $album['title']) ?></option><?php endforeach; ?></select></label></div>
      <div class="form-grid two"><label>Audio file <?= $editTrack ? '<small>leave empty to keep current</small>' : '' ?><input type="file" name="audio" accept="audio/mpeg,audio/mp4,audio/aac,audio/wav,audio/flac" <?= $editTrack ? '' : 'required' ?>></label><label>Cover art <small>optional</small><input type="file" name="cover" accept="image/jpeg,image/png,image/webp"></label></div>
      <div class="form-grid four"><label>Status<select name="status"><?php foreach ($statusOptions as $value=>$label): ?><option value="<?= e($value) ?>"<?= $selected($editTrack['status'] ?? 'draft', $value) ?>><?= e($label) ?></option><?php endforeach; ?></select></label><label>Access<select name="access_mode"><?php foreach ($accessOptions as $value=>$label): ?><option value="<?= e($value) ?>"<?= $selected($editTrack['accessMode'] ?? 'public', $value) ?>><?= e($label) ?></option><?php endforeach; ?></select></label><label>Preview seconds<input type="number" name="preview_seconds" min="0" max="600" value="<?= e((string) ($editTrack['previewSeconds'] ?? 0)) ?>"></label><label>Sort order<input type="number" name="sort_order" value="<?= e((string) ($editTrack['sortOrder'] ?? 100)) ?>"></label></div>
      <div class="form-grid two"><label>Publish date<input type="datetime-local" name="published_at" value="<?= e($dateValue($editTrack['publishedAt'] ?? null)) ?>"></label><label>Scheduled date<input type="datetime-local" name="scheduled_at" value="<?= e($dateValue($editTrack['scheduledAt'] ?? null)) ?>"></label></div>
      <label>Lyrics <small>optional</small><textarea name="lyrics" rows="6"><?= e((string) ($editTrack['lyrics'] ?? '')) ?></textarea></label>
      <button class="admin-button primary full">Save Track</button>
    </form>
    <div class="media-record-list">
      <?php foreach ($tracks as $track): $cover=(array)($track['coverAsset']??[]); ?><article class="media-record-card"><img src="<?= e(media_thumbnail_src($cover)) ?>" alt=""><div><div class="record-badges"><span><?= e((string) $track['status']) ?></span><span><?= e(MediaAccess::label((string) $track['accessMode'])) ?></span></div><h3><?= e((string) $track['title']) ?></h3><p><?= e((string) ($track['artist'] ?? 'Nikki Wilder')) ?><?= !empty($track['albumTitle']) ? ' · '.e((string)$track['albumTitle']) : '' ?></p><small><?= e(media_time((float)($track['duration']??0))) ?><?= (int)($track['previewSeconds']??0)>0?' · '.e((string)$track['previewSeconds']).' sec preview':'' ?></small></div><a class="mini-button" href="<?= e(base_url('admin/?page=music&edit_track='.rawurlencode((string)$track['id']))) ?>">Edit</a></article><?php endforeach; ?>
      <?php if ($tracks===[]): ?><div class="admin-empty"><strong>No tracks yet.</strong><span>Upload the first song using the form.</span></div><?php endif; ?>
    </div>
  </div>
</section>

<section class="media-admin-panel" data-tab-panel="albums">
  <div class="media-admin-layout">
    <form method="post" enctype="multipart/form-data" class="admin-card media-editor-card"><?= csrf_field() ?><input type="hidden" name="action" value="save_album"><input type="hidden" name="id" value="<?= e((string) ($editAlbum['id'] ?? '')) ?>"><input type="hidden" name="cover_asset_id" value="<?= e((string) ($editAlbum['coverAssetId'] ?? '')) ?>"><div class="card-head"><div><span class="admin-kicker"><?= $editAlbum?'Edit album':'New album' ?></span><h2><?= e((string)($editAlbum['title']??'Create an album')) ?></h2></div></div><label>Album title<input name="title" value="<?= e((string)($editAlbum['title']??'')) ?>" required></label><label>Slug <small>optional</small><input name="slug" value="<?= e((string)($editAlbum['slug']??'')) ?>"></label><label>Description<textarea name="description" rows="5"><?= e((string)($editAlbum['description']??'')) ?></textarea></label><label>Cover art<input type="file" name="cover" accept="image/jpeg,image/png,image/webp"></label><div class="form-grid two"><label>Status<select name="status"><?php foreach($statusOptions as $value=>$label):?><option value="<?=e($value)?>"<?=$selected($editAlbum['status']??'draft',$value)?>><?=e($label)?></option><?php endforeach;?></select></label><label>Scheduled date<input type="datetime-local" name="scheduled_at" value="<?=e($dateValue($editAlbum['scheduledAt']??null))?>"></label></div><button class="admin-button primary full">Save Album</button></form>
    <div class="media-record-list"><?php foreach($albums as $album):?><article class="media-record-card"><img src="<?=e(media_thumbnail_src((array)($album['coverAsset']??[])))?>" alt=""><div><div class="record-badges"><span><?=e((string)$album['status'])?></span></div><h3><?=e((string)$album['title'])?></h3><p><?=e((string)$album['description'])?></p><small><?=e((string)count(array_filter($tracks,static fn(array $track):bool=>(string)($track['albumId']??'')===(string)$album['id'])))?> tracks</small></div><a class="mini-button" href="<?=e(base_url('admin/?page=music&edit_album='.rawurlencode((string)$album['id'])))?>">Edit</a></article><?php endforeach;?><?php if($albums===[]):?><div class="admin-empty"><strong>No albums yet.</strong></div><?php endif;?></div>
  </div>
</section>

<section class="media-admin-panel" data-tab-panel="playlists">
<?php
$playlistAction = 'save_audio_playlist';
$playlistValue = static fn(string $post, string $key, mixed $default = ''): string => $mediaValue($playlistAction, $editPlaylist, $post, $key, $default);
$playlistIds = array_map('strval', (array) ($editPlaylist['trackIds'] ?? []));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === $playlistAction) {
    $playlistIds = array_values(array_filter((array) ($_POST['track_ids'] ?? []), 'is_string'));
}
$trackMap = [];
foreach ($tracks as $track) $trackMap[(string) $track['id']] = $track;
$pickerTracks = [];
foreach ($playlistIds as $id) if (isset($trackMap[$id])) { $pickerTracks[] = $trackMap[$id]; unset($trackMap[$id]); }
$pickerTracks = array_merge($pickerTracks, array_values($trackMap));
?>
  <div class="media-admin-layout">
    <form method="post" class="admin-card media-editor-card" data-media-schedule>
      <?= csrf_field() ?><input type="hidden" name="action" value="save_audio_playlist">
      <input type="hidden" name="id" value="<?= e((string) ($editPlaylist['id'] ?? '')) ?>">
      <input type="hidden" name="media_revision" value="<?= e($playlistValue('media_revision', 'revision')) ?>">
      <input type="hidden" name="cover_asset_id" value="<?= e((string) ($editPlaylist['coverAssetId'] ?? '')) ?>">
      <div class="card-head"><div><span class="admin-kicker"><?= $editPlaylist ? 'Edit playlist' : 'New playlist' ?></span><h2><?= e((string) ($editPlaylist['title'] ?? 'Build a playlist')) ?></h2></div></div>
      <label>Playlist title<input name="title" maxlength="190" value="<?= e($playlistValue('title', 'title')) ?>" required></label>
      <label>Slug <small>optional</small><input name="slug" value="<?= e($playlistValue('slug', 'slug')) ?>"></label>
      <label>Description<textarea name="description" rows="4"><?= e($playlistValue('description', 'description')) ?></textarea></label>
      <div class="form-grid two">
        <label>Status<select name="status"><?php foreach ($statusOptions as $value => $label): ?><option value="<?= e($value) ?>"<?= $selected($playlistValue('status', 'status', 'draft'), $value) ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Access<select name="access_mode"><?php foreach ($accessOptions as $value => $label): ?><option value="<?= e($value) ?>"<?= $selected($playlistValue('access_mode', 'accessMode', 'public'), $value) ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      </div>
      <div class="form-grid two">
        <label>Publish date<input type="datetime-local" name="published_at" value="<?= e($mediaValue($playlistAction, null, 'published_at', '', $dateValue($editPlaylist['publishedAt'] ?? null))) ?>"></label>
        <label>Scheduled date<input type="datetime-local" name="scheduled_at" value="<?= e($mediaValue($playlistAction, null, 'scheduled_at', '', $dateValue($editPlaylist['scheduledAt'] ?? null))) ?>"></label>
      </div>
      <p class="media-field-help">Dates use <?= e($scheduleTimezone) ?>. Choose Scheduled and a date for a future publishing run.</p>
      <fieldset class="track-picker"><legend>Tracks in playback order</legend>
        <p class="media-field-help">Select tracks, then use the arrow buttons to change their playback order.</p>
        <div data-playlist-sort>
        <?php foreach ($pickerTracks as $track): $trackId = (string) $track['id']; ?>
          <div class="playlist-track-option" data-playlist-track="<?= e($trackId) ?>">
            <label><input type="checkbox" name="track_ids[]" value="<?= e($trackId) ?>"<?= $checked(in_array($trackId, $playlistIds, true)) ?>><span><strong><?= e((string) $track['title']) ?></strong><small><?= e((string) ($track['artist'] ?? 'Nikki Wilder')) ?></small></span></label>
            <div class="media-order-controls"><button type="button" class="mini-button" data-move-playlist-track="earlier" aria-label="Move track earlier">↑</button><button type="button" class="mini-button" data-move-playlist-track="later" aria-label="Move track later">↓</button></div>
          </div>
        <?php endforeach; ?>
        </div>
        <?php if ($tracks === []): ?><p class="media-field-help">Add a track in the Tracks tab first.</p><?php endif; ?>
        <span class="media-sr-only" data-playlist-status aria-live="polite"></span>
      </fieldset>
      <button class="admin-button primary full">Save Playlist</button>
      <?php if ($editPlaylist): ?><a class="back-link" href="<?= e(base_url('admin/?page=music&edit_playlist=' . rawurlencode((string) $editPlaylist['id']))) ?>">Reload latest playlist</a><?php endif; ?>
    </form>
    <div class="media-record-list">
      <?php foreach ($playlists as $playlist): ?>
      <article class="media-record-card"><img src="<?= e(media_thumbnail_src((array) ($playlist['coverAsset'] ?? []))) ?>" alt="">
        <div><div class="record-badges"><span><?= e((string) $playlist['status']) ?></span><span><?= e(MediaAccess::label((string) $playlist['accessMode'])) ?></span></div><h3><?= e((string) $playlist['title']) ?></h3><p><?= e((string) $playlist['description']) ?></p><small><?= e((string) count((array) ($playlist['trackIds'] ?? []))) ?> tracks</small><?php if (!empty($playlist['scheduledAt'])): ?><small class="media-schedule-date">Scheduled: <?= e($dateValue($playlist['scheduledAt'])) ?> · <?= e($scheduleTimezone) ?></small><?php endif; ?></div>
        <a class="mini-button" href="<?= e(base_url('admin/?page=music&edit_playlist=' . rawurlencode((string) $playlist['id']))) ?>">Edit</a>
      </article>
      <?php endforeach; ?><?php if ($playlists === []): ?><div class="admin-empty"><strong>No playlists yet.</strong></div><?php endif; ?>
    </div>
  </div>
</section>

<?php elseif ($page === 'galleries'):
    $galleries = $store->galleries(false);
    $editGallery = isset($_GET['edit']) ? $store->gallery((string) $_GET['edit']) : null;
    $galleryItems = (array) ($editGallery['items'] ?? []);
    $galleryAction = 'save_gallery';
    $galleryValue = static fn(string $post, string $key, mixed $default = ''): string => $mediaValue($galleryAction, $editGallery, $post, $key, $default);
    $galleryPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === $galleryAction) ? $_POST : [];
    $postedCaptions = is_array($galleryPost['asset_captions'] ?? null) ? $galleryPost['asset_captions'] : [];
    $postedTags = is_array($galleryPost['asset_tags'] ?? null) ? $galleryPost['asset_tags'] : [];
    if (isset($galleryPost['asset_order']) && is_string($galleryPost['asset_order'])) {
        $requested = json_decode($galleryPost['asset_order'], true);
        $byId = [];
        foreach ($galleryItems as $item) $byId[(string) $item['assetId']] = $item;
        if (is_array($requested) && array_is_list($requested) && count(array_filter($requested, 'is_string')) === count($requested) && count(array_unique($requested)) === count($requested) && array_diff($requested, array_keys($byId)) === []) {
            $galleryItems = array_map(static fn(string $id): array => $byId[$id], $requested);
        }
    }
?>
<section class="media-admin-hero"><div><span class="admin-kicker">Photo publishing</span><h2>Gallery manager</h2><p>Arrange photos, edit captions and tags, choose free previews, and manage subscription or PPV access.</p></div><div class="media-admin-stats"><article><strong><?= e((string) count($galleries)) ?></strong><span>Galleries</span></article><article><strong><?= e((string) array_sum(array_map(static fn(array $g): int => count((array) ($g['items'] ?? [])), $galleries))) ?></strong><span>Photos</span></article></div></section>
<div class="media-admin-layout">
  <form method="post" enctype="multipart/form-data" class="admin-card media-editor-card" data-gallery-editor data-media-schedule>
    <?= csrf_field() ?><input type="hidden" name="action" value="save_gallery">
    <input type="hidden" name="id" value="<?= e((string) ($editGallery['id'] ?? '')) ?>">
    <input type="hidden" name="media_revision" value="<?= e($galleryValue('media_revision', 'revision')) ?>">
    <input type="hidden" name="cover_asset_id" value="<?= e((string) ($editGallery['coverAssetId'] ?? '')) ?>">
    <input type="hidden" name="asset_order" data-gallery-order value="<?= e(json_encode(array_map(static fn(array $item): string => (string) ($item['assetId'] ?? ''), $galleryItems))) ?>">
    <div class="card-head"><div><span class="admin-kicker"><?= $editGallery ? 'Edit gallery' : 'New gallery' ?></span><h2><?= e((string) ($editGallery['title'] ?? 'Create a photo set')) ?></h2></div><?php if ($editGallery): ?><a class="mini-button" href="<?= e(base_url('admin/?page=galleries')) ?>">New</a><?php endif; ?></div>
    <label>Gallery title<input name="title" maxlength="190" value="<?= e($galleryValue('title', 'title')) ?>" required></label>
    <label>Slug <small>optional</small><input name="slug" value="<?= e($galleryValue('slug', 'slug')) ?>"></label>
    <label>Description<textarea name="description" rows="5"><?= e($galleryValue('description', 'description')) ?></textarea></label>
    <div class="gallery-editor-heading"><h3>Photos &amp; captions</h3><button type="button" class="mini-button" data-undo-gallery-removal hidden>Undo removal</button></div>
    <p class="media-field-help">Drag photos or use the arrow buttons to change their order. Captions appear in this gallery's lightbox. Tags belong to the photo and are shared wherever it is used.</p>
    <div class="existing-gallery-items gallery-photo-editor" data-gallery-sort>
      <?php foreach ($galleryItems as $index => $item):
        $assetId = (string) $item['assetId']; $asset = (array) ($item['asset'] ?? []);
        $caption = is_string($postedCaptions[$assetId] ?? null) ? $postedCaptions[$assetId] : (string) ($item['caption'] ?? '');
        $tags = is_string($postedTags[$assetId] ?? null) ? $postedTags[$assetId] : implode(', ', array_filter((array) ($asset['tags'] ?? []), 'is_string'));
      ?>
      <article draggable="true" data-gallery-asset="<?= e($assetId) ?>">
        <div class="gallery-photo-preview"><img src="<?= e(media_thumbnail_src($asset)) ?>" alt="<?= e((string) ($asset['title'] ?? 'Gallery photo')) ?>"><span class="gallery-position" data-gallery-position>Photo <?= e((string) ($index + 1)) ?></span><span class="gallery-preview-label" data-gallery-preview<?= $index < (int) $galleryValue('preview_count', 'previewCount', 0) ? '' : ' hidden' ?>>Free preview</span></div>
        <div class="gallery-photo-fields">
          <label>Caption<textarea name="asset_captions[<?= e($assetId) ?>]" rows="3" maxlength="2000" placeholder="Add a caption for this photo"><?= e($caption) ?></textarea></label>
          <label>Photo tags<input name="asset_tags[<?= e($assetId) ?>]" value="<?= e($tags) ?>" maxlength="2000" placeholder="Separate tags with commas"></label>
          <div class="gallery-photo-controls"><div class="media-order-controls"><button type="button" class="mini-button" data-move-gallery-asset="earlier" aria-label="Move photo earlier">↑</button><button type="button" class="mini-button" data-move-gallery-asset="later" aria-label="Move photo later">↓</button></div><button type="button" class="mini-button gallery-remove" data-remove-gallery-asset aria-label="Remove photo">Remove</button></div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <p class="media-field-help" data-gallery-empty<?= $galleryItems === [] ? '' : ' hidden' ?>>Keep at least one photo, or add photos below before saving. Save new uploads to edit their captions and tags.</p>
    <p class="media-field-help" data-gallery-removal-status aria-live="polite">Removing a photo from this gallery keeps its original file in your media library.</p>
    <div class="form-grid two">
      <label>Add photos <small>multiple files allowed</small><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple<?= $editGallery ? '' : ' required' ?>></label>
      <label>Custom cover <small>optional</small><input type="file" name="cover" accept="image/jpeg,image/png,image/webp"></label>
    </div>
    <div class="form-grid four">
      <label>Status<select name="status"><?php foreach ($statusOptions as $value => $label): ?><option value="<?= e($value) ?>"<?= $selected($galleryValue('status', 'status', 'draft'), $value) ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label>Access<select name="access_mode"><?php foreach ($accessOptions as $value => $label): ?><option value="<?= e($value) ?>"<?= $selected($galleryValue('access_mode', 'accessMode', 'public'), $value) ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label>Free preview photos<input type="number" name="preview_count" min="0" value="<?= e($galleryValue('preview_count', 'previewCount', 0)) ?>"></label>
      <label>Currency<input name="currency" maxlength="3" value="<?= e($galleryValue('currency', 'currency', 'USD')) ?>"></label>
    </div>
    <p class="media-field-help">Free previews use the first photos in the order above. Reordering can change which photos are free to view.</p>
    <div class="form-grid four">
      <label>PPV price<input type="number" min="0" step="0.01" name="standard_price" value="<?= e($galleryValue('standard_price', 'standardPrice')) ?>"></label>
      <label>Subscriber price<input type="number" min="0" step="0.01" name="subscriber_price" value="<?= e($galleryValue('subscriber_price', 'subscriberPrice')) ?>"></label>
      <label>Rental price<input type="number" min="0" step="0.01" name="rental_price" value="<?= e($galleryValue('rental_price', 'rentalPrice')) ?>"></label>
      <label>Rental hours<input type="number" min="0" name="rental_hours" value="<?= e($galleryValue('rental_hours', 'rentalHours', 0)) ?>"></label>
    </div>
    <div class="form-grid two">
      <label>Publish date<input type="datetime-local" name="published_at" value="<?= e($mediaValue($galleryAction, null, 'published_at', '', $dateValue($editGallery['publishedAt'] ?? null))) ?>"></label>
      <label>Scheduled date<input type="datetime-local" name="scheduled_at" value="<?= e($mediaValue($galleryAction, null, 'scheduled_at', '', $dateValue($editGallery['scheduledAt'] ?? null))) ?>"></label>
    </div>
    <p class="media-field-help">Dates use <?= e($scheduleTimezone) ?>.</p>
    <button class="admin-button primary full">Save Gallery</button>
    <?php if ($editGallery): ?><a class="back-link" href="<?= e(base_url('admin/?page=galleries&edit=' . rawurlencode((string) $editGallery['id']))) ?>">Reload latest gallery</a><?php endif; ?>
  </form>
  <div class="media-record-list">
    <?php foreach ($galleries as $gallery): ?>
    <article class="media-record-card gallery-record"><img src="<?= e(media_thumbnail_src((array) ($gallery['coverAsset'] ?? []))) ?>" alt=""><div><div class="record-badges"><span><?= e((string) $gallery['status']) ?></span><span><?= e(MediaAccess::label((string) $gallery['accessMode'])) ?></span></div><h3><?= e((string) $gallery['title']) ?></h3><p><?= e((string) $gallery['description']) ?></p><small><?= e((string) count((array) ($gallery['items'] ?? []))) ?> photos · <?= e((string) ($gallery['previewCount'] ?? 0)) ?> previews</small></div><a class="mini-button" href="<?= e(base_url('admin/?page=galleries&edit=' . rawurlencode((string) $gallery['id']))) ?>">Edit</a></article>
    <?php endforeach; ?><?php if ($galleries === []): ?><div class="admin-empty"><strong>No galleries yet.</strong><span>Upload the first set using the form.</span></div><?php endif; ?>
  </div>
</div>

<?php elseif ($page === 'videos'):
    $videos = $store->videos(false);
    $editVideo = isset($_GET['edit']) ? $store->video((string) $_GET['edit']) : null;
?>
<section class="media-admin-hero"><div><span class="admin-kicker">Video publishing</span><h2>Video library &amp; player</h2><p>Upload posters, public trailers, protected full videos, captions, access rules, and PPV-ready pricing.</p></div><div class="media-admin-stats"><article><strong><?=e((string)count($videos))?></strong><span>Videos</span></article><article><strong><?=e((string)count(array_filter($videos,static fn(array $v):bool=>(string)($v['status']??'')==='published')))?></strong><span>Published</span></article></div></section>
<div class="media-admin-layout">
<form method="post" enctype="multipart/form-data" class="admin-card media-editor-card"><?=csrf_field()?><input type="hidden" name="action" value="save_video"><input type="hidden" name="id" value="<?=e((string)($editVideo['id']??''))?>"><input type="hidden" name="poster_asset_id" value="<?=e((string)($editVideo['posterAssetId']??''))?>"><input type="hidden" name="trailer_asset_id" value="<?=e((string)($editVideo['trailerAssetId']??''))?>"><input type="hidden" name="full_asset_id" value="<?=e((string)($editVideo['fullAssetId']??''))?>"><input type="hidden" name="caption_asset_id" value="<?=e((string)($editVideo['captionAssetId']??''))?>"><div class="card-head"><div><span class="admin-kicker"><?=$editVideo?'Edit video':'New video'?></span><h2><?=e((string)($editVideo['title']??'Publish a video'))?></h2></div><?php if($editVideo):?><a class="mini-button" href="<?=e(base_url('admin/?page=videos'))?>">New</a><?php endif;?></div><label>Video title<input name="title" value="<?=e((string)($editVideo['title']??''))?>" required></label><label>Slug <small>optional</small><input name="slug" value="<?=e((string)($editVideo['slug']??''))?>"></label><label>Description<textarea name="description" rows="5"><?=e((string)($editVideo['description']??''))?></textarea></label><div class="form-grid two"><label>Poster image <?=$editVideo?'<small>leave empty to keep current</small>':''?><input type="file" name="poster" accept="image/jpeg,image/png,image/webp" <?=$editVideo?'':'required'?>></label><label>Caption file <small>VTT or SRT</small><input type="file" name="caption" accept=".vtt,.srt,text/vtt,application/x-subrip"></label><label>Public trailer<input type="file" name="trailer" accept="video/mp4,video/webm,video/quicktime"></label><label>Protected full video<input type="file" name="full_video" accept="video/mp4,video/webm,video/quicktime"></label></div><div class="form-grid four"><label>Status<select name="status"><?php foreach($statusOptions as $value=>$label):?><option value="<?=e($value)?>"<?=$selected($editVideo['status']??'draft',$value)?>><?=e($label)?></option><?php endforeach;?></select></label><label>Access<select name="access_mode"><?php foreach($accessOptions as $value=>$label):?><option value="<?=e($value)?>"<?=$selected($editVideo['accessMode']??'public',$value)?>><?=e($label)?></option><?php endforeach;?></select></label><label>Currency<input name="currency" maxlength="3" value="<?=e((string)($editVideo['currency']??'USD'))?>"></label><label>Rental hours<input type="number" min="0" name="rental_hours" value="<?=e((string)($editVideo['rentalHours']??0))?>"></label></div><div class="form-grid three"><label>PPV price<input type="number" min="0" step="0.01" name="standard_price" value="<?=e((string)($editVideo['standardPrice']??''))?>"></label><label>Subscriber price<input type="number" min="0" step="0.01" name="subscriber_price" value="<?=e((string)($editVideo['subscriberPrice']??''))?>"></label><label>Rental price<input type="number" min="0" step="0.01" name="rental_price" value="<?=e((string)($editVideo['rentalPrice']??''))?>"></label></div><div class="form-grid two"><label>Publish date<input type="datetime-local" name="published_at" value="<?=e($dateValue($editVideo['publishedAt']??null))?>"></label><label>Scheduled date<input type="datetime-local" name="scheduled_at" value="<?=e($dateValue($editVideo['scheduledAt']??null))?>"></label></div><button class="admin-button primary full">Save Video</button></form>
<div class="media-record-list"><?php foreach($videos as $video):?><article class="media-record-card video-record"><img src="<?=e(media_thumbnail_src((array)($video['posterAsset']??[])))?>" alt=""><div><div class="record-badges"><span><?=e((string)$video['status'])?></span><span><?=e(MediaAccess::label((string)$video['accessMode']))?></span></div><h3><?=e((string)$video['title'])?></h3><p><?=e((string)$video['description'])?></p><small><?=e(media_time((float)($video['duration']??0)))?><?=!empty($video['trailerAssetId'])?' · trailer':''?><?=!empty($video['fullAssetId'])?' · full video':''?></small></div><a class="mini-button" href="<?=e(base_url('admin/?page=videos&edit='.rawurlencode((string)$video['id'])))?>">Edit</a></article><?php endforeach;?><?php if($videos===[]):?><div class="admin-empty"><strong>No videos yet.</strong><span>Upload a poster and trailer or full video.</span></div><?php endif;?></div>
</div>
<?php endif; ?>

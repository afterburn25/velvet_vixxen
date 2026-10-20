<?php
declare(strict_types=1);

use App\Core\MediaAccess;

$playerMember = current_member();
$allTracks = app_store()->tracks(true);
$playerTracks = [];
foreach ($allTracks as $track) {
    $asset = (array) ($track['audioAsset'] ?? []);
    $decision = entitlement_resolver()->resolve('track', $track, $playerMember, false);
    $hasPreview = (int) ($track['previewSeconds'] ?? 0) > 0;
    $src = ($decision['allowed'] || $hasPreview) ? media_asset_src($asset, true) : '';
    if ($src === '') {
        continue;
    }
    $cover = media_thumbnail_src((array) ($track['coverAsset'] ?? []));
    $playerTracks[] = [
        'id' => (string) ($track['id'] ?? ''),
        'title' => (string) ($track['title'] ?? 'Untitled'),
        'artist' => (string) ($track['artist'] ?? 'Nikki Wilder'),
        'album' => (string) ($track['albumTitle'] ?? ''),
        'src' => $src,
        'cover' => $cover,
        'duration' => (float) ($track['duration'] ?? $asset['duration'] ?? 0),
        'previewSeconds' => $decision['allowed'] ? 0 : (int) ($track['previewSeconds'] ?? 0),
        'accessAllowed' => (bool) $decision['allowed'],
        'accessMode' => (string) ($track['accessMode'] ?? 'public'),
        'favorite' => $playerMember ? (bool) (app_store()->memberMediaState((string) $playerMember['id'], 'track', (string) $track['id'])['isFavorite'] ?? false) : false,
        'serverProgress' => $playerMember ? (float) (app_store()->memberMediaState((string) $playerMember['id'], 'track', (string) $track['id'])['progressSeconds'] ?? 0) : 0,
    ];
}
?>
<div class="persistent-player" id="persistentPlayer" data-player hidden>
  <audio data-player-audio preload="metadata"></audio>
  <button class="player-cover" type="button" data-player-expand aria-label="Expand player"><img data-player-cover alt=""><span>♫</span></button>
  <div class="player-meta"><strong data-player-title>Choose a song</strong><small data-player-artist>Nikki Wilder</small></div>
  <div class="player-main-controls"><button type="button" data-player-prev aria-label="Previous track">‹</button><button class="player-play" type="button" data-player-play aria-label="Play">▶</button><button type="button" data-player-next aria-label="Next track">›</button></div>
  <div class="player-progress"><span data-player-current>0:00</span><input type="range" min="0" max="1000" value="0" data-player-seek aria-label="Track position"><span data-player-duration>0:00</span></div>
  <button type="button" data-player-shuffle aria-label="Shuffle">⌘</button><button type="button" data-player-repeat aria-label="Repeat">↻</button>
  <label class="player-volume"><span>⌕</span><input type="range" min="0" max="1" value="0.8" step="0.01" data-player-volume aria-label="Volume"></label>
  <button class="player-queue-button" type="button" data-player-expand>Queue</button>
</div>
<aside class="player-drawer" data-player-drawer hidden aria-label="Audio queue">
  <div class="player-drawer-head"><div><span class="eyebrow pink">Now playing</span><h2 data-drawer-title>Velvet Vixxen Player</h2></div><button type="button" data-player-close aria-label="Close expanded player">×</button></div>
  <div class="player-drawer-now"><img data-drawer-cover alt=""><div><strong data-drawer-track>Choose a track</strong><small data-drawer-artist>Nikki Wilder</small></div></div>
  <div class="player-queue" data-player-queue></div>
</aside>
<script>window.VELVET_MEDIA_CENTER=Object.assign(window.VELVET_MEDIA_CENTER||{},<?= json_encode(['tracks'=>$playerTracks], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>);</script>

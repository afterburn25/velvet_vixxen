<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }require ROOT_PATH.'/app/bootstrap.php';if(!is_installed())redirect(base_url('install/'));
use App\Core\MediaAccess;
$pageTitle='Music';$activeNav='music';$albums=app_store()->albums(true);$tracks=app_store()->tracks(true);$playlists=app_store()->audioPlaylists(true);$member=current_member();$memberId=$member?(string)$member['id']:null;
require root_path('app/Views/public/partials/header.php');
?>
<section class="media-hero music-hero"><div><span class="eyebrow pink">The Velvet Vixxen soundtrack</span><h1>Music after dark.</h1><p>Play Nikki's songs, build a queue, save favorites, and keep listening while you move through the site.</p></div><div class="media-hero-orbit">♫</div></section>
<section class="media-content-section"><div class="media-section-head"><div><span class="eyebrow pink">Latest tracks</span><h2>Press play</h2></div><p>Public songs, member releases, subscription tracks, and previews share one persistent player.</p></div><div class="track-list">
<?php foreach($tracks as $track):$cover=media_thumbnail_src((array)($track['coverAsset']??[]));$asset=(array)($track['audioAsset']??[]);$decision=entitlement_resolver()->resolve('track',$track,$member,false);$hasPreview=(int)($track['previewSeconds']??0)>0;$playable=$decision['allowed']||$hasPreview;$state=$memberId?app_store()->memberMediaState($memberId,'track',(string)$track['id']):[];?>
<article class="track-row" id="track-<?=e((string)$track['id'])?>" data-track-card="<?=e((string)$track['id'])?>"><button class="track-play" type="button" <?=$playable?'data-play-track="'.e((string)$track['id']).'"':'disabled'?> aria-label="<?=$playable?'Play':'Locked'?> <?=e((string)$track['title'])?>"><?=$playable?'▶':'◆'?></button><img src="<?=e($cover)?>" alt=""><div class="track-copy"><strong><?=e((string)$track['title'])?></strong><small><?=e((string)($track['artist']??'Nikki Wilder'))?><?=!empty($track['albumTitle'])?' · '.e((string)$track['albumTitle']):''?><?=!$decision['allowed']&&$hasPreview?' · '.e((string)$track['previewSeconds']).' sec preview':''?></small><?php if(!$decision['allowed']):?><small><?=e((string)$decision['reason'])?></small><?php endif;?></div><span class="access-pill <?=$decision['allowed']?'public':'locked'?>"><?=e($decision['allowed']?'Available':MediaAccess::label((string)$track['accessMode']))?></span><time><?=e(media_time((float)($track['duration']??$asset['duration']??0)))?></time><?php if($member):?><button class="favorite-toggle <?=!empty($state['isFavorite'])?'active':''?>" type="button" data-favorite-toggle data-content-type="track" data-content-id="<?=e((string)$track['id'])?>" aria-label="Favorite track">♡</button><?php endif;?><button class="queue-add" type="button" <?=$playable?'data-queue-track="'.e((string)$track['id']).'"':'disabled'?>><?=$playable?'+ Queue':'Locked'?></button></article>
<?php endforeach;?><?php if($tracks===[]):?><div class="empty-media-state"><span>♫</span><h3>No published tracks yet.</h3><p>Upload a song from the Music administration page.</p></div><?php endif;?></div></section>
<?php if($albums!==[]):?><section class="media-content-section alt"><div class="media-section-head"><div><span class="eyebrow pink">Albums</span><h2>Collections</h2></div></div><div class="album-grid"><?php foreach($albums as $album):?><article class="album-card"><img src="<?=e(media_thumbnail_src((array)($album['coverAsset']??[])))?>" alt=""><div><span><?=e((string)count(array_filter($tracks,fn(array $t):bool=>(string)($t['albumId']??'')===(string)$album['id'])))?> tracks</span><h3><?=e((string)$album['title'])?></h3><p><?=e((string)$album['description'])?></p></div></article><?php endforeach;?></div></section><?php endif;?>
<?php if ($playlists !== []):
    $queueableTracks = [];
    foreach ($tracks as $candidate) {
        $trackDecision = entitlement_resolver()->resolve('track', $candidate, $member, false);
        if ($trackDecision['allowed'] || (int) ($candidate['previewSeconds'] ?? 0) > 0) $queueableTracks[(string) $candidate['id']] = true;
    }
?>
<section class="media-content-section"><div class="media-section-head"><div><span class="eyebrow pink">Playlists</span><h2>Curated for you</h2></div></div>
  <div class="playlist-grid">
  <?php foreach ($playlists as $playlist):
      $playlistDecision = entitlement_resolver()->resolve('playlist', $playlist, $member, false);
      $queue = $playlistDecision['allowed'] ? array_values(array_filter(array_map('strval', (array) ($playlist['trackIds'] ?? [])), static fn(string $id): bool => isset($queueableTracks[$id]))) : [];
  ?>
    <article class="playlist-card" data-playlist-card="<?= e((string) $playlist['id']) ?>">
      <img src="<?= e(media_thumbnail_src((array) ($playlist['coverAsset'] ?? []))) ?>" alt="">
      <div><span class="access-pill <?= $playlistDecision['allowed'] ? 'public' : 'locked' ?>"><?= e(MediaAccess::label((string) $playlist['accessMode'])) ?></span><h3><?= e((string) $playlist['title']) ?></h3><p><?= e((string) $playlist['description']) ?></p>
      <?php if ($playlistDecision['allowed'] && $queue !== []): ?>
        <button class="button button--primary" type="button" data-play-playlist="<?= e(json_encode($queue)) ?>">Play Playlist</button>
      <?php elseif (!$playlistDecision['allowed']): ?>
        <p><?= e((string) $playlistDecision['reason']) ?></p>
        <?php if ($playlistDecision['loginRequired']): ?><a class="button button--primary" href="<?= e(base_url('account/login.php')) ?>">Sign in to play</a><?php endif; ?>
      <?php else: ?><p>No playable tracks are available in this playlist yet.</p><?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php require root_path('app/Views/public/partials/footer.php'); ?>

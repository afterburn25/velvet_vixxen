<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__)); }
require ROOT_PATH . '/app/bootstrap.php';

if (!is_installed()) {
    redirect(base_url('install/'));
}

use App\Core\MediaAccess;

$store = app_store();
$member = current_member();
$membershipPlans = $store->membershipPlans(true);
$settings = (array) $store->setting('site', []);
$newsletterVisible = (bool) ($settings['newsletter_enabled'] ?? true);
if ($newsletterVisible) {
    require_once root_path('includes/marketing.php');
    try { $newsletterVisible = !empty(velvet_marketing_store()->settings()['newsletter_signup_enabled']); }
    catch (Throwable) { $newsletterVisible = false; }
}
$brand = (string) ($settings['brand_name'] ?? 'Nikki Wilder');
$subtitle = (string) ($settings['brand_subtitle'] ?? 'The Velvet Vixxen');
$tagline = (string) ($settings['tagline'] ?? 'Bolder. Sexier. Closer.');
$heroCopy = (string) ($settings['hero_copy'] ?? 'Exclusive content, personal updates, and a closer connection. Welcome to my world.');
$previewMode = isset($_GET['preview']) && $_GET['preview'] === '1' && app_auth()->check();
require_once root_path('includes/marketing.php');
if (!$previewMode) velvet_marketing_record_pageview();
$publicScenes = [
    'hero' => scene_protected_paths($store->scene('hero', !$previewMode)),
    'footer' => scene_protected_paths($store->scene('footer', !$previewMode)),
];
$featuredGalleries = array_slice($store->galleries(true), 0, 5);
$featuredTracks = array_slice($store->tracks(true), 0, 3);
$featuredVideos = array_slice($store->videos(true), 0, 3);
$trailerVideo = $featuredVideos[0] ?? null;
$trailerAsset = (array) ($trailerVideo['trailerAsset'] ?? $trailerVideo['fullAsset'] ?? []);
$trailerSrc = $trailerAsset !== [] ? media_asset_src($trailerAsset, true) : '';
$trailerCaption = (array) ($trailerVideo['captionAsset'] ?? []);
$trailerCaptionSrc = $trailerCaption !== [] ? media_asset_src($trailerCaption, false) : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#090308">
<meta name="description" content="Welcome to Nikki Wilder — The Velvet Vixxen.">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($brand) ?> — <?= e($subtitle) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('css/site.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/media-center.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/member.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/communication.css')) ?>">
<link rel="icon" href="<?= e(base_url('images/defaults/brand-mark.png')) ?>" type="image/png"><link rel="stylesheet" href="<?= e(asset_url('css/site-ux.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/navigation.css')) ?>"></head>
<body class="public-page">
<div class="age-gate" id="ageGate" hidden>
  <div class="age-gate__backdrop"></div>
  <section class="age-gate__card" role="dialog" aria-modal="true" aria-labelledby="ageTitle">
    <div class="brand-lockup compact"><span class="brand-script"><?= e($brand) ?></span><span class="brand-sub"><?= e($subtitle) ?></span></div>
    <p class="eyebrow">Adults only</p>
    <h1 id="ageTitle">Please confirm you are 18 or older.</h1>
    <p>This website is intended for adults. By entering, you confirm that you meet the legal age requirement where you live.</p>
    <div class="age-actions"><button class="button button--primary" id="ageEnter">I am 18+ — Enter</button><a class="button button--ghost" href="https://www.google.com">Leave</a></div>
  </section>
</div>

<?php $activeNav = 'home'; require root_path('app/Views/public/partials/navigation.php'); ?>

<main id="mainContent" tabindex="-1">
<section class="visual-scene hero-scene" id="heroScene" data-scene="hero">
  <div class="scene-stage" aria-hidden="true"><img class="scene-background" alt=""><div class="scene-overlay"></div><div class="scene-layers"></div></div>
  <div class="hero-content">
    <div class="hero-brand brand-script"><?= e($brand) ?><span class="neon-heart">♡</span></div>
    <div class="hero-subtitle"><?= e($subtitle) ?></div>
    <h1><?= e($tagline) ?></h1>
    <p><?= e($heroCopy) ?></p>
    <?php $sceneButtonSection = 'hero'; require root_path('app/Views/public/partials/scene-buttons.php'); ?>
  </div>
</section>

<section class="paper-section feature-and-sets" id="sets">
  <div class="feature-grid">
    <article><span class="line-icon">♫</span><strong>Original<br>Music</strong></article>
    <article><span class="line-icon">▣</span><strong>Exclusive<br>Photos</strong></article>
    <article><span class="line-icon">▶</span><strong>Videos &amp;<br>Trailers</strong></article>
    <a class="feature-link" href="<?= e($member?base_url('requests/'):base_url('account/login.php?return='.rawurlencode('/requests/'))) ?>"><span class="line-icon">♡</span><strong>Member<br>Requests</strong></a>
    <a class="feature-link" href="<?= e(base_url('booking/')) ?>"><span class="line-icon">▦</span><strong>Booking &amp;<br>Appearances</strong></a>
  </div>
  <div class="content-wrap">
    <div class="section-heading"><div><span class="eyebrow pink">Private collection</span><h2>Popular Sets</h2></div><a href="<?= e(base_url('photos/')) ?>">View All <span>→</span></a></div>
    <div class="set-grid" tabindex="0" role="region" aria-label="Popular photo sets">
      <?php if ($featuredGalleries !== []): foreach ($featuredGalleries as $gallery): $cover=(array)($gallery['coverAsset']??[]); $galleryDecision=entitlement_resolver()->resolve('gallery',$gallery,$member,false); ?>
      <a class="set-card" href="<?= e(base_url('photos/view.php?slug='.rawurlencode((string)$gallery['slug']))) ?>"><div class="set-image"><img src="<?= e(media_thumbnail_src($cover)) ?>" alt="<?= e((string)$gallery['title']) ?>"><span class="lock-badge"><?= $galleryDecision['allowed']?'↗':'◆' ?></span></div><h3><?= e((string)$gallery['title']) ?></h3><p><?= e((string)count((array)($gallery['items']??[]))) ?> Photos · <?= e($galleryDecision['allowed']?'Available':MediaAccess::label((string)$gallery['accessMode'])) ?></p></a>
      <?php endforeach; else: foreach ([['Pink Paradise',48,'set-1.jpg'],['Vixxen Nights',32,'set-2.jpg'],['Mirror Play',27,'set-3.jpg'],['Lingerie Lounge',41,'set-4.jpg'],['Velvet Escapes',36,'set-5.jpg']] as [$title,$count,$image]): ?>
      <article class="set-card"><div class="set-image"><img src="<?= e(image_url('defaults/'.$image)) ?>" alt="<?= e($title) ?> placeholder scene"><span class="lock-badge">▣</span></div><h3><?= e($title) ?></h3><p><?= e((string)$count) ?> Photos</p></article>
      <?php endforeach; endif; ?>
    </div>
  </div>
</section>

<?= addon_hook('homepage.after_sets', ['member'=>$member, 'site'=>$settings]) ?>

<section class="homepage-media-showcase">
  <div class="content-wrap">
    <div class="section-heading inverse"><div><span class="eyebrow pink">Now playing</span><h2>Music &amp; Video</h2></div><p>The audio player stays with you across the site. Videos remember your position on this device.</p></div>
    <div class="homepage-media-grid">
      <article class="homepage-music-card">
        <div><span class="eyebrow pink">Featured tracks</span><h3>Velvet after dark.</h3><p>Press play, build a queue, and keep listening while you browse.</p><a class="button button--primary" href="<?= e(base_url('music/')) ?>">Open Music</a></div>
        <div class="home-track-stack"><?php foreach ($featuredTracks as $track): $trackDecision=entitlement_resolver()->resolve('track',$track,$member,false);$trackPlayable=$trackDecision['allowed']||(int)($track['previewSeconds']??0)>0; ?><button type="button" <?= $trackPlayable?'data-play-track="'.e((string)$track['id']).'"':'disabled' ?>><span><?= $trackPlayable?'▶':'◆' ?></span><div><strong><?= e((string)$track['title']) ?></strong><small><?= e((string)($track['artist']??'Nikki Wilder')) ?> · <?= e($trackDecision['allowed']?'Available':MediaAccess::label((string)$track['accessMode'])) ?></small></div><time><?= e(media_time((float)($track['duration']??0))) ?></time></button><?php endforeach; ?><?php if($featuredTracks===[]):?><p class="home-empty">Your featured songs will appear here after publishing.</p><?php endif;?></div>
      </article>
      <div class="home-video-grid"><?php foreach ($featuredVideos as $video): $videoDecision=entitlement_resolver()->resolve('video',$video,$member,false); ?><a href="<?= e(base_url('videos/watch.php?slug='.rawurlencode((string)$video['slug']))) ?>"><img src="<?= e(media_thumbnail_src((array)($video['posterAsset']??[]))) ?>" alt="<?= e((string)$video['title']) ?>"><span>▶</span><div><strong><?= e((string)$video['title']) ?></strong><small><?= e($videoDecision['allowed']?'Available':MediaAccess::label((string)$video['accessMode'])) ?> · <?= e(media_time((float)($video['duration']??0))) ?></small></div></a><?php endforeach; ?><?php if($featuredVideos===[]):?><div class="home-empty">Published videos will appear here.</div><?php endif;?></div>
    </div>
  </div>
</section>

<section class="visual-scene closing-scene" id="footerScene" data-scene="footer">
  <div class="scene-stage" aria-hidden="true"><img class="scene-background" alt=""><div class="scene-overlay"></div><div class="scene-layers"></div></div>
  <div class="closing-content" id="about"><span class="script-heading">More Than<br>Just Content...</span><p>This is a place to be real, to connect, and to share my world with you. Thank you for being here and supporting my journey.</p><?php $sceneButtonSection = 'footer'; require root_path('app/Views/public/partials/scene-buttons.php'); ?></div>
  <div class="closing-heart" aria-hidden="true">♡</div>
</section>

<section class="membership-section" id="membership">
  <div class="content-wrap"><div class="section-heading inverse"><div><span class="eyebrow">Choose your access</span><h2>Enter the Velvet Club</h2></div><p>Member registration, verified accounts, plan entitlements, My Library, favorites, and cross-device progress are active now.</p></div>
  <div class="membership-grid"><?php foreach(array_slice($membershipPlans,0,3) as $index=>$plan):?><article class="<?=$index===1?'featured':''?>"><span><?=e((string)$plan['billingInterval'])?></span><h3><?=e((string)$plan['name'])?></h3><p><?=e((string)$plan['description'])?></p><a class="button <?=$index===1?'button--primary':'button--ghost'?>" href="<?=e(base_url('membership/'))?>">View Plan</a></article><?php endforeach;?><?php if($membershipPlans===[]):?><article><span>Free</span><h3>Member Account</h3><p>Save favorites, track progress, and collect unlocked releases.</p><a class="button button--primary" href="<?=e(base_url('account/register.php'))?>">Create Account</a></article><?php endif;?></div></div>
</section>

<section class="contact-section" id="contact"><div class="content-wrap contact-grid"><article id="booking"><span class="eyebrow pink">Professional inquiries</span><h2>Booking &amp; Appearances</h2><p>Submit a private appearance, collaboration, interview, media, or event inquiry with optional protected reference files.</p><a class="button button--dark" href="<?= e(base_url('booking/')) ?>">Start a Private Inquiry</a></article><?php if ($newsletterVisible): ?><article data-newsletter-invitation><span class="eyebrow pink">Stay connected</span><h2>Never miss a drop.</h2><p>New songs, galleries, videos, appearances, and member-only offers delivered directly.</p><a class="button button--primary" href="<?= e(base_url('newsletter/')) ?>">Join the Mailing List</a></article><?php endif; ?></div></section>
</main>

<?php
try { $footerMenuSnapshot = $store->navigationMenu('footer', 'home'); }
catch (Throwable) { $footerMenuSnapshot = ['configured' => true, 'items' => []]; }
$customFooterMenu = $footerMenuSnapshot['configured'];
$publicMenuItems = $customFooterMenu ? \App\Core\MenuService::publicItems($footerMenuSnapshot) : [];
$publicMenuInstance = 'footer';
?>
<footer class="legal-footer<?= $customFooterMenu && $publicMenuItems !== [] ? ' has-custom-footer-menu' : '' ?>"><div class="brand-lockup compact"><span class="brand-script"><?= e($brand) ?></span><span class="brand-sub"><?= e($subtitle) ?></span></div><nav aria-label="Footer navigation"<?= $customFooterMenu ? ' data-menu-custom' . ($publicMenuItems === [] ? ' data-menu-empty' : '') : '' ?>><?php if ($customFooterMenu): require root_path('app/Views/public/partials/menu-items.php'); else: ?><a href="#top">Home</a><a href="<?= e(base_url('music/')) ?>">Music</a><a href="<?= e(base_url('photos/')) ?>">Photos</a><a href="<?= e(base_url('videos/')) ?>">Videos</a><a href="<?= e(base_url('membership/')) ?>">Membership</a><a href="<?= e(base_url('shop/')) ?>">Store</a><a href="<?= e(base_url('booking/')) ?>">Booking</a><a href="<?= e(base_url('about/')) ?>">About Me</a><?php endif; ?></nav><?php require root_path('app/Views/public/partials/legal-links.php'); ?><span class="signature">Nikki ♡</span><p class="site-credit">Website designed &amp; developed by <a href="https://cajunwebpro.com/" target="_blank" rel="noopener noreferrer">Cajun Web Pro LLC</a></p></footer>

<?php if ($trailerSrc !== ''): ?><div class="trailer-modal" id="trailerModal" role="dialog" aria-modal="true" aria-labelledby="trailerTitle" hidden><button class="trailer-close" data-close-trailer aria-label="Close preview">×</button><div class="trailer-player"><video controls playsinline preload="metadata" poster="<?= e(media_thumbnail_src((array)($trailerVideo['posterAsset']??[]))) ?>" src="<?= e($trailerSrc) ?>"><?php if ($trailerCaptionSrc !== ''): ?><track kind="captions" srclang="en" label="English" src="<?= e($trailerCaptionSrc) ?>" default><?php endif; ?></video><h2 id="trailerTitle"><?= e((string)($trailerVideo['title']??'Watch Trailer')) ?></h2><a class="button button--primary" href="<?= e(base_url('videos/watch.php?slug='.rawurlencode((string)($trailerVideo['slug']??'')))) ?>">Open Video Page</a></div></div><?php endif; ?>
<?php require root_path('app/Views/public/partials/player.php'); ?>
<script>window.VELVET_CONFIG=<?= json_encode(['baseUrl'=>base_url(),'uploadBase'=>base_url('uploads/'),'scenes'=>$publicScenes,'ageGate'=>(bool)($settings['age_gate_enabled']??true),'csrfToken'=>csrf_token(),...public_route_config(),'member'=>$member?['id'=>(string)$member['id'],'displayName'=>(string)$member['displayName']]:null], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script>
<script src="<?= e(asset_url('js/scene-geometry.js')) ?>" defer></script>
<script src="<?= e(asset_url('js/scene-depth.js')) ?>" defer></script>
<script src="<?= e(asset_url('js/site.js')) ?>" defer></script>
<script src="<?= e(asset_url('js/navigation.js')) ?>" defer></script>
<script src="<?= e(asset_url('js/media-center.js')) ?>" defer></script>
</body></html>

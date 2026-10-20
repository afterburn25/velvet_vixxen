<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 3));
require ROOT_PATH . '/app/bootstrap.php';
if (!is_installed()) redirect(base_url('install/'));
$store = app_store();
$commerceSettings = (array) $store->setting('commerce', []);
$storeEnabled = (bool) ($commerceSettings['store_enabled'] ?? true);
$products = $storeEnabled ? $store->merchandiseProducts(true) : [];
$selected = isset($_GET['product']) ? $store->merchandiseProduct((string) $_GET['product'], true) : null;
$missing = isset($_GET['product']) && !$selected;
if ($missing) http_response_code(404);
$plans = $storeEnabled ? $store->membershipPlans(true) : [];
$pageTitle = $selected ? (string) $selected['title'] : 'Store';
$activeNav = 'shop';
require root_path('app/Views/public/partials/header.php');
?>
<div class="commerce-main merchandise-store">
  <section class="commerce-head"><span class="eyebrow pink">Velvet store</span><h1><?= $selected ? e((string) $selected['title']) : 'A little Velvet, all yours.' ?></h1><p>Explore merchandise, find your favorite fit, and bring a piece of the Velvet world home.</p></section>
  <?php if (!$storeEnabled): ?><section class="commerce-empty"><h2>The store is temporarily unavailable.</h2><p>Purchases are paused while the catalog is updated.</p></section>
  <?php elseif ($missing): ?><section class="commerce-empty"><h2>Product not found.</h2><a class="button button--primary" href="<?= e(base_url('shop/')) ?>">Browse merchandise</a></section>
  <?php elseif ($selected): $product = $selected; ?>
    <a class="back-link" href="<?= e(base_url('shop/')) ?>">← All merchandise</a>
    <section class="merchandise-detail"><div class="merchandise-gallery">
      <?php if ($product['imageIds'] === []): ?><div class="store-card-art" aria-label="Product image coming soon">♡</div><?php else: foreach ($product['imageIds'] as $imageId): $asset = $store->mediaAsset((string) $imageId); if ($asset): ?><img src="<?= e(media_asset_src($asset, false)) ?>" alt="<?= e($product['title']) ?>" loading="lazy"><?php endif; endforeach; endif; ?>
    </div><div class="merchandise-detail-copy"><h2><?= e($product['title']) ?></h2><p class="merchandise-description"><?= nl2br(e($product['description'])) ?></p><?php require root_path('app/Views/public/partials/merchandise-buy.php'); ?></div></section>
  <?php else: ?>
    <section class="store-section"><div class="store-heading"><span class="eyebrow pink">The collection</span><h2>Merchandise</h2></div>
      <?php if ($products === []): ?><div class="commerce-empty"><h3>The next collection is on its way.</h3><p>Check back for new merchandise.</p></div><?php else: ?><div class="store-grid">
        <?php foreach ($products as $product): $image = !empty($product['imageIds']) ? $store->mediaAsset((string) $product['imageIds'][0]) : null; ?>
        <article class="store-card merchandise-card" data-product-id="<?= e((string) $product['id']) ?>">
          <a class="merchandise-image" href="<?= e(base_url('shop/?product=' . rawurlencode($product['slug']))) ?>"><?php if ($image): ?><img src="<?= e(media_thumbnail_src($image)) ?>" alt="<?= e($product['title']) ?>" loading="lazy"><?php else: ?><span class="store-card-art" aria-label="Product image coming soon">♡</span><?php endif; ?></a>
          <div><h3><a href="<?= e(base_url('shop/?product=' . rawurlencode($product['slug']))) ?>"><?= e($product['title']) ?></a></h3><p><?= e(mb_strimwidth($product['description'], 0, 180, '…')) ?></p><?php require root_path('app/Views/public/partials/merchandise-buy.php'); ?></div>
        </article>
        <?php endforeach; ?>
      </div><?php endif; ?>
    </section>
  <?php endif; ?>
  <?php if ($storeEnabled && !$selected && !$missing): ?>
    <section class="store-section membership-promotion"><div class="store-heading"><span class="eyebrow pink">Membership</span><h2>Enter the Velvet Club</h2><p>Choose recurring access through a dedicated membership checkout.</p></div><div class="store-grid">
      <?php foreach ($plans as $plan): ?><article class="store-card membership-store-card"><div class="store-card-art">♛</div><div><h3><?= e((string) $plan['name']) ?></h3><p><?= e((string) $plan['description']) ?></p><strong><?= e(money((float) $plan['price'], (string) $plan['currency'])) ?> / <?= e((string) $plan['billingInterval']) ?></strong><form method="post" action="<?= e(base_url('checkout/')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="start"><input type="hidden" name="type" value="plan"><input type="hidden" name="id" value="<?= e((string) $plan['id']) ?>"><input type="hidden" name="mode" value="subscribe"><button class="button button--primary">Choose Plan</button></form></div></article><?php endforeach; ?>
    </div></section>
    <section class="store-section content-promotion"><h2>Discover a new release.</h2><p>Use Buy Now or Rent directly on a gallery or video. Purchased access stays in My Library.</p><div class="store-actions"><a class="button button--ghost" href="<?= e(base_url('photos/')) ?>">Photo galleries</a><a class="button button--ghost" href="<?= e(base_url('videos/')) ?>">Videos</a><a class="button button--ghost" href="<?= e(base_url('collections/')) ?>">Content collections</a></div></section>
  <?php endif; ?>
</div>
<script src="<?= e(asset_url('js/merchandise.js')) ?>" defer></script>
<?php require root_path('app/Views/public/partials/footer.php'); ?>

<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 3));
require ROOT_PATH . '/app/bootstrap.php';
if (!is_installed()) redirect(base_url('install/'));
$member = current_member();
$subscriber = $member && app_store()->activeSubscriptions((string) $member['id']) !== [];
$bundles = app_store()->bundles(true);
$pageTitle = 'Content Collections'; $activeNav = 'photos';
require root_path('app/Views/public/partials/header.php');
?>
<div class="commerce-main"><section class="commerce-head"><span class="eyebrow pink">Photo + video</span><h1>Content collections.</h1><p>Unlock a curated collection with one direct purchase. Your content is available in My Library.</p></section><div class="store-grid">
<?php foreach ($bundles as $bundle): ?><article class="store-card bundle-card"><div class="store-card-art">✦</div><div><h2><?= e((string) $bundle['title']) ?></h2><p><?= e((string) $bundle['description']) ?></p><ul><?php foreach ($bundle['items'] as $item): $content = entitlement_resolver()->content($item['itemType'], $item['itemId']); if ($content): ?><li><?= e((string) $content['title']) ?></li><?php endif; endforeach; ?></ul><strong><?= e(money((float) ($subscriber && $bundle['subscriberPrice'] !== null ? $bundle['subscriberPrice'] : $bundle['price']), $bundle['currency'])) ?></strong><form method="post" action="<?= e(base_url('checkout/')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="start"><input type="hidden" name="type" value="bundle"><input type="hidden" name="id" value="<?= e((string) $bundle['id']) ?>"><input type="hidden" name="mode" value="purchase"><button class="button button--primary">Buy Collection Now</button></form></div></article><?php endforeach; ?>
</div></div>
<?php require root_path('app/Views/public/partials/footer.php'); ?>

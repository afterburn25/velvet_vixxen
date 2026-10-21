<?php
declare(strict_types=1);
$publicFooterSettings = (array) app_store()->setting('site', []);
$publicFooterBrand = (string) ($publicFooterSettings['brand_name'] ?? 'Nikki Wilder');
$publicFooterSubtitle = (string) ($publicFooterSettings['brand_subtitle'] ?? 'The Velvet Vixxen');
try { $footerMenuSnapshot = app_store()->navigationMenu('footer'); }
catch (Throwable) { $footerMenuSnapshot = ['configured' => true, 'items' => []]; }
$customFooterMenu = $footerMenuSnapshot['configured'];
$publicMenuItems = $customFooterMenu ? \App\Core\MenuService::publicItems($footerMenuSnapshot) : [];
$publicMenuInstance = 'footer';
?>
</main>
<footer class="legal-footer media-footer<?= $customFooterMenu && $publicMenuItems !== [] ? ' has-custom-footer-menu' : '' ?>"><div class="brand-lockup compact"><span class="brand-script"><?= e($publicFooterBrand) ?></span><span class="brand-sub"><?= e($publicFooterSubtitle) ?></span></div><nav aria-label="Footer navigation"<?= $customFooterMenu ? ' data-menu-custom' . ($publicMenuItems === [] ? ' data-menu-empty' : '') : '' ?>><?php if ($customFooterMenu): require root_path('app/Views/public/partials/menu-items.php'); else: ?><a href="<?= e(base_url()) ?>">Home</a><a href="<?= e(base_url('music/')) ?>">Music</a><a href="<?= e(base_url('photos/')) ?>">Photos</a><a href="<?= e(base_url('videos/')) ?>">Videos</a><a href="<?= e(base_url('membership/')) ?>">Membership</a><a href="<?= e(base_url('shop/')) ?>">Store</a><a href="<?= e(base_url('messages/')) ?>">Messages</a><a href="<?= e(base_url('booking/')) ?>">Booking</a><a href="<?= e(base_url('about/')) ?>">About Me</a><?php endif; ?></nav><div class="legal-links"><a href="#">Terms</a><a href="<?= e(base_url('privacy/choices.php')) ?>">Privacy choices</a><a href="#">18+</a><a href="#">DMCA</a><a href="#">Support</a></div><span class="signature">Nikki ♡</span><p class="site-credit">Website designed &amp; developed by <a href="https://cajunwebpro.com/" target="_blank" rel="noopener noreferrer">Cajun Web Pro LLC</a></p></footer>
<?php if (empty($hidePersistentPlayer)) { require root_path('app/Views/public/partials/player.php'); } ?>
<script>window.VELVET_CONFIG=Object.assign(window.VELVET_CONFIG||{},<?= json_encode(['baseUrl'=>base_url(),'uploadBase'=>base_url('uploads/'),'ageGate'=>(bool)($publicFooterSettings['age_gate_enabled']??true),'csrfToken'=>csrf_token(),...public_route_config(),'member'=>current_member()?['id'=>(string)current_member()['id'],'displayName'=>(string)current_member()['displayName']]:null], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>);</script>
<script src="<?= e(asset_url('js/scene-geometry.js')) ?>" defer></script>
<script src="<?= e(asset_url('js/scene-depth.js')) ?>" defer></script>
<script src="<?= e(asset_url('js/site.js')) ?>" defer></script>
<script src="<?= e(asset_url('js/navigation.js')) ?>" defer></script>
<script src="<?= e(asset_url('js/media-center.js')) ?>" defer></script>
</body></html>

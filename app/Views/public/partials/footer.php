<?php
declare(strict_types=1);
$publicFooterSettings = (array) app_store()->setting('site', []);
$publicFooterBrand = (string) ($publicFooterSettings['brand_name'] ?? 'Nikki Wilder');
$publicFooterSubtitle = (string) ($publicFooterSettings['brand_subtitle'] ?? 'The Velvet Vixxen');
?>
</main>
<footer class="legal-footer media-footer"><div class="brand-lockup compact"><span class="brand-script"><?= e($publicFooterBrand) ?></span><span class="brand-sub"><?= e($publicFooterSubtitle) ?></span></div><nav><a href="<?= e(base_url()) ?>">Home</a><a href="<?= e(base_url('music/')) ?>">Music</a><a href="<?= e(base_url('photos/')) ?>">Photos</a><a href="<?= e(base_url('videos/')) ?>">Videos</a><a href="<?= e(base_url('membership/')) ?>">Membership</a><a href="<?= e(base_url('shop/')) ?>">Store</a><a href="<?= e(base_url('messages/')) ?>">Messages</a><a href="<?= e(base_url('booking/')) ?>">Booking</a><a href="<?= e(base_url('about/')) ?>">About Me</a></nav><div class="legal-links"><a href="#">Terms</a><a href="#">Privacy</a><a href="#">18+</a><a href="#">DMCA</a><a href="#">Support</a></div><span class="signature">Nikki ♡</span></footer>
<?php if (empty($hidePersistentPlayer)) { require root_path('app/Views/public/partials/player.php'); } ?>
<script>window.VELVET_CONFIG=Object.assign(window.VELVET_CONFIG||{},<?= json_encode(['baseUrl'=>base_url(),'uploadBase'=>base_url('uploads/'),'ageGate'=>(bool)($publicFooterSettings['age_gate_enabled']??true),'csrfToken'=>csrf_token(),...public_route_config(),'member'=>current_member()?['id'=>(string)current_member()['id'],'displayName'=>(string)current_member()['displayName']]:null], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>);</script>
<script src="<?= e(asset_url('js/site.js')) ?>" defer></script>
<script src="<?= e(asset_url('js/media-center.js')) ?>" defer></script>
</body></html>

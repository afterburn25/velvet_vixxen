<?php
declare(strict_types=1);
(static function (string $active): void {
    $site = (array) app_store()->setting('site', []);
    $brand = (string) ($site['brand_name'] ?? 'Nikki Wilder');
    $subtitle = (string) ($site['brand_subtitle'] ?? 'The Velvet Vixxen');
    $member = current_member();
    $items = [
        'home' => ['Home', ''], 'music' => ['Music', 'music/'], 'photos' => ['Photos', 'photos/'],
        'videos' => ['Videos', 'videos/'], 'membership' => ['Membership', 'membership/'],
        'shop' => ['Store', 'shop/'], 'booking' => ['Booking', 'booking/'],
        'about' => ['About Me', 'about/'],
    ];
?>
<a class="skip-link" href="#mainContent">Skip to content</a>
<header class="site-header public-navigation" id="top">
  <a class="brand-lockup" href="<?= e(base_url()) ?>" aria-label="<?= e($brand) ?> home"><span class="brand-script"><?= e($brand) ?></span><span class="brand-sub"><?= e($subtitle) ?></span></a>
  <button class="mobile-menu" id="mobileMenu" type="button" aria-expanded="false" aria-controls="primaryNav"><span></span><span></span><span></span><span class="sr-only">Open menu</span></button>
  <nav class="site-nav" id="primaryNav" aria-label="Primary navigation">
    <?php foreach ($items as $key => [$label, $path]): ?><a class="<?= $active === $key ? 'active' : '' ?>" <?= $active === $key ? 'aria-current="page"' : '' ?> href="<?= e(base_url($path)) ?>"><?= e($label) ?></a><?php endforeach; ?>
    <a class="mobile-account-nav" href="<?= e(base_url('cart/')) ?>">Cart<?php if (cart_service()->count() > 0): ?> (<?= cart_service()->count() ?>)<?php endif; ?></a>
    <a class="mobile-account-nav" href="<?= e(base_url($member ? 'account/' : 'account/login.php')) ?>"><?= $member ? 'My Account' : 'Login' ?></a>
  </nav>
  <div class="header-actions"><a class="header-cart" href="<?= e(base_url('cart/')) ?>" aria-label="Shopping cart">♡ <span>Cart</span><?php if (cart_service()->count() > 0): ?><b><?= cart_service()->count() ?></b><?php endif; ?></a><?php if ($member): ?><a class="header-login" href="<?= e(base_url('account/')) ?>">My Account</a><a class="button button--primary button--small" href="<?= e(base_url('account/logout.php')) ?>">Sign Out</a><?php else: ?><a class="header-login" href="<?= e(base_url('account/login.php')) ?>">Login</a><a class="button button--primary button--small" href="<?= e(base_url('account/register.php')) ?>">Join Now</a><?php endif; ?></div>
</header>
<?php })(isset($activeNav) ? (string) $activeNav : ''); ?>

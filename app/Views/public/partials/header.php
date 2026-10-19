<?php
declare(strict_types=1);

$settings = (array) app_store()->setting('site', []);
$brand = (string) ($settings['brand_name'] ?? 'Nikki Wilder');
$subtitle = (string) ($settings['brand_subtitle'] ?? 'The Velvet Vixxen');
$pageTitle = isset($pageTitle) ? (string) $pageTitle : $brand;
$activeNav = isset($activeNav) ? (string) $activeNav : '';
$nav = [
    'home' => ['Home', base_url()],
    'about' => ['About', base_url('#about')],
    'music' => ['Music', base_url('music/')],
    'photos' => ['Photos', base_url('photos/')],
    'videos' => ['Videos', base_url('videos/')],
    'membership' => ['Membership', base_url('membership/')],
    'shop' => ['Store', base_url('shop/')],
    'booking' => ['Booking', base_url('booking/')],
    'contact' => ['Contact', base_url('#contact')],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#090308">
<meta name="description" content="<?= e($pageTitle) ?> — Nikki Wilder, The Velvet Vixxen.">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($pageTitle) ?> · <?= e($brand) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('css/site.css')) ?>?v=0.8.1">
<link rel="stylesheet" href="<?= e(asset_url('css/media-center.css')) ?>?v=0.8.1">
<link rel="stylesheet" href="<?= e(asset_url('css/member.css')) ?>?v=0.8.1">
<link rel="stylesheet" href="<?= e(asset_url('css/commerce.css')) ?>?v=0.8.1">
<link rel="stylesheet" href="<?= e(asset_url('css/communication.css')) ?>?v=0.8.1">
<link rel="icon" href="<?= e(base_url('images/defaults/brand-mark.png')) ?>" type="image/png"></head>
<body class="media-page<?= !empty($hidePersistentPlayer) ? ' media-page--video' : '' ?>">
<div class="age-gate" id="ageGate" hidden>
  <div class="age-gate__backdrop"></div>
  <section class="age-gate__card" role="dialog" aria-modal="true" aria-labelledby="ageTitle">
    <div class="brand-lockup compact"><span class="brand-script"><?= e($brand) ?></span><span class="brand-sub"><?= e($subtitle) ?></span></div>
    <p class="eyebrow">Adults only</p><h1 id="ageTitle">Please confirm you are 18 or older.</h1>
    <p>This website is intended for adults. By entering, you confirm that you meet the legal age requirement where you live.</p>
    <div class="age-actions"><button class="button button--primary" id="ageEnter">I am 18+ — Enter</button><a class="button button--ghost" href="https://www.google.com">Leave</a></div>
  </section>
</div>
<header class="site-header media-header" id="top">
  <a class="brand-lockup" href="<?= e(base_url()) ?>" aria-label="<?= e($brand) ?> home"><span class="brand-script"><?= e($brand) ?></span><span class="brand-sub"><?= e($subtitle) ?></span></a>
  <button class="mobile-menu" id="mobileMenu" aria-expanded="false" aria-controls="primaryNav"><span></span><span></span><span></span><span class="sr-only">Open menu</span></button>
  <nav class="site-nav" id="primaryNav" aria-label="Primary navigation">
    <?php foreach ($nav as $key => [$label, $href]): ?><a class="<?= $activeNav === $key ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a><?php endforeach; ?>
  </nav>
  <?php $headerMember = current_member(); ?>
  <div class="header-actions"><a class="header-cart" href="<?= e(base_url('cart/')) ?>" aria-label="Shopping cart">♡ <span>Cart</span><?php if (cart_service()->count() > 0): ?><b><?= e((string) cart_service()->count()) ?></b><?php endif; ?></a><?php if ($headerMember): ?><a class="header-login" href="<?= e(base_url('account/')) ?>">My Account</a><a class="button button--primary button--small" href="<?= e(base_url('account/logout.php')) ?>">Sign Out</a><?php else: ?><a class="header-login" href="<?= e(base_url('account/login.php')) ?>">Login</a><a class="button button--primary button--small" href="<?= e(base_url('account/register.php')) ?>">Join Now</a><?php endif; ?></div>
</header>
<main class="media-main">

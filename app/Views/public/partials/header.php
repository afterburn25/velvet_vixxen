<?php
declare(strict_types=1);

$publicHeaderSettings = (array) app_store()->setting('site', []);
$publicHeaderBrand = (string) ($publicHeaderSettings['brand_name'] ?? 'Nikki Wilder');
$publicHeaderSubtitle = (string) ($publicHeaderSettings['brand_subtitle'] ?? 'The Velvet Vixxen');
$pageTitle = isset($pageTitle) ? (string) $pageTitle : $publicHeaderBrand;
$activeNav = isset($activeNav) ? (string) $activeNav : '';

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#090308">
<meta name="description" content="<?= e($pageTitle) ?> — Nikki Wilder, The Velvet Vixxen.">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($pageTitle) ?> · <?= e($publicHeaderBrand) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('css/site.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/media-center.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/member.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/commerce.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/communication.css')) ?>">
<link rel="icon" href="<?= e(base_url('images/defaults/brand-mark.png')) ?>" type="image/png"><?php if (!empty($marketingLayout)): ?><link rel="stylesheet" href="<?= e(asset_url('css/marketing.css')) ?>"><?php endif; ?><link rel="stylesheet" href="<?= e(asset_url('css/site-ux.css')) ?>"></head>
<body class="media-page<?= !empty($authLayout) ? ' auth-page' : '' ?><?= !empty($marketingLayout) ? ' marketing-page' : '' ?><?= !empty($hidePersistentPlayer) ? ' media-page--video' : '' ?>">
<div class="age-gate" id="ageGate" hidden>
  <div class="age-gate__backdrop"></div>
  <section class="age-gate__card" role="dialog" aria-modal="true" aria-labelledby="ageTitle">
    <div class="brand-lockup compact"><span class="brand-script"><?= e($publicHeaderBrand) ?></span><span class="brand-sub"><?= e($publicHeaderSubtitle) ?></span></div>
    <p class="eyebrow">Adults only</p><h1 id="ageTitle">Please confirm you are 18 or older.</h1>
    <p>This website is intended for adults. By entering, you confirm that you meet the legal age requirement where you live.</p>
    <div class="age-actions"><button class="button button--primary" id="ageEnter">I am 18+ — Enter</button><a class="button button--ghost" href="https://www.google.com">Leave</a></div>
  </section>
</div>
<?php require root_path('app/Views/public/partials/navigation.php'); ?>
<main class="media-main" id="mainContent" tabindex="-1">

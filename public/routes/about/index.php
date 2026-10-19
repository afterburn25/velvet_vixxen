<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 3));
require ROOT_PATH . '/app/bootstrap.php';
if (!is_installed()) redirect(base_url('install/'));
$about = (new App\Core\AboutPage(app_store()))->details();
$aboutStats = array_values(array_filter($about['statistics'], static fn(array $stat): bool => trim((string) ($stat['value'] ?? '')) !== ''));
$aboutHasSidebar = $about['imageFile'] !== '' || $aboutStats !== [];
$pageTitle = (string) $about['title'];
$activeNav = 'about';
require root_path('app/Views/public/partials/header.php');
?>
<section class="about-page" aria-labelledby="aboutTitle">
  <header class="about-heading"><span class="eyebrow pink">Nikki Wilder · The Velvet Vixxen</span><h1 id="aboutTitle"><?= e($about['title']) ?></h1></header>
  <div class="about-layout <?= $aboutHasSidebar ? '' : 'about-layout--biography' ?>">
    <?php if ($aboutHasSidebar): ?>
    <aside class="about-sidebar" aria-label="Details about Nikki">
      <?php if ($about['imageFile'] !== ''): ?><img class="about-photo" src="<?= e(scene_asset_url($about['imageFile'])) ?>" alt="<?= e($about['imageAlt']) ?>"><?php endif; ?>
      <dl class="about-statistics"><?php foreach ($aboutStats as $stat): ?><div><dt><?= e((string) $stat['label']) ?></dt><dd><?= e((string) $stat['value']) ?></dd></div><?php endforeach; ?></dl>
    </aside>
    <?php endif; ?>
    <article class="about-biography">
      <?php foreach (preg_split('/\R\s*\R/u', (string) $about['biography']) ?: [] as $paragraph): if (trim($paragraph) === '') continue; ?><p><?= nl2br(e($paragraph)) ?></p><?php endforeach; ?>
      <?php if ($about['ctaLabel'] !== '' && $about['ctaUrl'] !== ''): $aboutCta = vvx_public_destination(base_url(), (string) $about['ctaUrl']); ?><a class="button button--primary" href="<?= e($aboutCta) ?>"><?= e($about['ctaLabel']) ?></a><?php endif; ?>
    </article>
  </div>
</section>
<?php require root_path('app/Views/public/partials/footer.php'); ?>

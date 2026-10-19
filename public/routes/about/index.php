<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 3));
require ROOT_PATH . '/app/bootstrap.php';
if (!is_installed()) redirect(base_url('install/'));
$about = (new App\Core\AboutPage(app_store()))->details();
$pageTitle = (string) $about['title'];
$activeNav = 'about';
require root_path('app/Views/public/partials/header.php');
?>
<section class="about-page content-wrap" aria-labelledby="aboutTitle">
  <header class="about-heading"><span class="eyebrow pink">Nikki Wilder · The Velvet Vixxen</span><h1 id="aboutTitle"><?= e($about['title']) ?></h1></header>
  <div class="about-layout">
    <aside class="about-sidebar" aria-label="Details about Nikki">
      <?php if ($about['imageFile'] !== ''): ?><img class="about-photo" src="<?= e(scene_asset_url($about['imageFile'])) ?>" alt="<?= e($about['imageAlt']) ?>"><?php endif; ?>
      <dl class="about-statistics"><?php foreach ($about['statistics'] as $stat): if (trim((string) ($stat['value'] ?? '')) === '') continue; ?><div><dt><?= e((string) $stat['label']) ?></dt><dd><?= e((string) $stat['value']) ?></dd></div><?php endforeach; ?></dl>
    </aside>
    <article class="about-biography">
      <?php foreach (preg_split('/\R\s*\R/u', (string) $about['biography']) ?: [] as $paragraph): if (trim($paragraph) === '') continue; ?><p><?= nl2br(e($paragraph)) ?></p><?php endforeach; ?>
      <?php if ($about['ctaLabel'] !== '' && $about['ctaUrl'] !== ''): $aboutCta = str_starts_with($about['ctaUrl'], '/') ? base_url(ltrim($about['ctaUrl'], '/')) : $about['ctaUrl']; ?><a class="button button--primary" href="<?= e($aboutCta) ?>"><?= e($about['ctaLabel']) ?></a><?php endif; ?>
    </article>
  </div>
</section>
<?php require root_path('app/Views/public/partials/footer.php'); ?>

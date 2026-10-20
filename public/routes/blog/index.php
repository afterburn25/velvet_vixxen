<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require_once ROOT_PATH . '/includes/marketing_public_shell.php';
$posts = velvet_marketing_store()->publishedPosts(null, velvet_marketing_viewer());
velvet_marketing_public_header('Latest Updates', 'News, music, galleries, videos and personal updates from Nikki Wilder.');
?>
<div class="marketing-shell">
  <section class="marketing-hero"><span class="marketing-eyebrow">Inside the Velvet World</span><h1>Latest Updates</h1><p>New releases, stories, behind-the-scenes notes and announcements—all in one place.</p></section>
  <section class="marketing-grid" aria-label="Published updates">
  <?php if (!$posts): ?><article class="marketing-card"><h2>Updates are coming soon</h2><p>Join the mailing list to be first to know when something new is published.</p><a class="marketing-button" href="<?= velvet_marketing_escape(base_url('newsletter/')) ?>">Join the list</a></article><?php endif; ?>
  <?php foreach ($posts as $post): ?>
    <article class="marketing-card"><div class="marketing-meta"><?php $publishedDate = \Velvet\Core\MarketingPublication::storedDate($post['publish_at'] ?? null); if ($publishedDate !== null): ?><span><?= velvet_marketing_escape($publishedDate->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('M j, Y')) ?></span><?php endif; ?><span><?= velvet_marketing_escape((string)($post['category'] ?? 'Update')) ?></span></div><h2><?= velvet_marketing_escape((string)$post['title']) ?></h2><p><?= velvet_marketing_escape((string)($post['excerpt'] ?? '')) ?></p><a class="marketing-button" href="<?= velvet_marketing_escape(base_url('blog/post.php?slug=' . rawurlencode((string)$post['slug']))) ?>">Read More</a></article>
  <?php endforeach; ?>
  </section>
</div>
<?php velvet_marketing_public_footer(); ?>

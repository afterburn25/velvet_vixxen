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
    <?php velvet_marketing_render_post_card($post, velvet_marketing_editorial_presentation('posts', $post)); ?>
  <?php endforeach; ?>
  </section>
</div>
<?php velvet_marketing_public_footer(); ?>

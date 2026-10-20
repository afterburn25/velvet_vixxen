<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require_once ROOT_PATH . '/includes/marketing_public_shell.php';
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_GET['slug'] ?? '')));
$post = velvet_marketing_store()->findPost($slug);
if (!$post) { http_response_code(404); velvet_marketing_public_header('Update Not Found'); echo '<div class="marketing-shell"><article class="marketing-card"><h1>Update not found</h1><p>This update is unavailable or has not been published yet.</p><a class="marketing-button" href="' . velvet_marketing_escape(base_url('blog/')) . '">Back to Updates</a></article></div>'; velvet_marketing_public_footer(); exit; }
velvet_marketing_public_header((string)$post['title'], (string)($post['excerpt'] ?? ''));
?>
<div class="marketing-shell"><article class="marketing-card"><div class="marketing-meta"><?php $publishedDate = \Velvet\Core\MarketingPublication::storedDate($post['publish_at'] ?? null); if ($publishedDate !== null): ?><span><?= velvet_marketing_escape($publishedDate->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('F j, Y')) ?></span><?php endif; ?><span><?= velvet_marketing_escape((string)($post['category'] ?? 'Update')) ?></span></div><h1 class="marketing-title"><?= velvet_marketing_escape((string)$post['title']) ?></h1><div class="marketing-content"><?= nl2br(velvet_marketing_escape((string)($post['body'] ?? ''))) ?></div></article></div>
<?php velvet_marketing_public_footer(); ?>

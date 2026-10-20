<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require_once ROOT_PATH . '/includes/marketing_public_shell.php';
$slug=preg_replace('/[^a-z0-9-]/','',strtolower((string)($_GET['slug']??''))); $page=velvet_marketing_store()->activeLandingPage($slug);
if(!$page){http_response_code(404);velvet_marketing_public_header('Campaign Not Found');echo '<div class="marketing-shell"><article class="marketing-card"><h1>This offer is unavailable</h1><p>It may not have started yet or may have ended.</p></article></div>';velvet_marketing_public_footer();exit;}
velvet_marketing_store()->recordEvent('campaign_view',['campaign'=>$slug]); velvet_marketing_public_header((string)$page['title'],(string)($page['excerpt']??''));
?><div class="marketing-shell"><section class="marketing-hero"><span class="marketing-eyebrow"><?= velvet_marketing_escape((string)($page['eyebrow']??'Special Offer')) ?></span><h1><?= velvet_marketing_escape((string)$page['title']) ?></h1><p><?= velvet_marketing_escape((string)($page['body']??'')) ?></p><?php if(!empty($page['cta_url'])): ?><a class="marketing-button" data-marketing-event="cta_click" href="<?= velvet_marketing_escape(velvet_marketing_destination((string)$page['cta_url'])) ?>"><?= velvet_marketing_escape((string)($page['cta_label']??'Learn More')) ?></a><?php endif; ?></section></div><?php velvet_marketing_public_footer(); ?>

<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require_once ROOT_PATH . '/includes/marketing_public_shell.php';
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower(is_string($_GET['slug'] ?? null) ? $_GET['slug'] : ''));
$page = velvet_marketing_store()->activeLandingPage($slug, velvet_marketing_viewer());
if(!$page){http_response_code(404);velvet_marketing_public_header('Campaign Not Found');echo '<div class="marketing-shell"><article class="marketing-card"><h1>This offer is unavailable</h1><p>It may not have started yet or may have ended.</p></article></div>';velvet_marketing_public_footer();exit;}
velvet_marketing_store()->recordEvent('campaign_view',['campaign'=>$slug]); velvet_marketing_public_header((string)$page['title'],(string)($page['excerpt']??''));
echo '<div class="marketing-shell">';
velvet_marketing_render_landing_page($page);
echo '</div>';
velvet_marketing_public_footer();

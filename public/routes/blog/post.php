<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require_once ROOT_PATH . '/includes/marketing_public_shell.php';
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower(is_string($_GET['slug'] ?? null) ? $_GET['slug'] : ''));
$post = velvet_marketing_store()->findPost($slug, velvet_marketing_viewer());
if (!$post) { http_response_code(404); velvet_marketing_public_header('Update Not Found'); echo '<div class="marketing-shell"><article class="marketing-card"><h1>Update not found</h1><p>This update is unavailable or has not been published yet.</p><a class="marketing-button" href="' . velvet_marketing_escape(base_url('blog/')) . '">Back to Updates</a></article></div>'; velvet_marketing_public_footer(); exit; }
velvet_marketing_public_header((string)$post['title'], (string)($post['excerpt'] ?? ''));
echo '<div class="marketing-shell">';
velvet_marketing_render_post($post);
echo '</div>';
velvet_marketing_public_footer();

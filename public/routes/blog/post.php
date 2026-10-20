<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { define('ROOT_PATH', dirname(__DIR__, 3)); }
require_once ROOT_PATH . '/includes/marketing_public_shell.php';
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower(is_string($_GET['slug'] ?? null) ? $_GET['slug'] : ''));
$viewer = velvet_marketing_viewer();
$post = velvet_marketing_store()->findPost($slug, $viewer);
if (!$post) { http_response_code(404); velvet_marketing_public_header('Update Not Found'); echo '<div class="marketing-shell"><article class="marketing-card"><h1>Update not found</h1><p>This update is unavailable or has not been published yet.</p><a class="marketing-button" href="' . velvet_marketing_escape(base_url('blog/')) . '">Back to Updates</a></article></div>'; velvet_marketing_public_footer(); exit; }
$postPresentation = velvet_marketing_editorial_presentation('posts', $post);
$relatedPresentations = [];
try {
    foreach (velvet_marketing_store()->relatedPosts(velvet_marketing_record_text($post, 'id'), $viewer) as $relatedPost) {
        $relatedPresentations[] = ['record' => $relatedPost, 'presentation' => velvet_marketing_editorial_presentation('posts', $relatedPost)];
    }
} catch (Throwable) { $relatedPresentations = []; }
velvet_marketing_public_header((string)$post['title'], (string)($post['excerpt'] ?? ''), velvet_marketing_editorial_head($post, $postPresentation));
echo '<div class="marketing-shell">';
velvet_marketing_render_post($post, $postPresentation, $relatedPresentations);
echo '</div>';
velvet_marketing_public_footer();

<?php
declare(strict_types=1);

require_once root_path('includes/marketing.php');
require_once root_path('includes/marketing_admin.php');
velvet_marketing_private_response(true);

$previewDeny = static function (int $status): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Preview unavailable.');
};
if (!is_installed() || !velvet_marketing_is_owner()) $previewDeny(403);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    $previewDeny(405);
}
try {
    $previewCollection = $_GET['collection'] ?? null;
    if (!is_string($previewCollection)) throw new InvalidArgumentException('Invalid collection.');
    \Velvet\Core\MarketingPublication::collection($previewCollection);
    $previewId = \Velvet\Core\MarketingPublication::id($_GET['id'] ?? null);
    $previewRecord = \Velvet\Core\MarketingStore::previewPublication($previewCollection, $previewId, ROOT_PATH);
    if ($previewRecord === null) $previewDeny(404);
    $previewPresentation = null;
    $previewRelated = [];
    if ($previewCollection !== 'banners') {
        $previewPresentation = velvet_marketing_editorial_presentation($previewCollection, $previewRecord);
        if ($previewCollection === 'posts') {
            foreach (\Velvet\Core\MarketingStore::previewRelatedPosts($previewId, ROOT_PATH) as $relatedPost) {
                $previewRelated[] = ['record' => $relatedPost, 'presentation' => velvet_marketing_editorial_presentation('posts', $relatedPost)];
            }
        }
    }
} catch (Throwable) { $previewDeny(404); }
$previewMeta = mm_publication_meta($previewCollection);
$previewCatalog = mm_publication_access_catalog();
$previewAccess = \Velvet\Core\MarketingAccess::describe($previewRecord, $previewCatalog['available_plan_ids']);
$previewEditUrl = base_url('admin/?page=marketing&tab=' . $previewMeta['tab'] . '&' . $previewMeta['edit'] . '=' . rawurlencode($previewId));
// Saved content, related posts, artwork and tier-catalog reads may have waited. Recheck the owner
// after all of them, immediately before the private response is rendered.
if (!velvet_marketing_is_owner()) $previewDeny(403);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#090308">
<title>Saved Preview · Velvet Vixxen</title>
<link rel="stylesheet" href="<?= velvet_marketing_escape(asset_url('css/marketing.css')) ?>">
<link rel="icon" href="<?= velvet_marketing_escape(base_url('images/defaults/brand-mark.png')) ?>" type="image/png">
</head>
<body class="marketing-page">
<main class="marketing-shell marketing-owner-preview" data-marketing-preview>
  <header class="marketing-card marketing-preview-toolbar">
    <span class="marketing-eyebrow">Private owner preview</span>
    <h1>Saved Preview</h1>
    <p>This is the last saved <?= $previewCollection === 'posts' ? 'post' : ($previewCollection === 'banners' ? 'banner' : 'landing page') ?>. Unsaved edits are not included. Only the active site owner can open this preview.</p>
    <p data-preview-publication-state><strong><?= velvet_marketing_escape(mm_form_text($previewRecord['visibility']['state'] ?? 'Needs review')) ?></strong> · <?= velvet_marketing_escape(mm_publication_access_summary($previewAccess, $previewCatalog)) ?></p>
    <?php if (!$previewAccess['valid']): ?><p class="marketing-notice" data-editorial-access-problem><?= velvet_marketing_escape($previewAccess['problem'] ?? 'Access needs review.') ?> Public visibility stays restricted until repaired.</p><?php endif; ?>
    <p>Buttons and links in the content are shown for review. This preview does not publish content, record a visit or send email.</p>
    <a class="marketing-button" data-preview-back href="<?= velvet_marketing_escape($previewEditUrl) ?>">Back to Edit</a>
  </header>
  <?php if ($previewPresentation !== null): $previewSearch = velvet_marketing_editorial_head($previewRecord, $previewPresentation); ?>
  <section class="marketing-card marketing-preview-search" data-preview-editorial-seo aria-labelledby="preview-search-title">
    <span class="marketing-eyebrow">Saved search appearance</span>
    <h2 id="preview-search-title"><?= velvet_marketing_escape($previewSearch['title']) ?></h2>
    <?php if ($previewSearch['description'] !== ''): ?><p><?= velvet_marketing_escape($previewSearch['description']) ?></p><?php else: ?><p>No excerpt or search description is saved.</p><?php endif; ?>
    <p class="marketing-field-help">Search appearance does not change publication status or who can view this content.</p>
    <?php foreach ($previewPresentation['problems'] as $previewProblem): ?><p class="marketing-notice"><?= velvet_marketing_escape($previewProblem) ?></p><?php endforeach; ?>
    <?php if ($previewPresentation['values']['featured_asset_id'] !== '' && $previewPresentation['image'] === null): ?><p class="marketing-notice" data-preview-image-unavailable>The saved featured image is unavailable. Choose a current public image or clear it in the editor.</p><?php endif; ?>
  </section>
  <?php endif; ?>
  <div data-preview-content="<?= velvet_marketing_escape($previewCollection) ?>" data-preview-id="<?= velvet_marketing_escape($previewId) ?>">
    <?php
    if ($previewCollection === 'posts') velvet_marketing_render_post($previewRecord, $previewPresentation, $previewRelated, true);
    elseif ($previewCollection === 'banners') velvet_marketing_render_banner_record($previewRecord, true);
    else velvet_marketing_render_landing_page($previewRecord, true, $previewPresentation);
    ?>
  </div>
</main>
</body>
</html>

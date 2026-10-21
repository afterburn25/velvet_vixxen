<?php
declare(strict_types=1);

require_once root_path('includes/marketing.php');
require_once root_path('includes/marketing_admin.php');
velvet_marketing_private_response(true);

$historyDeny = static function (int $status): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('History unavailable.');
};
if (!is_installed() || !velvet_marketing_is_owner()) $historyDeny(403);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    $historyDeny(405);
}
try {
    $historyCollection = $_GET['collection'] ?? null;
    if (!is_string($historyCollection)) throw new InvalidArgumentException('Invalid collection.');
    \Velvet\Core\MarketingPublication::collection($historyCollection);
    $historyId = \Velvet\Core\MarketingPublication::id($_GET['id'] ?? null);
    $historyVersion = array_key_exists('version', $_GET) ? \Velvet\Core\MarketingHistory::id($_GET['version']) : null;
    $history = \Velvet\Core\MarketingStore::previewPublicationHistory($historyCollection, $historyId, $historyVersion, ROOT_PATH);
    if ($history === null) $historyDeny(404);
} catch (Throwable) { $historyDeny(404); }

$historyMeta = mm_publication_meta($historyCollection);
$historyCurrent = $history['current'];
$historySelected = $history['selected'];
$historyEarlier = null;
$historyProblem = '';
if ($historySelected !== null) {
    try { $historyEarlier = \Velvet\Core\MarketingHistory::project($historyCollection, $historyCurrent, $historySelected); }
    catch (Throwable) { $historyProblem = 'This saved version contains fields that need repair and cannot be restored. Your current saved content is unchanged.'; }
}
$historyCatalog = mm_publication_access_catalog();
$historyPresentations = ['current' => null, 'earlier' => null];
if ($historyCollection !== 'banners') {
    $historyPresentations['current'] = velvet_marketing_editorial_presentation($historyCollection, $historyCurrent);
    if ($historyEarlier !== null) $historyPresentations['earlier'] = velvet_marketing_editorial_presentation($historyCollection, $historyEarlier);
}
$historyRelatedTitles = $history['related_titles'] ?? [];
$historyEditUrl = base_url('admin/?page=marketing&tab=' . $historyMeta['tab'] . '&' . $historyMeta['edit'] . '=' . rawurlencode($historyId));
$historyRestoreUrl = base_url('admin/?page=marketing&tab=' . $historyMeta['tab']);
$historyTitle = mm_form_text($historyCurrent[$historyCollection === 'banners' ? 'text' : 'title'] ?? '', 1000);
$historyTimezone = date_default_timezone_get();
$historyDate = static function (string $value) use ($historyTimezone): string {
    try { return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone($historyTimezone))->format('M j, Y · g:i:s A T'); }
    catch (Throwable) { return 'Date unavailable'; }
};
$historyAction = static fn(string $action): string => match ($action) {
    'visibility' => 'Before visibility change', 'restore' => 'Before restore', default => 'Before content edit',
};
$historyLabels = match ($historyCollection) {
    'posts' => ['title' => 'Title', 'category' => 'Category', 'excerpt' => 'Excerpt', 'body' => 'Body',
        'author_name' => 'Author display name', 'tags' => 'Tags'],
    'banners' => ['text' => 'Banner text', 'url' => 'Link'],
    'landing_pages' => ['title' => 'Title', 'eyebrow' => 'Eyebrow', 'excerpt' => 'Excerpt', 'body' => 'Body',
        'cta_label' => 'Button label', 'cta_url' => 'Button link'],
};
if ($historyCollection !== 'banners') {
    $historyLabels += ['featured_asset_id' => 'Featured image', 'featured_alt' => 'Image description',
        'seo_title' => 'Search title', 'seo_description' => 'Search description'];
}
if ($historyCollection === 'posts') $historyLabels['related_post_ids'] = 'Related updates';
$historyLabels['access'] = 'Who can view this?';
$historyLabels += $historyCollection === 'posts'
    ? ['status' => 'Saved status', 'publish_at' => 'Publish at', 'expires_at' => 'Expires at']
    : ['enabled' => 'Saved visibility', 'starts_at' => 'Starts', 'ends_at' => 'Ends'];
$historyField = static function (array $record, ?array $presentation, string $key) use ($historyCollection, $historyCatalog, $historyRelatedTitles): string {
    if ($key === 'access') return mm_publication_access_summary(\Velvet\Core\MarketingAccess::describe($record, $historyCatalog['available_plan_ids']), $historyCatalog);
    if ($key === 'enabled') return \Velvet\Core\MarketingPublication::enabled($historyCollection, $record) ? 'Enabled' : 'Disabled';
    if (in_array($key, ['publish_at', 'expires_at', 'starts_at', 'ends_at'], true)) {
        try {
            $date = \Velvet\Core\MarketingPublication::dateForForm($record[$key] ?? null);
            return $date !== '' ? str_replace('T', ' ', $date) : ($key === 'expires_at' ? 'No expiry' : 'Open');
        } catch (Throwable) { return 'Needs review'; }
    }
    $value = $presentation['values'][$key] ?? $record[$key] ?? '';
    if ($key === 'tags') return is_array($value) ? implode(', ', $value) : 'Needs review';
    if ($key === 'related_post_ids') {
        if (!is_array($value)) return 'Needs review';
        return implode("\n", array_map(static fn(string $id): string => $historyRelatedTitles[$id] ?? 'Unavailable saved update', $value));
    }
    if ($key === 'featured_asset_id') {
        if ($value === '') return 'No featured image';
        return $presentation['image']['title'] ?? 'Unavailable saved image';
    }
    return mm_form_text($value);
};
$historyValues = ['current' => [], 'earlier' => []];
foreach ($historyLabels as $key => $label) {
    $historyValues['current'][$key] = $historyField($historyCurrent, $historyPresentations['current'], $key);
    if ($historyEarlier !== null) $historyValues['earlier'][$key] = $historyField($historyEarlier, $historyPresentations['earlier'], $key);
}
// History, image and tier reads may wait. No private version is rendered after
// the saved owner has been demoted or deactivated during those reads.
if (!velvet_marketing_is_owner()) $historyDeny(403);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#090308">
<title>Publication History · Velvet Vixxen</title>
<link rel="stylesheet" href="<?= velvet_marketing_escape(asset_url('css/marketing.css')) ?>">
<link rel="icon" href="<?= velvet_marketing_escape(base_url('images/defaults/brand-mark.png')) ?>" type="image/png">
</head>
<body class="marketing-page">
<main class="marketing-shell marketing-owner-preview marketing-history-shell" data-marketing-history>
  <header class="marketing-card marketing-preview-toolbar">
    <span class="marketing-eyebrow">Private owner workspace</span>
    <h1>Publication History</h1>
    <p class="marketing-history-title"><?= velvet_marketing_escape($historyTitle) ?></p>
    <p>Review an earlier saved version before restoring it. Restoring replaces the saved content and leaves this <?= $historyCollection === 'posts' ? 'post as a draft' : 'item disabled' ?>, ready for your review.</p>
    <div class="marketing-publication-review-links"><a class="marketing-button" data-history-back href="<?= velvet_marketing_escape($historyEditUrl) ?>">Back to Edit</a><a class="marketing-button marketing-button-secondary" href="<?= velvet_marketing_escape(mm_publication_preview_url($historyCollection, $historyId)) ?>" target="_blank" rel="noopener noreferrer">Preview Saved</a></div>
    <p class="marketing-field-help">Unsaved editor changes are not included. Reviewing history does not publish content, record a visit or send email.</p>
  </header>
  <div class="marketing-history-layout">
    <section class="marketing-card marketing-history-list" data-publication-history-list aria-labelledby="history-list-heading">
      <span class="marketing-eyebrow">Earlier versions</span>
      <h2 id="history-list-heading">Saved history <span class="marketing-history-count"><?= count($history['entries']) ?></span></h2>
      <p>Up to <?= (int) $history['limit'] ?> earlier versions are kept for this item. The oldest is removed when the limit is reached.</p>
      <?php if ($history['entries'] === []): ?>
      <div class="marketing-history-empty" data-history-empty><h3>Your next edit starts the history</h3><p>There are no earlier saved versions yet. A content or visibility change will keep the version it replaces. History cannot recover changes made before this feature was installed.</p></div>
      <?php else: ?>
      <ol class="marketing-history-timeline">
        <?php foreach ($history['entries'] as $historyEntry): ?>
        <li data-history-entry="<?= velvet_marketing_escape($historyEntry['id']) ?>" <?= $historyVersion === $historyEntry['id'] ? 'class="is-selected"' : '' ?>>
          <span class="marketing-history-action"><?= velvet_marketing_escape($historyAction($historyEntry['action'])) ?></span>
          <strong><?= velvet_marketing_escape($historyEntry['title']) ?></strong>
          <time datetime="<?= velvet_marketing_escape($historyEntry['captured_at']) ?>"><?= velvet_marketing_escape($historyDate($historyEntry['captured_at'])) ?></time>
          <a class="marketing-button marketing-button-secondary" data-history-review-link href="<?= velvet_marketing_escape(mm_publication_history_url($historyCollection, $historyId, $historyEntry['id'])) ?>" <?= $historyVersion === $historyEntry['id'] ? 'aria-current="page"' : '' ?>>Review version</a>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
      <p class="marketing-field-help">Times use <?= velvet_marketing_escape($historyTimezone) ?>. Deleting the item also removes its history.</p>
    </section>
    <section class="marketing-card marketing-history-review" data-history-review aria-labelledby="history-review-heading">
      <span class="marketing-eyebrow"><?= $historySelected !== null ? 'Compare saved content' : 'Current saved content' ?></span>
      <h2 id="history-review-heading"><?= $historySelected !== null ? 'Review this version' : 'Choose a version to compare' ?></h2>
      <?php if ($historySelected !== null): ?>
      <p>Captured <?= velvet_marketing_escape($historyDate($historySelected['captured_at'])) ?> · <?= velvet_marketing_escape($historyAction($historySelected['action'])) ?>.</p>
      <?php else: ?><p>Your current saved content is shown below. Choose <strong>Review version</strong> beside an earlier version to see its content and restore controls.</p><?php endif; ?>
      <?php if ($historyProblem !== ''): ?><p class="marketing-notice" role="alert" data-history-problem><?= velvet_marketing_escape($historyProblem) ?></p><?php endif; ?>
      <?php if ($historyEarlier !== null): ?>
      <form method="post" action="<?= velvet_marketing_escape($historyRestoreUrl) ?>" class="marketing-history-restore" data-history-restore>
        <?= csrf_field() ?><input type="hidden" name="action" value="restore_publication">
        <input type="hidden" name="collection" value="<?= velvet_marketing_escape($historyCollection) ?>">
        <input type="hidden" name="id" value="<?= velvet_marketing_escape($historyId) ?>">
        <input type="hidden" name="history_id" value="<?= velvet_marketing_escape($historySelected['id']) ?>">
        <input type="hidden" name="publication_revision" value="<?= velvet_marketing_escape($historyCurrent['revision']) ?>">
        <p><strong>Ready to restore?</strong> The current version will be kept in History. The saved address stays the same. Review dates, access and images in the editor before publishing again.</p>
        <button class="marketing-button" type="submit"><?= $historyCollection === 'posts' ? 'Restore as draft' : 'Restore disabled' ?></button>
        <p class="marketing-field-help">Images, related updates and membership tiers must still be available. If they cannot be verified, restoration is refused and your current content stays saved.</p>
      </form>
      <?php endif; ?>
      <div class="marketing-history-comparison <?= $historyEarlier === null ? 'is-single' : '' ?>" data-history-content>
        <?php foreach ($historyEarlier !== null ? ['current' => 'Current saved version', 'earlier' => 'Earlier saved version'] : ['current' => 'Current saved version'] as $side => $sideLabel):
          $sideRecord = $side === 'current' ? $historyCurrent : $historyEarlier;
          $sidePresentation = $historyPresentations[$side];
        ?>
        <section class="marketing-history-version" <?= $side === 'current' ? 'data-history-current' : 'data-history-selected' ?>>
          <h3><?= $sideLabel ?></h3>
          <?php if ($sidePresentation !== null && $sidePresentation['image'] !== null): velvet_marketing_render_editorial_image($sideRecord, $sidePresentation); endif; ?>
          <dl>
            <?php foreach ($historyLabels as $key => $label):
              $changed = $historyEarlier !== null && $historyValues['current'][$key] !== $historyValues['earlier'][$key];
              $value = $historyValues[$side][$key];
            ?>
            <div class="marketing-history-field <?= $changed ? 'is-changed' : '' ?>" data-history-field="<?= velvet_marketing_escape($key) ?>">
              <dt><?= velvet_marketing_escape($label) ?><?php if ($changed): ?><span>Changed</span><?php endif; ?></dt>
              <dd class="marketing-history-value" <?= strlen($value) > 1000 ? 'tabindex="0" aria-label="' . velvet_marketing_escape($label . ' text') . '"' : '' ?>><?= $value !== '' ? velvet_marketing_escape($value) : 'Not set' ?></dd>
            </div>
            <?php endforeach; ?>
          </dl>
        </section>
        <?php endforeach; ?>
      </div>
      <p class="marketing-field-help">Dates shown in <?= velvet_marketing_escape($historyTimezone) ?>. Links and related updates are displayed as text for review.</p>
    </section>
  </div>
</main>
</body>
</html>

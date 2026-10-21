<?php
declare(strict_types=1);
$deletedReview = $recoverySnapshot['review'];
$deletedSelected = $deletedReview['selected'];
$deletedLabels = ['all' => 'All content', 'posts' => 'Posts', 'banners' => 'Banners', 'landing_pages' => 'Landing pages'];
?>
<div class="marketing-deleted" data-editorial-recovery>
  <header class="marketing-card marketing-deleted-intro">
    <div>
      <span class="marketing-eyebrow">Private owner workspace</span>
      <h2>Deleted content</h2>
      <p>Bring a removed post, banner or landing page back to your workspace. Restored posts become drafts; banners and landing pages return disabled, ready for your review.</p>
      <p class="marketing-deleted-help">Items stay here until you restore or permanently remove them. Nothing is automatically published or discarded.</p>
    </div>
    <div class="marketing-deleted-capacity" data-recovery-capacity><strong><?= (int) $deletedReview['total'] ?><span> / <?= (int) $deletedReview['limit'] ?></span></strong><span>items saved</span><small><?= number_format($deletedReview['bytes'] / 1048576, 1) ?> of <?= (int) ($deletedReview['max_bytes'] / 1048576) ?> MB used</small></div>
  </header>
  <?php if ($deletedSelected === null): ?>
  <section class="marketing-card" aria-labelledby="deleted-list-heading">
    <div class="marketing-deleted-list-heading"><h3 id="deleted-list-heading">Saved for recovery</h3>
      <form class="marketing-form marketing-deleted-filter" method="get" action="<?= e(base_url('admin/')) ?>">
        <input type="hidden" name="page" value="marketing"><input type="hidden" name="tab" value="deleted">
        <label for="deleted-content-type">Content type</label>
        <select id="deleted-content-type" name="collection"><?php foreach ($deletedLabels as $key => $label): ?><option value="<?= $key ?>" <?= ($recoveryRequest['collection'] ?? 'all') === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
        <button class="marketing-button marketing-button-secondary">Apply filter</button>
      </form>
    </div>
    <?php if ($deletedReview['entries'] === []): ?>
    <div class="marketing-deleted-empty" data-recovery-empty><span aria-hidden="true">↶</span><h3><?= $deletedReview['total'] === 0 ? 'Nothing to recover yet' : 'No deleted items of this type' ?></h3><p>Items you remove from Posts, Banners or Landing pages appear here with their saved history. Items permanently deleted before recovery was available cannot be recovered here.</p><a class="marketing-button marketing-button-secondary" href="<?= e(base_url('admin/?page=marketing&tab=posts')) ?>">Open Posts</a></div>
    <?php else: ?>
    <ol class="marketing-deleted-list" data-recovery-list>
      <?php foreach ($deletedReview['entries'] as $deletedEntry): ?>
      <li data-recovery-item="<?= e($deletedEntry['id']) ?>" data-recovery-collection="<?= e($deletedEntry['collection']) ?>">
        <div><span class="marketing-deleted-kind"><?= $deletedLabels[$deletedEntry['collection']] ?></span><h4><?= e($deletedEntry['title']) ?></h4><p>Deleted <time datetime="<?= e($deletedEntry['deleted_at']) ?>"><?= e(vvx_marketing_recovery_date($deletedEntry['deleted_at'])) ?></time></p></div>
        <a class="marketing-button marketing-button-secondary" data-recovery-review-link href="<?= e(vvx_marketing_recovery_url($deletedEntry['collection'], $deletedEntry['id'])) ?>">Review item</a>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
    <p class="marketing-deleted-help">Up to <?= (int) $deletedReview['limit'] ?> items and <?= (int) ($deletedReview['max_bytes'] / 1048576) ?> MB are kept. When full, restore or permanently remove an item to make room. Dates are shown in <?= e(date_default_timezone_get()) ?>.</p>
  </section>
  <?php else: ?>
  <section class="marketing-card marketing-deleted-review" data-recovery-review="<?= e($deletedSelected['id']) ?>" aria-labelledby="deleted-review-heading">
    <a class="marketing-deleted-back" href="<?= e(vvx_marketing_recovery_url($deletedSelected['collection'])) ?>">← Back to deleted content</a>
    <div class="marketing-deleted-review-heading"><div><span class="marketing-deleted-kind"><?= $deletedLabels[$deletedSelected['collection']] ?></span><h3 id="deleted-review-heading"><?= e($recoverySnapshot['title']) ?></h3><p>Deleted <?= e(vvx_marketing_recovery_date($deletedSelected['deleted_at'])) ?></p></div><span class="marketing-deleted-badge">Hidden from visitors</span></div>
    <?php if ($recoverySnapshot['problem'] !== ''): ?><p class="marketing-notice" role="alert" data-recovery-problem><?= e($recoverySnapshot['problem']) ?></p><?php endif; ?>
    <?php foreach ($recoverySnapshot['notices'] as $deletedNotice): ?><p class="marketing-notice" data-recovery-notice><?= e($deletedNotice) ?></p><?php endforeach; ?>
    <div class="marketing-deleted-restore-panel">
      <div><h4>A private first step</h4><p>The original address, content, audience, dates and saved history are retained. Review them in the editor after restoring.</p><p class="marketing-deleted-help"><?= $recoverySnapshot['history_count'] !== null ? (int) $recoverySnapshot['history_count'] . ' earlier saved versions retained.' : 'Saved history needs review.' ?> A previous publication can be kept in History when its restored status changes.</p></div>
      <?php if ($recoveryFailed): ?>
      <a class="marketing-button" data-recovery-reload href="<?= e(vvx_marketing_recovery_url($deletedSelected['collection'], $deletedSelected['id'])) ?>">Reload saved review</a>
      <?php elseif ($recoverySnapshot['can_restore']): ?>
      <form method="post" action="<?= e(vvx_marketing_recovery_url($deletedSelected['collection'], $deletedSelected['id'])) ?>" data-recovery-restore>
        <?= csrf_field() ?><input type="hidden" name="action" value="editorial_deleted_restore"><input type="hidden" name="collection" value="<?= e($deletedSelected['collection']) ?>"><input type="hidden" name="recovery_id" value="<?= e($deletedSelected['id']) ?>"><input type="hidden" name="recovery_revision" value="<?= e($recoverySnapshot['revision']) ?>">
        <button class="marketing-button"><?= $deletedSelected['collection'] === 'posts' ? 'Restore as draft' : 'Restore disabled' ?></button>
      </form>
      <?php endif; ?>
    </div>
    <details class="marketing-deleted-content" open><summary>Saved content and settings</summary><dl data-recovery-content>
      <?php foreach ($recoverySnapshot['fields'] as $key => $field): ?>
      <div data-recovery-field="<?= e($key) ?>"><dt><?= e($field['label']) ?></dt><dd <?= strlen($field['value']) > 1000 ? 'tabindex="0" aria-label="' . e($field['label'] . ' text') . '"' : '' ?>><?= e($field['value']) ?></dd></div>
      <?php endforeach; ?>
    </dl><p class="marketing-deleted-help">Dates shown in <?= e(date_default_timezone_get()) ?>. Saved links are shown as text.</p></details>
    <?php if (!$recoveryFailed): ?>
    <details class="marketing-deleted-permanent" data-recovery-permanent><summary>Permanently remove this item</summary><p>This removes the saved item and all of its earlier versions from Deleted content. It cannot be undone here. Uploaded images and other publications are kept.</p>
      <form class="marketing-form" method="post" action="<?= e(vvx_marketing_recovery_url($deletedSelected['collection'], $deletedSelected['id'])) ?>" data-recovery-purge>
        <?= csrf_field() ?><input type="hidden" name="action" value="editorial_deleted_purge"><input type="hidden" name="collection" value="<?= e($deletedSelected['collection']) ?>"><input type="hidden" name="recovery_id" value="<?= e($deletedSelected['id']) ?>"><input type="hidden" name="recovery_revision" value="<?= e($recoverySnapshot['revision']) ?>">
        <label class="marketing-deleted-confirm"><input type="checkbox" name="confirm_permanent" value="1" required> I understand this item and its saved history will be permanently removed.</label>
        <button class="marketing-button marketing-button-secondary">Permanently remove</button>
      </form>
    </details>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>

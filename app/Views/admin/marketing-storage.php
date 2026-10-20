<?php
declare(strict_types=1);

require_once root_path('app/Support/marketing_storage_admin.php');
$marketingStorageView ??= (new Velvet\Core\MarketingStorage(ROOT_PATH))->status();
$marketingStorageState = (string) $marketingStorageView['state'];
$marketingStorageReviewed = is_array($marketingStorageReview ?? null);
$marketingStorageHeadings = [
    'file' => 'Marketing uses file storage',
    'schema_needed' => 'Update the database first',
    'ready' => 'Ready to move to MySQL',
    'mysql' => 'Marketing is stored in MySQL',
    'blocked' => 'Storage needs attention',
];
?>
<section class="communication-admin-hero"><div><span class="admin-kicker">System</span><h2>Keep your marketing together</h2><p>Move marketing records into this site's configured MySQL database. Review the totals before moving; your saved audiences, consent, delivery history and publishing schedules stay together.</p></div></section>
<div class="member-admin-detail" data-marketing-storage data-storage-state="<?= e($marketingStorageState) ?>">
  <section class="admin-card" aria-labelledby="marketing-storage-status">
    <span class="admin-kicker">Current storage</span><h2 id="marketing-storage-status"><?= e($marketingStorageHeadings[$marketingStorageState] ?? 'Storage needs attention') ?></h2>
    <p class="muted-copy" role="status" data-storage-message><?= e((string) $marketingStorageView['message']) ?></p>
    <?php if ($marketingStorageState === 'file'): ?>
      <p>This site has no installed MySQL connection to use for a move. Marketing can continue using its current file storage.</p>
    <?php elseif ($marketingStorageState === 'schema_needed'): ?>
      <p>Apply the available update in Database Updates, then return here to preview the move.</p>
      <div class="panel-actions"><a class="admin-button primary" href="<?= e(base_url('admin/?page=database-updates')) ?>">Open Database Updates</a></div>
    <?php elseif ($marketingStorageState === 'mysql'): ?>
      <?php if (!empty($marketingStorageView['migrated_at'])): ?><p>Moved <time datetime="<?= e((string) $marketingStorageView['migrated_at']) ?>"><?= e((string) $marketingStorageView['migrated_at']) ?></time>.</p><?php endif; ?>
      <?php if (!empty($marketingStorageView['source_preserved'])): ?><p>The original marketing file is preserved. Include it with your normal backups.</p><?php endif; ?>
      <div class="panel-actions"><a class="admin-button primary" href="<?= e(base_url('admin/?page=marketing')) ?>">Open Marketing</a></div>
    <?php endif; ?>
    <?php if (!empty($marketingStorageView['can_migrate']) && !$marketingStorageReviewed): ?>
      <p>Preview the records to be copied. Your original records are retained while the move is reviewed.</p>
      <form method="post" action="<?= e(base_url('admin/?page=marketing-storage')) ?>" class="communication-admin-form" data-storage-preview-form>
        <?= csrf_field() ?><input type="hidden" name="action" value="preview_marketing_storage">
        <button class="admin-button primary" type="submit">Preview Move</button>
      </form>
    <?php endif; ?>
  </section>
  <?php if ($marketingStorageReviewed || $marketingStorageState === 'mysql'):
    $marketingStorageCounts = (array) ($marketingStorageReview['counts'] ?? $marketingStorageView['counts']);
  ?>
  <section class="admin-card" aria-labelledby="marketing-storage-records" data-storage-counts>
    <span class="admin-kicker"><?= $marketingStorageReviewed ? 'Review the move' : 'Saved in MySQL' ?></span><h2 id="marketing-storage-records"><?= $marketingStorageReviewed ? 'Records ready to copy' : 'Marketing records' ?></h2>
    <p class="muted-copy"><?= $marketingStorageReviewed ? 'Check these totals before continuing. The move includes settings, record order and all saved details.' : 'These totals reflect the current marketing records in the database.' ?></p>
    <dl aria-label="Marketing record totals">
      <?php foreach (vvx_marketing_storage_count_labels() as $key => $label): ?>
      <div class="order-detail-row" data-storage-count="<?= e($key) ?>"><dt><?= e($label) ?></dt><dd><?= (int) ($marketingStorageCounts[$key] ?? 0) ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <?php if ($marketingStorageReviewed): ?>
      <p>The original file and existing backups are kept. Moving records does not send email or release queued messages. If records change after this review, preview the move again.</p>
      <form method="post" action="<?= e(base_url('admin/?page=marketing-storage')) ?>" class="communication-admin-form" data-storage-migrate-form>
        <?= csrf_field() ?><input type="hidden" name="action" value="migrate_marketing_storage"><input type="hidden" name="plan" value="<?= e((string) $marketingStorageReview['plan']) ?>">
        <button class="admin-button primary" type="submit">Move to MySQL</button>
        <a class="admin-button secondary" href="<?= e(base_url('admin/?page=marketing-storage')) ?>">Cancel Review</a>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>

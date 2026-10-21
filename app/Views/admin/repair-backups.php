<?php
declare(strict_types=1);
/** @var \App\Core\RepairBackupService $repairService */
$repairBackups = $repairService->backups();
?>
<section class="welcome-panel"><div><span class="admin-kicker">Safe shared-hosting recovery</span><h2>Repair Backups</h2><p>These private folders may contain older uploads or configuration. Moving a backup preserves its contents and keeps it available here. Restore missing files before reviewing a duplicate for deletion.</p><div class="panel-actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="archive_repair_residues"><button class="admin-button primary">Move Root Backups to Private Storage</button></form></div></div></section>

<section class="admin-card"><h2>Why these folders appeared</h2><p>Folders named <code>.vvx-private-repair-uploads-…</code> or with another folder name are saved originals from the installer’s permissions repair. When the hosting control panel and PHP use different file owners, the installer creates a writable replacement and preserves the existing files. An old copy can remain in the root when the server prevents its cleanup or move.</p><p>Move root backups to <code>storage/private/repair-backups/</code> with the button above. Review missing and different entries before deleting anything. <strong>Delete Verified Duplicate</strong> is available only when every entry has a verified live copy. If a move fails, ask your host to correct file ownership for this site.</p></section>

<section class="admin-card"><div class="card-head"><div><span class="admin-kicker">Organized root layout</span><h2>Restore Organized Folders</h2></div></div>
<p class="muted-copy">Update Status verifies the current application and retires only exact forwarding files from the earlier package. Custom files stay in their existing locations, and only empty route folders are removed.</p>
<a class="admin-button primary" href="<?= e(base_url('admin/repair.php')) ?>">Open Update &amp; Folder Repair</a>
</section>

<?php if ($repairBackups === []): ?>
<section class="admin-card"><h2>No repair backups found.</h2><p class="muted-copy">The site root and private archive do not currently contain ownership-repair residues.</p></section>
<?php else: ?>
<div class="library-grid repair-backup-grid">
<?php foreach ($repairBackups as $backup): ?>
<article class="library-card repair-backup-card">
  <div>
    <span><?=e(strtoupper((string)$backup['type']))?> · <?=e((string)$backup['location'])?></span>
    <h3><?=e((string)$backup['name'])?></h3>
    <small><?=e(number_format((int)$backup['fileCount']))?> files · <?=e(number_format((int)$backup['bytes']/1024/1024,2))?> MB</small>
  </div>
  <dl class="repair-summary">
    <div><dt>Entries missing from live folder</dt><dd><?=e((string)$backup['missingCount'])?></dd></div>
    <div><dt>Entries different from live folder</dt><dd><?=e((string)$backup['differentCount'])?></dd></div>
    <div><dt>Already identical</dt><dd><?=e((string)$backup['sameCount'])?></dd></div>
  </dl>
  <?php if ($backup['missingPreview'] !== []): ?><details><summary>Files available to restore</summary><ul><?php foreach($backup['missingPreview'] as $file):?><li><?=e((string)$file)?></li><?php endforeach;?></ul></details><?php endif; ?>
  <?php if ($backup['differentPreview'] !== []): ?><details><summary>Files with different contents</summary><ul><?php foreach($backup['differentPreview'] as $file):?><li><?=e((string)$file)?></li><?php endforeach;?></ul></details><?php endif; ?>
  <?php if ($backup['verificationIssues'] !== []): ?><details><summary>Verification needs attention</summary><ul><?php foreach($backup['verificationIssues'] as $issue):?><li><?=e((string)$issue)?></li><?php endforeach;?></ul></details><?php endif; ?>
  <?php if ($backup['canMerge']): ?>
  <?php if (in_array($backup['type'], ['config', 'storage'], true)): ?><p class="muted-copy">The active configuration and installation lock use verified installation recovery. This restore action preserves those files.</p><?php endif; ?>
  <form method="post" class="compact-form"><?=csrf_field()?><input type="hidden" name="action" value="merge_repair_backup"><input type="hidden" name="backup_id" value="<?=e((string)$backup['id'])?>"><button class="admin-button secondary full">Restore Files Missing from Live Folder</button></form>
  <?php endif; ?>
  <?php if ($backup['safeToDelete']): ?>
  <form method="post" class="compact-form"><?=csrf_field()?><input type="hidden" name="action" value="delete_repair_backup"><input type="hidden" name="backup_id" value="<?=e((string)$backup['id'])?>"><label>Type DELETE VERIFIED BACKUP to delete this duplicate<input name="confirmation" autocomplete="off" required></label><button class="admin-button danger full">Delete Verified Duplicate</button></form>
  <?php else: ?><p class="muted-copy">This backup is preserved because it contains unique, different, or unverified entries. Deletion is available only after every entry has a verified live copy.</p><?php endif; ?>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>

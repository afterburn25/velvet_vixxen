<?php
declare(strict_types=1);
/** @var \App\Core\RepairBackupService $repairService */
$repairBackups = $repairService->backups();
$legacyEntries = $repairService->legacyRootEntries();
?>
<section class="welcome-panel"><div><span class="admin-kicker">Safe shared-hosting recovery</span><h2>Repair Backups</h2><p>These private folders may contain older uploads or configuration. Moving a backup preserves its contents and keeps it available here. Restore missing files before reviewing a duplicate for deletion.</p><div class="panel-actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="archive_repair_residues"><button class="admin-button primary">Move Root Backups to Private Storage</button></form></div></div></section>

<section class="admin-card"><div class="card-head"><div><span class="admin-kicker">Consolidated root layout</span><h2>Obsolete Root Route Folders</h2></div></div>
<?php if ($legacyEntries === []): ?><p class="muted-copy">No obsolete route folders remain in the site root.</p>
<?php else: ?><p class="muted-copy">The new router already serves these public URLs from <code>/public/routes</code>. Move the old physical entries into protected private storage after confirming the site is working.</p><p><code><?=e(implode(', ', $legacyEntries))?></code></p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="archive_legacy_root_entries"><button class="admin-button primary">Archive Old Root Entries</button></form><?php endif; ?>
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

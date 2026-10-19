<?php
declare(strict_types=1);
if ((string) (app_auth()->user()['role'] ?? '') !== 'owner') {
    echo '<section class="admin-card"><h2>Owner access required.</h2><p>Only the owner can inspect or apply database updates.</p></section>';
    return;
}
$databaseUpdateStatus = null;
$databaseUpdateError = '';
try { $databaseUpdateStatus = (new \App\Core\DatabaseUpdateService(ROOT_PATH))->preview(); }
catch (\Throwable $databaseUpdateException) { $databaseUpdateError = $databaseUpdateException->getMessage(); }
?>
<section class="welcome-panel"><div><span class="admin-kicker">System</span><h2>Database Updates</h2><p>Review database changes included with an uploaded site update and apply them to this existing installation.</p></div></section>
<?php if ($databaseUpdateError !== ''): ?>
<section class="admin-card"><h2>Updates are blocked</h2><p class="admin-alert error"><?=e($databaseUpdateError)?></p><p>Keep the existing database and configuration. Resolve the reported verification issue before continuing.</p></section>
<?php elseif ($databaseUpdateStatus['mode'] === 'file'): ?>
<section class="admin-card"><h2>No SQL update is needed</h2><p>This site uses file storage. Booking definitions and submissions are saved through the existing file store, so database migrations do not apply.</p></section>
<?php else:
$databaseUpdateLastRun = $databaseUpdateStatus['lastRun'];
$databaseUpdateNeedsReview = in_array($databaseUpdateLastRun['status'] ?? '', ['failed','interrupted'], true);
?>
<section class="admin-card"><div class="card-head"><div><span class="admin-kicker">Verified installation</span><h2><?=count($databaseUpdateStatus['pending'])?> pending update<?=count($databaseUpdateStatus['pending']) === 1 ? '' : 's'?></h2></div></div><p>The active configuration, installation lock and database installation ID match.</p><p><strong>Database:</strong> <?=e($databaseUpdateStatus['database'])?> · <strong>Server:</strong> <?=e($databaseUpdateStatus['server'])?></p>
<?php if ($databaseUpdateStatus['pending'] !== []): ?>
<div class="table-wrap"><table class="admin-table"><thead><tr><th>Pending migration</th><th>Purpose</th></tr></thead><tbody><?php foreach ($databaseUpdateStatus['pending'] as $migration): ?><tr><td><?=e($migration['id'])?></td><td><?=e($migration['description'])?></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><div class="admin-alert success">The database is current for the migration files installed on this site.</div><?php endif; ?></section>
<?php if (is_array($databaseUpdateLastRun)): ?>
<section class="admin-card"><h2>Last update: <?=e(ucfirst($databaseUpdateLastRun['status'] ?? 'unknown'))?></h2><p>Started <?=e($databaseUpdateLastRun['startedAt'] ?? '')?><?=!empty($databaseUpdateLastRun['finishedAt']) ? ' · Finished ' . e($databaseUpdateLastRun['finishedAt']) : ''?></p>
<p><strong>Backup reference recorded by the operator:</strong> <?=e($databaseUpdateLastRun['backupReference'] ?? '')?></p><p><?=e($databaseUpdateLastRun['backupConfirmation'] ?? '')?></p>
<?php if (!empty($databaseUpdateLastRun['applied'])): ?><p><strong>Completed migrations:</strong> <?=e(implode(', ', $databaseUpdateLastRun['applied']))?></p><?php endif; ?>
<?php if (!empty($databaseUpdateLastRun['currentMigration'])): ?><p><strong>Last migration reached:</strong> <?=e($databaseUpdateLastRun['currentMigration'])?></p><?php endif; ?>
<?php if (!empty($databaseUpdateLastRun['error'])): ?><div class="admin-alert error"><?=e($databaseUpdateLastRun['error'])?></div><?php endif; ?>
<?php if ($databaseUpdateNeedsReview): ?><p>SQL schema changes can commit before a migration finishes. Partial changes may remain. Review the failed migration and the database before retrying. Completed migrations remain recorded; no automatic rollback ran.</p><?php endif; ?></section>
<?php endif; ?>
<?php if ($databaseUpdateStatus['pending'] !== []): ?>
<section class="admin-card"><h2>Before applying updates</h2><ol class="workflow"><li><b>Back up</b><span>Create a fresh database backup through your hosting control panel or database tool. Keep copies of config/app.php, storage/install.lock and customer uploads in a separate backup location.</span></li><li><b>Verify</b><span>Check that the backup completed successfully, contains the expected tables/files, and has a usable restore procedure. Keep its timestamp and backup reference.</span></li><li><b>Apply</b><span>Run during a quiet maintenance window. Only pending migrations run. This updater preserves configuration and security keys and does not run the installer.</span></li></ol>
<form method="post" class="communication-admin-form">
<?=csrf_field()?><input type="hidden" name="action" value="apply_database_updates"><input type="hidden" name="update_plan" value="<?=e($databaseUpdateStatus['plan'])?>">
<label>Backup location or hosting backup reference<input name="backup_reference" maxlength="190" required placeholder="Hosting backup ID and date, or verified backup location"></label>
<label class="admin-inline-check"><input type="checkbox" name="backup_confirmed" value="1" required><span>I verified a fresh database backup and copies of the active configuration, installation lock and customer uploads, and I can restore them.</span></label>
<?php if ($databaseUpdateNeedsReview): ?><label class="admin-inline-check"><input type="checkbox" name="reviewed_failure" value="1" required><span>I reviewed the previous failed or interrupted run and any partial database changes before retrying.</span></label><?php endif; ?>
<button class="admin-button primary" <?=($databaseUpdateLastRun['status'] ?? '') === 'running' ? 'disabled' : ''?>>Apply <?=count($databaseUpdateStatus['pending'])?> Pending Update<?=count($databaseUpdateStatus['pending']) === 1 ? '' : 's'?></button>
</form><p>Command line: <code>php app/Console/migrate.php --status</code> shows the same plan. Use <code>--help</code> for the apply command and required backup confirmation.</p></section>
<?php endif; ?>
<details class="admin-card"><summary>Applied migrations (<?=count($databaseUpdateStatus['applied'])?>)</summary><div class="table-wrap"><table class="admin-table"><thead><tr><th>Migration</th><th>Applied</th></tr></thead><tbody><?php foreach ($databaseUpdateStatus['applied'] as $migration): ?><tr><td><?=e($migration['migration_key'])?></td><td><?=e($migration['executed_at'])?></td></tr><?php endforeach; ?></tbody></table></div></details>
<?php endif; ?>

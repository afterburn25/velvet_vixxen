<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Update Status · Velvet Vixxen Admin</title>
<link rel="stylesheet" href="../public/assets/css/admin.css?v=<?= e((string) ($status['build_id'] ?? 'folder-repair')) ?>">
<style>
.update-body{display:block}.update-shell{width:min(960px,100%);margin:0 auto;padding:clamp(18px,4vw,40px);box-sizing:border-box}.update-shell h1{margin:12px 0}.update-shell p{line-height:1.6}.update-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:24px 0}.update-summary div{min-width:0}.update-summary dt{color:#d0a8c1;font-size:.85rem;margin-bottom:6px}.update-summary dd{margin:0;font-weight:700;overflow-wrap:anywhere}.update-actions{display:flex;flex-wrap:wrap;gap:12px;margin:20px 0}.update-files{padding-left:20px}.update-files li{margin:12px 0;overflow-wrap:anywhere}.update-files span{display:block;color:#d0a8c1;font-size:.85rem}.update-shell .admin-card{margin-top:24px}.update-shell code{overflow-wrap:anywhere}.update-shell details{margin:16px 0}.update-shell summary{cursor:pointer}@media(max-width:540px){.update-summary{grid-template-columns:1fr}.update-actions .admin-button{width:100%;text-align:center}}
</style></head><body class="admin-body update-body"><main class="update-shell">
<div class="admin-kicker">Nikki Wilder · The Velvet Vixxen</div><h1>Update Status</h1>
<p>Verify this installation, activate the updated application, and restore the organized folders.</p>
<?php if ($error !== ''): ?><div class="admin-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="admin-alert success" role="status"><?= e($success) ?></div><?php endif; ?>
<?php if ($status): ?>
<section class="admin-card" data-update-status="<?= $status['files_match'] ? 'complete' : 'incomplete' ?>">
<h2><?= $status['files_match'] ? 'Update files verified' : 'Some update files did not arrive' ?></h2>
<dl class="update-summary">
<div><dt>Uploaded package</dt><dd>v<?= e($status['version']) ?> · Build <span data-uploaded-build><?= e($status['build_id']) ?></span></dd></div>
<div><dt>PHP running now</dt><dd data-running-build><?= e($status['running_build_id'] !== '' ? $status['running_build_id'] : 'Earlier build') ?></dd></div>
<div><dt>Runtime files checked</dt><dd><?= e((string) $status['files_checked']) ?></dd></div>
<div><dt>Files needing replacement</dt><dd><?= e((string) count($status['problems'])) ?></dd></div>
</dl>
<?php if ($status['files_match']): ?>
<p>Every file in the current package inventory matches this installation.<?php if (!$status['running_build_matches']): ?> <strong>PHP is still running an earlier build. Refresh application PHP first.</strong><?php endif; ?></p>
<form method="post" action="<?= e($repairEntry) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="refresh_php_cache"><button class="admin-button primary" type="submit">Refresh application PHP</button></form>
<?php else: ?>
<p>Extract the complete current Upgrade ZIP into this installation’s folder and replace matching application files. Then reload this page.</p>
<ul class="update-files"><?php foreach ($status['problems'] as $problem): ?><li><code><?= e($problem['path']) ?></code><span><?= e($problem['status']) ?></span></li><?php endforeach; ?></ul>
<?php endif; ?>
</section>
<?php endif; ?>
<?php if (is_array($layoutResult ?? null)): ?>
<section class="admin-card" role="status" data-layout-result="<?= $layoutResult['failed'] === [] && !$layoutResult['remaining']['has_work'] ? 'complete' : 'partial' ?>" data-retired-files="<?= count($layoutResult['retired']) ?>" data-removed-directories="<?= count($layoutResult['removed_directories']) ?>">
<h2><?= $layoutResult['failed'] === [] && !$layoutResult['remaining']['has_work'] ? 'Folder repair completed' : 'Folder repair needs attention' ?></h2>
<p><?= count($layoutResult['retired']) ?> verified old forwarding files retired. <?= count($layoutResult['removed_directories']) ?> empty route folders removed.</p>
<?php if ($layoutResult['archive'] !== ''): ?><p>The original forwarding files are preserved as reversible, nonexecuting data in <code><?= e($layoutResult['archive']) ?></code>.</p><?php endif; ?>
<?php if ($layoutResult['failed'] !== []): ?><ul class="update-files"><?php foreach ($layoutResult['failed'] as $problem): ?><li><code><?= e($problem['path']) ?></code><span><?= e($problem['reason']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
</section>
<?php endif; ?>
<?php if (is_array($layout ?? null)): ?>
<section class="admin-card" data-root-layout="<?= $layout['organized'] ? 'organized' : ($layout['has_work'] ? 'repair-available' : 'custom-preserved') ?>">
<h2>Organized website folders</h2>
<?php if ($layout['organized']): ?>
<p>No retired compatibility files or folders remain at the root.</p>
<?php else: ?>
<p>This repair removes only unchanged forwarding files distributed in the earlier update and then removes their empty folders. Configuration, uploads, database records, keys and custom items remain in place.</p>
<?php if ($layout['known_files'] !== []): ?><details><summary><?= count($layout['known_files']) ?> verified forwarding files ready to retire</summary><ul class="update-files"><?php foreach ($layout['known_files'] as $relative): ?><li><code><?= e($relative) ?></code></li><?php endforeach; ?></ul></details><?php endif; ?>
<?php if ($layout['has_work']): ?>
<?php if ($status && $status['files_match'] && $status['running_build_matches']): ?>
<form method="post" action="<?= e($repairEntry) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="repair_root_layout"><button class="admin-button primary" type="submit">Restore organized folders</button></form>
<?php else: ?><p><strong>Complete file verification and refresh application PHP before restoring the organized folders.</strong></p><?php endif; ?>
<?php endif; ?>
<?php if ($layout['preserved'] !== []): ?><details open><summary><?= count($layout['preserved']) ?> custom or unverified items preserved</summary><p>These items were left in their existing locations. A folder containing one of these items will remain.</p><ul class="update-files"><?php foreach ($layout['preserved'] as $item): ?><li><code><?= e($item['path']) ?></code><span><?= e($item['reason']) ?></span></li><?php endforeach; ?></ul></details><?php endif; ?>
<?php endif; ?>
</section>
<?php endif; ?>
<p>After the update, check Add Button, Full Page Preview, Publish and an image upload in Visual Composer.</p>
<nav class="update-actions" aria-label="Update verification"><a class="admin-button primary" href="index.php?page=composer">Open Visual Composer</a><a class="admin-button secondary" href="index.php?page=media">Open Media Library</a><a class="admin-button secondary" href="<?= e($repairEntry) ?>">Check again</a></nav>
</main></body></html>

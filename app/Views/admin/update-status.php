<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Update Status · Velvet Vixxen Admin</title>
<link rel="stylesheet" href="../public/assets/css/admin.css?v=<?= e((string) ($status['build_id'] ?? 'update-check')) ?>">
<style>
.update-body{display:block}
.update-shell{width:min(960px,100%);margin:0 auto;padding:clamp(18px,4vw,40px);box-sizing:border-box}.update-shell h1{margin:12px 0}.update-shell p{line-height:1.6}.update-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:24px 0}.update-summary div{min-width:0}.update-summary dt{color:#d0a8c1;font-size:.85rem;margin-bottom:6px}.update-summary dd{margin:0;font-weight:700;overflow-wrap:anywhere}.update-actions{display:flex;flex-wrap:wrap;gap:12px;margin:20px 0}.update-files{padding-left:20px}.update-files li{margin:12px 0;overflow-wrap:anywhere}.update-files span{display:block;color:#d0a8c1;font-size:.85rem}.update-shell .admin-card{margin-top:24px}.update-shell code{overflow-wrap:anywhere}@media(max-width:540px){.update-summary{grid-template-columns:1fr}.update-actions .admin-button{width:100%;text-align:center}}
</style></head><body class="admin-body update-body"><main class="update-shell">
<div class="admin-kicker">Nikki Wilder · The Velvet Vixxen</div><h1>Update Status</h1>
<p>Check that the update reached this site and refresh its application PHP after replacing files.</p>
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
<p>Every file in the package inventory matches this installation.<?php if (!$status['running_build_matches']): ?> <strong>PHP is still running an earlier build. Refresh application PHP below.</strong><?php endif; ?></p>
<form method="post" action="update.php"><?= csrf_field() ?><input type="hidden" name="action" value="refresh_php_cache"><button class="admin-button primary" type="submit">Refresh application PHP</button></form>
<p class="muted-copy">After refreshing, open Visual Composer and check Add Button, Full Page Preview, Publish and an image upload. File verification alone cannot confirm those actions work on this host.</p>
<?php else: ?>
<p>Extract the complete Upgrade ZIP into this installation’s folder and choose to replace existing files. Then reload this page. The list identifies missing files and files whose contents differ from the package.</p>
<ul class="update-files"><?php foreach ($status['problems'] as $problem): ?><li><code><?= e($problem['path']) ?></code><span><?= e($problem['status']) ?></span></li><?php endforeach; ?></ul>
<?php endif; ?>
</section>
<?php endif; ?>
<nav class="update-actions" aria-label="Update verification"><a class="admin-button primary" href="index.php?page=composer">Open Visual Composer</a><a class="admin-button secondary" href="index.php?page=media">Open Media Library</a><a class="admin-button secondary" href="update.php">Check again</a></nav>
</main></body></html>

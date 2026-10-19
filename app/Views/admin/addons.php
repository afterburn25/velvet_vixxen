<?php
declare(strict_types=1);

use App\Core\AddonPermissionCatalog;
use App\Core\AddonManifest;

$addons = $store->addons();
$activity = $store->addonActivity(12);
$safeMode = $store->addonsSafeMode();
$activeCount = count(array_filter($addons, static fn(array $row): bool => ($row['status'] ?? '') === 'active'));
$inactiveCount = count(array_filter($addons, static fn(array $row): bool => in_array(($row['status'] ?? ''), ['installed','inactive'], true)));
$attentionCount = count(array_filter($addons, static fn(array $row): bool => in_array(($row['status'] ?? ''), ['failed','quarantined'], true)));
?>
<section class="addon-hero">
  <div><span class="admin-kicker">Versioned extension platform</span><h2>Add-on Manager</h2><p>Upload validated ZIP packages, review permissions, install updates, activate features, roll back failures, and keep core files untouched.</p></div>
  <div class="addon-hero-actions">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_addon_safe_mode"><input type="hidden" name="enabled" value="<?= $safeMode ? '0' : '1' ?>"><button class="admin-button <?= $safeMode ? 'primary' : 'secondary' ?>"><?= $safeMode ? 'Disable Safe Mode' : 'Enable Safe Mode' ?></button></form>
    <a class="admin-button secondary" href="<?= e(base_url('admin/download.php?file=starter-addon')) ?>" download>Download Starter Add-on</a>
  </div>
</section>
<?php if ($safeMode): ?><div class="admin-alert error"><strong>Add-on Safe Mode is active.</strong> Installed packages remain registered, but optional add-on PHP code, routes, widgets, and admin pages will not load until Safe Mode is disabled.</div><?php endif; ?>
<div class="addon-top-grid">
  <section class="admin-card addon-upload-card">
    <span class="admin-kicker">Install or update</span><h2>Upload an add-on ZIP</h2><p class="muted-copy">Packages are staged in a protected folder and inspected before any PHP code executes.</p>
    <form method="post" enctype="multipart/form-data" class="addon-upload-form"><?= csrf_field() ?><input type="hidden" name="action" value="inspect_addon_upload"><label>Add-on package<input type="file" name="addon_package" accept="application/zip,.zip" required></label><button class="admin-button primary full">Inspect Package</button></form>
    <form method="post" class="addon-upload-form"><?= csrf_field() ?><input type="hidden" name="action" value="inspect_starter_addon"><button class="admin-button secondary full">Inspect Included Starter Add-on</button></form>
    <div class="addon-security-note"><strong>Security boundary</strong><p>PHP add-ons execute server-side code and cannot be fully sandboxed on ordinary shared hosting. Install only trusted packages. Signatures, checksums, permissions, backups, and Safe Mode reduce risk but do not turn untrusted PHP into safe code.</p></div>
  </section>
  <section class="admin-card addon-framework-status">
    <span class="admin-kicker">Framework status</span><h2><?= e((string)count($addons)) ?> registered add-on<?= count($addons)===1?'':'s' ?></h2>
    <div class="addon-stat-row"><article><strong><?= e((string)$activeCount) ?></strong><span>Active</span></article><article><strong><?= e((string)$inactiveCount) ?></strong><span>Inactive</span></article><article><strong><?= e((string)$attentionCount) ?></strong><span>Needs attention</span></article></div>
    <ul class="addon-capability-list"><li>Manifest and semantic-version validation</li><li>Explicit permissions, dependencies, and conflicts</li><li>Checksums and optional Ed25519 signatures</li><li>Pre-update backups and one-click rollback</li><li>Database migration registry and purge workflow</li><li>Admin pages, routes, widgets, hooks, filters, services, and tasks</li></ul>
  </section>
</div>

<?php if (is_array($addonReview)): $manifest=(array)$addonReview['manifest']; ?>
<section class="admin-card addon-review-card">
  <div class="card-head"><div><span class="admin-kicker">Permission and compatibility review</span><h2><?= e((string)$manifest['name']) ?> <small>v<?= e((string)$manifest['version']) ?></small></h2></div><span class="addon-status-badge ready">Ready to <?= e((string)$addonReview['operation']) ?></span></div>
  <div class="addon-review-facts">
    <article><h3>Package</h3><dl><div><dt>Add-on id</dt><dd><?= e((string)$manifest['id']) ?></dd></div><div><dt>Vendor</dt><dd><?= e((string)$manifest['vendor']) ?></dd></div><div><dt>Operation</dt><dd><?= e(ucfirst((string)$addonReview['operation'])) ?></dd></div><div><dt>Files</dt><dd><?= e((string)$addonReview['fileCount']) ?></dd></div><div><dt>Package SHA-256</dt><dd class="hash-value"><?= e((string)$addonReview['packageSha256']) ?></dd></div></dl></article>
    <article><h3>Integrity</h3><dl><div><dt>Checksums</dt><dd><?= e((string)$addonReview['checksums']['status']) ?></dd></div><div><dt>Signature</dt><dd><?= e((string)$addonReview['signature']['status']) ?></dd></div><div><dt>Core range</dt><dd><?= e((string)$manifest['minimum_core_version']) ?> – <?= e((string)($manifest['maximum_core_version']?:'newer')) ?></dd></div><div><dt>PHP</dt><dd><?= e((string)$manifest['minimum_php_version']) ?>+</dd></div></dl></article>
  </div>
  <h3>Requested permissions</h3>
  <div class="addon-permission-grid"><?php foreach ((array)$addonReview['permissions'] as $permission): ?><article class="risk-<?= e((string)$permission['risk']) ?>"><code><?= e((string)$permission['key']) ?></code><span><?= e((string)$permission['description']) ?></span></article><?php endforeach; ?><?php if(empty($addonReview['permissions'])):?><article><span>This package requests no registered application permissions.</span></article><?php endif;?></div>
  <?php if (!empty($addonReview['source']['source']) && $addonReview['source']['source']==='remote-update'): ?><div class="notice-panel"><strong>Signed remote feed:</strong> This package was downloaded from the configured licensing service and matched the feed SHA-256. Review changed permissions before installation.</div><?php endif; ?>
  <?php if (!empty($addonReview['warnings'])): ?><div class="addon-warning"><strong>Warnings</strong><ul><?php foreach ((array)$addonReview['warnings'] as $warning): ?><li><?= e((string)$warning) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="post" class="addon-install-form"><?= csrf_field() ?><input type="hidden" name="action" value="install_addon"><input type="hidden" name="review_token" value="<?= e((string)$addonReview['token']) ?>">
    <?php if (($addonReview['signature']['status']??'unsigned') !== 'verified'): ?><label class="addon-confirm"><input type="checkbox" name="trust_package" required><span>I trust this package source and understand that its PHP code will execute on the server.</span></label><?php else: ?><input type="hidden" name="trust_package" value="1"><?php endif; ?>
    <label class="addon-confirm"><input type="checkbox" name="activate_after_install"><span><b>Activate after install</b><small>Available for new packages. Updates remain inactive until reviewed on the next request unless selected.</small></span></label>
    <button class="admin-button primary full"><?= e(ucfirst((string)$addonReview['operation'])) ?> Package</button>
  </form>
</section>
<?php endif; ?>

<section class="addon-registry-section"><span class="admin-kicker">Installed registry</span><h2>Installed Add-ons</h2>
<?php if ($addons === []): ?><div class="admin-card addon-empty"><h3>No add-ons are installed.</h3><p class="muted-copy">Inspect the included starter add-on to test the full permission-review and lifecycle workflow.</p></div><?php else: ?><div class="addon-card-grid"><?php foreach($addons as $addon): $manifest=(array)($addon['manifest']??[]);$manifestObject=AddonManifest::fromArray($manifest);$licensing=$manifestObject->licensing();$updates=$manifestObject->updates();$backups=$store->addonBackups((string)$addon['addonKey']);$clientLicense=$store->addonLicense((string)$addon['addonKey']);$updateCheck=$store->latestAddonUpdateCheck((string)$addon['addonKey']); ?><article class="addon-record status-<?= e((string)$addon['status']) ?>">
  <div class="addon-record-top"><span class="addon-status-badge <?= e((string)$addon['status']) ?>"><?= e((string)$addon['status']) ?></span><span class="addon-signature-badge"><?= e((string)($addon['signatureStatus']??'unsigned')) ?></span></div>
  <h3><?= e((string)$addon['name']) ?></h3><small><?= e((string)$addon['vendor']) ?> · v<?= e((string)$addon['version']) ?></small><p><?= e((string)($manifest['description']??'')) ?></p>
  <div class="addon-record-stats"><div><strong><?= e((string)count((array)$addon['permissions'])) ?></strong><span>permissions</span></div><div><strong><?= e((string)count((array)($manifest['dependencies']??[]))) ?></strong><span>dependencies</span></div><div><strong><?= e((string)count($backups)) ?></strong><span>backups</span></div></div>
  <details><summary>Permissions &amp; package details</summary><div class="addon-detail-list"><p><b>Package:</b> <code><?= e((string)$addon['packageSha256']) ?></code></p><?php foreach(AddonPermissionCatalog::describe((array)$addon['permissions']) as $permission):?><p><code><?= e((string)$permission['key']) ?></code> — <?= e((string)$permission['description']) ?></p><?php endforeach;?><?php if(!empty($addon['lastError'])):?><p class="addon-error-text"><b>Last error:</b> <?=e((string)$addon['lastError'])?></p><?php endif;?></div></details>
  <div class="addon-license-panel">
    <div class="addon-license-head"><div><span class="admin-kicker">License &amp; remote updates</span><strong><?= $clientLicense ? e((string)$clientLicense['keyPrefix']) : ($manifestObject->requiresLicense() ? 'Activation required' : 'Optional update entitlement') ?></strong></div><span class="addon-status-badge <?= e((string)($clientLicense['status']??($manifestObject->requiresLicense()?'inactive':'free'))) ?>"><?= e((string)($clientLicense['status']??($manifestObject->requiresLicense()?'inactive':'free'))) ?></span></div>
    <?php if($clientLicense===null): ?>
      <details><summary>Activate a license / connect update service</summary><form method="post" class="compact-form addon-license-form"><?=csrf_field()?><input type="hidden" name="action" value="activate_addon_license"><input type="hidden" name="addon_id" value="<?=e((string)$addon['addonKey'])?>"><label>License key<input name="license_key" autocomplete="off" placeholder="VVX-XXXX-XXXX-XXXX-XXXX-XXXX" required></label><label>License service URL<input type="url" name="server_url" value="<?=e((string)($licensing['server_url']??''))?>" placeholder="https://licenses.example.com" required></label><label>Ed25519 public key<textarea name="public_key" rows="3" required><?=e((string)($licensing['public_key']??$updates['public_key']??''))?></textarea></label><label>Update channel<select name="channel"><option value="stable">Stable</option><option value="beta">Beta</option><option value="development">Development</option></select></label><button class="mini-button">Activate License</button></form></details>
    <?php else: ?>
      <div class="addon-license-facts"><span>License expires <b><?=e((string)($clientLicense['expiresAt']??'Never'))?></b></span><span>Updates through <b><?=e((string)($clientLicense['updatesExpiresAt']??'No expiry'))?></b></span><span>Last checked <b><?=e((string)($clientLicense['lastCheckedAt']??'Never'))?></b></span></div>
      <?php if(!empty($clientLicense['lastError'])):?><p class="addon-error-text"><?=e((string)$clientLicense['lastError'])?></p><?php endif;?>
      <div class="addon-license-actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="check_addon_license"><input type="hidden" name="addon_id" value="<?=e((string)$addon['addonKey'])?>"><button class="mini-button">Check License</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="deactivate_addon_license"><input type="hidden" name="addon_id" value="<?=e((string)$addon['addonKey'])?>"><button class="mini-button">Deactivate Site</button></form></div>
      <form method="post" class="addon-update-row"><?=csrf_field()?><input type="hidden" name="action" value="save_addon_update_channel"><input type="hidden" name="addon_id" value="<?=e((string)$addon['addonKey'])?>"><select name="channel"><?php foreach(['stable'=>'Stable','beta'=>'Beta','development'=>'Development'] as $key=>$label):?><option value="<?=$key?>" <?=($clientLicense['channel']??'stable')===$key?'selected':''?>><?=$label?></option><?php endforeach;?></select><button class="mini-button">Save Channel</button></form>
      <form method="post" class="addon-update-row"><?=csrf_field()?><input type="hidden" name="action" value="check_addon_update"><input type="hidden" name="addon_id" value="<?=e((string)$addon['addonKey'])?>"><input type="hidden" name="channel" value="<?=e((string)($clientLicense['channel']??'stable'))?>"><button class="mini-button">Check Updates</button><?php if(($updateCheck['status']??'')==='available'):?><span>v<?=e((string)$updateCheck['availableVersion'])?> available</span><?php elseif($updateCheck!==null):?><span><?=e((string)$updateCheck['status'])?></span><?php endif;?></form>
      <?php if(($updateCheck['status']??'')==='available'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="download_addon_update"><input type="hidden" name="addon_id" value="<?=e((string)$addon['addonKey'])?>"><button class="admin-button primary full">Download &amp; Review v<?=e((string)$updateCheck['availableVersion'])?></button></form><?php endif;?>
    <?php endif; ?>
  </div>
  <div class="addon-record-actions">
    <?php if(($addon['status']??'')==='active'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="deactivate_addon"><input type="hidden" name="addon_id" value="<?=e((string)$addon['addonKey'])?>"><button class="mini-button">Deactivate</button></form><?php else:?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="activate_addon"><input type="hidden" name="addon_id" value="<?=e((string)$addon['addonKey'])?>"><button class="mini-button">Activate</button></form><?php endif;?>
    <?php if($backups!==[]):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="rollback_addon"><input type="hidden" name="addon_id" value="<?=e((string)$addon['addonKey'])?>"><button class="mini-button">Rollback</button></form><?php endif;?>
    <form method="post" onsubmit="return confirm('Uninstall this add-on?');"><?=csrf_field()?><input type="hidden" name="action" value="uninstall_addon"><input type="hidden" name="addon_id" value="<?=e((string)$addon['addonKey'])?>"><button class="mini-button danger">Uninstall</button><label class="purge-check"><input type="checkbox" name="purge_data"><span>Purge data</span></label></form>
  </div>
</article><?php endforeach; ?></div><?php endif; ?></section>

<div class="addon-bottom-grid">
  <section class="admin-card"><span class="admin-kicker">Starter add-on</span><h2>Test the extension lifecycle</h2><p class="muted-copy">Download the included audited starter package to test inspection, permission review, installation, activation, updates, backup, rollback, and uninstall on this site.</p><div class="panel-actions"><a class="admin-button secondary" href="<?=e(base_url('admin/download.php?file=starter-addon'))?>" download>Download Starter ZIP</a></div></section>
  <section class="admin-card"><span class="admin-kicker">Recent activity</span><h2>Lifecycle Log</h2><div class="addon-activity-list"><?php foreach($activity as $entry):?><article><span class="activity-level <?=e((string)$entry['level'])?>"><?=e(strtoupper((string)$entry['level']))?></span><div><strong><?=e((string)$entry['event'])?></strong><small><?=e((string)($entry['addonKey']?:'core'))?> · <?=e((string)$entry['createdAt'])?></small></div></article><?php endforeach;?><?php if($activity===[]):?><p class="muted-copy">Lifecycle events will appear after an add-on is inspected or installed.</p><?php endif;?></div></section>
</div>

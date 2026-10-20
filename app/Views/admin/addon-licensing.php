<?php
declare(strict_types=1);

$hubStatus = $licenseHub->status();
$products = $store->addonLicenseProducts();
$issuedLicenses = $store->issuedAddonLicenses();
$releases = $store->addonReleases();
$transactions = $store->addonLicenseTransactions(null, 20);
$issuedOnce = $_SESSION['_issued_addon_license_once'] ?? null;
unset($_SESSION['_issued_addon_license_once']);
$activeIssued = count(array_filter($issuedLicenses, static fn(array $row): bool => ($row['status']??'') === 'active'));
$activeSites = count(array_filter($store->addonLicenseActivations(), static fn(array $row): bool => ($row['status']??'') === 'active'));
?>
<section class="license-hub-hero">
  <div><span class="admin-kicker">0.8.1 Commercial delivery</span><h2>Add-on Licensing &amp; Release Hub</h2><p>Issue commercial license keys, control site activations, publish signed update feeds, and deliver protected package downloads without disabling already-installed add-ons during a service outage.</p></div>
  <div class="license-hub-identity">
    <span class="addon-status-badge <?= $hubStatus['enabled']?'active':'failed' ?>"><?= $hubStatus['enabled']?'Signing online':'Needs attention' ?></span>
    <small>Ed25519 fingerprint</small><code><?= e($hubStatus['fingerprint'] ?: 'Unavailable') ?></code>
  </div>
</section>
<?php if (!$hubStatus['enabled']): ?><div class="admin-alert error"><strong>Licensing Hub cannot sign responses.</strong> <?= e((string)$hubStatus['error']) ?></div><?php endif; ?>
<?php if (is_array($issuedOnce)): ?><section class="admin-card license-once"><span class="admin-kicker">Shown once</span><h2>Copy this license key now</h2><p>The raw key is not stored in readable form and will not be shown again.</p><code><?= e((string)$issuedOnce['licenseKey']) ?></code><small><?= e((string)($issuedOnce['customerEmail']??'')) ?> · <?= e((string)($issuedOnce['productKey']??'')) ?></small></section><?php endif; ?>

<div class="license-stat-grid">
  <article><strong><?= e((string)count($products)) ?></strong><span>License products</span></article>
  <article><strong><?= e((string)$activeIssued) ?></strong><span>Active licenses</span></article>
  <article><strong><?= e((string)$activeSites) ?></strong><span>Active sites</span></article>
  <article><strong><?= e((string)count(array_filter($releases,static fn(array $r):bool=>!empty($r['published'])))) ?></strong><span>Published releases</span></article>
</div>

<section class="admin-card license-service-card">
  <div class="card-head"><div><span class="admin-kicker">Service identity</span><h2>Connection details for commercial add-ons</h2></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="generate_license_hub_keys"><button class="mini-button">Verify / Generate Keys</button></form></div>
  <div class="license-service-grid"><label>Service base URL<input readonly value="<?= e(rtrim(base_url(),'/')) ?>"></label><label>API endpoint<input readonly value="<?= e((string)$hubStatus['endpoint']) ?>"></label><label class="wide">Ed25519 public key<textarea readonly rows="3"><?= e((string)$hubStatus['publicKey']) ?></textarea></label></div>
  <p class="muted-copy">The private signing key is generated in protected storage and is never placed in an add-on ZIP, public repository, or client response. Production vendors may deploy this same API on a separate licensing domain.</p>
</section>

<div class="license-build-grid">
<section class="admin-card">
  <span class="admin-kicker">Product rules</span><h2>Create a license product</h2>
  <form method="post" class="compact-form license-form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save_addon_license_product">
    <label>Product key<input name="product_key" placeholder="velvet.welcome-banner.pro" required></label>
    <label>Add-on id<input name="addon_key" placeholder="velvet.welcome-banner" required></label>
    <label class="wide">Display name<input name="name" placeholder="Welcome Banner — Single Site" required></label>
    <label>License type<select name="license_type"><option value="lifetime">Lifetime</option><option value="one_time">One-time</option><option value="annual">Annual</option><option value="monthly">Monthly</option><option value="trial">Trial</option><option value="free">Free</option></select></label>
    <label>Billing interval<select name="billing_interval"><option value="one_time">One-time</option><option value="year">Annual</option><option value="month">Monthly</option><option value="none">None</option></select></label>
    <label>License duration days<input type="number" min="0" max="3650" name="duration_days" value="0"></label>
    <label>Site limit<input type="number" min="1" max="100000" name="site_limit" value="1" required></label>
    <label>Update entitlement days<input type="number" min="0" max="3650" name="updates_days" value="365"></label>
    <label>Support entitlement days<input type="number" min="0" max="3650" name="support_days" value="365"></label>
    <label>Offline grace days<input type="number" min="0" max="90" name="grace_days" value="7"></label>
    <label>Price<input type="number" min="0" step="0.01" name="price" value="0.00"></label>
    <label>Currency<input name="currency" value="USD" maxlength="3"></label>
    <label class="toggle-row wide"><input type="checkbox" name="is_active" checked><span><b>Product active</b><small>Allow new license issuance and activation.</small></span></label>
    <button class="admin-button primary wide">Save License Product</button>
  </form>
</section>
<section class="admin-card">
  <span class="admin-kicker">Customer entitlement</span><h2>Issue a license key</h2>
  <form method="post" class="compact-form"><?= csrf_field() ?><input type="hidden" name="action" value="issue_addon_license">
    <label>License product<select name="product_key" required><option value="">Choose product</option><?php foreach($products as $product): ?><option value="<?=e((string)$product['productKey'])?>"><?=e((string)$product['name'])?> · <?=e((string)$product['siteLimit'])?> site<?=((int)$product['siteLimit']===1?'':'s')?></option><?php endforeach;?></select></label>
    <label>Customer email<input type="email" name="customer_email" required></label>
    <div class="control-grid"><label>Amount paid<input type="number" min="0" step="0.01" name="amount" value="0.00"></label><label>Reference / invoice<input name="reference" placeholder="ORDER-1001"></label></div>
    <button class="admin-button primary full" <?= $products===[]?'disabled':'' ?>>Issue License</button>
  </form>
  <div class="addon-security-note"><strong>Customer-friendly behavior</strong><p>Expired update/support entitlement blocks new downloads and support, but does not remotely disable already-installed local functionality. Revocation and refunds stop future activation and downloads.</p></div>
</section>
</div>

<section class="admin-card">
  <div class="card-head"><div><span class="admin-kicker">Release publishing</span><h2>Publish an add-on update</h2></div><span class="addon-status-badge ready">Signed feed + protected download</span></div>
  <form method="post" enctype="multipart/form-data" class="release-publish-form"><?=csrf_field()?><input type="hidden" name="action" value="publish_addon_release">
    <label>Release ZIP<input type="file" name="release_package" accept="application/zip,.zip" required></label>
    <label>Channel<select name="channel"><option value="stable">Stable</option><option value="beta">Beta</option><option value="development">Development</option></select></label>
    <label class="wide">Changelog<textarea name="changelog" rows="4" placeholder="What changed in this version?"></textarea></label>
    <label class="wide">Release notes URL<input type="url" name="release_notes_url" placeholder="https://vendor.example/releases/..."></label>
    <label class="toggle-row wide"><input type="checkbox" name="published" checked><span><b>Publish immediately</b><small>Eligible licensed installations may see this release on their selected channel.</small></span></label>
    <button class="admin-button primary wide">Inspect &amp; Publish Release</button>
  </form>
</section>

<section class="admin-card">
  <div class="card-head"><div><span class="admin-kicker">Products</span><h2>License Catalog</h2></div></div>
  <?php if($products===[]):?><p class="muted-copy">No commercial license products have been created.</p><?php else:?><div class="license-product-grid"><?php foreach($products as $product):?><article><span class="addon-status-badge <?=$product['active']?'active':'inactive'?>"><?=$product['active']?'active':'inactive'?></span><h3><?=e((string)$product['name'])?></h3><code><?=e((string)$product['productKey'])?></code><dl><div><dt>Add-on</dt><dd><?=e((string)$product['addonKey'])?></dd></div><div><dt>Type</dt><dd><?=e((string)$product['licenseType'])?></dd></div><div><dt>Sites</dt><dd><?=e((string)$product['siteLimit'])?></dd></div><div><dt>Updates</dt><dd><?=e((string)$product['updatesDays'])?> days</dd></div><div><dt>Grace</dt><dd><?=e((string)$product['graceDays'])?> days</dd></div><div><dt>Price</dt><dd><?=e(money((float)$product['price'],(string)$product['currency']))?></dd></div></dl></article><?php endforeach;?></div><?php endif;?>
</section>

<section class="admin-card">
  <div class="card-head"><div><span class="admin-kicker">Customers &amp; sites</span><h2>Issued Licenses</h2></div></div>
  <?php if($issuedLicenses===[]):?><p class="muted-copy">No license keys have been issued.</p><?php else:?><div class="table-wrap"><table class="admin-table license-table"><thead><tr><th>License</th><th>Customer</th><th>Status</th><th>Sites</th><th>Updates</th><th>Actions</th></tr></thead><tbody><?php foreach($issuedLicenses as $license):$activations=$store->addonLicenseActivations((string)$license['id']);?><tr><td><strong><?=e((string)$license['keyPrefix'])?></strong><small><?=e((string)$license['productKey'])?></small></td><td><?=e((string)$license['customerEmail'])?></td><td><span class="addon-status-badge <?=e((string)$license['status'])?>"><?=e((string)$license['status'])?></span></td><td><?=e((string)count(array_filter($activations,static fn(array $a):bool=>($a['status']??'')==='active')))?> / <?=e((string)$license['maxActivations'])?><?php foreach($activations as $activation):?><small><?=e((string)$activation['domainName'])?> · <?=e((string)$activation['status'])?></small><?php endforeach;?></td><td><small><?=e((string)($license['updatesExpiresAt']??'No expiry'))?></small></td><td><form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="change_issued_license_status"><input type="hidden" name="license_id" value="<?=e((string)$license['id'])?>"><select name="status"><option value="active">Active</option><option value="suspended">Suspended</option><option value="revoked">Revoked</option><option value="refunded">Refunded</option></select><button class="mini-button">Apply</button></form><form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="renew_issued_license"><input type="hidden" name="license_id" value="<?=e((string)$license['id'])?>"><input type="number" name="days" min="1" max="3650" value="365" title="Days"><button class="mini-button">Renew</button></form></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</section>

<div class="license-bottom-grid">
<section class="admin-card"><span class="admin-kicker">Update channels</span><h2>Published Releases</h2><?php if($releases===[]):?><p class="muted-copy">No remote releases have been published.</p><?php else:?><div class="release-list"><?php foreach($releases as $release):?><article><div><strong><?=e((string)$release['addonKey'])?> v<?=e((string)$release['version'])?></strong><small><?=e((string)$release['channel'])?> · <?=e((string)$release['signatureStatus'])?> · <?=number_format(((int)$release['packageSize'])/1024,1)?> KB</small></div><span class="addon-status-badge <?=$release['published']?'active':'inactive'?>"><?=$release['published']?'published':'draft'?></span><p><?=nl2br(e((string)$release['changelog']))?></p><code><?=e((string)$release['packageSha256'])?></code></article><?php endforeach;?></div><?php endif;?></section>
<section class="admin-card"><span class="admin-kicker">Billing history</span><h2>License Transactions</h2><?php if($transactions===[]):?><p class="muted-copy">Purchases, renewals, refunds, and revocations will appear here.</p><?php else:?><div class="activity-list"><?php foreach($transactions as $transaction):?><article><div><strong><?=e(ucfirst((string)$transaction['eventType']))?></strong><small>License #<?=e((string)$transaction['licenseId'])?> · <?=e((string)$transaction['createdAt'])?></small></div><span><?=e(money((float)$transaction['amount'],(string)$transaction['currency']))?></span></article><?php endforeach;?></div><?php endif;?></section>
</div>

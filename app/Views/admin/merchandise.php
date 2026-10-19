<?php
declare(strict_types=1);
if ((app_auth()->user()['role'] ?? '') !== 'owner') { echo '<section class="admin-card"><h2>Owner access required.</h2></section>'; return; }
$products = $store->merchandiseProducts();
$editProduct = isset($_GET['edit']) ? $store->merchandiseProduct((string) $_GET['edit']) : null;
$formRevision = $editProduct ? App\Core\MerchandiseService::revision($editProduct) : '';
$formProduct = $editProduct ?? ['id' => '', 'title' => '', 'slug' => '', 'description' => '', 'price' => '0.00', 'currency' => app_config()->get('currency', 'USD'), 'status' => 'draft', 'requiresShipping' => true, 'shippingPrice' => '0.00', 'sortOrder' => 100, 'imageIds' => [], 'variants' => [['label' => 'Standard', 'isEnabled' => true, 'stock' => 0]]];
if (($_POST['action'] ?? '') === 'save_merchandise_product') {
    $formRevision = is_string($_POST['product_revision'] ?? null) ? $_POST['product_revision'] : '';
    foreach (['title', 'slug', 'description', 'price', 'currency', 'status'] as $field) $formProduct[$field] = (string) ($_POST[$field] ?? '');
    $formProduct['id'] = (string) ($_POST['product_id'] ?? '');
    $formProduct['requiresShipping'] = isset($_POST['requires_shipping']);
    $formProduct['shippingPrice'] = (string) ($_POST['shipping_price'] ?? '0');
    $formProduct['sortOrder'] = (int) ($_POST['sort_order'] ?? 100);
    $formProduct['imageIds'] = (array) ($_POST['image_ids'] ?? []);
    $formProduct['variants'] = array_values((array) ($_POST['variants'] ?? []));
}
$publicImages = array_values(array_filter($store->media(), static fn(array $asset): bool => str_starts_with((string) ($asset['mime'] ?? ''), 'image/') && empty($asset['private']) && !str_starts_with((string) ($asset['file'] ?? ''), 'private:')));
?>
<link rel="stylesheet" href="<?= e(asset_url('css/merchandise.css')) ?>">
<section class="admin-card"><div class="card-head"><div><span class="admin-kicker">Commerce</span><h2>Merchandise</h2><p>Manage product photos, options, available stock and shipping. Memberships and PPV have separate checkout flows.</p></div><a class="admin-button secondary" href="<?= e(base_url('shop/')) ?>" target="_blank" rel="noopener">View Store</a></div></section>
<div class="merchandise-admin-grid">
  <form method="post" enctype="multipart/form-data" class="admin-card merchandise-product-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_merchandise_product"><input type="hidden" name="product_id" value="<?= e((string) $formProduct['id']) ?>"><input type="hidden" name="product_revision" value="<?= e($formRevision) ?>">
    <span class="admin-kicker">Catalog</span><h2><?= $formProduct['id'] ? 'Edit product' : 'Create product' ?></h2>
    <label>Product title<input name="title" value="<?= e($formProduct['title']) ?>" maxlength="190" required></label>
    <label>URL slug<input name="slug" value="<?= e($formProduct['slug']) ?>" maxlength="190" placeholder="Generated from title"></label>
    <label>Description<textarea name="description" rows="5" maxlength="10000"><?= e($formProduct['description']) ?></textarea></label>
    <div class="control-grid">
      <label>Product price<input type="number" name="price" min="0" max="99999999.99" step=".01" value="<?= e((string) $formProduct['price']) ?>" required></label>
      <label>Currency<input name="currency" value="<?= e($formProduct['currency']) ?>" pattern="[A-Za-z]{3}" minlength="3" maxlength="3" required></label>
      <label>Status<select name="status"><?php foreach (['draft' => 'Draft', 'active' => 'Published', 'archived' => 'Archived'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $formProduct['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      <label>Sort order<input type="number" name="sort_order" min="0" max="100000" value="<?= e((string) $formProduct['sortOrder']) ?>"></label>
    </div>
    <label class="toggle-row"><input type="checkbox" name="requires_shipping" value="1" <?= $formProduct['requiresShipping'] ? 'checked' : '' ?>><span><b>Shipping required</b><small>Collect a private delivery address at checkout.</small></span></label>
    <label>Shipping price per item<input type="number" name="shipping_price" min="0" step=".01" value="<?= e((string) $formProduct['shippingPrice']) ?>"></label>
    <h3>Images</h3><label>Upload product images<input type="file" name="product_images[]" accept="image/jpeg,image/png,image/webp" multiple><small>JPG, PNG or WebP. Up to twelve images per product.</small></label>
    <details class="merchandise-image-library"><summary>Choose / keep images from the media library</summary><div class="merchandise-image-choices">
      <?php foreach ($publicImages as $asset): ?><label><input type="checkbox" name="image_ids[]" value="<?= e((string) $asset['id']) ?>" <?= in_array((string) $asset['id'], array_map('strval', $formProduct['imageIds']), true) ? 'checked' : '' ?>><img src="<?= e(media_thumbnail_src($asset)) ?>" alt="" loading="lazy"><span><?= e((string) $asset['title']) ?></span></label><?php endforeach; ?>
    </div></details>
    <h3>Variants, sizes and stock</h3><p class="muted-copy">Stock is the available quantity after checkout reservations. Removed options remain attached to historical orders.</p>
    <div data-merchandise-variants data-next-index="<?= count($formProduct['variants']) ?>">
      <?php foreach ($formProduct['variants'] as $variantIndex => $variant) require root_path('app/Views/admin/merchandise-variant.php'); ?>
    </div>
    <template data-variant-template><?php $variantIndex = '__INDEX__'; $variant = ['label' => '', 'isEnabled' => true, 'stock' => 0]; require root_path('app/Views/admin/merchandise-variant.php'); ?></template>
    <button type="button" class="admin-button secondary" data-add-variant>Add option</button><p data-variant-status role="status"></p>
    <button class="admin-button primary full">Save Product</button>
  </form>
  <section class="admin-card merchandise-catalog"><div class="card-head"><h2>Product catalog</h2><a class="mini-button" href="<?= e(base_url('admin/?page=merchandise')) ?>">New product</a></div>
    <?php if ($products === []): ?><p>Create your first merchandise product.</p><?php endif; ?>
    <?php foreach ($products as $product): ?><article class="merchandise-admin-product"><div><h3><?= e($product['title']) ?></h3><p><?= e(ucfirst($product['status'])) ?> · <?= e(money((float) $product['price'], $product['currency'])) ?></p><small><?= count($product['variants']) ?> options · <?= array_sum(array_column($product['variants'], 'stock')) ?> available</small></div><div class="panel-actions"><a class="mini-button" href="<?= e(base_url('admin/?page=merchandise&edit=' . rawurlencode((string) $product['id']))) ?>">Edit</a><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_merchandise_product"><input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>"><button class="mini-button danger">Delete</button></form></div></article><?php endforeach; ?>
  </section>
</div>
<script src="<?= e(asset_url('js/merchandise.js')) ?>" defer></script>

<?php
declare(strict_types=1);
$availableVariants = array_values(array_filter($product['variants'], static fn(array $variant): bool => !empty($variant['isEnabled']) && (int) $variant['stock'] > 0));
$displayPrice = $availableVariants !== [] ? (float) ($availableVariants[0]['price'] ?? $product['price']) : (float) $product['price'];
?>
<form method="post" action="<?= e(base_url('cart/')) ?>" class="merchandise-buy" data-merchandise-buy>
  <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="type" value="merchandise"><input type="hidden" name="id" value="<?= e((string) $product['id']) ?>"><input type="hidden" name="mode" value="purchase">
  <strong class="merchandise-price" data-merchandise-price><?= e(money($displayPrice, $product['currency'])) ?></strong>
  <?php if ($availableVariants === []): ?><span class="merchandise-stock">Sold out</span><?php else: ?>
  <label>Size / option<select name="variant_id" required data-merchandise-variant><?php foreach ($product['variants'] as $variant): if (!$variant['isEnabled']) continue; $price = (float) ($variant['price'] ?? $product['price']); ?><option value="<?= e((string) $variant['id']) ?>" data-price="<?= e(money($price, $product['currency'])) ?>" data-stock="<?= e((string) $variant['stock']) ?>" <?= (int) $variant['stock'] < 1 ? 'disabled' : '' ?>><?= e($variant['label']) ?><?= $variant['options'] ? ' · ' . e(implode(' / ', $variant['options'])) : '' ?><?= (int) $variant['stock'] < 1 ? ' — sold out' : '' ?></option><?php endforeach; ?></select></label>
  <label>Quantity<input type="number" name="quantity" value="1" min="1" max="<?= min(99, (int) $availableVariants[0]['stock']) ?>" required></label>
  <small><?= $product['requiresShipping'] ? 'Shipping ' . e(money((float) $product['shippingPrice'], $product['currency'])) . ' per item.' : 'No shipping required.' ?></small>
  <button class="button button--primary">Add to Cart</button>
  <?php endif; ?>
</form>

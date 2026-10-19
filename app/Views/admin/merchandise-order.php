<?php
declare(strict_types=1);
if (($selectedOrder['metadata']['checkoutFlow'] ?? '') !== 'merchandise') return;
$shippingAddress = (array) ($selectedOrder['metadata']['shippingAddress'] ?? []);
$shippingStatus = (string) ($selectedOrder['metadata']['shippingStatus'] ?? 'pending');
?>
<section class="merchandise-order admin-card"><h3>Merchandise fulfillment</h3>
  <p><strong>Status:</strong> <?= e(ucfirst($shippingStatus)) ?> · <strong>Shipping:</strong> <?= e(money((float) ($selectedOrder['metadata']['shippingTotal'] ?? 0), (string) $selectedOrder['currency'])) ?></p>
  <?php if ($shippingAddress): ?><h4>Delivery address</h4><address><?php foreach (['name', 'address1', 'address2', 'city', 'region', 'postalCode', 'country'] as $field): if (!empty($shippingAddress[$field])): ?><?= e((string) $shippingAddress[$field]) ?><br><?php endif; endforeach; ?></address><?php else: ?><p>No shipping required.</p><?php endif; ?>
  <ul><?php foreach ($selectedOrder['items'] as $item): ?><li><?= e((string) $item['quantity']) ?> × <?= e($item['title']) ?> · SKU <?= e((string) ($item['metadata']['sku'] ?? '')) ?></li><?php endforeach; ?></ul>
  <?php if ($selectedOrder['status'] === 'paid'): ?><form method="post" class="compact-form"><?= csrf_field() ?><input type="hidden" name="action" value="update_merchandise_fulfillment"><input type="hidden" name="order_id" value="<?= e((string) $selectedOrder['id']) ?>"><label>Fulfillment status<select name="shipping_status"><?php $states = !empty($selectedOrder['metadata']['requiresShipping']) ? ['pending', 'packing', 'shipped', 'delivered'] : ['pending', 'packing', 'completed']; foreach ($states as $status): ?><option value="<?= e($status) ?>" <?= $shippingStatus === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select></label><label>Tracking reference<input name="tracking_reference" value="<?= e((string) ($selectedOrder['metadata']['trackingReference'] ?? '')) ?>" maxlength="190"></label><button class="admin-button primary">Update Fulfillment</button></form>
  <?php elseif (in_array($selectedOrder['status'], ['pending', 'processing'], true)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_merchandise_order"><input type="hidden" name="order_id" value="<?= e((string) $selectedOrder['id']) ?>"><button class="admin-button secondary">Cancel Pending Sandbox Order</button></form><?php endif; ?>
  <p class="muted-copy">Refunds return unshipped stock once. Items already dispatched are not restocked automatically.</p>
</section>

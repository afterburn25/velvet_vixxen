<?php declare(strict_types=1); ?>
<dl>
  <div><dt>Subtotal</dt><dd><?= e(money((float) $quote['subtotal'], (string) $quote['currency'])) ?></dd></div>
  <?php if ((float) $quote['discountTotal'] > 0): ?><div class="discount"><dt>Discount<?= !empty($quote['couponCode']) ? ' · ' . e($quote['couponCode']) : '' ?></dt><dd>−<?= e(money((float) $quote['discountTotal'], (string) $quote['currency'])) ?></dd></div><?php endif; ?>
  <?php if (!empty($quote['requiresShipping'])): ?><div><dt>Shipping</dt><dd><?= e(money((float) $quote['shippingTotal'], (string) $quote['currency'])) ?></dd></div><?php endif; ?>
  <div><dt>Configured tax</dt><dd><?= e(money((float) $quote['taxTotal'], (string) $quote['currency'])) ?></dd></div>
  <div class="total"><dt>Total</dt><dd><?= e(money((float) $quote['grandTotal'], (string) $quote['currency'])) ?></dd></div>
</dl>

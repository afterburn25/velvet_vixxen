<?php
declare(strict_types=1);
?>
<?php foreach ($quote['items'] as $quotedItem): if (!isset($quotedItem['metadata']['purchaseTerms'])) continue;
    $terms = App\Core\PurchasedTerms::validate($quotedItem['metadata']['purchaseTerms'])['terms']; ?>
<section class="purchased-terms" data-checkout-purchased-terms="<?= e($terms['kind']) ?>">
  <h3>Purchase terms</h3>
  <?php if ($terms['kind'] === 'plan'): ?>
    <p><?= e(money($terms['initialTotalMinor'] / 100, $terms['currency'])) ?> now. <?= $terms['interval'] === 'one_time' ? 'One-time membership.' : 'Sandbox renewal: ' . e(money($terms['recurringMinor'] / 100, $terms['currency'])) . ' every ' . e((string) $terms['intervalCount']) . ' ' . e($terms['interval']) . ($terms['intervalCount'] === 1 ? '' : 's') . '.' ?></p>
    <p>Coupons and sandbox tax apply to the initial payment only.<?= $terms['trialDays'] > 0 ? ' The initial charge starts a ' . e((string) $terms['trialDays']) . '-day trial period.' : '' ?> Purchased PPV discount: <?= e(number_format($terms['ppvDiscountBasisPoints'] / 100, 2)) ?>%.</p>
  <?php else: ?>
    <p>This purchase includes <?= e((string) count($terms['items'])) ?> saved content items. Later bundle edits do not change this purchase.</p>
  <?php endif; ?>
</section>
<?php endforeach; ?>

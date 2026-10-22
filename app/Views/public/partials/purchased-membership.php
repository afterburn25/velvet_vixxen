<?php
declare(strict_types=1);
$termsState = (string) ($sub['purchasedTermsState'] ?? 'legacy');
?>
<div class="purchased-terms" data-purchased-terms-state="<?= e($termsState) ?>">
<?php if ($termsState === 'accepted'): $accepted = $sub['purchasedTerms']['terms']; ?>
  <small>Purchased terms: <?= e(money($accepted['recurringMinor'] / 100, $accepted['currency'])) ?><?= $accepted['interval'] === 'one_time' ? ' one time' : ' every ' . e((string) $accepted['intervalCount']) . ' ' . e($accepted['interval']) . ($accepted['intervalCount'] === 1 ? '' : 's') ?>.</small>
  <small>Initial total <?= e(money($accepted['initialTotalMinor'] / 100, $accepted['currency'])) ?>. Coupons and sandbox tax apply to the initial payment only.</small>
  <?php if ($accepted['trialDays'] > 0): ?><small>The initial charge starts a <?= e((string) $accepted['trialDays']) ?>-day trial period.</small><?php endif; ?>
  <small>Purchased PPV discount: <?= e(number_format($accepted['ppvDiscountBasisPoints'] / 100, 2)) ?>%.</small>
<?php elseif ($termsState === 'held'): ?>
  <small class="billing-warning">Purchased terms need review. Automatic pricing changes are held.</small>
<?php else: ?>
  <small>Legacy membership: original purchase terms were not recorded. Saved access dates remain in effect; sandbox pricing uses the current plan.</small>
<?php endif; ?>
</div>

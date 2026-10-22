<?php
declare(strict_types=1);
$detail = $paymentRecoveryRequest['record'] !== null;
$rows = $paymentRecoveryPage['rows'];
?>
<div class="payment-recovery" data-payment-recovery>
<section class="admin-card payment-recovery-intro">
  <span class="admin-kicker">Commerce</span>
  <h2>Review payment outcomes and order follow-ups</h2>
  <p>Inspect saved payment, refund and provider-event records. Check an uncertain outcome using its original operation, then run eligible order follow-ups separately.</p>
  <p>Checking an outcome never submits another charge or refund. Receipts and connected add-ons can run only through an eligible follow-up action.</p>
  <div class="payment-recovery-links"><a href="<?= e(base_url('admin/?page=orders')) ?>">Orders</a><a href="<?= e(base_url('admin/?page=payments')) ?>">Payment settings</a></div>
</section>
<?php if ($paymentRecoveryReadFailed): ?>
<section class="admin-card"><h2>Payment records unavailable</h2><p>Reload the saved records after resolving the reported problem.</p><a class="admin-button" href="<?= e(vvx_payment_recovery_url()) ?>">Return to payment records</a></section>
</div>
<?php return; endif; ?>
<?php if ($paymentRecoveryPage['confirmation']): ?>
<section class="admin-card payment-recovery-notice"><h2>Confirm your identity before taking action</h2><p>You can review these records now. Payment recovery actions require your password and, when enabled, your authenticator or recovery code.</p><a class="admin-button primary" href="<?= e(base_url('admin/?page=security')) ?>">Confirm in Security</a></section>
<?php endif; ?>
<?php if ($detail): ?>
<a class="payment-recovery-back" href="<?= e(vvx_payment_recovery_url($paymentRecoveryRequest, ['record' => null])) ?>">← Back to payment records</a>
<?php else: ?>
<section class="admin-card" aria-labelledby="payment-filter-heading">
  <h2 id="payment-filter-heading">Find saved records</h2>
  <form method="get" action="<?= e(base_url('admin/')) ?>" class="payment-recovery-filters">
    <input type="hidden" name="page" value="payment-recovery">
    <label>Show<select name="scope"><option value="open" <?= $paymentRecoveryRequest['scope'] === 'open' ? 'selected' : '' ?>>Open records</option><option value="all" <?= $paymentRecoveryRequest['scope'] === 'all' ? 'selected' : '' ?>>All records</option></select></label>
    <label>Order ID <span class="payment-recovery-optional">(optional)</span><input name="order" maxlength="190" value="<?= e((string) ($paymentRecoveryRequest['order'] ?? '')) ?>" autocomplete="off"></label>
    <button class="admin-button primary">Apply filters</button>
  </form>
  <p class="payment-recovery-count"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?> on this page. Each page contains up to 50 records. Open records include pending and held work.</p>
</section>
<?php endif; ?>
<?php if ($rows === []): ?>
<section class="admin-card"><h2>No matching records on this page</h2><p>New payment activity appears here as it is recorded. Existing orders created before payment recovery may have no recovery record.</p><?php if ($paymentRecoveryRequest['after'] !== null): ?><a class="admin-button" href="<?= e(vvx_payment_recovery_url($paymentRecoveryRequest, ['after' => null])) ?>">Return to first page</a><?php endif; ?></section>
<?php endif; ?>
<div class="payment-recovery-records">
<?php foreach ($rows as $record): ?>
  <article class="admin-card payment-recovery-record" data-recovery-record="<?= e($record['id']) ?>">
    <header><div><span class="admin-kicker"><?= e(vvx_payment_recovery_kind($record)) ?></span><h2><?= $record['orderNumber'] !== null ? 'Order ' . e((string) $record['orderNumber']) : 'Unmatched provider record' ?></h2></div><span class="payment-recovery-state"><?= e(vvx_payment_recovery_state($record['state'])) ?></span></header>
    <p><?= e(vvx_payment_recovery_reason($record)) ?></p>
    <dl class="payment-recovery-facts">
      <?php if ($record['provider'] !== null): ?><div><dt>Provider</dt><dd><?= e($record['provider']) ?></dd></div><?php endif; ?>
      <?php if ($record['amountMinor'] !== null && $record['currency'] !== null): ?><div><dt>Operation amount</dt><dd><?= e(money($record['amountMinor'] / 100, $record['currency'])) ?></dd></div><?php endif; ?>
      <div><dt>Updated (UTC)</dt><dd><time datetime="<?= e(gmdate('c', $record['updatedAt'])) ?>"><?= e(gmdate('Y-m-d H:i:s', $record['updatedAt'])) ?></time></dd></div>
      <div class="payment-recovery-wide"><dt>Record ID</dt><dd><code><?= e($record['id']) ?></code></dd></div>
      <?php if ($detail && $record['operationId'] !== null && $record['operationId'] !== $record['id']): ?><div class="payment-recovery-wide"><dt>Original operation</dt><dd><code><?= e($record['operationId']) ?></code></dd></div><?php endif; ?>
      <?php if ($detail): ?><div><dt>Created (UTC)</dt><dd><time datetime="<?= e(gmdate('c', $record['createdAt'])) ?>"><?= e(gmdate('Y-m-d H:i:s', $record['createdAt'])) ?></time></dd></div><?php endif; ?>
    </dl>
    <div class="payment-recovery-links">
      <?php if (!$detail): ?><a class="admin-button" href="<?= e(vvx_payment_recovery_url($paymentRecoveryRequest, ['record' => $record['id']])) ?>" aria-label="<?= e('Review ' . vvx_payment_recovery_kind($record) . ' record ' . $record['id']) ?>">Review record</a><?php endif; ?>
      <?php if ($record['orderId'] !== null): ?><a href="<?= e(base_url('admin/?page=orders&order=' . rawurlencode($record['orderId']))) ?>">Open order</a><?php endif; ?>
    </div>
    <?php if ($detail && !$paymentRecoveryPage['confirmation'] && ($record['canReconcile'] || $record['canRetryEffects'])): ?>
    <section class="payment-recovery-action" aria-labelledby="payment-recovery-action-heading">
      <h3 id="payment-recovery-action-heading"><?= $record['canReconcile'] ? 'Check the saved payment outcome' : 'Run eligible follow-ups for this order' ?></h3>
      <p><?= $record['canReconcile'] ? 'This checks the original operation or completes its already confirmed local updates. It does not send a new payment request, receipt or add-on notification.' : 'This can send a pending receipt and process eligible connected integrations for this order. Completed, suppressed and uncertain deliveries remain unchanged.' ?></p>
      <form method="post" action="<?= e(vvx_payment_recovery_url($paymentRecoveryRequest)) ?>">
        <?= csrf_field() ?><input type="hidden" name="record" value="<?= e($record['id']) ?>"><input type="hidden" name="action" value="<?= $record['canReconcile'] ? 'payment_recovery_reconcile' : 'payment_recovery_retry_effects' ?>">
        <button class="admin-button primary"><?= $record['canReconcile'] ? 'Check payment outcome' : 'Run eligible order follow-ups' ?></button>
      </form>
    </section>
    <?php elseif ($detail && !$paymentRecoveryPage['confirmation']): ?>
    <p class="payment-recovery-notice">No automatic recovery action is available for this record. Review its status and the linked order before taking further action.</p>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
<?php if (!$detail && ($paymentRecoveryPage['nextCursor'] !== null || $paymentRecoveryRequest['after'] !== null)): ?>
<nav class="payment-recovery-pagination" aria-label="Payment records pages"><a class="admin-button" href="<?= e(vvx_payment_recovery_url($paymentRecoveryRequest, ['after' => null])) ?>">First page</a><?php if ($paymentRecoveryPage['nextCursor'] !== null): ?><a class="admin-button primary" href="<?= e(vvx_payment_recovery_url($paymentRecoveryRequest, ['after' => $paymentRecoveryPage['nextCursor']])) ?>">Next page</a><?php endif; ?></nav>
<?php endif; ?>
</div>

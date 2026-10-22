<?php
declare(strict_types=1);

if (!$isPaymentRecoveryRequest || $page !== 'payment-recovery'
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !verify_csrf()) {
    throw new RuntimeException('Open Payment recovery and submit its owner form.', 400);
}
$action = $_POST['action'] ?? null;
if (!is_string($action) || !in_array($action, ['payment_recovery_reconcile', 'payment_recovery_retry_effects'], true)) {
    throw new InvalidArgumentException('Choose an available payment recovery action.', 400);
}
$recordId = vvx_payment_recovery_record_id($_POST['record'] ?? null);
if ($paymentRecoveryRequest['record'] !== $recordId) {
    throw new InvalidArgumentException('Submit the form for the recovery record you are reviewing.', 400);
}
$authorize = static function (): void { app_auth()->requireRecentOwner(); };
$authorize();
$recovery = new App\Core\PaymentRecoveryService(app_store());
$record = $recovery->inspect($recordId);
if ($paymentRecoveryRequest['order'] !== null && $record['orderId'] !== $paymentRecoveryRequest['order']) {
    throw new RuntimeException('This recovery record does not belong to the selected order.', 404);
}
if ($action === 'payment_recovery_reconcile') {
    if (!$record['canReconcile'] || !is_string($record['operationId'])) {
        throw new RuntimeException('This record has no payment outcome available to check. Reload its current status.', 409);
    }
    commerce_service()->reconcilePaymentOperation($record['operationId'], $authorize);
    $message = 'Payment outcome checked. ';
} else {
    if (!$record['canRetryEffects'] || !is_string($record['orderId'])) {
        throw new RuntimeException('This follow-up is not eligible to run. Reload its current status.', 409);
    }
    commerce_service()->retryPaymentEffects($record['orderId'], $authorize);
    $message = 'Eligible order follow-ups processed. ';
}
$authorize();
$current = $recovery->inspect($recordId);
app_store()->audit(app_auth()->id(), 'commerce.payment_recovery.' . ($action === 'payment_recovery_reconcile' ? 'checked' : 'followups'),
    ['recordId' => $recordId, 'orderId' => $current['orderId'], 'state' => $current['state']]);
flash('success', $message . 'Current record status: ' . vvx_payment_recovery_state($current['state']) . '.');
redirect(vvx_payment_recovery_url($paymentRecoveryRequest));

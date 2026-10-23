<?php
declare(strict_types=1);

use App\Core\PaymentRecoveryService;

function vvx_payment_recovery_owner(): array
{
    $owner = app_auth()->user();
    if (!is_array($owner) || ($owner['role'] ?? '') !== 'owner') {
        throw new RuntimeException('Only the current site owner can review payment recovery.', 403);
    }
    return $owner;
}

function vvx_payment_recovery_record_id(mixed $value): string
{
    if (!is_string($value) || preg_match('/\A(?:pay_[a-f0-9]{32}|evt_[a-f0-9]{64}|fx_[a-f0-9]{64})\z/D', $value) !== 1) {
        throw new InvalidArgumentException('Choose a saved payment recovery record.', 400);
    }
    return $value;
}

function vvx_payment_recovery_request(): array
{
    if (isset($_GET['view']) && $_GET['view'] !== 'recurring') throw new InvalidArgumentException('Choose a valid payment recovery view.',400);
    if (($_GET['view'] ?? '') === 'recurring') {
        $request=['view'=>'recurring','scope'=>'all','after'=>null,'order'=>null,'record'=>null,'agreement'=>null,'journalAfter'=>null];
        foreach (['after','agreement'] as $key) if (isset($_GET[$key])) $request[$key]=App\Core\RecurringContract::id($_GET[$key]);
        if (isset($_GET['journalAfter'])) {
            if (!is_string($_GET['journalAfter']) || preg_match('/\A(?:rcmd_|rcyc_|revt_)[a-f0-9]{64}\z/D',$_GET['journalAfter'])!==1 || $request['agreement']===null) throw new InvalidArgumentException('Invalid recurring request page.',400);
            $request['journalAfter']=$_GET['journalAfter'];
        }
        return $request;
    }
    $scope = $_GET['scope'] ?? 'open';
    if (!is_string($scope) || !in_array($scope, ['open', 'all'], true)) {
        throw new InvalidArgumentException('Choose Open records or All records.', 400);
    }
    $request = ['scope' => $scope, 'after' => null, 'order' => null, 'record' => null];
    foreach (['after', 'record'] as $key) {
        if (isset($_GET[$key])) $request[$key] = vvx_payment_recovery_record_id($_GET[$key]);
    }
    if (isset($_GET['order']) && $_GET['order'] !== '') {
        $order = $_GET['order'];
        if (!is_string($order) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,189}\z/D', $order) !== 1) {
            throw new InvalidArgumentException('Choose a valid saved order.', 400);
        }
        if (app_store()->mode() !== 'file' && !ctype_digit($order)) {
            throw new InvalidArgumentException('Choose a valid saved order.', 400);
        }
        $request['order'] = $order;
    }
    return $request;
}

function vvx_payment_recovery_url(array $request = [], array $changes = []): string
{
    $values = array_replace(['scope' => 'open', 'after' => null, 'order' => null, 'record' => null], $request, $changes);
    $query = ['page' => 'payment-recovery'];
    foreach (['scope', 'after', 'order', 'record', 'view', 'agreement', 'journalAfter'] as $key) {
        if (isset($values[$key]) && is_string($values[$key]) && $values[$key] !== '') $query[$key] = $values[$key];
    }
    return base_url('admin/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
}

function vvx_payment_recovery_read(array $request): array
{
    vvx_payment_recovery_owner();
    if (($request['view'] ?? '') === 'recurring') {
        $service=new App\Core\RecurringRecoveryService(app_store());
        $result=$request['agreement']===null ? $service->page($request['after']) : ['rows'=>[$service->inspect($request['agreement'],$request['journalAfter'])],'nextCursor'=>null];
        $confirmation=false;
        try { app_auth()->requireRecentOwner(); } catch (RuntimeException $e) { if($e->getCode()!==428)throw $e;$confirmation=true; }
        vvx_payment_recovery_owner();return $result+['confirmation'=>$confirmation];
    }
    $service = new PaymentRecoveryService(app_store());
    if ($request['record'] !== null) {
        $row = $service->inspect($request['record']);
        if ($request['order'] !== null && $row['orderId'] !== $request['order']) {
            throw new RuntimeException('This recovery record does not belong to the selected order.', 404);
        }
        $result = ['rows' => [$row], 'nextCursor' => null];
    } else {
        $result = $service->page($request['after'], 50, $request['scope'] === 'open', $request['order']);
    }
    $confirmation = false;
    try { app_auth()->requireRecentOwner(); }
    catch (RuntimeException $error) {
        if ($error->getCode() !== 428) throw $error;
        $confirmation = true;
    }
    vvx_payment_recovery_owner();
    return $result + ['confirmation' => $confirmation];
}

function vvx_payment_recovery_state(string $state): string
{
    return match ($state) {
        'prepared' => 'Prepared', 'attempting' => 'Outcome needs checking',
        'unknown' => 'Outcome unknown', 'pending' => 'Pending',
        'confirmed' => 'Confirmed; local updates pending', 'applied' => 'Applied',
        'declined' => 'Declined', 'held' => 'Held for review', 'received' => 'Received',
        'processing' => 'Processing needs review', 'retryable' => 'Safe to retry',
        'done' => 'Completed', 'skipped' => 'Skipped', 'ignored' => 'Ignored',
        default => 'Review required',
    };
}

function vvx_payment_recovery_kind(array $row): string
{
    if ($row['kind'] === 'event') return 'Provider event';
    if ($row['kind'] === 'effect') {
        return match ($row['effect']) {
            'receipt' => 'Order receipt', 'marketing' => 'Purchase attribution',
            'addon' => 'Add-on follow-up', 'inventory' => 'Inventory review',
            default => 'Order follow-up',
        };
    }
    return match ($row['operationType']) {
        'refund' => 'Refund', 'chargeback' => 'Chargeback', 'renewal' => 'Renewal',
        default => 'Payment',
    };
}

function vvx_payment_recovery_reason(array $row): string
{
    return match ($row['reasonCode']) {
        'transport_unknown' => 'The provider response was interrupted. Check the saved operation before making another payment attempt.',
        'pending_provider' => 'The provider has not returned a final outcome yet.',
        'scope_changed' => 'The payment provider or account configuration has changed. Restore the original configuration before checking this operation.',
        'malformed_result', 'binding_conflict' => 'The provider result could not be matched safely to this operation. Review the provider records before taking further action.',
        'local_effect_failed' => 'The provider outcome is saved, but a local update needs recovery. Checking the outcome can finish eligible local updates.',
        'external_interrupted', 'external_unknown' => 'A follow-up may already have been delivered. It remains held until its outcome can be established; this screen cannot resend it.',
        'external_retryable' => 'This follow-up is recorded as safe to retry.',
        'account_closed' => 'The member account is closing or closed. New charges and contact or integration follow-ups are stopped. Accepted payment outcomes can still be reconciled.',
        'account_ineligible' => 'The member account is no longer active and verified. This new charge was stopped before contacting the provider.',
        'disabled' => 'This follow-up was skipped because its delivery option is disabled.',
        'inventory_review' => 'Review retained fulfillment records before changing physical stock. Automatic stock return is held because fulfillment evidence is unavailable.',
        default => $row['state'] === 'held' ? 'This record needs review before any further action.' : 'The saved status is shown below.',
    };
}

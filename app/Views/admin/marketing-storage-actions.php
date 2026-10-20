<?php
declare(strict_types=1);

$marketingStorageOwner = vvx_marketing_storage_require_owner();
$marketingStorageAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$marketingStorageService = new Velvet\Core\MarketingStorage(ROOT_PATH);

if ($marketingStorageAction === 'preview_marketing_storage') {
    unset($_SESSION['_marketing_storage_review']);
    $marketingStorageView = $marketingStorageService->status();
    if (empty($marketingStorageView['can_migrate'])) {
        http_response_code(409);
        throw new RuntimeException('A move is not available. Review the current storage status below.');
    }
    $marketingStorageReview = $marketingStorageView;
    $_SESSION['_marketing_storage_review'] = [
        'owner' => (string) $marketingStorageOwner['id'],
        'plan' => $marketingStorageReview['plan'],
        'issued' => time(),
    ];
    return;
}

if ($marketingStorageAction !== 'migrate_marketing_storage') {
    http_response_code(400);
    throw new RuntimeException('Choose a valid marketing storage action.');
}

$marketingStoragePlan = is_string($_POST['plan'] ?? null) ? $_POST['plan'] : '';
$marketingStorageClaim = $_SESSION['_marketing_storage_review'] ?? null;
unset($_SESSION['_marketing_storage_review']);
if (!preg_match('/\A[a-f0-9]{64}\z/', $marketingStoragePlan)
    || !is_array($marketingStorageClaim)
    || ($marketingStorageClaim['owner'] ?? '') !== (string) $marketingStorageOwner['id']
    || !is_string($marketingStorageClaim['plan'] ?? null)
    || !hash_equals($marketingStorageClaim['plan'], $marketingStoragePlan)
    || (int) ($marketingStorageClaim['issued'] ?? 0) < time() - 1800) {
    http_response_code(409);
    throw new RuntimeException('Preview the move again before continuing. The earlier review expired or was already used.');
}

$marketingStorageView = $marketingStorageService->migrate($marketingStoragePlan, static function (): void {
    vvx_marketing_storage_require_owner();
});
vvx_marketing_storage_audit();
flash('success', 'Marketing is now stored in MySQL. The original file was preserved. No email was sent.');
redirect(base_url('admin/?page=marketing-storage'));

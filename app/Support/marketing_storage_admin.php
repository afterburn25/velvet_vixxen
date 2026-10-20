<?php
declare(strict_types=1);

require_once root_path('app/Core/MarketingStorage.php');

/** Recheck the saved account, including when a move has waited for a lock. */
function vvx_marketing_storage_require_owner(): array
{
    $session = app_auth()->user();
    try {
        $fresh = new App\Core\Store(new App\Core\Config(app_config()->path()));
        $owner = $fresh->findAdminByEmail((string) ($session['email'] ?? ''));
    } catch (Throwable) {
        throw new RuntimeException('Only the active site owner can manage marketing storage.', 403);
    }
    if (!$owner || empty($owner['is_active']) || ($owner['role'] ?? '') !== 'owner'
        || (string) ($owner['id'] ?? '') !== (string) ($session['id'] ?? '')) {
        // Throw instead of exiting so the storage service can release its locks
        // and roll back if access changes while the request is waiting.
        throw new RuntimeException('Only the active site owner can manage marketing storage.', 403);
    }
    return $owner;
}

/** Display aggregate totals without rendering record keys or private values. */
function vvx_marketing_storage_count_labels(): array
{
    return [
        'posts' => 'Posts',
        'banners' => 'Banners',
        'landing_pages' => 'Campaign pages',
        'links' => 'Links',
        'referral_codes' => 'Referral codes',
        'contacts' => 'Newsletter contacts',
        'segments' => 'Saved audiences',
        'campaigns' => 'Email campaigns',
        'campaign_queue' => 'Delivery records',
        'events' => 'Analytics events',
        'consents' => 'Consent records',
        'suppressions' => 'Suppression records',
    ];
}

function vvx_marketing_storage_audit(): void
{
    try {
        app_store()->audit(app_auth()->id(), 'system.marketing_storage.mysql');
    } catch (Throwable) {
        // A logging problem must not report a completed move as a failure.
    }
}

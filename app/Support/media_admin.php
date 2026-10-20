<?php
declare(strict_types=1);

/** Resolve the current stored account before allowing Media Center changes. */
function vvx_media_require_owner(): array
{
    $session = app_auth()->user();
    $owner = app_store()->findAdminByEmail((string) ($session['email'] ?? ''));
    if (!$owner || empty($owner['is_active']) || ($owner['role'] ?? '') !== 'owner'
        || (string) ($owner['id'] ?? '') !== (string) ($session['id'] ?? '')) {
        http_response_code(403);
        exit('Only the active site owner can manage media.');
    }
    return $owner;
}

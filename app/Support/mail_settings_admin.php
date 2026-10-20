<?php
declare(strict_types=1);

/** Recheck the current account; an old owner role in the session is insufficient. */
function vvx_mail_settings_require_owner(): array
{
    $session = app_auth()->user();
    $owner = app_store()->findAdminByEmail((string) ($session['email'] ?? ''));
    if (!$owner || empty($owner['is_active']) || ($owner['role'] ?? '') !== 'owner'
        || (string) ($owner['id'] ?? '') !== (string) ($session['id'] ?? '')) {
        http_response_code(403);
        exit('Only the active site owner can manage email settings.');
    }
    return $owner;
}

/** Only fixed, non-sensitive metadata enters the ordinary activity log. */
function vvx_mail_settings_audit(string $event, array $metadata = []): void
{
    try {
        app_store()->audit(app_auth()->id(), $event, $metadata);
    } catch (Throwable) {
        // An audit failure must not turn a completed save or accepted test into
        // an apparent failure that encourages an accidental repeat transmission.
    }
}

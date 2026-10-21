<?php
declare(strict_types=1);

function vvx_privacy_private(): void
{
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}

/** Privacy reads do not touch activity timestamps or initialize another subsystem. */
function vvx_privacy_member(App\Core\MemberPrivacyService $service): array
{
    $session = $_SESSION['member'] ?? null;
    if (!is_array($session) || !is_string($session['id'] ?? null) || !is_string($session['token'] ?? null)) {
        throw new RuntimeException('Sign in to your verified account before opening Account & privacy.', 403);
    }
    return [$service->currentMember($session['id'], $session['token']), $session['token']];
}

function vvx_privacy_owner(bool $recent = false): array
{
    $identity = app_auth()->user();
    if (!$identity || ($identity['role'] ?? '') !== 'owner') throw new RuntimeException('Only the current site owner can manage privacy requests.', 403);
    (new App\Core\Store(new App\Core\Config(app_config()->path())))->requireSceneScheduleOwner($identity);
    if ($recent) app_auth()->requireRecentOwner();
    return $identity;
}

function vvx_privacy_field(string $name, int $limit = 256, string $default = ''): string
{
    $value = $_POST[$name] ?? $default;
    if (!is_string($value) || strlen($value) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
        throw new InvalidArgumentException('Reload the privacy form and enter a valid value.');
    }
    return $value;
}

function vvx_privacy_error(Throwable $error): string
{
    $code = $error instanceof InvalidArgumentException ? 400 : (int) $error->getCode();
    if (!in_array($code, [400, 403, 404, 409, 419, 428, 429], true)) $code = 503;
    http_response_code($code);
    return $code === 503 ? 'Private account records are unavailable. The owner must review storage before this request can continue.' : $error->getMessage();
}

function vvx_privacy_date(?int $at): string { return $at === null ? 'Unavailable' : gmdate('M j, Y · H:i', $at) . ' UTC'; }

function vvx_privacy_status(string $status): string
{
    return match ($status) {
        'requested' => 'Waiting for owner review', 'approved' => 'Approved for processing', 'processing' => 'Processing',
        'held' => 'Needs a new owner review', 'complete' => 'Complete', 'canceled' => 'Canceled',
        'declined' => 'Declined by owner', 'expired' => 'Download expired', 'revoked' => 'Download revoked', default => 'Needs review',
    };
}

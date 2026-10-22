<?php
declare(strict_types=1);

function vvx_protection_service(): App\Core\ProtectionRecords { return new App\Core\ProtectionRecords(app_config()); }
function vvx_protection_owner(): array
{
    $identity = app_auth()->user();
    if (!is_array($identity)) throw new RuntimeException('Only the current site owner can open Protection & policies.', 403);
    vvx_protection_service()->requireOwner($identity); return $identity;
}
function vvx_protection_private(): void
{
    header('Cache-Control: no-store, private, max-age=0'); header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive'); header('Referrer-Policy: no-referrer');
}
function vvx_protection_response_code(int $status): void
{
    vvx_response_status($status);
}
function vvx_protection_request(): array
{
    $tab = $_GET['tab'] ?? 'performers'; $id = $_GET['record'] ?? '';
    if (!is_string($tab) || !in_array($tab, ['performers', 'releases', 'cases', 'media'], true) || !is_string($id)) throw new InvalidArgumentException('Choose Collaborators, Releases, Cases or Kept offline.');
    if ($tab === 'media' && $id !== '') throw new InvalidArgumentException('Review media from the Kept offline list.');
    if ($id !== '' && $id !== 'new') App\Core\ProtectionRecords::id($id);
    if ($tab === 'cases' && $id === 'new') throw new InvalidArgumentException('Cases start from the public report form.');
    return ['tab' => $tab, 'record' => $id];
}
function vvx_protection_url(string $tab = 'performers', string $record = ''): string
{
    return base_url('admin/?page=protection&tab=' . rawurlencode($tab) . ($record === '' ? '' : '&record=' . rawurlencode($record)));
}
function vvx_protection_status(string $status, string $collection = ''): string
{
    if ($collection === 'performers' && $status === 'active') return 'Active';
    return match ($status) {
        'active' => 'Approved', 'inactive' => 'Inactive', 'draft' => 'Draft', 'revoked' => 'Revoked',
        'received' => 'Received', 'in_review' => 'In review', 'waiting_reporter' => 'Waiting for a reply',
        'taken_down' => 'Withdrawn', 'appealed' => 'Appeal received', 'closed' => 'Closed', 'reinstated' => 'Hold lifted', default => 'Needs review',
    };
}
function vvx_protection_kind(string $kind): string
{
    return ['post' => 'Post', 'landing_page' => 'Landing page', 'gallery' => 'Gallery', 'video' => 'Video',
        'track' => 'Track', 'album' => 'Album', 'playlist' => 'Playlist', 'asset' => 'Media file'][$kind] ?? 'Saved item';
}
function vvx_protection_targets(mixed $raw): array
{
    if (!is_array($raw) || !array_is_list($raw) || count($raw) > 20) throw new InvalidArgumentException('Choose up to 20 linked items.');
    $result = [];
    foreach ($raw as $value) {
        if (!is_string($value) || !str_contains($value, ':')) throw new InvalidArgumentException('Choose saved items from the list.');
        [$kind, $id] = explode(':', $value, 2); $result[] = ['kind' => $kind, 'id' => $id];
    }
    return App\Core\ProtectionRecords::targets($result);
}
function vvx_protection_attempt(): array
{
    $result = [];
    foreach (['id', 'revision', 'content_revision', 'config_revision', 'state_revision', 'label', 'status', 'document_reference', 'notes', 'reviewed_on', 'expires_on', 'decision', 'message', 'public_message'] as $key) {
        $value = $_POST[$key] ?? '';
        $result[$key] = is_string($value) && strlen($value) <= 6000 ? $value : '';
    }
    foreach (['performer_ids', 'targets'] as $key) $result[$key] = is_array($_POST[$key] ?? null)
        ? array_values(array_filter(array_slice($_POST[$key], 0, 20), static fn(mixed $value): bool => is_string($value) && strlen($value) <= 180)) : [];
    $result['consent_reviewed'] = ($_POST['consent_reviewed'] ?? '') === '1';
    return $result;
}
function vvx_protection_read(array $request): array
{
    $owner = vvx_protection_owner(); $service = vvx_protection_service();
    $overview = $service->overview($owner); $review = null; $choices = []; $withdrawal = null; $confirmation = false;
    if ($request['record'] !== '') {
        try { app_auth()->requireRecentOwner(); }
        catch (RuntimeException $error) { if ($error->getCode() !== 428) throw $error; $confirmation = true; }
        if (!$confirmation) {
            if ($request['record'] !== 'new') $review = $service->review($request['tab'], $request['record'], $owner, static fn() => app_auth()->requireRecentOwner());
            $resources = new App\Core\ProtectionResources(app_config());
            if (in_array($request['tab'], ['releases', 'cases'], true)) {
                foreach ($resources->catalog() as $kind => $rows) foreach ($rows as $row) {
                    $choices[] = ['value' => $kind . ':' . (string) $row['id'], 'label' => vvx_protection_kind($kind) . ' · ' . (string) ($row['title'] ?? $row['name'] ?? 'Saved item')];
                }
            }
            if ($review !== null) {
                $links = $request['tab'] === 'performers' ? $service->performerLinks($service->snapshot(), $review['id'])
                    : ['targets' => $review['targets'], 'asset_ids' => $review['asset_ids']];
                $withdrawal = $resources->withdrawalReview($links['targets'], $links['asset_ids']);
            }
            app_auth()->requireRecentOwner();
        }
    }
    vvx_protection_owner();
    return compact('overview', 'review', 'choices', 'withdrawal', 'confirmation');
}

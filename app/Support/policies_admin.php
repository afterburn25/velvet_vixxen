<?php
declare(strict_types=1);

function vvx_policies_owner(): array
{
    $owner = app_auth()->user();
    if (!is_array($owner)) throw new RuntimeException('Only the current owner can manage Policies & access.', 403);
    (new App\Core\PublicPolicies(app_config()))->requireOwner($owner); return $owner;
}

function vvx_policies_request(): array
{
    $tab = $_GET['tab'] ?? 'documents'; $document = $_GET['document'] ?? 'terms';
    if (!is_string($tab) || !in_array($tab, ['documents', 'access'], true)) throw new InvalidArgumentException('Choose Public policies or Age & location.');
    return ['tab' => $tab, 'document' => App\Core\PublicPolicies::name($document)];
}

function vvx_policies_url(string $tab = 'documents', string $document = 'terms'): string
{
    return base_url('admin/?page=policies&tab=' . rawurlencode($tab) . '&document=' . rawurlencode($document));
}

function vvx_policies_attempt(): array
{
    $result = [];
    foreach (['revision', 'title', 'body', 'mode', 'minimum_age', 'session_minutes', 'sdk_id', 'country_header', 'trusted_proxies', 'blocked_countries'] as $key) {
        $value = $_POST[$key] ?? ''; $result[$key] = is_string($value) && strlen($value) <= 30000 ? $value : '';
    }
    foreach (['geographic_enabled', 'clear_api_key', 'reviewed_rules', 'reviewed_policy'] as $key) $result[$key] = ($_POST[$key] ?? '') === '1';
    return $result;
}

function vvx_policies_read(array $request): array
{
    $owner = vvx_policies_owner(); $confirmation = false; $document = null; $configuration = null;
    try { app_auth()->requireRecentOwner(); }
    catch (RuntimeException $error) { if ($error->getCode() !== 428) throw $error; $confirmation = true; }
    if (!$confirmation) {
        if ($request['tab'] === 'documents') $document = (new App\Core\PublicPolicies(app_config()))->read($request['document'], $owner);
        else $configuration = (new App\Core\AgeAccess(app_config()))->configuration($owner);
        app_auth()->requireRecentOwner();
    }
    vvx_policies_owner(); return compact('confirmation', 'document', 'configuration');
}

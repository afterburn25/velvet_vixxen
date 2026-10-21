<?php
declare(strict_types=1);
if (!$isPoliciesRequest || $page !== 'policies' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !verify_csrf()) throw new RuntimeException('Open Policies & access and submit its owner form.', 400);
$owner = vvx_policies_owner(); $guard = static fn() => app_auth()->requireRecentOwner(); $guard();
$action = $_POST['action'] ?? null;
if (!is_string($_POST['revision'] ?? null)) throw new InvalidArgumentException('Reload the current policy or access form.');
if ($action === 'policies_access_save' && $policiesRequest['tab'] === 'access') {
    $input = vvx_policies_attempt(); $input['api_key'] = $_POST['api_key'] ?? '';
    (new App\Core\AgeAccess(app_config()))->saveConfiguration($input, $_POST['revision'], $owner, $guard);
    flash('success', 'Age and location settings saved. No provider check was started.');
} elseif ($policiesRequest['tab'] === 'documents' && ($_POST['document'] ?? '') === $policiesRequest['document']) {
    $service = new App\Core\PublicPolicies(app_config()); $name = $policiesRequest['document'];
    if ($action === 'policies_draft_save') { $service->save($name, ['title' => $_POST['title'] ?? null, 'body' => $_POST['body'] ?? null], $_POST['revision'], $owner, $guard); flash('success', 'Draft saved. The current public policy is unchanged.'); }
    elseif ($action === 'policies_publish') { $service->publish($name, $_POST['revision'], ($_POST['reviewed_policy'] ?? '') === '1', $owner, $guard); flash('success', 'The reviewed draft is now public.'); }
    elseif ($action === 'policies_unpublish') { $service->unpublish($name, $_POST['revision'], $owner, $guard); flash('success', 'Policy withdrawn from public view. Its draft is retained.'); }
    else throw new InvalidArgumentException('Choose a public policy action.');
} else throw new InvalidArgumentException('Submit the form matching this policy or access tab.');
redirect(vvx_policies_url($policiesRequest['tab'], $policiesRequest['document']));

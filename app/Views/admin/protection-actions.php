<?php
declare(strict_types=1);

if (!$isProtectionRequest || $page !== 'protection' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !verify_csrf()) throw new RuntimeException('Open Protection & policies and submit the owner form.', 400);
$owner = vvx_protection_owner();
$guard = static fn() => app_auth()->requireRecentOwner(); $guard();
$service = vvx_protection_service();
$action = $_POST['action'] ?? null;
if ($action === 'protection_activate') {
    if (($_POST['confirm_private'] ?? '') !== '1') throw new InvalidArgumentException('Confirm that managed public uploads can move into private storage.');
    foreach (['config_revision', 'state_revision'] as $field) if (!is_string($_POST[$field] ?? null)) throw new InvalidArgumentException('Reload the protection setup form.');
    $service->activate($_POST['config_revision'], $_POST['state_revision'], $owner, $guard);
    flash('success', 'Protection is enabled. Managed uploads are served through current publication and release checks.');
    redirect(vvx_protection_url($protectionRequest['tab']));
}
if ($action === 'protection_media_release' && $protectionRequest['tab'] === 'media' && $protectionRequest['record'] === '') {
    foreach (['id', 'revision', 'state_revision'] as $field) if (!is_string($_POST[$field] ?? null)) throw new InvalidArgumentException('Reload the kept-offline media review.');
    if (($_POST['confirm_available'] ?? '') !== '1') throw new InvalidArgumentException('Confirm that the reviewed media may be made available.');
    $service->releaseMedia($_POST['id'], $_POST['state_revision'], $_POST['revision'], $owner, $guard);
    flash('success', 'The reviewed media is available through protected delivery. Linked publications keep their saved draft or disabled state.');
    redirect(vvx_protection_url('media'));
}
if (!is_string($_POST['revision'] ?? null) || !is_string($_POST['id'] ?? null)
    || $_POST['id'] !== ($protectionRequest['record'] === 'new' ? '' : $protectionRequest['record'])) throw new InvalidArgumentException('Open the matching saved record before submitting this form.');
$input = [];
foreach (['id', 'label', 'status', 'document_reference', 'notes', 'reviewed_on', 'expires_on', 'content_revision'] as $key) $input[$key] = $_POST[$key] ?? '';
if ($action === 'protection_performer_save' && $protectionRequest['tab'] === 'performers') {
    $id = $service->savePerformer($input, $_POST['revision'], $owner, $guard);
    $message = 'Collaborator saved. Private references remain in the owner workspace.';
} elseif ($action === 'protection_release_save' && $protectionRequest['tab'] === 'releases') {
    $input['targets'] = vvx_protection_targets($_POST['targets'] ?? []);
    $input['performer_ids'] = $_POST['performer_ids'] ?? [];
    $input['consent_reviewed'] = ($_POST['consent_reviewed'] ?? '') === '1';
    $id = $service->saveRelease($input, $_POST['revision'], $owner, $guard);
    $message = 'Release saved. Any previously held linked publications remain draft or disabled until you publish them deliberately.';
} elseif ($action === 'protection_case_action' && $protectionRequest['tab'] === 'cases' && $protectionRequest['record'] !== '') {
    $service->caseAction($_POST['id'], $_POST['revision'], ['action' => $_POST['decision'] ?? '',
        'message' => $_POST['message'] ?? '', 'public_message' => $_POST['public_message'] ?? '',
        'targets' => vvx_protection_targets($_POST['targets'] ?? []), 'content_revision' => $_POST['content_revision'] ?? ''], $owner, $guard);
    $id = $_POST['id']; $message = 'Case updated. The reporter can read your response with their case access key.';
} else throw new InvalidArgumentException('Choose an action for this protection record.');
flash('success', $message); redirect(vvx_protection_url($protectionRequest['tab'], $id));

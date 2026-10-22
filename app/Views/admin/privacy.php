<?php
declare(strict_types=1);
// Dedicated controller: ordinary reads never boot extensions or initialize journals.
require_once root_path('app/Support/member_privacy.php');
vvx_privacy_private();
try { if (!is_installed()) throw new RuntimeException(); $user = vvx_privacy_owner(); }
catch (Throwable) { http_response_code(403); exit('Only the current site owner can manage privacy requests.'); }
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit('Method not allowed.'); }
if (($_GET['page'] ?? null) !== 'privacy') { http_response_code(400); exit('Open Member privacy before changing a request.'); }
$id = $_GET['request'] ?? '';
if (!is_string($id) || ($id !== '' && preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1)) { http_response_code(400); exit('Choose a saved privacy request.'); }
$privacy = new App\Core\MemberPrivacyService(app_config(), app_store());
$url = base_url('admin/?page=privacy'); $error = ''; $success = flash('privacy_success');
$recent = static function (): void { vvx_privacy_owner(true); };
if (($_GET['guide'] ?? '') === '1' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    vvx_privacy_owner(); header('Content-Type: text/markdown; charset=utf-8'); header('Content-Disposition: attachment; filename="MEMBER_PRIVACY.md"');
    readfile(root_path('app/Docs/MEMBER_PRIVACY.md')); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $user = vvx_privacy_owner(true);
        if (!verify_csrf()) throw new RuntimeException('Your form session expired. Reload Member privacy and try again.', 419);
        $action = vvx_privacy_field('action');
        if ($action === 'privacy_approve') {
            $input = ['reason' => vvx_privacy_field('reason', 1500), 'review_on' => vvx_privacy_field('review_on')];
            foreach (App\Core\MemberPrivacyService::RETENTION_CHECKS as $key) $input[$key] = vvx_privacy_field($key) === '1';
            $privacy->approve(vvx_privacy_field('id'), vvx_privacy_field('revision'), vvx_privacy_field('inventory_revision'), $input, $user, $recent);
            flash('privacy_success', 'The reviewed request is approved. Run approved work or let the hosting worker continue it.');
        } elseif ($action === 'privacy_respond') {
            $decision = vvx_privacy_field('decision');
            if (!in_array($decision, ['respond', 'decline'], true)) throw new InvalidArgumentException('Choose a response or decline.');
            $privacy->respond(vvx_privacy_field('id'), vvx_privacy_field('revision'), vvx_privacy_field('message', 2000), $decision === 'decline', $user, $recent);
            flash('privacy_success', $decision === 'decline' ? 'The request was declined with a response visible to this member.' : 'Your response is saved in the member’s private request history.');
        } elseif ($action === 'privacy_run') {
            $result = $privacy->run(10);
            vvx_privacy_owner(true);
            flash('privacy_success', $result['busy'] ? 'Another privacy worker is already processing approved work.' : sprintf('%d processing stages finished; %d requests completed; %d need a new review; %d exports expired.', $result['processed'], $result['completed'], $result['held'], $result['expired']));
        } else throw new InvalidArgumentException('Choose an action from Member privacy.');
        redirect($url . ($id !== '' ? '&request=' . rawurlencode($id) : ''));
    } catch (Throwable $caught) { $error = vvx_privacy_error($caught); }
}
$requests = []; $review = null;
try { $requests = $privacy->ownerRequests($user); if ($id !== '') $review = $privacy->review($id, $user, $recent); vvx_privacy_owner(); }
catch (Throwable $caught) { $error = vvx_privacy_error($caught); }
$page = 'privacy';
$nav = ['dashboard' => ['⌂', 'Dashboard'], 'members' => ['♙', 'Members'], 'privacy' => ['◇', 'Member privacy'], 'messages' => ['✉', 'Messages'], 'security' => ['◇', 'Security']];
$labels = ['financial_reviewed' => 'I reviewed transaction facts that must remain, including amounts, currency, tax, status and dates.',
    'safety_reviewed' => 'I reviewed safety holds, retained decisions and evidence. Removal will preserve those controls.',
    'backups_reviewed' => 'I reviewed the independent backup policy and any separate retention obligations.',
    'billing_reviewed' => 'I reviewed external recurring billing and recorded any required provider action outside this workflow.',
    'open_orders_reviewed' => 'I reviewed open orders, refunds, fulfillment and any unresolved purchase obligations.',
    'guest_requests_reviewed' => 'I understand that unlinked guest inquiries and other members’ records remain outside this account removal.'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Member privacy · Velvet Vixxen Admin</title><link rel="stylesheet" href="<?= e(asset_url('css/admin.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('css/admin-ux.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('css/privacy-admin.css')) ?>"></head>
<body class="admin-body"><a class="admin-skip-link" href="#adminContent">Skip to content</a><?php require root_path('app/Views/admin/navigation.php'); ?><div class="admin-shell">
<header class="admin-topbar"><button class="sidebar-toggle" type="button" aria-label="Toggle menu" aria-controls="adminSidebar" aria-expanded="false">☰</button><div><div class="admin-kicker">Members &amp; Communications</div><h1>Member privacy</h1></div><a class="admin-button secondary" href="<?= e(base_url('admin/?page=security')) ?>">Security</a></header>
<main class="admin-content privacy-admin" id="adminContent" tabindex="-1" data-owner-privacy>
<?php if ($success): ?><div class="admin-alert success" role="status"><?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="admin-alert error" role="alert" data-privacy-error><?= e($error) ?></div><?php endif; ?>
<section class="admin-card"><span class="admin-kicker">Reviewed account requests</span><h2>Private exports &amp; account closure</h2><p>Members confirm their current password when requesting an export or closure. Review the complete current inventory before approval. Every approval belongs to one request and one saved inventory.</p><p>Protected changes require a recent owner sign-in. <a href="<?= e(base_url('admin/?page=security')) ?>">Confirm your password in Security</a> if this session is older than 15 minutes.</p><div class="privacy-admin-actions"><form method="post" action="<?= e($url) ?>" data-privacy-run><?= csrf_field() ?><input type="hidden" name="action" value="privacy_run"><button class="admin-button primary" type="submit">Run approved work</button></form><a class="admin-button secondary" href="<?= e($url . '&guide=1') ?>">Download operations guide</a></div><p class="privacy-admin-muted">A run processes up to ten durable stages. The hosting worker can resume an interrupted run. This workflow sends no email and performs no external billing or backup deletion.</p></section>
<?php if ($review !== null): $request = $review['request']; $retention = $review['retention'] ?? []; ?>
<section class="admin-card privacy-admin-review" aria-labelledby="privacy-review" data-privacy-review="<?= e($request['id']) ?>"><span class="admin-kicker">Current inventory</span><h2 id="privacy-review"><?= $request['kind'] === 'export' ? 'Review account export' : 'Review account closure' ?></h2><p><strong><?= e($review['member']['displayName']) ?></strong> · <?= e($review['member']['email']) ?></p><p><?= e(vvx_privacy_status($request['status'])) ?> · <?= (int) $request['stage'] ?> of 10 closure stages recorded</p>
<?php if ($request['last_error']): ?><p class="privacy-admin-note"><?= e($request['last_error']) ?></p><?php endif; ?>
<dl class="privacy-inventory"><?php foreach ($review['counts'] as $key => $count): ?><div><dt><?= e(ucfirst(preg_replace('/([a-z])([A-Z])/', '$1 $2', $key))) ?></dt><dd><?= number_format($count) ?></dd></div><?php endforeach; ?></dl>
<div class="privacy-review-flags"><p><strong>Open purchase obligations:</strong> <?= $review['has_open_orders'] ? 'Review required' : 'No pending or processing order recorded' ?></p><p><strong>External recurring billing:</strong> <?= $review['has_recurring_billing'] ? 'Active provider reference requires review' : 'No active provider subscription recorded' ?></p><p><strong>Safety holds:</strong> <?= $review['has_safety_holds'] ? 'Retained safety hold present' : 'No linked active hold recorded' ?></p></div>
<?php if ($retention !== []): ?><details><summary>Saved retention review</summary><p><?= nl2br(e($retention['reason'])) ?></p><p>Next review date: <strong><?= e($retention['review_on']) ?></strong>. This date is a review reminder, not an automatic purge.</p></details><?php endif; ?>
<?php if (in_array($request['status'], ['requested', 'held', 'approved'], true)): ?>
<form method="post" action="<?= e($url . '&request=' . $request['id']) ?>" class="privacy-admin-form" data-privacy-approve><?= csrf_field() ?><input type="hidden" name="action" value="privacy_approve"><input type="hidden" name="id" value="<?= e($request['id']) ?>"><input type="hidden" name="revision" value="<?= e($request['revision']) ?>"><input type="hidden" name="inventory_revision" value="<?= e($review['inventory_revision']) ?>">
<?php if ($request['kind'] === 'erase'): ?><fieldset><legend>Retention and billing review</legend><p>Closing the account removes personal fields and private communications. Exact financial facts, independent suppression markers, safety decisions and retained evidence remain. Shared attachment files remain while another record owns a reference.</p><label>Retention reason<textarea name="reason" rows="4" minlength="10" maxlength="1500" aria-describedby="privacy-retention-help" required><?= e($retention['reason'] ?? '') ?></textarea></label><small id="privacy-retention-help">Record the basis for retained facts and any separate billing action. Keep account secrets and unnecessary personal details out of this note.</small><label>Next retention review date<input type="date" name="review_on" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" max="<?= e(date('Y-m-d', strtotime('+10 years'))) ?>" value="<?= e($retention['review_on'] ?? '') ?>" required></label>
<?php foreach ($labels as $key => $label): ?><label class="privacy-admin-check"><input type="checkbox" name="<?= e($key) ?>" value="1" required><span><?= e($label) ?></span></label><?php endforeach; ?></fieldset><?php else: ?><p>Approval prepares a complete private ZIP for this member. It remains available for seven days and includes only member-visible attachments. Oversized or inconsistent archives pause for review.</p><?php endif; ?>
<button class="admin-button primary" type="submit"><?= $request['status'] === 'held' ? 'Approve reviewed resumption' : ($request['kind'] === 'export' ? 'Approve private export' : 'Approve account closure') ?></button></form><?php endif; ?>
<?php if ($request['stage'] === 0 && in_array($request['status'], ['requested', 'held', 'approved'], true)): ?><details class="privacy-admin-response"><summary>Respond or decline before processing</summary><form method="post" action="<?= e($url . '&request=' . $request['id']) ?>" class="privacy-admin-form" data-privacy-respond><?= csrf_field() ?><input type="hidden" name="action" value="privacy_respond"><input type="hidden" name="id" value="<?= e($request['id']) ?>"><input type="hidden" name="revision" value="<?= e($request['revision']) ?>"><label>Response visible to this member<textarea name="message" rows="4" maxlength="2000" required></textarea></label><label>Decision<select name="decision"><option value="respond">Save response</option><option value="decline">Decline request with this response</option></select></label><button class="admin-button secondary" type="submit">Save response</button></form></details><?php endif; ?></section><?php endif; ?>
<section class="admin-card" aria-labelledby="privacy-requests"><span class="admin-kicker">Private request journal</span><h2 id="privacy-requests">Requests</h2><?php if ($requests === []): ?><p>No member privacy requests yet.</p><?php endif; ?><div class="privacy-admin-requests">
<?php foreach ($requests as $row): ?><article data-request-id="<?= e($row['id']) ?>" data-request-status="<?= e($row['status']) ?>"><div><h3><?= e($row['member_name']) ?> · <?= $row['kind'] === 'export' ? 'Account export' : 'Account closure' ?></h3><p><?= e(vvx_privacy_status($row['status'])) ?> · <?= e(vvx_privacy_date($row['created_at'])) ?></p><?php if ($row['kind'] === 'erase' && $row['stage'] > 0): ?><p><?= (int) $row['stage'] ?> of 10 closure stages recorded</p><?php endif; ?><?php if ($row['retention']): ?><p>Retention review due <?= e($row['retention']['review_on']) ?></p><?php endif; ?></div><a class="admin-button secondary" href="<?= e($url . '&request=' . $row['id']) ?>">Review request</a></article><?php endforeach; ?></div></section>
<section class="admin-card"><h2>Connect the hosting worker</h2><p>Run this command regularly to process approved work and expire completed downloads. An interrupted stage is retried from its durable checkpoint.</p><code>php /path/to/website/app/Console/run_privacy.php --limit=10</code><p class="privacy-admin-muted">Review held work before approving resumption. A worker never approves a request, cancels external billing or deletes an independent backup.</p></section>
</main></div><script src="<?= e(asset_url('js/admin.js')) ?>" defer></script><script src="<?= e(asset_url('js/admin-ux.js')) ?>" defer></script></body></html>

<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 3));
require ROOT_PATH . '/includes/bootstrap.php';
require_once ROOT_PATH . '/app/Support/member_privacy.php';
vvx_privacy_private();
if (!is_installed()) { http_response_code(404); exit('Not found.'); }
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit('Method not allowed.'); }
$privacy = new App\Core\MemberPrivacyService(app_config(), app_store());
try { [$member, $token] = vvx_privacy_member($privacy); }
catch (Throwable $caught) { http_response_code(403); exit('Sign in to your verified account before opening Account & privacy.'); }
$memberId = (string) $member['id']; $error = ''; $success = flash('member_privacy_success');
$url = base_url('account/privacy.php');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!verify_csrf()) throw new RuntimeException('Your form session expired. Reload Account & privacy and try again.', 419);
        $action = vvx_privacy_field('action');
        if ($action === 'privacy_request') {
            $kind = vvx_privacy_field('kind');
            $privacy->request($kind, $memberId, $token, vvx_privacy_field('password', 255), vvx_privacy_field('confirmed') === '1');
            flash('member_privacy_success', $kind === 'export' ? 'Your account export was requested. The owner will review it before preparing your private download.' : 'Your account closure was requested. The owner must review billing, retained records and safety holds before processing it.');
        } elseif ($action === 'privacy_cancel') {
            $privacy->cancel(vvx_privacy_field('id'), $memberId, $token, vvx_privacy_field('password', 255), vvx_privacy_field('confirmed') === '1', vvx_privacy_field('revision'));
            flash('member_privacy_success', 'Your pending request was canceled.');
        } else throw new InvalidArgumentException('Choose an action from Account & privacy.');
        redirect($url);
    } catch (Throwable $caught) { $error = vvx_privacy_error($caught); }
}
try { $requests = $privacy->memberRequests($memberId, $token); [$member] = vvx_privacy_member($privacy); }
catch (Throwable $caught) { http_response_code(403); exit('Sign in again before reviewing account privacy.'); }
$open = []; foreach ($requests as $request) if (in_array($request['status'], ['requested', 'approved', 'processing', 'held'], true)) $open[$request['kind']] = true;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account &amp; privacy · Velvet Vixxen</title><link rel="stylesheet" href="<?= e(asset_url('css/member-privacy.css')) ?>"></head>
<body class="privacy-member"><a class="privacy-skip" href="#privacy-main">Skip to account privacy</a>
<header class="privacy-header"><a href="<?= e(base_url()) ?>" class="privacy-brand">The Velvet Vixxen</a><nav aria-label="Account"><a href="<?= e(base_url('account/security.php')) ?>">Account &amp; Security</a><a href="<?= e(base_url('account/')) ?>">Dashboard</a></nav></header>
<main class="privacy-main" id="privacy-main" tabindex="-1" data-member-privacy>
<section class="privacy-intro"><span class="privacy-kicker">Your private account</span><h1>Account &amp; privacy</h1><p>Request a copy of your account records or ask the owner to close this account and remove its personal data. Your signed-in identity is checked again before every protected action.</p><p class="privacy-muted">Signed in as <strong><?= e((string) $member['email']) ?></strong></p></section>
<?php if ($success): ?><div class="privacy-alert success" role="status"><?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="privacy-alert" role="alert" data-privacy-error><?= e($error) ?></div><?php endif; ?>
<div class="privacy-grid"><section class="privacy-card" aria-labelledby="privacy-export"><span class="privacy-kicker">A private copy</span><h2 id="privacy-export">Export account data</h2><p>Your copy includes account and purchase records, visible conversations, your linked requests, permitted attachments and your own saved privacy records.</p><p>When ready, the ZIP is available here for seven days. Only your current, verified account can download it. Changing your account identity or closing the account removes access to earlier exports.</p>
<?php if (isset($open['export'])): ?><p class="privacy-note">An export request is already open. Its status appears below.</p><?php else: ?>
<form method="post" action="<?= e($url) ?>" class="privacy-form" data-privacy-request="export"><?= csrf_field() ?><input type="hidden" name="action" value="privacy_request"><input type="hidden" name="kind" value="export"><label>Current password<input type="password" name="password" maxlength="255" autocomplete="current-password" required></label><label class="privacy-check"><input type="checkbox" name="confirmed" value="1" required><span>I want a private copy of this account’s records.</span></label><button class="privacy-button" type="submit">Request account export</button></form><?php endif; ?></section>
<section class="privacy-card" aria-labelledby="privacy-close"><span class="privacy-kicker">Owner-reviewed closure</span><h2 id="privacy-close">Close account &amp; remove data</h2><p>After owner approval, this account closes and all devices are signed out. Personal account fields, linked communications, optional delivery records and owned private attachments are removed or anonymized.</p><p>Transaction facts and safety evidence may remain under a documented retention review. External recurring billing must be reviewed by the owner. This request does not cancel a provider subscription or remove independent backups.</p>
<?php if (isset($open['erase'])): ?><p class="privacy-note">An account closure request is already open. Its status appears below.</p><?php else: ?>
<form method="post" action="<?= e($url) ?>" class="privacy-form" data-privacy-request="erase"><?= csrf_field() ?><input type="hidden" name="action" value="privacy_request"><input type="hidden" name="kind" value="erase"><label>Current password<input type="password" name="password" maxlength="255" autocomplete="current-password" required></label><label class="privacy-check"><input type="checkbox" name="confirmed" value="1" required><span>I understand that approved processing closes this account and removes personal data, with reviewed financial and safety retention.</span></label><button class="privacy-button secondary" type="submit">Request account closure</button></form><?php endif; ?></section></div>
<section class="privacy-card privacy-history" aria-labelledby="privacy-history"><span class="privacy-kicker">Saved requests</span><h2 id="privacy-history">Your request history</h2><p>You can cancel a request while it is waiting for owner review. Once approved, the owner must resolve any changes before processing continues.</p>
<?php if ($requests === []): ?><p class="privacy-note">No account privacy requests yet.</p><?php endif; ?>
<?php foreach ($requests as $request): ?><article class="privacy-request" data-request-id="<?= e($request['id']) ?>" data-request-status="<?= e($request['status']) ?>"><div class="privacy-request-heading"><h3><?= $request['kind'] === 'export' ? 'Account export' : 'Account closure' ?></h3><span class="privacy-badge"><?= e(vvx_privacy_status($request['status'])) ?></span></div><p class="privacy-muted">Requested <?= e(vvx_privacy_date($request['created_at'])) ?></p>
<?php if ($request['last_error']): ?><p class="privacy-note"><?= e($request['last_error']) ?></p><?php endif; ?>
<?php foreach ($request['responses'] as $response): ?><blockquote><p><?= nl2br(e($response['text'])) ?></p><footer>Owner response · <?= e(vvx_privacy_date($response['at'])) ?></footer></blockquote><?php endforeach; ?>
<?php if (!empty($request['downloadable'])): ?><p><a class="privacy-button" data-privacy-download href="<?= e(base_url('communication/privacy-download.php?id=' . rawurlencode($request['id']))) ?>">Download private ZIP</a></p><p class="privacy-muted">Available until <?= e(vvx_privacy_date($request['expires_at'])) ?>.</p><?php endif; ?>
<?php if ($request['status'] === 'requested'): ?><details><summary>Cancel this pending request</summary><form method="post" action="<?= e($url) ?>" class="privacy-form" data-privacy-cancel><?= csrf_field() ?><input type="hidden" name="action" value="privacy_cancel"><input type="hidden" name="id" value="<?= e($request['id']) ?>"><input type="hidden" name="revision" value="<?= e($request['revision']) ?>"><label>Current password<input type="password" name="password" autocomplete="current-password" maxlength="255" required></label><label class="privacy-check"><input type="checkbox" name="confirmed" value="1" required><span>Cancel this pending <?= $request['kind'] === 'export' ? 'export' : 'closure' ?> request.</span></label><button class="privacy-button secondary" type="submit">Cancel pending request</button></form></details><?php endif; ?></article><?php endforeach; ?></section>
<footer class="privacy-footer"><a href="<?= e(base_url('privacy/choices.php')) ?>">Browser privacy choices</a><a href="<?= e(base_url('reports/')) ?>">Report a concern</a></footer>
</main></body></html>

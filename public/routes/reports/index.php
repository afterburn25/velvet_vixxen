<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 3));
require ROOT_PATH . '/includes/bootstrap.php';
require ROOT_PATH . '/app/Support/protection_admin.php';
vvx_protection_private();
if (!is_installed()) { http_response_code(404); exit; }
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit('Method not allowed.'); }
$service = vvx_protection_service(); $error = ''; $case = null; $receipt = null; $attempt = [];
$caseId = $_GET['case'] ?? '';
if (!is_string($caseId)) { http_response_code(400); exit('Choose a valid case number.'); }
try { if ($caseId !== '') App\Core\ProtectionRecords::id($caseId); }
catch (Throwable) { http_response_code(400); exit('Choose a valid case number.'); }
$member = current_member();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach (['category', 'label', 'name', 'email', 'description', 'url', 'message'] as $key) {
        $value = $_POST[$key] ?? ''; $attempt[$key] = is_string($value) && strlen($value) <= 6000 ? $value : '';
    }
    try {
        if (!verify_csrf()) throw new RuntimeException('Your form session expired. Reload and try again.', 419);
        $action = $_POST['action'] ?? null;
        if ($action === 'report_create' && $caseId === '') {
            $receipt = $service->report($attempt + ['accurate' => ($_POST['accurate'] ?? '') === '1'], (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $member);
            $_SESSION['protection_case_keys'][$receipt['id']] = $receipt['access_key'];
            $_SESSION['protection_case_keys'] = array_slice($_SESSION['protection_case_keys'], -20, null, true);
            $_SESSION['protection_case_created'] = $receipt;
            redirect(base_url('reports/?case=' . $receipt['id']));
        } elseif ($action === 'report_lookup' && $caseId === '') {
            if (!is_string($_POST['case_number'] ?? null) || !is_string($_POST['access_key'] ?? null)) throw new InvalidArgumentException('Enter a case number and access key.');
            $id = App\Core\ProtectionRecords::id(trim($_POST['case_number'])); $key = trim($_POST['access_key']);
            $service->publicReview($id, $key, $member);
            $_SESSION['protection_case_keys'][$id] = $key;
            $_SESSION['protection_case_keys'] = array_slice($_SESSION['protection_case_keys'], -20, null, true);
            redirect(base_url('reports/?case=' . $id));
        } elseif (in_array($action, ['report_reply', 'report_appeal'], true) && $caseId !== '') {
            if (!is_string($_POST['revision'] ?? null) || ($_POST['case_number'] ?? '') !== $caseId) throw new InvalidArgumentException('Reload the current case before replying.');
            $service->reporterReply($caseId, $_POST['revision'], (string) ($_SESSION['protection_case_keys'][$caseId] ?? ''), $member, $attempt['message'], $action === 'report_appeal');
            redirect(base_url('reports/?case=' . $caseId));
        } else throw new InvalidArgumentException('Choose a report or case action.');
    } catch (Throwable $caught) {
        vvx_protection_response_code(in_array((int) $caught->getCode(), [403, 409, 419, 429, 503], true) ? (int) $caught->getCode() : 422);
        $error = ($caught instanceof RuntimeException && !$caught instanceof PDOException) || $caught instanceof InvalidArgumentException
            ? $caught->getMessage() : 'The report workspace is temporarily unavailable. Please try again later.';
    }
}
if ($caseId !== '') {
    try {
        $case = $service->publicReview($caseId, (string) ($_SESSION['protection_case_keys'][$caseId] ?? ''), $member);
        if (($_SESSION['protection_case_created']['id'] ?? '') === $caseId) { $receipt = $_SESSION['protection_case_created']; unset($_SESSION['protection_case_created']); }
    } catch (Throwable $caught) {
        http_response_code($caught->getCode() === 403 ? 403 : 503);
        $error = $caught->getCode() === 403 ? 'Enter the case number and access key from your receipt to open this case.' : 'This case is temporarily unavailable. Your saved report has been preserved.';
    }
}
$value = static fn(string $key, string $default = ''): string => $attempt[$key] ?? $default;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reports & appeals · Velvet Vixxen</title><link rel="stylesheet" href="<?= e(asset_url('css/protection-public.css')) ?>"><link rel="icon" href="<?= e(base_url('images/defaults/brand-mark.png')) ?>"></head><body class="protection-public"><a class="protection-skip" href="#reportMain">Skip to report workspace</a><header><a class="protection-brand" href="<?= e(base_url()) ?>">Nikki Wilder<span>The Velvet Vixxen</span></a><a href="<?= e(base_url('reports/')) ?>">Reports & appeals</a></header><main id="reportMain" tabindex="-1"><div class="report-heading"><span class="report-kicker">A private way to be heard</span><h1><?= $case === null ? 'Report content or a concern' : 'Your report' ?></h1><p>Share a copyright, consent, privacy or content concern with the site owner. You can return here for a response and, when available, submit an appeal.</p></div>
<?php if ($error !== ''): ?><div class="report-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($receipt !== null): ?><section class="report-card receipt" data-report-receipt><h2>Your report was received</h2><p>Save this case number and access key somewhere private. The access key is shown once. It is not sent by email.</p><label>Case number<input readonly value="<?= e($receipt['id']) ?>" aria-label="Receipt case number"></label><label>Access key<input readonly value="<?= e($receipt['access_key']) ?>" aria-label="Receipt access key"></label><p>This browser can reopen the case while its session remains active. Anyone with both values can read the case responses.</p></section><?php endif; ?>
<?php if ($case !== null): ?><section class="report-card" data-report-case><div class="report-status"><?= e(vvx_protection_status($case['status'])) ?></div><h2><?= e($case['label']) ?></h2><p class="report-number">Case <?= e($case['id']) ?></p><ol class="report-timeline"><?php foreach ($case['events'] as $event): ?><li><time><?= e($event['at']) ?></time><p><?= e($event['message']) ?></p></li><?php endforeach; ?></ol>
<?php if ($case['can_reply'] || $case['can_appeal']): ?><form method="post" action="<?= e(base_url('reports/?case=' . $case['id'])) ?>" data-report-reply><?= csrf_field() ?><input type="hidden" name="action" value="<?= $case['can_reply'] ? 'report_reply' : 'report_appeal' ?>"><input type="hidden" name="case_number" value="<?= e($case['id']) ?>"><input type="hidden" name="revision" value="<?= e(is_string($_POST['revision'] ?? null) && $error !== '' ? $_POST['revision'] : $case['revision']) ?>"><label><?= $case['can_reply'] ? 'Your reply' : 'Appeal details' ?><textarea name="message" rows="6" maxlength="6000" required><?= e($value('message')) ?></textarea></label><p><?= $case['can_reply'] ? 'Include the information requested in the owner response.' : 'Explain what you believe should be reconsidered and include supporting references. A case accepts up to three appeals; the owner reviews each one.' ?></p><button><?= $case['can_reply'] ? 'Send reply to this case' : 'Submit appeal' ?></button></form><?php endif; ?><a class="report-link" href="<?= e(base_url('reports/')) ?>">Report another concern or open a different case</a></section>
<?php else: ?><div class="report-columns"><section class="report-card"><h2>New report</h2><p>Include the affected address and enough detail to identify the concern. Avoid sending identity documents or financial information in this form.</p><form method="post" action="<?= e(base_url('reports/')) ?>" data-report-form><?= csrf_field() ?><input type="hidden" name="action" value="report_create"><label for="reportCategory">What is your concern?</label><select id="reportCategory" name="category" required><?php foreach (['content' => 'Content concern', 'copyright' => 'Copyright or rights', 'consent' => 'Consent', 'privacy' => 'Privacy', 'other' => 'Something else'] as $key => $label): ?><option value="<?= e($key) ?>" <?= $value('category', 'content') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><label>Report subject<input name="label" maxlength="180" required value="<?= e($value('label')) ?>"></label><label>Your name<input name="name" maxlength="160" autocomplete="name" required value="<?= e($value('name', (string) ($member['displayName'] ?? ''))) ?>"></label><label>Email address<input type="email" name="email" maxlength="190" autocomplete="email" required value="<?= e($value('email', (string) ($member['email'] ?? ''))) ?>"></label><label>Content address on this website<input type="url" name="url" maxlength="1500" value="<?= e($value('url')) ?>" placeholder="<?= e(base_url()) ?>"></label><label>Report details<textarea name="description" rows="7" maxlength="6000" required><?= e($value('description')) ?></textarea></label><label class="report-check"><input type="checkbox" name="accurate" value="1" required <?= ($_POST['accurate'] ?? '') === '1' ? 'checked' : '' ?>><span>This report is accurate to the best of my knowledge.</span></label><button>Submit report</button><p class="report-small">Submitting a report starts an owner review. It does not automatically remove content. This form receives up to three reports per connection per hour.</p></form></section>
<aside class="report-card report-lookup"><span class="report-kicker">Already have a receipt?</span><h2>Open your case</h2><form method="post" action="<?= e(base_url('reports/')) ?>" data-report-lookup><?= csrf_field() ?><input type="hidden" name="action" value="report_lookup"><label>Case number<input name="case_number" maxlength="32" required autocomplete="off" value="<?= e($caseId) ?>"></label><label>Access key<input name="access_key" type="password" maxlength="48" required autocomplete="off"></label><button class="secondary">Open case</button></form><p>Return here to read the owner response. Keep your access key private.</p></aside></div><?php endif; ?>
</main><footer><span>© <?= date('Y') ?> Nikki Wilder · The Velvet Vixxen</span><a href="<?= e(base_url()) ?>">Return to the website</a></footer></body></html>

<?php
declare(strict_types=1);

require_once root_path('includes/marketing.php');
velvet_marketing_private_response(true);
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; frame-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$deny = static function (int $status): never { http_response_code($status); header('Content-Type: text/plain; charset=utf-8'); exit('Saved campaign preview unavailable. Reload the campaign list and open its current preview.'); };
if (!is_installed() || !velvet_marketing_is_owner()) $deny(403);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); $deny(405); }
try {
    $id = \Velvet\Core\MarketingAudience::id($_GET['id'] ?? null);
    $preview = \Velvet\Core\MarketingStore::previewCampaignMessage($id, ROOT_PATH);
    if ($preview === null) $deny(404);
    if (!is_string($_GET['revision'] ?? null) || !is_string($_GET['template_revision'] ?? null)
        || !hash_equals($preview['revision'], $_GET['revision']) || !hash_equals($preview['template_revision'], $_GET['template_revision'])) $deny(409);
    $base = \Velvet\Core\CampaignMessage::baseUrl((string) app_config()->get('base_url', ''));
    $rendered = \Velvet\Core\CampaignMessage::render($preview['snapshot'], \App\Core\CampaignTestService::exampleUnsubscribe($base), $base);
    $inertHtml = $rendered['html'] === null ? null : str_replace('</a>', '</span>',
        preg_replace('/<a href="[^"]*"/', '<span role="link" aria-disabled="true"', $rendered['html']));
    if (!velvet_marketing_is_owner()) $deny(403);
    $owner = (array) app_auth()->user();
    $request = bin2hex(random_bytes(32));
    $_SESSION['_campaign_test_requests'] = array_filter((array) ($_SESSION['_campaign_test_requests'] ?? []), static fn($row): bool => is_array($row) && (int) ($row['issued'] ?? 0) >= time() - 1800);
    if (count($_SESSION['_campaign_test_requests']) >= 20) array_shift($_SESSION['_campaign_test_requests']);
    $_SESSION['_campaign_test_requests'][$request] = ['owner' => (string) $owner['id'], 'campaign' => $id,
        'revision' => $preview['revision'], 'template_revision' => $preview['template_revision'], 'issued' => time()];
    $mailProblem = (new \App\Core\MailService(app_config(), app_store()))->configurationProblem();
} catch (Throwable) { $deny(400); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>Saved campaign message · Velvet Vixxen</title>
<link rel="stylesheet" href="<?= e(asset_url('css/marketing.css')) ?>"><style>.campaign-message-frame{width:100%;height:760px;border:0;background:#0d0810}.campaign-message-plain{white-space:pre-wrap;overflow-wrap:anywhere}.campaign-preview-actions{display:flex;gap:14px;flex-wrap:wrap}.campaign-preview-actions button,.campaign-preview-actions a{min-height:44px;display:inline-flex;align-items:center}.campaign-preview-form{margin:20px 0}@media(max-width:600px){.campaign-message-frame{height:860px}.campaign-preview-actions>*{width:100%}}</style></head>
<body class="marketing-page"><main class="marketing-shell" data-campaign-message-preview>
<section class="marketing-card"><span class="marketing-eyebrow">Private owner preview</span><h1>Saved campaign message</h1>
<p><?= $preview['queued'] ? 'This campaign uses its frozen message and email brand.' : 'This is the saved draft and current email brand. Unsaved edits are not included.' ?> The preview does not queue subscribers or record campaign clicks.</p>
<p><strong>Subject:</strong> <span data-preview-subject><?= e($rendered['subject']) ?></span></p>
<p>Test messages go only to your current owner email address, <strong><?= e((string) $owner['email']) ?></strong>, with a <strong>[Test]</strong> subject prefix. The example unsubscribe link does not unsubscribe a real subscriber.</p>
<?php if ($mailProblem !== null): ?><p class="marketing-notice" role="status"><?= e($mailProblem) ?></p><?php endif; ?>
<form method="post" action="<?= e(base_url('admin/?page=marketing&tab=campaigns')) ?>" class="campaign-preview-form" data-campaign-test-form><?= csrf_field() ?>
<input type="hidden" name="action" value="send_campaign_test"><input type="hidden" name="campaign_id" value="<?= e($id) ?>"><input type="hidden" name="campaign_revision" value="<?= e($preview['revision']) ?>"><input type="hidden" name="template_revision" value="<?= e($preview['template_revision']) ?>"><input type="hidden" name="test_request" value="<?= e($request) ?>">
<div class="campaign-preview-actions"><button class="marketing-button" <?= $mailProblem !== null ? 'disabled' : '' ?>>Send test to owner</button><a class="marketing-button" href="<?= e(base_url('admin/?page=marketing&tab=campaigns')) ?>">Back to campaigns</a></div></form>
<p>One test per minute. Accepted means the transport accepted the message; it does not confirm inbox arrival. Tests never retry automatically.</p></section>
<?php if ($inertHtml !== null): ?><section class="marketing-card"><h2>Branded message</h2><p>Links in this isolated preview are inactive.</p><iframe class="campaign-message-frame" title="Rendered saved campaign" sandbox srcdoc="<?= e($inertHtml) ?>"></iframe></section><?php endif; ?>
<section class="marketing-card"><h2>Plain-text message</h2><pre class="campaign-message-plain" data-campaign-plain><?= e($rendered['text']) ?></pre></section>
</main></body></html>

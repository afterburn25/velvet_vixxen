<?php
declare(strict_types=1);
require_once ROOT_PATH . '/includes/bootstrap.php';
vvx_access_private();
if (!is_installed()) { http_response_code(503); exit('This website is not available yet.'); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); http_response_code(405); exit('Method not allowed.'); }
$name = $_GET['document'] ?? ''; $policy = null; $policyError = '';
if (!is_string($name) || ($name !== '' && !isset(App\Core\PublicPolicies::DOCUMENTS[$name]))) { http_response_code(404); exit('Policy not found.'); }
$service = new App\Core\PublicPolicies(app_config()); $published = [];
try {
    foreach (App\Core\PublicPolicies::DOCUMENTS as $key => $label) if (($entry = $service->published($key)) !== null) $published[$key] = $entry;
    if ($name !== '') { $policy = $published[$name] ?? null; if ($policy === null) { http_response_code(404); $policyError = 'This policy has not been published yet. You can still use the account privacy and report options below.'; } }
} catch (Throwable) { http_response_code(503); $policyError = 'Published policies are temporarily unavailable. Please try again later.'; }
$title = $policy['title'] ?? ($name === '' ? 'Policies & help' : App\Core\PublicPolicies::DOCUMENTS[$name]);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($title) ?> · Velvet Vixxen</title><link rel="stylesheet" href="<?= e(asset_url('css/protection-public.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('css/policies.css')) ?>"><link rel="icon" href="<?= e(base_url('images/defaults/brand-mark.png')) ?>"></head><body class="protection-public"><a class="protection-skip" href="#policyMain">Skip to policy</a><header><a class="protection-brand" href="<?= e(base_url()) ?>">Nikki Wilder<span>The Velvet Vixxen</span></a><a href="<?= e(base_url('policies/')) ?>">Policies & help</a></header>
<main id="policyMain" tabindex="-1" class="access-main"><article class="report-card"><span class="report-kicker">Public information</span><h1><?= e($title) ?></h1>
<?php if ($policyError !== ''): ?><p role="status"><?= e($policyError) ?></p><?php elseif ($policy !== null): ?><p class="report-small">Published <?= e($policy['published_at']) ?></p><div class="policy-text" data-public-policy><?= e($policy['body']) ?></div><?php else: ?><p>Read the policies published by the site owner. Account privacy and report forms remain available even when content access is restricted.</p><nav class="policy-directory" aria-label="Published policies"><?php foreach ($published as $key => $entry): ?><a href="<?= e(base_url('policies/?document=' . $key)) ?>"><?= e($entry['title']) ?></a><?php endforeach; ?></nav><?php if ($published === []): ?><p>No public policies have been published yet.</p><?php endif; ?><?php endif; ?></article>
<section class="report-card access-help"><h2>Account & help</h2><nav aria-label="Account and policy help"><a href="<?= e(base_url('account/privacy.php')) ?>">Account & privacy</a><a href="<?= e(base_url('privacy/choices.php')) ?>">Privacy choices</a><a href="<?= e(base_url('reports/')) ?>">Report content or a concern</a><a href="<?= e(base_url('age/')) ?>">Age verification</a></nav></section></main><footer><span>© <?= date('Y') ?> Nikki Wilder · The Velvet Vixxen</span><a href="<?= e(base_url()) ?>">Return to the website</a></footer></body></html>

<?php
declare(strict_types=1);
require_once root_path('includes/marketing.php');
require_once root_path('includes/marketing_admin.php');
velvet_marketing_require_owner();
$marketingTabs = ['overview', 'calendar', 'posts', 'banners', 'campaigns', 'notifications', 'provider', 'deleted', 'audiences', 'links', 'newsletter', 'referrals', 'analytics'];
// Match the controller's exact calendar route; never turn an unguarded alias
// such as calendar1 into a request for the private preloaded calendar view.
$tab = is_string($_GET['tab'] ?? null) && in_array($_GET['tab'], $marketingTabs, true) ? $_GET['tab'] : 'overview';
// Calendar data was read and authorized before output. Viewing it must never
// initialize marketing storage, create defaults, publish content or send mail.
if (!in_array($tab, ['calendar', 'deleted', 'analytics', 'notifications', 'provider'], true)) {
    $marketingStore = velvet_marketing_store();
    $segments = $marketingStore->segments();
    $data = $marketingStore->all();
    $summary = null; $overviewMeasurementError = '';
    if ($tab === 'overview') {
        try { $summary = $marketingStore->measurementReport(); }
        catch (Throwable) { $overviewMeasurementError = 'Measurement reporting needs review. Open Analytics for details.'; }
    }
}
?>
<nav class="marketing-tabs" aria-label="Marketing pages"><?php foreach($marketingTabs as $t):?><a href="?page=marketing&amp;tab=<?= $t ?>" <?= $tab === $t ? 'aria-current="page"' : '' ?>><?= $t === 'deleted' ? 'Deleted content' : ($t === 'provider' ? 'Provider events' : ucfirst($t)) ?></a><?php endforeach;?></nav>
<?php if($tab==='calendar'): require root_path('app/Views/admin/marketing-calendar.php'); ?>
<?php elseif($tab==='notifications'): require root_path('app/Views/admin/marketing-notifications.php'); ?>
<?php elseif($tab==='provider'): require root_path('app/Views/admin/marketing-provider.php'); ?>
<?php elseif($tab==='deleted'): require root_path('app/Views/admin/marketing-deleted.php'); ?>
<?php elseif($tab==='analytics'): require root_path('app/Views/admin/marketing-analytics.php'); ?>
<?php elseif($tab==='overview'):?><?php if ($overviewMeasurementError !== ''): ?><p class="marketing-notice" role="alert"><?= velvet_marketing_escape($overviewMeasurementError) ?></p><?php endif; ?><p>Consented measurement for the last 30 UTC calendar days. Legacy events are excluded. <a href="?page=marketing&amp;tab=analytics">Open reports, privacy controls and retention</a>.</p><section class="marketing-stat-grid"><div class="marketing-stat"><strong><?= (int)($summary['retained_unique_visitors'] ?? 0) ?></strong>Retained measured visitors</div><div class="marketing-stat"><strong><?= (int)($summary['counts']['page_view']??0) ?></strong>Page views</div><div class="marketing-stat"><strong><?= count($data['contacts']) ?></strong>Contacts</div><div class="marketing-stat"><strong><?= count($data['posts']) ?></strong>Posts</div></section><section class="marketing-card"><h2>Top pages — last 30 days</h2><table class="marketing-table"><tbody><?php foreach(($summary['top_paths'] ?? []) as $path=>$count):?><tr><td><?=velvet_marketing_escape((string)$path)?></td><td><?= (int)$count ?></td></tr><?php endforeach;?></tbody></table></section>
<p><a class="marketing-button" href="<?= e(base_url('admin/?page=marketing-storage')) ?>">Manage Marketing Storage</a></p>
<?php elseif($tab==='posts' || $tab==='banners'):
$publicationCollection = $tab;
?>
<div class="marketing-admin-grid">
  <?php require root_path('app/Views/admin/marketing-publication-editor.php'); ?>
  <?php require root_path('app/Views/admin/marketing-publication-list.php'); ?>
</div>
<?php elseif($tab==='campaigns'):
$deliveryService = new \App\Core\CampaignDeliveryService(app_config(), $marketingStore, new \App\Core\MailService(app_config(), app_store()));
$deliveryHold = $deliveryService->holdReason();
$marketingSettings = $marketingStore->settings();
?>
<section class="marketing-card" data-campaign-delivery>
  <h2>Campaign delivery</h2>
  <p role="status" data-campaign-transport-status><?= velvet_marketing_escape($deliveryHold ?? 'Configured mail transport is available. Only explicitly queued, due campaigns will run.') ?></p>
  <p>Campaigns go to confirmed subscribers with current consent. Every message includes an unsubscribe link. Accepted means the mail transport accepted the message; it does not confirm arrival in an inbox.</p>
  <div class="marketing-admin-grid">
    <form class="marketing-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_marketing_settings">
      <label><input type="checkbox" name="newsletter_signup_enabled" value="1" style="width:auto" <?= !empty($marketingSettings['newsletter_signup_enabled']) ? 'checked' : '' ?>> Public newsletter signup and confirmation</label>
      <label><input type="checkbox" name="campaign_delivery_enabled" value="1" style="width:auto" <?= !empty($marketingSettings['campaign_delivery_enabled']) ? 'checked' : '' ?>> Campaign email delivery</label>
      <p>These switches are independent. Unsubscribe is always available. Manage the shared connection in <a href="<?= e(base_url('admin/?page=mail-settings')) ?>">Email &amp; SMTP</a>. Email may remain disabled while you prepare campaigns.</p>
      <button class="marketing-button">Save Marketing Settings</button>
    </form>
    <form class="marketing-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="run_campaign_batch">
      <label>Messages per batch<input type="number" name="batch_limit" value="3" min="1" max="10" required></label>
      <p>Run due work now, or schedule <code>php app/Console/run_marketing.php --limit=10</code> through your hosting control panel. The command accepts up to 25 messages per run.</p>
      <button class="marketing-button" <?= $deliveryHold !== null ? 'disabled' : '' ?>>Run Due Campaign Batch</button>
    </form>
  </div>
</section>
<?php require root_path('app/Views/admin/marketing-email-brand.php'); ?>
<div class="marketing-admin-grid" style="margin-top:22px">
  <?php $publicationCollection = 'landing_pages'; require root_path('app/Views/admin/marketing-publication-editor.php'); ?>
  <?php require root_path('app/Views/admin/marketing-campaign-form.php'); ?>
</div>
<div style="margin-top:22px"><?php $publicationCollection = 'landing_pages'; require root_path('app/Views/admin/marketing-publication-list.php'); ?></div>
<section class="marketing-card" style="margin-top:22px"><h2>Saved email campaigns</h2>
<p>Retry only after correcting the reported problem. Failed deliveries wait at least one minute between attempts and stop after three attempts. An unknown outcome is held permanently because the server may already have accepted it. Inspect your mail provider before deciding on a separate followup; automatic retries never resend accepted or unknown deliveries.</p>
<?php if ($data['campaigns'] === []): ?><p>No email campaigns saved yet.</p><?php endif; ?>
<?php foreach (array_reverse($data['campaigns']) as $campaign):
  $campaignId = (string) $campaign['id'];
  $messagePreview = $marketingStore->campaignMessage($campaignId);
  $campaignSummary = $marketingStore->campaignSummary($campaignId);
  $counts = $campaignSummary['counts'];
  $campaignAudienceError = '';
  try {
    if (array_key_exists('audience_snapshot', $campaign)) {
      $campaignAudience = \Velvet\Core\MarketingAudience::snapshot($campaign['audience_snapshot']);
    } elseif (isset($campaign['delivery_snapshot']) || !array_key_exists('audience', $campaign)) {
      $campaignAudience = ['label' => 'All eligible newsletter contacts', 'rules' => \Velvet\Core\MarketingAudience::rules([])];
    } else {
      $selection = \Velvet\Core\MarketingAudience::selection($campaign['audience']);
      $campaignAudience = ['label' => 'All eligible newsletter contacts', 'rules' => \Velvet\Core\MarketingAudience::rules([])];
      if ($selection['mode'] === 'segment') {
        $candidate = null;
        foreach ($segments as $segment) if ($segment['id'] === $selection['segment_id']) $candidate = $segment;
        if ($candidate === null || !$candidate['valid'] || !$candidate['enabled'] || !hash_equals($candidate['revision'], $selection['segment_revision'])) throw new RuntimeException('Audience changed or is unavailable. Edit this draft before queueing.');
        $campaignAudience = ['label' => $candidate['name'], 'rules' => $candidate['rules']];
      }
    }
  } catch (Throwable $caught) { $campaignAudienceError = $caught->getMessage(); }
?>
<article class="marketing-card" style="margin-top:16px" data-campaign-id="<?= velvet_marketing_escape($campaignId) ?>">
  <h3><?= velvet_marketing_escape((string) ($campaign['subject'] ?? $campaign['name'] ?? 'Email campaign')) ?></h3>
  <?php if ($messagePreview !== null): ?><p><a data-campaign-message-preview class="marketing-button" href="<?= e(base_url('admin/?page=campaign-message-preview&id=' . rawurlencode($campaignId) . '&revision=' . $messagePreview['revision'] . '&template_revision=' . $messagePreview['template_revision'])) ?>">Preview saved message</a></p><?php endif; ?>
  <p><?= !empty($campaign['delivery_paused']) ? 'Paused' : velvet_marketing_escape(ucfirst((string) ($campaign['status'] ?? 'draft'))) ?> · <?= empty($campaign['scheduled_at']) ? 'No future schedule' : 'Scheduled: ' . velvet_marketing_escape((string) $campaign['scheduled_at']) ?></p>
  <?php if ($campaignAudienceError !== ''): ?><p class="marketing-notice" role="alert"><?= velvet_marketing_escape($campaignAudienceError) ?></p><?php else: ?><p data-campaign-audience><strong>Audience: <?= velvet_marketing_escape($campaignAudience['label']) ?></strong><br><?= velvet_marketing_escape(mm_audience_summary($campaignAudience['rules'])) ?> (<?= velvet_marketing_escape(date_default_timezone_get()) ?>)</p><?php endif; ?>
  <?php if (!isset($campaign['delivery_snapshot']) && !isset($campaign['audience_snapshot'])): ?><p><a href="?page=marketing&amp;tab=campaigns&amp;edit_campaign=<?= rawurlencode($campaignId) ?>">Edit campaign draft</a></p><?php else: ?><p>Queued addresses are saved. Queue Recipients can add new eligible matches using this campaign's saved rules. Consent and suppression are checked again before sending.</p><?php endif; ?>
  <p data-campaign-counts><?= $counts['queued'] ?> queued · <?= $counts['claimed'] + $counts['sending'] ?> processing · <?= $counts['sent'] ?> accepted · <?= $counts['failed'] ?> failed · <?= $counts['unknown'] ?> unknown · <?= $counts['suppressed'] ?> suppressed<?= $counts['prepared'] ? ' · ' . $counts['prepared'] . ' legacy prepared (use Queue Recipients to release)' : '' ?></p>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= velvet_marketing_escape($campaignId) ?>">
    <?php if (isset($queueAudiencePreview) && ($queuePreviewId ?? '') === $campaignId): ?><input type="hidden" name="audience_preview_revision" value="<?= velvet_marketing_escape($queueAudiencePreview['revision']) ?>"><?php endif; ?>
    <button class="marketing-button" name="action" value="preview_campaign_queue" <?= $campaignAudienceError !== '' ? 'disabled' : '' ?>>Preview Recipients</button>
    <button class="marketing-button" name="action" value="queue_campaign" <?= $campaignAudienceError !== '' ? 'disabled' : '' ?>>Queue Recipients</button>
    <button class="marketing-button" name="action" value="<?= !empty($campaign['delivery_paused']) ? 'resume_campaign' : 'pause_campaign' ?>"><?= !empty($campaign['delivery_paused']) ? 'Resume Campaign' : 'Pause Campaign' ?></button>
    <?php if ($counts['failed']): ?><button class="marketing-button" name="action" value="retry_campaign">Retry Failed Deliveries</button><?php endif; ?>
  </form>
  <?php if (isset($queueAudiencePreview) && ($queuePreviewId ?? '') === $campaignId): ?><p class="marketing-notice" role="status" data-queue-audience-preview><?= (int) $queueAudiencePreview['new_count'] ?> new eligible recipients can be added; <?= (int) $queueAudiencePreview['existing_count'] ?> addresses already have delivery records. This preview is checked again when you queue.</p><?php endif; ?>
  <?php $problems = array_filter($campaignSummary['deliveries'], static fn(array $delivery): bool => in_array($delivery['status'] ?? '', ['failed', 'unknown'], true)); ?>
  <?php if ($problems !== []): ?><details style="margin-top:16px"><summary>Delivery problems (<?= count($problems) ?>)</summary><ul><?php foreach (array_slice($problems, 0, 50) as $delivery): ?><li><?= velvet_marketing_escape((string) $delivery['email']) ?> — <?= velvet_marketing_escape((string) $delivery['status']) ?> (<?= (int) ($delivery['attempts'] ?? 0) ?> attempts): <?= velvet_marketing_escape((string) ($delivery['last_error'] ?? 'Review required.')) ?></li><?php endforeach; ?></ul><?php if (count($problems) > 50): ?><p>Showing the first 50 problems.</p><?php endif; ?></details><?php endif; ?>
</article>
<?php endforeach; ?>
</section>
<?php elseif($tab==='audiences'): require root_path('app/Views/admin/marketing-audiences.php'); ?>
<?php elseif($tab==='links'):?><div class="marketing-admin-grid"><section class="marketing-card"><h2>Add Link</h2><form class="marketing-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_link"><label>Label<input name="label" required></label><label>URL<input name="url" required></label><label>Order<input type="number" name="sort" value="50"></label><label><input type="checkbox" name="enabled" value="1" checked style="width:auto"> Enabled</label><button class="marketing-button">Save Link</button></form></section><section class="marketing-card"><h2>Link-in-Bio</h2><?php foreach($data['links'] as $l):?><p><?=velvet_marketing_escape((string)$l['label'])?> — <?=velvet_marketing_escape((string)$l['url'])?></p><?php endforeach;?></section></div>
<?php elseif($tab==='newsletter'):?><section class="marketing-card"><h2>Newsletter Contacts</h2><p><?=count(array_filter($data['contacts'],fn($c)=>($c['status']??'')==='active'))?> active · <?=count(array_filter($data['campaign_queue'],fn($d)=>($d['status']??'')==='queued'))?> queued deliveries</p><table class="marketing-table"><tr><th>Email</th><th>Name</th><th>Status</th><th>Source</th></tr><?php foreach($data['contacts'] as $c):?><tr><td><?=velvet_marketing_escape((string)$c['email'])?></td><td><?=velvet_marketing_escape((string)$c['name'])?></td><td><?=velvet_marketing_escape((string)$c['status'])?></td><td><?=velvet_marketing_escape((string)$c['source'])?></td></tr><?php endforeach;?></table></section>
<?php elseif($tab==='referrals'): require root_path('app/Views/admin/marketing-attribution.php'); ?><?php endif; ?>
<?php if ($tab === 'links') require root_path('app/Views/admin/marketing-link-bio.php'); ?>

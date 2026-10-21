<?php
declare(strict_types=1);
// Private state and final current-owner checks are completed by the controller before output.
$providerForm = $providerAttempt ?? ['enabled' => $providerState['settings']['enabled'] ? '1' : '',
    'public_key' => $providerState['settings']['public_key'], 'provider_revision' => $providerState['revision']];
$providerLabels = ['hard_bounce' => 'Hard bounce', 'blocked' => 'Temporary block', 'spamreport' => 'Spam complaint',
    'unsubscribe' => 'Provider unsubscribe', 'delivered' => 'Receiving server accepted', 'deferred' => 'Deferred',
    'dropped' => 'Provider dropped', 'processed' => 'Provider processed', 'ignored' => 'Ignored engagement/account event'];
?>
<style>.provider-endpoint{overflow-wrap:anywhere;white-space:normal}.provider-enable{display:flex!important;align-items:center;gap:10px;min-height:44px}.provider-enable input{width:auto;flex:none}.provider-events-summary{display:flex;gap:16px;flex-wrap:wrap}.provider-record{overflow-wrap:anywhere}.provider-record small{display:block}</style>
<section class="marketing-card" data-mail-provider>
  <span class="marketing-eyebrow">Signed delivery feedback</span><h2>Twilio SendGrid provider events</h2>
  <p>Configure SendGrid's Signed Event Webhook to report delivery outcomes for campaign and optional notification messages. A matching saved outbound Message-ID and recipient are required before an event can affect local delivery.</p>
  <p data-provider-state><?= $providerState['settings']['enabled'] ? 'Signed provider events are enabled.' : 'Provider events are disabled.' ?> Saving a verification key does not configure SendGrid, change SMTP settings, or verify a production connection.</p>
  <div class="marketing-admin-grid">
    <form method="post" class="marketing-form" data-provider-settings><?= csrf_field() ?>
      <input type="hidden" name="action" value="mail_provider_settings"><input type="hidden" name="provider_revision" value="<?= e(mm_form_text($providerForm['provider_revision'] ?? '', 64)) ?>">
      <label class="provider-enable"><input name="enabled" value="1" type="checkbox" <?= ($providerForm['enabled'] ?? null) === '1' ? 'checked' : '' ?>><span>Enable signed SendGrid events</span></label>
      <label for="provider-public-key">Public verification key</label><textarea id="provider-public-key" name="public_key" rows="5" maxlength="2048" spellcheck="false" autocapitalize="off" aria-describedby="provider-key-help"><?= e(mm_form_text($providerForm['public_key'] ?? '', 2048)) ?></textarea><small id="provider-key-help">Paste the Base64 public verification key shown for this specific SendGrid webhook. Do not paste an API key or a private key.</small>
      <button class="marketing-button">Save Provider Settings</button>
    </form>
    <section><h3>Connect your provider</h3>
      <p>In SendGrid, create an Event Webhook and enable signature verification. Use this exact destination; the endpoint accepts POST directly:</p>
      <p><code class="provider-endpoint" data-provider-endpoint><?= e(base_url('api/mail-events.php')) ?></code></p>
      <p>Use HTTPS on your production host, keep its clock synchronized, and select delivery events, spam reports and unsubscribes. Configure your outgoing SendGrid SMTP connection separately in <a href="<?= e(base_url('admin/?page=mail-settings')) ?>">Email &amp; SMTP</a>.</p>
      <p>SendGrid sample events have no matching saved message and remain unmatched. Verify an actual authorized test delivery and its signed event on your host before relying on this integration.</p>
      <p><a href="https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/getting-started-event-webhook-security-features" target="_blank" rel="noopener noreferrer">Official signature setup</a> · <a href="https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/event" target="_blank" rel="noopener noreferrer">Official event reference</a></p>
    </section>
  </div>
</section>
<section class="marketing-card" style="margin-top:22px" data-provider-journal>
  <h2>Verified event journal</h2>
  <div class="provider-events-summary"><?php foreach ($providerState['counts'] as $status => $count): ?><span><strong><?= (int) $count ?></strong> <?= e($status) ?></span><?php endforeach; ?></div>
  <p>Hard bounces, spam complaints and provider unsubscribes suppress future campaign and optional notification delivery. A temporary block or deferral does not permanently suppress the address. Later delivery events never remove an existing suppression or restore consent.</p>
  <p>Events confirm provider handling; “receiving server accepted” does not prove inbox placement. Unknown SMTP outcomes remain held even after provider feedback and are never automatically retried.</p>
  <p>Raw payloads, message bodies, IP addresses, user agents and open/click tracking are not stored. A pending event keeps its recipient encrypted only until its verified effects finish. Later receipts or reconciliation remove completed event metadata older than eight days; active suppressions and saved delivery outcomes remain.</p>
  <form method="post" data-provider-reconcile><?= csrf_field() ?><input type="hidden" name="action" value="mail_provider_reconcile"><button class="marketing-button">Resume verified pending events</button></form>
  <p>For interrupted requests, a hosting scheduler can run <code>php app/Console/run_mail_events.php --limit=100</code>. This command only applies already verified events and sends no email.</p>
  <?php if ($providerState['events'] === []): ?><p>No verified provider events have been recorded.</p><?php endif; ?>
  <?php foreach ($providerState['events'] as $event): ?><article class="marketing-card provider-record" style="margin-top:16px" data-provider-event="<?= e($event['id']) ?>" data-provider-status="<?= e($event['status']) ?>">
    <h3><?= e($providerLabels[$event['event']]) ?> · <?= e($event['status']) ?></h3>
    <small>Received <?= e(gmdate('Y-m-d H:i:s', $event['received_at'])) ?> UTC<?= $event['delivery_kind'] !== null ? ' · ' . e(ucfirst($event['delivery_kind'])) : '' ?></small>
    <small>Event reference <?= e(substr($event['id'], 0, 16)) ?></small>
  </article><?php endforeach; ?>
</section>

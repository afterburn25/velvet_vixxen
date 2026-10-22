<?php
declare(strict_types=1);

$notificationLabels = ['new_content' => 'New content', 'renewal_reminder' => 'Renewal reminders',
    'renewal' => 'Renewal confirmations', 'payment_failure' => 'Payment failures',
    'pending_registration' => 'Unfinished registration', 'owner_security' => 'Owner security alerts'];
// The controller preloads the private snapshot and rechecks current ownership
// after the dependent read, before any shared admin output is rendered.
$notificationForm = $notificationAttempt ?? ['enabled' => $notificationState['settings']['enabled'] ? '1' : '',
    'types' => $notificationState['settings']['types'], 'notification_revision' => $notificationState['revision']];
$notificationTypes = is_array($notificationForm['types'] ?? null) ? $notificationForm['types'] : [];
?>
<style>.notification-options{display:grid;gap:10px}.notification-options label{display:flex;align-items:center;gap:10px;min-height:44px}.notification-options input{width:auto;flex:none}.notification-summary{display:flex;gap:14px;flex-wrap:wrap}.notification-actions{display:flex;gap:10px;flex-wrap:wrap}.notification-record{overflow-wrap:anywhere}.notification-record small{display:block}.notification-record button{min-height:44px}@media(max-width:600px){.notification-actions>*{width:100%}}</style>
<section class="marketing-card" data-notifications>
  <span class="marketing-eyebrow">Optional notices</span><h2>Member &amp; owner notifications</h2>
  <p>Choose which notices the worker may send. Member notices use their saved preferences; unfinished-registration reminders require a separate opt-in at signup. Email contains a short notice and an account link, with no private content or payment details.</p>
  <p data-notification-state><?= $notificationState['settings']['enabled'] ? 'Notification automation is enabled.' : 'Notification automation is paused.' ?> Viewing this page does not discover work or send mail.</p>
  <div class="marketing-admin-grid">
    <form class="marketing-form" method="post" data-notification-settings><?= csrf_field() ?>
      <input type="hidden" name="action" value="notification_settings"><input type="hidden" name="notification_revision" value="<?= e(mm_form_text($notificationForm['notification_revision'] ?? '', 64)) ?>">
      <div class="notification-options"><label><input type="checkbox" name="enabled" value="1" <?= ($notificationForm['enabled'] ?? null) === '1' ? 'checked' : '' ?>><span>Enable notification automation</span></label>
      <?php foreach ($notificationLabels as $value => $label): ?><label><input type="checkbox" name="types[]" value="<?= e($value) ?>" <?= in_array($value, $notificationTypes, true) ? 'checked' : '' ?>><span><?= e($label) ?></span></label><?php endforeach; ?></div>
      <p>First enablement starts from the current content catalog. It does not announce older releases. New content is announced once to current members who can access it and opted in. Pending members can receive one registration reminder, after 24 hours and within seven days.</p>
      <button class="marketing-button">Save Notification Settings</button>
    </form>
    <section>
      <h3>Run a bounded batch</h3>
      <p>Discovery reads committed publications, memberships, subscriptions and orders. It examines up to 25 members and 25 content recipients per run, queues at most 100 notices, and resumes through the saved cursor.</p>
      <form method="post" data-notification-discover><?= csrf_field() ?><input type="hidden" name="action" value="notification_discover"><button class="marketing-button">Discover notifications</button></form>
      <form class="marketing-form" method="post" style="margin-top:20px" data-notification-run><?= csrf_field() ?><input type="hidden" name="action" value="notification_run">
        <label>Notices per batch<input name="batch_limit" type="number" min="1" max="10" value="3" required></label>
        <button class="marketing-button" <?= $notificationMailProblem !== null ? 'disabled' : '' ?>>Run notification batch</button>
      </form>
      <?php if ($notificationMailProblem !== null): ?><p class="marketing-notice" role="status"><?= e($notificationMailProblem) ?> <a href="<?= e(base_url('admin/?page=mail-settings')) ?>">Email &amp; SMTP settings</a></p><?php endif; ?>
      <p>A hosting scheduler can run <code>php app/Console/run_notifications.php --limit=10</code>. Configure and verify that scheduler on your host. This page does not install a cron job.</p>
    </section>
  </div>
</section>
<section class="marketing-card" style="margin-top:22px" data-notification-journal>
  <h2>Delivery journal</h2>
  <div class="notification-summary" data-notification-counts><?php foreach ($notificationState['counts'] as $status => $count): ?><span><strong><?= (int) $count ?></strong> <?= e($status === 'sent' ? 'accepted' : $status) ?></span><?php endforeach; ?></div>
  <p>Accepted means the mail transport accepted the message; it does not prove inbox arrival. Unknown outcomes may already have been accepted and cannot be retried. Definitely failed, retryable notices allow up to three total attempts, at least one minute apart. Notices older than seven days are skipped.</p>
  <?php if ($notificationState['jobs'] === []): ?><p>No notifications have been queued.</p><?php endif; ?>
  <?php foreach ($notificationState['jobs'] as $job): ?>
  <article class="marketing-card notification-record" style="margin-top:16px" data-notification-id="<?= e($job['id']) ?>" data-notification-status="<?= e($job['status']) ?>">
    <h3><?= e($notificationLabels[$job['type']]) ?> · <?= e($job['status'] === 'sent' ? 'accepted' : $job['status']) ?></h3>
    <small>Queued <?= e(gmdate('Y-m-d H:i:s', $job['created_at'])) ?> UTC · <?= (int) $job['attempts'] ?> attempts</small>
    <small>Source <?= e($job['source_id']) ?> · <?= $job['member_id'] !== null ? 'Member ' . e($job['member_id']) : 'Current owner' ?></small>
    <?php if ($job['last_error'] !== null): ?><p><?= e($job['last_error']) ?></p><?php endif; ?>
    <?php if (in_array($job['status'], ['queued', 'claimed', 'failed'], true)): ?>
    <form method="post" class="notification-actions"><?= csrf_field() ?><input type="hidden" name="notification_id" value="<?= e($job['id']) ?>"><input type="hidden" name="notification_revision" value="<?= e($job['revision']) ?>">
      <button class="marketing-button" name="action" value="notification_cancel">Cancel notification</button>
      <?php if ($job['status'] === 'failed' && $job['retryable'] && $job['attempts'] < 3): ?><button class="marketing-button" name="action" value="notification_retry" <?= $job['next_attempt_at'] > time() ? 'disabled' : '' ?>>Retry failed notification</button><?php endif; ?>
    </form><?php endif; ?>
  </article><?php endforeach; ?>
  <?php if ($notificationState['total'] > 100): ?><p>Showing the 100 newest entries. Counts include the full journal.</p><?php endif; ?>
</section>

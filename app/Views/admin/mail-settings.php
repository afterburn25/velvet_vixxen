<?php
declare(strict_types=1);

$mailOwner = vvx_mail_settings_require_owner();
$mailView = null;
try {
    $mailView = (new App\Core\MailSettingsService(app_config(), (string) $mailOwner['email']))->snapshot();
} catch (Throwable) {
    echo '<div class="admin-alert error" role="alert">Email settings could not be loaded. Check that the site configuration is readable, then reload this page.</div>';
    return;
}
$mailProblem = (new App\Core\MailService(app_config(), app_store()))->configurationProblem();
$modeNames = ['disabled' => 'Delivery disabled', 'smtp' => 'SMTP server', 'php' => 'PHP mail'];
$testTokens = (array) ($_SESSION['_mail_test_tokens'] ?? []);
$testTokens = array_filter($testTokens, static fn($claim): bool => is_array($claim) && (int) ($claim['issued'] ?? 0) >= time() - 1800);
$testTokens = array_slice($testTokens, -9, null, true);
$mailTestToken = bin2hex(random_bytes(24));
$testTokens[$mailTestToken] = ['owner' => (string) $mailOwner['id'], 'revision' => $mailView['revision'], 'issued' => time()];
$_SESSION['_mail_test_tokens'] = $testTokens;
$mailTestResult = $_SESSION['_mail_test_result'][(string) $mailOwner['id']] ?? null;
if (!is_array($mailTestResult) || !is_string($mailTestResult['revision'] ?? null)
    || !hash_equals($mailView['revision'], $mailTestResult['revision'])) $mailTestResult = null;
?>
<section class="mail-intro">
  <div><span class="admin-kicker">Delivery settings</span><h2>Connect your site's email.</h2><p>Use one saved connection for account emails, booking notifications, and newsletter campaigns.</p></div>
  <span class="mail-mode-badge" data-mail-saved-mode><?= e($modeNames[$mailView['mode']] ?? 'Needs attention') ?></span>
</section>
<div class="mail-settings-layout" data-mail-settings>
  <form method="post" action="<?= e(base_url('admin/?page=mail-settings')) ?>" class="admin-card mail-settings-form" data-mail-editor autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_mail_settings"><input type="hidden" name="mail_revision" value="<?= e($mailView['revision']) ?>">
    <div class="card-head"><div><span class="admin-kicker">01 / Connection</span><h2>How should email be sent?</h2></div></div>
    <label for="mail-mode">Delivery method<select name="mode" id="mail-mode" data-mail-mode>
      <?php foreach (['disabled' => 'Disabled — prepare your site without sending', 'smtp' => 'SMTP — use your email provider', 'php' => 'PHP mail — use your hosting server'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $mailView['mode'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
    <p class="mail-help" data-mail-mode-description>Saving changes does not send email. Newsletter signup and campaign delivery have their own switches.</p>
    <div class="mail-section-heading"><span class="admin-kicker">02 / Sender</span><h2>What recipients see</h2></div>
    <div class="mail-field-pair">
      <label for="mail-from-name">Sender name<input id="mail-from-name" name="from_name" maxlength="190" value="<?= e($mailView['from_name']) ?>" autocomplete="organization"></label>
      <label for="mail-from-email"><span>From email <small>optional</small></span><input id="mail-from-email" name="from_email" type="email" maxlength="254" value="<?= e($mailView['from_email']) ?>" placeholder="you@example.com" aria-describedby="mail-sender-help" autocomplete="email"></label>
    </div>
    <p class="mail-help" id="mail-sender-help">Use an address your provider permits. Leave it blank to use a valid SMTP username, then your owner email as a fallback.</p>
    <fieldset class="mail-smtp-fields" data-mail-smtp>
      <legend><span class="admin-kicker">03 / SMTP server</span></legend>
      <p class="mail-help">Enter the server details supplied by your email provider.</p>
      <div class="mail-field-pair mail-host-pair">
        <label for="mail-host">SMTP host<input id="mail-host" name="host" maxlength="253" value="<?= e($mailView['host']) ?>" placeholder="smtp.example.com" spellcheck="false" autocapitalize="none" data-smtp-required></label>
        <label for="mail-port">Port<input id="mail-port" name="port" type="number" min="1" max="65535" value="<?= (int) $mailView['port'] ?>" data-smtp-required></label>
      </div>
      <label for="mail-encryption">Connection security<select id="mail-encryption" name="encryption">
        <?php foreach (['tls' => 'STARTTLS — commonly port 587', 'ssl' => 'TLS from connection start — commonly port 465', 'none' => 'None — only when required by your server'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $mailView['encryption'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select></label>
      <label for="mail-username">SMTP username<input id="mail-username" name="username" maxlength="254" value="<?= e($mailView['username']) ?>" autocomplete="off" autocapitalize="none" spellcheck="false"></label>
      <label for="mail-password">SMTP password<input id="mail-password" name="password" type="password" maxlength="4096" value="" autocomplete="new-password" placeholder="<?= $mailView['password_set'] ? 'Password saved — leave blank to keep it' : 'Enter your SMTP password' ?>" aria-describedby="mail-password-help"></label>
      <p class="mail-help" id="mail-password-help"><?= $mailView['password_set'] ? 'A password is saved. Its value is never shown here.' : 'No SMTP password is saved.' ?> A blank field keeps the saved value.</p>
      <label class="mail-inline-check"><input type="checkbox" name="clear_password" value="1" data-mail-clear-password><span>Remove the saved SMTP password</span></label>
    </fieldset>
    <div class="mail-save-row"><button class="admin-button primary" type="submit" data-mail-save>Save Email Settings</button><span class="mail-help" data-mail-unsaved role="status">Your saved settings are shown.</span></div>
  </form>
  <aside class="mail-side-panels">
    <section class="admin-card mail-test-card" id="mail-test">
      <span class="admin-kicker">Check delivery</span><h2>Send yourself a test</h2>
      <p>This sends one plain-text test to your owner account using the <strong>saved</strong> settings.</p>
      <div class="mail-recipient"><span>Test recipient</span><strong><?= e((string) $mailOwner['email']) ?></strong></div>
      <p class="mail-status-note" data-mail-transport-status><?= e($mailProblem ?? 'Saved transport settings are ready for a test.') ?></p>
      <form method="post" action="<?= e(base_url('admin/?page=mail-settings')) ?>" data-mail-test-form>
        <?= csrf_field() ?><input type="hidden" name="action" value="send_mail_test"><input type="hidden" name="mail_revision" value="<?= e($mailView['revision']) ?>"><input type="hidden" name="mail_test_token" value="<?= e($mailTestToken) ?>">
        <button type="submit" class="admin-button secondary full" data-mail-test <?= $mailProblem !== null ? 'disabled' : '' ?>>Send Test Email</button>
      </form>
      <p class="mail-help">Save edits before testing. A successful test means your mail transport accepted the message; check your inbox to confirm arrival.</p>
      <?php if (is_array($mailTestResult)): ?>
      <div class="mail-test-result <?= ($mailTestResult['status'] ?? '') === 'sent' ? 'accepted' : 'attention' ?>" role="status" data-mail-test-result>
        <strong><?= ($mailTestResult['status'] ?? '') === 'sent' ? 'Test accepted' : 'Test needs attention' ?></strong>
        <p><?= e((string) ($mailTestResult['message'] ?? '')) ?></p><time datetime="<?= e((string) ($mailTestResult['at'] ?? '')) ?>"><?= e((string) ($mailTestResult['at'] ?? '')) ?></time>
      </div>
      <?php endif; ?>
    </section>
    <section class="admin-card mail-related-card"><span class="admin-kicker">Related controls</span><h2>Choose which emails run</h2><p>Your connection is shared. Each feature keeps its own delivery preferences.</p><a href="<?= e(base_url('admin/?page=marketing&tab=campaigns')) ?>">Newsletter campaigns <span aria-hidden="true">↗</span></a><a href="<?= e(base_url('admin/?page=communication-settings')) ?>">Communication settings <span aria-hidden="true">↗</span></a></section>
  </aside>
</div>

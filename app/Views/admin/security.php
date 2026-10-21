<?php
declare(strict_types=1);
$enrollment = $_SESSION['admin_enrollment'] ?? null;
if (!is_array($enrollment) || (int) ($enrollment['expires'] ?? 0) <= time()) $enrollment = null;
?>
<div class="security-grid">
<section class="admin-card" aria-labelledby="security-factor-heading">
<h2 id="security-factor-heading">Authenticator app</h2>
<p><?= $securityOverview['enabled'] ? 'Two-factor authentication is enabled. Sign-in requires your password and an authenticator or recovery code.' : 'Add a second step to administrator sign-in using an authenticator app.' ?></p>
<?php if ($securityRecoveryCodes !== []): ?>
<div class="admin-alert success" role="status"><strong>Save your recovery codes now.</strong> Each code works once. They are shown only in this response. Store them somewhere private, separately from this site.</div>
<ul class="security-codes"><?php foreach ($securityRecoveryCodes as $recoveryCode): ?><li><code><?= e($recoveryCode) ?></code></li><?php endforeach; ?></ul>
<?php elseif ($enrollment !== null && !$securityOverview['enabled']): ?>
<p>Add a new account in your authenticator using this setup key. Choose time based, six digits and a 30-second interval.</p>
<p class="security-secret"><code><?= e($enrollment['secret']) ?></code></p>
<p>Account: <?= e($user['email']) ?> · Velvet Vixxen. Setup expires in ten minutes.</p>
<form method="post" class="security-form"><?= csrf_field() ?><input type="hidden" name="action" value="security_enable"><label>Authenticator code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button class="admin-button primary">Connect authenticator</button></form>
<?php elseif (!$securityOverview['enabled']): ?>
<form method="post" class="security-form"><?= csrf_field() ?><input type="hidden" name="action" value="security_begin"><label>Current password<input type="password" name="password" autocomplete="current-password" required></label><button class="admin-button primary">Set up authenticator</button></form>
<?php else: ?>
<p><?= (int) $securityOverview['recovery_count'] ?> unused recovery codes remain.</p>
<details><summary>Remove authenticator</summary><form method="post" class="security-form"><?= csrf_field() ?><input type="hidden" name="action" value="security_disable"><label>Current password<input type="password" name="password" autocomplete="current-password" required></label><label>Authenticator or recovery code<input name="code" autocomplete="one-time-code" maxlength="64" required></label><label class="security-check"><input type="checkbox" name="confirm_disable" value="1" required>Remove two-factor authentication and sign out other sessions</label><button class="admin-button">Remove authenticator</button></form></details>
<?php endif; ?>
</section>
<section class="admin-card" aria-labelledby="security-confirm-heading"><h2 id="security-confirm-heading">Confirm protected actions</h2><p>Private records, credentials, system recovery and other sensitive changes require your identity to have been confirmed within the last 15 minutes.</p>
<form method="post" class="security-form"><?= csrf_field() ?><input type="hidden" name="action" value="security_confirm"><label>Current password<input type="password" name="password" autocomplete="current-password" required></label><?php if ($securityOverview['enabled']): ?><label>Authenticator or recovery code<input name="code" autocomplete="one-time-code" maxlength="64" required></label><?php endif; ?><button class="admin-button primary">Confirm my identity</button></form></section>
<section class="admin-card" aria-labelledby="security-sessions-heading"><h2 id="security-sessions-heading">Administrator sessions</h2><p>Sessions expire after 12 hours. Password changes and disabling an account end access immediately.</p><ul><?php foreach ($securityOverview['sessions'] as $securitySession): ?><li>Signed in <?= e($securitySession['created']) ?> · expires <?= e($securitySession['expires']) ?></li><?php endforeach; ?></ul><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="security_revoke"><button class="admin-button">Sign out my other sessions</button></form></section>
<section class="admin-card security-wide" aria-labelledby="security-events-heading"><h2 id="security-events-heading">Security activity</h2><p>A new-client sign-in appears here and queues an owner alert when notification automation is configured. This list contains the latest 100 security events.</p><div class="security-table" tabindex="0" role="region" aria-labelledby="security-events-heading"><table><thead><tr><th scope="col">When (UTC)</th><th scope="col">Action</th><th scope="col">Account</th></tr></thead><tbody><?php if ($securityOverview['events'] === []): ?><tr><td colspan="3">No security activity has been recorded.</td></tr><?php endif; ?><?php foreach ($securityOverview['events'] as $securityEvent): ?><tr><td><?= e($securityEvent['at'] ?? '') ?></td><td><?= e($securityEvent['action'] ?? '') ?></td><td><?= e($securityEvent['admin'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="admin-card security-wide" aria-labelledby="security-audit-heading"><h2 id="security-audit-heading">Recent administrative actions</h2><div class="security-table" tabindex="0" role="region" aria-labelledby="security-audit-heading"><table><thead><tr><th scope="col">When</th><th scope="col">Action</th><th scope="col">Account</th></tr></thead><tbody><?php if ($securityAudit === []): ?><tr><td colspan="3">No administrative actions have been recorded.</td></tr><?php endif; ?><?php foreach ($securityAudit as $auditItem): ?><tr><td><?= e($auditItem['createdAt'] ?? '') ?></td><td><?= e($auditItem['action'] ?? '') ?></td><td><?= e((string) ($auditItem['adminId'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div>

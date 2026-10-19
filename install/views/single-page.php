<?php
declare(strict_types=1);
/** @var bool $installed */
/** @var array $lockData */
/** @var array $systemChecks */
/** @var array $fileResults */
/** @var bool $requirementsReady */
/** @var string $installId */
/** @var string $baseGuess */
/** @var string $error */
/** @var string $notice */
/** @var array $form */

$value = static fn(string $key, string $default = ''): string => e((string) ($form[$key] ?? $default));
$requiredSystemFailures = array_values(array_filter(
    $systemChecks,
    static fn(array $row): bool => ($row['required'] ?? false) && ($row['status'] ?? 'fail') !== 'pass',
));
$visibleSystemIssues = array_values(array_filter(
    $systemChecks,
    static fn(array $row): bool => ($row['status'] ?? 'fail') !== 'pass',
));
$requiredFileFailures = array_values(array_filter(
    $fileResults,
    static fn(array $row): bool => ($row['required'] ?? false) && ($row['status'] ?? 'fail') !== 'pass',
));
$visibleFileIssues = array_values(array_filter(
    $fileResults,
    static fn(array $row): bool => ($row['status'] ?? 'fail') !== 'pass',
));
$version = defined('VELVET_VIXXEN_VERSION') ? VELVET_VIXXEN_VERSION : '0.8.1';
$ownerEmailDefault = (string) ($form['admin_email'] ?? '');
$supportEmailDefault = (string) ($form['support_email'] ?? $ownerEmailDefault);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#10050d">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= $installed ? 'Installation Locked' : 'Install Velvet Vixxen' ?> · Nikki Wilder</title>
<link rel="stylesheet" href="<?= e(asset_url('css/installer.css')) ?>">
</head>
<body class="single-page-installer simplified-installer" data-requirements-ready="<?= $requirementsReady ? '1' : '0' ?>" data-install-id="<?= e($installId) ?>" data-endpoint="<?= e(base_url('install/')) ?>">
<main class="installer-shell simplified-shell">
  <header class="installer-brand">
    <a class="brand-lockup" href="<?= e(base_url('install/')) ?>" aria-label="Velvet Vixxen installer">
      <span class="brand-script">Nikki Wilder</span>
      <span class="brand-sub">The Velvet Vixxen</span>
    </a>
    <span class="version-chip">Version <?= e($version) ?></span>
  </header>

  <?php if ($installed): ?>
    <section class="installer-panel locked-page">
      <div class="locked-card">
        <div class="locked-icon">✓</div>
        <span class="kicker">Installation locked</span>
        <h1>This website is already installed.</h1>
        <p>The installation lock is active, so this page cannot overwrite the existing website.</p>
        <div class="lock-facts compact-facts">
          <div><span>Version</span><strong><?= e((string) ($lockData['version'] ?? $version)) ?></strong></div>
          <div><span>Installed</span><strong><?= e((string) ($lockData['installed_at'] ?? 'Verified')) ?></strong></div>
          <div><span>Installer</span><strong><?= is_dir(ROOT_PATH . '/install') ? 'Disabled' : 'Removed' ?></strong></div>
        </div>
        <?php if ($error !== ''): ?><div class="notice error" role="alert"><strong>Cleanup needs attention</strong><?= e($error) ?></div><?php endif; ?>
        <div class="actions centered">
          <a class="button secondary" href="<?= e(base_url()) ?>">View Website</a>
          <a class="button primary" href="<?= e(base_url('admin/?page=login')) ?>">Open Admin</a>
          <?php if (is_dir(ROOT_PATH . '/install')): ?>
            <form method="post" data-cleanup-form>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="cleanup_installer">
              <button class="button secondary" type="submit">Retry Cleanup</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </section>
  <?php else: ?>
    <section class="installer-panel simple-installer-panel">
      <div class="simple-installer-main">
        <header class="simple-intro">
          <span class="kicker">Quick setup</span>
          <h1>Install Velvet Vixxen</h1>
          <p>Enter the database and owner details below. Everything else uses safe defaults and can be changed later in Admin.</p>
        </header>

        <?php if ($error !== ''): ?><div class="notice error page-notice" role="alert" data-page-notice><strong>Installation needs attention</strong><?= e($error) ?></div><?php endif; ?>
        <?php if ($notice !== ''): ?><div class="notice success page-notice" role="status" data-page-notice><strong>Ready</strong><?= e($notice) ?></div><?php endif; ?>
        <div class="notice page-notice" data-live-notice hidden role="status"></div>

        <div class="readiness-card <?= $requirementsReady ? 'ready' : 'attention' ?>">
          <span class="readiness-icon"><?= $requirementsReady ? '✓' : '!' ?></span>
          <div class="readiness-copy">
            <strong data-readiness-title><?= $requirementsReady ? 'Server and folders are ready' : 'Server setup needs attention' ?></strong>
            <small data-readiness-copy>
              <?= $requirementsReady
                  ? 'All required checks passed. Technical details are hidden below.'
                  : count($requiredSystemFailures) . ' server and ' . count($requiredFileFailures) . ' folder checks must be corrected.' ?>
            </small>
          </div>
          <button class="button compact secondary" type="button" data-recheck>Run Checks</button>
        </div>

        <details class="technical-details" id="readiness"<?= $requirementsReady ? '' : ' open' ?>>
          <summary>Technical details <span data-technical-summary><?= $requirementsReady ? 'All required checks passed' : 'Open to review failures' ?></span></summary>
          <div class="technical-content">
            <div class="check-summary compact-check-summary">
              <div><span>Server failures</span><strong data-system-failure-count><?= count($requiredSystemFailures) ?></strong></div>
              <div><span>Folder failures</span><strong data-file-failure-count><?= count($requiredFileFailures) ?></strong></div>
              <div><span>Permissions</span><strong>0755 / 0775</strong></div>
            </div>

            <div class="issue-groups">
              <div>
                <h3>Server</h3>
                <div class="check-grid" data-system-checks>
                  <?php if ($visibleSystemIssues === []): ?>
                    <article class="check-row pass compact-success"><span class="check-icon">✓</span><span><b>Required server checks passed</b><small>Optional tools can be configured later.</small></span></article>
                  <?php else: ?>
                    <?php foreach ($visibleSystemIssues as $check): ?>
                      <article class="check-row <?= e((string) $check['status']) ?>">
                        <span class="check-icon"><?= ($check['status'] ?? '') === 'warn' ? '!' : '×' ?></span>
                        <span><b><?= e((string) $check['label']) ?></b><small><?= e((string) $check['detail']) ?></small></span>
                        <span class="check-badge"><?= ($check['required'] ?? false) ? 'Required' : 'Optional' ?></span>
                      </article>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>

              <div>
                <h3>Folders</h3>
                <div class="file-check-list" data-file-checks>
                  <?php if ($visibleFileIssues === []): ?>
                    <article class="file-check pass compact-success"><span class="file-check-icon">✓</span><span><b>Required folders are writable</b><small>Real write, rename, read, and delete tests passed.</small></span><code>OK</code></article>
                  <?php else: ?>
                    <?php foreach ($visibleFileIssues as $row): ?>
                      <article class="file-check <?= e((string) $row['status']) ?>">
                        <span class="file-check-icon">×</span>
                        <span><b>/<?= e((string) $row['path']) ?></b><small><?= e((string) $row['message']) ?></small></span>
                        <code><?= e((string) $row['mode']) ?></code>
                      </article>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </details>

        <form method="post" id="installerForm" autocomplete="off" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="install_now">
          <input type="hidden" name="support_email" value="<?= e($supportEmailDefault) ?>">
          <input type="hidden" name="currency" value="<?= $value('currency', 'USD') ?>">
          <input type="hidden" name="timezone" value="<?= $value('timezone', 'America/Chicago') ?>">
          <input type="hidden" name="age_gate_enabled" value="1">
          <input type="hidden" name="mail_mode" value="disabled">
          <input type="hidden" name="mail_from_name" value="<?= $value('mail_from_name', 'Nikki Wilder') ?>">
          <input type="hidden" name="mail_from_email" value="<?= e($ownerEmailDefault) ?>">
          <input type="hidden" name="smtp_host" value="">
          <input type="hidden" name="smtp_port" value="587">
          <input type="hidden" name="smtp_encryption" value="tls">
          <input type="hidden" name="smtp_username" value="">
          <input type="hidden" name="smtp_password" value="">

          <section class="simple-section install-section" id="database">
            <header class="simple-section-heading">
              <span class="section-number">1</span>
              <div><h2>Database</h2><p>Use the empty MySQL or MariaDB database from your hosting panel.</p></div>
              <span class="section-state waiting" data-section-state="database">Not tested</span>
            </header>
            <div class="simple-section-body">
              <div class="form-grid compact-form-grid">
                <label class="field"><span>Database host</span><input name="db_host" value="<?= $value('db_host', 'localhost') ?>" required data-required><em data-field-error="db_host"></em></label>
                <label class="field"><span>Database name</span><input name="db_name" value="<?= $value('db_name') ?>" required data-required><em data-field-error="db_name"></em></label>
                <label class="field"><span>Database username</span><input name="db_user" value="<?= $value('db_user') ?>" required data-required autocomplete="off"><em data-field-error="db_user"></em></label>
                <label class="field"><span>Database password</span><span class="password-wrap"><input id="dbPassword" type="password" name="db_password" autocomplete="new-password"><button class="password-toggle" type="button" data-toggle-password="dbPassword">Show</button></span></label>
              </div>
              <details class="inline-advanced">
                <summary>Advanced database setting</summary>
                <label class="field narrow-field"><span>Port</span><input type="number" name="db_port" min="1" max="65535" value="<?= $value('db_port', '3306') ?>" required data-required><em data-field-error="db_port"></em></label>
              </details>
              <div class="inline-test-row simple-test-row">
                <button class="button secondary" type="button" data-test-database>Test Connection</button>
                <div class="inline-result" data-database-result><span>The installer will also test it automatically.</span></div>
              </div>
            </div>
          </section>

          <section class="simple-section install-section" id="owner-site">
            <header class="simple-section-heading">
              <span class="section-number">2</span>
              <div><h2>Owner and website</h2><p>Create your private owner login and confirm the website address.</p></div>
              <span class="section-state waiting" data-section-state="owner">Incomplete</span>
            </header>
            <div class="simple-section-body">
              <div class="form-grid compact-form-grid">
                <label class="field"><span>Owner name</span><input name="admin_name" value="<?= $value('admin_name', 'Nikki Wilder') ?>" required data-required><em data-field-error="admin_name"></em></label>
                <label class="field"><span>Owner email</span><input type="email" name="admin_email" value="<?= $value('admin_email') ?>" required data-required autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" placeholder="red.pixie93@gmail.com"><em data-field-error="admin_email"></em></label>
                <label class="field"><span>Password</span><span class="password-wrap"><input id="ownerPassword" data-password type="password" name="admin_password" minlength="12" maxlength="4096" required data-required autocomplete="new-password"><button class="password-toggle" type="button" data-toggle-password="ownerPassword">Show</button></span><span class="strength" data-strength-for="ownerPassword"><span></span></span><small data-strength-label="ownerPassword">0/12 characters</small><em data-field-error="admin_password"></em></label>
                <label class="field"><span>Confirm password</span><span class="password-wrap"><input id="ownerPasswordConfirm" type="password" name="admin_password_confirmation" minlength="12" maxlength="4096" required data-required autocomplete="new-password"><button class="password-toggle" type="button" data-toggle-password="ownerPasswordConfirm">Show</button></span><em data-field-error="admin_password_confirmation"></em></label>
                <label class="field full"><span>Site name</span><input name="site_name" value="<?= $value('site_name', 'Nikki Wilder — The Velvet Vixxen') ?>" required data-required><em data-field-error="site_name"></em></label>
                <label class="field full"><span>Website URL</span><input type="url" name="base_url" value="<?= $value('base_url', $baseGuess) ?>" required data-required><small>Already detected. Change it only when the site uses a different domain or folder.</small><em data-field-error="base_url"></em></label>
              </div>
              <div class="simple-defaults-note">Email delivery, currency, time zone, and the 18+ gate use safe defaults and remain editable in Admin after installation.</div>
            </div>
          </section>

          <section class="simple-section install-section final-install-section" id="install-review">
            <header class="simple-section-heading">
              <span class="section-number">3</span>
              <div><h2>Install</h2><p>The installer creates the tables, owner account, secure configuration, and installation lock.</p></div>
              <span class="section-state waiting" data-section-state="install">Waiting</span>
            </header>
            <div class="simple-section-body">
              <label class="confirmation"><input type="checkbox" name="confirm_install" value="1" required><span><b>I am ready to install the website.</b><small>The selected database must not contain an existing Velvet Vixxen installation.</small></span></label>
              <button class="button primary install-button" type="submit" data-install-button <?= $requirementsReady ? '' : 'disabled' ?>><span>Install Velvet Vixxen</span><b>→</b></button>
              <p class="fine centered-text">No install key is required. Email delivery can be configured later.</p>
            </div>
          </section>
        </form>

        <section class="installation-console simplified-console" data-installation-console hidden aria-live="polite">
          <div class="console-head">
            <div class="pulse-logo">♡</div>
            <div><span class="kicker">Installing</span><h3 data-progress-label>Preparing installation</h3><p data-progress-detail>Please keep this page open.</p></div>
            <strong data-progress-percent>0%</strong>
          </div>
          <div class="progress-track large"><span data-progress-bar></span></div>
          <div class="progress-timeline compact-timeline" data-progress-timeline>
            <?php foreach ([
              'filesystem' => 'Prepare folders',
              'database' => 'Verify database',
              'migrations' => 'Create tables',
              'owner' => 'Create owner',
              'verification' => 'Verify website',
              'cleanup' => 'Remove installer',
            ] as $stage => $label): ?>
              <div data-progress-stage="<?= e($stage) ?>"><i>○</i><span><?= e($label) ?></span></div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="installation-success" data-installation-success hidden>
          <div class="success-mark">✓</div>
          <span class="kicker">Installation complete</span>
          <h2>Your Velvet Vixxen website is ready.</h2>
          <p data-success-message>The website and owner account were verified.</p>
          <div class="success-facts"><span><b>Owner:</b> <em data-success-owner></em></span><span><b>Migrations:</b> <em data-success-migrations></em></span></div>
          <a class="button primary" href="#" data-login-link>Open Owner Login →</a>
        </section>
      </div>
    </section>
  <?php endif; ?>

  <footer class="installer-footer">Quick one-page setup · 12-character minimum password · Safe permissions · Automatic cleanup</footer>
</main>
<script>
window.VVX_INSTALLER = <?= json_encode([
    'requirementsReady' => $requirementsReady,
    'progressId' => $installId,
    'endpoint' => base_url('install/'),
    'csrf' => csrf_token(),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= e(asset_url('js/installer.js')) ?>" defer></script>
</body>
</html>

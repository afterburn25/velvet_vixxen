(() => {
  'use strict';

  const config = window.VVX_INSTALLER || {};
  const body = document.body;
  const form = document.getElementById('installerForm');
  const endpoint = config.endpoint || body.dataset.endpoint || window.location.href;
  const csrf = config.csrf || document.querySelector('meta[name="csrf-token"]')?.content || '';
  let requirementsReady = Boolean(config.requirementsReady ?? body.dataset.requirementsReady === '1');
  let databaseVerified = false;
  let databaseFingerprint = '';
  let mailVerified = false;
  let installRunning = false;
  let progressTimer = null;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

  const escapeText = value => String(value ?? '');

  function setButtonLoading(button, loading, label = '') {
    if (!button) return;
    if (!button.dataset.originalText) button.dataset.originalText = button.textContent.trim();
    button.classList.toggle('is-loading', loading);
    button.disabled = loading;
    if (label) button.textContent = loading ? label : button.dataset.originalText;
    else if (!loading) button.textContent = button.dataset.originalText;
  }

  function showNotice(message, type = 'error') {
    const notice = $('[data-live-notice]');
    if (!notice) return;
    notice.hidden = false;
    notice.className = `notice page-notice ${type}`;
    notice.innerHTML = '';
    const strong = document.createElement('strong');
    strong.textContent = type === 'success' ? 'Ready' : type === 'loading' ? 'Working' : type === 'warn' ? 'Needs attention' : 'Installation needs attention';
    notice.append(strong, document.createTextNode(escapeText(message)));
    if (type === 'error' || type === 'warn') {
      notice.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  function clearNotice() {
    const notice = $('[data-live-notice]');
    if (notice) notice.hidden = true;
  }

  async function request(action, extra = null, method = 'POST') {
    const options = {
      method,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
    };
    let url = endpoint;
    if (method === 'GET') {
      const params = new URLSearchParams(extra || {});
      params.set('action', action);
      url += (url.includes('?') ? '&' : '?') + params.toString();
    } else {
      const data = form ? new FormData(form) : new FormData();
      data.set('_csrf', csrf);
      data.set('action', action);
      if (extra) {
        Object.entries(extra).forEach(([key, value]) => data.set(key, String(value)));
      }
      options.body = data;
    }

    const response = await fetch(url, options);
    let payload;
    try {
      payload = await response.json();
    } catch {
      throw new Error(`The server returned an unreadable response (${response.status}).`);
    }
    if (!response.ok || !payload.ok) {
      throw new Error(payload.message || `The request failed (${response.status}).`);
    }
    return payload;
  }

  function setSectionState(name, state, text) {
    const badge = $(`[data-section-state="${name}"]`);
    if (badge) {
      badge.className = `section-state ${state}`;
      badge.textContent = text;
    }
    const status = $(`[data-status-${name}]`);
    if (status) status.textContent = text;
    const link = $(`[data-status-link="${name}"]`);
    if (link) {
      link.classList.remove('complete', 'attention', 'current');
      if (state === 'pass') link.classList.add('complete');
      if (state === 'fail') link.classList.add('attention');
      const icon = $('i', link);
      if (icon) icon.textContent = state === 'pass' ? '✓' : state === 'fail' ? '!' : icon.textContent;
    }
    updateOverallProgress();
  }

  function updateOverallProgress() {
    let percent = 0;
    if (requirementsReady) percent += 20;
    if (databaseVerified) percent += 20;
    if (ownerFieldsComplete()) percent += 25;
    const mode = form?.elements.mail_mode?.value || 'disabled';
    if (mode === 'disabled' || mailVerified) percent += 10;
    if (form?.elements.confirm_install?.checked) percent += 10;
    if (installRunning) percent = Math.max(percent, 75);
    const bar = $('[data-overall-bar]');
    const label = $('[data-overall-percent]');
    if (bar) bar.style.width = `${Math.min(percent, 100)}%`;
    if (label) label.textContent = `${Math.min(percent, 100)}%`;
  }

  function field(name) {
    return form?.elements.namedItem(name) || null;
  }

  function fieldValue(name) {
    const element = field(name);
    return element ? String(element.value || '').trim() : '';
  }

  function setFieldError(name, message = '') {
    const element = field(name);
    const error = $(`[data-field-error="${name}"]`);
    if (element && 'setAttribute' in element) {
      if (message) element.setAttribute('aria-invalid', 'true');
      else element.removeAttribute('aria-invalid');
    }
    if (error) error.textContent = message;
  }

  function clearFieldErrors() {
    $$('[aria-invalid="true"]', form || document).forEach(element => element.removeAttribute('aria-invalid'));
    $$('[data-field-error]', form || document).forEach(element => { element.textContent = ''; });
  }

  function normalizeEmailValue(value) {
    let normalized = String(value ?? '').trim();
    const bracketed = normalized.match(/<([^<>]+)>/);
    if (bracketed) normalized = bracketed[1];
    normalized = normalized
      .replace(/^mailto\s*:/i, '')
      .replace(/[＠﹫]/g, '@')
      .replace(/[。．｡]/g, '.')
      .replace(/[\s\u00A0\u1680\u2000-\u200D\u202F\u205F\u2060\u3000\uFEFF]/g, '')
      .toLowerCase();
    return normalized;
  }

  function normalizeEmailField(name) {
    const element = field(name);
    if (!element || !('value' in element)) return '';
    const normalized = normalizeEmailValue(element.value);
    element.value = normalized;
    return normalized;
  }

  function normalizeInstallerEmailFields() {
    const ownerEmail = normalizeEmailField('admin_email');
    const support = field('support_email');
    const from = field('mail_from_email');
    const fromName = field('mail_from_name');
    const ownerName = fieldValue('admin_name');

    // The streamlined installer uses the owner address for support and sender
    // defaults. These values remain editable later in Admin.
    if (ownerEmail) {
      if (support && 'value' in support) support.value = ownerEmail;
      if (from && 'value' in from) from.value = ownerEmail;
    }
    if (fromName && 'value' in fromName && ownerName) fromName.value = ownerName;

    normalizeEmailField('support_email');
    normalizeEmailField('mail_from_email');
  }

  function isEmail(value) {
    const normalized = normalizeEmailValue(value);
    if (!normalized) return false;
    const input = document.createElement('input');
    input.type = 'email';
    input.value = normalized;
    return input.checkValidity();
  }

  function isUrl(value) {
    try {
      const parsed = new URL(value);
      return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch {
      return false;
    }
  }

  function ownerFieldsComplete() {
    if (!form) return false;
    const password = fieldValue('admin_password');
    const confirmation = fieldValue('admin_password_confirmation');
    return fieldValue('admin_name') !== ''
      && isEmail(fieldValue('admin_email'))
      && password.length >= 12
      && password === confirmation
      && fieldValue('site_name') !== ''
      && isUrl(fieldValue('base_url'))
      && isEmail(fieldValue('support_email'))
      && /^[A-Za-z]{3}$/.test(fieldValue('currency'))
      && fieldValue('timezone') !== '';
  }

  function validateDatabaseFields() {
    let valid = true;
    ['db_host', 'db_name', 'db_user'].forEach(name => {
      if (!fieldValue(name)) {
        setFieldError(name, 'This field is required.');
        valid = false;
      } else setFieldError(name);
    });
    if (fieldValue('db_host') && !/^[A-Za-z0-9_.:\-\[\]]+$/.test(fieldValue('db_host'))) {
      setFieldError('db_host', 'Use a hostname, IP address, or localhost without a port.');
      valid = false;
    }
    if (fieldValue('db_name') && !/^[A-Za-z0-9_$.-]+$/.test(fieldValue('db_name'))) {
      setFieldError('db_name', 'Use letters, numbers, underscores, dots, dashes, or dollar signs.');
      valid = false;
    }
    const port = Number(fieldValue('db_port'));
    if (!Number.isInteger(port) || port < 1 || port > 65535) {
      setFieldError('db_port', 'Enter a port between 1 and 65535.');
      valid = false;
    } else setFieldError('db_port');
    return valid;
  }

  function validateOwnerSiteFields() {
    let valid = true;
    const required = ['admin_name', 'admin_email', 'admin_password', 'admin_password_confirmation', 'site_name', 'base_url', 'support_email', 'currency', 'timezone'];
    required.forEach(name => {
      if (!fieldValue(name)) {
        setFieldError(name, 'This field is required.');
        valid = false;
      }
    });
    if (fieldValue('admin_email') && !isEmail(fieldValue('admin_email'))) {
      setFieldError('admin_email', 'Enter a valid email address.'); valid = false;
    }
    if (fieldValue('support_email') && !isEmail(fieldValue('support_email'))) {
      setFieldError('support_email', 'Enter a valid email address.'); valid = false;
    }
    if (fieldValue('base_url') && !isUrl(fieldValue('base_url'))) {
      setFieldError('base_url', 'Use a complete URL beginning with http:// or https://.'); valid = false;
    }
    if (fieldValue('admin_password').length < 12) {
      setFieldError('admin_password', 'Use at least 12 characters.'); valid = false;
    }
    if (fieldValue('admin_password') !== fieldValue('admin_password_confirmation')) {
      setFieldError('admin_password_confirmation', 'The passwords do not match.'); valid = false;
    }
    if (!/^[A-Za-z]{3}$/.test(fieldValue('currency'))) {
      setFieldError('currency', 'Use a three-letter code such as USD.'); valid = false;
    }
    return valid;
  }

  function validateMailFields() {
    const mode = fieldValue('mail_mode') || 'disabled';
    if (mode === 'disabled') {
      setFieldError('mail_from_email');
      return true;
    }
    let valid = true;
    if (!isEmail(fieldValue('mail_from_email'))) {
      setFieldError('mail_from_email', 'Enter a valid From email address.');
      valid = false;
    } else setFieldError('mail_from_email');
    if (mode === 'smtp') {
      if (!fieldValue('smtp_host')) {
        setFieldError('smtp_host', 'Enter the SMTP server hostname.'); valid = false;
      } else setFieldError('smtp_host');
      const port = Number(fieldValue('smtp_port'));
      if (!Number.isInteger(port) || port < 1 || port > 65535) {
        setFieldError('smtp_port', 'Enter a port between 1 and 65535.'); valid = false;
      } else setFieldError('smtp_port');
      const username = fieldValue('smtp_username');
      const password = fieldValue('smtp_password');
      if ((username && !password) || (!username && password)) {
        showNotice('Enter both the SMTP username and password, or leave both blank.', 'error');
        valid = false;
      }
    }
    return valid;
  }

  function validateAll() {
    clearFieldErrors();
    let valid = validateDatabaseFields();
    valid = validateOwnerSiteFields() && valid;
    valid = validateMailFields() && valid;
    if (!requirementsReady) {
      showNotice('Required server or file checks are not passing.', 'error');
      $('#readiness')?.setAttribute('open', '');
      return false;
    }
    if (!field('confirm_install')?.checked) {
      showNotice('Confirm that you are ready to install the website.', 'error');
      $('#install-review')?.setAttribute('open', '');
      valid = false;
    }
    if (!valid) {
      const firstInvalid = $('[aria-invalid="true"]', form);
      firstInvalid?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      firstInvalid?.focus({ preventScroll: true });
    }
    return valid;
  }

  function databaseFingerprintValue() {
    return ['db_host', 'db_port', 'db_name', 'db_user'].map(fieldValue).join('|');
  }

  function renderSystemChecks(checks) {
    const container = $('[data-system-checks]');
    if (!container) return;
    container.innerHTML = '';
    const issues = checks.filter(check => check.status !== 'pass');
    if (issues.length === 0) {
      const article = document.createElement('article');
      article.className = 'check-row pass compact-success';
      article.innerHTML = '<span class="check-icon">✓</span><span><b>Required server checks passed</b><small>Optional tools can be configured later.</small></span>';
      container.append(article);
      return;
    }
    issues.forEach(check => {
      const article = document.createElement('article');
      article.className = `check-row ${check.status}`;
      const icon = document.createElement('span');
      icon.className = 'check-icon';
      icon.textContent = check.status === 'warn' ? '!' : '×';
      const copy = document.createElement('span');
      const title = document.createElement('b');
      const detail = document.createElement('small');
      title.textContent = check.label;
      detail.textContent = check.detail;
      copy.append(title, detail);
      const badge = document.createElement('span');
      badge.className = 'check-badge';
      badge.textContent = check.required ? 'Required' : 'Optional';
      article.append(icon, copy, badge);
      container.append(article);
    });
  }

  function renderFileResults(rows) {
    const container = $('[data-file-checks]');
    if (!container) return;
    container.innerHTML = '';
    const issues = rows.filter(row => row.status !== 'pass');
    if (issues.length === 0) {
      const article = document.createElement('article');
      article.className = 'file-check pass compact-success';
      article.innerHTML = '<span class="file-check-icon">✓</span><span><b>Required folders are writable</b><small>Real write, rename, read, and delete tests passed.</small></span><code>OK</code>';
      container.append(article);
      return;
    }
    issues.forEach(row => {
      const article = document.createElement('article');
      article.className = `file-check ${row.status}`;
      const icon = document.createElement('span');
      icon.className = 'file-check-icon';
      icon.textContent = '×';
      const copy = document.createElement('span');
      const title = document.createElement('b');
      const detail = document.createElement('small');
      title.textContent = `/${row.path}`;
      detail.textContent = row.message;
      copy.append(title, detail);
      const mode = document.createElement('code');
      mode.textContent = row.mode;
      article.append(icon, copy, mode);
      container.append(article);
    });
  }

  function syncMailFields() {
    if (!form) return;
    const mode = fieldValue('mail_mode') || 'disabled';
    const smtp = $('[data-smtp-fields]');
    const sender = $('[data-mail-sender-fields]');
    if (smtp) smtp.hidden = mode !== 'smtp';
    if (sender) sender.hidden = mode === 'disabled';
    const testButton = $('[data-test-mail]');
    if (testButton) testButton.textContent = mode === 'disabled' ? 'Confirm Email Is Skipped' : 'Test Email Settings';
    if (mode === 'disabled') {
      mailVerified = true;
      setSectionState('email', 'optional', 'Skipped');
      const result = $('[data-mail-result]');
      if (result) { result.className = 'inline-result'; result.textContent = 'Email setup will be configured later.'; }
    } else {
      mailVerified = false;
      setSectionState('email', 'optional', 'Optional');
    }
    updateReview();
  }

  function updateReview() {
    if (!form) return;
    const values = {
      site: fieldValue('site_name') || 'Nikki Wilder — The Velvet Vixxen',
      url: fieldValue('base_url') || 'Enter website URL',
      owner: fieldValue('admin_name') || 'Enter owner name',
      email: fieldValue('admin_email') || 'Enter an email address',
      database: fieldValue('db_name') || 'Not configured',
      dbHost: `${fieldValue('db_host') || 'localhost'}:${fieldValue('db_port') || '3306'}`,
      mail: ({ disabled: 'Configure later', php: 'PHP mail()', smtp: 'SMTP' })[fieldValue('mail_mode') || 'disabled'] || 'Configure later',
    };
    const map = {
      '[data-review-site]': values.site,
      '[data-review-url]': values.url,
      '[data-review-owner]': values.owner,
      '[data-review-email]': values.email,
      '[data-review-database]': values.database,
      '[data-review-db-host]': values.dbHost,
      '[data-review-mail]': values.mail,
    };
    Object.entries(map).forEach(([selector, value]) => { const node = $(selector); if (node) node.textContent = value; });

    if (ownerFieldsComplete()) setSectionState('owner', 'pass', 'Ready');
    else setSectionState('owner', 'waiting', 'Incomplete');
    updateOverallProgress();
  }

  async function recheckRequirements() {
    const button = $('[data-recheck]');
    setButtonLoading(button, true, 'Running Checks');
    showNotice('Checking the server, organized folders, write access, and permission policy…', 'loading');
    try {
      const payload = await request('recheck');
      requirementsReady = Boolean(payload.ready);
      renderSystemChecks(payload.system_checks || []);
      renderFileResults(payload.file_results || []);
      const serverFailures = (payload.system_checks || []).filter(row => row.required && row.status !== 'pass').length;
      const fileFailures = (payload.file_results || []).filter(row => row.required && row.status !== 'pass').length;
      const serverCount = $('[data-system-failure-count]');
      const fileCount = $('[data-file-failure-count]');
      if (serverCount) serverCount.textContent = String(serverFailures);
      if (fileCount) fileCount.textContent = String(fileFailures);
      const readinessCard = $('.readiness-card');
      const readinessIcon = $('.readiness-icon');
      const readinessTitle = $('[data-readiness-title]');
      const readinessCopy = $('[data-readiness-copy]');
      const technicalSummary = $('[data-technical-summary]');
      if (readinessCard) {
        readinessCard.classList.toggle('ready', requirementsReady);
        readinessCard.classList.toggle('attention', !requirementsReady);
      }
      if (readinessIcon) readinessIcon.textContent = requirementsReady ? '✓' : '!';
      if (readinessTitle) readinessTitle.textContent = requirementsReady ? 'Server and folders are ready' : 'Server setup needs attention';
      if (readinessCopy) readinessCopy.textContent = requirementsReady
        ? 'All required checks passed. Technical details are hidden below.'
        : `${serverFailures} server and ${fileFailures} folder checks must be corrected.`;
      if (technicalSummary) technicalSummary.textContent = requirementsReady ? 'All required checks passed' : 'Open to review failures';
      if (!requirementsReady) $('#readiness')?.setAttribute('open', '');
      setSectionState('readiness', requirementsReady ? 'pass' : 'fail', requirementsReady ? 'Ready' : 'Attention');
      const installButton = $('[data-install-button]');
      if (installButton && !installRunning) installButton.disabled = !requirementsReady;
      showNotice(payload.message, requirementsReady ? 'success' : 'warn');
    } catch (error) {
      requirementsReady = false;
      setSectionState('readiness', 'fail', 'Attention');
      showNotice(error.message, 'error');
    } finally {
      setButtonLoading(button, false);
    }
  }

  async function testDatabase({ silent = false } = {}) {
    clearFieldErrors();
    if (!validateDatabaseFields()) {
      $('#database')?.setAttribute('open', '');
      $('[aria-invalid="true"]')?.focus();
      return false;
    }
    const button = $('[data-test-database]');
    const result = $('[data-database-result]');
    setButtonLoading(button, true, 'Testing Database');
    if (result) { result.className = 'inline-result loading'; result.textContent = 'Testing connection and create/write/drop privileges…'; }
    setSectionState('database', 'working', 'Testing');
    try {
      const payload = await request('test_database');
      databaseVerified = true;
      databaseFingerprint = databaseFingerprintValue();
      if (result) {
        result.className = 'inline-result success';
        result.textContent = `${payload.message} Server ${payload.database?.server_version || 'verified'}.`;
      }
      setSectionState('database', 'pass', 'Verified');
      if (!silent) showNotice(payload.message, 'success');
      return true;
    } catch (error) {
      databaseVerified = false;
      databaseFingerprint = '';
      if (result) { result.className = 'inline-result error'; result.textContent = error.message; }
      setSectionState('database', 'fail', 'Failed');
      showNotice(error.message, 'error');
      return false;
    } finally {
      setButtonLoading(button, false);
    }
  }

  async function testMail() {
    const mode = fieldValue('mail_mode') || 'disabled';
    const result = $('[data-mail-result]');
    if (mode === 'disabled') {
      mailVerified = true;
      setSectionState('email', 'optional', 'Skipped');
      if (result) { result.className = 'inline-result success'; result.textContent = 'Email configuration will be completed later in administration.'; }
      updateOverallProgress();
      return true;
    }
    clearFieldErrors();
    normalizeInstallerEmailFields();
    if (!validateMailFields()) {
      $('#email')?.setAttribute('open', '');
      return false;
    }
    const button = $('[data-test-mail]');
    setButtonLoading(button, true, 'Testing Email');
    if (result) { result.className = 'inline-result loading'; result.textContent = 'Connecting to the selected mail service…'; }
    setSectionState('email', 'working', 'Testing');
    try {
      const payload = await request('test_mail');
      mailVerified = true;
      if (result) { result.className = 'inline-result success'; result.textContent = payload.message; }
      setSectionState('email', 'pass', 'Verified');
      showNotice(payload.message, 'success');
      return true;
    } catch (error) {
      mailVerified = false;
      if (result) { result.className = 'inline-result error'; result.textContent = error.message; }
      setSectionState('email', 'fail', 'Failed');
      showNotice(error.message, 'error');
      return false;
    } finally {
      setButtonLoading(button, false);
    }
  }

  const stageOrder = ['validation', 'filesystem', 'database', 'configuration', 'migrations', 'owner', 'defaults', 'lock', 'verification', 'cleanup'];

  function updateProgress(progress) {
    if (!progress || !progress.stage) return;
    const label = $('[data-progress-label]');
    const detail = $('[data-progress-detail]');
    const percent = $('[data-progress-percent]');
    const bar = $('[data-progress-bar]');
    if (label) label.textContent = progress.label || 'Installing';
    if (detail) detail.textContent = progress.detail || '';
    if (percent) percent.textContent = `${Number(progress.percent || 0)}%`;
    if (bar) bar.style.width = `${Number(progress.percent || 0)}%`;

    const currentIndex = stageOrder.indexOf(progress.stage);
    stageOrder.forEach((stage, index) => {
      const row = $(`[data-progress-stage="${stage}"]`);
      if (!row) return;
      row.classList.remove('active', 'complete', 'failed');
      const icon = $('i', row);
      if (progress.status === 'failed' && stage === progress.stage) {
        row.classList.add('failed');
        if (icon) icon.textContent = '×';
      } else if (currentIndex >= 0 && index < currentIndex) {
        row.classList.add('complete');
        if (icon) icon.textContent = '✓';
      } else if (stage === progress.stage) {
        row.classList.add(progress.status === 'success' ? 'complete' : 'active');
        if (icon) icon.textContent = progress.status === 'success' ? '✓' : '●';
      } else if (icon) icon.textContent = '○';
    });
  }

  async function pollProgress() {
    if (!installRunning) return;
    try {
      const payload = await request('progress', { id: config.progressId || body.dataset.installId }, 'GET');
      updateProgress(payload.progress);
    } catch {
      // The endpoint can disappear at the final cleanup stage. The install response is authoritative.
    }
  }

  function startProgressPolling() {
    stopProgressPolling();
    pollProgress();
    progressTimer = window.setInterval(pollProgress, 650);
  }

  function stopProgressPolling() {
    if (progressTimer) window.clearInterval(progressTimer);
    progressTimer = null;
  }

  async function installWebsite(event) {
    event.preventDefault();
    if (installRunning) return;
    clearNotice();
    normalizeInstallerEmailFields();
    if (!validateAll()) return;

    if (!databaseVerified || databaseFingerprint !== databaseFingerprintValue()) {
      const passed = await testDatabase({ silent: true });
      if (!passed) return;
    }
    const mode = fieldValue('mail_mode') || 'disabled';
    if (mode !== 'disabled' && !mailVerified) {
      const passed = await testMail();
      if (!passed) return;
    }

    installRunning = true;
    body.classList.add('installing');
    setSectionState('install', 'working', 'Installing');
    const button = $('[data-install-button]');
    setButtonLoading(button, true, 'Installing Website');
    form.classList.add('is-locked');
    const consolePanel = $('[data-installation-console]');
    if (consolePanel) {
      consolePanel.hidden = false;
      consolePanel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    updateProgress({ stage: 'validation', label: 'Starting installation', detail: 'Validating your settings before any database changes are made.', percent: 2, status: 'running' });
    startProgressPolling();

    try {
      const payload = await request('install_now');
      stopProgressPolling();
      body.classList.remove('installing');
      if (button) {
        button.classList.remove('is-loading');
        button.textContent = 'Installation complete';
        button.disabled = true;
      }
      updateProgress({ stage: 'cleanup', label: 'Installation complete', detail: payload.message, percent: 100, status: 'success' });
      setSectionState('install', 'pass', 'Complete');
      const success = $('[data-installation-success]');
      if (consolePanel) consolePanel.hidden = true;
      if (success) {
        success.hidden = false;
        const message = $('[data-success-message]', success);
        const owner = $('[data-success-owner]', success);
        const migrations = $('[data-success-migrations]', success);
        const link = $('[data-login-link]', success);
        if (message) message.textContent = payload.message;
        if (owner) owner.textContent = payload.admin_email || fieldValue('admin_email');
        if (migrations) migrations.textContent = String((payload.migrations || []).length);
        if (link) link.href = payload.redirect;
        success.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      const overallBar = $('[data-overall-bar]');
      const overallLabel = $('[data-overall-percent]');
      if (overallBar) overallBar.style.width = '100%';
      if (overallLabel) overallLabel.textContent = '100%';
      showNotice(payload.message, payload.cleanup?.public_removed === false ? 'warn' : 'success');
    } catch (error) {
      stopProgressPolling();
      installRunning = false;
      body.classList.remove('installing');
      setSectionState('install', 'fail', 'Stopped');
      updateProgress({ stage: 'validation', label: 'Installation stopped', detail: error.message, percent: 100, status: 'failed' });
      form.classList.remove('is-locked');
      if (!requirementsReady && button) button.disabled = true;
      setButtonLoading(button, false);
      showNotice(error.message, 'error');
    }
  }

  // Password visibility and strength.
  $$('[data-toggle-password]').forEach(button => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.togglePassword || '');
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
      button.textContent = input.type === 'password' ? 'Show' : 'Hide';
    });
  });

  $$('[data-password]').forEach(input => {
    const meter = $(`[data-strength-for="${input.id}"] span`);
    const label = $(`[data-strength-label="${input.id}"]`);
    const update = () => {
      const value = input.value;
      let score = Math.min(value.length / 20, 1) * 45;
      if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score += 15;
      if (/\d/.test(value)) score += 15;
      if (/[^A-Za-z0-9]/.test(value)) score += 15;
      if (value.length >= 16) score += 10;
      score = Math.min(score, 100);
      if (meter) meter.style.width = `${score}%`;
      if (label) label.textContent = value.length < 12 ? `${value.length}/12 characters` : score >= 75 ? 'Strong passphrase' : 'Accepted — longer is stronger';
      updateReview();
    };
    input.addEventListener('input', update);
    update();
  });

  if (form) {
    form.addEventListener('input', event => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement)) return;
      setFieldError(target.name);
      if (['db_host', 'db_port', 'db_name', 'db_user'].includes(target.name)) {
        if (databaseFingerprint && databaseFingerprint !== databaseFingerprintValue()) {
          databaseVerified = false;
          databaseFingerprint = '';
          setSectionState('database', 'waiting', 'Retest');
          const result = $('[data-database-result]');
          if (result) { result.className = 'inline-result'; result.textContent = 'Database settings changed. Test the connection again.'; }
        }
      }
      if (target.name.startsWith('smtp_') || target.name.startsWith('mail_')) {
        if (target.name !== 'mail_mode') mailVerified = false;
      }
      if (target.name === 'admin_email') {
        const normalized = normalizeEmailValue(target.value);
        if (field('support_email')) field('support_email').value = normalized;
        if (field('mail_from_email')) field('mail_from_email').value = normalized;
      }
      if (target.name === 'admin_name' && field('mail_from_name')) field('mail_from_name').value = target.value;
      updateReview();
    });
    form.addEventListener('change', event => {
      if (event.target?.name === 'mail_mode') syncMailFields();
      updateReview();
    });
    form.addEventListener('blur', event => {
      const target = event.target;
      if (target && ['admin_email', 'support_email', 'mail_from_email'].includes(target.name)) {
        normalizeEmailField(target.name);
        setFieldError(target.name);
        updateReview();
      }
    }, true);
    form.addEventListener('submit', installWebsite);
  }

  $('[data-recheck]')?.addEventListener('click', recheckRequirements);
  $('[data-test-database]')?.addEventListener('click', () => testDatabase());
  $('[data-test-mail]')?.addEventListener('click', testMail);

  // Highlight the currently viewed section in the sticky status rail.
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => {
      const visible = entries.filter(entry => entry.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
      if (!visible) return;
      $$('[data-status-link]').forEach(link => link.classList.remove('active'));
      const name = visible.target.id === 'owner-site' ? 'owner' : visible.target.id === 'install-review' ? 'install' : visible.target.id;
      $(`[data-status-link="${name}"]`)?.classList.add('active');
    }, { rootMargin: '-20% 0px -65% 0px', threshold: [0, .2, .5] });
    $$('.install-section').forEach(section => observer.observe(section));
  }

  // Installed-state cleanup retry remains on the same page.
  const cleanupForm = $('[data-cleanup-form]');
  cleanupForm?.addEventListener('submit', async event => {
    event.preventDefault();
    const button = $('button', cleanupForm);
    setButtonLoading(button, true, 'Cleaning Up');
    try {
      const data = new FormData(cleanupForm);
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: data,
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Cleanup failed.');
      window.location.href = payload.redirect;
    } catch (error) {
      window.alert(error.message);
      setButtonLoading(button, false);
    }
  });

  syncMailFields();
  updateReview();
  setSectionState('readiness', requirementsReady ? 'pass' : 'fail', requirementsReady ? 'Ready' : 'Attention');
  const installButton = $('[data-install-button]');
  if (installButton) installButton.disabled = !requirementsReady;
})();

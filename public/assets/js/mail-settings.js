(() => {
  'use strict';
  const root = document.querySelector('[data-mail-settings]');
  if (!root) return;
  const form = root.querySelector('[data-mail-editor]');
  const mode = form.querySelector('[data-mail-mode]');
  const smtp = form.querySelector('[data-mail-smtp]');
  const save = form.querySelector('[data-mail-save]');
  const status = form.querySelector('[data-mail-unsaved]');
  const testForm = root.querySelector('[data-mail-test-form]');
  const testButton = testForm.querySelector('[data-mail-test]');
  const transportDisabled = testButton.disabled;
  let dirty = false;

  function syncMode() {
    // Preserve saved SMTP details when switching off; hidden fields remain in
    // the submission, while browser validation only requires an active host.
    smtp.querySelectorAll('[data-smtp-required]').forEach(field => { field.required = mode.value === 'smtp'; });
    // Keep an invalid stored/edited field reachable even after a mode change.
    // Otherwise native validation can block Save with no visible field to fix.
    const invalidDetail = [...smtp.querySelectorAll('input')].some(field => !field.validity.valid);
    smtp.hidden = mode.value !== 'smtp' && !invalidDetail;
  }
  function changed() {
    dirty = true;
    status.textContent = 'You have unsaved changes. Save before testing.';
    testButton.disabled = true;
    syncMode();
  }
  form.addEventListener('input', changed);
  form.addEventListener('change', changed);
  form.querySelector('[data-mail-clear-password]').addEventListener('change', event => {
    if (event.target.checked) form.querySelector('[name="password"]').value = '';
  });
  form.querySelector('[name="password"]').addEventListener('input', event => {
    if (event.target.value !== '') form.querySelector('[data-mail-clear-password]').checked = false;
  });
  form.addEventListener('submit', () => {
    save.disabled = true;
    save.textContent = 'Saving…';
  });
  testForm.addEventListener('submit', event => {
    if (dirty || transportDisabled) { event.preventDefault(); return; }
    testButton.disabled = true;
    testButton.textContent = 'Sending test…';
  });
  window.addEventListener('pageshow', () => {
    save.disabled = false;
    save.textContent = 'Save Email Settings';
    testButton.disabled = dirty || transportDisabled;
    testButton.textContent = 'Send Test Email';
  });
  syncMode();
})();

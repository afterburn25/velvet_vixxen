(() => {
  'use strict';
  const navigation = document.querySelector('[data-admin-navigation]');
  const scope = navigation?.dataset.storageKey || 'velvet-admin-navigation';
  let expanded = {};
  try { expanded = JSON.parse(localStorage.getItem(scope) || '{}') || {}; } catch (_) { expanded = {}; }
  if (typeof expanded !== 'object' || Array.isArray(expanded)) expanded = {};
  const groups = [...document.querySelectorAll('[data-admin-group]')];
  groups.forEach(group => {
    const key = group.dataset.adminGroup;
    group.open = typeof expanded[key] === 'boolean' ? expanded[key] : !!group.querySelector('[aria-current="page"]');
  });
  // Native toggle events are queued tasks. Save subsequent open changes before
  // the next task, without recording initialization defaults as owner choices.
  const persistChanges = records => {
    if (!records.length) return;
    records.forEach(({target}) => { expanded[target.dataset.adminGroup] = target.open; });
    try { localStorage.setItem(scope, JSON.stringify(expanded)); } catch (_) { /* Navigation stays usable without storage. */ }
  };
  const observer = new MutationObserver(persistChanges);
  groups.forEach(group => observer.observe(group, {attributes: true, attributeFilter: ['open']}));
  window.addEventListener('pagehide', () => persistChanges(observer.takeRecords()));
  if (navigation) {
    const active = navigation.querySelector('[aria-current="page"]');
    active?.scrollIntoView({block:'nearest'});
  }
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
      document.body.classList.remove('sidebar-open');
      document.querySelector('.sidebar-toggle')?.setAttribute('aria-expanded', 'false');
      document.querySelector('.sidebar-toggle')?.focus();
    }
  });
  const editor = document.querySelector('[data-about-editor]');
  if (!editor) return;
  const rows = editor.querySelector('[data-about-rows]');
  const status = editor.querySelector('[data-about-status]');
  function numberRows() {
    rows.querySelectorAll('[data-about-row]').forEach((row,index) => {
      const inputs = row.querySelectorAll('input');
      inputs[0].name = 'statistics[' + index + '][label]';
      inputs[1].name = 'statistics[' + index + '][value]';
    });
  }
  editor.addEventListener('click', event => {
    const row = event.target.closest('[data-about-row]');
    if (event.target.closest('[data-about-add]')) {
      if (rows.children.length >= 40) { status.textContent = 'Maximum of 40 statistics reached.'; return; }
      const next = document.querySelector('#aboutRowTemplate').content.cloneNode(true);
      rows.append(next); numberRows(); rows.lastElementChild.querySelector('input').focus();
      status.textContent = 'Statistic added.';
    } else if (row && event.target.closest('[data-row-remove]')) {
      const focus = row.nextElementSibling?.querySelector('input') || row.previousElementSibling?.querySelector('input') || editor.querySelector('[data-about-add]');
      row.remove(); numberRows(); focus.focus(); status.textContent = 'Statistic removed.';
    } else if (row && event.target.closest('[data-row-up]') && row.previousElementSibling) {
      rows.insertBefore(row, row.previousElementSibling); numberRows(); event.target.focus(); status.textContent = 'Statistic moved up.';
    } else if (row && event.target.closest('[data-row-down]') && row.nextElementSibling) {
      rows.insertBefore(row.nextElementSibling, row); numberRows(); event.target.focus(); status.textContent = 'Statistic moved down.';
    }
  });
})();

(() => {
  'use strict';
  const body = document.body;
  const sidebar = document.getElementById('adminSidebar');
  const menuToggle = document.querySelector('.sidebar-toggle');
  const mobileMenu = matchMedia('(max-width:900px)');
  function setSidebar(open, returnFocus = false) {
    open = !!open && mobileMenu.matches;
    body.classList.toggle('sidebar-open', open);
    menuToggle?.setAttribute('aria-expanded', String(open));
    if (sidebar) sidebar.inert = mobileMenu.matches && !open;
    if (returnFocus) menuToggle?.focus();
  }
  const sidebarControls = () => [...(sidebar?.querySelectorAll('a[href],button:not([disabled]),summary') || [])]
    .filter(node => node.getClientRects().length && getComputedStyle(node).visibility !== 'hidden');
  setSidebar(body.classList.contains('sidebar-open'));
  menuToggle?.addEventListener('click', () => {
    const open = !body.classList.contains('sidebar-open');
    setSidebar(open);
    if (open) sidebarControls()[0]?.focus();
  });
  document.addEventListener('click', event => {
    if (body.classList.contains('sidebar-open') && !event.target.closest('.admin-sidebar') && !event.target.closest('.sidebar-toggle')) setSidebar(false);
  });
  document.addEventListener('keydown', event => {
    if (!mobileMenu.matches || !body.classList.contains('sidebar-open')) return;
    if (event.key === 'Escape') { event.preventDefault(); setSidebar(false, true); return; }
    if (event.key !== 'Tab') return;
    const controls = [menuToggle, ...sidebarControls()].filter(Boolean);
    const current = controls.indexOf(document.activeElement);
    if ((event.shiftKey && current <= 0) || (!event.shiftKey && (current === controls.length - 1 || current === -1))) {
      event.preventDefault(); controls[event.shiftKey ? controls.length - 1 : 0]?.focus();
    }
  });
  mobileMenu.addEventListener('change', () => setSidebar(false));
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  async function uploadForm(form, messageNode) {
    const button = form.querySelector('button[type="submit"],button:not([type])');
    const original = button?.textContent;
    if (button) { button.disabled = true; button.textContent = 'Uploading…'; }
    try {
      const response = await fetch(form.action, { method:'POST', body:new FormData(form), headers:{'X-CSRF-Token':csrf} });
      const result = await window.VELVET_API.read(response, 'Upload failed.');
      if (messageNode) { messageNode.className='admin-alert success'; messageNode.textContent='Image uploaded. Refreshing…'; }
      setTimeout(() => location.reload(), 600);
    } catch (error) {
      if (messageNode) { messageNode.className='admin-alert error'; messageNode.textContent=error.message; }
      else alert(error.message);
    } finally {
      if (button) { button.disabled = false; button.textContent = original; }
    }
  }

  const libraryUpload = document.getElementById('libraryUpload');
  libraryUpload?.addEventListener('submit', (event) => { event.preventDefault(); uploadForm(libraryUpload, libraryUpload.querySelector('[data-upload-message]')); });
})();

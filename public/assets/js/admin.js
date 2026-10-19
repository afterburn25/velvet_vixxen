(() => {
  'use strict';
  const body = document.body;
  document.querySelector('.sidebar-toggle')?.addEventListener('click', (event) => { const open = body.classList.toggle('sidebar-open'); event.currentTarget.setAttribute('aria-expanded', String(open)); });
  document.addEventListener('click', (event) => {
    if (body.classList.contains('sidebar-open') && !event.target.closest('.admin-sidebar') && !event.target.closest('.sidebar-toggle')) { body.classList.remove('sidebar-open'); document.querySelector('.sidebar-toggle')?.setAttribute('aria-expanded','false'); }
  });
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

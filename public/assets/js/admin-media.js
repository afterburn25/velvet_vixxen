(() => {
  'use strict';
  const tabRoot = document.querySelector('[data-admin-tabs]');
  if (tabRoot) {
    const buttons = [...tabRoot.querySelectorAll('[data-tab-target]')];
    const panels = [...document.querySelectorAll('[data-tab-panel]')];
    const activate = name => {
      buttons.forEach(button => button.classList.toggle('active', button.dataset.tabTarget === name));
      panels.forEach(panel => panel.classList.toggle('active', panel.dataset.tabPanel === name));
      history.replaceState(null, '', `${location.pathname}${location.search}#${name}`);
    };
    buttons.forEach(button => button.addEventListener('click', () => activate(button.dataset.tabTarget)));
    const requested = location.hash.slice(1);
    if (requested && buttons.some(button => button.dataset.tabTarget === requested)) activate(requested);
    else if (location.search.includes('edit_album')) activate('albums');
    else if (location.search.includes('edit_playlist')) activate('playlists');
  }

  const sortRoot = document.querySelector('[data-gallery-sort]');
  const orderInput = document.querySelector('[data-gallery-order]');
  if (sortRoot && orderInput) {
    let dragged = null;
    const sync = () => {
      orderInput.value = JSON.stringify([...sortRoot.querySelectorAll('[data-gallery-asset]')].map(item => item.dataset.galleryAsset));
    };
    sortRoot.querySelectorAll('[data-gallery-asset]').forEach(item => {
      item.addEventListener('dragstart', () => { dragged = item; item.classList.add('dragging'); });
      item.addEventListener('dragend', () => { item.classList.remove('dragging'); dragged = null; sync(); });
      item.addEventListener('dragover', event => {
        event.preventDefault();
        if (!dragged || dragged === item) return;
        const rect = item.getBoundingClientRect();
        sortRoot.insertBefore(dragged, event.clientX < rect.left + rect.width / 2 ? item : item.nextSibling);
      });
      item.querySelector('[data-remove-gallery-asset]')?.addEventListener('click', () => { item.remove(); sync(); });
    });
    sync();
  }
})();

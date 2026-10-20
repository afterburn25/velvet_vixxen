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
    const form = sortRoot.closest('form');
    const previewInput = form.querySelector('[name="preview_count"]');
    const uploadInput = form.querySelector('[name="photos[]"]');
    const undoButton = form.querySelector('[data-undo-gallery-removal]');
    const status = form.querySelector('[data-gallery-removal-status]');
    const removed = [];
    let dragged = null;
    const items = () => [...sortRoot.querySelectorAll('[data-gallery-asset]')];
    const sync = () => {
      const current = items();
      const previews = Math.max(0, Number.parseInt(previewInput.value, 10) || 0);
      orderInput.value = JSON.stringify(current.map(item => item.dataset.galleryAsset));
      current.forEach((item, index) => {
        item.querySelector('[data-gallery-position]').textContent = 'Photo ' + (index + 1);
        item.querySelector('[data-gallery-preview]').hidden = index >= previews;
        item.querySelector('[data-move-gallery-asset="earlier"]').disabled = index === 0;
        item.querySelector('[data-move-gallery-asset="later"]').disabled = index === current.length - 1;
      });
      const empty = form.querySelector('[data-gallery-empty]');
      if (empty) empty.hidden = current.length !== 0;
      if (undoButton) undoButton.hidden = removed.length === 0;
    };
    const announceOrder = item => {
      if (status) status.textContent = 'Photo moved to position ' + (items().indexOf(item) + 1) + '. Save Gallery to keep this order.';
    };
    items().forEach(item => {
      item.addEventListener('pointerdown', event => {
        item.draggable = !event.target.closest('input, textarea, button, select, label');
      });
      item.addEventListener('dragstart', event => {
        if (event.target.closest('input, textarea, button, select')) { event.preventDefault(); return; }
        dragged = item;
        item.classList.add('dragging');
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', item.dataset.galleryAsset);
        }
      });
      item.addEventListener('dragend', () => {
        item.classList.remove('dragging');
        dragged = null;
        sync();
        announceOrder(item);
      });
      item.addEventListener('dragover', event => {
        event.preventDefault();
        if (!dragged || dragged === item) return;
        const rect = item.getBoundingClientRect();
        sortRoot.insertBefore(dragged, event.clientY < rect.top + rect.height / 2 ? item : item.nextSibling);
      });
      item.querySelectorAll('[data-move-gallery-asset]').forEach(button => {
        button.addEventListener('click', () => {
          const current = items();
          const index = current.indexOf(item);
          const earlier = button.dataset.moveGalleryAsset === 'earlier';
          const target = current[index + (earlier ? -1 : 1)];
          if (!target) return;
          sortRoot.insertBefore(item, earlier ? target : target.nextSibling);
          sync();
          // The selected direction may now be disabled at a boundary.
          const focusTarget = button.disabled ? item.querySelector('[data-move-gallery-asset]:not(:disabled)') : button;
          focusTarget?.focus();
          announceOrder(item);
        });
      });
      item.querySelector('[data-remove-gallery-asset]')?.addEventListener('click', () => {
        removed.push({item, index: items().indexOf(item)});
        item.remove();
        sync();
        if (status) status.textContent = 'Photo removed from this gallery. Save to keep the change, or undo the removal.';
        undoButton?.focus();
      });
    });
    undoButton?.addEventListener('click', () => {
      const previous = removed.pop();
      if (!previous) return;
      sortRoot.insertBefore(previous.item, items()[previous.index] || null);
      sync();
      previous.item.querySelector('[data-remove-gallery-asset]')?.focus();
      if (status) status.textContent = 'Photo restored with its caption and tags.';
    });
    previewInput.addEventListener('input', sync);
    uploadInput?.addEventListener('change', sync);
    form.addEventListener('submit', sync);
    sync();
  }

  const playlistRoot = document.querySelector('[data-playlist-sort]');
  if (playlistRoot) {
    const selected = () => [...playlistRoot.querySelectorAll('[data-playlist-track]')].filter(item => item.querySelector('input[type="checkbox"]').checked);
    const sync = () => {
      const current = selected();
      playlistRoot.querySelectorAll('[data-playlist-track]').forEach(item => {
        const index = current.indexOf(item);
        item.classList.toggle('is-selected', index >= 0);
        item.querySelector('[data-move-playlist-track="earlier"]').disabled = index <= 0;
        item.querySelector('[data-move-playlist-track="later"]').disabled = index < 0 || index === current.length - 1;
      });
    };
    playlistRoot.querySelectorAll('[data-playlist-track]').forEach(item => {
      item.querySelector('input[type="checkbox"]').addEventListener('change', sync);
      item.querySelectorAll('[data-move-playlist-track]').forEach(button => button.addEventListener('click', () => {
        const current = selected();
        const index = current.indexOf(item);
        const earlier = button.dataset.movePlaylistTrack === 'earlier';
        const target = current[index + (earlier ? -1 : 1)];
        if (!target) return;
        playlistRoot.insertBefore(item, earlier ? target : target.nextSibling);
        sync();
        const focusTarget = button.disabled ? item.querySelector('[data-move-playlist-track]:not(:disabled)') : button;
        focusTarget?.focus();
        const status = playlistRoot.parentElement.querySelector('[data-playlist-status]');
        if (status) status.textContent = 'Track moved to position ' + (selected().indexOf(item) + 1) + '.';
      }));
    });
    sync();
  }

  document.querySelectorAll('[data-media-schedule]').forEach(form => {
    const status = form.querySelector('[name="status"]');
    const scheduled = form.querySelector('[name="scheduled_at"]');
    if (!status || !scheduled) return;
    const sync = () => { scheduled.required = status.value === 'scheduled'; };
    status.addEventListener('change', sync);
    sync();
  });
})();

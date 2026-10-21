(() => {
  'use strict';

  const root = document.getElementById('visualComposer');
  const seed = window.COMPOSER_DATA;
  if (!root || !seed) return;
  const geometry = window.VELVET_SCENE_GEOMETRY;

  const clone = (value) => typeof structuredClone === 'function'
    ? structuredClone(value)
    : JSON.parse(JSON.stringify(value));
  const clamp = (value, min, max) => Math.min(max, Math.max(min, Number(value)));
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const baseUrl = String(seed.baseUrl || '').replace(/\/$/, '');
  const sceneUrl = String(seed.sceneUrl || `${baseUrl}/route.php?vvx_route=api%2Fscene.php`);
  const uploadUrl = String(seed.uploadUrl || `${baseUrl}/route.php?vvx_route=api%2Fupload.php`);
  const sceneEndpoint = (targetSection) => {
    const url = new URL(sceneUrl, location.href);
    url.searchParams.set('section', targetSection);
    return url.href;
  };
  const uploadBase = String(seed.uploadBase || `${baseUrl}/uploads/`).replace(/\/?$/, '/');
  const assetUrls = {...(seed.assetUrls || {})};
  const brokenAssets = new Set();
  const maxContentButtons = Number(seed.maxContentButtons || 8);

  let scenes = clone(seed.scenes || {});
  let media = Array.isArray(seed.media) ? clone(seed.media) : Object.values(clone(seed.media || {}));
  let versions = clone(seed.versions || {});
  const library = seed.library || {scenes: [], categories: [], warnings: [], unavailable: []};
  let assetsTab = library.scenes?.length ? 'library' : 'media';
  let saving = false;
  let section = 'hero';
  let device = 'desktop';
  let selectedId = null;
  let selectedTarget = 'none';
  let backgroundPanMode = false;
  let history = [];
  let historyIndex = -1;

  const el = {
    canvas: root.querySelector('[data-canvas]'),
    bg: root.querySelector('[data-canvas-bg]'),
    overlay: root.querySelector('[data-canvas-overlay]'),
    layers: root.querySelector('[data-canvas-layers]'),
    contentBox: root.querySelector('[data-content-box]'),
    contentButtons: root.querySelector('[data-content-buttons]'),
    contentButtonPreviews: root.querySelector('[data-content-button-previews]'),
    empty: root.querySelector('[data-canvas-empty]'),
    mediaList: root.querySelector('[data-media-list]'),
    mediaSearch: root.querySelector('[data-media-search]'),
    mediaFilter: root.querySelector('[data-media-filter]'),
    status: root.querySelector('[data-status]'),
    versions: root.querySelector('[data-versions-list]'),
    label: root.querySelector('[data-canvas-label]'),
    noSelection: root.querySelector('[data-no-selection]'),
    layerControls: root.querySelector('[data-layer-controls]'),
    selectedPreview: root.querySelector('[data-selected-preview]'),
    selectedName: root.querySelector('[data-selected-name]'),
    selectedSize: root.querySelector('[data-selected-size]'),
    panButton: root.querySelector('[data-action="pan-background"]'),
    selection: root.querySelector('[data-selection-overlay]'),
    layerStack: root.querySelector('[data-layer-stack]'),
    snapGuides: root.querySelector('[data-snap-guides]'),
    placementGuides: root.querySelector('[data-placement-guides]'),
    libraryList: root.querySelector('[data-library-list]'),
    librarySearch: root.querySelector('[data-library-search]'),
    libraryCategory: root.querySelector('[data-library-category]'),
    libraryCount: root.querySelector('[data-library-count]'),
    libraryWarnings: root.querySelector('[data-library-warnings]'),
    backgroundInfo: root.querySelector('[data-background-info]')
  };

  function normalizePath(path) {
    path = String(path || '').trim().replace(/\\/g, '/');
    while (path.startsWith('../')) path = path.slice(3);
    return path.replace(/^\/+/, '');
  }

  function resolveAsset(path, explicitUrl = '') {
    if (explicitUrl) return String(explicitUrl);
    if (!path) return '';
    if (/^(?:https?:)?\/\//i.test(path) || String(path).startsWith('data:') || String(path).startsWith('blob:')) {
      return String(path);
    }

    const normalized = normalizePath(path);
    if (assetUrls[path]) return String(assetUrls[path]);
    if (assetUrls[normalized]) return String(assetUrls[normalized]);
    if (normalized.startsWith('uploads/')) return `${baseUrl}/${normalized}`;
    if (/^images\/(?:defaults|hero|backgrounds|branding|gallery-covers|icons|placeholders)\//.test(normalized)) {
      return `${baseUrl}/${normalized}`;
    }
    if (/^(?:media|models|thumbnails|exports|audio|videos|captions)\//.test(normalized)) {
      return uploadBase + normalized;
    }
    if (normalized.startsWith('images/')) return uploadBase + normalized;
    return `${baseUrl}/${normalized}`;
  }

  function placeholderData(label = 'Image unavailable') {
    const safe = String(label).replace(/[<>&'"]/g, '').slice(0, 42);
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="640" height="480" viewBox="0 0 640 480"><defs><linearGradient id="g" x1="0" x2="1"><stop stop-color="#2c0a20"/><stop offset="1" stop-color="#0b060a"/></linearGradient></defs><rect width="640" height="480" fill="url(#g)"/><path d="M0 380l145-130 90 78 95-106 170 158z" fill="#ff2d8d" opacity=".18"/><circle cx="466" cy="145" r="48" fill="#ff78b8" opacity=".22"/><text x="320" y="232" text-anchor="middle" fill="#ffc2df" font-family="Arial,sans-serif" font-size="23">${safe}</text><text x="320" y="267" text-anchor="middle" fill="#aa7890" font-family="Arial,sans-serif" font-size="15">Check the file in Media Library</text></svg>`;
    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
  }

  function setImageSource(img, url, label, onBroken = null) {
    const requested = String(url || '');
    img.classList.remove('is-broken');
    img.dataset.requestedSrc = requested;
    img.onerror = () => {
      if (img.dataset.fallbackApplied === '1') return;
      img.dataset.fallbackApplied = '1';
      img.classList.add('is-broken');
      img.src = placeholderData(label);
      if (requested && !brokenAssets.has(requested)) {
        brokenAssets.add(requested);
        setStatus(`An image file could not be found: ${label}. Re-upload or replace it in Media Library.`, 'error');
      }
      if (typeof onBroken === 'function') onBroken();
    };
    img.onload = () => {
      if (img.dataset.fallbackApplied !== '1') img.classList.remove('is-broken');
    };
    img.dataset.fallbackApplied = '0';
    img.src = requested || placeholderData(label);
  }

  function defaultContentBox(key, targetDevice) {
    if (key === 'footer') {
      return clone({
        desktop: {x: 5, y: 18, width: 46, height: 64},
        tablet: {x: 6, y: 20, width: 58, height: 66},
        mobile: {x: 8, y: 43, width: 84, height: 47}
      }[targetDevice]);
    }
    return clone({
      desktop: {x: 6, y: 17, width: 36, height: 63},
      tablet: {x: 7, y: 18, width: 46, height: 64},
      mobile: {x: 7, y: 52, width: 86, height: 42}
    }[targetDevice]);
  }

  function normalizeBackground(source, fallback = {}, key = section) {
    const bg = {...fallback, ...(source && typeof source === 'object' ? source : {})};
    const number = (value, defaultValue, min, max) => Number.isFinite(Number(value)) ? clamp(value, min, max) : defaultValue;
    return {
      ...bg,
      src: typeof bg.src === 'string' && bg.src ? bg.src : `images/defaults/${key === 'hero' ? 'hero-pink-room.jpg' : 'footer-pink-room.jpg'}`,
      fit: bg.fit === 'contain' ? 'contain' : 'cover',
      x: number(bg.x ?? 50, 50, 0, 100),
      y: number(bg.y ?? 50, 50, 0, 100),
      zoom: number(bg.zoom ?? 1, 1, 0.25, 4),
      brightness: number(bg.brightness ?? 100, 100, 25, 175),
      contrast: number(bg.contrast ?? 100, 100, 50, 175),
      saturation: number(bg.saturation ?? 100, 100, 0, 200),
      blur: number(bg.blur ?? 0, 0, 0, 20),
      overlay: number(bg.overlay ?? (key === 'hero' ? 24 : 48), 25, 0, 90),
      overlayColor: /^#[0-9a-f]{6}$/i.test(bg.overlayColor || '') ? bg.overlayColor : '#080007'
    };
  }

  function normalizeScene(key) {
    if (!scenes[key] || typeof scenes[key] !== 'object') scenes[key] = {};
    const scene = scenes[key];
    scene.section = key;
    scene.title = String(scene.title || (key === 'hero' ? 'Homepage Hero' : 'Visual Footer')).slice(0, 160);
    // Legacy compositions keep their global background as the migration fallback.
    scene.background = normalizeBackground(scene.background, {}, key);
    scene.devices ||= {};
    ['desktop', 'tablet', 'mobile'].forEach((targetDevice) => {
      scene.devices[targetDevice] ||= {};
      const view = scene.devices[targetDevice];
      view.height = geometry.frame(key, targetDevice, view).height;
      view.background = normalizeBackground(view.background, scene.background, key);
      view.layers = (Array.isArray(view.layers) ? view.layers : []).filter((layer) => layer && typeof layer === 'object');
      view.layers.forEach((layer) => {
        layer.x = clamp(Number.isFinite(Number(layer.x)) ? layer.x : 50, -400, 500);
        layer.y = clamp(Number.isFinite(Number(layer.y)) ? layer.y : 50, -400, 500);
        layer.width = clamp(Number.isFinite(Number(layer.width)) ? layer.width : 30, 0.5, 400);
        layer.locked = layer.locked === true;
        if (layer.type === 'button') {
          layer.label = typeof layer.label === 'string' ? layer.label.slice(0, 120) : 'Button';
          layer.url = typeof layer.url === 'string' ? layer.url : 'booking/';
          layer.name = String(layer.name || layer.label || 'Button');
          layer.height = clamp(Number.isFinite(Number(layer.height)) ? layer.height : 8, 0.5, 400);
          layer.fontSize = clamp(Number.isFinite(Number(layer.fontSize)) ? layer.fontSize : 18, 8, 96);
          layer.radius = clamp(Number.isFinite(Number(layer.radius)) ? layer.radius : 10, 0, 100);
          layer.style = ['solid', 'outline', 'pill'].includes(layer.style) ? layer.style : 'solid';
          ['color', 'textColor', 'borderColor'].forEach((key) => {
            if (!/^#[0-9a-f]{6}$/i.test(layer[key] || '')) layer[key] = key === 'textColor' ? '#ffffff' : '#ff2d8d';
          });
          layer.newTab = layer.newTab === true;
        }
      });
      view.contentBox = {...defaultContentBox(key, targetDevice), ...(view.contentBox || {})};
      const box = view.contentBox;
      box.width = clamp(box.width, 5, 100);
      box.height = clamp(box.height, 5, 100);
      box.x = clamp(box.x, 0, 100 - box.width);
      box.y = clamp(box.y, 0, 100 - box.height);
    });
  }

  normalizeScene('hero');
  normalizeScene('footer');

  const currentScene = () => scenes[section];
  const currentView = () => currentScene().devices[device];
  const currentBackground = () => currentView().background;
  const selectedLayer = () => currentView().layers.find((layer) => layer.id === selectedId) || null;
  const currentContentButtons = () => Array.isArray(currentScene().contentButtons)
    ? currentScene().contentButtons : (seed.contentButtonDefaults?.[section] || []);

  function editableContentButtons() {
    if (!Array.isArray(currentScene().contentButtons)) currentScene().contentButtons = clone(currentContentButtons());
    return currentScene().contentButtons;
  }

  function contentButtonLabel(button) {
    if (String(button.label || '').trim()) return button.label;
    if (button.action === 'account') return section === 'footer' ? 'Join My World' : 'Join Now';
    if (button.action === 'trailer') return 'Watch Trailer';
    return 'Button';
  }

  function renderContentButtonPreviews() {
    el.contentButtonPreviews.replaceChildren();
    currentContentButtons().forEach((button) => {
      const preview = document.createElement('em');
      preview.dataset.contentButtonPreview = button.id;
      preview.className = `content-button-preview--${button.style}`;
      preview.textContent = contentButtonLabel(button);
      el.contentButtonPreviews.appendChild(preview);
    });
  }

  function renderContentButtons() {
    const buttons = currentContentButtons();
    el.contentButtons.replaceChildren();
    buttons.forEach((button, index) => {
      const card = document.createElement('section');
      card.className = 'content-button-card';
      card.dataset.contentButtonId = button.id;
      card.dataset.contentButtonIndex = String(index);
      card.innerHTML = `<div class="content-button-heading"><h4>Button ${index + 1}</h4><div>
        <button type="button" class="mini-button" data-content-button-action="up" aria-label="Move Button ${index + 1} earlier" ${index === 0 ? 'disabled' : ''}>↑</button>
        <button type="button" class="mini-button" data-content-button-action="down" aria-label="Move Button ${index + 1} later" ${index === buttons.length - 1 ? 'disabled' : ''}>↓</button>
        <button type="button" class="mini-button danger" data-content-button-action="remove" aria-label="Remove Button ${index + 1}">Remove</button>
        </div></div>
        <label>Button action<select data-content-button-field="action"><option value="link">Website link</option><option value="account">Join / My Account</option><option value="trailer">Trailer / Videos</option></select></label>
        <label>Button label<input type="text" maxlength="120" data-content-button-field="label"></label>
        <label>Destination<input type="text" maxlength="2048" data-content-button-field="url" placeholder="booking/ or https://example.com"></label>
        <p class="property-help" data-content-button-help></p>
        <label>Button style<select data-content-button-field="style"><option value="primary">Pink</option><option value="glass">Glass</option><option value="ghost">Subtle</option></select></label>
        <label class="composer-check"><input type="checkbox" data-content-button-field="newTab"> Open in a new tab</label>`;
      ['action', 'label', 'url', 'style'].forEach((field) => {
        card.querySelector(`[data-content-button-field="${field}"]`).value = button[field] || '';
      });
      const label = card.querySelector('[data-content-button-field="label"]');
      label.placeholder = button.action === 'link' ? 'Book with me' : `Automatic: ${contentButtonLabel({...button, label: ''})}`;
      label.required = button.action === 'link';
      const url = card.querySelector('[data-content-button-field="url"]');
      url.disabled = button.action !== 'link';
      url.required = button.action === 'link';
      const newTab = card.querySelector('[data-content-button-field="newTab"]');
      newTab.checked = button.newTab === true;
      newTab.disabled = button.action === 'trailer';
      card.querySelector('[data-content-button-help]').textContent = button.action === 'account'
        ? 'Opens registration for guests and the account for signed-in members. Leave the label blank for automatic wording.'
        : (button.action === 'trailer'
          ? 'Plays the featured trailer when available, or opens Videos. Leave the label blank for automatic wording.'
          : 'Page paths such as booking/ stay within this website. Complete http:// or https:// links open the address you enter.');
      el.contentButtons.appendChild(card);
    });
    const add = root.querySelector('[data-action="add-content-button"]');
    add.disabled = buttons.length >= maxContentButtons;
    root.querySelector('[data-content-button-count]').textContent = `${buttons.length} of ${maxContentButtons} buttons in this group.`;
    renderContentButtonPreviews();
  }

  function addContentButton() {
    if (currentContentButtons().length >= maxContentButtons) return;
    const buttons = editableContentButtons();
    const button = {id: `content-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`,
      action: 'link', label: `Button ${buttons.length + 1}`, url: 'booking/', style: 'primary', newTab: false};
    buttons.push(button);
    selectedId = null;
    selectedTarget = 'content';
    commitHistory();
    activatePropertyTab('content');
    render();
    el.contentButtons.lastElementChild?.querySelector('[data-content-button-field="label"]')?.focus();
    setStatus(`${button.label} added to the live text group on every device. Edit its label and destination in Text + Buttons.`);
  }

  function setStatus(message, mode = 'ready') {
    el.status.classList.toggle('busy', mode === 'busy');
    el.status.classList.toggle('error', mode === 'error');
    const label = el.status.querySelector('span:last-child');
    if (label) label.textContent = message;
    if (mode === 'error') el.status.scrollIntoView({block: 'nearest', behavior: 'auto'});
  }

  function snapshot() {
    return JSON.stringify(scenes);
  }

  function resetHistory() {
    history = [snapshot()];
    historyIndex = 0;
  }

  function commitHistory() {
    const state = snapshot();
    if (history[historyIndex] === state) return;
    history = history.slice(0, historyIndex + 1);
    history.push(state);
    if (history.length > 60) history.shift();
    historyIndex = history.length - 1;
  }

  function restoreSnapshot(serialized) {
    scenes = JSON.parse(serialized);
    normalizeScene('hero');
    normalizeScene('footer');
    if (!selectedLayer()) selectedId = null;
    render();
  }

  resetHistory();

  function canvasScale() {
    return geometry.scale(device, el.canvas.getBoundingClientRect().width);
  }

  function activatePropertyTab(name) {
    root.querySelectorAll('[data-property-tab]').forEach((button) => {
      button.classList.toggle('active', button.dataset.propertyTab === name);
    });
    root.querySelectorAll('[data-property-page]').forEach((page) => {
      page.classList.toggle('active', page.dataset.propertyPage === name);
    });
  }

  function applyBackgroundStyles() {
    const bg = currentBackground();
    el.bg.style.objectFit = bg.fit || 'cover';
    el.bg.style.objectPosition = `${bg.x}% ${bg.y}%`;
    el.bg.style.transform = `scale(${bg.zoom})`;
    el.bg.style.transformOrigin = `${bg.x}% ${bg.y}%`;
    el.bg.style.filter = `brightness(${bg.brightness}%) contrast(${bg.contrast}%) saturate(${bg.saturation}%) blur(${bg.blur * canvasScale()}px)`;
    el.overlay.style.background = bg.overlayColor;
    el.overlay.style.opacity = String(bg.overlay / 100);
    renderPlacementGuides();
  }

  function applyContentBoxStyles(node = el.contentBox) {
    const box = currentView().contentBox;
    node.style.left = `${box.x}%`;
    node.style.top = `${box.y}%`;
    node.style.width = `${box.width}%`;
    node.style.height = `${box.height}%`;
    node.classList.toggle('selected', selectedTarget === 'content');
  }

  function layerTransform(layer) {
    const sx = layer.flipX ? -1 : 1;
    const sy = layer.flipY ? -1 : 1;
    return `translate(-50%,-50%) rotate(${Number(layer.rotation || 0)}deg) scale(${sx},${sy})`;
  }

  function layerFilter(layer) {
    const scale = canvasScale();
    return `brightness(${Number(layer.brightness ?? 100)}%) contrast(${Number(layer.contrast ?? 100)}%) saturate(${Number(layer.saturation ?? 100)}%) drop-shadow(${Number(layer.shadowX ?? 0) * scale}px ${Number(layer.shadowY ?? 18) * scale}px ${Number(layer.shadowBlur ?? 24) * scale}px rgba(0,0,0,${Number(layer.shadowOpacity ?? 35) / 100}))`;
  }

  function applyLayerNodeStyles(node, layer) {
    node.style.left = `${Number(layer.x ?? 65)}%`;
    node.style.top = `${Number(layer.y ?? 50)}%`;
    node.style.width = `${Number(layer.width ?? 30)}%`;
    node.style.opacity = String(Number(layer.opacity ?? 100) / 100);
    node.style.zIndex = String(Number(layer.z ?? 1));
    node.style.transform = layerTransform(layer);
    node.style.filter = layerFilter(layer);
    if (layer.type === 'button') {
      const scale = canvasScale();
      node.style.height = `${Number(layer.height ?? 8)}%`;
      node.style.backgroundColor = layer.style === 'outline' ? 'transparent' : layer.color;
      node.style.color = layer.textColor;
      node.style.borderColor = layer.borderColor;
      node.style.borderWidth = `${2 * scale}px`;
      node.style.borderRadius = layer.style === 'pill' ? '999px' : `${Number(layer.radius ?? 10) * scale}px`;
      node.style.fontSize = `${Number(layer.fontSize ?? 18) * scale}px`;
    }
  }

  function render() {
    normalizeScene(section);
    const scene = currentScene();
    const view = currentView();
    const frame = geometry.frame(section, device, view);
    el.canvas.style.aspectRatio = `${frame.width} / ${frame.height}`;
    el.canvas.style.maxWidth = `${frame.width}px`;
    setImageSource(el.bg, resolveAsset(view.background.src), `${section} background`);
    applyBackgroundStyles();
    applyContentBoxStyles();
    el.label.textContent = `${section === 'hero' ? 'Homepage Hero' : 'Visual Footer'} · ${device[0].toUpperCase() + device.slice(1)}`;
    renderLayers();
    renderProperties();
    renderVersions();
    renderLibrarySelection();
    root.querySelectorAll('[data-section-tabs] button').forEach((button) => button.classList.toggle('active', button.dataset.section === section));
    root.querySelectorAll('[data-device-tabs] button').forEach((button) => button.classList.toggle('active', button.dataset.device === device));
    el.canvas.classList.toggle('background-pan-mode', backgroundPanMode);
    if (el.panButton) {
      el.panButton.classList.toggle('active', backgroundPanMode);
      el.panButton.setAttribute('aria-pressed', String(backgroundPanMode));
    }
  }

  function renderLayers() {
    el.layers.replaceChildren();
    const view = currentView();
    const visible = view.layers.filter((layer) => layer.visible !== false);
    el.empty.hidden = visible.length > 0;

    [...view.layers]
      .sort((a, b) => Number(a.z ?? 1) - Number(b.z ?? 1))
      .forEach((layer) => {
        if (layer.visible === false) return;
        const node = document.createElement('div');
        node.className = 'composer-layer' + (layer.id === selectedId && selectedTarget === 'layer' ? ' selected' : '');
        node.dataset.layerId = layer.id;
        node.dataset.layerType = layer.type === 'button' ? 'button' : 'image';
        node.classList.toggle('is-button', layer.type === 'button');
        applyLayerNodeStyles(node, layer);

        if (layer.type === 'button') {
          const label = document.createElement('span');
          label.className = 'composer-button-label';
          label.textContent = layer.label || 'Button';
          node.appendChild(label);
        } else {
        const img = document.createElement('img');
        setImageSource(img, resolveAsset(layer.src), layer.name || 'Photo layer', () => node.classList.add('has-broken-image'));
        img.alt = '';
        const feather = Math.min(45, Number(layer.feather || 0));
        if (feather > 0) {
          img.style.maskImage = `radial-gradient(ellipse at center,#000 ${100 - feather}%,transparent 100%)`;
          img.style.webkitMaskImage = img.style.maskImage;
        }
        node.appendChild(img);

        img.addEventListener('load', () => {
          if (layer.id === selectedId) renderSelectionOverlay();
        });
        }

        node.classList.toggle('locked', layer.locked === true);
        node.addEventListener('pointerdown', startLayerPointer);
        node.addEventListener('click', (event) => {
          event.stopPropagation();
          selectedId = layer.id;
          selectedTarget = 'layer';
          activatePropertyTab('layer');
          renderLayers();
          renderProperties();
        });
        el.layers.appendChild(node);
      });
    renderSelectionOverlay();
  }

  function layerNode(layer) {
    return [...el.layers.children].find((node) => node.dataset.layerId === String(layer.id)) || null;
  }

  function renderSelectionOverlay() {
    const layer = selectedLayer();
    if (!layer || selectedTarget !== 'layer' || layer.locked || layer.visible === false) {
      el.selection.replaceChildren();
      delete el.selection.dataset.selectionLayerId;
      return;
    }
    const node = layerNode(layer);
    if (!node) return;
    const frame = el.canvas.getBoundingClientRect();
    const bounds = node.getBoundingClientRect();
    // A newly rendered image may still be loading. Keep its existing controls
    // until geometry is available instead of discarding focus between frames.
    if (!frame.width || !frame.height || !bounds.height) return;
    // Image loads, pointer updates and ResizeObserver refresh geometry often.
    // Preserve the actual buttons for the same selection so focus and an
    // in-flight pointer target survive those asynchronous refreshes.
    if (el.selection.dataset.selectionLayerId !== String(layer.id)) {
      el.selection.replaceChildren();
      el.selection.dataset.selectionLayerId = String(layer.id);
      ['nw', 'ne', 'sw', 'se'].forEach((corner) => {
        const handle = document.createElement('button');
        handle.type = 'button';
        handle.className = `resize-handle ${corner}`;
        handle.dataset.handle = corner;
        handle.setAttribute('aria-label', `Resize layer from ${corner} corner`);
        handle.addEventListener('pointerdown', (event) => startLayerPointer(event, selectedLayer()?.id));
        el.selection.appendChild(handle);
      });
      const move = document.createElement('button');
      move.type = 'button';
      move.className = 'selection-move';
      move.dataset.selectionMove = '';
      move.title = 'Drag to move this layer, including when it is outside the frame';
      move.setAttribute('aria-label', 'Move selected layer');
      move.addEventListener('pointerdown', (event) => startLayerPointer(event, selectedLayer()?.id));
      el.selection.appendChild(move);
    }
    // Dock handles to the frame, expanding very small selections to an accessible hit area.
    const left = clamp(bounds.left - frame.left, 15, Math.max(15, frame.width - 45));
    const top = clamp(bounds.top - frame.top, 15, Math.max(15, frame.height - 45));
    const right = clamp(bounds.right - frame.left, left + 30, Math.max(left + 30, frame.width - 15));
    const bottom = clamp(bounds.bottom - frame.top, top + 30, Math.max(top + 30, frame.height - 15));
    [['nw', left, top], ['ne', right, top], ['sw', left, bottom], ['se', right, bottom]].forEach(([corner, x, y]) => {
      const handle = el.selection.querySelector(`[data-handle="${corner}"]`);
      handle.style.left = `${x}px`;
      handle.style.top = `${y}px`;
    });
    const move = el.selection.querySelector('[data-selection-move]');
    move.textContent = layer.name || 'Move layer';
    const halfLabel = Math.min(75, (frame.width - 30) / 2);
    const corners = [[left, top], [right, top], [left, bottom], [right, bottom]];
    const candidates = [[(bounds.left + bounds.right) / 2 - frame.left, bounds.top - frame.top - 32]];
    [0.5, 0.25, 0.75].forEach((y) => [0.5, 0.25, 0.75].forEach((x) => candidates.push([frame.width * x, frame.height * y])));
    const positions = candidates.map(([x, y]) => [clamp(x, halfLabel + 15, Math.max(halfLabel + 15, frame.width - halfLabel - 15)), clamp(y, 28, Math.max(28, frame.height - 28))]);
    const position = positions.find(([x, y]) => corners.every(([cx, cy]) => Math.abs(cx - x) > halfLabel + 14 || Math.abs(cy - y) > 28)) || positions[0];
    move.style.left = `${position[0]}px`;
    move.style.top = `${position[1]}px`;
  }

  function clearSnapGuides() {
    el.snapGuides.replaceChildren();
  }

  function snapAxis(value, offsets, candidates, threshold) {
    let best = {value, guide: null, distance: threshold};
    offsets.forEach((offset) => candidates.forEach((candidate) => {
      const distance = Math.abs(value + offset - candidate);
      if (distance < best.distance) best = {value: candidate - offset, guide: candidate, distance};
    }));
    return best;
  }

  function snapPosition(x, y, width, height, centered, excludeId, temporarilyDisabled) {
    clearSnapGuides();
    if (temporarilyDisabled || !root.querySelector('[data-snap-enabled]').checked) return {x, y};
    const frame = el.canvas.getBoundingClientRect();
    const xTargets = [0, 50, 100];
    const yTargets = [0, 50, 100];
    currentView().layers.forEach((layer) => {
      if (layer.id === excludeId || layer.visible === false) return;
      const node = layerNode(layer);
      if (!node) return;
      const rect = node.getBoundingClientRect();
      xTargets.push((rect.left - frame.left) / frame.width * 100, Number(layer.x), (rect.right - frame.left) / frame.width * 100);
      yTargets.push((rect.top - frame.top) / frame.height * 100, Number(layer.y), (rect.bottom - frame.top) / frame.height * 100);
    });
    const snappedX = snapAxis(x, centered ? [-width / 2, 0, width / 2] : [0, width / 2, width], xTargets, 5 / frame.width * 100);
    const snappedY = snapAxis(y, centered ? [-height / 2, 0, height / 2] : [0, height / 2, height], yTargets, 5 / frame.height * 100);
    [[snappedX.guide, 'vertical'], [snappedY.guide, 'horizontal']].forEach(([position, direction]) => {
      if (position === null || position < 0 || position > 100) return;
      const guide = document.createElement('span');
      guide.className = `snap-guide ${direction}`;
      guide.style[direction === 'vertical' ? 'left' : 'top'] = `${position}%`;
      el.snapGuides.appendChild(guide);
    });
    return {x: snappedX.value, y: snappedY.value};
  }

  function startLayerPointer(event, layerId = null) {
    if (event.button !== 0) return;
    const layer = currentView().layers.find((item) => item.id === (layerId || event.currentTarget.dataset.layerId));
    const node = layer && layerNode(layer);
    if (!node) return;
    selectedId = layer.id;
    selectedTarget = 'layer';
    activatePropertyTab('layer');
    event.preventDefault();
    event.stopPropagation();
    renderProperties();
    if (layer.locked) {
      renderSelectionOverlay();
      setStatus('This layer is locked. Unlock it in Layers to move or resize it.');
      return;
    }
    const frame = el.canvas.getBoundingClientRect();
    const nodeRect = node.getBoundingClientRect();
    const start = {
      pointerX: event.clientX, pointerY: event.clientY,
      layerX: Number(layer.x), layerY: Number(layer.y), width: Number(layer.width), height: Number(layer.height || 8),
      handle: event.target.closest('[data-handle]')?.dataset.handle || '',
      halfWidth: Math.max(1, node.offsetWidth / 2), halfHeight: Math.max(1, node.offsetHeight / 2),
      angle: Number(layer.rotation || 0) * Math.PI / 180
    };
    node.classList.add('selected', 'dragging');
    const onMove = (move) => {
      const dx = move.clientX - start.pointerX;
      const dy = move.clientY - start.pointerY;
      if (start.handle) {
        // Project pointer movement onto the image's original aspect-preserving diagonal.
        const localX = dx * Math.cos(start.angle) + dy * Math.sin(start.angle);
        const localY = -dx * Math.sin(start.angle) + dy * Math.cos(start.angle);
        const sx = start.handle.includes('e') ? 1 : -1;
        const sy = start.handle.includes('s') ? 1 : -1;
        if (layer.type === 'button') {
          layer.width = clamp(start.width + sx * localX / frame.width * 200, 0.5, 400);
          layer.height = clamp(start.height + sy * localY / frame.height * 200, 0.5, 400);
        } else {
          const factor = 1 + (sx * start.halfWidth * localX + sy * start.halfHeight * localY) / (start.halfWidth ** 2 + start.halfHeight ** 2);
          layer.width = clamp(start.width * factor, 0.5, 400);
        }
      } else {
        const next = snapPosition(start.layerX + dx / frame.width * 100, start.layerY + dy / frame.height * 100,
          nodeRect.width / frame.width * 100, nodeRect.height / frame.height * 100, true, layer.id, move.altKey);
        layer.x = clamp(next.x, -400, 500);
        layer.y = clamp(next.y, -400, 500);
      }
      applyLayerNodeStyles(node, layer);
      updateLayerPropertyReadout(layer);
      renderSelectionOverlay();
    };
    const onUp = () => {
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      document.removeEventListener('pointercancel', onUp);
      clearSnapGuides();
      node.classList.remove('dragging');
      commitHistory();
      render();
      setStatus(start.handle ? 'Layer resized — save when ready.' : 'Layer moved — the frame crops any part outside its edges.');
    };
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp, {once: true});
    document.addEventListener('pointercancel', onUp, {once: true});
  }

  function startContentPointer(event) {
    if (event.button !== 0) return;
    event.preventDefault();
    event.stopPropagation();
    selectedTarget = 'content';
    selectedId = null;
    activatePropertyTab('content');
    el.contentBox.classList.add('selected', 'dragging');

    const box = currentView().contentBox;
    const canvasRect = el.canvas.getBoundingClientRect();
    const start = {
      pointerX: event.clientX,
      pointerY: event.clientY,
      x: Number(box.x),
      y: Number(box.y),
      width: Number(box.width),
      height: Number(box.height),
      handle: event.target.dataset.contentHandle || ''
    };

    const onMove = (move) => {
      const dx = (move.clientX - start.pointerX) / canvasRect.width * 100;
      const dy = (move.clientY - start.pointerY) / canvasRect.height * 100;
      if (!start.handle) {
        const next = snapPosition(start.x + dx, start.y + dy, box.width, box.height, false, null, move.altKey);
        box.x = clamp(next.x, 0, Math.max(0, 100 - box.width));
        box.y = clamp(next.y, 0, Math.max(0, 100 - box.height));
      } else {
        let nextX = start.x;
        let nextY = start.y;
        let nextWidth = start.width;
        let nextHeight = start.height;
        if (start.handle.includes('e')) nextWidth = clamp(start.width + dx, 5, 100 - start.x);
        if (start.handle.includes('s')) nextHeight = clamp(start.height + dy, 5, 100 - start.y);
        if (start.handle.includes('w')) {
          nextWidth = clamp(start.width - dx, 5, start.x + start.width);
          nextX = start.x + (start.width - nextWidth);
        }
        if (start.handle.includes('n')) {
          nextHeight = clamp(start.height - dy, 5, start.y + start.height);
          nextY = start.y + (start.height - nextHeight);
        }
        box.x = nextX;
        box.y = nextY;
        box.width = nextWidth;
        box.height = nextHeight;
      }
      applyContentBoxStyles();
      updateContentPropertyReadout();
    };

    const onUp = () => {
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      document.removeEventListener('pointercancel', onUp);
      el.contentBox.classList.remove('dragging');
      clearSnapGuides();
      commitHistory();
      render();
      setStatus(start.handle ? 'Live content box resized.' : 'Live text and buttons moved.');
    };

    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp, {once: true});
    document.addEventListener('pointercancel', onUp, {once: true});
  }

  function startBackgroundPan(event) {
    if (!backgroundPanMode || event.button !== 0) return;
    if (event.target.closest('.composer-layer, [data-content-box], [data-selection-overlay]')) return;
    event.preventDefault();
    const rect = el.canvas.getBoundingClientRect();
    const bg = currentBackground();
    const start = {x: event.clientX, y: event.clientY, bgX: Number(bg.x), bgY: Number(bg.y)};
    el.canvas.classList.add('panning');

    const onMove = (move) => {
      bg.x = clamp(start.bgX + (move.clientX - start.x) / rect.width * 100, 0, 100);
      bg.y = clamp(start.bgY + (move.clientY - start.y) / rect.height * 100, 0, 100);
      applyBackgroundStyles();
      updateBackgroundPropertyReadout();
    };
    const onUp = () => {
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      document.removeEventListener('pointercancel', onUp);
      el.canvas.classList.remove('panning');
      commitHistory();
      render();
      setStatus('Background position changed.');
    };
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp, {once: true});
    document.addEventListener('pointercancel', onUp, {once: true});
  }

  function setControlValue(name, value) {
    root.querySelectorAll(`[data-control="${name}"]`).forEach((input) => {
      input.value = String(value);
    });
  }

  function setOutput(name, value) {
    root.querySelectorAll(`[data-output="${name}"]`).forEach((output) => {
      output.textContent = value;
    });
  }

  function updateBackgroundPropertyReadout() {
    const bg = currentBackground();
    setControlValue('bgX', bg.x);
    setControlValue('bgY', bg.y);
    setOutput('bgX', `${Math.round(bg.x)}%`);
    setOutput('bgY', `${Math.round(bg.y)}%`);
  }

  function updateContentPropertyReadout() {
    const box = currentView().contentBox;
    setControlValue('contentX', round(box.x, 1));
    setControlValue('contentY', round(box.y, 1));
    setControlValue('contentWidth', round(box.width, 1));
    setControlValue('contentHeight', round(box.height, 1));
  }

  function updateLayerPropertyReadout(layer) {
    setControlValue('layerX', round(layer.x, 1));
    setControlValue('layerY', round(layer.y, 1));
    setControlValue('layerWidth', round(layer.width, 1));
    setControlValue('layerHeight', round(layer.height ?? 8, 1));
    setControlValue('layerScale', round(layer.width, 1));
    setOutput('layerScale', `${round(layer.width, 1)}%`);
    if (el.selectedSize) el.selectedSize.textContent = `${round(layer.width, 1)}%${layer.type === 'button' ? ` × ${round(layer.height, 1)}%` : ''} of canvas · z${layer.z ?? 1}${layer.visible === false ? ' · hidden' : ''}${layer.locked ? ' · locked' : ''}`;
  }

  function round(value, digits = 0) {
    const factor = 10 ** digits;
    return Math.round(Number(value) * factor) / factor;
  }

  function renderProperties(refreshInputs = true) {
    const scene = currentScene();
    const view = currentView();
    const bg = view.background;
    const box = view.contentBox;

    if (refreshInputs) {
      const bgMap = {
        bgX: bg.x,
        bgY: bg.y,
        bgZoom: bg.zoom * 100,
        height: view.height,
        bgBrightness: bg.brightness,
        bgContrast: bg.contrast,
        bgSaturation: bg.saturation,
        bgBlur: bg.blur,
        overlay: bg.overlay,
        overlayColor: bg.overlayColor
      };
      Object.entries(bgMap).forEach(([key, value]) => setControlValue(key, value));
      setControlValue('sceneTitle', scene.title);
      updateContentPropertyReadout();
      renderContentButtons();
    }
    renderBackgroundInfo();
    renderLayerStack();

    setOutput('bgX', `${Math.round(bg.x)}%`);
    setOutput('bgY', `${Math.round(bg.y)}%`);
    setOutput('bgZoom', `${Math.round(bg.zoom * 100)}%`);
    setOutput('height', `${Math.round(view.height)}px`);
    setOutput('bgBrightness', `${Math.round(bg.brightness)}%`);
    setOutput('bgContrast', `${Math.round(bg.contrast)}%`);
    setOutput('bgSaturation', `${Math.round(bg.saturation)}%`);
    setOutput('bgBlur', `${Math.round(bg.blur)}px`);
    setOutput('overlay', `${Math.round(bg.overlay)}%`);

    const layer = selectedLayer();
    el.noSelection.hidden = !!layer;
    el.layerControls.hidden = !layer;
    if (!layer) return;
    el.layerControls.querySelectorAll('input, select, [data-layer-size], [data-action]').forEach((control) => {
      const allowed = ['layer-lock', 'layer-hide', 'layer-duplicate'];
      control.disabled = layer.locked && !allowed.includes(control.dataset.action);
    });
    root.querySelector('[data-action="layer-lock"]').textContent = layer.locked ? 'Unlock' : 'Lock';
    root.querySelector('[data-action="layer-hide"]').textContent = layer.visible === false ? 'Show' : 'Hide';
    const isButton = layer.type === 'button';
    root.querySelector('[data-button-controls]').hidden = !isButton;
    root.querySelector('[data-button-icon]').hidden = !isButton;
    el.selectedPreview.hidden = isButton;
    root.querySelectorAll('[data-button-only]').forEach((node) => { node.hidden = !isButton; });
    root.querySelectorAll('[data-photo-only]').forEach((node) => { node.hidden = isButton; });

    if (refreshInputs) {
      const map = {
        layerX: layer.x,
        layerY: layer.y,
        layerWidth: layer.width,
        layerHeight: layer.height ?? 8,
        layerScale: layer.width,
        layerRotation: layer.rotation || 0,
        layerOpacity: layer.opacity ?? 100,
        layerBrightness: layer.brightness ?? 100,
        layerContrast: layer.contrast ?? 100,
        layerSaturation: layer.saturation ?? 100,
        layerFeather: layer.feather ?? 0,
        layerShadow: layer.shadowOpacity ?? 35
      };
      Object.entries(map).forEach(([key, value]) => setControlValue(key, value));
      if (isButton) {
        Object.entries({buttonLabel: layer.label, buttonUrl: layer.url, buttonStyle: layer.style,
          buttonColor: layer.color, buttonTextColor: layer.textColor, buttonBorderColor: layer.borderColor,
          buttonFontSize: layer.fontSize, buttonRadius: layer.radius}).forEach(([key, value]) => setControlValue(key, value));
        root.querySelector('[data-control="buttonNewTab"]').checked = layer.newTab === true;
      }
    }

    if (!isButton) setImageSource(el.selectedPreview, resolveAsset(layer.thumbnail || layer.src), layer.name || 'Photo layer');
    el.selectedName.textContent = layer.name || 'Photo layer';
    updateLayerPropertyReadout(layer);
    setOutput('layerOpacity', `${Math.round(layer.opacity ?? 100)}%`);
    setOutput('layerBrightness', `${Math.round(layer.brightness ?? 100)}%`);
    setOutput('layerContrast', `${Math.round(layer.contrast ?? 100)}%`);
    setOutput('layerSaturation', `${Math.round(layer.saturation ?? 100)}%`);
    setOutput('layerFeather', `${Math.round(layer.feather ?? 0)}%`);
    setOutput('layerShadow', `${Math.round(layer.shadowOpacity ?? 35)}%`);
  }

  function renderLayerStack() {
    const stack = el.layerStack;
    stack.innerHTML = '<h3>Layer stack · photos and buttons</h3>';
    stack.hidden = currentView().layers.length === 0;
    [...currentView().layers]
      .sort((a, b) => Number(b.z ?? 1) - Number(a.z ?? 1))
      .forEach((layer) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.layerSelect = layer.id;
        button.className = layer.id === selectedId ? 'active' : '';
        button.innerHTML = `<span>${layer.visible === false ? '○' : (layer.type === 'button' ? '↗' : '●')}</span><b>${escapeHtml(layer.name || 'Photo layer')}</b><small>${layer.locked ? 'Locked · ' : ''}z${Number(layer.z ?? 1)}</small>`;
        button.addEventListener('click', () => {
          selectedId = layer.id;
          selectedTarget = 'layer';
          activatePropertyTab('layer');
          render();
        });
        stack.appendChild(button);
      });
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, (character) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'}[character]));
  }

  function activateAssetsTab(name) {
    assetsTab = name;
    root.querySelectorAll('[data-assets-tab]').forEach((button) => {
      button.classList.toggle('active', button.dataset.assetsTab === name);
      button.setAttribute('aria-pressed', String(button.dataset.assetsTab === name));
    });
    root.querySelectorAll('[data-assets-pane]').forEach((pane) => { pane.hidden = pane.dataset.assetsPane !== name; });
  }

  function renderLibrary() {
    const query = el.librarySearch.value.toLowerCase().trim();
    const category = el.libraryCategory.value;
    const available = Array.isArray(library.scenes) ? library.scenes : [];
    const rows = available.filter((scene) => {
      const text = [scene.title, scene.categoryTitle, scene.orientation, scene.placement, ...(scene.keywords || [])].join(' ').toLowerCase();
      return (category === 'all' || scene.category === category) && (!query || text.includes(query));
    });
    el.libraryCount.textContent = `${rows.length} of ${available.length} available scenes`;
    el.libraryList.replaceChildren();
    if (!rows.length) {
      el.libraryList.innerHTML = '<p class="media-empty">No matching scenes. Try another category or search.</p>';
    }
    rows.forEach((scene) => {
      const card = document.createElement('button');
      card.type = 'button';
      card.className = 'library-item';
      card.dataset.libraryId = scene.id;
      card.setAttribute('aria-label', `Preview ${scene.title}`);
      const img = document.createElement('img');
      img.alt = '';
      setImageSource(img, resolveAsset(scene.thumbnail, scene.thumbnailUrl), scene.title, () => {
        card.disabled = true;
        card.classList.add('media-broken');
        card.title = 'This thumbnail could not load. Check the bundled background files.';
      });
      const caption = document.createElement('span');
      caption.className = 'library-caption';
      caption.innerHTML = `<strong>${escapeHtml(scene.title)}</strong><small>${escapeHtml(scene.categoryTitle)} · ${escapeHtml(scene.orientation)} · ${Number(scene.width)} × ${Number(scene.height)}</small><span>${escapeHtml(scene.placement)}</span>`;
      card.append(img, caption);
      card.addEventListener('click', () => setBackground(scene, true));
      el.libraryList.appendChild(card);
    });
    const warnings = [...(library.warnings || [])];
    el.libraryWarnings.replaceChildren();
    el.libraryWarnings.hidden = warnings.length === 0;
    warnings.forEach((warning) => {
      const line = document.createElement('p');
      line.textContent = warning;
      el.libraryWarnings.appendChild(line);
    });
    if (library.unavailable?.length) {
      const details = document.createElement('details');
      const summary = document.createElement('summary');
      summary.textContent = 'Unavailable scene details';
      details.appendChild(summary);
      library.unavailable.forEach((scene) => {
        const line = document.createElement('p');
        line.textContent = `${scene.title}: ${scene.reason}`;
        details.appendChild(line);
      });
      el.libraryWarnings.appendChild(details);
    }
    renderLibrarySelection();
  }

  function renderLibrarySelection() {
    const bg = currentBackground();
    el.libraryList.querySelectorAll('[data-library-id]').forEach((card) => {
      const active = card.dataset.libraryId === bg.libraryId;
      card.classList.toggle('active', active);
      card.setAttribute('aria-pressed', String(active));
    });
  }

  function renderBackgroundInfo() {
    const bg = currentBackground();
    const title = bg.title || 'Custom background';
    el.backgroundInfo.innerHTML = `<strong>${escapeHtml(title)}</strong><small>${escapeHtml(bg.orientation || '')}${bg.width && bg.height ? ` · ${Number(bg.width)} × ${Number(bg.height)}` : ''}</small><p>${escapeHtml(bg.placement || 'Place a separate photo layer in an open area of the scene.')}</p>`;
  }

  function renderPlacementGuides() {
    el.placementGuides.replaceChildren();
    if (!root.querySelector('[data-placement-enabled]').checked) return;
    const bg = currentBackground();
    const frame = el.canvas.getBoundingClientRect();
    const sourceWidth = Number(bg.width || el.bg.naturalWidth);
    const sourceHeight = Number(bg.height || el.bg.naturalHeight);
    if (!sourceWidth || !sourceHeight || !frame.width || !frame.height) return;
    const fit = bg.fit === 'contain' ? Math.min : Math.max;
    const scale = fit(frame.width / sourceWidth, frame.height / sourceHeight);
    const width = sourceWidth * scale;
    const height = sourceHeight * scale;
    const offsetX = (frame.width - width) * bg.x / 100;
    const offsetY = (frame.height - height) * bg.y / 100;
    const originX = frame.width * bg.x / 100;
    const originY = frame.height * bg.y / 100;
    [[bg.modelZone, 'Photo placement', 'photo'], [bg.textZone, 'Suggested live text', 'text']].forEach(([zone, label, kind]) => {
      if (!zone) return;
      const guide = document.createElement('div');
      guide.className = `placement-guide ${kind}`;
      guide.style.left = `${originX + (offsetX + width * zone.x / 100 - originX) * bg.zoom}px`;
      guide.style.top = `${originY + (offsetY + height * zone.y / 100 - originY) * bg.zoom}px`;
      guide.style.width = `${width * zone.width / 100 * bg.zoom}px`;
      guide.style.height = `${height * zone.height / 100 * bg.zoom}px`;
      guide.textContent = label;
      el.placementGuides.appendChild(guide);
    });
  }

  function renderMedia() {
    const query = (el.mediaSearch?.value || '').toLowerCase().trim();
    const filter = el.mediaFilter?.value || 'all';
    el.mediaList.replaceChildren();

    const rows = media.filter((asset) => {
      const matchesFilter = filter === 'all' || asset.type === filter;
      const haystack = `${asset.title || ''} ${Array.isArray(asset.tags) ? asset.tags.join(' ') : asset.tags || ''}`.toLowerCase();
      return matchesFilter && (!query || haystack.includes(query));
    });

    if (!rows.length) {
      el.mediaList.innerHTML = '<div class="media-empty">No matching images. Upload a PNG, JPG, or WebP.</div>';
      return;
    }

    rows.forEach((asset) => {
      const card = document.createElement('article');
      card.className = 'media-item';
      card.draggable = true;
      card.dataset.mediaId = String(asset.id);

      const img = document.createElement('img');
      img.alt = '';
      setImageSource(img, resolveAsset(asset.thumbnail || asset.file, asset.thumbnailUrl || asset.url), asset.title || 'Media image', () => card.classList.add('media-broken'));

      const actions = document.createElement('div');
      actions.className = 'media-actions';
      actions.innerHTML = '<button type="button" data-media-action="background" title="Use as background">BG</button><button type="button" data-media-action="add" title="Add as photo layer">＋</button>';

      const caption = document.createElement('div');
      caption.className = 'media-caption';
      caption.innerHTML = `<strong>${escapeHtml(asset.title || 'Untitled')}</strong><small>${escapeHtml(asset.type || 'image')}</small>`;

      card.append(img, actions, caption);
      card.addEventListener('dragstart', (event) => {
        event.dataTransfer.effectAllowed = 'copy';
        event.dataTransfer.setData('text/velvet-media-id', String(asset.id));
      });
      card.addEventListener('dblclick', () => addLayer(asset, section === 'hero' ? 72 : 68, 50));
      actions.querySelector('[data-media-action="background"]').addEventListener('click', (event) => {
        event.stopPropagation();
        setBackground(asset);
      });
      actions.querySelector('[data-media-action="add"]').addEventListener('click', (event) => {
        event.stopPropagation();
        addLayer(asset, section === 'hero' ? 72 : 68, 50);
      });
      el.mediaList.appendChild(card);
    });
  }

  function findAsset(id) {
    return media.find((item) => String(item.id) === String(id));
  }

  function setBackground(asset, fromLibrary = false) {
    const targets = fromLibrary && root.querySelector('[data-library-all-devices]').checked
      ? ['desktop', 'tablet', 'mobile'] : [device];
    targets.forEach((targetDevice) => {
      const bg = currentScene().devices[targetDevice].background;
      Object.assign(bg, {
        src: asset.file, title: asset.title || 'Custom background',
        libraryId: fromLibrary ? asset.id : null,
        width: Number(asset.width || 0), height: Number(asset.height || 0),
        orientation: asset.orientation || (Number(asset.width) > Number(asset.height) ? 'landscape' : 'portrait'),
        placement: asset.placement || '', modelZone: asset.modelZone || null, textZone: asset.textZone || null,
        x: 50, y: 50, zoom: 1
      });
    });
    if (asset.file && asset.url) assetUrls[asset.file] = asset.url;
    commitHistory();
    render();
    activatePropertyTab('background');
    setStatus(`Previewing ${asset.title}${targets.length > 1 ? ' on all devices' : ` on ${device}`}. Save Draft or Publish when ready.`);
  }

  function addLayer(asset, x = 68, y = 50) {
    const layers = currentView().layers;
    const z = Math.max(0, ...layers.map((layer) => Number(layer.z || 0))) + 1;
    const portrait = Number(asset.height || 0) > Number(asset.width || 0);
    const layer = {
      id: `layer-${Date.now()}-${Math.random().toString(16).slice(2, 7)}`,
      mediaId: String(asset.id),
      name: asset.title || 'Photo layer',
      src: asset.file,
      thumbnail: asset.thumbnail || asset.file,
      x,
      y,
      width: device === 'mobile' ? (portrait ? 52 : 58) : (portrait ? 28 : 32),
      rotation: 0,
      opacity: 100,
      brightness: 100,
      contrast: 100,
      saturation: 100,
      feather: 0,
      shadowX: 0,
      shadowY: 18,
      shadowBlur: 24,
      shadowOpacity: 35,
      flipX: false,
      flipY: false,
      visible: true,
      locked: false,
      naturalWidth: Number(asset.width || 0),
      naturalHeight: Number(asset.height || 0),
      z
    };
    if (asset.file && asset.url) assetUrls[asset.file] = asset.url;
    if (asset.thumbnail && asset.thumbnailUrl) assetUrls[asset.thumbnail] = asset.thumbnailUrl;
    layers.push(layer);
    selectedId = layer.id;
    selectedTarget = 'layer';
    commitHistory();
    activatePropertyTab('layer');
    render();
    setStatus(`${layer.name} added. Drag it anywhere or use the corner handles to resize it.`);
  }

  function addButton() {
    const layer = {
      id: `button-${Date.now()}-${Math.random().toString(16).slice(2, 7)}`,
      type: 'button', name: 'Book with me', label: 'Book with me', url: 'booking/',
      x: device === 'mobile' ? 50 : 70, y: 82,
      width: device === 'mobile' ? 62 : (device === 'tablet' ? 32 : 22),
      height: section === 'footer' ? 12 : 8,
      style: 'solid', color: '#ff2d8d', textColor: '#ffffff', borderColor: '#ff2d8d',
      fontSize: device === 'mobile' ? 16 : 18, radius: 10, newTab: false,
      rotation: 0, opacity: 100, shadowX: 0, shadowY: 8, shadowBlur: 18, shadowOpacity: 25,
      visible: true, locked: false,
      z: Math.max(0, ...currentView().layers.map((item) => Number(item.z || 0))) + 1
    };
    currentView().layers.push(layer);
    selectedId = layer.id;
    selectedTarget = 'layer';
    commitHistory();
    activatePropertyTab('layer');
    render();
    setStatus(`Button added to ${device}. Edit its label and destination in Layers; copy the layout to reuse it on other devices.`);
  }

  el.contentBox.addEventListener('pointerdown', startContentPointer);
  el.contentBox.addEventListener('click', (event) => {
    event.stopPropagation();
    selectedTarget = 'content';
    selectedId = null;
    activatePropertyTab('content');
    render();
  });

  el.canvas.addEventListener('pointerdown', startBackgroundPan);
  el.canvas.addEventListener('click', (event) => {
    if (event.target.closest('.composer-layer, [data-content-box], [data-selection-overlay]')) return;
    if (backgroundPanMode) return;
    selectedId = null;
    selectedTarget = 'none';
    renderLayers();
    renderProperties();
  });
  el.canvas.addEventListener('dragover', (event) => {
    event.preventDefault();
    event.dataTransfer.dropEffect = 'copy';
  });
  el.canvas.addEventListener('drop', (event) => {
    event.preventDefault();
    const asset = findAsset(event.dataTransfer.getData('text/velvet-media-id'));
    if (!asset) return;
    const rect = el.canvas.getBoundingClientRect();
    const x = (event.clientX - rect.left) / rect.width * 100;
    const y = (event.clientY - rect.top) / rect.height * 100;
    if (asset.type === 'background') setBackground(asset);
    else addLayer(asset, x, y);
  });

  root.querySelectorAll('[data-section]').forEach((button) => button.addEventListener('click', () => {
    section = button.dataset.section;
    selectedId = null;
    selectedTarget = 'none';
    backgroundPanMode = false;
    render();
  }));
  root.querySelectorAll('[data-device]').forEach((button) => button.addEventListener('click', () => {
    device = button.dataset.device;
    selectedId = null;
    selectedTarget = 'none';
    render();
  }));
  root.querySelectorAll('[data-property-tab]').forEach((button) => button.addEventListener('click', () => activatePropertyTab(button.dataset.propertyTab)));

  const bgControls = {
    bgX: 'x',
    bgY: 'y',
    bgZoom: 'zoom',
    bgBrightness: 'brightness',
    bgContrast: 'contrast',
    bgSaturation: 'saturation',
    bgBlur: 'blur',
    overlay: 'overlay',
    overlayColor: 'overlayColor'
  };
  Object.entries(bgControls).forEach(([control, property]) => {
    const input = root.querySelector(`[data-control="${control}"]`);
    if (!input) return;
    input.addEventListener('input', () => {
      let value = property === 'overlayColor' ? input.value : Number(input.value);
      if (property === 'zoom') value /= 100;
      currentBackground()[property] = value;
      applyBackgroundStyles();
      renderProperties(false);
    });
    input.addEventListener('change', () => {
      commitHistory();
      setStatus('Background changed — save when ready.');
    });
  });

  const heightInput = root.querySelector('[data-control="height"]');
  heightInput?.addEventListener('input', () => {
    currentView().height = Number(heightInput.value);
    const frame = geometry.frame(section, device, currentView());
    el.canvas.style.aspectRatio = `${frame.width} / ${frame.height}`;
    renderProperties(false);
  });
  heightInput?.addEventListener('change', () => {
    commitHistory();
    setStatus('Section height changed.');
  });

  const contentControls = {
    contentX: 'x',
    contentY: 'y',
    contentWidth: 'width',
    contentHeight: 'height'
  };
  Object.entries(contentControls).forEach(([control, property]) => {
    const input = root.querySelector(`[data-control="${control}"]`);
    if (!input) return;
    input.addEventListener('input', () => {
      const contentBox = currentView().contentBox;
      if (property === 'width') contentBox.width = clamp(input.value, 5, 100 - Math.max(0, contentBox.x));
      else if (property === 'height') contentBox.height = clamp(input.value, 5, 100 - Math.max(0, contentBox.y));
      else if (property === 'x') contentBox.x = clamp(input.value, 0, Math.max(0, 100 - contentBox.width));
      else if (property === 'y') contentBox.y = clamp(input.value, 0, Math.max(0, 100 - contentBox.height));
      selectedTarget = 'content';
      selectedId = null;
      applyContentBoxStyles();
      updateContentPropertyReadout();
    });
    input.addEventListener('change', () => {
      commitHistory();
      setStatus('Live content box changed.');
    });
  });

  el.contentButtons.addEventListener('input', (event) => {
    const input = event.target.closest('[data-content-button-field]');
    const card = input?.closest('[data-content-button-id]');
    if (!card) return;
    const button = editableContentButtons().find((entry) => entry.id === card.dataset.contentButtonId);
    if (!button) return;
    const field = input.dataset.contentButtonField;
    button[field] = input.type === 'checkbox' ? input.checked : input.value;
    if (field === 'action') {
      button.url = button.action === 'link' ? 'booking/' : '';
      if (button.action === 'link' && !button.label.trim()) button.label = `Button ${Number(card.dataset.contentButtonIndex) + 1}`;
      if (button.action === 'trailer') button.newTab = false;
    }
    renderContentButtonPreviews();
  });
  el.contentButtons.addEventListener('change', (event) => {
    const input = event.target.closest('[data-content-button-field]');
    if (!input) return;
    commitHistory();
    if (input.dataset.contentButtonField === 'action') renderContentButtons();
    setStatus('Live group button changed — save when ready.');
  });
  el.contentButtons.addEventListener('click', (event) => {
    const control = event.target.closest('[data-content-button-action]');
    const card = control?.closest('[data-content-button-id]');
    if (!card) return;
    const buttons = editableContentButtons();
    const index = buttons.findIndex((button) => button.id === card.dataset.contentButtonId);
    if (index < 0) return;
    const action = control.dataset.contentButtonAction;
    if (action === 'remove') buttons.splice(index, 1);
    else {
      const next = index + (action === 'up' ? -1 : 1);
      if (next < 0 || next >= buttons.length) return;
      [buttons[index], buttons[next]] = [buttons[next], buttons[index]];
    }
    commitHistory();
    renderContentButtons();
    setStatus(action === 'remove' ? 'Button removed from the live text group. Undo can restore it.' : 'Live group button order changed.');
  });

  const layerControls = {
    layerX: 'x',
    layerY: 'y',
    layerWidth: 'width',
    layerHeight: 'height',
    layerScale: 'width',
    layerRotation: 'rotation',
    layerOpacity: 'opacity',
    layerBrightness: 'brightness',
    layerContrast: 'contrast',
    layerSaturation: 'saturation',
    layerFeather: 'feather',
    layerShadow: 'shadowOpacity'
  };
  Object.entries(layerControls).forEach(([control, property]) => {
    const input = root.querySelector(`[data-control="${control}"]`);
    if (!input) return;
    input.addEventListener('input', () => {
      const layer = selectedLayer();
      if (!layer || layer.locked) return;
      let value = Number(input.value);
      if (!Number.isFinite(value)) return;
      if (property === 'width' || property === 'height') value = clamp(value, 0.5, 400);
      if (property === 'x' || property === 'y') value = clamp(value, -400, 500);
      layer[property] = value;
      renderLayers();
      renderProperties(false);
      if (property === 'width' || property === 'height') updateLayerPropertyReadout(layer);
    });
    input.addEventListener('change', () => {
      commitHistory();
      setStatus('Layer changed — save when ready.');
    });
  });

  const buttonControls = {buttonLabel: 'label', buttonUrl: 'url', buttonStyle: 'style',
    buttonColor: 'color', buttonTextColor: 'textColor', buttonBorderColor: 'borderColor',
    buttonFontSize: 'fontSize', buttonRadius: 'radius', buttonNewTab: 'newTab'};
  Object.entries(buttonControls).forEach(([control, property]) => {
    const input = root.querySelector(`[data-control="${control}"]`);
    input.addEventListener('input', () => {
      const layer = selectedLayer();
      if (!layer || layer.type !== 'button' || layer.locked) return;
      let value = input.type === 'checkbox' ? input.checked : input.value;
      if (property === 'fontSize') value = clamp(value, 8, 96);
      if (property === 'radius') value = clamp(value, 0, 100);
      layer[property] = value;
      if (property === 'label') layer.name = value || 'Button';
      renderLayers();
      renderProperties(false);
    });
    input.addEventListener('change', () => {
      commitHistory();
      setStatus('Button changed — save when ready.');
    });
  });

  root.querySelectorAll('[data-layer-size]').forEach((button) => button.addEventListener('click', () => {
    const layer = selectedLayer();
    if (!layer || layer.locked) return;
    layer.width = clamp(button.dataset.layerSize, 0.5, 400);
    commitHistory();
    render();
    setStatus(`Layer width set to ${layer.width}%.`);
  }));

  root.querySelectorAll('[data-action]').forEach((button) => button.addEventListener('click', () => handleAction(button.dataset.action)));

  function handleAction(action) {
    const layer = selectedLayer();
    if (action === 'add-content-button') {
      addContentButton();
      return;
    }
    if (action === 'add-button') {
      addButton();
      return;
    }
    if (action === 'toggle-upload') {
      activateAssetsTab('media');
      const panel = root.querySelector('[data-upload-panel]');
      panel.hidden = !panel.hidden;
      return;
    }
    if (action === 'undo') {
      if (historyIndex <= 0) return;
      historyIndex -= 1;
      restoreSnapshot(history[historyIndex]);
      setStatus('Undid the last change.');
      return;
    }
    if (action === 'redo') {
      if (historyIndex >= history.length - 1) return;
      historyIndex += 1;
      restoreSnapshot(history[historyIndex]);
      setStatus('Redid the change.');
      return;
    }
    if (action === 'preview') {
      previewDrafts();
      return;
    }
    if (action === 'save' || action === 'publish') {
      runSave(action);
      return;
    }
    if (action === 'pan-background') {
      backgroundPanMode = !backgroundPanMode;
      selectedTarget = 'none';
      selectedId = null;
      render();
      setStatus(backgroundPanMode ? 'Pan Background is on — drag the room image.' : 'Pan Background is off.');
      return;
    }
    if (action === 'copy-layout') {
      const source = clone(currentView());
      ['desktop', 'tablet', 'mobile'].filter((targetDevice) => targetDevice !== device).forEach((targetDevice) => {
        currentScene().devices[targetDevice] = clone(source);
      });
      commitHistory();
      render();
      setStatus(`${device} layout copied to the other devices.`);
      return;
    }
    if (action === 'reset-view') {
      Object.assign(currentBackground(), {x: 50, y: 50, zoom: 1, brightness: 100, contrast: 100, saturation: 100, blur: 0});
      commitHistory();
      render();
      setStatus('Background view reset.');
      return;
    }
    if (action === 'reset-content') {
      currentView().contentBox = defaultContentBox(section, device);
      selectedTarget = 'content';
      selectedId = null;
      commitHistory();
      render();
      activatePropertyTab('content');
      setStatus('Live content box reset for this device.');
      return;
    }
    if (!layer) return;
    if (layer.locked && !['layer-lock', 'layer-hide', 'layer-duplicate'].includes(action)) {
      setStatus('Unlock this layer before changing it.');
      return;
    }
    if (action.startsWith('layer-align-') || action === 'layer-crop-bottom') alignLayer(action, layer);
    if (action === 'layer-recover') { layer.x = 50; layer.y = 50; layer.visible = true; }
    if (action === 'layer-lock') layer.locked = !layer.locked;

    if (action === 'layer-forward') layer.z = Math.max(...currentView().layers.map((item) => Number(item.z ?? 1))) + 1;
    if (action === 'layer-back') layer.z = Math.min(...currentView().layers.map((item) => Number(item.z ?? 1))) - 1;
    if (action === 'layer-flip') layer.flipX = !layer.flipX;
    if (action === 'layer-duplicate') {
      const copy = clone(layer);
      copy.id = `layer-${Date.now()}-${Math.random().toString(16).slice(2, 6)}`;
      copy.name = `${layer.name} copy`;
      copy.x = clamp(Number(layer.x) + 3, -400, 500);
      copy.y = clamp(Number(layer.y) + 3, -400, 500);
      copy.locked = false;
      copy.z = Math.max(...currentView().layers.map((item) => Number(item.z ?? 1))) + 1;
      currentView().layers.push(copy);
      selectedId = copy.id;
    }
    if (action === 'layer-hide') layer.visible = layer.visible === false;
    if (action === 'layer-delete') {
      currentView().layers = currentView().layers.filter((item) => item.id !== layer.id);
      selectedId = null;
      selectedTarget = 'none';
    }
    commitHistory();
    render();
    setStatus('Layer updated.');
  }

  function alignLayer(action, layer) {
    const node = layerNode(layer);
    const frame = el.canvas.getBoundingClientRect();
    const rect = node?.getBoundingClientRect();
    const width = rect ? rect.width / frame.width * 100 : Number(layer.width);
    const height = rect ? rect.height / frame.height * 100 : width;
    if (action === 'layer-align-left') layer.x = width / 2;
    if (action === 'layer-align-center-x') layer.x = 50;
    if (action === 'layer-align-right') layer.x = 100 - width / 2;
    if (action === 'layer-align-top') layer.y = height / 2;
    if (action === 'layer-align-center-y') layer.y = 50;
    if (action === 'layer-align-bottom') layer.y = 100 - height / 2;
    if (action === 'layer-crop-bottom') layer.y = 100 - height * 0.3;
    layer.x = clamp(layer.x, -400, 500);
    layer.y = clamp(layer.y, -400, 500);
  }

  function setSaving(value) {
    saving = value;
    root.querySelectorAll('[data-action="save"], [data-action="publish"], [data-action="preview"], [data-restore-version]').forEach((button) => { button.disabled = value; });
  }

  async function saveScene(action, targetSection, draft) {
    const response = await fetch(sceneEndpoint(targetSection), {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
      body: JSON.stringify({action, scene: draft})
    });
    const result = await window.VELVET_API.read(response, 'Unable to save composition.');
    versions[targetSection] = result.versions || versions[targetSection];
    return result;
  }

  async function runSave(action) {
    if (saving) return;
    const targetSection = section;
    const draft = clone(scenes[targetSection]);
    setSaving(true);
    setStatus(action === 'publish' ? 'Publishing composition…' : 'Saving draft…', 'busy');
    try {
      const result = await saveScene(action, targetSection, draft);
      commitHistory();
      renderVersions();
      setStatus(result.message || `${targetSection} saved.`);
    } catch (error) {
      setStatus(error.message, 'error');
    } finally {
      setSaving(false);
    }
  }

  async function previewDrafts() {
    if (saving) return;
    const drafts = clone(scenes);
    const preview = window.open('about:blank', '_blank');
    if (preview) {
      preview.opener = null;
      preview.document.title = 'Preparing your draft preview';
      preview.document.body.textContent = 'Saving Hero and Footer drafts for your preview…';
    }
    setSaving(true);
    setStatus('Saving Hero and Footer drafts for Full Page Preview…', 'busy');
    try {
      // Sequential saves avoid conflicting writes in the file-store installation mode.
      await saveScene('save', 'hero', drafts.hero);
      await saveScene('save', 'footer', drafts.footer);
      renderVersions();
      if (preview && !preview.closed) preview.location.replace(`${baseUrl}/?preview=1`);
      setStatus(preview ? 'Full Page Preview opened with both saved drafts.' : 'Both drafts saved. Allow pop-ups, then open Full Page Preview again.');
    } catch (error) {
      if (preview && !preview.closed) {
        try {
          preview.document.title = 'Preview could not be opened';
          const heading = preview.document.createElement('h1');
          heading.textContent = 'Preview could not be opened';
          const message = preview.document.createElement('p');
          message.textContent = error.message || 'The draft could not be saved. Return to the Composer and try again.';
          const link = preview.document.createElement('a');
          link.href = `${baseUrl}/admin/?page=composer`;
          link.textContent = 'Return to Composer';
          link.style.color = '#ff78b8';
          preview.document.body.style.cssText = 'margin:0;padding:clamp(24px,8vw,96px);background:#100810;color:#fff;font:18px/1.6 Arial,sans-serif';
          preview.document.body.replaceChildren(heading, message, link);
        } catch { /* The owner may have navigated the preview tab elsewhere. */ }
      }
      setStatus(error.message, 'error');
    } finally {
      setSaving(false);
    }
  }

  function renderVersions() {
    el.versions.replaceChildren();
    const rows = versions[section] || [];
    if (!rows.length) {
      el.versions.innerHTML = '<div class="no-selection">No published versions yet. Publishing creates a restore point.</div>';
      return;
    }
    rows.forEach((version, index) => {
      const item = document.createElement('div');
      item.className = 'version-item';
      item.dataset.versionId = version.id;
      const date = new Date(version.publishedAt || version.createdAt || Date.now());
      item.innerHTML = `<strong>${escapeHtml(version.scene?.title || `Published version ${rows.length - index}`)}</strong><small>${escapeHtml(date.toLocaleString())} · Published version ${rows.length - index}</small><button type="button" data-restore-version>Restore and publish</button>`;
      item.querySelector('button').disabled = saving;
      item.querySelector('button').addEventListener('click', () => restoreVersion(version.id));
      el.versions.appendChild(item);
    });
  }

  async function restoreVersion(versionId) {
    if (saving) return;
    const targetSection = section;
    if (!confirm('Restore this version and publish it to the live homepage?')) return;
    setSaving(true);
    setStatus('Restoring version…', 'busy');
    try {
      const response = await fetch(sceneEndpoint(targetSection), {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
        body: JSON.stringify({action: 'restore', versionId})
      });
      const result = await window.VELVET_API.read(response, 'Restore failed.');
      scenes[targetSection] = result.scene;
      versions[targetSection] = result.versions || [];
      selectedId = null;
      selectedTarget = 'none';
      normalizeScene(targetSection);
      resetHistory();
      render();
      setStatus(result.message || 'Version restored.');
    } catch (error) {
      setStatus(error.message, 'error');
    } finally {
      setSaving(false);
    }
  }

  const quickUpload = document.getElementById('quickUpload');
  quickUpload?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = quickUpload.querySelector('button');
    const old = button.textContent;
    button.disabled = true;
    button.textContent = 'Uploading…';
    setStatus('Uploading media…', 'busy');
    try {
      const response = await fetch(uploadUrl, {
        method: 'POST',
        body: new FormData(quickUpload),
        headers: {'X-CSRF-Token': csrf}
      });
      const result = await window.VELVET_API.read(response, 'Upload failed.');
      media.unshift(result.asset);
      if (result.asset.file && result.asset.url) assetUrls[result.asset.file] = result.asset.url;
      if (result.asset.thumbnail && result.asset.thumbnailUrl) assetUrls[result.asset.thumbnail] = result.asset.thumbnailUrl;
      quickUpload.reset();
      root.querySelector('[data-upload-panel]').hidden = true;
      renderMedia();
      setStatus('Image uploaded. Drag it onto the canvas or click +.');
    } catch (error) {
      setStatus(error.message, 'error');
    } finally {
      button.disabled = false;
      button.textContent = old;
    }
  });

  root.querySelectorAll('[data-assets-tab]').forEach((button) => button.addEventListener('click', () => activateAssetsTab(button.dataset.assetsTab)));
  el.librarySearch.addEventListener('input', renderLibrary);
  el.libraryCategory.addEventListener('change', renderLibrary);
  root.querySelector('[data-placement-enabled]').addEventListener('change', renderPlacementGuides);
  el.selection.addEventListener('click', (event) => event.stopPropagation());
  el.bg.addEventListener('load', renderPlacementGuides);
  root.querySelector('[data-control="sceneTitle"]').addEventListener('input', (event) => {
    currentScene().title = event.target.value.slice(0, 160);
    setStatus('Composition title changed — save when ready.');
  });
  root.querySelector('[data-control="sceneTitle"]').addEventListener('change', commitHistory);
  if (typeof ResizeObserver === 'function') {
    new ResizeObserver(() => {
      applyBackgroundStyles();
      currentView().layers.forEach((layer) => {
        const node = layerNode(layer);
        if (node) applyLayerNodeStyles(node, layer);
      });
      renderSelectionOverlay();
    }).observe(el.canvas);
  }

  el.mediaSearch?.addEventListener('input', renderMedia);
  el.mediaFilter?.addEventListener('change', renderMedia);

  document.addEventListener('keydown', (event) => {
    const activeTag = document.activeElement?.tagName || '';
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
      event.preventDefault();
      handleAction(event.shiftKey ? 'redo' : 'undo');
      return;
    }
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'y') {
      event.preventDefault();
      handleAction('redo');
      return;
    }
    if (/INPUT|TEXTAREA|SELECT/.test(activeTag)) return;
    if (event.key === 'Escape') {
      selectedId = null;
      selectedTarget = 'none';
      backgroundPanMode = false;
      render();
      return;
    }

    const step = event.shiftKey ? 1 : 0.2;
    let changed = false;
    if (selectedTarget === 'content') {
      const box = currentView().contentBox;
      if (event.key === 'ArrowLeft') { box.x -= step; changed = true; }
      if (event.key === 'ArrowRight') { box.x += step; changed = true; }
      if (event.key === 'ArrowUp') { box.y -= step; changed = true; }
      if (event.key === 'ArrowDown') { box.y += step; changed = true; }
    } else {
      const layer = selectedLayer();
      if (!layer || layer.locked) return;
      if (event.key === 'Delete' || event.key === 'Backspace') {
        event.preventDefault();
        handleAction('layer-delete');
        return;
      }
      if (event.key === 'ArrowLeft') { layer.x -= step; changed = true; }
      if (event.key === 'ArrowRight') { layer.x += step; changed = true; }
      if (event.key === 'ArrowUp') { layer.y -= step; changed = true; }
      if (event.key === 'ArrowDown') { layer.y += step; changed = true; }
      if (event.key === '+' || event.key === '=') { layer.width = clamp(layer.width + (event.shiftKey ? 10 : 2), 0.5, 400); changed = true; }
      if (event.key === '-' || event.key === '_') { layer.width = clamp(layer.width - (event.shiftKey ? 10 : 2), 0.5, 400); changed = true; }
    }
    if (changed) {
      if (selectedTarget === 'content') {
        const box = currentView().contentBox;
        box.x = clamp(box.x, 0, 100 - box.width);
        box.y = clamp(box.y, 0, 100 - box.height);
      } else {
        const layer = selectedLayer();
        layer.x = clamp(layer.x, -400, 500);
        layer.y = clamp(layer.y, -400, 500);
      }
      event.preventDefault();
      commitHistory();
      render();
      setStatus(selectedTarget === 'content' ? 'Live content box nudged.' : 'Layer adjusted.');
    }
  });

  (library.categories || []).forEach((category) => {
    const option = document.createElement('option');
    option.value = category.id;
    option.textContent = `${category.title} (${category.count})`;
    el.libraryCategory.appendChild(option);
  });
  renderMedia();
  renderLibrary();
  activateAssetsTab(assetsTab);
  render();
  setStatus('Ready — drag images and live content, or choose Add Button for a separately movable link.');
})();

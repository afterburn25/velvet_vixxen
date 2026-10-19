(() => {
  'use strict';

  const root = document.getElementById('visualComposer');
  const seed = window.COMPOSER_DATA;
  if (!root || !seed) return;

  const clone = (value) => typeof structuredClone === 'function'
    ? structuredClone(value)
    : JSON.parse(JSON.stringify(value));
  const clamp = (value, min, max) => Math.min(max, Math.max(min, Number(value)));
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const baseUrl = String(seed.baseUrl || '').replace(/\/$/, '');
  const uploadBase = String(seed.uploadBase || `${baseUrl}/uploads/`).replace(/\/?$/, '/');
  const assetUrls = {...(seed.assetUrls || {})};
  const brokenAssets = new Set();

  let scenes = clone(seed.scenes || {});
  let media = Array.isArray(seed.media) ? clone(seed.media) : Object.values(clone(seed.media || {}));
  let versions = clone(seed.versions || {});
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
    panButton: root.querySelector('[data-action="pan-background"]')
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
    if (/^images\/(?:defaults|backgrounds|branding|gallery-covers|icons|placeholders)\//.test(normalized)) {
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

  function normalizeScene(key) {
    if (!scenes[key] || typeof scenes[key] !== 'object') scenes[key] = {};
    const scene = scenes[key];
    scene.section = key;
    scene.background ||= {};
    Object.assign(scene.background, {
      src: scene.background.src || `images/defaults/${key === 'hero' ? 'hero-pink-room.jpg' : 'footer-pink-room.jpg'}`,
      fit: scene.background.fit || 'cover',
      x: Number(scene.background.x ?? 50),
      y: Number(scene.background.y ?? 50),
      zoom: Number(scene.background.zoom ?? 1),
      brightness: Number(scene.background.brightness ?? 100),
      contrast: Number(scene.background.contrast ?? 100),
      saturation: Number(scene.background.saturation ?? 100),
      blur: Number(scene.background.blur ?? 0),
      overlay: Number(scene.background.overlay ?? (key === 'hero' ? 24 : 48)),
      overlayColor: scene.background.overlayColor || '#080007'
    });
    scene.devices ||= {};
    const heights = key === 'hero'
      ? {desktop: 760, tablet: 900, mobile: 860}
      : {desktop: 420, tablet: 520, mobile: 620};
    ['desktop', 'tablet', 'mobile'].forEach((targetDevice) => {
      scene.devices[targetDevice] ||= {};
      const view = scene.devices[targetDevice];
      view.height = Number(view.height || heights[targetDevice]);
      view.layers = Array.isArray(view.layers) ? view.layers : [];
      view.contentBox = {...defaultContentBox(key, targetDevice), ...(view.contentBox || {})};
      view.contentBox.x = Number(view.contentBox.x);
      view.contentBox.y = Number(view.contentBox.y);
      view.contentBox.width = Number(view.contentBox.width);
      view.contentBox.height = Number(view.contentBox.height);
    });
  }

  normalizeScene('hero');
  normalizeScene('footer');

  const currentScene = () => scenes[section];
  const currentView = () => currentScene().devices[device];
  const selectedLayer = () => currentView().layers.find((layer) => layer.id === selectedId) || null;

  function setStatus(message, mode = 'ready') {
    el.status.classList.toggle('busy', mode === 'busy');
    el.status.classList.toggle('error', mode === 'error');
    const label = el.status.querySelector('span:last-child');
    if (label) label.textContent = message;
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

  function canvasWidthForDevice() {
    return device === 'desktop' ? 2400 : (device === 'tablet' ? 1600 : 1080);
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
    const bg = currentScene().background;
    el.bg.style.objectFit = bg.fit || 'cover';
    el.bg.style.objectPosition = `${bg.x}% ${bg.y}%`;
    el.bg.style.transform = `scale(${bg.zoom})`;
    el.bg.style.filter = `brightness(${bg.brightness}%) contrast(${bg.contrast}%) saturate(${bg.saturation}%) blur(${bg.blur}px)`;
    el.overlay.style.background = bg.overlayColor;
    el.overlay.style.opacity = String(bg.overlay / 100);
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
    return `brightness(${Number(layer.brightness ?? 100)}%) contrast(${Number(layer.contrast ?? 100)}%) saturate(${Number(layer.saturation ?? 100)}%) drop-shadow(${Number(layer.shadowX ?? 0)}px ${Number(layer.shadowY ?? 18)}px ${Number(layer.shadowBlur ?? 24)}px rgba(0,0,0,${Number(layer.shadowOpacity ?? 35) / 100}))`;
  }

  function applyLayerNodeStyles(node, layer) {
    node.style.left = `${Number(layer.x ?? 65)}%`;
    node.style.top = `${Number(layer.y ?? 50)}%`;
    node.style.width = `${Number(layer.width ?? 30)}%`;
    node.style.opacity = String(Number(layer.opacity ?? 100) / 100);
    node.style.zIndex = String(Number(layer.z ?? 1));
    node.style.transform = layerTransform(layer);
    node.style.filter = layerFilter(layer);
  }

  function render() {
    normalizeScene(section);
    const scene = currentScene();
    const view = currentView();
    const canvasWidth = canvasWidthForDevice();
    el.canvas.style.aspectRatio = `${canvasWidth} / ${Math.max(100, view.height)}`;
    setImageSource(el.bg, resolveAsset(scene.background.src), `${section} background`);
    applyBackgroundStyles();
    applyContentBoxStyles();
    el.label.textContent = `${section === 'hero' ? 'Homepage Hero' : 'Visual Footer'} · ${device[0].toUpperCase() + device.slice(1)}`;
    renderLayers();
    renderProperties();
    renderVersions();
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
      .sort((a, b) => Number(a.z || 1) - Number(b.z || 1))
      .forEach((layer) => {
        if (layer.visible === false) return;
        const node = document.createElement('div');
        node.className = 'composer-layer' + (layer.id === selectedId && selectedTarget === 'layer' ? ' selected' : '');
        node.dataset.layerId = layer.id;
        applyLayerNodeStyles(node, layer);

        const img = document.createElement('img');
        setImageSource(img, resolveAsset(layer.src), layer.name || 'Photo layer', () => node.classList.add('has-broken-image'));
        img.alt = '';
        const feather = Math.min(45, Number(layer.feather || 0));
        if (feather > 0) {
          img.style.maskImage = `radial-gradient(ellipse at center,#000 ${100 - feather}%,transparent 100%)`;
          img.style.webkitMaskImage = img.style.maskImage;
        }
        node.appendChild(img);

        if (layer.id === selectedId && selectedTarget === 'layer') {
          const tag = document.createElement('span');
          tag.className = 'layer-tag';
          tag.textContent = layer.name || 'Photo layer';
          node.appendChild(tag);
          ['nw', 'ne', 'sw', 'se'].forEach((position) => {
            const handle = document.createElement('span');
            handle.className = `resize-handle ${position}`;
            handle.dataset.handle = position;
            node.appendChild(handle);
          });
        }

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
  }

  function startLayerPointer(event) {
    if (event.button !== 0) return;
    const node = event.currentTarget;
    const layer = currentView().layers.find((item) => item.id === node.dataset.layerId);
    if (!layer) return;

    selectedId = layer.id;
    selectedTarget = 'layer';
    activatePropertyTab('layer');
    event.preventDefault();
    event.stopPropagation();

    const canvasRect = el.canvas.getBoundingClientRect();
    const nodeRect = node.getBoundingClientRect();
    const start = {
      pointerX: event.clientX,
      pointerY: event.clientY,
      layerX: Number(layer.x),
      layerY: Number(layer.y),
      width: Number(layer.width),
      handle: event.target.dataset.handle || '',
      centerX: nodeRect.left + nodeRect.width / 2,
      centerY: nodeRect.top + nodeRect.height / 2
    };
    start.distance = Math.max(8, Math.hypot(start.pointerX - start.centerX, start.pointerY - start.centerY));
    node.classList.add('selected', 'dragging');

    const onMove = (move) => {
      const dx = move.clientX - start.pointerX;
      const dy = move.clientY - start.pointerY;
      if (start.handle) {
        const distance = Math.max(1, Math.hypot(move.clientX - start.centerX, move.clientY - start.centerY));
        layer.width = clamp(start.width * (distance / start.distance), 0.5, 400);
      } else {
        const halfWidth = nodeRect.width / canvasRect.width * 50;
        const halfHeight = nodeRect.height / canvasRect.height * 50;
        const minX = halfWidth >= 50 ? 50 : halfWidth;
        const maxX = halfWidth >= 50 ? 50 : 100 - halfWidth;
        const minY = halfHeight >= 50 ? 50 : halfHeight;
        const maxY = halfHeight >= 50 ? 50 : 100 - halfHeight;
        layer.x = clamp(start.layerX + dx / canvasRect.width * 100, minX, maxX);
        layer.y = clamp(start.layerY + dy / canvasRect.height * 100, minY, maxY);
      }
      applyLayerNodeStyles(node, layer);
      updateLayerPropertyReadout(layer);
    };

    const onUp = () => {
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      document.removeEventListener('pointercancel', onUp);
      node.classList.remove('dragging');
      commitHistory();
      render();
      setStatus(start.handle ? 'Photo resized — save when ready.' : 'Photo moved — save when ready.');
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
        box.x = clamp(start.x + dx, 0, Math.max(0, 100 - box.width));
        box.y = clamp(start.y + dy, 0, Math.max(0, 100 - box.height));
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
    if (event.target.closest('.composer-layer, [data-content-box]')) return;
    event.preventDefault();
    const rect = el.canvas.getBoundingClientRect();
    const bg = currentScene().background;
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
    const bg = currentScene().background;
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
    setControlValue('layerScale', round(layer.width, 1));
    setOutput('layerScale', `${round(layer.width, 1)}%`);
    if (el.selectedSize) el.selectedSize.textContent = `${round(layer.width, 1)}% of canvas · z${layer.z || 1}${layer.visible === false ? ' · hidden' : ''}`;
  }

  function round(value, digits = 0) {
    const factor = 10 ** digits;
    return Math.round(Number(value) * factor) / factor;
  }

  function renderProperties(refreshInputs = true) {
    const scene = currentScene();
    const view = currentView();
    const bg = scene.background;
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
      updateContentPropertyReadout();
    }

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

    if (refreshInputs) {
      const map = {
        layerX: layer.x,
        layerY: layer.y,
        layerWidth: layer.width,
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
    }

    setImageSource(el.selectedPreview, resolveAsset(layer.thumbnail || layer.src), layer.name || 'Photo layer');
    el.selectedName.textContent = layer.name || 'Photo layer';
    updateLayerPropertyReadout(layer);
    setOutput('layerOpacity', `${Math.round(layer.opacity ?? 100)}%`);
    setOutput('layerBrightness', `${Math.round(layer.brightness ?? 100)}%`);
    setOutput('layerContrast', `${Math.round(layer.contrast ?? 100)}%`);
    setOutput('layerSaturation', `${Math.round(layer.saturation ?? 100)}%`);
    setOutput('layerFeather', `${Math.round(layer.feather ?? 0)}%`);
    setOutput('layerShadow', `${Math.round(layer.shadowOpacity ?? 35)}%`);
    renderLayerStack();
  }

  function renderLayerStack() {
    let stack = el.layerControls.querySelector('.layer-stack-list');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'layer-stack-list';
      el.layerControls.appendChild(stack);
    }
    stack.innerHTML = '<h3>Layer stack</h3>';
    [...currentView().layers]
      .sort((a, b) => Number(b.z || 1) - Number(a.z || 1))
      .forEach((layer) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = layer.id === selectedId ? 'active' : '';
        button.innerHTML = `<span>${layer.visible === false ? '○' : '●'}</span><b>${escapeHtml(layer.name || 'Photo layer')}</b><small>z${layer.z || 1}</small>`;
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

  function setBackground(asset) {
    currentScene().background.src = asset.file;
    currentScene().background.x = 50;
    currentScene().background.y = 50;
    currentScene().background.zoom = 1;
    if (asset.file && asset.url) assetUrls[asset.file] = asset.url;
    commitHistory();
    render();
    activatePropertyTab('background');
    setStatus(`Background changed to ${asset.title}.`);
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
    if (event.target.closest('.composer-layer, [data-content-box]')) return;
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
      currentScene().background[property] = value;
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
    el.canvas.style.aspectRatio = `${canvasWidthForDevice()} / ${Math.max(100, currentView().height)}`;
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

  const layerControls = {
    layerX: 'x',
    layerY: 'y',
    layerWidth: 'width',
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
      if (!layer) return;
      let value = Number(input.value);
      if (property === 'width') value = clamp(value, 0.5, 400);
      if (property === 'x' || property === 'y') value = clamp(value, -100, 200);
      layer[property] = value;
      renderLayers();
      renderProperties(false);
      if (property === 'width') updateLayerPropertyReadout(layer);
    });
    input.addEventListener('change', () => {
      commitHistory();
      setStatus('Photo layer changed — save when ready.');
    });
  });

  root.querySelectorAll('[data-layer-size]').forEach((button) => button.addEventListener('click', () => {
    const layer = selectedLayer();
    if (!layer) return;
    layer.width = clamp(button.dataset.layerSize, 0.5, 400);
    commitHistory();
    render();
    setStatus(`Photo size set to ${layer.width}%.`);
  }));

  root.querySelectorAll('[data-action]').forEach((button) => button.addEventListener('click', () => handleAction(button.dataset.action)));

  function handleAction(action) {
    const layer = selectedLayer();
    if (action === 'toggle-upload') {
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
      window.open(`${baseUrl}/?preview=1`, '_blank', 'noopener');
      return;
    }
    if (action === 'save' || action === 'publish') {
      saveScene(action);
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
      Object.assign(currentScene().background, {x: 50, y: 50, zoom: 1, brightness: 100, contrast: 100, saturation: 100, blur: 0});
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

    if (action === 'layer-forward') layer.z = Math.max(...currentView().layers.map((item) => Number(item.z || 1))) + 1;
    if (action === 'layer-back') layer.z = Math.min(...currentView().layers.map((item) => Number(item.z || 1))) - 1;
    if (action === 'layer-flip') layer.flipX = !layer.flipX;
    if (action === 'layer-duplicate') {
      const copy = clone(layer);
      copy.id = `layer-${Date.now()}-${Math.random().toString(16).slice(2, 6)}`;
      copy.name = `${layer.name} copy`;
      copy.x = Number(layer.x) + 3;
      copy.y = Number(layer.y) + 3;
      copy.z = Math.max(...currentView().layers.map((item) => Number(item.z || 1))) + 1;
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
    setStatus('Photo layer updated.');
  }

  async function saveScene(action) {
    setStatus(action === 'publish' ? 'Publishing composition…' : 'Saving draft…', 'busy');
    try {
      const response = await fetch(`${baseUrl}/api/scene.php?section=${encodeURIComponent(section)}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
        body: JSON.stringify({action, scene: currentScene()})
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Unable to save composition.');
      versions[section] = result.versions || versions[section];
      commitHistory();
      renderVersions();
      setStatus(result.message || 'Saved.');
    } catch (error) {
      setStatus(error.message, 'error');
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
      const date = new Date(version.publishedAt || version.createdAt || Date.now());
      item.innerHTML = `<strong>Published version ${rows.length - index}</strong><small>${escapeHtml(date.toLocaleString())}</small><button type="button">Restore and publish</button>`;
      item.querySelector('button').addEventListener('click', () => restoreVersion(version.id));
      el.versions.appendChild(item);
    });
  }

  async function restoreVersion(versionId) {
    if (!confirm('Restore this version and publish it to the live homepage?')) return;
    setStatus('Restoring version…', 'busy');
    try {
      const response = await fetch(`${baseUrl}/api/scene.php?section=${encodeURIComponent(section)}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
        body: JSON.stringify({action: 'restore', versionId})
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Restore failed.');
      scenes[section] = result.scene;
      versions[section] = result.versions || [];
      selectedId = null;
      selectedTarget = 'none';
      normalizeScene(section);
      resetHistory();
      render();
      setStatus(result.message || 'Version restored.');
    } catch (error) {
      setStatus(error.message, 'error');
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
      const response = await fetch(`${baseUrl}/api/upload.php`, {
        method: 'POST',
        body: new FormData(quickUpload),
        headers: {'X-CSRF-Token': csrf}
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Upload failed.');
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
      if (!layer) return;
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
      event.preventDefault();
      commitHistory();
      render();
      setStatus(selectedTarget === 'content' ? 'Live content box nudged.' : 'Photo layer adjusted.');
    }
  });

  renderMedia();
  render();
  setStatus('Ready — drag images and the live text + button box directly on the canvas.');
})();

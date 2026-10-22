(() => {
  'use strict';
  const config = window.VELVET_CONFIG || {};
  const geometry = window.VELVET_SCENE_GEOMETRY;
  const depth = window.VELVET_SCENE_DEPTH;
  const baseUrl = String(config.baseUrl || '').replace(/\/$/, '');
  const uploadBase = String(config.uploadBase || `${baseUrl}/uploads/`).replace(/\/?$/, '/');
  const publicRoutes = new Set((config.publicRoutes || []).map(route => String(route).replace(/^\/+|\/+$/g, '')));

  const normalizePath = (path) => {
    path = String(path || '').trim().replace(/\\/g, '/');
    while (path.startsWith('../')) path = path.slice(3);
    return path.replace(/^\/+/, '');
  };

  const resolveAsset = (path) => {
    if (!path) return '';
    if (/^(?:https?:)?\/\//i.test(path) || String(path).startsWith('data:') || String(path).startsWith('blob:')) return String(path);
    const normalized = normalizePath(path);
    if (normalized.startsWith('uploads/')) return `${baseUrl}/${normalized}`;
    if (/^images\/(?:defaults|hero|backgrounds|branding|gallery-covers|icons|placeholders)\//.test(normalized)) return `${baseUrl}/${normalized}`;
    if (/^(?:media|models|thumbnails|exports|audio|videos|captions)\//.test(normalized)) return uploadBase + normalized;
    if (normalized.startsWith('images/')) return uploadBase + normalized;
    return `${baseUrl}/${normalized}`;
  };

  const sceneProjections = new Map();
  function projectScene(root) {
    const projection = sceneProjections.get(root);
    if (!projection) return;
    const scale = geometry.scale(projection.device, root.getBoundingClientRect().width);
    root.style.height = `${projection.frame.height * scale}px`;
    projection.node.style.transform = `scale(${scale})`;
  }
  const sceneResizeObserver = typeof ResizeObserver === 'function'
    ? new ResizeObserver((entries) => entries.forEach((entry) => projectScene(entry.target)))
    : null;

  function buttonDestination(value) {
    if (typeof value !== 'string' || /[\u0000-\u0020\u007f\\]/.test(value)) return '';
    const url = value.trim();
    if (!url || url.length > 2048 || /[\u0000-\u0020\u007f\\]/.test(url)
      || /%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i.test(url) || /%(?![0-9a-f]{2})/i.test(url)) return '';
    let decoded;
    try { decoded = decodeURIComponent(url); } catch { return ''; }
    if (url.startsWith('//') || decoded.startsWith('//') || decoded.includes('\\')) return '';
    try {
      const site = new URL(`${baseUrl}/`);
      const prefix = site.pathname.replace(/\/$/, '');
      let target;
      if (/^https?:\/\//i.test(url)) {
        target = new URL(url);
        if (!target.hostname || target.username || target.password) return '';
        if (target.origin !== site.origin
          || (prefix && target.pathname !== prefix && !target.pathname.startsWith(`${prefix}/`))) return target.href;
      } else {
        if (/^[^/?#]*:/.test(url) || /^[^/?#]*:/.test(decoded)
          || /(?:^|\/)\.\.(?:\/|$)/.test(decoded.split(/[?#]/)[0])) return '';
        const path = url.split(/[?#]/)[0];
        const relative = prefix && (path === prefix || path.startsWith(`${prefix}/`)) ? url.slice(prefix.length) : url;
        target = new URL(relative.replace(/^\/+/, ''), site);
      }
      const route = decodeURIComponent(target.pathname.slice(prefix.length)).replace(/^\/+|\/+$/g, '');
      if (!publicRoutes.has(route)) return target.href;
      const destination = new URL(String(config.routeUrl || `${baseUrl}/route.php`), site);
      destination.search = target.search;
      // PHP normalizes dots/spaces in parameter names; none may override routing.
      for (const key of Array.from(destination.searchParams.keys())) {
        if (/^vvx_route(?:\[|$)/.test(key.trimStart().replace(/[ .]/g, '_'))) destination.searchParams.delete(key);
      }
      destination.searchParams.set('vvx_route', route);
      destination.hash = target.hash;
      return destination.href;
    } catch { return ''; }
  }

  function renderButton(node, layer) {
    const color = (value, fallback) => /^#[0-9a-f]{6}$/i.test(value || '') ? value : fallback;
    const number = (value, fallback, min, max) => Number.isFinite(Number(value)) ? Math.max(min, Math.min(max, Number(value))) : fallback;
    const href = buttonDestination(layer.url);
    if (!href || typeof layer.label !== 'string' || !layer.label.trim()) return false;
    node.href = href;
    if (layer.newTab === true) { node.target = '_blank'; node.rel = 'noopener noreferrer'; }
    node.classList.add('public-scene-button');
    node.dataset.layerType = 'button';
    node.style.height = `${number(layer.height, 8, 0.5, 400)}%`;
    node.style.backgroundColor = layer.style === 'outline' ? 'transparent' : color(layer.color, '#ff2d8d');
    node.style.color = color(layer.textColor, '#ffffff');
    node.style.borderColor = color(layer.borderColor, '#ff2d8d');
    node.style.borderRadius = layer.style === 'pill' ? '999px' : `${number(layer.radius, 10, 0, 100)}px`;
    node.style.fontSize = `${number(layer.fontSize, 18, 8, 96)}px`;
    const label = document.createElement('span');
    label.textContent = layer.label.slice(0, 120);
    node.appendChild(label);
    return true;
  }

  const defaultContentBox = (section, targetDevice) => {
    if (section === 'footer') {
      return {
        desktop: {x: 5, y: 18, width: 46, height: 64},
        tablet: {x: 6, y: 20, width: 58, height: 66},
        mobile: {x: 8, y: 43, width: 84, height: 47}
      }[targetDevice];
    }
    return {
      desktop: {x: 6, y: 17, width: 36, height: 63},
      tablet: {x: 7, y: 18, width: 46, height: 64},
      mobile: {x: 7, y: 52, width: 86, height: 42}
    }[targetDevice];
  };

  function renderScene(section) {
    const root = document.querySelector(`[data-scene="${section}"]`);
    const scene = config.scenes?.[section];
    if (!root || !scene) return;

    const targetDevice = geometry.device();
    const view = scene.devices?.[targetDevice] || scene.devices?.desktop || {layers: []};
    const frame = geometry.frame(section, targetDevice, view);
    let projection = sceneProjections.get(root)?.node;
    if (!projection) {
      projection = document.createElement('div');
      projection.className = 'scene-projection';
      projection.append(...root.childNodes);
      root.appendChild(projection);
      root.classList.add('scene-composed');
      sceneResizeObserver?.observe(root);
    }
    Object.assign(projection.style, {width: `${frame.width}px`, height: `${frame.height}px`});
    projection.style.setProperty('--scene-vw', `${frame.width / 100}px`);
    root.dataset.sceneDevice = targetDevice;
    sceneProjections.set(root, {node: projection, frame, device: targetDevice});
    projectScene(root);

    const bg = root.querySelector('.scene-background');
    const overlay = root.querySelector('.scene-overlay');
    const layerRoot = root.querySelector('.scene-layers');
    const content = root.querySelector(section === 'hero' ? '.hero-content' : '.closing-content');
    const settings = {src: `images/defaults/${section === 'hero' ? 'hero' : 'footer'}-pink-room.jpg`, ...(scene.background || {}), ...(view.background || {})};

    if (bg) {
      bg.style.objectFit = settings.fit || 'cover';
      bg.style.objectPosition = `${Number(settings.x ?? 50)}% ${Number(settings.y ?? 50)}%`;
      bg.style.transform = `scale(${Number(settings.zoom ?? 1)})`;
      bg.style.transformOrigin = `${Number(settings.x ?? 50)}% ${Number(settings.y ?? 50)}%`;
      Object.assign(bg.style, {inset: '0', width: '100%', height: '100%'});
      bg.style.filter = `brightness(${Number(settings.brightness ?? 100)}%) contrast(${Number(settings.contrast ?? 100)}%) saturate(${Number(settings.saturation ?? 100)}%) blur(${Number(settings.blur ?? 0)}px)`;
      bg.onload = renderLayers;
      bg.onerror = () => {
        bg.onerror = null;
        bg.dataset.fallbackApplied = '1';
        renderLayers();
        bg.src = `${baseUrl}/images/defaults/${section === 'hero' ? 'hero-pink-room.jpg' : 'footer-pink-room.jpg'}`;
      };
      const source = resolveAsset(settings.src);
      if (bg.dataset.sceneSource !== source) {
        bg.dataset.sceneSource = source;
        bg.dataset.fallbackApplied = '0';
        bg.src = source;
      }
    }

    if (overlay) {
      overlay.style.background = settings.overlayColor || '#080007';
      overlay.style.opacity = String(Number(settings.overlay ?? 25) / 100);
    }

    if (content) {
      const box = {...defaultContentBox(section, targetDevice), ...(view.contentBox || {})};
      Object.assign(content.style, {
        position: 'absolute',
        left: `${Number(box.x)}%`,
        top: `${Number(box.y)}%`,
        width: `${Number(box.width)}%`,
        height: `${Number(box.height)}%`,
        maxWidth: 'none',
        margin: '0',
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'center',
        overflow: 'visible'
      });
    }

    function renderLayers() {
      if (!layerRoot) return;
      // Keep every photo above the artwork, even after repeated Move Back actions.
      layerRoot.style.zIndex = '1';
      layerRoot.style.isolation = 'isolate';
      layerRoot.replaceChildren();
      const stage = root.querySelector('.scene-stage');
      const foreground = depth.createForeground(view, settings, bg, overlay, frame,
        stage ? getComputedStyle(stage).backgroundColor : '#150711');
      const stack = depth.createStack(layerRoot, foreground);
      [...(view.layers || [])]
        .sort((a, b) => Number(a.z ?? 1) - Number(b.z ?? 1))
        .forEach((layer) => {
          if (layer.visible === false) return;
          const isButton = layer.type === 'button';
          const projected = depth.project(layer, view);
          const wrap = document.createElement(isButton ? 'a' : 'div');
          wrap.className = 'public-scene-layer';
          wrap.dataset.layerId = String(layer.id || '');
          wrap.style.left = `${projected.x}%`;
          wrap.style.top = `${projected.y}%`;
          wrap.style.width = `${projected.width}%`;
          wrap.style.opacity = String(Number(layer.opacity ?? 100) / 100);
          wrap.style.zIndex = String(Number(layer.z ?? 1));
          const sx = layer.flipX ? -1 : 1;
          const sy = layer.flipY ? -1 : 1;
          wrap.style.transform = `translate(-50%,-50%) rotate(${Number(layer.rotation ?? 0)}deg) scale(${sx},${sy})`;
          wrap.style.filter = `brightness(${Number(layer.brightness ?? 100)}%) contrast(${Number(layer.contrast ?? 100)}%) saturate(${Number(layer.saturation ?? 100)}%) drop-shadow(${Number(layer.shadowX ?? 0) * projected.scale}px ${Number(layer.shadowY ?? 18) * projected.scale}px ${Number(layer.shadowBlur ?? 24) * projected.scale}px rgba(0,0,0,${Number(layer.shadowOpacity ?? 35) / 100}))`;
          if (isButton) {
            if (renderButton(wrap, layer)) stack.front.appendChild(wrap);
            return;
          }
          const img = document.createElement('img');
          img.src = resolveAsset(layer.src);
          img.alt = '';
          img.onerror = () => wrap.remove();
          if (Number(layer.feather ?? 0) > 0) {
            const feather = Math.min(45, Number(layer.feather));
            img.style.maskImage = `radial-gradient(ellipse at center,#000 ${100 - feather}%,transparent 100%)`;
            img.style.webkitMaskImage = img.style.maskImage;
          }
          wrap.appendChild(img);
          (layer.behindForeground === true ? stack.rear : stack.front).appendChild(wrap);
        });
      if (stage) {
        if (layerRoot.querySelector('.public-scene-button')) stage.removeAttribute('aria-hidden');
        else stage.setAttribute('aria-hidden', 'true');
      }
    }
    renderLayers();
  }

  let resizeTimer;
  const renderAll = () => {
    renderScene('hero');
    renderScene('footer');
  };
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(renderAll, 120);
  });
  renderAll();

  // Dialogs keep keyboard focus inside the visible decision and restore the
  // invoking control. This client acknowledgement is not provider verification.
  function openDialog(root, first, escape) {
    const previousFocus = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    let topLevel = root;
    while (topLevel.parentElement && topLevel.parentElement !== document.body) topLevel = topLevel.parentElement;
    const siblings = [...document.body.children].filter(node => node !== topLevel)
      .map(node => ({node, inert: node.inert}));
    siblings.forEach(({node}) => { node.inert = true; });
    document.body.style.overflow = 'hidden';
    const controls = () => [...root.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),video[controls],[tabindex]:not([tabindex="-1"])')]
      .filter(node => node.getClientRects().length && getComputedStyle(node).visibility !== 'hidden');
    const focusFirst = () => (first || controls()[0])?.focus();
    const keydown = event => {
      if (event.key === 'Escape') { event.preventDefault(); escape?.(); return; }
      if (event.key !== 'Tab') return;
      const items = controls();
      if (!items.length) { event.preventDefault(); return; }
      const index = items.indexOf(document.activeElement);
      if (event.shiftKey && index <= 0) { event.preventDefault(); items.at(-1).focus(); }
      else if (!event.shiftKey && (index === items.length - 1 || index === -1)) { event.preventDefault(); items[0].focus(); }
    };
    const focusin = event => { if (!root.contains(event.target)) focusFirst(); };
    document.addEventListener('keydown', keydown, true);
    document.addEventListener('focusin', focusin, true);
    focusFirst();
    let open = true;
    return () => {
      if (!open) return;
      open = false;
      document.removeEventListener('keydown', keydown, true);
      document.removeEventListener('focusin', focusin, true);
      siblings.forEach(({node, inert}) => { node.inert = inert; });
      document.body.style.overflow = previousOverflow;
      const target = previousFocus && previousFocus !== document.body && previousFocus.isConnected
        ? previousFocus : document.getElementById('mainContent');
      target?.focus();
    };
  }
  const gate = document.getElementById('ageGate');
  let ageAcknowledged = false;
  try { ageAcknowledged = localStorage.getItem('velvetAgeVerified') === 'yes'; } catch (_) { /* Explicit choice still works when storage is unavailable. */ }
  if (gate && config.ageGate && !ageAcknowledged) {
    gate.hidden = false;
    const enter = document.getElementById('ageEnter');
    const closeGate = openDialog(gate, enter, () => gate.querySelector('a[href]')?.focus());
    enter?.addEventListener('click', () => {
      try { localStorage.setItem('velvetAgeVerified', 'yes'); } catch (_) { /* A future page can ask again. */ }
      gate.hidden = true;
      closeGate();
    });
  }

  const modal = document.getElementById('trailerModal');
  const trailerVideo = modal?.querySelector('video');
  let releaseTrailer = null;
  const closeTrailer = () => {
    if (!modal) return;
    modal.hidden = true;
    trailerVideo?.pause();
    releaseTrailer?.();
    releaseTrailer = null;
  };
  document.querySelectorAll('[data-open-trailer]').forEach(button => button.addEventListener('click', () => {
    if (!modal || !modal.hidden) return;
    modal.hidden = false;
    releaseTrailer = openDialog(modal, modal.querySelector('[data-close-trailer]'), closeTrailer);
  }));
  document.querySelectorAll('[data-close-trailer]').forEach(button => button.addEventListener('click', closeTrailer));
  modal?.addEventListener('click', event => { if (event.target === modal) closeTrailer(); });
})();

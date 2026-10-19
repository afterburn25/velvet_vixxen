(() => {
  'use strict';
  const config = window.VELVET_CONFIG || {};
  const baseUrl = String(config.baseUrl || '').replace(/\/$/, '');
  const uploadBase = String(config.uploadBase || `${baseUrl}/uploads/`).replace(/\/?$/, '/');

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

  const device = () => window.innerWidth <= 760 ? 'mobile' : (window.innerWidth <= 1120 ? 'tablet' : 'desktop');

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

    const targetDevice = device();
    const view = scene.devices?.[targetDevice] || scene.devices?.desktop || {layers: []};
    if (view.height) root.style.minHeight = `${Number(view.height)}px`;

    const bg = root.querySelector('.scene-background');
    const overlay = root.querySelector('.scene-overlay');
    const layerRoot = root.querySelector('.scene-layers');
    const content = root.querySelector(section === 'hero' ? '.hero-content' : '.closing-content');
    const settings = {...(scene.background || {}), ...(view.background || {})};

    if (bg) {
      bg.src = resolveAsset(settings.src);
      bg.style.objectFit = settings.fit || 'cover';
      bg.style.objectPosition = `${Number(settings.x ?? 50)}% ${Number(settings.y ?? 50)}%`;
      bg.style.transform = `scale(${Number(settings.zoom ?? 1)})`;
      bg.style.transformOrigin = `${Number(settings.x ?? 50)}% ${Number(settings.y ?? 50)}%`;
      Object.assign(bg.style, {inset: '0', width: '100%', height: '100%'});
      bg.style.filter = `brightness(${Number(settings.brightness ?? 100)}%) contrast(${Number(settings.contrast ?? 100)}%) saturate(${Number(settings.saturation ?? 100)}%) blur(${Number(settings.blur ?? 0)}px)`;
      bg.onerror = () => {
        bg.onerror = null;
        bg.src = `${baseUrl}/images/defaults/${section === 'hero' ? 'hero-pink-room.jpg' : 'footer-pink-room.jpg'}`;
      };
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

    if (layerRoot) {
      // Keep every photo above the artwork, even after repeated Move Back actions.
      layerRoot.style.zIndex = '1';
      layerRoot.style.isolation = 'isolate';
      layerRoot.replaceChildren();
      [...(view.layers || [])]
        .sort((a, b) => Number(a.z ?? 1) - Number(b.z ?? 1))
        .forEach((layer) => {
          if (layer.visible === false) return;
          const wrap = document.createElement('div');
          wrap.className = 'public-scene-layer';
          wrap.dataset.layerId = String(layer.id || '');
          wrap.style.left = `${Number(layer.x ?? 50)}%`;
          wrap.style.top = `${Number(layer.y ?? 50)}%`;
          wrap.style.width = `${Number(layer.width ?? 30)}%`;
          wrap.style.opacity = String(Number(layer.opacity ?? 100) / 100);
          wrap.style.zIndex = String(Number(layer.z ?? 1));
          const sx = layer.flipX ? -1 : 1;
          const sy = layer.flipY ? -1 : 1;
          wrap.style.transform = `translate(-50%,-50%) rotate(${Number(layer.rotation ?? 0)}deg) scale(${sx},${sy})`;
          wrap.style.filter = `brightness(${Number(layer.brightness ?? 100)}%) contrast(${Number(layer.contrast ?? 100)}%) saturate(${Number(layer.saturation ?? 100)}%) drop-shadow(${Number(layer.shadowX ?? 0)}px ${Number(layer.shadowY ?? 18)}px ${Number(layer.shadowBlur ?? 24)}px rgba(0,0,0,${Number(layer.shadowOpacity ?? 35) / 100}))`;
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
          layerRoot.appendChild(wrap);
        });
    }
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

  const gate = document.getElementById('ageGate');
  if (gate && config.ageGate && localStorage.getItem('velvetAgeVerified') !== 'yes') {
    gate.hidden = false;
    document.body.style.overflow = 'hidden';
    document.getElementById('ageEnter')?.addEventListener('click', () => {
      localStorage.setItem('velvetAgeVerified', 'yes');
      gate.hidden = true;
      document.body.style.overflow = '';
    });
  }

  const menuButton = document.getElementById('mobileMenu');
  const nav = document.getElementById('primaryNav');
  menuButton?.addEventListener('click', () => {
    const open = nav?.classList.toggle('open') || false;
    menuButton.setAttribute('aria-expanded', String(open));
    document.body.classList.toggle('menu-open', open);
  });
  nav?.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
    nav.classList.remove('open');
    menuButton?.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('menu-open');
  }));

  const modal = document.getElementById('trailerModal');
  const trailerVideo = modal?.querySelector('video');
  const closeTrailer = () => { if (!modal) return; modal.hidden = true; trailerVideo?.pause(); };
  document.querySelectorAll('[data-open-trailer]').forEach(button => button.addEventListener('click', () => { if (modal) modal.hidden = false; }));
  document.querySelectorAll('[data-close-trailer]').forEach(button => button.addEventListener('click', closeTrailer));
  modal?.addEventListener('click', event => { if (event.target === modal) closeTrailer(); });
})();

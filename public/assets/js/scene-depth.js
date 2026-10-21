(() => {
  'use strict';
  const EPSILON = 1e-9;
  const MIN_AREA = 1e-7;
  const numeric = (value, fallback) => value == null ? fallback : Number.isFinite(Number(value)) ? Number(value) : fallback;
  const percentage = value => typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 100;
  const point = value => value && !Array.isArray(value) && typeof value === 'object'
    && Object.keys(value).length === 2 && percentage(value.x) && percentage(value.y);
  const perspective = view => point(view?.perspective) ? {...view.perspective} : {x: 50, y: 35};

  function project(layer, view) {
    const depth = layer?.type !== 'button' && percentage(layer?.depth) ? layer.depth : 0;
    const scale = 1 / (1 + 3 * depth / 100);
    const anchor = perspective(view);
    return {
      x: anchor.x + (numeric(layer?.x, 50) - anchor.x) * scale,
      y: anchor.y + (numeric(layer?.y, 50) - anchor.y) * scale,
      width: numeric(layer?.width, 30) * scale,
      scale, depth
    };
  }

  function unproject(x, y, layer, view) {
    const {scale} = project(layer, view);
    const anchor = perspective(view);
    return {x: anchor.x + (x - anchor.x) / scale, y: anchor.y + (y - anchor.y) / scale};
  }

  const cross = (a, b, c) => (b.x - a.x) * (c.y - a.y) - (b.y - a.y) * (c.x - a.x);
  const onSegment = (a, b, p) => Math.abs(cross(a, b, p)) <= EPSILON
    && p.x >= Math.min(a.x, b.x) - EPSILON && p.x <= Math.max(a.x, b.x) + EPSILON
    && p.y >= Math.min(a.y, b.y) - EPSILON && p.y <= Math.max(a.y, b.y) + EPSILON;
  function intersects(a, b, c, d) {
    const abC = cross(a, b, c), abD = cross(a, b, d), cdA = cross(c, d, a), cdB = cross(c, d, b);
    return (((abC > EPSILON && abD < -EPSILON) || (abC < -EPSILON && abD > EPSILON))
      && ((cdA > EPSILON && cdB < -EPSILON) || (cdA < -EPSILON && cdB > EPSILON)))
      || onSegment(a, b, c) || onSegment(a, b, d) || onSegment(c, d, a) || onSegment(c, d, b);
  }

  function validatePolygon(points) {
    if (!Array.isArray(points) || points.length < 3 || points.length > 64) return false;
    for (let index = 0; index < points.length; index++) {
      if (!point(points[index])) return false;
      for (let other = 0; other < index; other++) {
        if (Math.abs(points[index].x - points[other].x) <= EPSILON
          && Math.abs(points[index].y - points[other].y) <= EPSILON) return false;
      }
    }
    let area = 0;
    for (let index = 0; index < points.length; index++) {
      const next = (index + 1) % points.length;
      area += points[index].x * points[next].y - points[next].x * points[index].y;
      for (let other = index + 1; other < points.length; other++) {
        const after = (other + 1) % points.length;
        if (next === other || after === index) continue;
        if (intersects(points[index], points[next], points[other], points[after])) return false;
      }
    }
    return Math.abs(area) / 2 > MIN_AREA;
  }

  function backgroundRect(settings, image, frame) {
    const frameWidth = Number(frame?.width), frameHeight = Number(frame?.height);
    const imageWidth = Number(image?.naturalWidth), imageHeight = Number(image?.naturalHeight);
    if (![frameWidth, frameHeight, imageWidth, imageHeight].every(value => Number.isFinite(value) && value > 0)) return null;
    const fit = settings?.fit === 'contain' ? Math.min(frameWidth / imageWidth, frameHeight / imageHeight)
      : Math.max(frameWidth / imageWidth, frameHeight / imageHeight);
    const zoom = numeric(settings?.zoom, 1);
    if (zoom <= 0) return null;
    const width = imageWidth * fit * zoom, height = imageHeight * fit * zoom;
    return {left: (frameWidth - width) * numeric(settings?.x, 50) / 100,
      top: (frameHeight - height) * numeric(settings?.y, 50) / 100, width, height};
  }

  function imagePoint(x, y, settings, image, frame) {
    const rect = backgroundRect(settings, image, frame);
    if (!rect || !Number.isFinite(x) || !Number.isFinite(y)) return null;
    const sourceX = (x * frame.width / 100 - rect.left) / rect.width * 100;
    const sourceY = (y * frame.height / 100 - rect.top) / rect.height * 100;
    if (sourceX < -EPSILON || sourceX > 100 + EPSILON || sourceY < -EPSILON || sourceY > 100 + EPSILON) return null;
    return {x: Math.max(0, Math.min(100, sourceX)), y: Math.max(0, Math.min(100, sourceY))};
  }

  function canvasPoints(view, settings, image, frame) {
    const mask = view?.foreground;
    if (!mask || mask.enabled !== true || mask.source !== settings?.src || !validatePolygon(mask.points)
      || !image?.complete || image.dataset?.fallbackApplied === '1') return null;
    const rect = backgroundRect(settings, image, frame);
    if (!rect) return null;
    return mask.points.map(p => ({x: (rect.left + p.x / 100 * rect.width) / frame.width * 100,
      y: (rect.top + p.y / 100 * rect.height) / frame.height * 100}));
  }

  function createForeground(view, settings, image, overlay, frame, backgroundColor) {
    const points = canvasPoints(view, settings, image, frame);
    if (!points) return null;
    const foreground = document.createElement('div');
    foreground.className = 'scene-foreground';
    foreground.setAttribute('aria-hidden', 'true');
    Object.assign(foreground.style, {position: 'absolute', inset: '0', pointerEvents: 'none',
      // Flatten the base, filtered image and shade before antialiasing the clip.
      // Separate clipped paints can expose a dark one-pixel polygon seam.
      overflow: 'hidden', backgroundColor: backgroundColor || '#150711', zIndex: '2', filter: 'opacity(1)',
      clipPath: `polygon(${points.map(p => `${p.x}% ${p.y}%`).join(',')})`});
    const copy = document.createElement('img');
    copy.className = 'scene-foreground__image';
    copy.alt = '';
    copy.draggable = false;
    copy.style.cssText = image.style.cssText;
    Object.assign(copy.style, {position: 'absolute', inset: '0', width: '100%', height: '100%',
      maxWidth: 'none', margin: '0', padding: '0', border: '0', pointerEvents: 'none'});
    copy.src = image.currentSrc || image.src;
    foreground.appendChild(copy);
    if (overlay) {
      const shade = document.createElement('div');
      shade.className = 'scene-foreground__overlay';
      Object.assign(shade.style, {position: 'absolute', inset: '0', pointerEvents: 'none',
        background: overlay.style.background, opacity: overlay.style.opacity});
      foreground.appendChild(shade);
    }
    return foreground;
  }

  function createStack(parent, foreground) {
    if (!foreground) return {rear: parent, front: parent};
    const group = (name, z) => {
      const node = document.createElement('div');
      node.className = `scene-depth-${name}`;
      Object.assign(node.style, {position: 'absolute', inset: '0', pointerEvents: 'none', isolation: 'isolate', zIndex: String(z)});
      return node;
    };
    const rear = group('rear', 1), front = group('front', 3);
    parent.append(rear, foreground, front);
    return {rear, front};
  }

  window.VELVET_SCENE_DEPTH = Object.freeze({perspective, project, unproject, validatePolygon,
    backgroundRect, imagePoint, canvasPoints, createForeground, createStack});
})();

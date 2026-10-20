(() => {
  'use strict';

  // Stored positions and heights use these design pixels in both the editor and
  // the public page. Only the final projection changes with the available width.
  const widths = Object.freeze({desktop: 1440, tablet: 900, mobile: 390});
  const heights = {
    hero: {desktop: 760, tablet: 900, mobile: 860},
    footer: {desktop: 420, tablet: 520, mobile: 620}
  };
  const device = (width = window.innerWidth) => width <= 760 ? 'mobile' : (width <= 1120 ? 'tablet' : 'desktop');
  const width = (target) => widths[target] || widths.desktop;
  const frame = (section, target, view = {}) => {
    const fallback = (heights[section] || heights.hero)[target] || heights.hero.desktop;
    const saved = Number(view.height);
    return {width: width(target), height: Math.min(1800, Math.max(280, Number.isFinite(saved) && saved > 0 ? saved : fallback))};
  };
  const scale = (target, availableWidth) => Math.max(0, Number(availableWidth) || 0) / width(target);

  window.VELVET_SCENE_GEOMETRY = Object.freeze({device, width, frame, scale});
})();

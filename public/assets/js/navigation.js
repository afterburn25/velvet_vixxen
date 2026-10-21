(() => {
  'use strict';
  const header = document.querySelector('.public-navigation');
  const menuButton = document.getElementById('mobileMenu');
  const primary = document.getElementById('primaryNav');
  const menus = Array.from(document.querySelectorAll('[data-public-menu]'));
  const collapsed = () => Boolean(header?.classList.contains('navigation-collapsed'));
  const panelFor = button => document.getElementById(button.getAttribute('aria-controls'));
  const buttonFor = panel => panel.parentElement.querySelector(':scope > .navigation-menu-row > [data-menu-toggle]');
  const closePanel = (panel, restoreFocus = false) => {
    if (!panel) return;
    panel.querySelectorAll('[data-menu-panel]').forEach(child => {
      child.hidden = true;
      buttonFor(child)?.setAttribute('aria-expanded', 'false');
    });
    panel.hidden = true;
    const button = buttonFor(panel);
    button?.setAttribute('aria-expanded', 'false');
    if (restoreFocus) button?.focus();
  };
  const closeMenus = except => menus.forEach(menu => {
    if (menu === except) return;
    menu.querySelectorAll(':scope > .navigation-menu-item > [data-menu-panel]').forEach(panel => closePanel(panel));
  });
  const playerClearance = () => {
    const player = document.querySelector('[data-player]');
    return player && !player.hidden ? Math.ceil(player.getBoundingClientRect().height) + 8 : 0;
  };
  const positionPanels = () => {
    if (!header || !primary) return;
    primary.style.setProperty('--public-menu-top', header.getBoundingClientRect().bottom + 'px');
    primary.style.setProperty('--public-menu-bottom', playerClearance() + 'px');
    primary.querySelectorAll(':scope > .navigation-menu > .navigation-menu-item > [data-menu-panel]').forEach(panel => {
      panel.style.removeProperty('--menu-panel-shift');
      panel.style.removeProperty('--menu-panel-height');
      if (panel.hidden || collapsed()) return;
      const box = panel.getBoundingClientRect();
      const shift = Math.max(12 - box.left, Math.min(0, innerWidth - 12 - box.right));
      panel.style.setProperty('--menu-panel-shift', shift + 'px');
      panel.style.setProperty('--menu-panel-height', Math.max(80, innerHeight - box.top - playerClearance() - 12) + 'px');
    });
  };
  const closePrimary = (restoreFocus = false) => {
    primary?.classList.remove('open');
    menuButton?.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('menu-open');
    if (primary) primary.querySelectorAll('[data-menu-panel]').forEach(panel => closePanel(panel));
    if (restoreFocus) menuButton?.focus();
  };
  menus.forEach(menu => {
    menu.querySelectorAll('[data-menu-toggle]').forEach(button => {
      button.hidden = false;
      button.setAttribute('aria-expanded', 'false');
      const panel = panelFor(button);
      if (panel) panel.hidden = true;
      button.addEventListener('click', () => {
        if (!panel) return;
        const opening = panel.hidden;
        const siblings = button.closest('.navigation-menu-item').parentElement;
        siblings.querySelectorAll(':scope > .navigation-menu-item > [data-menu-panel]').forEach(other => {
          if (other !== panel) closePanel(other);
        });
        closeMenus(menu);
        if (opening) {
          panel.hidden = false;
          button.setAttribute('aria-expanded', 'true');
          positionPanels();
        } else closePanel(panel);
      });
    });
    menu.dataset.menuEnhanced = 'true';
    menu.addEventListener('keydown', event => {
      if (event.key !== 'Escape') return;
      const item = event.target.closest('.navigation-menu-item');
      const ownPanel = item?.querySelector(':scope > [data-menu-panel]');
      const panel = ownPanel && !ownPanel.hidden ? ownPanel : event.target.closest('[data-menu-panel]');
      if (panel && !panel.hidden) {
        event.preventDefault();
        event.stopPropagation();
        closePanel(panel, true);
      }
    });
    menu.addEventListener('focusout', () => {
      requestAnimationFrame(() => {
        if (!menu.contains(document.activeElement)) {
          menu.querySelectorAll(':scope > .navigation-menu-item > [data-menu-panel]').forEach(panel => closePanel(panel));
        }
      });
    });
  });
  const fitHeader = () => {
    if (!header || !primary) return;
    const before = collapsed();
    header.classList.remove('navigation-collapsed');
    let compact = innerWidth <= 1280;
    const custom = primary.querySelector(':scope > [data-public-menu]');
    if (!compact && custom) {
      compact = custom.scrollWidth > primary.clientWidth + 1 || custom.getBoundingClientRect().height > 64;
    }
    header.classList.toggle('navigation-collapsed', compact);
    if (before !== compact || !compact) closePrimary();
    positionPanels();
  };
  header?.classList.add('navigation-enhanced');
  fitHeader();
  document.fonts?.ready.then(fitHeader);
  let frame = 0;
  window.addEventListener('resize', () => {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(fitHeader);
  });
  menuButton?.addEventListener('click', () => {
    if (!primary) return;
    const opening = !primary.classList.contains('open');
    if (!opening) return closePrimary();
    closeMenus();
    positionPanels();
    primary.classList.add('open');
    menuButton.setAttribute('aria-expanded', 'true');
    document.body.classList.add('menu-open');
  });
  primary?.addEventListener('keydown', event => {
    if (event.key === 'Escape' && primary.classList.contains('open')) {
      event.preventDefault();
      closePrimary(true);
    }
  });
  primary?.addEventListener('focusout', () => {
    requestAnimationFrame(() => {
      if (!primary.contains(document.activeElement) && document.activeElement !== menuButton) closePrimary();
    });
  });
  document.addEventListener('click', event => {
    if (header && !header.contains(event.target)) closePrimary();
    menus.forEach(menu => {
      if (!menu.contains(event.target)) {
        menu.querySelectorAll(':scope > .navigation-menu-item > [data-menu-panel]').forEach(panel => closePanel(panel));
      }
    });
    const link = event.target.closest('a[href]');
    if (!link) return;
    if (primary?.contains(link)) closePrimary();
    // Native _blank links remain the fallback for script errors, modifiers and
    // browser policy. A noopener popup can return null even when it succeeds.
    if (!link.matches('[data-menu-window]') || !event.isTrusted || event.defaultPrevented
      || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    try {
      const destination = new URL(link.href, location.href);
      if (!['http:', 'https:'].includes(destination.protocol) || destination.username || destination.password) return;
      window.open(destination.href, '_blank', 'popup,noopener,noreferrer');
      event.preventDefault();
    } catch { /* Keep the ordinary safe anchor behavior. */ }
  });
})();

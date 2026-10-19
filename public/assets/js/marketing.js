(() => {
  'use strict';
  const config = window.VELVET_CONFIG || {};
  const baseUrl = String(config.baseUrl || '').replace(/\/$/, '');
  const endpoint = String(config.marketingEventUrl || `${baseUrl}/route.php?vvx_route=api%2Fmarketing-event.php`);
  document.addEventListener('click', (event) => {
    const dismiss = event.target.closest('[data-banner-dismiss]');
    if (dismiss) {
      const banner = dismiss.closest('[data-marketing-banner]');
      if (banner) {
        banner.remove();
        sessionStorage.setItem('velvet-banner-dismissed', '1');
      }
    }
    const cta = event.target.closest('a,button');
    if (cta?.dataset.marketingEvent) {
      navigator.sendBeacon?.(endpoint, new Blob([
        JSON.stringify({event: cta.dataset.marketingEvent, path: String(config.pagePath || location.pathname)})
      ], {type: 'application/json'}));
    }
  });
  document.addEventListener('DOMContentLoaded', () => {
    if (sessionStorage.getItem('velvet-banner-dismissed') === '1') {
      document.querySelectorAll('[data-marketing-banner]').forEach((banner) => banner.remove());
    }
  });
})();

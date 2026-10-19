(() => {
  'use strict';
  const baseUrl = String(window.VELVET_CONFIG?.baseUrl || '').replace(/\/$/, '');
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
      navigator.sendBeacon?.(`${baseUrl}/api/marketing-event.php`, new Blob([
        JSON.stringify({event: cta.dataset.marketingEvent, path: location.pathname})
      ], {type: 'application/json'}));
    }
  });
  document.addEventListener('DOMContentLoaded', () => {
    if (sessionStorage.getItem('velvet-banner-dismissed') === '1') {
      document.querySelectorAll('[data-marketing-banner]').forEach((banner) => banner.remove());
    }
  });
})();

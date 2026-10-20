(() => {
  'use strict';

  // Hosting errors can arrive as plain text or HTML before PHP reaches an API
  // handler. Keep those responses out of JSON parser errors and the page DOM.
  async function read(response, fallback = 'The request could not be completed.') {
    const text = await response.text();
    let result;
    try {
      result = JSON.parse(text);
    } catch (_) {
      if (response.status === 413) {
        throw new Error('The selected file exceeds this server’s upload limit. Choose a smaller file and try again.');
      }
      if (response.status === 401 || response.status === 419) {
        throw new Error('Your session has expired. Sign in again, refresh this page, and retry.');
      }
      if (response.status === 404 || /^\s*File not found\.?\s*$/i.test(text)) {
        throw new Error('The requested service could not be found on this site. Refresh this page after applying the latest site update.');
      }
      if (response.status === 403) {
        throw new Error('The server refused this request. Refresh the page and try again.');
      }
      throw new Error(response.status >= 500
        ? 'The server could not complete this request. Try again in a moment.'
        : 'The server returned an unexpected response. Refresh the page and try again.');
    }
    if (!result || typeof result !== 'object' || Array.isArray(result)) {
      throw new Error('The server returned an unexpected response. Refresh the page and try again.');
    }
    if (!response.ok || result.ok !== true) {
      throw new Error(typeof result.message === 'string' && result.message.trim()
        ? result.message : fallback);
    }
    return result;
  }

  window.VELVET_API = Object.freeze({read});
})();

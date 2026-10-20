(() => {
  'use strict';

  const initialize = () => {
    document.querySelectorAll('[data-publication-editor]').forEach((form) => {
      const metadata = form.querySelector('[data-editorial-metadata]');
      if (!metadata || metadata.dataset.editorialReady === '1') return;
      metadata.dataset.editorialReady = '1';

      const title = form.querySelector('[name="title"]');
      const excerpt = form.querySelector('[name="excerpt"]');
      const searchTitle = metadata.querySelector('[data-editorial-seo-title]');
      const searchDescription = metadata.querySelector('[data-editorial-seo-description]');
      const titleCount = metadata.querySelector('[data-editorial-seo-title-count]');
      const descriptionCount = metadata.querySelector('[data-editorial-seo-description-count]');
      const previewTitle = metadata.querySelector('[data-editorial-search-title]');
      const previewDescription = metadata.querySelector('[data-editorial-search-description]');
      const picker = metadata.querySelector('[data-editorial-image-picker]');
      const image = metadata.querySelector('[data-editorial-image-sample]');
      const imageEmpty = metadata.querySelector('[data-editorial-image-empty]');
      const imageAlt = metadata.querySelector('[data-editorial-image-alt]');
      const imageUnavailable = metadata.querySelector('[data-editorial-image-unavailable]');

      const updateSearch = () => {
        if (titleCount) titleCount.textContent = String(Array.from(searchTitle?.value || '').length);
        if (descriptionCount) descriptionCount.textContent = String(Array.from(searchDescription?.value || '').length);
        const heading = searchTitle?.value.trim() || title?.value.trim() || 'Your publication title';
        const brand = metadata.dataset.editorialBrand || '';
        if (previewTitle) previewTitle.textContent = heading + (brand ? ` · ${brand}` : '');
        if (previewDescription) previewDescription.textContent = searchDescription?.value.trim() || excerpt?.value.trim()
          || 'Add an excerpt or search description to introduce this publication.';
      };
      const updateImage = () => {
        if (!picker || !image || !imageEmpty) return;
        const source = picker.selectedOptions[0]?.dataset.imageSrc || '';
        if (source) image.setAttribute('src', source);
        else image.removeAttribute('src');
        image.hidden = !source;
        imageEmpty.hidden = Boolean(source);
        image.alt = imageAlt?.value.trim() || title?.value.trim() || '';
        if (imageUnavailable) imageUnavailable.hidden = !picker.value || Boolean(source);
      };

      [title, excerpt, searchTitle, searchDescription].forEach((field) => field?.addEventListener('input', updateSearch));
      [title, imageAlt].forEach((field) => field?.addEventListener('input', updateImage));
      picker?.addEventListener('change', updateImage);
      updateSearch();
      updateImage();
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
  else initialize();
})();

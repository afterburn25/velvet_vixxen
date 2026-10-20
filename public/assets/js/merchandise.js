(() => {
  'use strict';
  document.querySelectorAll('[data-merchandise-buy]').forEach(form => {
    const select = form.querySelector('[data-merchandise-variant]');
    if (!select) return;
    const update = () => {
      const option = select.selectedOptions[0];
      if (!option) return;
      form.querySelector('[data-merchandise-price]').textContent = option.dataset.price;
      const quantity = form.querySelector('[name="quantity"]');
      quantity.max = String(Math.min(99, Number(option.dataset.stock)));
      if (Number(quantity.value) > Number(quantity.max)) quantity.value = quantity.max;
    };
    select.addEventListener('change', update);
    update();
  });
  document.querySelectorAll('[data-merchandise-variants]').forEach(container => {
    const form = container.closest('form');
    let next = Number(container.dataset.nextIndex || container.children.length);
    form.querySelector('[data-add-variant]')?.addEventListener('click', () => {
      const template = form.querySelector('template[data-variant-template]');
      const fragment = template.content.cloneNode(true);
      fragment.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace('__INDEX__', String(next)); });
      next += 1;
      container.appendChild(fragment);
      container.lastElementChild.querySelector('input:not([type="hidden"])')?.focus();
    });
    container.addEventListener('click', event => {
      const button = event.target.closest('[data-remove-variant]');
      if (!button) return;
      if (container.children.length === 1) { form.querySelector('[data-variant-status]').textContent = 'Keep one variant, or archive this product.'; return; }
      button.closest('[data-variant-row]').remove();
    });
  });
})();

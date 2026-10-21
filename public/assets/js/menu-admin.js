(() => {
  'use strict';
  const form = document.querySelector('[data-menu-editor]');
  if (!form) return;
  const source = form.querySelector('[data-menu-json]');
  const tree = form.querySelector('[data-menu-tree]');
  const save = form.querySelector('[data-menu-save]');
  const add = form.querySelector('[data-menu-add-root]');
  const status = form.querySelector('[data-menu-editor-status]');
  const reload = form.querySelector('[data-menu-reload]');
  if (!source || !tree || !save || !add || !status) return;
  const maxDepth = Number(form.dataset.maxDepth) || 3;
  const maxItems = Number(form.dataset.maxItems) || 50;
  const targets = {self: 'Same tab', tab: 'New tab', window: 'New window'};
  const attempted = form.hasAttribute('data-menu-attempted');
  let items, initial, selected = '', dirty = false;

  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const announce = (message, error = false) => {
    status.textContent = message;
    status.classList.toggle('is-error', error);
  };
  const walk = (nodes, callback, depth = 1, parent = null) => {
    nodes.forEach((node, index) => {
      callback(node, nodes, index, depth, parent);
      walk(node.children, callback, depth + 1, node);
    });
  };
  const validateShape = (nodes, depth = 1, state = {count: 0, ids: new Set()}) => {
    if (!Array.isArray(nodes) || depth > maxDepth + 1) return false;
    for (const node of nodes) {
      if (!node || typeof node !== 'object' || Array.isArray(node) || depth > maxDepth
          || typeof node.id !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/.test(node.id)
          || state.ids.has(node.id) || ++state.count > maxItems
          || typeof node.label !== 'string' || typeof node.url !== 'string'
          || !Object.hasOwn(targets, node.target) || typeof node.enabled !== 'boolean') return false;
      state.ids.add(node.id);
      if (!validateShape(node.children, depth + 1, state)) return false;
    }
    return true;
  };
  try {
    items = JSON.parse(source.value);
    if (form.hasAttribute('data-menu-load-error') || !validateShape(items)) throw new Error('Invalid menu');
    initial = JSON.stringify(items);
  } catch (_) {
    save.disabled = add.disabled = true;
    announce('This menu could not be loaded for editing. Reload the current saved menu before making changes.', true);
    return;
  }

  const locate = (id) => {
    let found = null;
    walk(items, (node, siblings, index, depth, parent) => {
      if (node.id === id) found = {node, siblings, index, depth, parent};
    });
    return found;
  };
  const count = () => { let total = 0; walk(items, () => total++); return total; };
  const height = (node) => 1 + Math.max(0, ...node.children.map(height));
  const descendantIds = (node) => {
    const ids = new Set([node.id]);
    walk(node.children, child => ids.add(child.id));
    return ids;
  };
  const copy = (value) => JSON.parse(JSON.stringify(value));
  const destinations = element('datalist');
  destinations.id = 'menu-known-destinations';
  [['Home', '/'], ['Music', 'music/'], ['Photos', 'photos/'], ['Videos', 'videos/'],
    ['Membership', 'membership/'], ['Store', 'shop/'], ['Messages', 'messages/'],
    ['Booking', 'booking/'], ['About Me', 'about/']].forEach(([label, url]) => {
      const choice = element('option'); choice.value = url; choice.label = label; destinations.append(choice);
    });
  form.append(destinations);
  const discard = element('button', 'admin-button secondary menu-editor-discard', 'Discard unsaved changes');
  discard.type = 'button';
  discard.disabled = true;
  discard.dataset.menuDiscard = '';
  save.insertAdjacentElement('afterend', discard);
  const sync = () => {
    source.value = JSON.stringify(items);
    dirty = attempted || source.value !== initial;
    discard.disabled = !dirty;
    add.disabled = count() >= maxItems;
    form.dataset.menuDirty = dirty ? 'true' : 'false';
  };
  const changed = (message) => {
    sync();
    announce(message || (dirty ? 'Unsaved changes. Save this menu to update the site.' : 'No unsaved changes.'));
  };
  const button = (action, id, text, label, disabled = false) => {
    const node = element('button', 'menu-editor-action', text);
    node.type = 'button';
    node.dataset.menuAction = action;
    node.dataset.menuId = id;
    node.setAttribute('aria-label', label);
    node.disabled = disabled;
    return node;
  };
  const field = (node, name, title, input) => {
    const label = element('label', 'menu-editor-field');
    input.id = `menu-${node.id}-${name}`;
    input.dataset.menuField = name;
    input.dataset.menuId = node.id;
    label.htmlFor = input.id;
    label.append(element('span', '', title), input);
    return label;
  };
  const option = (value, text, isSelected) => {
    const node = element('option', '', text);
    node.value = value;
    node.selected = isSelected;
    return node;
  };
  const editor = (node, depth, parent) => {
    const panel = element('div', 'menu-editor-fields');
    panel.id = `menu-editor-fields-${node.id}`;
    const labelInput = element('input');
    labelInput.type = 'text'; labelInput.value = node.label; labelInput.maxLength = 80; labelInput.required = true;
    panel.append(field(node, 'label', 'Menu label', labelInput));
    const urlInput = element('input');
    urlInput.type = 'text'; urlInput.value = node.url; urlInput.maxLength = 2048;
    urlInput.placeholder = 'about/ or https://example.com/'; urlInput.spellcheck = false;
    urlInput.setAttribute('autocomplete', 'off');
    urlInput.setAttribute('list', destinations.id);
    const urlField = field(node, 'url', 'Link destination', urlInput);
    urlField.classList.add('menu-editor-wide');
    urlField.append(element('small', '', 'Use a site path, a page anchor, or a full web address. Leave blank only for a heading with submenus.'));
    panel.append(urlField);
    const target = element('select');
    Object.entries(targets).forEach(([value, text]) => target.append(option(value, text, node.target === value)));
    panel.append(field(node, 'target', 'Open link in', target));
    const parents = element('select');
    parents.append(option('', 'No parent — top level', !parent));
    const excluded = descendantIds(node), branchHeight = height(node);
    walk(items, (candidate, _siblings, _index, candidateDepth) => {
      if (!excluded.has(candidate.id) && candidateDepth + branchHeight <= maxDepth) {
        parents.append(option(candidate.id, '› '.repeat(candidateDepth - 1) + (candidate.label.trim() || 'Untitled item'), parent?.id === candidate.id));
      }
    });
    panel.append(field(node, 'parent', 'Parent item', parents));
    const checkbox = element('input'); checkbox.type = 'checkbox'; checkbox.checked = node.enabled;
    const enabled = field(node, 'enabled', 'Show this item', checkbox);
    enabled.classList.add('menu-editor-check');
    enabled.append(element('small', '', 'Hiding a parent also hides its submenus.'));
    panel.append(enabled);
    panel.append(element('p', 'menu-editor-window-help menu-editor-wide', 'A new window is requested when a visitor clicks. Their browser may open a tab or block the window.'));
    const actions = element('div', 'menu-editor-item-actions menu-editor-wide');
    actions.append(button('add-child', node.id, 'Add submenu', `Add submenu to ${node.label || 'item'}`, depth >= maxDepth || count() >= maxItems));
    const remove = button('remove', node.id, 'Remove item', `Remove ${node.label || 'item'}`);
    remove.classList.add('is-danger');
    actions.append(remove);
    panel.append(actions);
    return panel;
  };
  const renderNodes = (nodes, depth = 1, parent = null) => {
    const list = element('ol', depth === 1 ? 'menu-editor-tree' : 'menu-editor-children');
    nodes.forEach((node, index) => {
      const item = element('li', 'menu-editor-node' + (node.id === selected ? ' is-selected' : '') + (!node.enabled ? ' is-disabled' : ''));
      item.dataset.menuItem = node.id;
      const header = element('div', 'menu-editor-row');
      const title = button('edit', node.id, node.label.trim() || 'Untitled item', `Edit ${node.label.trim() || 'item'}`);
      title.className = 'menu-editor-name';
      title.setAttribute('aria-expanded', node.id === selected ? 'true' : 'false');
      if (node.id === selected) title.setAttribute('aria-controls', `menu-editor-fields-${node.id}`);
      header.append(title);
      const detail = element('span', 'menu-editor-summary', !node.enabled ? 'Hidden' : (node.url ? targets[node.target] : 'Submenu heading'));
      detail.dataset.menuSummary = node.id;
      header.append(detail);
      const order = element('div', 'menu-editor-order');
      order.append(button('up', node.id, '↑', `Move ${node.label || 'item'} up`, index === 0));
      order.append(button('down', node.id, '↓', `Move ${node.label || 'item'} down`, index === nodes.length - 1));
      header.append(order);
      item.append(header);
      if (node.id === selected) item.append(editor(node, depth, parent));
      if (node.children.length) item.append(renderNodes(node.children, depth + 1, node));
      list.append(item);
    });
    return list;
  };
  const focusField = (id, fieldName = 'label') => {
    requestAnimationFrame(() => {
      const row = Array.from(tree.querySelectorAll('[data-menu-item]')).find(node => node.dataset.menuItem === id);
      row?.querySelector(`[data-menu-field="${fieldName}"]`)?.focus();
    });
  };
  const focusEdit = (id) => {
    requestAnimationFrame(() => {
      const control = Array.from(tree.querySelectorAll('[data-menu-action="edit"]')).find(node => node.dataset.menuId === id);
      (control || add).focus();
    });
  };
  const render = (focusId) => {
    const rendered = renderNodes(items);
    tree.replaceChildren(...rendered.childNodes);
    if (!items.length) tree.append(element('li', 'menu-editor-empty', 'This menu has no items. Add an item, or save to leave this menu empty.'));
    sync();
    if (focusId) focusField(focusId);
  };
  const createItem = () => ({
    id: 'item_' + (window.crypto?.randomUUID ? crypto.randomUUID().replaceAll('-', '') : Date.now().toString(36) + Math.random().toString(36).slice(2)),
    label: 'New link', url: '', target: 'self', enabled: true, children: []
  });
  const addItem = (parentId = '') => {
    if (count() >= maxItems) { announce(`This menu can contain up to ${maxItems} items.`, true); return; }
    const parent = parentId ? locate(parentId) : null;
    if (parentId && (!parent || parent.depth >= maxDepth)) return;
    const node = createItem();
    (parent ? parent.node.children : items).push(node);
    selected = node.id;
    render(selected);
    changed(parent ? 'Submenu item added. Enter its label and destination, then save.' : 'Menu item added. Enter its label and destination, then save.');
  };
  add.addEventListener('click', () => addItem());
  tree.addEventListener('click', (event) => {
    const control = event.target.closest('[data-menu-action]');
    if (!control || !tree.contains(control)) return;
    const entry = locate(control.dataset.menuId);
    if (!entry) return;
    const {node, siblings, index, parent} = entry;
    switch (control.dataset.menuAction) {
      case 'edit':
        selected = selected === node.id ? '' : node.id;
        render(selected);
        if (!selected) focusEdit(node.id);
        break;
      case 'add-child': addItem(node.id); break;
      case 'up':
      case 'down': {
        const to = index + (control.dataset.menuAction === 'up' ? -1 : 1);
        if (to < 0 || to >= siblings.length) return;
        siblings.splice(index, 1); siblings.splice(to, 0, node);
        selected = node.id; render(); changed('Menu order changed. Save to update the site.');
        focusEdit(node.id);
        break;
      }
      case 'remove': {
        const descendants = descendantIds(node).size - 1;
        const message = descendants ? `Remove “${node.label}” and its ${descendants} submenu item${descendants === 1 ? '' : 's'}? Changes apply when you save.` : `Remove “${node.label}”? Changes apply when you save.`;
        if (!window.confirm(message)) return;
        siblings.splice(index, 1);
        selected = parent?.id || siblings[Math.min(index, siblings.length - 1)]?.id || '';
        render(); changed('Item removed from this draft. Save to update the site.');
        focusEdit(selected);
        break;
      }
    }
  });
  const update = (event) => {
    const input = event.target.closest('[data-menu-field]');
    if (!input || !tree.contains(input)) return;
    const entry = locate(input.dataset.menuId);
    if (!entry) return;
    const name = input.dataset.menuField, node = entry.node;
    if (name === 'parent') {
      if (event.type !== 'change') return;
      const nextParent = input.value ? locate(input.value) : null;
      if (input.value && (!nextParent || descendantIds(node).has(input.value) || nextParent.depth + height(node) > maxDepth)) return;
      entry.siblings.splice(entry.index, 1);
      (nextParent ? nextParent.node.children : items).push(node);
      selected = node.id; render(); changed('Parent changed. Save to update the site.');
      focusField(node.id, 'parent');
      return;
    }
    if (!['label', 'url', 'target', 'enabled'].includes(name)) return;
    node[name] = name === 'enabled' ? input.checked : input.value;
    const row = input.closest('[data-menu-item]');
    const title = row.querySelector('.menu-editor-name');
    title.textContent = node.label.trim() || 'Untitled item';
    title.setAttribute('aria-label', `Edit ${node.label.trim() || 'item'}`);
    row.classList.toggle('is-disabled', !node.enabled);
    row.querySelector('[data-menu-summary]').textContent = !node.enabled ? 'Hidden' : (node.url ? targets[node.target] : 'Submenu heading');
    const actionLabels = {up: `Move ${node.label || 'item'} up`, down: `Move ${node.label || 'item'} down`,
      'add-child': `Add submenu to ${node.label || 'item'}`, remove: `Remove ${node.label || 'item'}`};
    row.querySelectorAll('[data-menu-action]').forEach(button => {
      if (button.dataset.menuId === node.id && actionLabels[button.dataset.menuAction])
        button.setAttribute('aria-label', actionLabels[button.dataset.menuAction]);
    });
    changed();
  };
  tree.addEventListener('input', update);
  tree.addEventListener('change', update);
  discard.addEventListener('click', () => {
    if (!dirty || !window.confirm('Discard your unsaved menu changes?')) return;
    if (attempted) {
      dirty = false;
      window.location.assign(reload?.href || form.action);
      return;
    }
    items = copy(JSON.parse(initial)); selected = items[0]?.id || '';
    render(); changed('Unsaved changes discarded.');
    focusEdit(selected);
  });
  form.addEventListener('submit', (event) => {
    let problem = null;
    walk(items, node => {
      if (problem) return;
      if (!node.label.trim()) problem = {id: node.id, field: 'label', message: 'Enter a label for every menu item.'};
      else if (!node.url.trim() && !node.children.length) problem = {id: node.id, field: 'url', message: 'Add a destination to this item, or add submenus to use it as a heading.'};
      else if (!node.url.trim() && node.target !== 'self') problem = {id: node.id, field: 'target', message: 'A submenu heading without a destination must use Same tab.'};
    });
    if (problem) {
      event.preventDefault(); selected = problem.id; render(); focusField(problem.id, problem.field); announce(problem.message, true); return;
    }
    sync();
    dirty = false;
  });
  document.querySelectorAll('[data-menu-location-link]').forEach(link => link.addEventListener('click', (event) => {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    if (dirty && !window.confirm('Leave this menu and discard unsaved changes?')) event.preventDefault();
    else dirty = false;
  }));
  reload?.addEventListener('click', (event) => {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    if (dirty && !window.confirm('Reload the saved menu and discard your unsaved changes?')) event.preventDefault();
    else dirty = false;
  });
  document.querySelectorAll('[data-menu-reset]').forEach(reset => reset.addEventListener('submit', (event) => {
    if (!window.confirm('Restore the default menu for this location? Your saved custom items will be replaced.')) event.preventDefault();
    else dirty = false;
  }));
  window.addEventListener('beforeunload', (event) => {
    if (dirty) { event.preventDefault(); event.returnValue = ''; }
  });
  selected = items[0]?.id || '';
  render();
  save.disabled = false;
  form.dataset.menuReady = 'true';
  announce(attempted ? 'Your attempted changes are still unsaved. Review the message above, or reload the saved menu.'
    : 'Select an item to edit it. Save this menu when your changes are ready.');
})();

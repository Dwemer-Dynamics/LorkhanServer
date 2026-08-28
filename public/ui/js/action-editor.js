document.addEventListener('DOMContentLoaded', () => {
  const toolbar = document.querySelector('[data-action-filters]');
  const rows = Array.from(document.querySelectorAll('.action-row'));
  if (toolbar && rows.length) {
    const search = toolbar.querySelector('[data-action-search]');
    const visible = document.querySelector('[data-action-visible]');
    const empty = document.querySelector('[data-action-empty]');
    const chosen = (name) => toolbar.querySelector(`input[name="live-${name}"]:checked`)?.value || 'all';
    const apply = () => {
      const query = (search?.value || '').trim().toLowerCase();
      const state = chosen('state');
      const tier = chosen('tier');
      let shown = 0;
      rows.forEach((row) => {
        const matches = (!query || (row.dataset.search || '').includes(query))
          && (state === 'all' || row.dataset.state === state)
          && (tier === 'all' || row.dataset.tier === tier);
        row.hidden = !matches;
        if (matches) shown += 1;
      });
      if (visible) visible.textContent = String(shown);
      if (empty) empty.hidden = shown !== 0;
    };
    search?.addEventListener('input', apply);
    toolbar.querySelectorAll('input[name^="live-"]').forEach((input) => input.addEventListener('change', apply));
    document.querySelector('[data-action-reset]')?.addEventListener('click', () => {
      if (search) search.value = '';
      toolbar.querySelectorAll('input[name^="live-"][value="all"]').forEach((input) => { input.checked = true; });
      apply();
    });
    apply();
  }

  // Bulk selection stays scoped to one policy form and only changes checkbox state,
  // so nothing is persisted until that form's own Save/Create submission.
  document.querySelectorAll('[data-action-policy-controls]').forEach((group) => {
    const boxes = Array.from(group.querySelectorAll('input[type="checkbox"][name="allowed_actions[]"]'));
    if (boxes.length === 0) return;
    const count = group.querySelector('[data-action-selected-count]');
    const report = () => {
      if (count) count.textContent = `${boxes.filter((box) => box.checked).length} of ${boxes.length} selected`;
    };
    boxes.forEach((box) => box.addEventListener('change', report));
    const bulk = group.querySelector('[data-action-bulk]');
    if (bulk) {
      const buttonClass = bulk.dataset.actionBulkClass || '';
      [['Select all', true], ['Clear', false]].forEach(([label, checked]) => {
        const button = document.createElement('button');
        button.type = 'button';
        if (buttonClass) button.className = buttonClass;
        button.textContent = label;
        button.addEventListener('click', () => {
          boxes.forEach((box) => { box.checked = checked; });
          report();
        });
        bulk.insertBefore(button, count && count.parentNode === bulk ? count : null);
      });
    }
    report();
  });

  const policyPanel = document.querySelector('[data-policy-panel]');
  if (policyPanel) {
    document.querySelector('[data-policy-create]')?.addEventListener('click', () => {
      policyPanel.hidden = false;
      policyPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      policyPanel.querySelector('input:not([type=hidden]), select, textarea, button')?.focus();
    });
    document.querySelector('[data-policy-close]')?.addEventListener('click', () => { policyPanel.hidden = true; });
  }

  const modal = document.querySelector('[data-active-modal]');
  if (modal) {
    const opener = document.querySelector('[data-active-actions]');
    const close = () => { modal.hidden = true; opener?.focus(); };
    opener?.addEventListener('click', () => {
      modal.hidden = false;
      modal.querySelector('[data-active-close]')?.focus();
    });
    modal.querySelector('[data-active-close]')?.addEventListener('click', close);
    modal.addEventListener('click', (event) => { if (event.target === modal) close(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.hidden) close(); });
  }
});

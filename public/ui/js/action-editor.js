document.addEventListener('DOMContentLoaded', () => {
  const rows = Array.from(document.querySelectorAll('.action-row'));
  const search = document.querySelector('[data-action-search]');
  const visible = document.querySelector('[data-action-visible]');
  const empty = document.querySelector('[data-action-empty]');
  const selected = (name) => document.querySelector(`input[name="live-${name}"]:checked`)?.value || 'all';
  const apply = () => {
    const query = (search?.value || '').trim().toLowerCase();
    let shown = 0;
    rows.forEach((row) => {
      const matches = (!query || row.dataset.search.includes(query)) && (selected('state') === 'all' || row.dataset.state === selected('state')) && (selected('scope') === 'all' || row.dataset.scope.split(' ').includes(selected('scope'))) && (selected('dispatch') === 'all' || row.dataset.dispatch === selected('dispatch')) && (selected('source') === 'all' || row.dataset.source === selected('source'));
      row.hidden = !matches;
      if (matches) shown++;
    });
    if (visible) visible.textContent = String(shown);
    if (empty) empty.hidden = shown !== 0;
  };
  search?.addEventListener('input', apply);
  document.querySelectorAll('.filter-toolbar input[type=radio]').forEach((input) => input.addEventListener('change', apply));
  document.querySelector('[data-action-reset]')?.addEventListener('click', () => { if (search) search.value = ''; document.querySelectorAll('.filter-toolbar input[value=all]').forEach((input) => { input.checked = true; }); apply(); });
  const policyPanel = document.querySelector('[data-policy-panel]');
  document.querySelector('[data-policy-create]')?.addEventListener('click', () => { policyPanel.hidden = false; policyPanel.scrollIntoView({behavior:'smooth',block:'start'}); });
  document.querySelector('[data-policy-close]')?.addEventListener('click', () => { policyPanel.hidden = true; });
  const modal = document.querySelector('[data-active-modal]');
  document.querySelector('[data-active-actions]')?.addEventListener('click', () => { modal.hidden = false; });
  document.querySelector('[data-active-close]')?.addEventListener('click', () => { modal.hidden = true; });
  modal?.addEventListener('click', (event) => { if (event.target === modal) modal.hidden = true; });
  apply();
});

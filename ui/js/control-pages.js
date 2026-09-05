document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-control-table-region]').forEach((region) => {
    const table = region.querySelector(':scope > [data-control-table]');
    if (!table?.tBodies.length) return;

    const rows = Array.from(table.tBodies[0].rows);
    const countLabel = region.dataset.countLabel || 'records';
    const tools = document.createElement('div');
    tools.className = 'control-table-tools';

    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = `Search ${countLabel}…`;
    search.setAttribute('aria-label', `Search ${countLabel}`);

    const status = document.createElement('span');
    status.className = 'control-result-count';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('aria-atomic', 'true');

    const refresh = document.createElement('button');
    refresh.type = 'button';
    refresh.textContent = 'Refresh';

    const actions = document.createElement('div');
    actions.className = 'control-table-actions';
    actions.append(status, refresh);
    tools.append(search, actions);
    region.before(tools);

    const zero = document.createElement('p');
    zero.className = 'control-zero-state';
    zero.textContent = `No ${countLabel} match this search.`;
    zero.hidden = true;
    region.after(zero);

    const update = () => {
      const query = search.value.trim().toLowerCase();
      let shown = 0;
      rows.forEach((row) => {
        const visible = query === '' || row.textContent.toLowerCase().includes(query);
        row.hidden = !visible;
        if (visible) shown += 1;
      });
      status.textContent = `Showing ${shown} of ${rows.length} ${countLabel}`;
      zero.hidden = shown !== 0;

      const url = new URL(window.location.href);
      if (query === '') url.searchParams.delete('q');
      else url.searchParams.set('q', search.value.trim());
      window.history.replaceState(null, '', url);
    };

    search.addEventListener('input', update);
    refresh.addEventListener('click', () => {
      refresh.disabled = true;
      refresh.textContent = 'Refreshing…';
      tools.setAttribute('aria-busy', 'true');
      window.location.reload();
    });

    search.value = new URL(window.location.href).searchParams.get('q') || '';
    update();
  });
});

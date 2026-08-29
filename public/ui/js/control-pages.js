document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.lorkhan-card > table, .lorkhan-card > .table-responsive > table, .management-page .widget-content > table').forEach((table) => {
    if (!table.tBodies.length || table.closest('details')) return;
    const tools = document.createElement('div');
    tools.className = 'control-table-tools';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search records...';
    search.setAttribute('aria-label', 'Search records');
    const refresh = document.createElement('button');
    refresh.type = 'button';
    refresh.textContent = 'Refresh';
    refresh.addEventListener('click', () => window.location.reload());
    search.addEventListener('input', () => {
      const query = search.value.trim().toLowerCase();
      Array.from(table.tBodies[0].rows).forEach((row) => { row.hidden = Boolean(query) && !row.textContent.toLowerCase().includes(query); });
    });
    tools.append(search, refresh);
    table.before(tools);
  });
});

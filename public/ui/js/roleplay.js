document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-roleplay-panel]').forEach((panel) => {
    const search = panel.querySelector('[data-roleplay-search]');
    search?.addEventListener('input', () => {
      const query = search.value.trim().toLowerCase();
      panel.querySelectorAll('[data-roleplay-data] table tbody tr, [data-roleplay-data] .profile-card').forEach((row) => {
        row.hidden = Boolean(query) && !row.textContent.toLowerCase().includes(query);
      });
    });
    panel.querySelector('[data-roleplay-refresh]')?.addEventListener('click', () => window.location.reload());
  });
});

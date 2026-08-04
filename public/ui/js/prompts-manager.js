document.addEventListener('DOMContentLoaded', () => {
  const createPanel = document.querySelector('[data-prompt-create-panel]');
  const importPanel = document.querySelector('[data-prompt-import-panel]');
  document.querySelector('[data-prompt-create]')?.addEventListener('click', () => { createPanel.hidden = false; importPanel.hidden = true; });
  document.querySelector('[data-prompt-create-close]')?.addEventListener('click', () => { createPanel.hidden = true; });
  document.querySelector('[data-prompt-import]')?.addEventListener('click', () => { importPanel.hidden = false; createPanel.hidden = true; });
  document.querySelector('[data-prompt-import-close]')?.addEventListener('click', () => { importPanel.hidden = true; });
  document.querySelectorAll('[data-prompt-edit]').forEach((button) => button.addEventListener('click', () => {
    const row = document.querySelector(`[data-prompt-editor="${CSS.escape(button.dataset.promptEdit)}"]`);
    if (row) row.hidden = !row.hidden;
  }));
  const search = document.querySelector('[data-prompt-search]');
  search?.addEventListener('input', () => {
    const query = search.value.trim().toLowerCase();
    document.querySelectorAll('[data-prompt-row]').forEach((row) => {
      const shown = !query || row.dataset.search.includes(query);
      row.hidden = !shown;
      const editor = row.nextElementSibling;
      if (!shown && editor?.matches('[data-prompt-editor]')) editor.hidden = true;
    });
  });
});

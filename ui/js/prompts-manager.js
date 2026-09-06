document.addEventListener('DOMContentLoaded', () => {
  const createPanel = document.querySelector('[data-prompt-create-panel]');
  const importPanel = document.querySelector('[data-prompt-import-panel]');
  document.querySelector('[data-prompt-create]')?.addEventListener('click', () => { createPanel.hidden = false; importPanel.hidden = true; });
  document.querySelector('[data-prompt-create-close]')?.addEventListener('click', () => { createPanel.hidden = true; });
  document.querySelector('[data-prompt-import]')?.addEventListener('click', () => { importPanel.hidden = false; createPanel.hidden = true; });
  document.querySelector('[data-prompt-import-close]')?.addEventListener('click', () => { importPanel.hidden = true; });
  document.querySelectorAll('[data-prompt-edit], [data-prompt-clear]').forEach((button) => button.addEventListener('click', () => {
    const dialog = document.getElementById(`prompt-editor-${button.dataset.promptEdit || button.dataset.promptClear}`);
    if (!dialog) return;
    const form = dialog.querySelector('[data-prompt-save]');
    const status = dialog.querySelector('[data-prompt-save-status]');
    form.reset();
    status.hidden = !button.dataset.promptClear;
    status.textContent = button.dataset.promptClear ? 'Save Custom Prompt to restore the default. The prompt document and its assignments will be kept.' : '';
    if (button.dataset.promptClear) form.elements.custom_prompt.value = '';
    dialog._promptOpener = button;
    dialog.showModal();
  }));
  document.querySelectorAll('[data-prompt-dialog]').forEach((dialog) => {
    dialog.querySelectorAll('[data-prompt-close]').forEach(button => button.addEventListener('click', () => {
      if (dialog.dataset.busy !== '1') dialog.close();
    }));
    dialog.addEventListener('cancel', event => { if (dialog.dataset.busy === '1') event.preventDefault(); });
    dialog.addEventListener('close', () => dialog._promptOpener?.focus());
    dialog.querySelector('[data-prompt-save]').addEventListener('submit', async event => {
      event.preventDefault();
      if (dialog.dataset.busy === '1') return;
      const form = event.currentTarget, body = new FormData(form);
      const status = dialog.querySelector('[data-prompt-save-status]');
      const controls = [...dialog.querySelectorAll('button,input,textarea,select')].map(control => [control,control.disabled]);
      dialog.dataset.busy = '1';
      controls.forEach(([control]) => { control.disabled = true; });
      status.hidden = false; status.textContent = 'Saving custom prompt...';
      const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 15000);
      try {
        const response = await fetch(form.action, {method:'POST',body,headers:{Accept:'application/json'},signal:controller.signal});
        const result = await response.json();
        if (!response.ok || result.ok !== true) throw new Error(result.error || `HTTP ${response.status}`);
        location.reload();
      } catch (error) {
        status.textContent = error.name === 'AbortError' ? 'Save timed out. Your draft is retained; reload before retrying if the server already saved it.'
          : error.message === 'revision_conflict' ? 'This prompt changed elsewhere. Your draft is retained; copy it before reloading.'
          : `Could not save: ${error.message}. Your draft is retained.`;
      } finally {
        clearTimeout(timeout); delete dialog.dataset.busy;
        controls.forEach(([control,disabled]) => { control.disabled = disabled; });
      }
    });
  });
  const search = document.querySelector('[data-prompt-search]');
  search?.addEventListener('input', () => {
    const query = search.value.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('[data-prompt-row]').forEach((row) => {
      const shown = !query || row.dataset.search.includes(query);
      row.hidden = !shown;
      if (shown) visible++;
    });
    const empty = document.querySelector('[data-prompt-search-empty]');
    if (empty) empty.hidden = visible > 0 || !query;
  });
});

document.addEventListener('DOMContentLoaded', () => {
  const csvForm = document.querySelector('[data-prompt-csv-form]');
  csvForm?.querySelector('input[type="file"]').addEventListener('change', event => {
    const file = event.currentTarget.files[0];
    csvForm.querySelector('[data-prompt-csv-name]').textContent = file?.name || '';
    csvForm.querySelector('button[type="submit"]').disabled = !file;
  });
  const createPanel = document.querySelector('[data-prompt-create-panel]');
  const importPanel = document.querySelector('[data-prompt-import-panel]');
  const documentTools = document.querySelector('.prompt-document-tools');
  [[createPanel, importPanel, 'create', '[name="name"]'], [importPanel, createPanel, 'import', '[name="prompt_json"]']].forEach(([panel, other, action, field]) => {
    if (!panel) return;
    document.querySelector(`[data-prompt-${action}]`)?.addEventListener('click', () => {
      panel.hidden = false; other.hidden = true; documentTools.open = false;
      panel.querySelector(field).focus();
    });
    const closePanel = () => { panel.hidden = true; documentTools.querySelector('summary').focus(); };
    panel.querySelector(`[data-prompt-${action}-close]`).addEventListener('click', closePanel);
    panel.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); closePanel(); }
    });
  });
  documentTools?.addEventListener('keydown', event => {
    if (event.key === 'Escape') { documentTools.open = false; documentTools.querySelector('summary').focus(); }
  });
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
      let partialSaved = false;
      try {
        const response = await fetch(form.action, {method:'POST',body,headers:{Accept:'application/json'},signal:controller.signal});
        const result = await response.json();
        if (!response.ok || result.ok !== true) throw new Error(result.error || `HTTP ${response.status}`);
        if (dialog.hasAttribute('data-prompt-partial')) {
          if (!Number.isInteger(result.revision) || result.revision < 1) throw new Error('Invalid save receipt');
          form.elements.expected_revision.value = String(result.revision);
          status.textContent = 'Prompt saved. Your Core Profile draft has not changed.';
          partialSaved = true;
        } else if (dialog.hasAttribute('data-prompt-inline')) {
          const key = form.elements.prompt_key.value;
          const row = [...document.querySelectorAll('[data-prompt-inline-row]')].find(item => item.dataset.promptInlineRow === key);
          const custom = String(body.get('custom_prompt') || '');
          const isCustom = custom.trim() !== '';
          const active = isCustom ? custom : dialog.querySelector('.prompt-default').textContent;
          const badge = row.querySelector('[data-inline-prompt-status]');
          badge.textContent = isCustom ? 'Custom' : 'Default';
          badge.classList.toggle('custom', isCustom); badge.classList.toggle('default', !isCustom);
          const preview = row.querySelector('[data-inline-prompt-preview]');
          preview.textContent = active.length > 150 ? active.slice(0, 147) + '...' : active;
          preview.classList.toggle('custom', isCustom);
          row.querySelector('[data-prompt-clear]').hidden = !isCustom;
          form.elements.expected_revision.value = String(result.revision);
          form.elements.custom_prompt.defaultValue = custom;
          document.querySelector('[data-inline-prompt-notice]').textContent = `Saved ${key}. Unsaved narration settings have not changed.`;
          dialog.close();
        } else location.reload();
      } catch (error) {
        status.textContent = error.name === 'AbortError' ? 'Save timed out. Your draft is retained; reload before retrying if the server already saved it.'
          : error.message === 'revision_conflict' ? 'This prompt changed elsewhere. Your draft is retained; copy it before reloading.'
          : `Could not save: ${error.message}. Your draft is retained.`;
      } finally {
        clearTimeout(timeout); delete dialog.dataset.busy;
        controls.forEach(([control,disabled]) => { control.disabled = disabled; });
        if (partialSaved) document.dispatchEvent(new CustomEvent('connector-saved', {detail:{snapshotCurrent:true}}));
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

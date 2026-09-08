(() => {
    'use strict';
    const row = document.querySelector('[data-core-presets]');
    if (!row) return;
    const form = row.closest('form');
    const select = document.getElementById('profile-preset-select');
    const status = document.getElementById('profile-preset-status');
    const dialog = document.getElementById('profile-preset-dialog');
    const name = document.getElementById('profile-preset-name');
    const error = document.getElementById('profile-preset-error');
    const confirm = document.getElementById('profile-preset-confirm');
    const cancel = document.getElementById('profile-preset-cancel');
    const file = document.getElementById('profile-preset-file');
    const buttons = Array.from(row.querySelectorAll('[data-core-preset-action]'));
    let busy = false, operation = '', opener = null, imported = null;
    document.body.append(dialog);
    row.addEventListener('input', event => event.stopPropagation());
    row.addEventListener('change', event => event.stopPropagation());

    // Match the reference feedback tones and expose long messages through the tooltip.
    const setStatus = (message, tone = '') => {
        status.textContent = message;
        status.classList.toggle('is-error', tone === 'error');
        status.classList.toggle('is-success', tone === 'success');
        status.title = message;
    };

    const updateButtons = () => {
        row.setAttribute('aria-busy', String(busy));
        dialog.setAttribute('aria-busy', String(busy));
        select.disabled = busy;
        buttons.forEach(button => { button.disabled = busy || (!select.value && ['apply','overwrite','export'].includes(button.dataset.corePresetAction)); });
        confirm.disabled = cancel.disabled = name.disabled = busy;
    };
    // Every action uses the same authenticated form boundary and explicit installation/revision scope.
    const request = async action => {
        const body = ['save_new','overwrite'].includes(action) ? new URLSearchParams(new FormData(form))
            : new URLSearchParams({_csrf:form.elements.namedItem('_csrf').value, core_profile_id:form.elements.namedItem('core_profile_id').value});
        body.set('operation', action);
        body.set('installation_id', row.dataset.installation);
        body.set('preset_id', select.value);
        body.set('preset_revision', select.selectedOptions[0]?.dataset.revision || '0');
        body.set('expected_revision', row.dataset.revision);
        body.set('preset_name', name.value.trim());
        body.set('confirm', action === 'apply' ? 'Apply' : 'Overwrite');
        if (action === 'import') body.set('preset_json', JSON.stringify(imported));
        const response = await fetch(row.dataset.endpoint, {method:'POST', credentials:'same-origin', headers:{Accept:'application/json'}, body});
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'request_failed');
        return result;
    };
    const showDialog = action => {
        operation = action;
        const naming = ['save_new','import'].includes(action);
        document.getElementById('profile-preset-title').textContent = naming ? (action === 'import' ? 'Import preset' : 'Save as new preset') : `${action === 'apply' ? 'Apply' : 'Overwrite'} preset?`;
        document.getElementById('profile-preset-description').textContent = action === 'apply'
            ? `Apply “${select.selectedOptions[0].textContent}” to this profile now? Unsaved edits will be discarded. Saved name, prompt, connector assignments, slot and default status stay unchanged.`
            : action === 'overwrite' ? `Replace “${select.selectedOptions[0].textContent}” with the editable settings currently on screen, including unsaved edits? The active profile stays unchanged.`
            : action === 'import' ? 'Store this file as a named preset. It will not create or change a profile until you choose Apply.'
            : `Saves the current settings for "${form.elements.namedItem('label').value}" as a preset.`;
        document.getElementById('profile-preset-description').title = 'Presets exclude profile prompts, connector assignments and profile identity.';
        document.getElementById('profile-preset-name-field').hidden = !naming;
        name.required = naming;
        name.value = action === 'import' ? imported.name : action === 'save_new' ? `${form.elements.namedItem('label').value} preset` : '';
        error.textContent = '';
        error.hidden = !naming;
        confirm.textContent = naming ? 'Save Preset' : action === 'apply' ? 'Apply Preset' : 'Overwrite Preset';
        dialog.showModal();
        (naming ? name : cancel).focus();
        if (naming) name.select();
    };
    select.addEventListener('change', () => { updateButtons(); setStatus(select.value ? 'Saved profile settings. Nothing changes until Apply.' : ''); });
    buttons.forEach(button => button.addEventListener('click', async () => {
        opener = button;
        const action = button.dataset.corePresetAction;
        if (['save_new','overwrite'].includes(action) && !form.reportValidity()) return;
        if (action === 'import') { file.value = ''; file.click(); return; }
        if (action !== 'export') { showDialog(action); return; }
        busy = true; updateButtons(); setStatus('Exporting preset…');
        try {
            const result = await request('export');
            const url = URL.createObjectURL(new Blob([JSON.stringify(result,null,2)], {type:'application/json'}));
            const link = document.createElement('a'); link.href = url; link.download = 'lorkhan-profile-preset.json';
            document.body.append(link); link.click(); link.remove(); window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            setStatus('Preset exported.', 'success');
        } catch (failure) { setStatus(`Export failed: ${failure.message.replaceAll('_',' ')}.`, 'error'); }
        finally { busy = false; updateButtons(); }
    }));
    file.addEventListener('change', async () => {
        const selected = file.files[0]; if (!selected) return;
        try {
            if (selected.size > 262144) throw new Error('File must be under 256 KB');
            imported = JSON.parse(await selected.text());
            if (imported?.schema !== 'lorkhan.named-core-preset-file.v1' || typeof imported.name !== 'string') throw new Error('Choose an exported named Core Profile preset');
            showDialog('import');
        } catch (failure) { setStatus(`Import failed: ${failure.message}.`, 'error'); opener?.focus(); }
    });
    cancel.addEventListener('click', () => { if (!busy) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', () => opener?.focus());
    name.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); confirm.click(); } });
    confirm.addEventListener('click', async () => {
        if (busy || !name.reportValidity()) return;
        busy = true; updateButtons(); error.textContent = ''; error.hidden = !['save_new','import'].includes(operation);
        setStatus(operation === 'apply' ? 'Applying preset…' : 'Saving preset…');
        try {
            const result = await request(operation);
            if (result.applied) {
                const url = new URL(location.href); url.searchParams.set('status','preset-applied');
                // Apply deliberately discards the draft after a confirmed, successful server revision.
                form.dispatchEvent(new Event('lorkhan:discard-draft'));
                location.assign(url); return;
            }
            select.replaceChildren(new Option('Choose preset…',''), ...result.presets.map(preset => {
                const option = new Option(preset.name,preset.preset_id); option.dataset.revision = preset.revision; return option;
            }));
            select.value = result.preset_id;
            setStatus('Preset saved. Active profile unchanged.', 'success');
            dialog.close();
        } catch (failure) {
            const messages = {revision_conflict:'The preset or profile changed in another tab. Reload before applying or overwriting.',
                preset_name_exists:'A preset with that name already exists.', invalid_preset_name:'Use a unique name of up to 128 bytes. Built-in names are reserved.'};
            error.textContent = messages[failure.message] || `Preset action failed: ${failure.message.replaceAll('_',' ')}.`;
            error.hidden = false;
            setStatus('Preset action failed. Your draft is unchanged.', 'error');
        } finally { busy = false; updateButtons(); if (!dialog.open) opener?.focus(); }
    });
    updateButtons();
})();

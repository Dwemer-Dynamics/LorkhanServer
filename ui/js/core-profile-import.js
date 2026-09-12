// Herika profile import interaction with native, credential-free Core bundles.
(() => {
    const opener = document.querySelector('[data-core-import-open]');
    const dialog = document.getElementById('core-import-dialog');
    const form = document.getElementById('core-import-form');
    if (!opener || !dialog || !form) return;
    const picker = document.getElementById('core-import-file');
    const preview = document.getElementById('core-import-preview');
    const result = document.getElementById('core-import-result');
    const confirm = document.getElementById('core-import-confirm');
    let busy = false, generation = 0, imported = false;
    opener.addEventListener('click', event => {
        event.preventDefault();
        if (busy) return;
        generation++; imported = false; form.reset();
        form.elements.profile_json.value = ''; confirm.disabled = true;
        preview.hidden = true; result.hidden = true;
        dialog.showModal(); picker.focus();
    });
    dialog.querySelectorAll('[data-core-import-close]').forEach(button => button.addEventListener('click', () => {
        if (!busy) dialog.close();
    }));
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', () => { generation++; opener.focus(); });
    picker.addEventListener('change', async () => {
        const current = ++generation, file = picker.files[0];
        confirm.disabled = true; form.elements.profile_json.value = ''; preview.hidden = true; result.hidden = true;
        if (!file || imported) return;
        try {
            if (file.size > 1048576) throw new Error('Choose a profile export smaller than 1 MiB.');
            const text = await file.text(), bundle = JSON.parse(text);
            if (current !== generation) return;
            if (bundle?.schema !== 'lorkhan.core-profile-export.v1' || typeof bundle.name !== 'string' || !bundle.profile || !bundle.connectors)
                throw new Error('Choose a complete Lorkhan profile export. Older settings presets use the legacy import page.');
            form.elements.profile_json.value = text;
            preview.querySelector('div').textContent = `${bundle.name}\n${Object.keys(bundle.connectors).length} referenced connectors\nProfile settings and prompt included. Assignments below are optional.`;
            preview.hidden = false; confirm.disabled = false;
        } catch (error) {
            if (current !== generation) return;
            result.textContent = error instanceof SyntaxError ? 'Invalid JSON. Check the file format.' : error.message;
            result.hidden = false;
        }
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || imported || !form.elements.profile_json.value) return;
        busy = true;
        const body = new FormData(form), controls = [...dialog.querySelectorAll('button,input,select')];
        controls.forEach(control => { control.disabled = true; });
        result.textContent = 'Importing profile…'; result.hidden = false;
        const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 30000);
        try {
            const response = await fetch(form.action, {method:'POST', body, credentials:'same-origin', headers:{Accept:'application/json'}, signal:controller.signal});
            const data = await response.json();
            if (!response.ok || data.ok !== true || !/^[0-9a-f-]{36}$/.test(data.core_profile_id || ''))
                throw new Error(`Import failed: ${String(data.error || 'invalid server response').replaceAll('_', ' ')}.`);
            imported = true;
            const destination = new URL(opener.href);
            destination.searchParams.delete('import'); destination.searchParams.set('edit', data.core_profile_id); destination.searchParams.set('status', 'imported');
            result.textContent = `Profile imported. ${data.created_connectors} connectors created, ${data.reused_connectors} reused; ${data.migrated_npcs} NPCs reassigned. `;
            const link = document.createElement('a'); link.href = destination.href; link.textContent = 'Open imported profile'; result.append(link);
            // Leave unrelated editor draft guards intact if navigation is cancelled.
            window.location.assign(destination.href);
        } catch (error) {
            result.textContent = error.name === 'AbortError' || error instanceof TypeError || error instanceof SyntaxError
                ? 'The import response could not be confirmed. Reload the profile list before retrying; it may have completed.' : error.message;
        } finally {
            clearTimeout(timer); busy = false;
            controls.forEach(control => { control.disabled = imported && !control.hasAttribute('data-core-import-close'); });
        }
    });
})();

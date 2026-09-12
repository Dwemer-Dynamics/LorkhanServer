// Herika's editor-header Import Bio picker targets the selected NPC, not a newly created profile.
(() => {
    document.querySelectorAll('[data-npc-import-to]').forEach(button => {
        let busy = false;
        button.addEventListener('click', () => {
            if (busy) return;
            const form = document.getElementById(`management-form-profile-${button.dataset.npcImportTo}`);
            if (!form) return;
            const picker = document.createElement('input');
            picker.type = 'file'; picker.accept = '.json,application/json'; picker.hidden = true;
            document.body.append(picker);
            picker.addEventListener('cancel', () => picker.remove(), {once:true});
            picker.addEventListener('change', async () => {
                const file = picker.files[0]; picker.remove();
                if (!file || busy) return;
                let submitted = false;
                try {
                    if (file.size > 1048576) throw new Error('Choose a biography export smaller than 1 MiB.');
                    const text = await file.text(), source = JSON.parse(text);
                    if (source?.schema !== 'lorkhan.profile-export.v1' || typeof source.name !== 'string' || !source.content)
                        throw new Error('Choose a Lorkhan NPC biography export.');
                    if (!window.confirm(`Import biography from "${source.name}" to this NPC?\n\nThis replaces exported roleplay fields, voice and profile flags. The NPC name, identity, Core Profile, connectors and history stay unchanged. Unsaved editor changes are not imported.`)) return;
                    busy = true; button.disabled = true;
                    const body = new FormData();
                    body.set('_csrf', form.elements._csrf.value);
                    body.set('profile_id', button.dataset.npcImportTo);
                    body.set('base_revision', button.dataset.baseRevision);
                    body.set('profile_json', text);
                    const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 30000);
                    let response, result;
                    try {
                        submitted = true;
                        response = await fetch(button.dataset.importUrl, {method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});
                        result = await response.json();
                    } finally { clearTimeout(timer); }
                    if (!response.ok || result.ok !== true || result.profile_id !== button.dataset.npcImportTo)
                        throw new Error(`Import failed: ${String(result.error || 'invalid server response').replaceAll('_',' ')}. Reload if this NPC was changed elsewhere.`);
                    window.alert('Biography imported to this NPC. Reload to view the saved revision.');
                    const destination = new URL(window.location.href);
                    destination.searchParams.set('bio_profile', result.profile_id);
                    destination.searchParams.set('status', 'imported');
                    // Keep unrelated draft navigation guards. A cancelled reload must not repeat this import.
                    window.location.assign(destination.href);
                } catch (error) {
                    window.alert(error.name === 'AbortError' || error instanceof TypeError || (submitted && error instanceof SyntaxError)
                        ? 'The import response could not be confirmed. Reload before retrying; it may have completed.'
                        : error instanceof SyntaxError ? 'Invalid JSON. Check the file format.' : error.message);
                    busy = false; button.disabled = false;
                }
            }, {once:true});
            picker.click();
        });
    });
    const imported = new URLSearchParams(window.location.search).get('bio_profile');
    if (imported && /^[0-9a-f-]{36}$/.test(imported)) {
        const modal = document.getElementById(`management-form-profile-${imported}`)?.closest('[data-npc-modal]');
        if (modal) document.querySelector(`.npc-card[data-npc-modal-target="${CSS.escape(modal.id)}"]`)?.click();
    }
})();

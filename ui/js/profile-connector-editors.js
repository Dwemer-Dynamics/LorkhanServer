/* Reuse the connector's own save form without navigating away from a profile draft. */
(() => {
    const editors = [];
    document.querySelectorAll('[data-connector-edit]').forEach(button => {
        const card = button.closest('.connector-option-card');
        const select = card.querySelector('select');
        const panel = card.querySelector('.profile-connector-editor');
        const frame = panel.querySelector('iframe');
        let selected = select.value;
        let dirty = false;
        const entry = { isDirty: () => dirty, button, form: button.closest('form'), frame };
        editors.push(entry);

        // Snapshot only in memory: connector forms never expose credential values.
        const snapshot = doc => JSON.stringify(Array.from(doc.querySelectorAll('input,select,textarea'))
            .filter(input => input.name && input.name !== '_csrf')
            .map(input => [input.name, input.value, input.checked, input.disabled]));
        frame.addEventListener('load', () => {
            const doc = frame.contentDocument;
            if (!doc) return;
            dirty = false;
            let initial = snapshot(doc);
            let submitted = initial;
            const changed = () => { dirty = snapshot(doc) !== initial; };
            doc.addEventListener('input', changed);
            doc.addEventListener('change', changed);
            doc.addEventListener('submit', () => { submitted = snapshot(doc); dirty = true; });
            doc.addEventListener('connector-saved', event => {
                initial = event.detail?.snapshotCurrent ? snapshot(doc) : submitted;
                changed();
            });
            entry.prepareSave = () => {
                const editor = doc.querySelector('form[action$="/provider-revise"], form[action$="/connector-revise"], form[data-prompt-save]');
                if (!editor || !editor.reportValidity()) throw new Error('Check the highlighted editor fields before saving.');
                if (doc.querySelector('[data-busy="1"]')) throw new Error('Wait for the open editor to finish saving before using Save All.');
                const promptEditor = editor.hasAttribute('data-prompt-save');
                const body = new FormData(editor);
                const id = body.get('configuration_id');
                if (id !== selected) throw new Error('The open connector changed. Close and reopen its editor.');
                const values = Array.from(body).filter(([key]) => key !== '_csrf');
                const savedSnapshot = snapshot(doc);
                return {
                    id,
                    signature: JSON.stringify(values.sort(([a], [b]) => a.localeCompare(b))),
                    save: async () => {
                        const response = await fetch(editor.action, { method: 'POST', body,
                            headers: { Accept: promptEditor ? 'application/json' : 'text/html' }, credentials: 'same-origin',
                            signal: AbortSignal.timeout(30000) });
                        if (promptEditor) {
                            const result = await response.json();
                            if (!response.ok || result.ok !== true || !Number.isInteger(result.revision) || result.revision < 1) {
                                throw new Error('Prompt save failed or its revision changed. Your draft is retained; the profile has not been submitted.');
                            }
                            editor.elements.expected_revision.value = String(result.revision);
                            return;
                        }
                        const receipt = new URL(response.url);
                        if (!response.ok || !response.redirected || receipt.origin !== location.origin
                            || receipt.searchParams.get('edit') !== id || receipt.searchParams.get('status') !== 'saved') {
                            throw new Error('Connector save failed. Your unsaved fields are still open; the profile has not been submitted.');
                        }
                    },
                    markSaved: () => { initial = promptEditor ? snapshot(doc) : savedSnapshot; changed(); },
                };
            };
            // A completed navigation after Save updates names, not profile route values.
            const receipt = new URL(frame.contentWindow.location.href).searchParams;
            if (receipt.get('status') === 'saved' && receipt.get('edit') === selected) {
                const name = doc.querySelector('#llm_name, #tts_name')?.value;
                if (name) document.querySelectorAll('.connector-option-card select option').forEach(option => {
                    if (option.value === selected) option.textContent = name;
                });
            }
        });
        const close = () => {
            if (dirty && !window.confirm('Discard unsaved editor changes?')) return false;
            dirty = false;
            panel.hidden = true;
            card.classList.remove('editor-open');
            button.setAttribute('aria-expanded', 'false');
            frame.removeAttribute('src');
            return true;
        };
        button.addEventListener('click', () => {
            if (!panel.hidden) { close(); return; }
            if (!select.value) return;
            const url = new URL(button.dataset.editorUrl, window.location.href);
            url.searchParams.set('edit', select.value);
            selected = select.value;
            panel.hidden = false;
            card.classList.add('editor-open');
            button.setAttribute('aria-expanded', 'true');
            frame.src = url.href;
        });
        panel.querySelector('[data-connector-close]').addEventListener('click', () => {
            if (close()) button.focus();
        });
        select.addEventListener('change', () => {
            if (!panel.hidden && !close()) { select.value = selected; return; }
            selected = select.value;
            button.disabled = !selected;
        });
    });
    document.querySelectorAll('.core-profile-form').forEach(form => {
        let saving = false;
        let resuming = false;
        form.addEventListener('submit', async event => {
            if (resuming) return;
            const pending = editors.filter(editor => editor.form === form && editor.isDirty());
            if (!pending.length && !saving) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            if (saving) return;
            saving = true;
            const submitter = event.submitter;
            try {
                // Validate every draft before writing any; a shared connector cannot have two different drafts.
                const groups = new Map();
                for (const entry of pending) {
                    const prepared = entry.prepareSave();
                    const group = groups.get(prepared.id);
                    if (group && group[0].signature !== prepared.signature) {
                        throw new Error('The same connector has different edits in two slots. Save or discard one draft first.');
                    }
                    if (group) group.push(prepared); else groups.set(prepared.id, [prepared]);
                }
                form.inert = true;
                form.setAttribute('aria-busy', 'true');
                for (const group of groups.values()) {
                    await group[0].save();
                    group.forEach(prepared => prepared.markSaved());
                }
                form.inert = false;
                resuming = true;
                form.requestSubmit(submitter);
            } catch (error) {
                window.alert(error.name === 'TimeoutError'
                    ? 'Connector save timed out. The profile was not submitted. Check the connector before retrying.'
                    : error.message || 'Could not save connectors. Your profile draft is still open.');
            } finally {
                resuming = false;
                saving = false;
                form.inert = false;
                form.removeAttribute('aria-busy');
            }
        }, { capture: true });
    });
    window.addEventListener('beforeunload', event => {
        if (!editors.some(editor => editor.isDirty())) return;
        event.preventDefault();
        event.returnValue = '';
    });
})();

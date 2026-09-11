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
        const entry = { isDirty: () => dirty, button };
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
            doc.addEventListener('connector-saved', () => {
                initial = submitted;
                changed();
            });
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
            if (dirty && !window.confirm('Discard unsaved connector changes?')) return false;
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
        form.addEventListener('submit', event => {
            const pending = editors.find(editor => editor.isDirty());
            if (!pending) return;
            event.preventDefault();
            window.alert('Save or discard the open connector changes before saving the profile.');
            pending.button.focus();
        });
    });
    window.addEventListener('beforeunload', event => {
        if (!editors.some(editor => editor.isDirty())) return;
        event.preventDefault();
        event.returnValue = '';
    });
})();

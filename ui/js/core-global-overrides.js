/* Herika-style inline Core overrides share the advanced JSON draft and Save All. */
(() => {
    const root = document.querySelector('[data-core-overrides]');
    if (!root) return;
    const catalog = JSON.parse(root.dataset.catalog), form = root.closest('form');
    const raw = form.querySelector('#core-settings-overrides-json'), status = root.querySelector('[data-core-override-status]');
    const rows = [...root.querySelectorAll('[data-path]')];
    const read = () => {
        const value = JSON.parse(raw.value);
        if (!value || typeof value !== 'object' || Array.isArray(value)) throw Error('Metadata must be a JSON object.');
        for (const fields of Object.values(value)) if (!fields || typeof fields !== 'object' || Array.isArray(fields)) throw Error('Each metadata section must be an object.');
        for (const [path, definition] of Object.entries(catalog)) {
            const [section, key] = path.split('.');
            if (!Object.hasOwn(value[section] || {}, key)) continue;
            const item = value[section][key];
            if (definition.type === 'boolean' ? typeof item !== 'boolean'
                : definition.type === 'string' ? typeof item !== 'string' || new TextEncoder().encode(item).length > definition.maxBytes
                : !Number.isInteger(item) || item < definition.range[0] || item > definition.range[1]) throw Error('Invalid value for ' + definition.label + '.');
        }
        return value;
    };
    const error = exception => { status.textContent = exception.message; status.classList.add('error'); };
    const render = () => {
        try {
            const value = read(); raw.setCustomValidity(''); status.textContent = ''; status.classList.remove('error');
            for (const row of rows) {
                const [section,key] = row.dataset.path.split('.'), definition = catalog[row.dataset.path];
                const enabled = Object.hasOwn(value[section] || {}, key), control = row.querySelector('[data-core-override-input]');
                row.querySelector('[data-core-override-enabled]').checked = enabled; control.disabled = !enabled; control.setCustomValidity(''); row.classList.toggle('enabled', enabled);
                const current = enabled ? value[section][key] : definition.value;
                if (definition.type === 'boolean') control.checked = current;
                else if (control.value !== String(current)) control.value = String(current);
            }
        } catch (exception) { raw.setCustomValidity(exception.message); error(exception); }
    };
    for (const row of rows) {
        const toggle = row.querySelector('[data-core-override-enabled]'), control = row.querySelector('[data-core-override-input]');
        const [section,key] = row.dataset.path.split('.'), definition = catalog[row.dataset.path];
        const update = () => {
            try {
                const value = read(); control.disabled = !toggle.checked;
                control.setCustomValidity(toggle.checked && definition.type === 'string' && new TextEncoder().encode(control.value).length > definition.maxBytes ? 'Maximum 4096 UTF-8 bytes.' : '');
                if (toggle.checked && !control.checkValidity()) { status.textContent = 'Correct ' + definition.label + ' before saving.'; return; }
                if (toggle.checked) { value[section] ||= {}; value[section][key] = definition.type === 'boolean' ? control.checked : definition.type === 'integer' ? Number(control.value) : control.value; }
                else if (value[section]) { delete value[section][key]; if (!Object.keys(value[section]).length) delete value[section]; }
                raw.value = JSON.stringify(value, null, 2); raw.dispatchEvent(new Event('input', {bubbles:true})); status.textContent = 'Unsaved changes. Save All to apply these overrides.';
            } catch (exception) { error(exception); }
        };
        toggle.addEventListener('change', update);
        control.addEventListener(definition.type === 'boolean' ? 'change' : 'input', update);
        control.addEventListener('invalid', () => { root.open = true; });
    }
    raw.addEventListener('input', render);
    raw.addEventListener('invalid', () => { raw.closest('details').open = true; });
    form.addEventListener('submit', event => { try { read(); } catch (exception) { event.preventDefault(); event.stopImmediatePropagation(); error(exception); raw.closest('details').open = true; raw.focus(); } }, true);
    render();
})();

/* Recent-value selection follows HerikaServer's Global Settings browser; edits remain local until Save All. */
(() => {
    'use strict';
    const dialog = document.getElementById('filter-browse-dialog');
    if (!dialog) return;
    const search = document.getElementById('filter-browse-search');
    const list = document.getElementById('filter-browse-list');
    const feedback = document.getElementById('filter-browse-feedback');
    const status = document.getElementById('filter-browse-status');
    const save = document.getElementById('filter-browse-save');
    const titles = {locations: 'Recent Locations', items: 'Recent Items', magic: 'Recent Magic Events', event_types: 'Recent Event Types'};
    const key = value => value.trim().toLowerCase();
    let candidates = new Map(), selected = new Set(), controls = [], opener, request;

    function render() {
        list.replaceChildren();
        const query = key(search.value);
        let visible = 0;
        for (const [id, candidate] of candidates) {
            if (!key(candidate.value).includes(query)) continue;
            visible++;
            const label = document.createElement('label');
            label.className = 'filter-candidate-item';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.setAttribute('aria-label', candidate.value);
            checkbox.checked = selected.has(id);
            const main = document.createElement('span');
            main.className = 'filter-candidate-top';
            const value = document.createElement('span');
            value.className = 'filter-candidate-value';
            value.textContent = candidate.value;
            const count = document.createElement('span');
            count.className = 'filter-candidate-count';
            count.textContent = candidate.count === null ? 'Manual entry' : `${candidate.count} hits`;
            const badge = document.createElement('span');
            badge.className = 'filter-candidate-badge';
            badge.textContent = 'Selected';
            badge.hidden = !checkbox.checked;
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) selected.add(id); else selected.delete(id);
                badge.hidden = !checkbox.checked;
                status.textContent = `${visible} shown · ${selected.size} selected`;
            });
            main.append(value, count, badge);
            label.append(checkbox, main);
            list.append(label);
        }
        list.hidden = visible === 0;
        feedback.hidden = visible > 0;
        feedback.textContent = 'No matching recent values found.';
        status.textContent = `${visible} shown · ${selected.size} selected`;
    }

    for (const button of document.querySelectorAll('[data-filter-browse]')) button.addEventListener('click', async () => {
        request?.abort();
        const controller = new AbortController();
        request = controller;
        opener = button;
        const kind = button.dataset.filterBrowse;
        controls = Array.from(document.getElementsByName(button.dataset.filterField + (kind === 'event_types' ? '[]' : '')));
        if (!controls.length) return;
        const current = kind === 'event_types' ? controls.filter(control => control.checked).map(control => control.value) : controls[0].value.split(/\r\n|\r|\n/u);
        candidates = new Map();
        selected = new Set();
        for (const entry of current) if (key(entry)) {
            candidates.set(key(entry), {value: entry.trim(), count: null});
            selected.add(key(entry));
        }
        search.value = '';
        document.getElementById('filter-browse-title').textContent = titles[kind];
        document.getElementById('filter-browse-hint').textContent = kind === 'event_types'
            ? 'Supported event types with counts from the latest 5,000 recorded events. Selected types enter conversation history.'
            : 'Up to 500 values from the latest 5,000 recorded turns. Existing manual entries are retained. Selected values are excluded from context.';
        feedback.className = 'filter-modal-loading';
        feedback.textContent = 'Loading recent values…';
        feedback.hidden = false;
        list.hidden = true;
        list.replaceChildren();
        status.textContent = '';
        save.disabled = true;
        dialog.showModal();
        search.focus();
        try {
            const url = new URL(dialog.dataset.endpoint, window.location.href);
            url.searchParams.set('installation_id', dialog.dataset.installation);
            url.searchParams.set('kind', kind);
            const response = await fetch(url, {signal: controller.signal, headers: {Accept: 'application/json'}});
            if (!response.ok) throw new Error('Recent values could not be loaded. Cancel and try again; your draft is unchanged.');
            const data = await response.json();
            if (controller.signal.aborted) return;
            for (const candidate of data.items) {
                const id = key(candidate.value);
                candidates.set(id, {...candidate, value: candidates.get(id)?.value ?? candidate.value});
            }
            save.disabled = false;
            render();
        } catch (error) {
            if (controller.signal.aborted) return;
            feedback.className = 'filter-modal-error';
            feedback.textContent = error.message;
        }
    });
    search.addEventListener('input', () => { if (!save.disabled) render(); });
    document.getElementById('filter-browse-cancel').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { request?.abort(); opener?.focus(); });
    save.addEventListener('click', () => {
        if (controls[0].type === 'checkbox') {
            for (const control of controls) control.checked = selected.has(key(control.value));
        } else {
            const values = Array.from(selected, id => candidates.get(id).value);
            const content = values.join('\n');
            if (values.length > 256 || content.length > controls[0].maxLength) {
                feedback.hidden = false;
                feedback.className = 'filter-modal-error';
                feedback.textContent = 'Select at most 256 values within the field length limit.';
                return;
            }
            controls[0].value = content;
        }
        for (const control of controls) control.dispatchEvent(new Event('change', {bubbles: true}));
        dialog.close();
    });
})();

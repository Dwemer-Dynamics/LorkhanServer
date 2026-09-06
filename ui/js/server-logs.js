/* Local-only debugger controls operate on the bounded, already-redacted snapshot. */
(() => {
    const root = document.querySelector('[data-server-logs]');
    if (!root) return;
    const sections = [...root.querySelectorAll('.log-section')];
    const dialog = root.querySelector('dialog');
    const modalRows = dialog.querySelector('.log-container');
    const modalSearch = dialog.querySelector('.modal-search-input');
    let opener = null;
    let localTime = false;
    const timezoneKey = 'lorkhan_server_logs_timezone';
    try { localTime = localStorage.getItem(timezoneKey) === 'local'; } catch { /* Storage may be disabled. */ }

    // Apply search and severity together; unclassified/raw records stay discoverable.
    const filterSection = (section) => {
        const query = section.querySelector('.search-input').value.trim().toLowerCase();
        const enabled = new Set([...section.querySelectorAll('.level-filter:checked')].map(input => input.dataset.level));
        const entries = [...section.querySelectorAll('.log-entry')];
        entries.forEach(entry => {
            entry.hidden = (!!entry.dataset.level && !enabled.has(entry.dataset.level)) || !entry.textContent.toLowerCase().includes(query);
        });
        section.querySelector('[data-log-no-match]').hidden = !entries.length || entries.some(entry => !entry.hidden);
    };
    sections.forEach(section => {
        section.querySelector('.search-input').addEventListener('input', () => filterSection(section));
        section.querySelectorAll('.level-filter').forEach(input => input.addEventListener('change', () => filterSection(section)));
        section.querySelectorAll('[data-level-action]').forEach(button => button.addEventListener('click', () => {
            section.querySelectorAll('.level-filter').forEach(input => { input.checked = button.dataset.levelAction === 'all'; });
            filterSection(section);
        }));
        section.querySelector('[data-expand-log]').addEventListener('click', (event) => {
            opener = event.currentTarget;
            dialog.querySelector('h2').textContent = section.querySelector('h2').textContent;
            modalRows.replaceChildren(...[...section.querySelector('.log-container').children].map(entry => entry.cloneNode(true)));
            modalSearch.value = '';
            dialog.showModal();
            modalSearch.focus();
        });
        filterSection(section);
    });
    modalSearch.addEventListener('input', () => {
        const query = modalSearch.value.trim().toLowerCase();
        modalRows.querySelectorAll('.log-entry').forEach(entry => { entry.hidden = !entry.textContent.toLowerCase().includes(query); });
        const noMatch = modalRows.querySelector('[data-log-no-match]');
        if (noMatch) noMatch.hidden = !modalRows.querySelector('.log-entry') || !!modalRows.querySelector('.log-entry:not([hidden])');
    });
    dialog.querySelector('.close-modal').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => opener?.focus());

    const updateTimes = () => root.querySelectorAll('[data-utc]').forEach(element => {
        const time = new Date(element.dataset.utc);
        if (!Number.isFinite(time.getTime())) return;
        element.textContent = localTime ? time.toLocaleString() + ' Local' : time.toISOString().replace('T', ' ').replace(/\.\d{3}Z$/, ' UTC');
    });
    root.querySelector('[data-log-timezone]').addEventListener('click', event => {
        localTime = !localTime;
        try { localStorage.setItem(timezoneKey, localTime ? 'local' : 'utc'); } catch { /* Keep the current view usable. */ }
        event.currentTarget.querySelector('span').textContent = 'Timezone: ' + (localTime ? 'Local' : 'UTC');
        updateTimes();
        sections.forEach(filterSection);
    });
    updateTimes();
    root.querySelector('[data-log-timezone] span').textContent = 'Timezone: ' + (localTime ? 'Local' : 'UTC');
    root.querySelector('[data-download-logs]').addEventListener('click', () => {
        const chunks = [];
        sections.forEach(section => {
            chunks.push('==== ' + section.querySelector('h2').textContent + ' ====');
            section.querySelectorAll('.log-entry').forEach(entry => {
                if (!entry.hidden) chunks.push([...entry.children].map(child => child.textContent).filter(Boolean).join(' '));
            });
            chunks.push('');
        });
        const url = URL.createObjectURL(new Blob([chunks.join('\n')], {type:'text/plain;charset=utf-8'}));
        const link = document.createElement('a');
        link.href = url; link.download = 'lorkhan_logs_' + new Date().toISOString().replace(/[:.]/g, '-') + '.txt';
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        root.querySelector('[data-log-feedback]').textContent = 'Visible redacted log download requested.';
    });
})();

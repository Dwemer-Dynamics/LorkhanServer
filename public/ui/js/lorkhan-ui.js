(() => {
    const activateTab = (button) => {
        const root = button.closest('main');
        if (!root) return;
        const tabId = button.dataset.tab;
        if (!tabId) return;
        root.querySelectorAll('.tab-button').forEach((item) => {
            const active = item === button;
            item.classList.toggle('active', active);
            item.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        root.querySelectorAll('.tab-group').forEach((group) => {
            group.classList.toggle('active', group === button.closest('.tab-group'));
        });
        root.querySelectorAll('.tab-content').forEach((panel) => {
            panel.classList.toggle('active', panel.id === tabId);
        });
        const panel = document.getElementById(tabId);
        const frame = panel ? panel.querySelector('iframe[data-src]') : null;
        if (frame && (!frame.getAttribute('src') || frame.getAttribute('src') === 'about:blank')) {
            frame.setAttribute('src', frame.dataset.src || 'about:blank');
        }
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabId);
        window.history.replaceState({}, '', url);
    };

    document.querySelectorAll('.tab-button').forEach((button) => {
        button.addEventListener('click', () => activateTab(button));
    });
})();

(() => {
    document.querySelectorAll('[data-connector-options]').forEach((editor) => {
        const driver = document.getElementById(editor.dataset.driverControl || '');
        if (!(driver instanceof HTMLSelectElement)) return;
        const form = editor.closest('form');
        let defaults = {};
        try { defaults = JSON.parse(editor.dataset.connectorDefaults || '{}'); } catch (_) { defaults = {}; }
        let previousDriver = driver.value;

        const update = () => {
            if (form && driver.value !== previousDriver && defaults[driver.value]) {
                const previous = defaults[previousDriver] || {};
                const selected = defaults[driver.value];
                ['endpoint', 'model', 'voice', 'language'].forEach((name) => {
                    const control = form.elements.namedItem(name);
                    if (!control || !('value' in control)) return;
                    if (control.value === '' || control.value === String(previous[name] || '')) {
                        control.value = String(selected[name] || '');
                    }
                });
            }
            let hasFields = false;
            editor.querySelectorAll('[data-connector-driver]').forEach((set) => {
                const active = set.dataset.connectorDriver === driver.value;
                set.hidden = !active;
                set.querySelectorAll('input, select, textarea').forEach((control) => {
                    control.disabled = !active;
                    if (active) hasFields = true;
                });
            });
            const empty = editor.querySelector('[data-connector-options-empty]');
            if (empty) empty.hidden = hasFields;
            previousDriver = driver.value;
        };

        driver.addEventListener('change', update);
        update();
    });
})();

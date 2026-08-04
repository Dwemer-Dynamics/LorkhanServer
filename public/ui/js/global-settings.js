document.addEventListener('DOMContentLoaded', () => {
    const tabs = Array.from(document.querySelectorAll('[data-settings-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-settings-panel]'));
    const activate = (id, focus = false) => {
        if (!tabs.some((tab) => tab.dataset.settingsTab === id)) id = 'prompt-rechat';
        tabs.forEach((tab) => {
            const active = tab.dataset.settingsTab === id;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
            if (active && focus) tab.focus();
        });
        panels.forEach((panel) => { panel.hidden = panel.dataset.settingsPanel !== id; });
        try { sessionStorage.setItem('almsivi-global-settings-tab', id); } catch (_) {}
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab.dataset.settingsTab));
        tab.addEventListener('keydown', (event) => {
            let next = null;
            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (index - 1 + tabs.length) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next !== null) { event.preventDefault(); activate(tabs[next].dataset.settingsTab, true); }
        });
    });
    document.querySelector('[data-installation-select]')?.addEventListener('change', (event) => {
        const url = new URL(window.location.href);
        url.searchParams.set('installation_id', event.target.value);
        window.location.assign(url.toString());
    });
    let initial = 'prompt-rechat';
    try { initial = sessionStorage.getItem('almsivi-global-settings-tab') || initial; } catch (_) {}
    activate(initial);
});

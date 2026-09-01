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
        try { sessionStorage.setItem('lorkhan-global-settings-tab', id); } catch (_) {}
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
    const portability = document.getElementById('gs-portability-panel');
    if (portability) {
        portability.hidden = true;
        document.querySelectorAll('[data-gs-portability-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                portability.hidden = false;
                const disclosure = portability.querySelector(`[data-gs-disclosure="${button.dataset.gsPortabilityToggle}"]`);
                if (disclosure) {
                    disclosure.open = true;
                    disclosure.querySelector('summary')?.focus();
                }
                document.querySelectorAll('[data-gs-portability-toggle]').forEach((toggle) => {
                    toggle.setAttribute('aria-expanded', portability.hidden ? 'false' : 'true');
                });
            });
        });
    }
    document.getElementById('gs_form')?.addEventListener('invalid', (event) => {
        const panel = event.target.closest('[data-settings-panel]');
        if (panel) activate(panel.dataset.settingsPanel);
    }, true);
    document.querySelector('[data-installation-select]')?.addEventListener('change', (event) => {
        const url = new URL(window.location.href);
        url.searchParams.set('installation_id', event.target.value);
        window.location.assign(url.toString());
    });
    const translationControls = Array.from(document.querySelectorAll('[data-translation-control]'));
    if (translationControls.length > 0) {
        const byRole = (role) => translationControls.filter((control) => control.dataset.translationControl === role);
        const provider = byRole('provider')[0] || null;
        const outputs = byRole('output');
        const saveText = byRole('save')[0] || null;
        const target = byRole('target')[0] || null;
        const setEnabled = (control, enabled) => {
            if (!control) return;
            control.disabled = !enabled;
            control.closest('.provider-card')?.classList.toggle('is-dependent-off', !enabled);
        };
        const sync = () => {
            const active = provider !== null && provider.value === 'deepl';
            translationControls.forEach((control) => { if (control !== provider && control !== saveText) setEnabled(control, active); });
            const translating = active && outputs.some((output) => output.checked);
            setEnabled(saveText, translating);
            if (target) target.required = translating;
        };
        provider?.addEventListener('change', sync);
        outputs.forEach((output) => output.addEventListener('change', sync));
        sync();
    }

    let initial = 'prompt-rechat';
    try { initial = sessionStorage.getItem('lorkhan-global-settings-tab') || initial; } catch (_) {}
    activate(initial);
});

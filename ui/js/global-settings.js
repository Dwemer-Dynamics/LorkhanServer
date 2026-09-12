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
        for (let parent = event.target.parentElement; parent; parent = parent.parentElement) {
            if (parent.tagName === 'DETAILS') parent.open = true;
        }
    }, true);
    const presetRow = document.querySelector('[data-preset-endpoint]');
    const presetDialog = document.getElementById('gs-preset-dialog');
    if (presetRow && presetDialog) {
        const settingsForm = document.getElementById('gs_form');
        const select = document.getElementById('gs-named-preset');
        const name = document.getElementById('gs-preset-name');
        const error = document.getElementById('gs-preset-error');
        const status = document.getElementById('gs-preset-status');
        const confirm = document.getElementById('gs-preset-confirm');
        const cancel = document.getElementById('gs-preset-cancel');
        const buttons = Array.from(presetRow.querySelectorAll('[data-preset-operation]'));
        let operation = '', opener = null, busy = false;
        const includesProfiles = () => select.value.startsWith('builtin:') || select.selectedOptions[0]?.dataset.profiles === '1';
        const updateButtons = () => {
            select.disabled = busy;
            buttons.forEach((button) => { button.disabled = busy || (button.dataset.presetOperation === 'overwrite' && (select.value === 'default' || select.value.startsWith('builtin:'))); });
        };
        select.addEventListener('change', updateButtons);
        updateButtons();
        buttons.forEach((button) => button.addEventListener('click', () => {
            operation = button.dataset.presetOperation;
            if (operation !== 'apply' && !settingsForm.reportValidity()) return;
            opener = button;
            const title = select.selectedOptions[0].textContent;
            document.getElementById('gs-preset-title').textContent = operation === 'save_new' ? 'Save current setup as a preset' : `${operation === 'apply' ? 'Apply' : 'Overwrite'} ${title}?`;
            document.getElementById('gs-preset-description').textContent = operation === 'apply'
                ? (includesProfiles()
                    ? `This applies ${title} to Global Settings, memory scheduling and all Core Profiles in this installation (${presetRow.dataset.presetProfileCount}) immediately, and sets defaults for new Core Profiles. Unsaved edits will be lost. Existing connector assignments, service URLs, NPC overrides and profile identities are preserved. ${select.value === 'builtin:default' ? 'Default enables memory and may require a configured connector.' : ''}`
                    : 'This saves the included Global Settings and memory scheduling immediately. Unsaved edits will be lost. Connector choices, service URLs and NPC profiles stay unchanged.')
                : operation === 'overwrite' ? 'Replace this preset with the Global Settings currently on screen and all saved Core Profile settings? Unsaved global edits are included; unsaved profile edits are not. This cannot be undone. Connector bindings and NPC overrides are not captured.'
                : 'Name this preset. It stores Global Settings currently on screen, including unsaved edits, and all saved Core Profile settings. Connector bindings and NPC overrides are not captured.';
            document.getElementById('gs-preset-name-field').hidden = operation !== 'save_new';
            name.required = operation === 'save_new';
            name.value = operation === 'save_new' ? '' : title;
            error.hidden = true;
            confirm.textContent = operation === 'save_new' ? 'Save preset' : operation === 'apply' ? 'Apply' : 'Overwrite';
            presetDialog.showModal();
            (operation === 'save_new' ? name : cancel).focus();
        }));
        cancel.addEventListener('click', () => { if (!busy) presetDialog.close(); });
        presetDialog.addEventListener('cancel', (event) => { if (busy) event.preventDefault(); });
        presetDialog.addEventListener('close', () => opener?.focus());
        document.getElementById('gs-preset-dialog-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            if (busy) return;
            const body = new URLSearchParams(new FormData(settingsForm));
            body.set('operation', operation);
            body.set('preset_id', select.value);
            if (operation === 'apply' && includesProfiles()) body.set('setup_fingerprint', presetRow.dataset.presetFingerprint);
            body.set('preset_name', name.value.trim());
            body.set('preset_revision', select.selectedOptions[0].dataset.revision || '0');
            body.set('confirm', operation === 'apply' ? 'Apply' : 'Overwrite');
            busy = true; updateButtons(); confirm.disabled = true; cancel.disabled = true; error.hidden = true;
            try {
                const response = await fetch(presetRow.dataset.presetEndpoint, {method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json'}, body});
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'Could not save preset.');
                if (result.applied) {
                    const url = new URL(location.href); url.searchParams.set('status', 'preset-applied'); url.searchParams.set('preset_id', select.value); location.assign(url); return;
                }
                const custom = document.getElementById('gs-custom-presets');
                custom.replaceChildren(...result.presets.map((preset) => {
                    const option = new Option(preset.name, preset.preset_id);
                    option.dataset.revision = preset.revision;
                    option.dataset.profiles = Number(preset.profiles_included) === 1 ? '1' : '0';
                    return option;
                }));
                select.value = result.preset_id;
                status.textContent = 'Preset saved. Active settings are unchanged.';
                presetDialog.close();
            } catch (failure) {
                const messages = {revision_conflict: operation === 'apply' ? 'Settings or profiles changed since this page loaded. Reload the page before applying the preset.' : 'This preset changed in another tab. Reload before overwriting it.',
                    preset_name_exists: 'A preset with that name already exists.', invalid_preset_name: 'Use a unique name of up to 128 bytes. Built-in names are reserved.'};
                error.textContent = messages[failure.message] || `Preset was not applied: ${failure.message.replaceAll('_', ' ')}. Check connector requirements if enabling memory or translation.`;
                error.hidden = false;
            } finally { busy = false; updateButtons(); confirm.disabled = false; cancel.disabled = false; }
        });
    }
    document.querySelector('[data-installation-select]')?.addEventListener('change', (event) => {
        const url = new URL(window.location.href);
        url.searchParams.set('installation_id', event.target.value);
        window.location.assign(url.toString());
    });
    const translationControls = Array.from(document.querySelectorAll('[data-translation-control]'));
    document.querySelectorAll('.connector-availability').forEach((control) => {
        const toggle = control.querySelector('input');
        toggle.addEventListener('change', () => { control.querySelector('[data-connector-state]').textContent = toggle.checked ? 'On' : 'Off'; });
    });
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

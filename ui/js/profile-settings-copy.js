(() => {
    'use strict';
    const form = document.querySelector('[data-profile-copy-endpoint]');
    const dialog = document.getElementById('profile-copy-dialog');
    if (!form || !dialog) return;
    const buttons = Array.from(form.querySelectorAll('[data-profile-copy-setting]'));
    const cancel = dialog.querySelector('[data-profile-copy-cancel]');
    const confirm = dialog.querySelector('[data-profile-copy-confirm]');
    const result = document.getElementById('profile-copy-result');
    let opener, pending, busy = false, finished = false;
    for (const button of buttons) button.addEventListener('click', () => {
        const control = form.elements.namedItem(button.dataset.profileCopyControl);
        if (!control || !control.reportValidity()) return;
        opener = button;
        pending = {core_profile_id: form.elements.namedItem('core_profile_id').value,
            revision: Number(form.dataset.profileCopyRevision), setting: button.dataset.profileCopySetting,
            value: control.type === 'checkbox' ? control.checked : control.type === 'number' ? control.valueAsNumber : control.dataset.valueType === 'integer' ? Number(control.value) : control.value,
            confirm: 'Copy to all'};
        document.getElementById('profile-copy-description').textContent = `Copy “${button.dataset.profileCopyLabel}” from this profile to all Core Profiles in this installation? Only this setting will be saved. Other settings and unsaved edits will not change.`;
        result.hidden = true;
        confirm.hidden = false;
        cancel.textContent = 'Cancel';
        finished = false;
        dialog.showModal();
        cancel.focus();
    });
    cancel.addEventListener('click', () => { if (!busy) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', () => opener?.focus());
    confirm.addEventListener('click', async () => {
        if (busy || finished || !pending) return;
        busy = true;
        buttons.forEach(button => { button.disabled = true; });
        confirm.disabled = true;
        cancel.disabled = true;
        result.hidden = false;
        result.className = '';
        result.textContent = 'Copying setting…';
        try {
            const response = await fetch(form.dataset.profileCopyEndpoint, {method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': form.elements.namedItem('_csrf').value},
                body: JSON.stringify(pending)});
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'copy_failed');
            form.dataset.profileCopyRevision = String(data.revision);
            result.textContent = `Updated ${data.profiles_updated} of ${data.profiles_total} profiles. Other settings are unchanged.`;
            finished = true;
            confirm.hidden = true;
            cancel.textContent = 'Close';
        } catch (error) {
            result.className = 'profile-copy-error';
            result.textContent = error.message === 'revision_conflict'
                ? 'This profile changed in another editor. Reload before copying; no profiles were changed.'
                : 'Copy could not be confirmed. Check the saved profiles before retrying. ' + error.message.replaceAll('_', ' ');
        } finally {
            busy = false;
            buttons.forEach(button => { button.disabled = false; });
            confirm.disabled = false;
            cancel.disabled = false;
            cancel.focus();
        }
    });
})();

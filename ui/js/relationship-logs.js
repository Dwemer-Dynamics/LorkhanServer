/* Keep context readers and confirmation keyboard-accessible without running AI work. */
(() => {
    const root = document.querySelector('[data-relationship-log]');
    if (!root) return;
    root.querySelector('#relationship-log-type').addEventListener('change', event => event.currentTarget.form.requestSubmit());
    root.querySelector('[data-refresh-log]').addEventListener('click', () => location.reload());
    root.querySelectorAll('.rel-context-toggle').forEach(button => button.addEventListener('click', () => {
        const content = document.getElementById(button.getAttribute('aria-controls'));
        content.hidden = !content.hidden;
        button.setAttribute('aria-expanded', String(!content.hidden));
        button.textContent = content.hidden ? '📋 Show Context' : '📋 Hide Context';
    }));
    const dialog = root.querySelector('#clear-relationship-log');
    let age = 'all';
    const open = value => {
        age = value;
        dialog.querySelector('[data-clear-description]').textContent = age === 'all' ? 'Delete all completed relationship log entries for this installation?' : `Delete completed relationship log entries older than ${age}?`;
        dialog.querySelector('[data-clear-status]').hidden = true;
        dialog.showModal();
    };
    root.querySelector('[data-delete-old]')?.addEventListener('click', () => open(root.querySelector('#relationship-log-age').value));
    root.querySelector('[data-delete-all]')?.addEventListener('click', () => open('all'));
    dialog.querySelector('[data-close-clear]').addEventListener('click', () => dialog.close());
    const button = dialog.querySelector('[data-confirm-clear]');
    button.addEventListener('click', async () => {
        button.disabled = true;
        const status = dialog.querySelector('[data-clear-status]');
        status.hidden = false;
        status.textContent = 'Removing completed log entries…';
        try {
            const response = await fetch(root.dataset.clearEndpoint, { method:'POST', credentials:'same-origin',
                headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-Token':root.dataset.csrf},
                body:JSON.stringify({installation_id:root.dataset.installation,age,confirm:'Clear'}) });
            if (!response.ok) throw new Error('Could not remove logs. Reload the page and try again.');
            const result = await response.json();
            if (!Number.isInteger(result.cleared)) throw new Error('Unexpected response. Reload to check the log.');
            location.reload();
        } catch (error) { status.textContent = error.message; button.disabled = false; }
    });
})();

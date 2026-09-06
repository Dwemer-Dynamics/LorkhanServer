/* Native readers retain keyboard focus; clearing changes presentation visibility only. */
(() => {
    const root = document.querySelector('[data-request-log]');
    if (!root) return;
    root.querySelectorAll('[data-open-modal]').forEach(button => button.addEventListener('click', () => {
        document.getElementById(button.dataset.openModal)?.showModal();
    }));
    root.querySelectorAll('[data-close-modal]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    const dialog = root.querySelector('#clear-request-log');
    root.querySelector('[data-clear-log]').addEventListener('click', () => dialog.showModal());
    const clearButton = dialog.querySelector('[data-confirm-clear]');
    clearButton.addEventListener('click', async () => {
        clearButton.disabled = true;
        const status = dialog.querySelector('[data-clear-status]');
        status.hidden = false;
        status.textContent = 'Clearing completed entries…';
        try {
            const response = await fetch(root.dataset.clearEndpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': root.dataset.csrf },
                body: JSON.stringify({ installation_id: root.dataset.installation, confirm: 'Clear' }),
            });
            if (!response.ok) throw new Error('The log could not be cleared. Reload the page and try again.');
            const result = await response.json();
            if (!Number.isInteger(result.cleared)) throw new Error('Unexpected response. Reload the page to check the log.');
            window.location.reload();
        } catch (error) {
            status.textContent = error.message;
            clearButton.disabled = false;
        }
    });
})();

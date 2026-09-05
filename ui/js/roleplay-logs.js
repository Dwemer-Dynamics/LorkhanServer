/* Native dialogs preserve keyboard focus and never interpret stored log text as HTML. */
(() => {
    const root = document.querySelector('[data-log-page]');
    if (!root) return;
    root.querySelectorAll('[data-log-open]').forEach(button => button.addEventListener('click', () => {
        document.getElementById(button.dataset.logOpen)?.showModal();
    }));
    root.querySelectorAll('[data-log-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    root.querySelectorAll('[data-log-copy]').forEach(button => button.addEventListener('click', async () => {
        const dialog = button.closest('dialog');
        try {
            await navigator.clipboard.writeText(dialog.querySelector('[data-log-copy-text]').innerText);
            dialog.querySelector('[data-log-status]').textContent = 'Copied.';
        } catch {
            dialog.querySelector('[data-log-status]').textContent = 'Clipboard unavailable. Select the text to copy it manually.';
        }
    }));
})();

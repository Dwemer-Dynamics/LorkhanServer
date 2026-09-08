/* Native dialogs preserve keyboard focus and never interpret stored log text as HTML. */
(() => {
    const root = document.querySelector('[data-log-page]');
    if (!root) return;
    let previousOverflow = '';
    root.querySelectorAll('[data-log-open]').forEach(button => button.addEventListener('click', () => {
        const dialog = document.getElementById(button.dataset.logOpen);
        if (!dialog || dialog.open) return;
        previousOverflow = document.body.style.overflow;
        dialog.querySelector('[data-log-status]').textContent = '';
        dialog.showModal();
        document.body.style.overflow = 'hidden';
    }));
    root.querySelectorAll('.log-content-modal').forEach(dialog => {
        dialog.addEventListener('close', () => {
            document.body.style.overflow = previousOverflow;
        });
        dialog.addEventListener('click', event => {
            if (event.target !== dialog) return;
            const bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right
                || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
        });
    });
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

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
    root.querySelectorAll('[data-log-copy]').forEach(button => {
        const dialog = button.closest('dialog');
        const prompt = dialog.classList.contains('response-prompt-viewer');
        const label = button.textContent;
        let resetTimer;
        let generation = 0;
        // Closing invalidates pending clipboard feedback and restores the initial button state.
        const resetCopy = () => {
            generation++;
            clearTimeout(resetTimer);
            button.textContent = label;
            button.style.background = '';
            if (prompt) dialog.querySelector('[data-log-status]').textContent = '';
        };
        dialog.addEventListener('close', resetCopy);
        button.addEventListener('click', async () => {
            const current = ++generation;
            const text = dialog.querySelector('[data-log-copy-text]').innerText;
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                } else if (prompt) {
                    // A native modal makes the page inert: keep the temporary selection inside it.
                    const selection = document.createElement('textarea');
                    selection.value = text;
                    selection.style.cssText = 'position:fixed;left:-999999px';
                    dialog.append(selection);
                    try {
                        selection.focus(); selection.select();
                        if (!document.execCommand('copy')) throw new Error('Copy unavailable');
                    } finally {
                        selection.remove();
                        if (dialog.open) button.focus();
                    }
                } else throw new Error('Clipboard unavailable');
                if (current !== generation || !dialog.open) return;
                dialog.querySelector('[data-log-status]').textContent = 'Copied.';
                if (prompt) {
                    clearTimeout(resetTimer);
                    button.textContent = '✅ Copied!';
                    button.style.background = '#28a745';
                    resetTimer = setTimeout(resetCopy, 2000);
                }
            } catch {
                if (current !== generation || !dialog.open) return;
                if (prompt) window.alert('Failed to copy to clipboard');
                else dialog.querySelector('[data-log-status]').textContent = 'Clipboard unavailable. Select the text to copy it manually.';
            }
        });
    });
})();

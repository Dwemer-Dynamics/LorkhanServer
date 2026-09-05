document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-key-visibility]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.closest('.provider-body')?.querySelector('input');
            if (!(input instanceof HTMLInputElement) || input.disabled) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.textContent = show ? 'Hide' : 'Show';
        });
    });
});

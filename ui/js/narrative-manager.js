'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-narrative-manager]');
    if (!root) return;
    let trigger = null;
    let previousOverflow = '';

    root.querySelectorAll('[data-open-narrative]').forEach(button => button.addEventListener('click', () => {
        const modal = document.getElementById(button.dataset.openNarrative);
        if (!modal || modal.open) return;
        trigger = button;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        modal.querySelector('form')?.reset();
        modal.querySelectorAll('details').forEach(details => { details.open = modal.id === 'narrative-create'; });
        modal.querySelector('[name="installation_id"]')?.dispatchEvent(new Event('change'));
        modal.showModal();
        modal.querySelector('.modal-body').scrollTop = 0;
    }));
    root.querySelectorAll('.narrative-modal').forEach(modal => {
        modal.addEventListener('invalid', event => {
            const details = event.target.closest('details');
            if (details) details.open = true;
        }, true);
        modal.querySelectorAll('[data-close-narrative]').forEach(button => button.addEventListener('click', () => modal.close()));
        modal.addEventListener('close', () => {
            document.body.style.overflow = previousOverflow;
            trigger?.focus();
        });
        // Keep keyboard traversal inside the top-layer editor, including its scrolled fields.
        modal.addEventListener('keydown', event => {
            if (event.key !== 'Tab') return;
            const controls = [...modal.querySelectorAll('button,input,select,textarea,summary,a[href]')]
                .filter(control => !control.disabled && control.type !== 'hidden' && control.getClientRects().length);
            const first = controls[0], last = controls.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        });
    });

    root.querySelectorAll('[data-scope-form]').forEach(form => {
        const installation = form.elements.installation_id;
        // Restrict dependent selectors to their installation; server validation remains authoritative.
        const syncScope = () => {
            let ready = Boolean(installation.value);
            ['profile_id', 'playthrough_id'].forEach(name => {
                const select = form.elements[name];
                [...select.options].forEach(option => {
                    option.hidden = option.disabled = option.dataset.installation !== installation.value;
                });
                if (!select.selectedOptions.length || select.selectedOptions[0].disabled) {
                    select.value = [...select.options].find(option => !option.disabled)?.value || '';
                }
                ready = ready && Boolean(select.value);
            });
            form.querySelector('[type="submit"]').disabled = !ready;
        };
        installation.addEventListener('change', syncScope);
        syncScope();
    });
});

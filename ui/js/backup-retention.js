'use strict';
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('operational-retention-form');
    const dialog = document.getElementById('operational-retention-confirm');
    if (!form || !dialog) return;
    const opener = form.querySelector('[data-retention-open]');
    let approved = false;
    let returnFocus = null;
    let previousOverflow = '';

    // Both the button and implicit form submission require the same explicit confirmation.
    function confirmRetention() {
        if (!form.reportValidity() || dialog.open) return;
        dialog.querySelector('[data-retention-days]').textContent = form.elements.days.value;
        returnFocus = document.activeElement;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        dialog.showModal();
    }
    opener.addEventListener('click', confirmRetention);
    form.addEventListener('submit', event => {
        if (!approved) { event.preventDefault(); confirmRetention(); }
    });
    dialog.querySelector('[data-retention-confirm]').addEventListener('click', () => {
        approved = true;
        form.requestSubmit();
        approved = false;
    });
    dialog.querySelector('[data-retention-cancel]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        document.body.style.overflow = previousOverflow;
        (returnFocus || opener).focus();
    });
    dialog.addEventListener('keydown', event => {
        if (event.key !== 'Tab') return;
        const controls = [...dialog.querySelectorAll('button')];
        const first = controls[0], last = controls.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
});

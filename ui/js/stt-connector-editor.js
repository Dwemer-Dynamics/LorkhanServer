/* Switch the global connector's draft locally, preserving each service's unsaved values. */
(() => {
    const form = document.getElementById('stt-form');
    const driver = document.getElementById('stt-driver');
    if (!form || !driver) return;
    const panels = [...form.querySelectorAll('[data-stt-driver-fields]')];
    const cards = [...document.querySelectorAll('[data-stt-driver-card]')];
    const sync = () => {
        panels.forEach(panel => {
            const active = panel.dataset.sttDriverFields === driver.value;
            panel.hidden = !active;
            panel.querySelectorAll('input, select, textarea').forEach(control => {
                control.disabled = !active || control.hasAttribute('data-stt-static-disabled');
            });
        });
        cards.forEach(card => {
            const active = card.dataset.sttDriverCard === driver.value;
            card.classList.toggle('active',active);
            if (active) card.setAttribute('aria-current','true'); else card.removeAttribute('aria-current');
        });
    };
    driver.addEventListener('change',sync);
    form.querySelectorAll('[data-stt-badge]').forEach(select => select.addEventListener('change',() => {
        const notice = select.parentElement.querySelector('[data-stt-badge-notice]');
        const configured = select.selectedOptions[0]?.dataset.configured === '1';
        notice.className = 'api-key-notice ' + (configured?'ok':'warn');
        notice.textContent = select.value === 'none' ? 'No API key selected. Some STT services require one.'
            : configured ? 'Selected API badge is configured.' : 'Selected API badge does not have a configured key yet.';
    }));
    cards.forEach(card => card.addEventListener('click',event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        driver.value = card.dataset.sttDriverCard;
        driver.dispatchEvent(new Event('change',{bubbles:true}));
    }));
    form.addEventListener('reset',() => window.setTimeout(sync,0));
    sync();
})();

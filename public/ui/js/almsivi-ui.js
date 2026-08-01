(() => {
    const activateTab = (button) => {
        const root = button.closest('main');
        if (!root) return;
        const tabId = button.dataset.tab;
        if (!tabId) return;
        root.querySelectorAll('.tab-button').forEach((item) => {
            const active = item === button;
            item.classList.toggle('active', active);
            item.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        root.querySelectorAll('.tab-group').forEach((group) => {
            group.classList.toggle('active', group === button.closest('.tab-group'));
        });
        root.querySelectorAll('.tab-content').forEach((panel) => {
            panel.classList.toggle('active', panel.id === tabId);
        });
        const panel = document.getElementById(tabId);
        const frame = panel ? panel.querySelector('iframe[data-src]') : null;
        if (frame && (!frame.getAttribute('src') || frame.getAttribute('src') === 'about:blank')) {
            frame.setAttribute('src', frame.dataset.src || 'about:blank');
        }
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabId);
        window.history.replaceState({}, '', url);
    };

    document.querySelectorAll('.tab-button').forEach((button) => {
        button.addEventListener('click', () => activateTab(button));
    });
})();

/* Match Herika's current-page search and server-side matched/paging controls. */
(() => {
    const root = document.querySelector('[data-oghma-audit]');
    if (!root) return;
    const cards = [...root.querySelectorAll('[data-search]')];
    root.querySelector('#auditSearch').addEventListener('input', event => {
        const needle = event.currentTarget.value.trim().toLowerCase();
        cards.forEach(card => { card.hidden = !card.dataset.search.includes(needle); });
        root.querySelector('[data-audit-no-match]').hidden = !cards.length || cards.some(card => !card.hidden);
    });
    root.querySelector('#matchedOnlyToggle').addEventListener('change', event => {
        const url = new URL(window.location.href);
        url.searchParams.set('matched', event.currentTarget.checked ? 'matched' : 'all');
        url.searchParams.set('page', '1');
        window.location.assign(url.toString());
    });
    root.querySelector('.per-page-select').addEventListener('change', event => event.currentTarget.form.requestSubmit());
    root.querySelectorAll('a[aria-disabled="true"]').forEach(link => link.addEventListener('click', event => event.preventDefault()));
})();

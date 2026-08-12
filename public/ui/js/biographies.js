'use strict';

document.addEventListener('DOMContentLoaded', function () {
    const rows = Array.from(document.querySelectorAll('[data-biography-row]'));
    const search = document.getElementById('biography-search');
    const noResults = document.getElementById('biography-no-results');
    let activeLetter = '';

    function applyFilters() {
        const term = (search.value || '').trim().toLowerCase();
        let visible = 0;
        rows.forEach(function (row) {
            const name = row.dataset.searchName || '';
            const show = (!activeLetter || name.startsWith(activeLetter.toLowerCase())) && (!term || name.includes(term));
            row.hidden = !show;
            if (show) visible += 1;
        });
        noResults.hidden = visible !== 0 || rows.length === 0;
    }

    document.getElementById('biography-search-button').addEventListener('click', applyFilters);
    search.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            applyFilters();
        }
    });
    document.querySelectorAll('[data-biography-letter]').forEach(function (button) {
        button.addEventListener('click', function () {
            activeLetter = button.dataset.biographyLetter || '';
            document.querySelectorAll('[data-biography-letter]').forEach(function (item) {
                item.classList.toggle('active', item === button);
            });
            applyFilters();
        });
    });

    const modal = document.getElementById('biography-edit-modal');
    const profileId = document.getElementById('biography-profile-id');
    const baseContent = document.getElementById('biography-base-content');
    const biography = document.getElementById('biography-text');
    const title = document.getElementById('biography-modal-title');
    const meta = document.getElementById('biography-profile-meta');

    function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('[data-biography-edit]').forEach(function (button) {
        button.addEventListener('click', function () {
            profileId.value = button.dataset.profileId || '';
            baseContent.value = button.dataset.baseContent || '{}';
            biography.value = button.dataset.biography || '';
            title.textContent = 'Edit NPC Entry: ' + (button.dataset.profileName || 'NPC');
            meta.textContent = 'Current revision ' + (button.dataset.revision || '0') + '. Saving creates the next typed profile revision.';
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            biography.focus();
        });
    });
    document.querySelectorAll('[data-biography-close]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });
});

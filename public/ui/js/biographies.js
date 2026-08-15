'use strict';

function initializeBiographyPage() {
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

    const editModal = document.getElementById('biography-edit-modal');
    const detailsModal = document.getElementById('biography-details-modal');
    const loadError = document.getElementById('biography-load-error');
    const templateCache = new Map();

    function closeModal(modal) {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    }

    function openModal(modal) {
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function loadTemplate(name) {
        if (templateCache.has(name)) return templateCache.get(name);
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('template', name);
        const request = fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (response) {
            if (!response.ok) throw new Error('Biography template could not be loaded.');
            return response.json();
        }).catch(function (error) {
            templateCache.delete(name);
            throw error;
        });
        templateCache.set(name, request);
        return request;
    }

    document.querySelectorAll('[data-biography-details]').forEach(function (button) {
        button.addEventListener('click', async function () {
            button.disabled = true;
            try {
                const template = await loadTemplate(button.dataset.templateName || '');
                document.getElementById('biography-details-title').textContent = 'Extended Profile: ' + template.npc_name;
                document.querySelectorAll('[data-biography-detail-field]').forEach(function (field) {
                    const value = String(template[field.dataset.biographyDetailField] || '').trim();
                    field.textContent = value || 'Not provided.';
                    field.classList.toggle('empty', value === '');
                });
                openModal(detailsModal);
            } catch (error) {
                loadError.textContent = error instanceof Error ? error.message : 'Biography template could not be loaded.';
                loadError.hidden = false;
            } finally {
                button.disabled = false;
            }
        });
    });

    const editFields = {
        'biography-template-name': 'npc_name',
        'biography-core': 'core',
        'biography-oghma-tags': 'oghma_knowledge_tags',
        'biography-text': 'npc_static_bio',
        'biography-appearance': 'appearance',
        'biography-personality': 'personality',
        'biography-relationships': 'relationships',
        'biography-occupation': 'occupation',
        'biography-skills': 'skills',
        'biography-speechstyle': 'speechstyle',
        'biography-goals': 'goals',
        'biography-voiceid': 'voiceid',
        'biography-gender': 'gender',
        'biography-race': 'race',
        'biography-refid': 'refid'
    };

    document.querySelectorAll('[data-biography-edit]').forEach(function (button) {
        button.addEventListener('click', async function () {
            button.disabled = true;
            try {
                const template = await loadTemplate(button.dataset.templateName || '');
                Object.keys(editFields).forEach(function (id) {
                    const value = template[editFields[id]];
                    document.getElementById(id).value = value === null || value === undefined ? '' : String(value);
                });
                document.getElementById('biography-modal-title').textContent = 'Edit NPC Entry: ' + template.npc_name;
                document.getElementById('biography-profile-meta').textContent = template.source === 'custom'
                    ? 'Editing the active custom override. The factory biography remains unchanged.'
                    : 'Saving creates a custom override. The factory biography remains unchanged.';
                openModal(editModal);
                document.getElementById('biography-core').focus();
            } catch (error) {
                loadError.textContent = error instanceof Error ? error.message : 'Biography template could not be loaded.';
                loadError.hidden = false;
            } finally {
                button.disabled = false;
            }
        });
    });
    document.querySelectorAll('[data-biography-close]').forEach(function (button) {
        button.addEventListener('click', function () { closeModal(editModal); });
    });
    document.querySelectorAll('[data-biography-details-close]').forEach(function (button) {
        button.addEventListener('click', function () { closeModal(detailsModal); });
    });
    [editModal, detailsModal].forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal(modal);
        });
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (editModal.classList.contains('open')) closeModal(editModal);
        if (detailsModal.classList.contains('open')) closeModal(detailsModal);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeBiographyPage);
} else {
    initializeBiographyPage();
}

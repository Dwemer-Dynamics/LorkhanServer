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
    const createModal = document.getElementById('biography-create-modal');
    const modals = [editModal, detailsModal, createModal];
    let backgroundOverflow = '';
    const loadError = document.getElementById('biography-load-error');
    const templateCache = new Map();
    const modalTriggers = new WeakMap();
    const displayTemplateName = (value) => String(value || '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();

    function focusableControls(modal) {
        return Array.from(modal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
            .filter(function (control) { return control.getClientRects().length > 0; });
    }

    function closeModal(modal) {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        if (!modals.some(item => item.classList.contains('open'))) document.body.style.overflow = backgroundOverflow;
        const trigger = modalTriggers.get(modal);
        if (trigger && trigger.isConnected) trigger.focus();
    }

    function openModal(modal, trigger, preferredFocus) {
        if (!modals.some(item => item.classList.contains('open'))) backgroundOverflow = document.body.style.overflow;
        modals.forEach(item => { if (item !== modal) { item.classList.remove('open'); item.setAttribute('aria-hidden', 'true'); } });
        document.body.style.overflow = 'hidden';
        loadError.hidden = true;
        modalTriggers.set(modal, trigger);
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        const target = preferredFocus || focusableControls(modal)[0];
        if (target) target.focus();
    }

    function loadTemplate(name, profileId) {
        const cacheKey = profileId || name;
        if (templateCache.has(cacheKey)) return templateCache.get(cacheKey);
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('template', name);
        if (profileId) {
            url.searchParams.set('profile_id', profileId);
            url.searchParams.set('installation_id', document.querySelector('#biography-edit-modal [name=installation_id]').value);
        }
        const request = fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (response) {
            if (!response.ok) throw new Error('Biography template could not be loaded.');
            return response.json();
        }).catch(function (error) {
            templateCache.delete(cacheKey);
            throw error;
        });
        templateCache.set(cacheKey, request);
        return request;
    }

    document.querySelectorAll('[data-biography-details]').forEach(function (button) {
        button.addEventListener('click', async function () {
            button.disabled = true;
            try {
                const template = await loadTemplate(button.dataset.templateName || '', button.dataset.templateProfile || '');
                document.getElementById('biography-details-title').textContent = 'Extended Profiles: ' + displayTemplateName(template.npc_name);
                document.querySelectorAll('[data-biography-detail-field]').forEach(function (field) {
                    const value = String(template[field.dataset.biographyDetailField] || '').trim();
                    field.textContent = value || 'Not provided.';
                    field.classList.toggle('empty', value === '');
                });
                openModal(detailsModal, button);
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
        'biography-display-name': 'npc_name',
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
                const template = await loadTemplate(button.dataset.templateName || '', button.dataset.templateProfile || '');
                Object.keys(editFields).forEach(function (id) {
                    const value = template[editFields[id]];
                    document.getElementById(id).value = value === null || value === undefined ? '' : String(value);
                });
                document.getElementById('biography-template-profile').value = template.profile_id || '';
                document.getElementById('biography-template-revision').value = template.current_revision || '';
                document.getElementById('biography-modal-title').textContent = 'Edit NPC Entry';
                document.getElementById('biography-profile-meta').textContent = template.source === 'installation'
                    ? 'Editing this installation template. Other installations and factory templates remain unchanged.'
                    : template.source === 'custom'
                    ? 'Editing the active custom override. The factory biography remains unchanged.'
                    : 'Saving creates a custom override. The factory biography remains unchanged.';
                openModal(editModal, button, document.getElementById('biography-core'));
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
    document.querySelectorAll('[data-biography-create]').forEach(function (button) {
        button.addEventListener('click', function () {
            createModal.querySelector('form').reset();
            openModal(createModal, button, document.getElementById('new-bio-name'));
        });
    });
    document.querySelectorAll('[data-biography-create-close]').forEach(function (button) {
        button.addEventListener('click', function () { closeModal(createModal); });
    });
    modals.forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal(modal);
        });
    });
    document.addEventListener('keydown', function (event) {
        const activeModal = modals.find(modal => modal.classList.contains('open'));
        if (!activeModal) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal(activeModal);
            return;
        }
        if (event.key !== 'Tab') return;
        const controls = focusableControls(activeModal);
        if (controls.length === 0) {
            event.preventDefault();
            return;
        }
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeBiographyPage);
} else {
    initializeBiographyPage();
}

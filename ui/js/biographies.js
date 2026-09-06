'use strict';

function initializeBiographyPage() {
    const editModal = document.getElementById('biography-edit-modal');
    const detailsModal = document.getElementById('biography-details-modal');
    const createModal = document.getElementById('biography-create-modal');
    const oghmaModal = document.getElementById('biography-oghma-modal');
    const modals = [editModal, detailsModal, createModal, oghmaModal];
    let oghmaRequest = null;
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
        if (modal === oghmaModal && oghmaRequest) oghmaRequest.abort();
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
    const oghmaSearch = document.getElementById('biography-oghma-search');
    const oghmaCategory = document.getElementById('biography-oghma-category');
    const oghmaLoading = document.getElementById('biography-oghma-loading');
    const oghmaError = document.getElementById('biography-oghma-error');
    const oghmaItems = document.getElementById('biography-oghma-items');
    const oghmaEmpty = document.getElementById('biography-oghma-empty');
    const oghmaCount = document.getElementById('biography-oghma-count');
    const oghmaPrevious = document.getElementById('biography-oghma-previous');
    const oghmaNext = document.getElementById('biography-oghma-next');
    let oghmaTemplate = null;
    let oghmaPage = 1;

    // Superseded or closed readers must never display a late response from another NPC/filter.
    async function loadBiographyOghma(page = 1) {
        if (oghmaRequest) oghmaRequest.abort();
        const request = new AbortController();
        oghmaRequest = request;
        oghmaLoading.hidden = false;
        oghmaError.hidden = true;
        oghmaEmpty.hidden = true;
        oghmaItems.replaceChildren();
        oghmaCount.textContent = '';
        oghmaPrevious.disabled = oghmaNext.disabled = true;
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('oghma', oghmaTemplate.name);
        if (oghmaTemplate.profileId) url.searchParams.set('profile_id', oghmaTemplate.profileId);
        url.searchParams.set('installation_id', document.querySelector('#biography-edit-modal [name=installation_id]').value);
        url.searchParams.set('search', oghmaSearch.value);
        url.searchParams.set('category', oghmaCategory.value);
        url.searchParams.set('page', String(page));
        try {
            const response = await fetch(url, {credentials:'same-origin', signal:request.signal, headers:{Accept:'application/json'}});
            if (!response.ok) throw new Error('Oghma knowledge could not be loaded. Try Apply Filters again.');
            const data = await response.json();
            if (request.signal.aborted || oghmaRequest !== request || !oghmaModal.classList.contains('open')) return;
            if (!Array.isArray(data.items) || !Array.isArray(data.categories)) throw new Error('Oghma returned an invalid response. Try Apply Filters again.');
            const category = oghmaCategory.value;
            oghmaCategory.replaceChildren(new Option('All Categories',''), ...data.categories.filter(Boolean).map(value => new Option(value,value)));
            oghmaCategory.value = category;
            for (const item of data.items) {
                const row = document.createElement('tr');
                const topic = row.insertCell();
                const title = document.createElement('strong'); title.textContent = item.topic; topic.append(title);
                const metadata = document.createElement('div'); metadata.className = 'oghma-topic-meta';
                for (const [field, prefix] of [['category','📁 '],['knowledge_class','🔸 '],['knowledge_class_basic','🔹 '],['tags','🏷 ']]) {
                    for (const value of String(item[field] || '').split(',').map(value => value.trim()).filter(Boolean)) {
                        const tag = document.createElement('span'); tag.className = field === 'tags' ? 'oghma-topic-tag' : 'oghma-topic-class';
                        tag.textContent = prefix + value; metadata.append(tag);
                    }
                }
                topic.append(metadata);
                const level = document.createElement('span'); level.className = 'oghma-level ' + (item.level === 'Advanced' ? 'advanced' : 'basic'); level.textContent = item.level;
                row.insertCell().append(level); row.insertCell().textContent = item.description;
                oghmaItems.append(row);
            }
            oghmaPage = data.page;
            oghmaEmpty.hidden = data.items.length !== 0;
            oghmaEmpty.textContent = data.total === 0 && (oghmaSearch.value || oghmaCategory.value)
                ? 'No knowledge articles found matching the current filters. Try adjusting your search terms or category filter.'
                : 'No accessible knowledge found for this NPC.';
            oghmaCount.textContent = data.total.toLocaleString() + ' articles · Page ' + data.page + ' of ' + data.pages;
            oghmaPrevious.disabled = data.page <= 1; oghmaNext.disabled = data.page >= data.pages;
        } catch (error) {
            if (request.signal.aborted || oghmaRequest !== request) return;
            oghmaError.textContent = error instanceof Error ? error.message : 'Oghma knowledge could not be loaded.';
            oghmaError.hidden = false;
        } finally {
            if (oghmaRequest === request) oghmaLoading.hidden = true;
        }
    }
    document.querySelectorAll('[data-biography-oghma]').forEach(button => button.addEventListener('click', function () {
        oghmaTemplate = {name:button.dataset.templateName || '', profileId:button.dataset.templateProfile || ''};
        oghmaSearch.value = ''; oghmaCategory.replaceChildren(new Option('All Categories',''));
        document.getElementById('biography-oghma-title').textContent = 'Oghma Knowledge: ' + displayTemplateName(oghmaTemplate.name);
        openModal(oghmaModal, button, oghmaSearch);
        loadBiographyOghma();
    }));
    document.getElementById('biography-oghma-filters').addEventListener('submit', event => {event.preventDefault(); loadBiographyOghma();});
    document.getElementById('biography-oghma-clear').addEventListener('click', () => {oghmaSearch.value = ''; oghmaCategory.value = ''; loadBiographyOghma();});
    oghmaPrevious.addEventListener('click', () => loadBiographyOghma(oghmaPage - 1));
    oghmaNext.addEventListener('click', () => loadBiographyOghma(oghmaPage + 1));
    document.querySelectorAll('[data-biography-oghma-close]').forEach(button => button.addEventListener('click', () => closeModal(oghmaModal)));
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

(() => {
    document.querySelectorAll('[data-route-select]').forEach((control) => {
        control.addEventListener('change', () => {
            const option = control.options[control.selectedIndex];
            if (option && option.dataset.url) window.location.assign(option.dataset.url);
        });
    });

    document.querySelectorAll('[data-json-import-target]').forEach((picker) => {
        picker.addEventListener('change', () => {
            const file = picker.files && picker.files[0];
            const target = document.getElementById(picker.getAttribute('data-json-import-target'));
            if (!file || !target) return;
            if (file.size > 1048576) {
                picker.value = '';
                window.alert('JSON imports are limited to 1 MiB.');
                return;
            }
            const reader = new FileReader();
            reader.onload = () => { target.value = typeof reader.result === 'string' ? reader.result : ''; };
            reader.onerror = () => { picker.value = ''; window.alert('The JSON file could not be read.'); };
            reader.readAsText(file, 'UTF-8');
        });
    });

    let activeModal = null;
    let lastTrigger = null;
    const closeModal = (modal) => {
        if (!modal) return;
        modal.hidden = true;
        document.body.classList.remove('npc-modal-open');
        activeModal = null;
        if (lastTrigger && typeof lastTrigger.focus === 'function') lastTrigger.focus();
    };
    const openModal = (id, trigger) => {
        const modal = document.getElementById(id);
        if (!modal) return;
        if (activeModal) closeModal(activeModal);
        lastTrigger = trigger || null;
        modal.hidden = false;
        document.body.classList.add('npc-modal-open');
        activeModal = modal;
        const close = modal.querySelector('[data-npc-modal-close]');
        if (close) close.focus();
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-npc-modal-target]');
        const interactive = event.target.closest('button, a, input, select, textarea, label');
        if (trigger && (!interactive || trigger === interactive)) {
            if (trigger.disabled) return;
            event.preventDefault();
            event.stopPropagation();
            openModal(trigger.getAttribute('data-npc-modal-target'), trigger);
            return;
        }
        const close = event.target.closest('[data-npc-modal-close]');
        if (close) {
            event.preventDefault();
            closeModal(close.closest('[data-npc-modal]'));
            return;
        }
        if (event.target.matches('[data-npc-modal]')) closeModal(event.target);
        const history = event.target.closest('[data-npc-history]');
        if (history) {
            const modal = history.closest('[data-npc-modal]');
            const revisions = modal && modal.querySelector('.revision-actions');
            if (revisions) revisions.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    document.querySelectorAll('[data-npc-editor-tabs]').forEach((tablist) => {
        const modal = tablist.closest('[data-npc-modal]');
        const buttons = [...tablist.querySelectorAll('[data-npc-editor-tab]')];
        const panels = [...(modal ? modal.querySelectorAll('[data-npc-editor-panel]') : [])];
        const activate = (name, focus = false) => {
            buttons.forEach((button) => {
                const active = button.getAttribute('data-npc-editor-tab') === name;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
                button.tabIndex = active ? 0 : -1;
                if (active && focus) button.focus();
            });
            panels.forEach((panel) => { panel.hidden = panel.getAttribute('data-npc-editor-panel') !== name; });
            try { window.localStorage.setItem('almsivi-npc-editor-tab', name); } catch (_error) {}
        };
        buttons.forEach((button) => button.addEventListener('click', () => activate(button.getAttribute('data-npc-editor-tab') || 'general')));
        tablist.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const current = Math.max(0, buttons.findIndex((button) => button.classList.contains('is-active')));
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (current + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;
            activate(buttons[next].getAttribute('data-npc-editor-tab') || 'general', true);
        });
        let initial = 'general';
        try { initial = window.localStorage.getItem('almsivi-npc-editor-tab') || initial; } catch (_error) {}
        if (!buttons.some((button) => button.getAttribute('data-npc-editor-tab') === initial)) initial = 'general';
        activate(initial);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && activeModal) closeModal(activeModal);
        const card = event.target.closest('.npc-card[data-npc-modal-target]');
        if (card && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            openModal(card.getAttribute('data-npc-modal-target'), card);
        }
    });

    const filterForm = document.querySelector('[data-npc-filter-form]');
    if (filterForm) {
        let timer = null;
        const search = filterForm.querySelector('input[name="q"]');
        const profile = filterForm.querySelector('select[name="profile"]');
        if (search) search.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => filterForm.submit(), 320);
        });
        if (profile) profile.addEventListener('change', () => filterForm.submit());
    }

    const updateNpcQuery = (name, value, resetPage = true) => {
        const url = new URL(window.location.href);
        if (value === '') url.searchParams.delete(name);
        else url.searchParams.set(name, value);
        if (resetPage) url.searchParams.delete('page');
        window.location.assign(url.toString());
    };
    document.querySelectorAll('.npc-page-link[data-page]').forEach((control) => {
        control.addEventListener('click', () => {
            if (!control.disabled) updateNpcQuery('page', control.getAttribute('data-page') || '1', false);
        });
    });
    document.querySelectorAll('.npc-letter-btn[data-letter]').forEach((control) => {
        control.addEventListener('click', () => updateNpcQuery('initial', control.getAttribute('data-letter') || ''));
    });

    const filterButton = document.querySelector('[data-filter-menu-toggle]');
    const filterMenu = document.querySelector('[data-filter-menu]');
    if (filterButton && filterMenu) {
        filterButton.addEventListener('click', () => {
            const opening = filterMenu.hidden;
            filterMenu.hidden = !opening;
            filterButton.setAttribute('aria-expanded', opening ? 'true' : 'false');
        });
        filterMenu.querySelectorAll('input[type="checkbox"]:not(:disabled)').forEach((control) => {
            control.addEventListener('change', () => filterMenu.submit());
        });
    }
    document.querySelectorAll('[data-auto-lock-form] input[type="checkbox"]').forEach((control) => {
        control.addEventListener('change', () => control.form.submit());
    });
})();

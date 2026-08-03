(() => {
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
        if (trigger) {
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
        const search = filterForm.querySelector('input[type="search"]');
        const profile = filterForm.querySelector('select[name="profile"]');
        if (search) search.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => filterForm.submit(), 320);
        });
        if (profile) profile.addEventListener('change', () => filterForm.submit());
    }

    const filterButton = document.querySelector('[data-filter-menu-toggle]');
    const filterMenu = document.querySelector('[data-filter-menu]');
    if (filterButton && filterMenu) {
        filterButton.addEventListener('click', () => {
            const opening = filterMenu.hidden;
            filterMenu.hidden = !opening;
            filterButton.setAttribute('aria-expanded', opening ? 'true' : 'false');
        });
        filterMenu.querySelectorAll('input[name="state"]').forEach((control) => {
            control.addEventListener('change', () => filterMenu.submit());
        });
    }
    document.querySelectorAll('[data-auto-lock-form] input[type="checkbox"]').forEach((control) => {
        control.addEventListener('change', () => control.form.submit());
    });
})();

'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('description-editor');
    const form = document.getElementById('description-editor-form');
    const deleteForm = document.getElementById('description-delete-form');
    const fields = {content_file:'description-plugin', record_id:'description-record', display_name:'description-name', description:'description-text'};
    let returnFocus = null;
    let previousOverflow = '';

    // Use one editor for both reference dialogs, preserving the existing save/delete contracts.
    function openEditor(entry, trigger) {
        form.reset();
        const editing = entry !== null;
        Object.entries(fields).forEach(([key,id]) => {
            const input = document.getElementById(id);
            if (editing) input.value = String(entry[key] ?? '');
            if (key === 'content_file' || key === 'record_id') input.readOnly = editing;
        });
        document.getElementById('description-editor-title').textContent = editing ? 'Edit Description' : 'Add New Description';
        document.getElementById('description-save').textContent = editing ? 'Save Changes' : 'Add Entry';
        document.getElementById('description-plugin-help').textContent = editing
            ? 'Plugin cannot be changed after creation. Create a new entry to use another plugin.'
            : 'Use the source plugin filename, such as Morrowind.esm.';
        deleteForm.hidden = !editing || entry.source !== 'custom' || !entry.description_id;
        document.getElementById('description-delete-id').value = editing ? String(entry.description_id || '') : '';
        returnFocus = trigger;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        modal.classList.add('open'); modal.setAttribute('aria-hidden','false');
        modal.querySelector('.modal-body').scrollTop = 0;
        document.getElementById('description-plugin').focus();
    }

    function closeEditor() {
        modal.classList.remove('open'); modal.setAttribute('aria-hidden','true');
        document.body.style.overflow = previousOverflow;
        if (returnFocus && returnFocus.isConnected) returnFocus.focus();
    }

    document.querySelectorAll('[data-description-create]').forEach(button => button.addEventListener('click', () => openEditor(null,button)));
    document.querySelectorAll('[data-description-edit]').forEach(button => button.addEventListener('click', () => openEditor(JSON.parse(button.dataset.descriptionEntry),button)));
    document.querySelectorAll('[data-description-cancel]').forEach(button => button.addEventListener('click',closeEditor));
    modal.addEventListener('click', event => {if (event.target === modal) closeEditor();});
    document.addEventListener('keydown', event => {
        if (!modal.classList.contains('open')) return;
        if (event.key === 'Escape') {event.preventDefault();closeEditor();return;}
        if (event.key !== 'Tab') return;
        const controls = Array.from(modal.querySelectorAll('button:not([disabled]),input:not([disabled]),textarea:not([disabled]),[tabindex="0"]')).filter(node => node.getClientRects().length > 0);
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {event.preventDefault();last.focus();}
        else if (!event.shiftKey && document.activeElement === last) {event.preventDefault();first.focus();}
    });

    document.querySelector('[data-description-installation]')?.addEventListener('change', event => event.currentTarget.form.requestSubmit());
    document.querySelectorAll('[data-description-filter]').forEach(select => select.addEventListener('change', () => {
        const url = new URL(window.location.href);
        url.searchParams.set(select.dataset.filterName,select.value);url.searchParams.delete('page');url.hash='entries';
        window.location.assign(url);
    }));
});

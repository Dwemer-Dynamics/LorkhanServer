document.addEventListener('DOMContentLoaded',()=>{
    const editModal=document.getElementById('editModal');
    const newModal=document.getElementById('newEntryModal');
    const openModal=(modal)=>{modal.hidden=false;modal.setAttribute('aria-hidden','false');document.body.classList.add('oghma-modal-open');modal.querySelector('input:not([type="hidden"]),textarea')?.focus();};
    const closeModal=(modal)=>{modal.hidden=true;modal.setAttribute('aria-hidden','true');if(editModal.hidden&&newModal.hidden)document.body.classList.remove('oghma-modal-open');};

    const editTitle=document.getElementById('edit-modal-title');
    const factoryNote=document.getElementById('edit-factory-note');
    const saveButton=document.getElementById('edit-save-button');
    const deleteButton=document.getElementById('edit-delete-button');

    document.querySelector('[data-oghma-new-open]')?.addEventListener('click',()=>openModal(newModal));
    document.querySelectorAll('[data-oghma-edit]').forEach((button)=>button.addEventListener('click',()=>{
        const row=JSON.parse(button.dataset.oghmaEdit);
        // Saving a factory row creates its custom override, so there is nothing of the user's own to delete yet.
        const factory=row.factory===true;
        const values={
            'edit-document-id':row.document_id,'delete-document-id':factory?'':row.document_id,'edit-topic':row.topic,
            'edit-title':row.title,'edit-aliases':row.aliases,'edit-content':row.content,
            'edit-knowledge-class':row.knowledge_class,'edit-basic':row.topic_desc_basic,
            'edit-basic-class':row.knowledge_class_basic,'edit-tags':row.tags,'edit-category':row.category,
        };
        Object.entries(values).forEach(([id,value])=>{const field=document.getElementById(id);if(field)field.value=value??'';});
        const topicField=document.getElementById('edit-topic');
        if(topicField)topicField.readOnly=factory;
        if(editTitle)editTitle.textContent=factory?'Edit Factory Oghma Entry':'Edit Oghma Entry';
        if(factoryNote)factoryNote.hidden=!factory;
        if(saveButton)saveButton.textContent=factory?'Save as Custom Article':'Save Changes';
        if(saveButton){if(factory)saveButton.setAttribute('aria-describedby','edit-factory-note');else saveButton.removeAttribute('aria-describedby');}
        if(deleteButton){deleteButton.hidden=factory;deleteButton.disabled=factory;}
        openModal(editModal);
    }));
    document.querySelectorAll('[data-oghma-modal-close]').forEach((button)=>button.addEventListener('click',()=>closeModal(button.closest('.modal-backdrop'))));
    [editModal,newModal].forEach((modal)=>modal?.addEventListener('click',(event)=>{if(event.target===modal)closeModal(modal);}));
    document.addEventListener('keydown',(event)=>{if(event.key!=='Escape')return;if(!editModal.hidden)closeModal(editModal);else if(!newModal.hidden)closeModal(newModal);});
    document.querySelectorAll('[data-confirm]').forEach((button)=>button.addEventListener('click',(event)=>{if(!window.confirm(button.dataset.confirm))event.preventDefault();}));
});

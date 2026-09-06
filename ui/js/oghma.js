document.addEventListener('DOMContentLoaded',()=>{
    const editModal=document.getElementById('editModal');
    const newModal=document.getElementById('newEntryModal');
    let returnFocus=null;
    // Keep keyboard focus inside the active reader and return it to its trigger on close.
    const openModal=(modal,trigger)=>{returnFocus=trigger;modal.hidden=false;modal.setAttribute('aria-hidden','false');document.body.classList.add('oghma-modal-open');modal.querySelector('.modal-body').scrollTop=0;modal.querySelector('input:not([type="hidden"]),textarea')?.focus();};
    const closeModal=(modal)=>{modal.hidden=true;modal.setAttribute('aria-hidden','true');if(editModal.hidden&&newModal.hidden)document.body.classList.remove('oghma-modal-open');if(returnFocus?.isConnected)returnFocus.focus();};

    const editTitle=document.getElementById('edit-modal-title');
    const factoryNote=document.getElementById('edit-factory-note');
    const saveButton=document.getElementById('edit-save-button');
    const deleteButton=document.getElementById('edit-delete-button');

    document.querySelector('[data-oghma-new-open]')?.addEventListener('click',event=>openModal(newModal,event.currentTarget));
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
        openModal(editModal,button);
    }));
    document.querySelectorAll('[data-oghma-modal-close]').forEach((button)=>button.addEventListener('click',()=>closeModal(button.closest('.modal-backdrop'))));
    [editModal,newModal].forEach((modal)=>modal?.addEventListener('click',(event)=>{if(event.target===modal)closeModal(modal);}));
    document.addEventListener('keydown',(event)=>{
        const modal=!editModal.hidden?editModal:(!newModal.hidden?newModal:null);
        if(!modal)return;
        if(event.key==='Escape'){event.preventDefault();closeModal(modal);return;}
        if(event.key!=='Tab')return;
        const controls=Array.from(modal.querySelectorAll('button:not([disabled]),input:not([disabled]),textarea:not([disabled])')).filter(node=>node.getClientRects().length>0);
        const first=controls[0],last=controls[controls.length-1];
        if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
        else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
    });
    document.querySelectorAll('[data-confirm]').forEach((button)=>button.addEventListener('click',(event)=>{if(!window.confirm(button.dataset.confirm))event.preventDefault();}));
});

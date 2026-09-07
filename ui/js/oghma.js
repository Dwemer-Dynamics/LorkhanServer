document.addEventListener('DOMContentLoaded',()=>{
    const editModal=document.getElementById('editModal');
    const newModal=document.getElementById('newEntryModal');
    const maintenanceModal=document.getElementById('oghmaMaintenanceModal');
    const modals=[editModal,newModal,maintenanceModal];
    const origins=new WeakMap();
    // Keep keyboard focus inside the active reader and return it to its trigger on close.
    const openModal=(modal,trigger)=>{const previous=modals.find(item=>!item.hidden);origins.set(modal,{trigger,previous});if(previous){previous.hidden=true;previous.setAttribute('aria-hidden','true');}modal.hidden=false;modal.setAttribute('aria-hidden','false');document.body.classList.add('oghma-modal-open');modal.querySelector('.modal-body').scrollTop=0;modal.querySelector(modal===maintenanceModal?'[data-oghma-modal-close]':'input:not([type="hidden"]),textarea')?.focus();};
    const closeModal=(modal)=>{modal.hidden=true;modal.setAttribute('aria-hidden','true');const origin=origins.get(modal);if(origin?.previous){origin.previous.hidden=false;origin.previous.setAttribute('aria-hidden','false');}if(modals.every(item=>item.hidden))document.body.classList.remove('oghma-modal-open');if(origin?.trigger.isConnected)origin.trigger.focus();};

    const editTitle=document.getElementById('edit-modal-title');
    const factoryNote=document.getElementById('edit-factory-note');
    const saveButton=document.getElementById('edit-save-button');
    const deleteButton=document.getElementById('edit-delete-button');

    document.querySelector('[data-oghma-new-open]')?.addEventListener('click',event=>openModal(newModal,event.currentTarget));
    document.querySelectorAll('[data-oghma-edit]').forEach((button)=>button.addEventListener('click',()=>{
        const row=JSON.parse(button.dataset.oghmaEdit);
        // Factory edits create an override; explicit Delete removes the effective topic and its older catalog versions.
        const factory=row.factory===true;
        const values={
            'edit-document-id':row.document_id,'edit-topic':row.topic,
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
        if(deleteButton)deleteButton.dataset.documentId=row.document_id;
        openModal(editModal,button);
    }));
    document.querySelectorAll('[data-oghma-modal-close]').forEach((button)=>button.addEventListener('click',()=>closeModal(button.closest('.modal-backdrop'))));
    document.querySelectorAll('[data-oghma-maintenance]').forEach(button=>button.addEventListener('click',()=>{
        const action=button.dataset.oghmaMaintenance;
        const labels={'delete-all':'Delete All Entries','factory-reset':'Factory Reset Database','delete-entry':'Delete Oghma Entry'};
        const warnings={'delete-all':'Delete every current custom and factory article in this catalog? Deleted topics stay removed during routine catalog sync and deployment.',
            'factory-reset':'Remove every custom catalog entry and restore the complete active factory catalog, including previously deleted factory topics?',
            'delete-entry':'Delete this topic, including older custom and factory versions? It will stay removed during routine catalog sync and deployment.'};
        document.getElementById('oghma-maintenance-title').textContent=labels[action];
        document.getElementById('oghma-maintenance-warning').textContent=warnings[action];
        document.getElementById('oghma-maintenance-action').value=action;
        document.getElementById('oghma-maintenance-document').value=button.dataset.documentId??'';
        document.getElementById('oghma-maintenance-confirm').value=action==='factory-reset'?'Reset':'Delete';
        document.getElementById('oghma-maintenance-submit').textContent=labels[action];
        openModal(maintenanceModal,button);
    }));
    modals.forEach((modal)=>modal?.addEventListener('click',(event)=>{if(event.target===modal)closeModal(modal);}));
    document.addEventListener('keydown',(event)=>{
        const modal=modals.find(item=>!item.hidden);
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

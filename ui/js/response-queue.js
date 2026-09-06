/* Only completed presentation entries can be removed; server guards remain authoritative. */
(() => {
    const root=document.querySelector('[data-response-queue]');
    if (!root) return;
    const dialog=root.querySelector('dialog');
    const confirm=dialog.querySelector('[data-remove-confirm]');
    const cancel=dialog.querySelector('[data-remove-cancel]');
    const status=dialog.querySelector('[data-remove-status]');
    let selected=null;
    root.querySelectorAll('[data-remove-row]').forEach(button=>button.addEventListener('click',()=>{
        selected=button;
        dialog.querySelector('[data-remove-id]').textContent=button.dataset.removeRow;
        status.hidden=true;
        dialog.showModal();
    }));
    cancel.addEventListener('click',()=>dialog.close());
    dialog.addEventListener('cancel',event=>{ if (confirm.disabled) event.preventDefault(); });
    confirm.addEventListener('click',async()=>{
        if (!selected || confirm.disabled) return;
        confirm.disabled=true; cancel.disabled=true; status.hidden=false; status.textContent='Removing log entry…';
        try {
            const rowid=Number(selected.dataset.removeRow);
            if (!Number.isSafeInteger(rowid) || rowid<1) throw new Error('This record ID cannot be safely submitted by the browser.');
            const response=await fetch(root.dataset.removeEndpoint,{method:'POST',credentials:'same-origin',
                headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-Token':root.dataset.csrf},
                body:JSON.stringify({installation_id:selected.dataset.installation,rowid,confirm:'Remove'})});
            if (!response.ok || (await response.json()).removed!==1) throw new Error('This entry could not be removed. It may be pending or already removed. Reload to check its state.');
            window.location.reload();
        } catch(error) { status.textContent=error.message; confirm.disabled=false; cancel.disabled=false; }
    });
})();

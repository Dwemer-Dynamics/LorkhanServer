document.querySelectorAll('[data-model-select]').forEach(function(select){
    // Show the chosen saved model, never a hardcoded price or a replacement route.
    function updateRecap(){
        select.closest('.qs-connector-card').querySelector('[data-model-recap]').textContent=select.selectedOptions[0]?.dataset.model||'';
    }
    select.addEventListener('change',updateRecap);
    updateRecap();
});

(() => {
    const form=document.querySelector('form[data-key-endpoint]');
    if(!form)return;
    const fields=[...form.querySelectorAll('[data-quick-key]')];
    const stt=form.querySelector('[name="stt_provider"]');
    const deepgram=form.querySelector('[data-deepgram-key]');
    const originalStt=stt?.selectedOptions[0];
    let queue=Promise.resolve(),allowSubmit=false,savingForm=false;

    // A provider-specific quick key must not silently replace another selected badge.
    function updateDeepgram(){
        const option=stt.selectedOptions[0]?.value?stt.selectedOptions[0]:originalStt;
        deepgram.hidden=option?.dataset.driver!=='deepgram';
        const mismatch=option?.dataset.credential!=='LORKHAN_TTS_DEEPGRAM_API_KEY';
        deepgram.querySelector('[data-key-badge-warning]').hidden=!mismatch;
        const input=deepgram.querySelector('[data-key-input]');
        input.disabled=deepgram.hidden||mismatch||input.dataset.locked==='1';
        deepgram.querySelector('[data-key-unhide]').disabled=input.disabled;
    }
    stt?.addEventListener('change',updateDeepgram);
    if(stt&&deepgram)updateDeepgram();

    // Serialize key writes, preserve newer drafts, and never return a saved secret to the DOM.
    function saveKey(field){
        const operation=queue.then(async()=>{
            const input=field.querySelector('[data-key-input]');
            if(input.disabled||!input.value.trim())return;
            const value=input.value,status=field.querySelector('[data-key-status]');
            status.textContent='Saving...';
            const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),10000);
            let failureMessage='';
            try{
                const response=await fetch(form.dataset.keyEndpoint,{method:'POST',credentials:'same-origin',signal:controller.signal,
                    headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':form.querySelector('[name="_csrf"]').value},
                    body:JSON.stringify({provider:field.dataset.quickKey,credential:value})});
                const result=await response.json();
                if(!response.ok||result.saved!==true){
                    failureMessage=result.error==='credential_managed_by_environment'?'This key is controlled by the server environment.':
                        response.status===401?'Session expired. Reload this page before saving.':'Key was not saved. Check the value and try again.';
                    throw new Error('key_save_failed');
                }
                const unchanged=input.value===value;
                if(unchanged){input.value='';input.type='password';field.querySelector('[data-key-unhide]').textContent='Unhide';}
                input.placeholder='Configured - leave blank to keep';status.textContent=unchanged?'Saved.':'Previous value saved. New value has not been saved yet.';
            }catch(error){
                status.textContent=error.name==='AbortError'?'Save timed out. Your pasted key is kept for retry.':
                    failureMessage||'Could not save the key. Your pasted value is kept for retry.';
                throw new Error('A key could not be saved. Check its status before continuing.');
            }finally{clearTimeout(timeout);}
        });
        queue=operation.catch(()=>{});
        return operation;
    }
    fields.forEach(field=>{
        const input=field.querySelector('[data-key-input]'),unhide=field.querySelector('[data-key-unhide]');
        field.addEventListener('focusout',event=>{if(!field.contains(event.relatedTarget))saveKey(field).catch(()=>{});});
        unhide.addEventListener('click',()=>{
            input.type=input.type==='password'?'text':'password';unhide.textContent=input.type==='password'?'Unhide':'Hide';
        });
    });

    // Flush active key drafts before the existing revisioned form save. Failed saves remain dirty.
    form.addEventListener('submit',async event=>{
        if(allowSubmit){allowSubmit=false;return;}
        event.preventDefault();
        if(savingForm)return;
        savingForm=true;
        const error=form.querySelector('[data-quickstart-error]'),button=form.querySelector('.qs-save-btn');
        error.textContent='';button.disabled=true;
        fields.forEach(field=>{field.querySelector('[data-key-input]').readOnly=true;});
        try{
            for(const field of fields)await saveKey(field);
            button.disabled=false;
            try{allowSubmit=true;form.requestSubmit();}finally{allowSubmit=false;}
        }catch(failure){error.textContent=failure.message;}
        finally{savingForm=false;button.disabled=false;fields.forEach(field=>{field.querySelector('[data-key-input]').readOnly=false;});}
    },true);
})();

// Check service reachability independently of unsaved Quickstart fields and secret autosaves.
(() => {
    const section=document.querySelector('[data-minime-endpoint]');
    if(!section)return;
    const status=section.querySelector('[role="status"]');
    async function probe(){
        status.className='qs-status';status.textContent='Checking MiniMe service...';
        try{
            const response=await fetch(section.dataset.minimeEndpoint,{method:'POST',credentials:'same-origin',
                headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-Token':section.dataset.minimeCsrf},
                body:JSON.stringify({installation_id:section.dataset.minimeInstallation}),signal:AbortSignal.timeout(6000)});
            const result=await response.json();
            if(!response.ok||typeof result.ok!=='boolean')throw new Error('probe-failed');
            status.classList.add(result.ok?'ok':'err');
            const http=Number(result.http_code)||0,latency=Number(result.latency_ms)||0;
            status.textContent=`MiniMe ${result.ok?'reachable':'not reachable'} (${http}) in ${latency} ms. ${result.message}`;
        }catch{status.classList.add('err');status.textContent='MiniMe check could not complete. Check the service and reload this page to try again.';}
    }
    if(section.dataset.minimeInstallation)probe();
    else{status.textContent='Select an installation to check MiniMe.';}
})();

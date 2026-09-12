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
            if(input.matches(':disabled')||!input.value.trim())return;
            const value=input.value,status=field.querySelector('[data-key-status]');
            status.hidden=false;status.textContent='Saving...';
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
                if(field.dataset.quickKey==='local_llm')form.elements.local_key_configured.value='1';
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
            // An all-empty key queue resolves in the original submit event's microtask checkpoint.
            // Wait for that event to finish; browsers suppress requestSubmit while it is still firing.
            await new Promise(resolve=>setTimeout(resolve,0));
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

// Local setup remains a draft until the existing transactional Quickstart form is saved.
(() => {
    const section=document.querySelector('[data-local-test]');
    if(!section)return;
    const form=section.closest('form'),panel=section.querySelector('#qs_local_llm_panel');
    const server=form.elements.local_server,url=form.elements.local_endpoint;
    const status=section.querySelector('#qs_local_llm_status'),test=section.querySelector('#qs_test_local_llm');
    const models=[...form.querySelectorAll('[data-model-select]')];
    const save=form.querySelector('.qs-save-btn'),initialDisabled=save.disabled;
    let testing=false;
    function update(){
        const local=form.elements.settings_preset.value==='builtin:local_llm';
        const player2=form.elements.player2_force_all_llm?.checked===true;
        panel.hidden=!local;panel.disabled=!local||player2;
        models.forEach(select=>{select.disabled=local||player2;});
        save.disabled=local||player2?!form.elements.core_profile_id.value:initialDisabled;
        section.querySelector('#qs_local_llm_player2_warning').hidden=!local||!player2;
        form.querySelector('[data-player2-recap]').hidden=!player2;
        form.querySelector('[data-player2-llm-note]').hidden=!player2;
        form.querySelector('[data-normal-llm-note]').hidden=local||player2;
        form.querySelector('[data-local-llm-note]').hidden=!local||player2;
        form.querySelector('[data-quick-key="openrouter"]').closest('.qs-section').hidden=player2;
        section.querySelector('#qs_settings_preset_desc').textContent=local?
            'Shorter context and replies for all Core Profiles in this installation. Configure the local model below.':
            'Default settings for all Core Profiles, with profile backfill, relationship updates, memory summaries and semantic recall enabled.';
        models[0]?.closest('.qs-connector-grid').toggleAttribute('hidden',local||player2);
        form.querySelector('[data-local-recap]').hidden=!local||player2;
        const allLocal=local&&form.elements.local_scope.value==='all';
        form.querySelector('[data-general-connector-title]').textContent=local&&!player2?'Other AI tasks:':'Other Connectors Used:';
        form.querySelector('[data-general-connector-saved]').hidden=player2||allLocal;
        const generalOverride=form.querySelector('[data-general-connector-override]');
        generalOverride.hidden=!player2&&!allLocal;
        generalOverride.textContent=player2?'Player2 Local':'Local LLM';
        form.querySelectorAll('[data-local-model]').forEach(e=>{e.textContent=form.elements.local_model.value.trim()?'Local: '+form.elements.local_model.value.trim():'Local model (name not set)';});
        form.querySelectorAll('[data-local-endpoint]').forEach(e=>{e.textContent=url.value.trim()||'Server URL not set';});
        form.querySelector('[data-default-required]')?.toggleAttribute('hidden',(local||player2)&&!!form.elements.core_profile_id.value);
        test.disabled=testing;
        try{const warning=section.querySelector('#qs-local-loopback'),host=new URL(url.value).hostname;
            warning.hidden=warning.dataset.mirrored==='1'||!['localhost','127.0.0.1','[::1]',warning.dataset.wslIp].includes(host);}
        catch{section.querySelector('#qs-local-loopback').hidden=true;}
    }
    form.querySelectorAll('[name="settings_preset"]').forEach(radio=>radio.addEventListener('change',update));
    form.elements.player2_force_all_llm?.addEventListener('change',update);
    form.querySelectorAll('[name="local_scope"]').forEach(radio=>radio.addEventListener('change',update));
    url.addEventListener('input',update);
    form.elements.local_model.addEventListener('input',update);
    section.querySelectorAll('[data-local-ip]').forEach(button=>button.addEventListener('click',()=>{
        if(!button.dataset.localIp)return;
        let endpoint;
        try{endpoint=new URL(url.value);}catch{endpoint=new URL('http://127.0.0.1:'+(server.selectedOptions[0].dataset.port||1234)+'/v1/chat/completions');}
        endpoint.hostname=button.dataset.localIp;
        if(!endpoint.port)endpoint.port=server.selectedOptions[0].dataset.port||1234;
        if(endpoint.pathname==='/')endpoint.pathname='/v1/chat/completions';
        url.value=endpoint.href;url.dispatchEvent(new Event('input',{bubbles:true}));
    }));
    server.addEventListener('change',()=>{
        const port=server.selectedOptions[0].dataset.port;
        if(port){try{const endpoint=new URL(url.value);endpoint.port=port;url.value=endpoint.href;}catch{}}
        update();
    });
    test.addEventListener('click',async()=>{
        if(testing)return;
        for(const input of panel.querySelectorAll('input,select'))if(!input.reportValidity())return;
        testing=true;test.disabled=true;status.hidden=false;status.className='qs-status qs-local-llm-status pending';status.textContent='Testing connection...';
        const setup={server_type:server.value,scope:form.elements.local_scope.value,endpoint:url.value.trim(),
            model:form.elements.local_model.value.trim(),timeout_seconds:Number(form.elements.local_timeout.value),
            disable_streaming:form.elements.local_disable_streaming.checked};
        if(form.elements.local_key_configured.value==='1')setup.credential='badge:LORKHAN_CUSTOM_QUICKSTART_LOCAL_LLM_API_KEY';
        const payload={installation_id:form.elements.installation_id.value,setup};
        const draft=panel.querySelector('[data-key-input]').value.trim();
        if(draft)payload.api_key=draft;
        try{
            const response=await fetch(section.dataset.localTest,{method:'POST',credentials:'same-origin',
                headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':form.elements._csrf.value},
                body:JSON.stringify(payload),signal:AbortSignal.timeout((setup.timeout_seconds+5)*1000)});
            const result=await response.json();
            if(!response.ok||result.ok!==true)throw new Error(response.status===429?'Please wait before testing again.':'Connection failed. Check the endpoint, model and server logs.');
            status.className='qs-status qs-local-llm-status ok';status.textContent=result.message||'Connection successful.';
        }catch(error){status.className='qs-status qs-local-llm-status err';status.textContent=error.name==='TimeoutError'?'Connection test timed out.':error.message||'Connection test failed.';}
        finally{testing=false;test.disabled=false;}
    });
    update();
})();

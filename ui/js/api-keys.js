/* Reference card editors and test dialog; stored secrets are never sent to the browser. */
(() => {
    const form=document.getElementById('api-keys-form');
    if(!form)return;
    const customKeys=document.getElementById('custom-keys'),dialog=document.getElementById('apikey-test-dialog'),testLoading=document.getElementById('apikey-test-loading');
    let queue=Promise.resolve(),draftId=0,testRequest=null,testOpener=null;
    form.noValidate=true;
    const keyInput=card=>card.querySelector('.provider-body input');
    const serial=operation=>{const pending=queue.then(operation);queue=pending.catch(()=>{});return pending;};

    // Submit only the selected operation's fields, not every unsaved key in the form.
    async function post(values,signal){
        const body=new URLSearchParams({...values,_csrf:form.elements.namedItem('_csrf').value});
        const response=await fetch(form.action,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body,signal});
        const result=await response.json().catch(()=>{throw new Error('The server returned an unreadable result. Check the connection before retrying.');});
        if(!response.ok||result.ok!==true){
            const error=new Error(response.status===401?'Session expired. Reload before continuing.':
                typeof result.message==='string'&&result.message.length<512?result.message:'The operation could not be completed.');
            error.testHttpStatus=result.test_http_status;
            throw error;
        }
        return result;
    }

    // Serialize writes and keep any newer draft when a save completes.
    function saveCard(card,explicit=false){
        return serial(async()=>{
            const input=keyInput(card),status=card.querySelector('[data-key-status]');
            if(!card.isConnected||input.disabled)return;
            const value=input.value,variable=card.dataset.variable,label=card.querySelector('[data-new-label]');
            if(variable&&card.dataset.configured!=='false'&&card.querySelector('[data-custom-display-label]')){
                const display=card.querySelector('[data-custom-display-label]');
                const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),10000);
                try{await post({action:'label',variable,display_label:display.value},controller.signal);}
                catch(error){status.textContent=error.name==='AbortError'?'Save timed out. Reload to check whether the label was saved.':error.message;throw error;}
                finally{clearTimeout(timer);}
                if(!value.trim()){status.textContent='Label saved. Saved key kept.';return;}
            }
            if(!value.trim()&&variable){if(explicit)status.textContent='No replacement entered. Saved key kept.';return;}
            if(!variable&&!explicit)return;
            if(!variable&&(!label.value.trim()||!label.checkValidity()||!value.trim())){
                status.textContent='Enter a valid label and API key before saving.';throw new Error('incomplete_key');
            }
            const name=label?.value;
            if(!variable)label.readOnly=true;
            status.textContent='Saving…';
            const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),10000);
            try{
                const result=await post(variable?{action:'set',variable,credential:value}:{add_custom:'1',custom_name:name,custom_credential:value},controller.signal);
                if(!variable){
                    if(typeof result.variable!=='string'||!/^LORKHAN_CUSTOM_[A-Z][A-Z0-9_]{0,39}_API_KEY$/.test(result.variable))throw new Error('Invalid saved-key response.');
                    card.dataset.variable=result.variable;label.value=name.trim().toUpperCase();label.readOnly=false;label.dataset.customDisplayLabel='';label.maxLength=80;label.removeAttribute('pattern');
                    label.title='Display label; connector identifier stays unchanged.';
                    input.name='credentials['+result.variable+']';
                }
                const unchanged=input.value===value;
                if(unchanged){input.value='';input.type='password';card.querySelector('[data-key-visibility]').textContent='Show';}
                input.placeholder='Configured - enter replacement';card.dataset.configured='true';
                const savedLabel=card.querySelector('[data-custom-display-label]');if(savedLabel)savedLabel.readOnly=false;
                if(card.classList.contains('custom-card'))card.classList.add('has-key');
                status.textContent=unchanged?'Saved.':'Previous value saved. Your new replacement is still unsaved.';
            }catch(error){
                status.textContent=error.name==='AbortError'?'Save timed out. The server may have saved it; your draft is kept.':
                    error instanceof TypeError?'Could not reach the server. Your draft is kept.':error.message;
                throw error;
            }finally{clearTimeout(timer);if(label&&!card.dataset.variable)label.readOnly=false;}
        });
    }

    // Preserve failed or newly added key drafts if the user navigates away.
    window.addEventListener('beforeunload',event=>{
        if([...form.querySelectorAll('[data-key-card]')].some(card=>!keyInput(card).disabled&&
            (keyInput(card).value!==''||(!card.dataset.variable&&card.querySelector('[data-new-label]')?.value)))){
            event.preventDefault();event.returnValue='';
        }
    });
    form.addEventListener('focusout',event=>{
        const card=event.target.closest('[data-key-card]');
        if(card&&!card.contains(event.relatedTarget)&&!dialog.open)saveCard(card).catch(()=>{});
    });
    form.addEventListener('input',event=>{
        const card=event.target.closest('[data-key-card]');
        if(card&&event.target===keyInput(card))card.querySelector('[data-key-status]').textContent=event.target.value.trim()
            ?card.dataset.variable?'Unsaved replacement.':'Unsaved key. Click Save to create it.':'';
    });
    document.getElementById('add-custom-key').addEventListener('click',()=>{
        const fragment=document.getElementById('custom-key-template').content.cloneNode(true),card=fragment.querySelector('[data-key-card]');
        const input=keyInput(card);input.id='custom-draft-'+(++draftId);card.querySelector('[data-custom-key-label]').htmlFor=input.id;
        const labelInput=card.querySelector('[data-new-label]');labelInput.id=input.id+'-label';card.querySelector('[data-custom-label]').htmlFor=labelInput.id;
        customKeys.append(fragment);labelInput.focus();
    });

    async function deleteCard(card){
        if(!window.confirm('Delete this custom API key? Saved connectors using it will need another key.'))return;
        if(!card.dataset.variable){card.remove();document.getElementById('add-custom-key').focus();return;}
        await serial(async()=>{
            const status=card.querySelector('[data-key-status]'),controller=new AbortController(),timer=setTimeout(()=>controller.abort(),10000);
            status.textContent='Deleting…';
            try{await post({delete_custom:card.dataset.variable},controller.signal);card.remove();document.getElementById('add-custom-key').focus();}
            catch(_){status.textContent='Key was not removed. Check the server and try again.';}
            finally{clearTimeout(timer);}
        });
    }
    form.addEventListener('click',event=>{
        const button=event.target.closest('button');if(!button)return;
        const card=button.closest('[data-key-card]');
        if(button.matches('[data-key-visibility]')){
            const input=keyInput(card);if(input.disabled)return;
            input.type=input.type==='password'?'text':'password';button.textContent=input.type==='password'?'Show':'Hide';
        }else if(button.matches('[data-save-custom]'))saveCard(card,true).catch(()=>{});
        else if(button.matches('[data-delete-draft]'))deleteCard(card);
    });
    form.addEventListener('submit',async event=>{
        event.preventDefault();const button=event.submitter;
        if(button?.name==='test_key'){runTest(button);return;}
        if(button?.name==='delete_custom'){deleteCard(button.closest('[data-key-card]'));return;}
        if(button)button.disabled=true;
        try{for(const card of form.querySelectorAll('[data-key-card]'))await saveCard(card);}
        catch(_){/* The affected card retains the draft and displays the error. */}
        finally{if(button)button.disabled=false;}
    });

    // Match the reference result hierarchy using text nodes and actual HTTP metadata only.
    function renderTestResult(message,successful,httpStatus){
        const status=document.getElementById('apikey-test-status');
        const hasHttp=Number.isInteger(httpStatus)&&httpStatus>=100&&httpStatus<=599;
        status.replaceChildren();status.className=successful?'is-success':'is-error';
        const row=document.createElement('div');row.className='apikey-test-result';
        const icon=document.createElement('span');icon.className='apikey-test-result-icon';icon.setAttribute('aria-hidden','true');icon.textContent=successful?'✔':'✖';
        const title=document.createElement('span');title.textContent=successful?'Key is valid':hasHttp?'Key failed':'Test error';
        row.append(icon,title);status.append(row);
        if(hasHttp){const metadata=document.createElement('div');metadata.className='apikey-test-http';metadata.textContent='HTTP '+httpStatus+(successful?' • Provider reachable':'');status.append(metadata);}
        if(!successful){const detail=document.createElement('div');detail.className='apikey-test-detail';detail.textContent=message;status.append(detail);}
    }
    // Authentication tests keep their result in a dismissible reader without saving or navigating.
    async function runTest(button){
        if(testRequest)return;
        const card=button.closest('[data-key-card]'),input=keyInput(card),status=document.getElementById('apikey-test-status');
        const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),10000);
        testRequest=controller;testOpener=button;
        document.getElementById('apikey-test-provider').textContent=button.dataset.testProvider||card.querySelector('.provider-title').lastElementChild.textContent.trim();
        status.textContent='Testing API key…';status.className='is-loading';testLoading.hidden=false;
        dialog.showModal();button.disabled=true;
        try{
            const result=await post({test_key:button.value,['credentials['+button.value+']']:input.value},controller.signal);
            if(testRequest!==controller||!dialog.open)return;
            renderTestResult(result.message,true,result.test_http_status);
        }catch(error){
            if(testRequest!==controller||!dialog.open)return;
            renderTestResult(error.name==='AbortError'?'The test timed out. No key was saved.':
                error instanceof TypeError?'Could not reach the server. No key was saved.':error.message,false,error.testHttpStatus);
        }finally{clearTimeout(timer);if(testRequest===controller){testRequest=null;testLoading.hidden=true;button.disabled=false;}}
    }
    document.getElementById('apikey-test-close').addEventListener('click',()=>dialog.close());
    dialog.addEventListener('close',()=>{testRequest?.abort();testRequest=null;testLoading.hidden=true;if(testOpener){testOpener.disabled=false;testOpener.focus();}});
    dialog.addEventListener('click',event=>{if(event.target===dialog){const rect=dialog.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)dialog.close();}});
    window.addEventListener('pagehide',()=>testRequest?.abort());
})();

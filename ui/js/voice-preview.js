"use strict";
(() => {
    const root = document.querySelector('[data-voice-preview-endpoint]');
    if (!root) return;
    const consent=root.querySelector('[data-voice-cloud-consent]');
    const cloudForms=[...root.querySelectorAll('form')].filter(form=>form.querySelector('[data-voice-upload-consent]'));
    consent?.addEventListener('change',()=>cloudForms.forEach(form=>{
        form.querySelector('[data-voice-upload-consent]').value=consent.checked?'1':'0';
        form.querySelector('button[type="submit"]').disabled=!consent.checked;
    }));
    cloudForms.forEach(form=>form.addEventListener('submit',event=>{if(!consent?.checked){event.preventDefault();consent?.focus();}}));
    root.querySelectorAll('[data-copy-voice]').forEach(button=>button.addEventListener('click',async()=>{
        const message=root.querySelector('[data-voice-copy-status]');message.hidden=false;
        try{await navigator.clipboard.writeText(button.dataset.copyVoice);message.textContent=`Copied ${button.dataset.copyVoice}`;}
        catch(_error){message.textContent=`Copy unavailable. Voice name: ${button.dataset.copyVoice}`;}
    }));
    const audio = document.createElement('audio'); audio.controls = true; audio.hidden = true; audio.preload = 'none';
    const status = document.createElement('p'); status.setAttribute('role', 'status');
    root.prepend(status, audio);
    let request = null, url = '';
    const stop = () => {
        if (request) request.abort(); request = null; audio.pause(); audio.removeAttribute('src'); audio.load();
        if (url) URL.revokeObjectURL(url); url = '';
    };
    root.querySelectorAll('form[action$="/forms/connector-test"]').forEach(form => form.addEventListener('submit', async event => {
        event.preventDefault(); stop();
        const run = new AbortController(); request = run;
        const data = new FormData(form); const button = form.querySelector('button'); button.disabled = true;
        status.textContent = 'Generating voice preview...'; audio.hidden = true;
        try {
            const response = await fetch(root.dataset.voicePreviewEndpoint, {
                method: 'POST', credentials: 'same-origin', signal: run.signal,
                headers: {'Content-Type':'application/json','X-CSRF-Token':data.get('_csrf'), Accept:'audio/*, application/json'},
                body: JSON.stringify({installation_id:data.get('installation_id'), configuration_id:data.get('configuration_id'),
                    voice:data.get('voice_id'), text:'Welcome to Morrowind. This is a preview of my voice.'})
            });
            if (!response.ok || !response.headers.get('content-type')?.startsWith('audio/')) throw new Error(response.status === 429
                ? 'Preview limit reached. Please wait before trying again.' : 'Voice preview failed. Check the connector, credentials and discovered voice library.');
            const blob = await response.blob(); if(run.signal.aborted) return;
            if (!blob.size) throw new Error('The provider returned empty audio.');
            url = URL.createObjectURL(blob); audio.src = url; audio.hidden = false;
            status.textContent = 'Voice preview ready.';
            audio.play().catch(() => { status.textContent = 'Press Play to hear the voice preview.'; });
        } catch(error) { if(!run.signal.aborted)status.textContent=error.message; }
        finally { button.disabled = false; }
    }));
    window.addEventListener('pagehide', stop);
})();

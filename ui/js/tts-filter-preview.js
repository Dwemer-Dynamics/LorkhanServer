"use strict";
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-profile-voice-preview]').forEach(root => {
        const play=root.querySelector('[data-filter-preview-play]');
        const select=root.querySelector('select'), desc=root.querySelector('[data-npc-voice-filter-desc]');
        const audio=root.querySelector('audio'), status=root.querySelector('[data-filter-preview-status]');
        let request=null,url='',previewKey='';
        const stop=()=>{
            request?.abort();request=null;audio.pause();audio.removeAttribute('src');audio.load();
            audio.classList.remove('is-ready');if(url)URL.revokeObjectURL(url);url='';previewKey='';
            play.disabled=!root.dataset.profile;play.classList.remove('is-loading');play.removeAttribute('aria-busy');
        };
        select.addEventListener('change',()=>{
            stop();status.textContent='';status.classList.remove('is-error');
            desc.textContent=select.selectedOptions[0]?.dataset.filterDesc||'';
        });
        for(const name of ['voice_id','core_profile_id']){
            select.form?.elements.namedItem(name)?.addEventListener('input',()=>{
                stop();status.textContent='';status.classList.remove('is-error');
            });
        }
        play.addEventListener('click',async()=>{
            const voice=select.form?.elements.namedItem('voice_id')?.value;
            const fields={profile_id:root.dataset.profile,tts_filter_preset:select.value};
            if(voice!==undefined)fields.voice_id=voice;
            const coreProfile=select.form?.elements.namedItem('core_profile_id')?.value;
            if(coreProfile!==undefined)fields.core_profile_id=coreProfile;
            const key=JSON.stringify(fields);
            if(url&&previewKey===key){audio.currentTime=0;audio.play().catch(()=>{status.textContent='Press Play to hear the preview.';});return;}
            stop(); const run=new AbortController();request=run;
            play.disabled=true;play.classList.add('is-loading');play.setAttribute('aria-busy','true');
            status.classList.remove('is-error');status.textContent='Generating voice preview...';
            try {
                const response=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',signal:run.signal,
                    headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},
                    body:JSON.stringify(fields)});
                if(!response.ok||!response.headers.get('content-type')?.startsWith('audio/'))throw new Error(response.status===429?'Preview limit reached. Try again shortly.':'Voice preview failed. Check the saved TTS connector and voice.');
                const blob=await response.blob();if(run.signal.aborted)return;
                url=URL.createObjectURL(blob);previewKey=key;audio.src=url;audio.classList.add('is-ready');status.textContent='Preview ready.';
                audio.play().catch(()=>{if(!run.signal.aborted)status.textContent='Press Play to hear the preview.';});
            }catch(error){if(!run.signal.aborted){status.textContent=error.message;status.classList.add('is-error');}}
            finally{if(request===run){play.disabled=false;play.classList.remove('is-loading');play.removeAttribute('aria-busy');}}
        });
        const modal=root.closest('[data-npc-modal]');
        if(modal)new MutationObserver(()=>{if(modal.hidden)stop();}).observe(modal,{attributes:true,attributeFilter:['hidden']});
        window.addEventListener('pagehide',stop);
    });
});

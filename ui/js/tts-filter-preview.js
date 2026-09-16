"use strict";
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-profile-voice-preview]').forEach(root => {
        const play=root.querySelector('[data-filter-preview-play]'); if(!play)return;
        const audio=root.querySelector('audio'), status=root.querySelector('[role="status"]');
        let request=null,url='';
        const stop=()=>{request?.abort();request=null;audio.pause();audio.removeAttribute('src');audio.load();if(url)URL.revokeObjectURL(url);url='';play.disabled=false;};
        root.querySelector('[data-filter-preview-stop]').addEventListener('click',()=>{stop();status.textContent='Stopped.';});
        play.addEventListener('click',async()=>{
            stop(); const run=new AbortController();request=run;play.disabled=true;status.textContent='Generating voice preview…';
            try {
                const response=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',signal:run.signal,
                    headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},
                    body:JSON.stringify({profile_id:root.dataset.profile,tts_filter_preset:root.querySelector('select').value})});
                if(!response.ok||!response.headers.get('content-type')?.startsWith('audio/'))throw new Error(response.status===429?'Preview limit reached. Try again shortly.':'Voice preview failed. Check the saved TTS connector and voice.');
                const blob=await response.blob();if(run.signal.aborted)return;
                url=URL.createObjectURL(blob);audio.src=url;audio.hidden=false;status.textContent='Preview ready.';
                audio.play().catch(()=>{status.textContent='Press Play to hear the preview.';});
            }catch(error){if(!run.signal.aborted)status.textContent=error.message;}
            finally{if(request===run)play.disabled=false;}
        });
        window.addEventListener('pagehide',stop);
    });
});

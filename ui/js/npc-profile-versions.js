// Herika version-list/detail interaction, retaining the surrounding NPC editor and its drafts.
(() => {
    const labels = {gender:'Gender',race:'Race',core:'Core',biography:'Backstory',appearance:'Appearance',personality:'Personality',relationships:'Relationships',occupation:'Occupation',skills:'Skills',speech_style:'Speech Style',goals:'Goals',oghma_knowledge_tags:'Oghma Tags',emote_moods:'Emote Moods',prompt_head:'Prompt Head',dynamic_profile:'Dynamic Profile',tags:'Tags',notes:'Notes',voice:'Voice',management:'Profile Flags',dynamic_profile_fields:'Dynamic Fields',settings_overrides:'Settings Overrides',diary:'Diary Settings'};
    const readable = value => value === undefined || value === null ? '' : typeof value === 'object' ? JSON.stringify(value,null,2) : String(value);
    document.querySelectorAll('[data-npc-versions]').forEach(dialog => {
        const opener = document.querySelector(`[data-npc-versions-open="${CSS.escape(dialog.id)}"]`);
        if (!opener) return;
        const list = dialog.querySelector('[data-versions-list]'), fields = dialog.querySelector('[data-version-fields]');
        const status = dialog.querySelector('[data-versions-status]'), form = dialog.querySelector('[data-versions-restore]');
        const close = dialog.querySelector('[data-versions-close]');
        const entries = JSON.parse(dialog.dataset.versionList || '[]');
        let controller = null, generation = 0, busy = false, restored = false;
        const select = async (entry, button) => {
            if (busy || restored) return;
            const request = ++generation; controller?.abort(); controller = new AbortController();
            const requestController = controller, timer = setTimeout(() => requestController.abort(),30000);
            list.querySelectorAll('button').forEach(item => item.setAttribute('aria-current',String(item === button)));
            form.hidden = true; fields.replaceChildren(); status.textContent = 'Fetching history…';
            try {
                const response = await fetch(`${dialog.dataset.versionUrl}/${entry.revision}`,{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});
                const data = await response.json();
                if (request !== generation) return;
                if (!response.ok || !data.content || data.revision !== Number(entry.revision)) throw new Error('Failed to load this version. Select it again to retry.');
                for (const [key,label] of Object.entries(labels)) {
                    const value = readable(data.content[key]); if (!value.trim()) continue;
                    const title = document.createElement('strong'), detail = document.createElement('div');
                    title.textContent = label; detail.textContent = value;
                    if (value !== readable(data.previous_content?.[key])) detail.classList.add('is-changed');
                    fields.append(title,detail);
                }
                const remaining=Object.fromEntries(Object.entries(data.content).filter(([key])=>!Object.hasOwn(labels,key)));
                if(Object.keys(remaining).length){const extra=document.createElement('details'),summary=document.createElement('summary'),text=document.createElement('pre');extra.className='npc-version-metadata';summary.textContent='Other saved settings';text.textContent=JSON.stringify(remaining,null,2);extra.append(summary,text);fields.append(extra);}
                form.elements.revision.value = String(entry.revision);
                dialog.querySelector('[data-version-date]').textContent = entry.created_at || 'Unknown time';
                const stale = Number(form.elements.base_revision.value) !== data.current_revision;
                form.querySelector('button').disabled = stale;
                status.textContent = stale ? 'This NPC changed since the editor opened. Reload before restoring.' : 'Highlighted fields differ from the preceding saved version.';
                form.hidden = false;
            } catch (error) {
                if (request === generation) status.textContent = error.name === 'AbortError' ? 'Loading timed out. Select the version again to retry.' : error.message;
            } finally { clearTimeout(timer); }
        };
        entries.forEach(entry => {
            const button = document.createElement('button');button.type='button';
            const title=document.createElement('strong'),date=document.createElement('small');
            title.textContent=entry.created_at?'Created '+entry.created_at:'Snapshot #'+entry.revision;date.textContent=entry.created_at || '';
            button.append(title,date);button.dataset.revision=String(entry.revision);button.title=entry.reason || 'Saved profile';
            button.addEventListener('click',()=>select(entry,button));list.append(button);
        });
        opener.addEventListener('click',event=>{event.preventDefault();dialog.showModal();close.focus();if(!restored){generation++;controller?.abort();form.hidden=true;fields.replaceChildren();status.textContent=entries.length?'Select a snapshot to view details.':'No history yet.';list.querySelectorAll('button').forEach(button=>button.removeAttribute('aria-current'));}});
        close.addEventListener('click',()=>{if(!busy)dialog.close();});
        dialog.addEventListener('keydown',event=>{if(event.key==='Escape')event.stopPropagation();});
        dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
        dialog.addEventListener('click',event=>{if(event.target!==dialog||busy)return;const box=dialog.getBoundingClientRect();if(event.clientX<box.left||event.clientX>box.right||event.clientY<box.top||event.clientY>box.bottom)dialog.close();});
        dialog.addEventListener('close',()=>{generation++;controller?.abort();opener.focus();});
        form.addEventListener('submit',async event=>{
            event.preventDefault();if(busy || restored || !form.elements.revision.value)return;
            if(!window.confirm('Restore this historical version?\n\nThis replaces saved profile content and creates a new revision. Current content remains in version history. Actor identity and Core Profile assignment stay unchanged.'))return;
            busy=true;form.querySelector('button').disabled=true;status.textContent='Restoring…';
            const abort=new AbortController(),timer=setTimeout(()=>abort.abort(),30000);
            try {
                const response=await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'},signal:abort.signal});
                const data=await response.json();
                if(!response.ok || data.ok!==true || data.profile_id!==form.elements.profile_id.value)throw new Error(`Restore failed: ${String(data.error || 'invalid server response').replaceAll('_',' ')}.`);
                restored=true;busy=false;form.hidden=true;list.querySelectorAll('button').forEach(button=>button.disabled=true);
                status.textContent='Profile restored. Reload to view the saved revision.';
                const destination=new URL(location.href);destination.searchParams.set('bio_profile',data.profile_id);destination.searchParams.set('status','restored');location.assign(destination.href);
            } catch(error) {
                status.textContent=error.name==='AbortError'||error instanceof TypeError||error instanceof SyntaxError ? 'The restore response could not be confirmed. Reload before retrying; it may have completed.' : error.message;
                busy=false;form.querySelector('button').disabled=false;
            } finally {clearTimeout(timer);}
        });
    });
})();

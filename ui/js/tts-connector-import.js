// Herika's multiple-file sidebar picker, wired to native validated imports without credential assignment.
(() => {
    const opener=document.querySelector('[data-tts-import-open]'),picker=document.getElementById('tts-import-picker');
    const form=document.getElementById('tts-quick-import'),status=document.getElementById('tts-import-status');
    if (!opener || !picker || !form || !status) return;
    let busy=false;
    opener.addEventListener('click',event=>{event.preventDefault();if(!busy)picker.click();});
    picker.addEventListener('change',async()=>{
        const files=Array.from(picker.files||[]);picker.value='';if(busy || !files.length)return;
        busy=true;opener.setAttribute('aria-disabled','true');status.hidden=false;status.setAttribute('role','status');
        let completed=0,firstId='',current='';
        try {
            if(files.length>20 || files.some(file=>file.size>1048576))throw new Error('Choose up to 20 files, at most 1 MiB each.');
            const documents=[];
            for(const file of files){
                current=file.name;const text=await file.text(),csv=/\.csv$/i.test(file.name);
                if(!csv){
                    const document=JSON.parse(text);
                    if(document?.schema!=='lorkhan.connector-export.v1' || document.kind!=='tts_provider')
                        throw new Error('Choose a TTS connector CSV or Lorkhan TTS JSON export.');
                }
                documents.push({text,csv});
            }
            for(let index=0;index<documents.length;index++){
                current=files[index].name;status.textContent=`Importing ${index+1} of ${files.length}: ${current}`;
                const body=new FormData(form);body.set(documents[index].csv?'connector_csv':'connector_json',documents[index].text);
                const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),30000);
                let response,result;
                // A lost response can follow a committed import. Never retry automatically.
                try {response=await fetch(form.action,{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});result=await response.json();}
                finally {clearTimeout(timer);}
                if(!response.ok || result.ok!==true || !/^[0-9a-f-]{36}$/.test(result.configuration_id||'')){
                    if(result.error==='tts_csv_cached_voice_requires_local_binding')throw new Error('Clear the source Zonos cached voice path and bind the local voice sample after importing.');
                    throw new Error(`The connector was not imported: ${(result.error||'invalid server response').replaceAll('_',' ')}.`);
                }
                if(!firstId)firstId=result.configuration_id;completed++;
            }
            const destination=new URL(opener.href);destination.searchParams.delete('import');destination.searchParams.set('edit',firstId);destination.searchParams.set('imported',String(completed));
            window.location.assign(destination.href);
        } catch(error){
            const message=error instanceof SyntaxError?'Invalid JSON. Check the file format.':error.name==='AbortError'||error instanceof TypeError?'The response could not be confirmed. Reload the connector list before retrying.':error.message;
            status.textContent=`${current?current+': ':''}${message}${completed?' '+completed+' confirmed imported; remaining files were not attempted.':''}`;
            status.setAttribute('role','alert');
        } finally {busy=false;opener.removeAttribute('aria-disabled');opener.focus();}
    });
})();

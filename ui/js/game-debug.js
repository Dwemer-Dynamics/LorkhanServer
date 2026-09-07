(() => {
    'use strict';
    const app=document.querySelector('[data-game-debug]');
    if(!app)return;
    const api=app.dataset.apiBase||'';
    const csrf=app.dataset.csrf||'';
    const session=app.querySelector('[data-debug-session]');
    const feedback=app.querySelector('[data-debug-feedback]');
    const history=app.querySelector('[data-debug-history]');
    const historyTable=app.querySelector('[data-debug-table]');
    const emptyHistory=app.querySelector('[data-debug-empty]');
    const count=app.querySelector('[data-debug-count]');
    const connection=document.querySelector('[data-debug-connection]');
    let busy=false;

    const selected=()=>session&&session.value?session.value:'';
    const supported=()=>{
        const option=session&&session.selectedOptions?session.selectedOptions[0]:null;
        return Boolean(option&&option.dataset.supported==='true');
    };
    const setFeedback=(text,error=false)=>{if(feedback){feedback.textContent=text;feedback.classList.toggle('is-error',error);}};
    const setEnabled=()=>{
        const enabled=Boolean(selected()&&supported()&&!busy);
        app.querySelectorAll('[data-debug-command],[data-debug-refresh]').forEach(button=>{button.disabled=!enabled;});
        if(connection){connection.textContent=!selected()?'Game offline':supported()?'Game connected':'Client update required';
            connection.className='status-pill '+(supported()?'status-success':'status-unknown');}
    };
    const cell=(text)=>{const td=document.createElement('td');td.textContent=text;return td;};
    const render=(items)=>{
        history.replaceChildren();
        historyTable.hidden=items.length===0;
        emptyHistory.hidden=items.length!==0;
        for(const item of items){
            const row=document.createElement('tr');
            row.append(cell(item.name||'Unknown'));
            row.append(cell(JSON.stringify(item.parameters||{})));
            const status=cell('');const pill=document.createElement('span');pill.className='status-pill '+
                (item.state==='succeeded'?'status-success':['failed','rejected','expired'].includes(item.state)?'status-error':'status-unknown');
            pill.textContent=String(item.state||'unknown').replaceAll('_',' ');status.append(pill);row.append(status);
            const result=item.observed&&Object.keys(item.observed).length?JSON.stringify(item.observed):item.reason_code||'—';
            const created=new Date(item.created_at||'');
            const timestamp=Number.isNaN(created.getTime())?'—':created.toLocaleString('en-GB',{timeZone:'UTC',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).replaceAll('/','-').replace(',','');
            row.append(cell(result));row.append(cell(timestamp));history.append(row);
        }
        if(count)count.textContent=`${items.length} command${items.length===1?'':'s'}`;
    };
    const refresh=async()=>{
        if(!selected()){render([]);setEnabled();return;}
        try{
            const response=await fetch(`${api}/debug-commands?session_id=${encodeURIComponent(selected())}`,{credentials:'same-origin',headers:{Accept:'application/json'}});
            const body=await response.json();if(!response.ok)throw new Error(body.error||'debug_status_failed');
            render(Array.isArray(body.items)?body.items:[]);
        }catch(error){setFeedback(error instanceof Error?error.message:'Unable to refresh debug commands.',true);}
        setEnabled();
    };
    const issue=async(name,parameters)=>{
        if(!selected()||!supported()||busy)return;
        busy=true;setEnabled();setFeedback(`Queueing ${name}…`);
        try{
            const response=await fetch(`${api}/debug-commands`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,Accept:'application/json'},body:JSON.stringify({session_id:selected(),name,parameters})});
            const body=await response.json();if(!response.ok)throw new Error(body.error||'debug_command_failed');
            setFeedback(`${name} queued for the connected game.`);await refresh();
        }catch(error){setFeedback(error instanceof Error?error.message:'Unable to queue the command.',true);}
        finally{busy=false;setEnabled();}
    };
    app.addEventListener('click',event=>{
        const button=event.target.closest('[data-debug-command],[data-debug-refresh]');if(!button)return;
        if(button.hasAttribute('data-debug-refresh')){issue('status.snapshot',{});return;}
        const parameters={};if(button.dataset.enabled!==undefined)parameters.enabled=button.dataset.enabled==='true';
        if(button.dataset.mode)parameters.mode=button.dataset.mode;
        issue(button.dataset.debugCommand,parameters);
    });
    if(session)session.addEventListener('change',()=>{setFeedback('');refresh();});
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
    setEnabled();refresh();window.setInterval(()=>{if(!document.hidden)refresh();},1000);
})();

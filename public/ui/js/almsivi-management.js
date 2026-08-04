document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('form[data-confirm]').forEach(function(form){
        form.addEventListener('submit',function(event){
            if(!window.confirm(form.dataset.confirm||'Continue?'))event.preventDefault();
        });
    });
    document.querySelectorAll('[data-installation-select]').forEach(function(select){
        select.addEventListener('change',function(){
            const url=new URL(window.location.href);url.searchParams.set('installation_id',select.value);window.location.assign(url.toString());
        });
    });
    document.querySelectorAll('[data-config-hub]').forEach(function(hub){
        const buttons=Array.from(hub.querySelectorAll('[data-tab]'));const panels=Array.from(hub.querySelectorAll('[data-tab-panel]'));const groups=Array.from(hub.querySelectorAll('[data-category]'));
        function activate(id){const button=buttons.find(function(item){return item.dataset.tab===id;});if(!button)return;const category=button.dataset.category;
            buttons.forEach(function(item){const active=item===button;item.classList.toggle('active',active);item.setAttribute('aria-selected',active?'true':'false');});
            groups.filter(function(item){return item.classList.contains('tab-group');}).forEach(function(item){item.classList.toggle('active',item.dataset.category===category);});
            panels.forEach(function(panel){const active=panel.id===id;panel.classList.toggle('active',active);if(active){const frame=panel.querySelector('iframe');if(frame&&frame.getAttribute('src')==='about:blank')frame.src=frame.dataset.src;}});
            const url=new URL(window.location.href);url.searchParams.set('tab',id);history.replaceState(null,'',url);
        }
        buttons.forEach(function(button){button.addEventListener('click',function(){activate(button.dataset.tab);});});activate(hub.dataset.activeTab||'npc');
    });
    document.querySelectorAll('[data-connector-options]').forEach(function(editor){
        const driver=document.getElementById(editor.dataset.driverControl||'');if(!(driver instanceof HTMLSelectElement))return;
        const form=editor.closest('form');let defaults={};try{defaults=JSON.parse(editor.dataset.connectorDefaults||'{}');}catch(_){defaults={};}let previous=driver.value;
        function update(){if(form&&driver.value!==previous&&defaults[driver.value]){const before=defaults[previous]||{};const selected=defaults[driver.value];['endpoint','model','voice','language'].forEach(function(name){const control=form.elements.namedItem(name);if(control&&('value' in control)&&(control.value===''||control.value===String(before[name]||'')))control.value=String(selected[name]||'');});}
            let any=false;editor.querySelectorAll('[data-connector-driver]').forEach(function(set){const active=set.dataset.connectorDriver===driver.value;set.hidden=!active;set.querySelectorAll('input,select,textarea').forEach(function(control){control.disabled=!active;if(active)any=true;});});const empty=editor.querySelector('[data-connector-options-empty]');if(empty)empty.hidden=any;previous=driver.value;}
        driver.addEventListener('change',update);update();
    });
});

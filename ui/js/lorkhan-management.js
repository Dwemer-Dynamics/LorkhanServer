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
        const lists=Array.from(hub.querySelectorAll('[role="tablist"]'));
        const buttons=Array.from(hub.querySelectorAll('.tab-button[data-tab]'));
        const panels=Array.from(hub.querySelectorAll('[data-tab-panel]'));
        const groups=Array.from(hub.querySelectorAll('.tab-group'));
        if(!buttons.length)return;
        function usable(button){return !button.disabled&&button.getAttribute('aria-disabled')!=='true';}
        // A tab names the region it controls, which for the shared Player and
        // Narration shell is one region of a panel rather than the whole panel.
        function regionOf(button){const id=button.getAttribute('aria-controls');return id?hub.querySelector('#'+CSS.escape(id)):null;}
        function mount(region){const frame=region?region.querySelector('iframe'):null;
            if(frame&&frame.getAttribute('src')==='about:blank'&&frame.dataset.src)frame.src=frame.dataset.src;}
        // One reachable stop per tablist, so Tab still enters every group.
        function roving(){lists.forEach(function(list){const items=buttons.filter(function(item){return list.contains(item);});
            const stop=items.find(function(item){return item.classList.contains('active');})||items.find(usable);
            items.forEach(function(item){item.tabIndex=item===stop?0:-1;});});}
        function activate(id,updateUrl){
            const button=buttons.find(function(item){return item.dataset.tab===id;});
            if(!button||!usable(button))return false;
            const region=regionOf(button);const panel=region?region.closest('[data-tab-panel]'):null;
            if(!panel)return false;
            buttons.forEach(function(item){const active=item===button;item.classList.toggle('active',active);item.setAttribute('aria-selected',active?'true':'false');});
            groups.forEach(function(item){item.classList.toggle('active',item.dataset.category===button.dataset.category);});
            panels.forEach(function(item){item.classList.toggle('active',item===panel);});
            // Regions only change visibility. A frame that has already loaded is
            // never re-pointed, so unsaved values survive the switch.
            panel.querySelectorAll('[data-tab-region]').forEach(function(item){item.hidden=item!==region;});
            mount(region);roving();
            if(updateUrl!==false){const url=new URL(window.location.href);url.searchParams.set('tab',id);history.replaceState(null,'',url);}
            return true;
        }
        buttons.forEach(function(button){button.addEventListener('click',function(event){
            if(!usable(button)){event.preventDefault();return;}
            activate(button.dataset.tab);});});
        lists.forEach(function(list){list.addEventListener('keydown',function(event){
            if(event.altKey||event.ctrlKey||event.metaKey)return;
            const items=buttons.filter(function(item){return list.contains(item)&&usable(item);});
            const index=items.indexOf(document.activeElement);
            if(index<0)return;
            if(event.key==='Enter'||event.key===' '){
                event.preventDefault();
                activate(items[index].dataset.tab);
                return;
            }
            let target=null;
            if(event.key==='ArrowRight'||event.key==='ArrowDown')target=items[(index+1)%items.length];
            else if(event.key==='ArrowLeft'||event.key==='ArrowUp')target=items[(index-1+items.length)%items.length];
            else if(event.key==='Home')target=items[0];
            else if(event.key==='End')target=items[items.length-1];
            if(!target)return;
            event.preventDefault();
            // Manual activation: an arrow key moves focus only, so browsing the
            // strip never loads an embedded page. Enter or Space opens it.
            items.forEach(function(item){item.tabIndex=item===target?0:-1;});
            target.focus();});});
        activate(hub.dataset.activeTab||buttons[0].dataset.tab);
    });
    document.querySelectorAll('[data-connector-options]').forEach(function(editor){
        const driver=document.getElementById(editor.dataset.driverControl||'');if(!(driver instanceof HTMLSelectElement))return;
        const form=editor.closest('form');let defaults={};try{defaults=JSON.parse(editor.dataset.connectorDefaults||'{}');}catch(_){defaults={};}let previous=driver.value;
        function update(){if(form&&driver.value!==previous&&defaults[driver.value]){const before=defaults[previous]||{};const selected=defaults[driver.value];['endpoint','model','voice','language'].forEach(function(name){const control=form.elements.namedItem(name);if(control&&('value' in control)&&(control.value===''||control.value===String(before[name]||'')))control.value=String(selected[name]||'');});}
            let any=false;editor.querySelectorAll('[data-connector-driver]').forEach(function(set){const active=set.dataset.connectorDriver===driver.value;set.hidden=!active;set.querySelectorAll('input,select,textarea').forEach(function(control){control.disabled=!active;if(active)any=true;});});const empty=editor.querySelector('[data-connector-options-empty]');if(empty)empty.hidden=any;previous=driver.value;}
        driver.addEventListener('change',update);update();
    });
});

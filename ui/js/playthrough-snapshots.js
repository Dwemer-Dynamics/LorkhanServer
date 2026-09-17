"use strict";
const timeline=document.querySelector('[data-snapshot-timeline]');
if(timeline){
    const items=JSON.parse(timeline.dataset.snapshotTimeline).sort((a,b)=>a.minute-b.minute);
    const min=items[0].minute,max=items[items.length-1].minute;
    const pct=value=>max===min?50:(value-min)/(max-min)*100;
    timeline.querySelector('[data-timeline-min]').textContent='Earliest: '+items[0].date;
    timeline.querySelector('[data-timeline-max]').textContent='Latest: '+items[items.length-1].date;
    const tooltip=timeline.querySelector('.timeline-tooltip');
    items.forEach(item=>{
        const node=document.createElement('button');node.type='button';node.className='timeline-node'+(item.active?' active':'');
        node.style.left=pct(item.minute)+'%';node.setAttribute('aria-label',item.name+' · '+item.date);node.setAttribute('aria-describedby','pt-tooltip');
        const show=()=>{
            tooltip.replaceChildren();
            [item.name,'Morrowind date: '+item.date,'Created: '+item.created,item.bytes===null?'Live database':'Size: '+(item.bytes/1048576).toFixed(1)+' MiB'].forEach((text,index)=>{
                const line=document.createElement('div');line.textContent=text;if(index===0)line.className='name';tooltip.append(line);
            });
            tooltip.style.display='block';
            tooltip.style.left=Math.max(0,Math.min(node.offsetLeft, timeline.clientWidth-tooltip.offsetWidth))+'px';
            tooltip.style.top='58px';
        };
        const hide=()=>{tooltip.style.display='none';};
        node.addEventListener('mouseenter',show);node.addEventListener('focus',show);node.addEventListener('click',show);
        node.addEventListener('mouseleave',hide);node.addEventListener('blur',hide);node.addEventListener('keydown',e=>{if(e.key==='Escape')hide();});
        timeline.querySelector('.timeline-nodes').append(node);
    });
    const segments=max===min?0:Math.min(Math.max(items.length-1,4),12);
    for(let index=0;index<=segments;index++){
        const notch=document.createElement('div');notch.className='timeline-notch'+(index%2===0?' major':'');
        notch.style.left=(segments===0?50:index/segments*100)+'%';timeline.querySelector('.timeline-notches').append(notch);
        if(index>0&&index<segments&&index%2===0){
            let day=Math.floor((min+index/segments*(max-min))/1440);
            const year=Math.floor(day/365)+1;day%=365;
            const lengths=[31,28,31,30,31,30,31,31,30,31,30,31],months=['Morning Star',"Sun's Dawn",'First Seed',"Rain's Hand",'Second Seed','Midyear',"Sun's Height",'Last Seed','Hearthfire','Frostfall',"Sun's Dusk",'Evening Star'];
            let month=0;while(day>=lengths[month]){day-=lengths[month];month++;}
            const label=document.createElement('div');label.className='timeline-tick-label';label.style.left=index/segments*100+'%';
            label.textContent=(day+1)+' '+months[month]+', 3E '+year;timeline.querySelector('.timeline-notches').append(label);
        }
    }
}
// Match the reference's submit feedback without replacing the durable job status page.
const snapshotOverlay = document.getElementById('switch-overlay');
let snapshotSubmitting = false;
document.querySelectorAll('.create-form, [data-snapshot-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (event.defaultPrevented) return;
    if (snapshotSubmitting) { event.preventDefault(); return; }
    const operation = form.dataset.snapshotConfirm;
    if (operation) {
        const name = form.dataset.snapshotName;
        const message = operation === 'copy'
            ? 'Copy '+name+' to the active database? Close the game first. Current data will be replaced after a rollback snapshot is saved.'
            : 'Permanently delete stored snapshot '+name+'? This does not delete the active database.';
        if (!confirm(message)) { event.preventDefault(); return; }
    }
    if (operation === 'delete' || !snapshotOverlay) return;
    snapshotOverlay.querySelector('.loading-title').textContent = operation === 'copy' ? 'Loading Snapshot' : 'Creating Snapshot…';
    snapshotOverlay.showModal();
    snapshotSubmitting = true;
}));
if (snapshotOverlay) {
    // Dismissing a submitted request cannot cancel the server's backup or restore.
    snapshotOverlay.addEventListener('cancel', event => event.preventDefault());
    addEventListener('pageshow', () => { snapshotOverlay.close(); snapshotSubmitting = false; });
}
const snapshotFile = document.getElementById("playthrough-snapshot-file");
const snapshotText = document.getElementById("playthrough-snapshot-json");
const snapshotStatus = document.getElementById("playthrough-file-status");
if (snapshotFile && snapshotText) {
    let selection = 0, reading = false;
    snapshotText.addEventListener("input", () => { if (!reading) snapshotStatus.textContent = ""; });
    snapshotFile.addEventListener("change", async () => {
        const currentSelection = ++selection;
        const file = snapshotFile.files[0];
        snapshotText.value = ""; snapshotStatus.textContent = ""; reading = false;
        if (!file) return;
        if (file.size > 2097152) { snapshotStatus.textContent = "Snapshot exceeds the 2 MiB import limit."; snapshotFile.value = ""; return; }
        reading = true; snapshotStatus.textContent = "Reading snapshot…";
        try {
            const text = await file.text();
            if (currentSelection !== selection) return;
            JSON.parse(text); snapshotText.value = text;
            snapshotStatus.textContent = file.name + " ready to import.";
        } catch (_) {
            if (currentSelection !== selection) return;
            snapshotText.value = ""; snapshotStatus.textContent = "Choose a valid JSON snapshot.";
        } finally { if (currentSelection === selection) reading = false; }
    });
    snapshotFile.form.addEventListener("submit", event => {
        if (reading) { event.preventDefault(); snapshotStatus.textContent = "Wait for the selected snapshot to finish reading."; return; }
        if (!snapshotText.value.trim()) { event.preventDefault(); snapshotStatus.textContent = "Choose a snapshot file or paste its JSON first."; snapshotFile.focus(); }
    });
}
// Archive and retention operations always require an inspected, unchanged selection.
for (const form of document.querySelectorAll('[data-playthrough-archive], [data-backup-retention], [data-backup-settings]')) {
    let preview = null, busy = false, selectionVersion = 0;
    const status = form.querySelector('[role="status"]');
    const confirmButton = form.querySelector('button[value="import"], button[value="delete"]');
    form.addEventListener('change', () => { ++selectionVersion; preview = null; if (confirmButton) confirmButton.disabled = true; status.textContent = ''; });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        const operation = event.submitter?.value || 'save';
        const submittedVersion = selectionVersion;
        const data = new FormData(form);
        data.set('operation', operation);
        if (operation === 'import') {
            if (!preview || !confirm('Import this inspected archive as a new inactive copy? The current game and original playthrough will not be changed.')) return;
            data.set('archive_sha256', preview.sha256); data.set('confirm', 'Import inactive copy');
        }
        if (operation === 'delete') {
            if (!preview || !confirm(`Delete exactly these ${preview.files.length} backups (SQL and companion dumps)? Live gameplay data will not be deleted.`)) return;
            data.set('token', preview.token); data.set('cutoff', preview.cutoff); data.set('confirm', 'Delete previewed backup files');
        }
        busy = true; status.textContent = 'Working…'; if (confirmButton) confirmButton.disabled = true;
        try {
            const response = await fetch(form.action, {method: 'POST', body: data, headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            const result = await response.json();
            if (!response.ok) throw new Error(result.code || result.error || 'Request failed');
            if (submittedVersion !== selectionVersion && (operation === 'inspect' || operation === 'preview')) throw new Error('Selection changed. Inspect or preview again.');
            if (operation === 'inspect') { preview = result; status.textContent = JSON.stringify(result.preview, null, 2); confirmButton.disabled = false; }
            else if (operation === 'preview') {
                preview = result;
                status.textContent = result.files.length ? result.files.map(file => `${file.name} — ${file.byte_count} bytes — ${file.created_at}`).join('\n') + `\nTotal: ${result.files.length} backups, ${result.bytes} recorded bytes.` : 'No eligible backups.';
                confirmButton.disabled = result.files.length === 0;
            } else if (operation === 'import') { preview = null; status.textContent = 'Inactive copy imported. Refresh this page, select the copy, then use Associate with character to queue it for the next save load.\n' + JSON.stringify(result.imported, null, 2); }
            else if (operation === 'delete') { preview = null; status.textContent = `${result.deleted.length} backups deleted; ${result.skipped.length} skipped after protection checks. No live gameplay records deleted. Preview again before continuing.`; }
            else { status.textContent = 'Threshold saved.'; if (result.revision) form.elements.expected_revision.value = result.revision; }
        } catch (error) { preview = null; status.textContent = String(error.message || 'Request failed.'); }
        finally { busy = false; }
    });
}

// Scoped changes never replace the running game session or shared configuration.
for (const form of document.querySelectorAll('[data-playthrough-manage], [data-playthrough-association]')) {
    let busy = false;
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        const data = new FormData(form);
        const operation = event.submitter?.value || data.get('operation');
        data.set('operation', operation);
        if (operation === 'delete') {
            if (prompt('Remove this inactive playthrough from the list? Stored history is retained. Type Delete to confirm.') !== 'Delete') return;
            data.set('confirm', 'Delete');
        }
        if (operation === 'copy' && !confirm('Create an independent copy of this playthrough? The current game and original data will stay unchanged.')) return;
        if (operation === 'queue') {
            if (!confirm('Associate this playthrough with the selected saved character on its next load? The current session will not change.')) return;
            const selected = form.querySelector('select[name="character_id"]').selectedOptions[0];
            data.set('expected_playthrough_id', selected.dataset.playthroughId);
            data.set('confirm', 'Associate on next load');
        }
        busy = true;
        const status = form.querySelector('[role="status"]');
        status.textContent = 'Working…';
        try {
            const response = await fetch(form.action, {method: 'POST', body: data, headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Request failed');
            status.textContent = operation === 'queue' ? 'Association queued for the next save load.' : 'Saved. Refreshing…';
            const destination = new URL(location.href);
            if (result.result?.playthrough_id) destination.searchParams.set('playthrough_id', result.result.playthrough_id);
            else if (operation === 'delete') destination.searchParams.delete('playthrough_id');
            location.assign(destination.href);
        } catch (error) { status.textContent = String(error.message || 'Request failed'); }
        finally { busy = false; }
    });
}

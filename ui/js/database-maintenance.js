/* Follow the explicit maintenance request without keeping its POST connection open. */
(() => {
    const factoryDialog=document.querySelector('[data-factory-dialog]');
    if(factoryDialog){
        const confirmation=factoryDialog.querySelector('[name="confirm"]');
        document.querySelector('[data-factory-open]').addEventListener('click',()=>{confirmation.value='';factoryDialog.showModal();confirmation.focus();});
        factoryDialog.querySelector('[data-factory-cancel]').addEventListener('click',()=>factoryDialog.close());
    }
    const replayDialog=document.querySelector('[data-replay-dialog]');
    if(replayDialog){
        const form=replayDialog.querySelector('form');
        document.querySelectorAll('[data-replay-version]').forEach(button=>button.addEventListener('click',()=>{
            const version=button.dataset.replayVersion;
            form.elements.namedItem('version').value=version;
            const confirmation=form.elements.namedItem('confirm');
            confirmation.value='';confirmation.pattern='Replay '+version;
            replayDialog.querySelector('[data-replay-label]').textContent='Type Replay '+version+' to confirm';
            replayDialog.querySelector('[data-replay-description]').textContent='Replay from version '+version+' ('+button.dataset.replayCount+' applied updates).';
            replayDialog.showModal();confirmation.focus();
        }));
        replayDialog.querySelector('[data-replay-cancel]').addEventListener('click',()=>replayDialog.close());
    }
    document.querySelectorAll('[data-backup-delete]').forEach(form => form.addEventListener('submit', event => {
        if(!confirm('Delete automatic backup '+form.dataset.backupName+' and its restore archive? This cannot be undone.'))event.preventDefault();
    }));
    document.querySelectorAll('[data-backup-auto-submit]').forEach(select => select.addEventListener('change', () => select.form.requestSubmit()));
    document.querySelectorAll('[data-database-maintenance]').forEach(status => {
    const backup = status.dataset.kind === 'backup';
    const restore = status.dataset.kind === 'restore';
    const snapshot = status.dataset.kind === 'snapshot';
    const replay = status.dataset.kind === 'replay';
    const factory = status.dataset.kind === 'factory';
    const labels = {queued:'Queued. Waiting for a maintenance-capable worker.', leased:'Running database maintenance. Tables may be locked.',
        succeeded:'Database maintenance completed.', dead:'Maintenance failed. Some tables may already be compacted. Check server logs before retrying.',
        cancelled:'Database maintenance cancelled.'};
    if(backup) Object.assign(labels,{queued:'SQL backup queued. Waiting for a worker.',leased:'Creating SQL backup.',succeeded:'SQL backup completed. Refresh the backup list to download.',dead:'SQL backup failed. Check storage space and server logs before retrying.'});
    if(restore) Object.assign(labels,{queued:'SQL restore queued. Keep the game closed.',leased:'Creating rollback backup and restoring SQL. Keep the server running.',succeeded:'SQL restore completed. Refresh this page for the rollback backup; reconnect the game before playing.',dead:'SQL restore did not complete. Active work, storage or an incompatible snapshot can prevent restoration. Check server logs and the backup list before retrying.'});
    if(replay) Object.assign(labels,{queued:'Migration replay queued. Keep the game closed.',leased:'Creating rollback backup and replaying migrations. Keep the server running.',succeeded:'Migration replay completed. Refresh the versions and backup list; reconnect the game before playing.',dead:'Migration replay did not complete. Database changes were rolled back if replay started. Check the rollback backup and server logs before retrying.'});
    if(snapshot) Object.assign(labels,{queued:'Snapshot save queued.',leased:'Saving the current database snapshot.',succeeded:'Snapshot saved. Refresh the list to see it.',dead:'Snapshot save failed. Check storage space and server logs before retrying.'});
    if(factory) Object.assign(labels,{queued:'Factory reset queued. Keep the game closed.',leased:'Creating rollback backup and installing factory data. Keep the server running.',succeeded:'Factory reset completed. Refresh this page for the rollback backup; reconnect the game before playing.',dead:'Factory reset did not complete. Database changes were rolled back if reset started. Check the backup list and server logs before retrying.'});
    let timer;
    const refresh = async () => {
        try {
            const response = await fetch(status.dataset.endpoint, {headers:{Accept:'application/json'}, credentials:'same-origin', signal:AbortSignal.timeout(10000)});
            if(response.status===503){status.textContent='Database restore or service maintenance in progress. Waiting for the server.';timer=setTimeout(refresh,2000);return;}
            if (!response.ok) throw Error('Status request failed');
            const {job} = await response.json();
            status.textContent = job ? labels[job.state] || 'Maintenance status unavailable.' : factory ? 'No factory reset requests.' : replay ? 'No migration replay requests.' : snapshot ? 'No snapshot save requests.' : restore ? 'No SQL restore requests.' : backup ? 'No SQL backup requests.' : 'No queued maintenance requests.';
            if (backup && job?.state === 'succeeded' && /^[0-9a-f-]{36}$/.test(job.job_id || '')) {
                const download=document.createElement('a'); download.textContent='Download SQL';
                download.href=status.dataset.downloadBase + job.job_id + '.sql';
                status.append(' ',download);
            }
            if (job && ['queued','leased'].includes(job.state)) timer = setTimeout(refresh, 2000);
        } catch (_) {
            status.textContent = 'Unable to refresh job status. Reload this page to check; this does not cancel the job.';
        }
    };
    addEventListener('pagehide', () => clearTimeout(timer));
    refresh();
    });
})();

/* Follow the explicit maintenance request without keeping its POST connection open. */
(() => {
    document.querySelectorAll('[data-backup-auto-submit]').forEach(select => select.addEventListener('change', () => select.form.requestSubmit()));
    document.querySelectorAll('[data-database-maintenance]').forEach(status => {
    const backup = status.dataset.kind === 'backup';
    const labels = {queued:'Queued. Waiting for a maintenance-capable worker.', leased:'Running database maintenance. Tables may be locked.',
        succeeded:'Database maintenance completed.', dead:'Maintenance failed. Some tables may already be compacted. Check server logs before retrying.',
        cancelled:'Database maintenance cancelled.'};
    if(backup) Object.assign(labels,{queued:'SQL backup queued. Waiting for a worker.',leased:'Creating SQL backup.',succeeded:'SQL backup completed. Refresh the backup list to download.',dead:'SQL backup failed. Check storage space and server logs before retrying.'});
    let timer;
    const refresh = async () => {
        try {
            const response = await fetch(status.dataset.endpoint, {headers:{Accept:'application/json'}, credentials:'same-origin', signal:AbortSignal.timeout(10000)});
            if (!response.ok) throw Error('Status request failed');
            const {job} = await response.json();
            status.textContent = job ? labels[job.state] || 'Maintenance status unavailable.' : backup ? 'No SQL backup requests.' : 'No queued maintenance requests.';
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

/* Follow the explicit maintenance request without keeping its POST connection open. */
(() => {
    const status = document.querySelector('[data-database-maintenance]');
    if (!status) return;
    const labels = {queued:'Queued. Waiting for a maintenance-capable worker.', leased:'Running database maintenance. Tables may be locked.',
        succeeded:'Database maintenance completed.', dead:'Maintenance failed. Some tables may already be compacted. Check server logs before retrying.',
        cancelled:'Database maintenance cancelled.'};
    let timer;
    const refresh = async () => {
        try {
            const response = await fetch(status.dataset.endpoint, {headers:{Accept:'application/json'}, credentials:'same-origin', signal:AbortSignal.timeout(10000)});
            if (!response.ok) throw Error('Status request failed');
            const {job} = await response.json();
            status.textContent = job ? labels[job.state] || 'Maintenance status unavailable.' : 'No queued maintenance requests.';
            if (job && ['queued','leased'].includes(job.state)) timer = setTimeout(refresh, 2000);
        } catch (_) {
            status.textContent = 'Unable to refresh maintenance status. Reload this page to check; this does not cancel the job.';
        }
    };
    addEventListener('pagehide', () => clearTimeout(timer));
    refresh();
})();

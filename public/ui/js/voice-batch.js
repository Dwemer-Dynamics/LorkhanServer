// Upload sequentially so progress is visible and Stop never interrupts a provider-side clone.
document.querySelectorAll('[data-voice-batch]').forEach((form) => {
    const start = form.querySelector('[type="submit"]');
    const stop = form.querySelector('[data-voice-batch-stop]');
    const status = form.querySelector('[data-voice-batch-status]');
    let stopped = false;
    stop.addEventListener('click', () => { stopped = true; stop.disabled = true; });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity() || start.disabled) return;
        const data = new FormData(form);
        data.set('_batch_ajax', '1');
        start.disabled = true; stop.hidden = false; stop.disabled = false; stopped = false;
        let total = 0;
        try {
            for (let step = 0; step < 512 && !stopped; step++) {
                status.textContent = `${total} voices uploaded. Processing next missing voice…`;
                const response = await fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin'});
                if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Upload failed. Refresh the page to check connector settings and credentials.');
                const result = await response.json();
                total += result.uploaded;
                if (result.failed) throw new Error(`${total} uploaded. A voice failed; batch stopped. Successful voices remain saved.`);
                if (result.remaining === 0) { status.textContent = `${total} voices uploaded. Voice library is up to date.`; return; }
            }
            status.textContent = `${total} voices uploaded. Stopped; run again to resume.`;
        } catch (error) { status.textContent = error.message; }
        finally { start.disabled = false; stop.hidden = true; }
    });
});

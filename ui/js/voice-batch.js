// Freeze one provider-checked queue, then process each voice once without interrupting an active clone.
document.querySelectorAll('input[type="file"][name="voice_sample[]"]').forEach(input => {
    input.form.addEventListener('submit', () => { input.form.elements.upload_count.value = String(input.files.length); });
});
document.querySelectorAll('[data-voice-batch]').forEach((form) => {
    const start = form.querySelector('[type="submit"]');
    const stop = form.querySelector('[data-voice-batch-stop]');
    const panel = form.querySelector('[data-voice-batch-progress]');
    const status = form.querySelector('[data-voice-batch-status]');
    const log = form.querySelector('[data-voice-batch-log]');
    const current = form.querySelector('[data-voice-batch-current]');
    const total = form.querySelector('[data-voice-batch-total]');
    const eta = form.querySelector('[data-voice-batch-eta]');
    const bar = form.querySelector('[data-voice-batch-bar]');
    const refresh = form.querySelector('[data-voice-batch-refresh]');
    let stopped = false;
    stop.addEventListener('click', () => {
        stopped = true; stop.disabled = true;
        status.textContent = 'Cancelling after the current request finishes…';
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity() || start.disabled) return;
        const data = new FormData(form);
        data.set('_batch_ajax', '1'); data.set('_batch_phase', 'plan');
        start.disabled = true; stop.hidden = false; stop.disabled = false; stopped = false;
        panel.hidden = false; refresh.hidden = true; log.replaceChildren();
        current.textContent = '0'; total.textContent = '0'; eta.textContent = '';
        bar.style.width = '0%'; bar.parentElement.setAttribute('aria-valuenow', '0');
        status.textContent = 'Checking the provider for missing voices…';
        let uploaded = 0, failed = 0, skipped = 0, completed = 0, rateLimited = false;
        // Never retry an uncertain upload automatically; a provider may already have created the voice.
        const send = async () => {
            // The hidden action field shadows HTMLFormElement.action. Read the URL attribute instead.
            const response = await fetch(form.getAttribute('action'), {method: 'POST', body: data, credentials: 'same-origin'});
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Unexpected response. Refresh the page before retrying; the last request may have completed.');
            const result = await response.json();
            if (!response.ok) throw new Error(typeof result.error === 'string' ? result.error : 'Voice request failed. Check the connector before retrying.');
            return result;
        };
        try {
            const plan = await send();
            if (!Array.isArray(plan.voices) || plan.voices.length > 512 || plan.voices.some(voice => typeof voice !== 'string') || new Set(plan.voices).size !== plan.voices.length) throw new Error('Invalid voice queue. Refresh the page before retrying.');
            total.textContent = String(plan.voices.length);
            data.set('_batch_phase', 'voice');
            const began = performance.now();
            for (const voice of plan.voices) {
                if (stopped) break;
                const row = document.createElement('div'); row.className = 'voice-batch-processing';
                row.textContent = `⏳ Processing: ${voice}`; log.append(row); log.scrollTop = log.scrollHeight;
                status.textContent = `Processing ${voice}…`; data.set('voice_name', voice);
                let result;
                try {
                    result = await send();
                    if (!result || result.voice !== voice || ![result.uploaded, result.failed, result.skipped].every(value => value === 0 || value === 1) || result.uploaded + result.failed + result.skipped !== 1) throw new Error('Unconfirmed voice result. Refresh the page before retrying.');
                } catch (error) { row.className = 'voice-batch-failed'; row.textContent = `? ${voice}: outcome unconfirmed`; throw error; }
                uploaded += result.uploaded; failed += result.failed; skipped += result.skipped; completed++;
                row.className = result.failed ? 'voice-batch-failed' : 'voice-batch-succeeded';
                row.textContent = result.failed ? `✗ ${voice}: upload failed` : result.skipped ? `✓ ${voice}: already available` : `✓ ${voice}`;
                if(result.previous_kept)row.textContent+=' — previous voice kept because profiles or connectors still use its ID';
                if(result.cleanup_failed)row.textContent+=' — new voice active; old remote clone cleanup failed';
                current.textContent = String(completed);
                const percentage = Math.round(completed / plan.voices.length * 100);
                bar.style.width = `${percentage}%`; bar.parentElement.setAttribute('aria-valuenow', String(percentage));
                const seconds = Math.ceil((performance.now() - began) / completed / 1000 * (plan.voices.length - completed));
                eta.textContent = seconds > 0 ? `(~${seconds > 60 ? Math.ceil(seconds / 60) + ' min' : seconds + ' sec'} remaining)` : '';
                if (result.rate_limited) { stopped = true; rateLimited = true; break; }
                const delay = Number(form.dataset.voiceBatchDelay) || 0;
                if (!stopped && completed < plan.voices.length && delay > 0) {
                    status.textContent = 'Waiting before the next provider request…';
                    await new Promise(resolve => setTimeout(resolve, delay));
                }
            }
            status.textContent = `${rateLimited ? 'Rate limit reached. Wait before continuing.' : stopped ? 'Batch stopped.' : 'Batch complete.'} ${uploaded} uploaded, ${skipped} already available, ${failed} failed. ${completed} / ${plan.voices.length} processed.${stopped ? ' No further voices were started.' : ''}`;
        } catch (error) {
            status.textContent = `${error.message} ${uploaded} uploaded, ${skipped} already available, ${failed} failed. Successful voices remain saved.`;
        } finally {
            start.disabled = false; stop.hidden = true; eta.textContent = ''; refresh.hidden = false;
        }
    });
});

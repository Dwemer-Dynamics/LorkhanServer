/* Match the reference save-before-test workflow without TTS generation or persisted test events. */
(() => {
    const opener = document.getElementById('stt-test-open');
    const dialog = document.getElementById('stt-test-dialog');
    const form = document.getElementById('stt-form');
    if (!opener || !dialog || !form) return;
    const status = document.getElementById('stt-test-status');
    const results = document.getElementById('stt-test-results');
    const retry = document.getElementById('stt-test-retry');
    const sample = document.getElementById('stt-test-sample');
    let pending = null;

    const stop = () => {
        pending?.abort();
        pending = null;
        sample.pause();
        sample.currentTime = 0;
        opener.disabled = false;
    };
    document.getElementById('stt-test-close').addEventListener('click', () => dialog.close());
    dialog.addEventListener('cancel', stop);
    dialog.addEventListener('close', () => { stop(); opener.focus(); });
    window.addEventListener('pagehide', stop);
    document.getElementById('stt-test-play').addEventListener('click', async () => {
        sample.currentTime = 0;
        try { await sample.play(); }
        catch (_) { status.textContent = 'The test audio could not be played in this browser.'; }
    });

    const run = async () => {
        if (pending || !form.reportValidity()) return;
        if (!dialog.open) dialog.showModal();
        const request = new AbortController();
        pending = request;
        opener.disabled = true;
        results.hidden = true;
        retry.hidden = true;
        status.textContent = 'Saving connector settings…';
        const timer = window.setTimeout(() => request.abort(), 135000);
        try {
            // Saving can complete even if the window is closed; closing does not undo a saved revision.
            const saved = await fetch(form.action, {method:'POST', credentials:'same-origin', body:new FormData(form), signal:request.signal});
            if (request !== pending || !dialog.open) return;
            if (!saved.ok) { status.textContent = 'Connector settings could not be saved. Close this window and check the form.'; return; }
            status.textContent = 'Obtaining transcription from STT service…';
            const response = await fetch(dialog.dataset.endpoint, {
                method:'POST', credentials:'same-origin', signal:request.signal,
                headers:{'Content-Type':'application/json', 'X-CSRF-Token':form.elements.namedItem('_csrf').value},
                body:JSON.stringify({installation_id:dialog.dataset.installation, configuration_id:dialog.dataset.configuration})
            });
            if (request !== pending || !dialog.open) return;
            if (!response.ok) {
                status.textContent = response.status === 429 ? 'Too many speech tests. Wait a moment before retrying.'
                    : response.status === 401 || response.status === 403 ? 'Session expired. Reload the page before testing.'
                    : response.status === 422 ? 'Transcription is unavailable. Check that an STT service is selected.'
                    : 'Transcription failed. Check the connector, API key and provider logs.';
                return;
            }
            const result = await response.json();
            if (request !== pending || !dialog.open) return;
            if (typeof result.transcript !== 'string' || !Number.isFinite(result.similarity_percent) || !Number.isFinite(result.elapsed_ms)) throw new Error('Invalid result');
            document.getElementById('stt-test-transcript').textContent = result.transcript;
            document.getElementById('stt-test-similarity').textContent = result.similarity_percent.toFixed(2);
            document.getElementById('stt-test-service').textContent = result.driver;
            document.getElementById('stt-test-elapsed').textContent = String(result.elapsed_ms);
            results.hidden = false;
            status.textContent = 'Transcription Successful!';
        } catch (error) {
            if (request === pending && dialog.open) status.textContent = error.name === 'AbortError'
                ? 'The test timed out. Saved settings may already have been applied; check the provider before retrying.'
                : 'The test could not be completed. Check the saved connector and server connection.';
        } finally {
            window.clearTimeout(timer);
            if (request === pending) { pending = null; opener.disabled = false; retry.hidden = false; }
        }
    };
    opener.addEventListener('click', run);
    retry.addEventListener('click', run);
})();

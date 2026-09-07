/* Connector tests use the existing bounded preview API; opening or closing never saves the editor. */
(() => {
    const driver = document.getElementById('tts_driver');
    const heading = document.querySelector('[data-tts-settings-heading]');
    if (driver && heading) {
        driver.addEventListener('change', () => { heading.textContent = driver.selectedOptions[0].textContent + ' Settings'; });
    }
    const badgeBlock = document.getElementById('tts_api_badge_block');
    const badge = document.getElementById('tts_credential');
    const badgeNotice = document.getElementById('tts_api_key_notice');
    if (driver && badgeBlock && badge && badgeNotice) {
        const defaults = JSON.parse(badgeBlock.dataset.credentialDefaults || '{}');
        const cloudDrivers = JSON.parse(badgeBlock.dataset.cloudDrivers || '[]');
        const drafts = new Map();
        let previous = badgeBlock.dataset.selectedDriver || driver.value;
        const updateBadge = () => {
            if (previous !== driver.value) {
                drafts.set(previous, badge.value);
                badge.value = drafts.get(driver.value) || defaults[driver.value] || 'none';
                previous = driver.value;
            }
            badgeBlock.hidden = !cloudDrivers.includes(driver.value);
            const configured = badge.value !== 'none' && badge.selectedOptions[0]?.dataset.empty === '0';
            badgeNotice.textContent = badge.value === 'none' ? 'No API key selected. Some services require a key.'
                : configured ? 'Selected API badge is configured.' : 'Selected API badge does not have a configured key yet.';
            badgeNotice.classList.toggle('warn', !configured);
            badgeNotice.classList.toggle('ok', configured);
            const endpointBlock = document.getElementById('tts_endpoint_block');
            if (endpointBlock) {
                if (badgeBlock.hidden) document.getElementById('tts_endpoint_anchor').before(endpointBlock);
                else document.getElementById('tts_advanced_endpoint').append(endpointBlock);
            }
        };
        driver.addEventListener('change', updateBadge);
        badge.addEventListener('change', updateBadge);
        updateBadge();
    }
    const dialog = document.getElementById('tts-test-dialog');
    const opener = document.getElementById('tts-test-open');
    if (!dialog || !opener) return;
    const form = document.getElementById('tts-test-form');
    const text = document.getElementById('tts-test-text');
    const voice = document.getElementById('tts-test-voice');
    const run = document.getElementById('tts-test-run');
    const audio = document.getElementById('tts-test-audio');
    const status = document.getElementById('tts-test-status');
    let pending = null;
    let objectUrl = '';

    // Release browser-owned audio immediately on close, replacement, or navigation.
    const releaseAudio = () => {
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
        audio.hidden = true;
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = '';
    };
    const stop = () => {
        pending?.abort();
        pending = null;
        releaseAudio();
        run.disabled = voice.disabled;
        run.textContent = 'Run Test';
    };
    opener.addEventListener('click', () => {
        document.getElementById('tts-test-result').hidden = true;
        document.getElementById('tts-test-request').hidden = true;
        dialog.showModal();
    });
    document.getElementById('tts-test-close').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { stop(); opener.focus(); });
    dialog.addEventListener('cancel', stop);
    window.addEventListener('pagehide', stop);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (pending || voice.disabled || !form.reportValidity()) return;
        const spoken = text.value.trim();
        if (!spoken) { text.focus(); return; }
        const request = new AbortController();
        pending = request;
        releaseAudio();
        run.disabled = true;
        run.textContent = 'Generating…';
        document.getElementById('tts-test-result').hidden = false;
        document.getElementById('tts-test-request').hidden = false;
        document.getElementById('tts-test-request-text').textContent = JSON.stringify({text: spoken, voice: voice.value}, null, 2);
        status.textContent = 'Generating audio…';
        const started = performance.now();
        const timer = window.setTimeout(() => request.abort(), 125000);
        try {
            const response = await fetch(dialog.dataset.endpoint, {
                method: 'POST', credentials: 'same-origin', signal: request.signal,
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': dialog.dataset.csrf},
                body: JSON.stringify({installation_id: dialog.dataset.installation,
                    configuration_id: dialog.dataset.configuration, text: spoken, voice: voice.value})
            });
            if (request !== pending || !dialog.open) return;
            if (!response.ok) {
                status.textContent = response.status === 429 ? 'Too many previews. Wait a moment and try again.'
                    : response.status === 401 || response.status === 403 ? 'Session expired. Reload the page before testing.'
                    : response.status === 422 ? 'The selected text, connector or voice is no longer available. Reload and check the saved settings.'
                    : 'No audio was produced. Check the connector settings, API key, endpoint and provider logs.';
                return;
            }
            const blob = await response.blob();
            if (request !== pending || !dialog.open) return;
            if (!blob.size || !/^audio\//.test(blob.type)) {
                status.textContent = 'The connector did not return playable audio.';
                return;
            }
            objectUrl = URL.createObjectURL(blob);
            audio.src = objectUrl;
            audio.hidden = false;
            status.textContent = 'Synthesis completed in ' + ((performance.now() - started) / 1000).toFixed(2) + ' seconds.';
            try { await audio.play(); }
            catch (_) { if (request === pending && dialog.open) status.textContent += ' Press Play to listen.'; }
        } catch (error) {
            if (request === pending && dialog.open) status.textContent = error.name === 'AbortError'
                ? 'The preview timed out. Check the provider before retrying.' : 'The server could not be reached for this test.';
        } finally {
            window.clearTimeout(timer);
            if (request === pending) {
                pending = null;
                run.disabled = voice.disabled;
                run.textContent = 'Run Test';
            }
        }
    });
})();

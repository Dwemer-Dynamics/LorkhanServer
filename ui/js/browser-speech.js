/* Browser recognition only produces bounded text; authenticated queue receipts distinguish game acceptance. */
(() => {
    const dialog = document.getElementById('browser-speech-dialog');
    const open = document.getElementById('browser-speech-open');
    if (!dialog || !open) return;
    const get = name => document.getElementById('browser-speech-' + name);
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    const session = get('session'), language = get('language'), delay = get('delay');
    const toggle = get('toggle'), status = get('status'), result = get('result');
    let listening = false, recognition = null, timer = null, pollTimer = null;
    let request = null, epoch = 0, finalText = '', submitting = false;
    const controls = () => {
        toggle.textContent = listening ? 'Stop Listening' : 'Start Listening';
        toggle.dataset.listening = String(listening);
        toggle.disabled = !Recognition || !session.value;
        session.disabled = language.disabled = delay.disabled = listening;
    };
    const stop = () => {
        listening = false; ++epoch; submitting = false; finalText = '';
        clearTimeout(timer); clearTimeout(pollTimer);
        request?.abort(); request = null;
        const previous = recognition; recognition = null;
        if (previous) { previous.onend = null; previous.abort(); }
        controls();
    };
    const fail = message => { stop(); status.textContent = message; };
    const json = async (path, options = {}) => {
        const response = await fetch(dialog.dataset.api + path, {credentials:'same-origin', ...options});
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'request_failed');
        return data;
    };
    const listen = () => {
        if (!listening || submitting) return;
        const current = new Recognition(); recognition = current;
        const generation = epoch;
        current.continuous = true; current.interimResults = true; current.lang = language.value;
        current.onresult = event => {
            if (!listening || generation !== epoch || recognition !== current) return;
            let interim = '';
            for (let i = event.resultIndex; i < event.results.length; ++i) {
                const text = event.results[i][0].transcript.replace(/[\u0000-\u001f\u007f]/g, ' ');
                if (event.results[i].isFinal) finalText += (finalText ? ' ' : '') + text;
                else interim += text;
            }
            result.textContent = finalText + (interim ? ' ' + interim : '');
            if (new TextEncoder().encode(finalText + interim).length > 2048) {
                fail('Speech is too long. Start again with a shorter utterance.'); return;
            }
            clearTimeout(timer);
            timer = setTimeout(send, Number(delay.value) * 1000);
        };
        current.onerror = event => {
            if (generation !== epoch || recognition !== current) return;
            if (event.error !== 'no-speech') fail('Speech recognition failed: ' + event.error);
        };
        current.onend = () => {
            if (generation !== epoch || recognition !== current) return;
            recognition = null;
            if (listening && !submitting) pollTimer = setTimeout(listen, 250);
        };
        try { current.start(); } catch (_) { fail('Microphone recognition could not start. Check browser permissions.'); }
    };
    const send = async () => {
        const text = finalText.trim();
        if (!listening || submitting || !text) return;
        finalText = ''; submitting = true;
        const current = recognition; recognition = null;
        if (current) { current.onend = null; current.abort(); }
        const generation = epoch, controller = new AbortController(); request = controller;
        const body = JSON.stringify({session_id:session.value, request_id:crypto.randomUUID(), text, language:language.value});
        const options = {method:'POST', signal:controller.signal,
            headers:{'Content-Type':'application/json','X-CSRF-Token':dialog.dataset.csrf}, body};
        status.textContent = 'Sending speech…';
        try {
            let queued;
            try { queued = await json('/browser-speech', options); }
            catch (error) {
                if (!(error instanceof TypeError) || controller.signal.aborted) throw error;
                queued = await json('/browser-speech', options); // Same utterance ID after an uncertain network response.
            }
            if (generation !== epoch) return;
            status.textContent = 'Waiting for the game to accept speech…';
            const deadline = Date.now() + 35000;
            const poll = async () => {
                if (generation !== epoch) return;
                try {
                    const data = await json('/debug-commands?session_id=' + encodeURIComponent(session.value), {signal:controller.signal});
                    if (generation !== epoch) return;
                    const receipt = data.items.find(item => item.command_id === queued.command.command_id);
                    if (receipt && receipt.state === 'succeeded') {
                        status.textContent = 'Dialogue queued in game.'; submitting = false; request = null; listen(); return;
                    }
                    if (receipt && ['failed','rejected','expired'].includes(receipt.state)) {
                        fail('Speech was not accepted: ' + (receipt.reason_code || receipt.state)); return;
                    }
                    if (Date.now() >= deadline) { fail('No game receipt. Speech may still be pending; check the game before repeating it.'); return; }
                    pollTimer = setTimeout(poll, 500);
                } catch (error) { if (generation === epoch) fail('Could not read the game receipt: ' + error.message); }
            };
            await poll();
        } catch (error) { if (generation === epoch) fail('Speech could not be queued: ' + error.message); }
    };
    open.addEventListener('click', async () => {
        stop(); dialog.showModal(); status.textContent = 'Finding compatible game sessions…';
        session.replaceChildren(new Option('Loading game sessions…', '')); controls();
        const generation = epoch, controller = new AbortController(); request = controller;
        try {
            const data = await json('/debug-command-sessions', {signal:controller.signal});
            if (generation !== epoch) return;
            const sessions = data.items.filter(item => item.installation_id === dialog.dataset.installation && item.browser_speech_supported);
            session.replaceChildren(...(sessions.length ? sessions.map(item => new Option(item.label, item.session_id)) : [new Option('No compatible game session', '')]));
            status.textContent = !Recognition ? 'This browser does not support speech recognition. Open this page in Chrome.'
                : sessions.length ? 'Ready. Recognition starts only when you click Start Listening.' : 'Load a game using a client with browser speech support.';
            request = null; controls();
        } catch (error) { if (generation === epoch) fail('Could not load game sessions: ' + error.message); }
    });
    toggle.addEventListener('click', () => {
        if (listening) { stop(); status.textContent = 'Listening stopped. Unsent words discarded.'; return; }
        if (!Recognition || !session.value) return;
        listening = true; result.textContent = ''; status.textContent = 'Listening…'; controls(); listen();
    });
    session.addEventListener('change', controls);
    get('close').addEventListener('click', () => { stop(); dialog.close(); });
    dialog.addEventListener('cancel', stop);
    dialog.addEventListener('close', () => { stop(); open.focus(); });
    window.addEventListener('pagehide', stop);
})();

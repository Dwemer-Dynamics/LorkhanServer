/* Diary reading uses the authenticated TTS preview lane; no audio is generated before a click. */
(() => {
    const root = document.querySelector('[data-reader]');
    if (!root) return;
    const calendarViewport = root.closest('.calendar-reader-viewport');
    // Keep date anchors inside the reader, as they are in the reference iframe.
    const alignCalendarAnchor = () => {
        if (!calendarViewport || window.location.hash !== '#adventure-events') return;
        const target = root.querySelector('#adventure-events');
        if (!target) return;
        requestAnimationFrame(() => {
            calendarViewport.scrollTop += target.getBoundingClientRect().top - calendarViewport.getBoundingClientRect().top - 200;
            window.scrollTo(0, 0);
        });
    };
    if (document.readyState === 'complete') alignCalendarAnchor();
    else window.addEventListener('load', alignCalendarAnchor, { once: true });
    window.addEventListener('hashchange', alignCalendarAnchor);
    const people = root.querySelector('[data-diary-people]');
    people?.querySelector('[data-diary-people-search]')?.addEventListener('input', (event) => {
        const query = event.target.value.toLocaleLowerCase();
        let count = 0;
        people.querySelectorAll('[data-diary-person]').forEach((item) => {
            item.hidden = !item.querySelector('[data-person-name]').textContent.toLocaleLowerCase().includes(query);
            if (!item.hidden) count++;
        });
        people.querySelector('[data-diary-people-empty]').hidden = count > 0;
    });
    const audio = root.querySelector('[data-reader-audio]');
    const status = root.querySelector('[data-reader-status]');
    const stopButton = root.querySelector('[data-reader-stop]');
    const dock = root.querySelector('[data-reader-dock]');
    const limit = Number(root.dataset.maxLength || 240);
    let active = null;
    let objectUrl = '';
    const announce = (message) => { status.textContent = message; };
    const release = () => {
        audio.pause(); audio.removeAttribute('src'); audio.load();
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = '';
    };
    const stop = (message = 'Reading stopped.') => {
        active?.controller.abort();
        active?.entry.classList.remove('is-reading');
        active = null; release(); stopButton.hidden = true; audio.hidden = true;
        announce(message);
    };

    // Keep sentences independent; only unusually long sentences are split at a word boundary.
    const sentences = (text) => {
        const normalized = text.replace(/\s+/gu, ' ').trim();
        const spans = typeof Intl.Segmenter === 'function'
            ? [...new Intl.Segmenter(undefined, { granularity: 'sentence' }).segment(normalized)].map((part) => part.segment.trim())
            : normalized.match(/[^.!?]+(?:[.!?]+["'’”]*|$)/gu) || [normalized];
        const result = [];
        for (const span of spans) {
            let points = Array.from(span);
            while (points.length > limit) {
                let cut = points.slice(0, limit + 1).lastIndexOf(' ');
                if (cut < 1) cut = limit;
                result.push(points.slice(0, cut).join('').trim());
                points = Array.from(points.slice(cut).join('').trim());
            }
            if (points.length) result.push(points.join(''));
        }
        return result;
    };

    // Playback completion, decode errors and cancellation each settle the current sentence once.
    const waitForPlayback = (signal) => new Promise((resolve, reject) => {
        const clean = () => {
            audio.removeEventListener('ended', ended); audio.removeEventListener('error', failed);
            signal.removeEventListener('abort', aborted);
        };
        const ended = () => { clean(); resolve(); };
        const failed = () => { clean(); reject(new Error('The generated audio could not be played.')); };
        const aborted = () => { clean(); reject(new DOMException('Stopped', 'AbortError')); };
        if (signal.aborted) { aborted(); return; }
        audio.addEventListener('ended', ended, { once: true });
        audio.addEventListener('error', failed, { once: true });
        signal.addEventListener('abort', aborted, { once: true });
        audio.play().catch(() => {
            if (!signal.aborted) announce('Audio is ready. Press Play on the audio player to continue.');
        });
    });

    root.querySelectorAll('[data-reader-play]').forEach((button) => button.addEventListener('click', async () => {
        stop('');
        const entry = button.dataset.readerTarget
            ? document.getElementById(button.dataset.readerTarget)?.querySelector('[data-reader-entry]')
            : button.closest('[data-reader-entry]');
        if (!entry) return;
        // Dialog content is modal: keep playback and cancellation inside the active entry.
        if (entry.closest('dialog')?.open) {
            entry.querySelector('.reader-entry-actions').append(stopButton, status, audio);
        } else dock?.append(stopButton, status, audio);
        const chunks = sentences(entry.querySelector('[data-reader-text]').innerText);
        if (!chunks.length) { announce('This entry has no text to read.'); return; }
        const run = { entry, controller: new AbortController() };
        active = run; entry.classList.add('is-reading'); stopButton.hidden = false; audio.hidden = false;
        try {
            for (let i = 0; i < chunks.length; i += 1) {
                if (run.controller.signal.aborted) return;
                announce(`Generating sentence ${i + 1} of ${chunks.length}…`);
                const response = await fetch(root.dataset.previewEndpoint, {
                    method: 'POST', credentials: 'same-origin', signal: run.controller.signal,
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf, Accept: 'audio/*, application/json' },
                    body: JSON.stringify({ installation_id: root.dataset.installation, configuration_id: root.dataset.connector,
                        voice: root.dataset.voice, text: chunks[i] }),
                });
                if (!response.ok) throw new Error(response.status === 429
                    ? 'Speech request limit reached. Wait before reading again.'
                    : 'Speech generation failed. Check TTS Studio and the Narrator voice, then try again.');
                if (!response.headers.get('content-type')?.startsWith('audio/')) throw new Error('The server did not return audio. Reload this page to check your session.');
                const clip = await response.blob();
                if (run.controller.signal.aborted) return;
                if (!clip.size) throw new Error('The speech service returned empty audio.');
                release(); objectUrl = URL.createObjectURL(clip); audio.src = objectUrl; audio.load();
                announce(`Reading sentence ${i + 1} of ${chunks.length}.`);
                await waitForPlayback(run.controller.signal);
            }
            if (active === run) stop('Finished reading.');
        } catch (error) {
            if (active === run) stop(error.name === 'AbortError' ? 'Reading stopped.' : error.message);
        }
    }));
    let modalTrigger = null;
    let previousOverflow = '';
    root.querySelectorAll('[data-calendar-open]').forEach(button => button.addEventListener('click', () => {
        const modal = document.getElementById(button.dataset.calendarOpen);
        if (!modal || modal.open) return;
        stop('');
        modalTrigger = button;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        modal.querySelector('form')?.reset();
        modal.querySelectorAll('details').forEach(details => { details.open = false; });
        modal.querySelectorAll('[data-reader-form-status]').forEach(message => { message.textContent = ''; });
        modal.showModal();
        const body = modal.querySelector('.modal-body');
        if (body) body.scrollTop = 0;
    }));
    root.querySelectorAll('[data-calendar-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    root.querySelectorAll('.diary-entry-modal,.diary-editor-modal').forEach(dialog => {
        dialog.addEventListener('close', () => {
            stop('');
            dock?.append(stopButton, status, audio);
            document.body.style.overflow = previousOverflow;
            modalTrigger?.focus();
        });
        dialog.addEventListener('invalid', event => {
            const details = event.target.closest('details');
            if (details) details.open = true;
        }, true);
        // Keep keyboard traversal inside the reader/editor, including fields in expanded metadata.
        dialog.addEventListener('keydown', event => {
            if (event.key !== 'Tab') return;
            const controls = [...dialog.querySelectorAll('button,input,textarea,summary,a[href],audio')]
                .filter(control => !control.disabled && control.type !== 'hidden' && control.getClientRects().length);
            const first = controls[0], last = controls.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        });
    });
    stopButton.addEventListener('click', () => stop());
    window.addEventListener('pagehide', () => stop(''));
    document.addEventListener('visibilitychange', () => { if (document.hidden && active) stop(); });
    root.querySelector('[data-reader-refresh]')?.addEventListener('click', () => { stop(''); window.location.reload(); });
    root.querySelectorAll('[data-reader-scope]').forEach((control) => control.addEventListener('change', () => {
        stop('');
        const form = control.form;
        form.elements.person.value = '';
        if (control.name === 'installation_id') form.elements.playthrough_id.value = '';
        form.requestSubmit();
    }));
    root.querySelectorAll('[data-reader-export]').forEach((button) => button.addEventListener('click', () => {
        const entry = button.closest('[data-reader-entry]');
        const title = entry.querySelector('[data-reader-title]').textContent;
        const content = `${title}\n${entry.querySelector('time').textContent}\n\n${entry.querySelector('[data-reader-text]').innerText}\n`;
        const url = URL.createObjectURL(new Blob([content], { type: 'text/plain;charset=utf-8' }));
        const link = document.createElement('a'); link.href = url;
        link.download = `${title.replace(/[^\p{L}\p{N} _-]/gu, '').slice(0, 80) || 'diary'}.txt`;
        link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    }));
    root.querySelectorAll('[data-reader-form]').forEach((form) => form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (form.hasAttribute('data-reader-delete') && !form.closest('dialog')?.open) return;
        stop('Saving entry…');
        const formStatus = form.querySelector('[data-reader-form-status]');
        if (formStatus) formStatus.textContent = 'Saving entry…';
        const submit = form.querySelector('[type="submit"]'); submit.disabled = true;
        try {
            const response = await fetch(form.action, { method: 'POST', credentials: 'same-origin', body: new FormData(form) });
            if (!response.ok || !response.redirected) throw new Error('The entry could not be saved. Reload the page and try again.');
            window.location.reload();
        } catch (error) { announce(error.message); if (formStatus) formStatus.textContent = error.message; submit.disabled = false; }
    }));
})();

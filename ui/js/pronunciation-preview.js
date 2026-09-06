/* Pronunciation previews: one shared player, one request at a time, no form ever submitted. */
(() => {
    const root = document.getElementById('pron-preview');
    if (!root || root.getAttribute('data-pron-bound') === '1') return;
    root.setAttribute('data-pron-bound', '1');

    const endpoint = root.getAttribute('data-pron-endpoint') || '';
    const installationId = root.getAttribute('data-pron-installation') || '';
    const csrf = root.getAttribute('data-pron-csrf') || '';
    const maxLength = Math.max(1, parseInt(root.getAttribute('data-pron-max-length') || '240', 10) || 240);
    const ready = root.getAttribute('data-pron-ready') === '1';
    const connectorSelect = document.getElementById('pron-preview-connector');
    const voiceSelect = document.getElementById('pron-preview-voice');
    const audio = document.getElementById('pron-preview-audio');
    const statusLine = document.getElementById('pron-preview-status');
    const buttons = Array.from(document.querySelectorAll('[data-pron-play]'));
    document.querySelectorAll('[data-pron-edit]').forEach(editButton => {
        const row=editButton.closest('form'),editor=row.querySelector('[data-pron-editor]');
        const field=editor.querySelector('input'),display=row.querySelector('[data-pron-display]');
        const action=row.querySelector('[data-pron-action]'),apply=row.querySelector('[data-pron-apply]');
        editButton.addEventListener('click',()=>{
            const editing=editButton.getAttribute('aria-expanded')==='true';
            if(editing)field.value=field.defaultValue;
            editor.hidden=editing;display.hidden=!editing;
            action.value=editing?'pronunciation_toggle':'pronunciation_builtin_save';
            apply.textContent=editing?'Apply':'Save';
            editButton.textContent=editing?'Edit':'Cancel';
            editButton.setAttribute('aria-expanded',String(!editing));
            if(!editing){field.focus();field.select();}
        });
    });
    if (buttons.length === 0) return;

    /* Each connector speaks only its own voices, so the list is rebuilt rather than shared. */
    let connectorVoices = {};
    try {
        const parsed = JSON.parse(root.getAttribute('data-pron-connector-voices') || '{}');
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) connectorVoices = parsed;
    } catch (_voicesError) {
        connectorVoices = {};
    }

    let pending = null;
    /* The previous clip is owned by this page, so it is released before another replaces it. */
    let objectUrl = '';

    const announce = (message) => { if (statusLine) statusLine.textContent = message; };

    /* Refill the voice select for the chosen connector, keeping the current voice when that
       connector also offers it so switching back and forth does not lose the selection. */
    const syncVoices = () => {
        if (!voiceSelect || !connectorSelect) return;
        const voices = connectorVoices[connectorSelect.value];
        if (!Array.isArray(voices)) return;
        const previous = voiceSelect.value;
        voiceSelect.textContent = '';
        if (voices.length === 0) {
            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = 'No voice installed';
            voiceSelect.appendChild(empty);
            voiceSelect.disabled = true;
            announce('That connector has no installed voice to preview with.');
            return;
        }
        voices.forEach((voice) => {
            const option = document.createElement('option');
            option.value = String(voice);
            option.textContent = String(voice);
            voiceSelect.appendChild(option);
        });
        voiceSelect.disabled = !ready;
        voiceSelect.value = voices.indexOf(previous) === -1 ? String(voices[0]) : previous;
        announce('Voice set to "' + voiceSelect.value + '" for this connector.');
    };

    if (connectorSelect) connectorSelect.addEventListener('change', syncVoices);

    const releaseAudio = () => {
        if (audio) {
            audio.pause();
            audio.removeAttribute('src');
            audio.load();
        }
        if (objectUrl !== '') {
            URL.revokeObjectURL(objectUrl);
            objectUrl = '';
        }
    };

    /* Editable rows are read at click time so a freshly typed value wins over the saved one. */
    const readText = (button) => {
        const inputId = button.getAttribute('data-pron-input');
        if (inputId) {
            const field = document.getElementById(inputId);
            return field ? String(field.value === null || field.value === undefined ? '' : field.value).trim() : '';
        }
        return String(button.getAttribute('data-pron-text') || '').trim();
    };

    const setBusy = (button, busy) => {
        const label = button.querySelector('.pron-play-text');
        if (busy) {
            button.setAttribute('data-pron-title', button.getAttribute('title') || '');
            if (label) {
                button.setAttribute('data-pron-label', label.textContent || '');
                label.textContent = 'Generating preview';
            }
            button.setAttribute('title', 'Generating preview');
            button.setAttribute('aria-busy', 'true');
            button.classList.add('is-busy');
            button.disabled = true;
            return;
        }
        if (label && button.hasAttribute('data-pron-label')) label.textContent = button.getAttribute('data-pron-label') || '';
        if (button.hasAttribute('data-pron-title')) button.setAttribute('title', button.getAttribute('data-pron-title') || '');
        button.removeAttribute('aria-busy');
        button.classList.remove('is-busy');
        button.disabled = false;
    };

    /* Only the shapes this endpoint documents are reported; anything else stays generic so a
       provider or credential failure never reaches the page as prose. */
    const describeFailure = async (response) => {
        let payload = null;
        try { payload = await response.json(); } catch (_parseError) { payload = null; }
        const code = payload && typeof payload === 'object' ? String(payload.error || '') : '';
        if (code === 'invalid_tts_preview_text') return 'That text is empty or longer than ' + maxLength + ' characters.';
        if (code === 'invalid_tts_preview_voice' || code === 'invalid_tts_preview_connector') {
            return 'That connector or installed voice is no longer available. Reload the page and try again.';
        }
        if (response.status === 401) return 'Your management session expired. Reload the page and try again.';
        if (response.status === 429) return 'Too many previews. Wait a moment and try again.';
        if (response.status === 502) return 'The connector could not generate this preview. Check the selected connector and voice.';
        return 'Preview failed (HTTP ' + response.status + ').';
    };

    const requestPreview = async (button) => {
        if (!ready || endpoint === '' || installationId === '') return;
        if (pending) {
            announce('A preview is still generating. Wait for it to finish, then try again.');
            return;
        }

        const text = readText(button);
        if (text === '') {
            announce('That field is empty. Type some text, then press play.');
            return;
        }
        if (text.length > maxLength) {
            announce('That text is longer than ' + maxLength + ' characters. Shorten it, then press play.');
            return;
        }
        const configurationId = connectorSelect ? connectorSelect.value : '';
        const voice = voiceSelect ? voiceSelect.value : '';
        if (configurationId === '' || voice === '') {
            announce('Choose a connector and a voice before previewing.');
            return;
        }

        pending = button;
        setBusy(button, true);
        /* A new preview supersedes the last one, so stop it before the request goes out. */
        releaseAudio();
        announce('Generating preview for "' + text + '".');
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, Accept: 'audio/*, application/json' },
                body: JSON.stringify({ installation_id: installationId, configuration_id: configurationId, voice: voice, text: text }),
            });
            if (!response.ok) {
                announce(await describeFailure(response));
                return;
            }
            const clip = await response.blob();
            if (clip.size === 0) {
                announce('The connector returned no audio for this preview.');
                return;
            }
            if (!audio) {
                announce('Preview was generated, but no player is available on this page.');
                return;
            }
            objectUrl = URL.createObjectURL(clip);
            audio.src = objectUrl;
            audio.load();
            announce('Playing "' + text + '".');
            try {
                await audio.play();
            } catch (_playError) {
                /* Autoplay can be refused before the page has been interacted with. */
                announce('Preview ready. Press play on the player above to listen.');
            }
        } catch (_error) {
            announce('The server could not be reached for this preview.');
        } finally {
            setBusy(button, false);
            pending = null;
        }
    };

    buttons.forEach((button) => {
        button.addEventListener('click', (event) => {
            /* Never let a preview reach the surrounding save, delete, or toggle form. */
            event.preventDefault();
            event.stopPropagation();
            requestPreview(button);
        });
    });
    window.addEventListener('pagehide', releaseAudio);
})();

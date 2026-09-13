(() => {
    const dirtyForms = new Set();

    // Keep drafts per playthrough; only submit edited memory entries with Save All.
    document.querySelectorAll('[data-npc-memory-editor]').forEach(editor => {
        const panels = [...editor.querySelectorAll('[data-memory-panel]')];
        panels.forEach(panel => {
            const content = panel.querySelector('[data-memory-content]');
            const revision = panel.querySelector('[data-memory-revision]');
            const original = content.value;
            const name = content.name;
            content.removeAttribute('name');
            revision.disabled = true;
            content.addEventListener('input', () => {
                const changed = content.value !== original;
                if (changed) content.name = name;
                else content.removeAttribute('name');
                revision.disabled = !changed;
            });
        });
        editor.querySelector('[data-memory-playthrough]')?.addEventListener('change', event => {
            panels.forEach(panel => { panel.hidden = panel.dataset.memoryPanel !== event.target.value; });
        });
    });


    // Keep the native audit note out of the main editor, but reveal it if validation fails.
    document.querySelectorAll('.profile-metadata [name="change_reason"]').forEach(control => {
        control.addEventListener('invalid', () => { control.closest('details').open = true; });
    });

    // Return to the editor in both standalone pages and the actual settings hub iframe.
    document.querySelectorAll('[data-profile-back-top]').forEach(button => {
        button.addEventListener('click', () => {
            const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth';
            window.scrollTo({top: 0, behavior});
            window.frameElement?.scrollIntoView({block: 'start', behavior});
        });
    });


    // Core Profile cards must describe the current checkbox state, including unsaved keyboard changes.
    document.querySelectorAll('.profiles-page .profile-toggle-control input[type="checkbox"], .profiles-page .profile-inline-toggle input[type="checkbox"]').forEach(control => {
        const label = control.parentElement.querySelector('.toggle-text');
        if (!label) return;
        const sync = () => { label.textContent = control.checked ? 'On' : 'Off'; };
        control.addEventListener('change', sync);
        control.form?.addEventListener('reset', () => window.setTimeout(sync, 0));
        sync();
    });

    // Match Herika's file picker, validation and confirmation flow without posting unrelated unsaved fields.
    [
        {kind:'player', name:'Player settings', schemas:['lorkhan.player-profile-settings.v1','lorkhan.player-profile-settings.v2'],
            form:'#player-profile-form', confirmation:'This replaces the player appearance, biography, biography visibility, personality, speech style, goals and notes. Identity, voices, connectors, autochat, diary controls and game state stay unchanged.'},
        {kind:'narrator', name:'Narration settings', schemas:['lorkhan.narrator-profile-settings.v1','lorkhan.narrator-profile-settings.v2'],
            form:'main.narrator-page form[data-track-dirty]', confirmation:'Only fields present in this file will change. Absent settings, internal identity and connector selections will be kept. A display name or diary controls included in the file will be applied.'},
        {kind:'global', name:'Global Settings', schemas:['lorkhan.global-settings-preset.v1','lorkhan.global-settings-preset.v2','lorkhan.global-settings-preset.v3'],
            form:'#gs_form', confirmation:'This saves a new Global Settings revision and replaces the settings in the imported document. Included memory scheduling and connector selections also apply. Core Profiles and NPC overrides are not imported.'}
    ].forEach(options => {
        const importForm = document.getElementById(`${options.kind}-import-form`);
        const opener = document.querySelector(`[data-${options.kind}-import-open]`);
        if (!importForm || !opener) return;
        const picker = importForm.querySelector('input[type=file]');
        let busy = false;
        opener.addEventListener('click', () => { if (!busy) picker.click(); });
        picker.addEventListener('change', async () => {
            const file = picker.files[0]; picker.value = '';
            if (!file || busy) return;
            if (file.size > 1048576) { window.alert('Import files must be 1 MB or smaller.'); return; }
            let preset;
            try { preset = JSON.parse(await file.text()); }
            catch (_) { window.alert('This file does not contain valid JSON.'); return; }
            if (!preset || !options.schemas.includes(preset.schema)
                || !preset.settings || typeof preset.settings !== 'object' || Array.isArray(preset.settings)) {
                window.alert(`This file is not a valid Lorkhan ${options.name} export.`); return;
            }
            if (!window.confirm(`Import ${Object.keys(preset.settings).length} ${options.name} fields?\n\n${options.confirmation} Unsaved page edits will be discarded.`)) return;
            busy = true; opener.disabled = true;
            const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 15000);
            try {
                const body = new FormData(importForm); body.set('preset_json', JSON.stringify(preset));
                const response = await fetch(importForm.action, {method:'POST', body, headers:{Accept:'application/json'}, signal:controller.signal});
                const result = await response.json();
                if (!response.ok || result.ok !== true) throw new Error(result.error || `HTTP ${response.status}`);
                window.alert(`${options.name} imported successfully.`);
                dirtyForms.delete(document.querySelector(options.form));
                const url = new URL(location.href); url.searchParams.set('status','imported'); location.assign(url.href);
            } catch (error) {
                window.alert(error.name === 'AbortError' ? 'Import timed out. Reload to check whether it was saved before retrying.' : `Import failed: ${error.message}`);
            } finally { clearTimeout(timer); busy = false; opener.disabled = false; opener.focus(); }
        });
    });
    const narratorCore = document.querySelector('[data-narrator-connectors]');
    if (narratorCore) {
        let summaries = null;
        try { summaries = JSON.parse(narratorCore.dataset.narratorConnectors); } catch (_) { /* Keep the server-rendered labels if malformed. */ }
        const updateNarratorConnectors = () => {
            if (!summaries || typeof summaries !== 'object') return;
            const summary = summaries[narratorCore.value] || {};
            document.querySelectorAll('[data-narrator-connector]').forEach(label => {
                label.textContent = summary[label.dataset.narratorConnector] || '—';
            });
        };
        narratorCore.addEventListener('change', updateNarratorConnectors);
        narratorCore.form?.addEventListener('reset', () => window.setTimeout(updateNarratorConnectors, 0));
        updateNarratorConnectors();
    }
    const playerTts = document.getElementById('player-tts');
    const playerTtsStatus = document.querySelector('[data-player-tts-status]');
    if (playerTts && playerTtsStatus) {
        const updatePlayerTtsStatus = () => {
            const enabled = playerTts.value !== '' && playerTts.value !== '__disabled__';
            const elevenPanel = document.getElementById('player_tts_elevenlabs_panel');
            if (elevenPanel) elevenPanel.hidden = playerTts.selectedOptions[0]?.dataset.driver !== '11labs';
            playerTtsStatus.classList.toggle('status-enabled', enabled);
            playerTtsStatus.classList.toggle('status-disabled', !enabled);
            playerTtsStatus.querySelector('[data-player-tts-status-text]').textContent = enabled ? 'Enabled' : 'Disabled';
        };
        playerTts.addEventListener('change', updatePlayerTtsStatus);
        playerTts.form?.addEventListener('reset', () => window.setTimeout(updatePlayerTtsStatus, 0));
        updatePlayerTtsStatus();
    }

    // Keep profile sliders and their submitted numeric fields synchronized without duplicate form values.
    document.querySelectorAll('[data-range-for]').forEach((slider) => {
        const number = document.getElementById(slider.dataset.rangeFor);
        if (!number || number.type !== 'number') return;
        slider.addEventListener('input', () => {
            number.value = slider.value;
            number.dispatchEvent(new Event('input', {bubbles: true}));
        });
        number.addEventListener('input', () => {
            if (number.value !== '' && Number.isFinite(number.valueAsNumber)) slider.value = number.value;
            else if (number.value === '' && slider.dataset.emptyPosition !== undefined) slider.value = slider.dataset.emptyPosition;
        });
        number.form?.addEventListener('reset', () => window.setTimeout(() => { slider.value = number.value || slider.dataset.emptyPosition || ''; }, 0));
    });

    // Lorkhan's round limit counts continuations, so include the initial reply before those probability rolls.
    const rechatOutput = document.getElementById('rechat-calc-output');
    const rechatRounds = document.getElementById('setting_behavior_rechat_max_depth');
    const rechatChance = document.getElementById('setting_behavior_rechat_probability_percent');
    if (rechatOutput && rechatRounds && rechatChance) {
        const updateRechatCalculator = () => {
            rechatOutput.replaceChildren();
            if (rechatRounds.value === '' || rechatChance.value === '' || !rechatRounds.validity.valid || !rechatChance.validity.valid) {
                rechatOutput.textContent = 'Enter valid rounds and probability to calculate responses.';
                return;
            }
            const probability = rechatChance.valueAsNumber / 100;
            for (let response = 0; response <= rechatRounds.valueAsNumber; response++) {
                if (response > 0) {
                    const separator = document.createElement('span');
                    separator.className = 'rechat-calculator-separator';
                    separator.textContent = ' | ';
                    rechatOutput.append(separator);
                }
                const chance = 100 * Math.pow(probability, response);
                const item = document.createElement('span');
                item.className = 'rechat-chance ' + (chance >= 50 ? 'high' : chance >= 25 ? 'medium' : chance >= 10 ? 'low' : 'rare');
                item.textContent = `Response ${response + 1}: ${chance.toFixed(1)}%`;
                rechatOutput.append(item);
            }
        };
        [rechatRounds, rechatChance].forEach(control => {
            control.addEventListener('input', updateRechatCalculator);
            control.addEventListener('change', updateRechatCalculator);
        });
        rechatRounds.form?.addEventListener('reset', () => window.setTimeout(updateRechatCalculator, 0));
        updateRechatCalculator();
    }

    /** Mark explicitly opted-in editors dirty without applying the guard to action or upload forms. */
    document.querySelectorAll('form[data-track-dirty]').forEach((form) => {
        const setDirty = () => {
            if (dirtyForms.has(form)) return;
            dirtyForms.add(form);
            form.classList.add('is-dirty');
            form.querySelectorAll('[data-dirty-indicator]').forEach((indicator) => { indicator.hidden = false; });
        };
        const clearDirty = () => {
            dirtyForms.delete(form);
            form.classList.remove('is-dirty');
            form.querySelectorAll('[data-dirty-indicator]').forEach((indicator) => { indicator.hidden = true; });
        };
        const handleChange = (event) => {
            const control = event.target;
            if (!(control instanceof HTMLElement) || control.matches('input[type="hidden"], button, [disabled]')) return;
            setDirty();
        };
        form.addEventListener('input', handleChange);
        form.addEventListener('change', handleChange);
        [...form.elements].filter(control => !form.contains(control)).forEach(control => {
            control.addEventListener('input', handleChange);
            control.addEventListener('change', handleChange);
        });
        form.addEventListener('submit', (event) => { if (!event.defaultPrevented) clearDirty(); });
        form.addEventListener('lorkhan:discard-draft', clearDirty);
        form.addEventListener('reset', () => window.setTimeout(clearDirty, 0));
    });

    window.addEventListener('beforeunload', (event) => {
        if (dirtyForms.size === 0) return;
        event.preventDefault();
        event.returnValue = '';
    });

    document.querySelectorAll('[data-route-select]').forEach((control) => {
        control.addEventListener('change', () => {
            const option = control.options[control.selectedIndex];
            if (option && option.dataset.url) window.location.assign(option.dataset.url);
        });
    });

    document.querySelectorAll('[data-json-import-target]').forEach((picker) => {
        picker.addEventListener('change', () => {
            const file = picker.files && picker.files[0];
            const target = document.getElementById(picker.getAttribute('data-json-import-target'));
            if (!file || !target) return;
            if (file.size > 1048576) {
                picker.value = '';
                window.alert('JSON imports are limited to 1 MiB.');
                return;
            }
            const reader = new FileReader();
            reader.onload = () => {
                target.value = typeof reader.result === 'string' ? reader.result : '';
                target.dispatchEvent(new Event('input', { bubbles: true }));
            };
            reader.onerror = () => { picker.value = ''; window.alert('The JSON file could not be read.'); };
            reader.readAsText(file, 'UTF-8');
        });
    });

    const HISTORY_COLUMNS = ['Type', 'Event', 'People Present', 'Tamrielic Time', 'Time (UTC)', 'Location', 'Actions'];
    const tabActivators = new WeakMap();
    const historyControllers = new WeakMap();

    const historyText = (value) => (value === null || value === undefined ? '' : String(value));

    const historyRequest = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: { Accept: 'application/json', ...(options.headers || {}) },
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload) throw new Error('request_failed');
        return payload;
    };

    /** Wire one NPC editor History tab: bounded reads, one typed injection, and guarded deletes. */
    const createHistoryController = (view) => {
        const endpoint = view.getAttribute('data-history-endpoint') || '';
        const csrf = view.getAttribute('data-history-csrf') || '';
        const limit = Number(view.getAttribute('data-history-limit')) || 100;
        const maxLength = Number(view.getAttribute('data-history-max-length')) || 4000;
        const recipientCap = Number(view.getAttribute('data-history-recipient-cap')) || 12;
        const extraRecipients = Math.max(0, recipientCap - 1);
        const playthrough = view.querySelector('[data-npc-history-playthrough]');
        const typeSelect = view.querySelector('[data-npc-history-type]');
        const refresh = view.querySelector('[data-npc-history-refresh]');
        const status = view.querySelector('[data-npc-history-status]');
        const errorBox = view.querySelector('[data-npc-history-error]');
        const results = view.querySelector('[data-npc-history-results]');
        const eventText = view.querySelector('[data-npc-history-event]');
        const recipients = view.querySelector('[data-npc-history-recipients]');
        const submit = view.querySelector('[data-npc-history-submit]');
        const counter = view.querySelector('[data-npc-history-count]');
        const chooseMessage = 'Choose a playthrough to load this NPC’s recent events.';
        let pending = false;
        let loaded = false;

        const setStatus = (text) => { if (status) status.textContent = text; };
        const setError = (text) => {
            if (!errorBox) return;
            errorBox.textContent = text;
            errorBox.hidden = text === '';
        };
        const setPending = (value) => {
            pending = value;
            [refresh, submit, playthrough, typeSelect, recipients, eventText].forEach((control) => {
                if (control) control.disabled = value;
            });
            if (results) results.querySelectorAll('[data-npc-history-delete]').forEach((button) => { button.disabled = value; });
        };
        const renderNotice = (message) => {
            if (!results) return;
            results.textContent = '';
            const notice = document.createElement('p');
            notice.className = 'npc-history-empty';
            notice.textContent = message;
            results.appendChild(notice);
        };
        const buildRow = (event) => {
            const row = document.createElement('tr');
            [
                historyText(event.type),
                historyText(event.data),
                historyText(event.people),
                historyText(event.game_time) || '—',
                historyText(event.time_utc),
                historyText(event.location) || '—',
            ].forEach((value, index) => {
                const cell = document.createElement('td');
                if (index === 1) {
                    const text = document.createElement('span');
                    text.className = 'npc-history-event-text';
                    text.textContent = value;
                    cell.appendChild(text);
                } else cell.textContent = value;
                row.appendChild(cell);
            });
            const actions = document.createElement('td');
            const rowId = Number(event.rowid);
            if (event.deletable === true && Number.isInteger(rowId) && rowId > 0) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'npc-history-delete';
                button.setAttribute('data-npc-history-delete', String(rowId));
                button.setAttribute('aria-label', 'Delete event ' + rowId);
                button.textContent = 'Delete';
                actions.appendChild(button);
            } else {
                const locked = document.createElement('span');
                locked.className = 'npc-history-locked';
                locked.textContent = 'Not deletable';
                actions.appendChild(locked);
            }
            row.appendChild(actions);
            return row;
        };
        const renderEvents = (events) => {
            if (!results) return;
            results.textContent = '';
            const table = document.createElement('table');
            table.className = 'npc-history-table';
            const head = document.createElement('thead');
            const headRow = document.createElement('tr');
            HISTORY_COLUMNS.forEach((label) => {
                const cell = document.createElement('th');
                cell.scope = 'col';
                cell.textContent = label;
                headRow.appendChild(cell);
            });
            head.appendChild(headRow);
            const body = document.createElement('tbody');
            events.forEach((event) => body.appendChild(buildRow(event)));
            table.appendChild(head);
            table.appendChild(body);
            results.appendChild(table);
        };
        const setTypes = (types) => {
            if (!typeSelect) return;
            const current = typeSelect.value;
            const names = types.map((type) => historyText(type)).filter((type) => type !== '');
            typeSelect.textContent = '';
            const all = document.createElement('option');
            all.value = '';
            all.textContent = 'All event types';
            typeSelect.appendChild(all);
            names.forEach((type) => {
                const option = document.createElement('option');
                option.value = type;
                option.textContent = type;
                typeSelect.appendChild(option);
            });
            typeSelect.value = names.includes(current) ? current : '';
        };
        const setRecipients = (profiles) => {
            if (!recipients) return;
            const selected = new Set([...recipients.selectedOptions].map((option) => option.value));
            recipients.textContent = '';
            profiles.forEach((profile) => {
                const profileId = historyText(profile && profile.profile_id);
                const name = historyText(profile && profile.name);
                if (profileId === '' || name === '') return;
                const option = document.createElement('option');
                option.value = profileId;
                option.textContent = name;
                option.selected = selected.has(profileId);
                recipients.appendChild(option);
            });
        };
        const updateCounter = () => {
            if (!counter || !eventText) return;
            counter.textContent = `${[...eventText.value].length} of ${maxLength} characters.`;
        };
        const load = async () => {
            if (pending) return false;
            const playthroughId = playthrough ? playthrough.value : '';
            if (!playthroughId) {
                setError('');
                setStatus(chooseMessage);
                renderNotice(chooseMessage);
                return false;
            }
            setPending(true);
            setError('');
            setStatus('Loading recent events.');
            let ok = false;
            try {
                const url = new URL(endpoint, window.location.origin);
                url.searchParams.set('playthrough_id', playthroughId);
                url.searchParams.set('limit', String(limit));
                const type = typeSelect ? typeSelect.value : '';
                if (type) url.searchParams.set('type', type);
                const payload = await historyRequest(url.toString(), { method: 'GET' });
                const data = payload.data || {};
                setTypes(Array.isArray(data.event_types) ? data.event_types : []);
                setRecipients(Array.isArray(data.recipient_profiles) ? data.recipient_profiles : []);
                const events = Array.isArray(data.events) ? data.events : [];
                if (events.length === 0) renderNotice('No events are recorded for this NPC in the selected playthrough.');
                else renderEvents(events);
                setStatus(events.length === 0 ? 'No events recorded yet.' : `Showing ${events.length} recent event${events.length === 1 ? '' : 's'}.`);
                loaded = true;
                ok = true;
            } catch (_error) {
                renderNotice('Recent events are unavailable.');
                setError('This NPC’s event history could not be loaded. Try Refresh.');
                setStatus('');
            } finally {
                setPending(false);
            }
            return ok;
        };
        const save = async () => {
            if (pending) return;
            setError('');
            const playthroughId = playthrough ? playthrough.value : '';
            const text = eventText ? eventText.value.trim() : '';
            const chosen = recipients ? [...recipients.selectedOptions].map((option) => option.value).filter(Boolean) : [];
            if (!playthroughId) { setError('Choose a playthrough before adding an event.'); return; }
            if (text === '') { setError('Enter the event text to record.'); if (eventText) eventText.focus(); return; }
            if ([...text].length > maxLength) { setError(`Event text is limited to ${maxLength} characters.`); return; }
            if (chosen.length > extraRecipients) { setError(`Choose at most ${extraRecipients} additional recipients.`); return; }
            setPending(true);
            setStatus('Saving event.');
            let saved = false;
            try {
                await historyRequest(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ playthrough_id: playthroughId, event: text, recipient_profile_ids: chosen }),
                });
                saved = true;
            } catch (_error) {
                setError('The event could not be saved. Try again.');
                setStatus('');
            } finally {
                setPending(false);
            }
            if (!saved) return;
            if (eventText) eventText.value = '';
            if (recipients) [...recipients.options].forEach((option) => { option.selected = false; });
            updateCounter();
            if (await load()) setStatus('Event saved. Recent events refreshed.');
        };
        const remove = async (rowId) => {
            if (pending || !Number.isInteger(rowId) || rowId <= 0) return;
            const playthroughId = playthrough ? playthrough.value : '';
            if (!playthroughId) return;
            if (!window.confirm('Delete this recorded event? This cannot be undone.')) return;
            setPending(true);
            setError('');
            setStatus('Deleting event.');
            let deleted = false;
            try {
                await historyRequest(`${endpoint}/${rowId}`, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ playthrough_id: playthroughId }),
                });
                deleted = true;
            } catch (_error) {
                setError('The event could not be deleted. Try Refresh.');
                setStatus('');
            } finally {
                setPending(false);
            }
            if (deleted && await load()) setStatus('Event deleted. Recent events refreshed.');
        };

        if (refresh) refresh.addEventListener('click', () => { load(); });
        if (playthrough) playthrough.addEventListener('change', () => { load(); });
        if (typeSelect) typeSelect.addEventListener('change', () => { load(); });
        if (submit) submit.addEventListener('click', () => { save(); });
        if (eventText) eventText.addEventListener('input', updateCounter);
        if (recipients) recipients.addEventListener('change', () => {
            const chosen = [...recipients.selectedOptions].length;
            setError(chosen > extraRecipients ? `Choose at most ${extraRecipients} additional recipients.` : '');
        });
        if (results) results.addEventListener('click', (event) => {
            const button = event.target.closest('[data-npc-history-delete]');
            if (!button || button.disabled) return;
            remove(Number(button.getAttribute('data-npc-history-delete')));
        });
        updateCounter();

        return { open: () => { if (!loaded && !pending) load(); } };
    };

    /** Load one NPC's event history the first time its History tab is opened, never on page load. */
    const openHistoryPanel = (modal) => {
        const view = modal && modal.querySelector('[data-npc-history-view]');
        if (!view) return;
        let controller = historyControllers.get(view);
        if (!controller) {
            controller = createHistoryController(view);
            historyControllers.set(view, controller);
        }
        controller.open();
    };

    const actionControllers = new WeakMap();
    /** Keep profile movement scoped to server-resolved identity and confirmed client receipts. */
    const openActionsPanel = (modal) => {
        const view = modal?.querySelector('[data-npc-actions]');
        if (!view || modal.hidden || view.closest('[data-npc-editor-panel]').hidden) return;
        if (actionControllers.has(view)) { actionControllers.get(view).open(); return; }
        const status = view.querySelector('[data-npc-action-status]');
        const buttons = [...view.querySelectorAll('[data-npc-action]')];
        const refresh = view.querySelector('[data-npc-action-refresh]');
        const move = buttons.find(button => button.dataset.npcAction === 'teleport');
        let busy = false, timer = null, commandId = null, scope = null, ready = false;
        const visible = () => !modal.hidden && !view.closest('[data-npc-editor-panel]').hidden;
        const notice = (text, error = false) => { status.textContent = text; status.classList.toggle('is-error', error); };
        const render = () => {
            const observed = scope?.observed;
            const returning = observed?.return_available === true;
            move.dataset.npcAction = returning ? 'return' : 'teleport';
            move.textContent = returning ? 'Return NPC' : 'Teleport';
            view.querySelector('[data-npc-move-title]').textContent = move.textContent;
            view.querySelector('[data-npc-move-description]').textContent = returning
                ? `Return this NPC to their saved location${observed.return_cell ? ': ' + observed.return_cell : ''}.`
                : 'Move this NPC to the player’s current position and save their previous location.';
            buttons.forEach(button => { button.disabled = busy || !ready || scope?.supported !== true || observed?.actor_available !== true; });
            refresh.disabled = busy || !view.dataset.profileId;
            view.setAttribute('aria-busy', busy ? 'true' : 'false');
        };
        const request = async (operation) => {
            const url = new URL(view.dataset.endpoint, window.location.origin);
            url.searchParams.set('profile_id', view.dataset.profileId);
            const abort = new AbortController();
            const timeout = window.setTimeout(() => abort.abort(), 10000);
            try {
                return await historyRequest(url, operation ? {
                    method: 'POST', signal: abort.signal,
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': view.dataset.csrf },
                    body: JSON.stringify({ profile_id: view.dataset.profileId, operation }),
                } : { signal: abort.signal });
            } finally { window.clearTimeout(timeout); }
        };
        const poll = async () => {
            timer = null;
            if (!visible()) { busy = false; ready = false; render(); return; }
            try {
                scope = await request();
                if (!scope.supported) throw new Error(scope.reason_code || 'npc_manager_unavailable');
                const item = scope.items.find(item => item.command_id === commandId);
                if (!item) throw new Error('session_changed');
                if (['queued', 'delivered'].includes(item.state)) {
                    notice(item.state === 'queued' ? 'Queued. Waiting for the game…' : 'Waiting for the game to confirm…');
                    timer = window.setTimeout(poll, 1000);
                } else {
                    busy = false;
                    ready = !!item.observed && typeof item.observed.return_available === 'boolean';
                    // Never enable movement using an older receipt after an expired or missing result.
                    scope.observed = ready ? item.observed : null;
                    const success = item.state === 'succeeded';
                    notice(success ? (item.name === 'npc.status' ? 'NPC status confirmed.' : 'Action completed in game.')
                        : `Action ${item.state}: ${item.reason_code || 'No confirmed result'}.${ready ? '' : ' Refresh status before retrying.'}`, !success);
                    if (success && item.observed?.actor_available !== true) notice('This exact NPC is not currently available in the game.', true);
                }
            } catch (_error) {
                busy = false; ready = false;
                notice('Status unavailable or the game session changed. Refresh status to reconnect. An accepted action may still complete.', true);
            }
            render();
        };
        const run = async (operation) => {
            if (busy) return;
            if (!view.dataset.profileId) { notice('Save this NPC profile before using actions.'); render(); return; }
            busy = true; ready = false; window.clearTimeout(timer); render();
            notice(operation === 'status' ? 'Checking game status…' : 'Sending action to the game…');
            try {
                scope = await request();
                if (!scope.supported) {
                    const reasons = {
                        npc_manager_unsupported: 'The connected client does not support NPC actions. Restart with the latest Lorkhan build.',
                        npc_manager_exact_actor_required: 'This profile has no complete observed NPC identity. Meet the NPC in this playthrough first.',
                    };
                    notice(reasons[scope.reason_code] || 'No matching active game session and NPC binding. Load this NPC’s playthrough first.', true);
                    busy = false; render(); return;
                }
                const pending = scope.items.find(item => ['queued', 'delivered'].includes(item.state));
                if (pending) commandId = pending.command_id;
                else commandId = (await request(operation)).command.command_id;
                await poll();
            } catch (_error) {
                busy = false; ready = false;
                notice('The request could not be confirmed. Refresh status before retrying; an accepted action may still complete.', true);
                render();
            }
        };
        buttons.forEach(button => button.addEventListener('click', () => { if (!button.disabled) run(button.dataset.npcAction); }));
        refresh.addEventListener('click', () => run('status'));
        actionControllers.set(view, { open: () => { if (!busy) run('status'); } });
        run('status');
    };

    let activeModal = null;
    let lastTrigger = null;
    const closeModal = (modal) => {
        if (!modal) return;
        if (modal.querySelector('[data-npc-core-switch][aria-busy="true"]')) return;
        modal.hidden = true;
        document.body.classList.remove('npc-modal-open');
        activeModal = null;
        if (lastTrigger && typeof lastTrigger.focus === 'function') lastTrigger.focus();
    };
    const openModal = (id, trigger) => {
        const modal = document.getElementById(id);
        if (!modal) return;
        if (activeModal) closeModal(activeModal);
        lastTrigger = trigger || null;
        modal.hidden = false;
        document.body.classList.add('npc-modal-open');
        activeModal = modal;
        openActionsPanel(modal);
        const close = modal.querySelector('[data-npc-modal-close]');
        if (close) close.focus();
        modal.querySelector('[data-npc-core-switch]')?.dispatchEvent(new Event('npc-switch-open'));
    };

    // Herika's mass switch changes Core Profile assignments, never actor identities.
    const coreSwitch = document.querySelector('[data-npc-core-switch]');
    if (coreSwitch) {
        const source = coreSwitch.elements.source_profile_id;
        const target = coreSwitch.elements.target_profile_id;
        const installation = coreSwitch.elements.installation_id;
        const confirm = coreSwitch.elements.confirm;
        const submit = coreSwitch.querySelector('button[type="submit"]');
        const status = coreSwitch.querySelector('[data-npc-switch-status]');
        const options = Array.from(source.options, option => ({id: option.value, label: option.text, installation: option.dataset.installation}));
        const validate = () => {
            submit.disabled = coreSwitch.getAttribute('aria-busy') === 'true' || confirm.value.trim() !== 'Switch'
                || !source.value || !target.value || source.value === target.value;
        };
        const populate = () => {
            const selected = document.querySelector('#npc_profile_filter')?.value;
            const available = options.filter(option => option.installation === installation.value);
            [source,target].forEach(select => select.replaceChildren(...available.map(option => new Option(option.label, option.id))));
            if (available.some(option => option.id === selected)) source.value = selected;
            target.value = available.find(option => option.id !== source.value)?.id || source.value;
            status.textContent = available.length < 2 ? 'Create at least two Core Profiles for this installation before switching.' : '';
            validate();
        };
        coreSwitch.addEventListener('npc-switch-open', () => { confirm.value = '';populate();confirm.focus(); });
        installation.addEventListener('change', populate);
        coreSwitch.addEventListener('input', validate);
        coreSwitch.addEventListener('change', validate);
        coreSwitch.addEventListener('submit', async event => {
            event.preventDefault();validate();if (submit.disabled) return;
            const body = new FormData(coreSwitch);body.set('confirm',confirm.value.trim());
            const controls = [...coreSwitch.querySelectorAll('input,select,button')];
            const disabled = controls.map(control => control.disabled);
            coreSwitch.setAttribute('aria-busy','true');controls.forEach(control => { control.disabled = true; });
            status.textContent = 'Switching profiles…';
            const abort = new AbortController();const timer = window.setTimeout(() => abort.abort(),15000);
            try {
                const response = await fetch(coreSwitch.action,{method:'POST',body,headers:{Accept:'application/json'},signal:abort.signal});
                const result = await response.json();
                if (!response.ok || result.ok !== true) throw new Error(result.error || 'Switch failed.');
                const url = new URL(window.location.href);
                url.searchParams.set('status','profiles-switched');
                url.searchParams.set('updated',String(result.updated));url.searchParams.set('skipped',String(result.skipped_locked));
                window.location.assign(url.toString());
            } catch (error) {
                status.textContent = error.name === 'AbortError' ? 'The request timed out. Reload to check assignments before trying again.' : `Profiles could not be switched: ${error.message}`;
            } finally {
                window.clearTimeout(timer);coreSwitch.removeAttribute('aria-busy');
                controls.forEach((control,index) => { control.disabled = disabled[index]; });validate();
            }
        });
        populate();
    }

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-npc-modal-target]');
        const interactive = event.target.closest('button, a, input, select, textarea, label');
        if (trigger && (!interactive || trigger === interactive)) {
            if (trigger.disabled) return;
            event.preventDefault();
            event.stopPropagation();
            openModal(trigger.getAttribute('data-npc-modal-target'), trigger);
            return;
        }
        const close = event.target.closest('[data-npc-modal-close]');
        if (close) {
            event.preventDefault();
            closeModal(close.closest('[data-npc-modal]'));
            return;
        }
        if (event.target.matches('[data-npc-modal]')) closeModal(event.target);
        const history = event.target.closest('[data-npc-history]');
        if (history) {
            event.preventDefault();
            const modal = history.closest('[data-npc-modal]');
            const tablist = modal && modal.querySelector('[data-npc-editor-tabs]');
            const activate = tablist ? tabActivators.get(tablist) : null;
            if (activate) activate('history', true);
        }
    });

    document.querySelectorAll('[data-npc-inherited]').forEach((control) => {
        const form = document.getElementById(control.dataset.profileForm);
        const profile = form?.elements.core_profile_id;
        const installation = form?.elements.installation_id;
        const toggle = control.querySelector('[data-npc-inherited-toggle]');
        const value = control.querySelector('input[type="hidden"]');
        const reset = control.querySelector('[data-npc-inherited-reset]');
        const defaults = JSON.parse(control.dataset.coreDefaults || '{}');
        const render = () => {
            const inherited = value.value === 'inherit';
            const scopedDefaults = defaults[installation?.value || control.dataset.installationId] || {};
            toggle.checked = inherited ? scopedDefaults[profile?.value || ''] === true : value.value === '1';
            control.querySelector('[data-npc-inherited-source]').textContent = inherited ? '(Inherited from profile)' : '(NPC override)';
            reset.disabled = inherited;
        };
        toggle.addEventListener('change', () => { value.value = toggle.checked ? '1' : '0'; render(); });
        reset.addEventListener('click', () => { value.value = 'inherit'; render(); toggle.dispatchEvent(new Event('input',{bubbles:true})); toggle.focus(); });
        profile?.addEventListener('change', render);
        installation?.addEventListener('change', render);
    });

    document.querySelectorAll('[data-profile-llm-summary]').forEach((summary) => {
        const form = document.getElementById(summary.dataset.profileForm);
        const select = form?.elements.core_profile_id;
        if (!select) return;
        const labels = JSON.parse(summary.dataset.profileSummaries || '{}');
        select.addEventListener('change', () => {
            summary.querySelector('span').textContent = labels[select.value] || 'Missing Core Profile';
        });
    });

    document.querySelectorAll('[data-npc-editor-tabs]').forEach((tablist, index) => {
        const modal = tablist.closest('[data-npc-modal]');
        const buttons = [...tablist.querySelectorAll('[data-npc-editor-tab]')];
        const panels = [...(modal ? modal.querySelectorAll('[data-npc-editor-panel]') : [])];
        buttons.forEach((button) => {
            const name = button.dataset.npcEditorTab;
            const panel = panels.find(item => item.dataset.npcEditorPanel === name);
            if (!panel) return;
            button.id = `npc-tab-${index}-${name}`;
            panel.id ||= `npc-panel-${index}-${name}`;
            button.setAttribute('aria-controls', panel.id);
            panel.setAttribute('aria-labelledby', button.id);
        });
        const activate = (name, focus = false) => {
            buttons.forEach((button) => {
                const active = button.getAttribute('data-npc-editor-tab') === name;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
                button.tabIndex = active ? 0 : -1;
                if (active && focus) button.focus();
            });
            panels.forEach((panel) => { panel.hidden = panel.getAttribute('data-npc-editor-panel') !== name; });
            if (name === 'history') openHistoryPanel(modal);
            if (name === 'actions') openActionsPanel(modal);
            // On-demand game/history reads never become the remembered default tab.
            if (!['history', 'actions'].includes(name)) try { window.localStorage.setItem('lorkhan-npc-editor-tab', name); } catch (_error) {}
        };
        tabActivators.set(tablist, activate);
        buttons.forEach((button) => button.addEventListener('click', () => activate(button.getAttribute('data-npc-editor-tab') || 'general')));
        tablist.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const current = Math.max(0, buttons.findIndex((button) => button.classList.contains('is-active')));
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (current + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;
            activate(buttons[next].getAttribute('data-npc-editor-tab') || 'general', true);
        });
        let initial = 'general';
        try { initial = window.localStorage.getItem('lorkhan-npc-editor-tab') || initial; } catch (_error) {}
        if (['history', 'actions'].includes(initial) || !buttons.some((button) => button.getAttribute('data-npc-editor-tab') === initial)) initial = 'general';
        activate(initial);
    });

    document.querySelectorAll('[data-npc-relationships]').forEach(view => {
        const tiers = JSON.parse(view.dataset.tiers || '[]');
        const profileForm=document.getElementById(view.dataset.profileForm);
        const addForm=view.querySelector('.npc-rel-add');
        if(profileForm&&addForm){
            const table=view.querySelector('.npc-rel-table');
            const rows=new Map();let serial=0;let clearSnapshot=null;
            const draft=document.createElement('input');draft.type='hidden';draft.name='npc_relationship_edits';
            draft.setAttribute('form',profileForm.id);view.append(draft);
            const note=view.querySelector('[data-rel-draft-status]');
            const clearForm=view.querySelector('form[action$="/relationship-clear"]');
            const clearButton=view.querySelector('[data-rel-details$="-clear"]');
            clearForm?.closest('dialog').querySelector('p').append(' Removal is staged until you save the NPC.');
            const customDescription=view.querySelector('[data-rel-custom-type]')?.closest('dialog').querySelector('p');
            if(customDescription)customDescription.textContent='Create a type such as client, mentor or servant. Select it on a relationship and save the NPC to retain it.';
            const initialCount=Number(clearForm?.querySelector('[data-rel-clear-count]')?.textContent
                ||view.querySelector('[data-rel-clear-count]')?.textContent||0);
            // Only changed rows enter the batch; unopened and undisplayed records retain their revisions.
            const readRow=form=>{
                const fields=Object.fromEntries(new FormData(form));const result={};
                for(const key of ['relationship_id','actor_profile_id','expected_revision','preview_job_id','preview_target_key','affinity','disposition','relationship_type','custom_info','reason'])
                    if(Object.hasOwn(fields,key))result[key]=fields[key];
                result.details={};
                for(const key of ['relation','note','best','worst'])if(Object.hasOwn(fields,`details[${key}]`))result.details[key]=fields[`details[${key}]`];
                return result;
            };
            const sync=()=>{
                const batch={profile_revision:Number(view.dataset.profileRevision),playthrough_id:view.dataset.playthrough,updates:[],additions:[],deletes:[]};
                let visible=0;
                for(const row of rows.values()){
                    if(row.removed){if(!row.added&&clearSnapshot===null)batch.deletes.push({relationship_id:row.initial.relationship_id,expected_revision:row.initial.expected_revision});continue;}
                    ++visible;const value=readRow(row.form);
                    if(row.added)batch.additions.push(value);
                    else if(JSON.stringify(value)!==JSON.stringify(row.initial))batch.updates.push(value);
                }
                if(clearSnapshot!==null){batch.clear_snapshot=clearSnapshot;batch.clear_confirm='Clear';}
                const changed=batch.updates.length+batch.additions.length+batch.deletes.length>0||clearSnapshot!==null;
                draft.value=changed?JSON.stringify(batch):'';
                note.textContent=changed?'Unsaved relationship changes. Click Save in the NPC header to keep them.':'Relationship changes are saved with the NPC. Use Add Custom Type for another label.';
                if(changed){dirtyForms.add(profileForm);profileForm.classList.add('is-dirty');}
                table.hidden=visible===0;
                const empty=view.querySelector('[data-rel-empty]');if(empty)empty.hidden=visible!==0;
                if(clearButton)clearButton.disabled=visible===0&&(clearSnapshot!==null||initialCount===0);
                const count=view.querySelector('[data-rel-clear-count]');
                if(count)count.textContent=String(clearSnapshot!==null?visible:initialCount+batch.additions.length-batch.deletes.length);
            };
            const removeRow=row=>{
                row.removed=true;row.element.hidden=true;
                const details=document.getElementById(row.form.id+'-details');if(details)details.hidden=true;
                for(const field of row.form.elements)field.disabled=true;
                dirtyForms.delete(row.form);row.form.classList.remove('is-dirty');
            };
            const register=(form,added=false)=>{
                const element=view.querySelector(`[data-rel-form="${CSS.escape(form.id)}"]`);
                const row={form,element,added,removed:false,initial:readRow(form)};rows.set(form.id,row);
                element.querySelector(`button[type="submit"][form="${CSS.escape(form.id)}"]`).hidden=true;
                for(const field of form.elements){field.addEventListener('input',sync);field.addEventListener('change',sync);}
                form.addEventListener('submit',event=>{event.preventDefault();event.stopImmediatePropagation();sync();},{capture:true});
                const deletion=document.getElementById(form.id+'-delete');
                deletion.addEventListener('submit',event=>{event.preventDefault();event.stopImmediatePropagation();removeRow(row);sync();},{capture:true});
            };
            view.querySelectorAll('form[action$="/relationships"][id]').forEach(form=>register(form));
            // Manual additions and generated targets use identical editable rows and draft semantics.
            const addRow=(fields,name,added=true)=>{
                const fragment=view.querySelector('[data-rel-row-template]').content.cloneNode(true);
                const id=`${profileForm.id}-relationship-new-${++serial}`;
                fragment.querySelectorAll('[id],[form],[data-rel-form],[data-rel-details]').forEach(element=>{
                    for(const attr of ['id','form','data-rel-form','data-rel-details'])if(element.hasAttribute(attr))
                        element.setAttribute(attr,element.getAttribute(attr).replace('__REL_FORM__',id));
                });
                fragment.querySelector('.npc-rel-target').textContent=name;
                fragment.querySelectorAll('[aria-label]').forEach(element=>element.setAttribute('aria-label',element.getAttribute('aria-label').replace('New relationship',name)));
                const type=fragment.querySelector('.npc-rel-type');type.replaceChildren(...[...addForm.elements.relationship_type.options].map(option=>option.cloneNode(true)));
                const newRows=[...fragment.querySelectorAll('tbody>tr')];const forms=[...fragment.querySelectorAll('form')];
                table.tBodies[0].append(...newRows);view.append(...forms);
                const form=document.getElementById(id);
                for(const [key,value] of Object.entries(fields)){
                    let field=form.elements.namedItem(key);
                    if(!field&&['relationship_id','expected_revision','preview_job_id','preview_target_key'].includes(key)){field=document.createElement('input');field.type='hidden';field.name=key;form.append(field);}
                    if(field){if(field.tagName==='SELECT'&&![...field.options].some(option=>option.value===String(value)))field.add(new Option(String(value),String(value)));field.value=value;}
                }
                register(form,added);return form;
            };
            addForm.addEventListener('submit',event=>{
                event.preventDefault();event.stopImmediatePropagation();if(!addForm.reportValidity())return;
                const selected=addForm.elements.actor_profile_id;const fields=readRow(addForm);
                if([...rows.values()].some(row=>!row.removed&&row.added&&readRow(row.form).actor_profile_id===selected.value)){
                    note.textContent='That target is already in the staged relationships.';selected.focus();return;
                }
                const form=addRow(fields,selected.selectedOptions[0].textContent.split(' — ')[0]);
                addForm.reset();dirtyForms.delete(addForm);addForm.classList.remove('is-dirty');
                form.elements.affinity.dispatchEvent(new Event('input',{bubbles:true}));sync();selected.focus();
            },{capture:true});
            const buildForm=view.querySelector('form[action$="/relationship-preview"]');
            const previewStatus=view.querySelector('[data-rel-preview-status]');
            const resume=view.querySelector('[data-rel-preview-resume]');
            let building=false;
            // Only a completed, unchanged editor receives proposals; persistence remains the header Save's job.
            const reviewBuild=async(generate)=>{
                if(building||!buildForm)return;
                if(generate&&!buildForm.reportValidity())return;
                const baseline=draft.value;
                const body=new URLSearchParams(new FormData(buildForm));
                const trigger=view.querySelector(`[data-rel-details="${buildForm.closest('dialog').id}"]`);
                const submit=buildForm.querySelector('button[type="submit"]');
                const request=async(operation,job)=>{
                    body.set('operation',operation);if(job)body.set('job_id',job);
                    const response=await fetch(buildForm.action,{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}});
                    const result=await response.json();
                    if(!response.ok)throw new Error(result.error||`Request failed (${response.status})`);
                    return result;
                };
                building=true;submit.disabled=true;resume.disabled=true;trigger.disabled=true;
                if(generate)buildForm.closest('dialog').close();
                previewStatus.textContent='Building relationships… Your saved relationships are unchanged.';
                try{
                    let job=resume.dataset.job;
                    if(generate){const queued=await request('generate');job=queued.job_id;resume.dataset.job=job;resume.hidden=false;}
                    if(!job)throw new Error('No relationship draft is available.');
                    let result;
                    for(let attempt=0;attempt<90;attempt++){
                        result=await request('status',job);
                        if(!['queued','building'].includes(result.state))break;
                        previewStatus.textContent=result.state==='queued'?'Waiting for the relationship worker…':'Analyzing recent conversations…';
                        if(!profileForm.closest('[data-npc-modal]').getClientRects().length)throw new Error('Build continues in the background. Reopen this NPC and review the result.');
                        await new Promise(resolve=>setTimeout(resolve,2000));
                    }
                    if(result.state!=='ready')throw new Error(result.state==='stale'?'This result is stale. Build again using the latest NPC and history.':result.state==='failed'?'Relationship build failed. Your saved relationships are unchanged.':'Build is still running. Use Review result to check again.');
                    if(result.profile_revision!==Number(view.dataset.profileRevision))throw new Error('This NPC changed. Reload before reviewing generated relationships.');
                    if(draft.value!==baseline)throw new Error('Your relationship edits changed during generation and were kept. Review result again when you are ready to merge generated scores.');
                    if(clearSnapshot!==null)throw new Error('Clear All is staged. Save or discard it before reviewing generated relationships.');
                    const proposals=result.relationships||[];
                    // Validate the entire merge before touching any local row.
                    if(proposals.some(candidate=>!/^([0-9a-f]{64})$/.test(candidate.target_key||'')))throw new Error('This draft predates the review editor. Build again.');
                    const savedRows=new Map((result.editor_rows||[]).map(row=>[row.relationship_id,row]));
                    for(const candidate of proposals)if(candidate.relationship_id&&![...rows.values()].some(row=>row.initial.relationship_id===candidate.relationship_id)){
                        const saved=savedRows.get(candidate.relationship_id);
                        if(!saved||Number(saved.revision)!==candidate.expected_revision)throw new Error('A generated target changed or could not be loaded. Reload before reviewing.');
                    }
                    let count=0;
                    for(const candidate of proposals){
                        let row=[...rows.values()].find(row=>candidate.relationship_id?row.initial.relationship_id===candidate.relationship_id:readRow(row.form).preview_target_key===candidate.target_key);
                        if(row?.removed)continue;
                        if(!row&&candidate.relationship_id){
                            const saved=savedRows.get(candidate.relationship_id);
                            const baseline={relationship_id:saved.relationship_id,expected_revision:saved.revision,affinity:saved.affinity,
                                disposition:saved.disposition,relationship_type:saved.relationship_type,custom_info:saved.custom_info||'',reason:'Manual edit'};
                            for(const key of ['relation','note','best','worst'])baseline[`details[${key}]`]=saved.details?.[key]||'';
                            const form=addRow(baseline,saved.actor,false);row=rows.get(form.id);
                        }
                        const fields={affinity:candidate.affinity,disposition:candidate.disposition,relationship_type:candidate.relationship_type,reason:candidate.reason,
                            preview_job_id:job,preview_target_key:candidate.target_key};
                        let form=row?.form;
                        if(!form){
                            form=addRow(fields,candidate.actor_identity?.display_name||candidate.actor_identity?.record_id||'Generated target');
                        }else for(const [key,value] of Object.entries(fields)){
                            let field=form.elements.namedItem(key);
                            if(!field){field=document.createElement('input');field.type='hidden';field.name=key;form.append(field);}
                            if(field.tagName==='SELECT'&&![...field.options].some(option=>option.value===String(value)))field.add(new Option(String(value),String(value)));field.value=value;
                        }
                        form.elements.affinity.dispatchEvent(new Event('input',{bubbles:true}));++count;
                    }
                    sync();previewStatus.textContent=`${count} generated relationship${count===1?'':'s'} staged. Review the rows and click Save in the NPC header.`;
                    resume.hidden=true;buildForm.elements.request_id.value=crypto.randomUUID();
                }catch(error){
                    const messages={relationship_build_no_connector:'Choose a Relationship LLM before building.',relationship_build_no_history:'No eligible played conversations were found.',relationship_build_locked:'Relationship Lock is enabled.',relationship_build_pending:'A build is already pending. Reload to review its status.'};
                    previewStatus.textContent=messages[error.message]||error.message||'Build failed. Your editor draft is unchanged.';
                }finally{building=false;submit.disabled=false;resume.disabled=false;trigger.disabled=false;}
            };
            buildForm?.addEventListener('submit',event=>{event.preventDefault();event.stopImmediatePropagation();reviewBuild(true);},{capture:true});
            resume?.addEventListener('click',()=>reviewBuild(false));
            clearForm?.addEventListener('submit',event=>{
                event.preventDefault();event.stopImmediatePropagation();if(!clearForm.reportValidity())return;
                if(initialCount>0)clearSnapshot=clearForm.elements.snapshot_token.value;
                for(const row of rows.values())removeRow(row);
                sync();clearForm.closest('dialog').close();addForm.elements.actor_profile_id.focus();
            },{capture:true});
            profileForm.addEventListener('submit',async event=>{
                sync();
                for(const row of rows.values())if(!row.removed&&!row.form.reportValidity()){event.preventDefault();break;}
                if(event.defaultPrevented){dirtyForms.add(profileForm);profileForm.classList.add('is-dirty');return;}
                const saved=()=>{for(const form of [profileForm,addForm,...[...rows.values()].map(row=>row.form)])dirtyForms.delete(form);};
                if(!draft.value){saved();return;}
                event.preventDefault();
                if(profileForm.getAttribute('aria-busy')==='true')return;
                const body=new URLSearchParams(new FormData(profileForm));
                const controls=[...(profileForm.closest('[data-npc-modal]')||view).querySelectorAll('input,textarea,select,button')].map(control=>[control,control.disabled]);
                profileForm.setAttribute('aria-busy','true');controls.forEach(([control])=>{control.disabled=true;});
                note.textContent='Saving NPC and relationship changes…';dirtyForms.add(profileForm);
                try{
                    const response=await fetch(profileForm.getAttribute('action'),{method:'POST',body,credentials:'same-origin'});
                    const destination=new URL(response.url);
                    if(!response.ok)throw new Error(response.status===409?'The NPC or a relationship changed. Your draft is still here; review the latest saved values before retrying.':`Save failed (${response.status}). Your draft is still here.`);
                    if(destination.origin!==location.origin||!destination.pathname.endsWith('/ui/core/npc_master.php')
                        ||destination.searchParams.get('status')!=='npc_relationships_saved')throw new Error('Save was not confirmed. Your draft is still here; check your management session.');
                    saved();window.location.assign(destination.href);
                }catch(error){note.textContent=error instanceof Error?error.message:'Save failed. Your draft is still here.';profileForm.classList.add('is-dirty');}
                finally{profileForm.removeAttribute('aria-busy');controls.forEach(([control,disabled])=>{control.disabled=disabled;});}
            });
            sync();
        }
        const detailsDialog=view.querySelector('[data-rel-detail-dialog]');
        let detailForm=null;let detailTrigger=null;
        const detailFields=[...(detailsDialog?.querySelectorAll('[data-rel-detail-field]')||[])];
        detailsDialog?.querySelector('[data-rel-detail-cancel]').addEventListener('click',()=>detailsDialog.close());
        detailsDialog?.querySelector('[data-rel-detail-save]').addEventListener('click',()=>{
            if(!detailForm||detailFields.some(field=>!field.reportValidity()))return;
            if(detailFields.every(field=>detailForm.elements.namedItem(field.dataset.relDetailField).value===field.value)){detailsDialog.close();return;}
            for(const field of detailFields)detailForm.elements.namedItem(field.dataset.relDetailField).value=field.value;
            detailForm.elements.namedItem('custom_info').dispatchEvent(new Event('change',{bubbles:true}));
            const row=view.querySelector(`[data-rel-form="${detailForm.id}"]`);
            if(row){
                const signals=row.querySelector('.npc-rel-signals');const previous=[...signals.children];signals.replaceChildren();
                for(const [key,label,className] of [['note','Last','npc-rel-last'],['best','Best','npc-rel-positive'],['worst','Worst','npc-rel-negative']]){
                    const value=detailForm.elements.namedItem(`details[${key}]`).value;
                    if(value){let line=previous.find(item=>item.className===className&&item.title===value);
                        if(!line){line=document.createElement('div');line.className=className;line.title=value;line.textContent=`${label}: ${value}`;}signals.append(line);}
                }
                if(!signals.childElementCount){const empty=document.createElement('span');empty.className='npc-rel-empty';empty.textContent='No signals';signals.append(empty);}
            }
            detailsDialog.close();
        });
        const suggestions=detailsDialog?.querySelector('.npc-rel-suggestions');
        const suggestionsToggle=detailsDialog?.querySelector('[data-rel-suggestions-toggle]');
        suggestionsToggle?.addEventListener('click',()=>{suggestions.hidden=!suggestions.hidden;suggestionsToggle.setAttribute('aria-expanded',String(!suggestions.hidden));});
        detailsDialog?.querySelectorAll('[data-rel-suggestion]').forEach(button=>button.addEventListener('click',()=>{
            const field=detailsDialog.querySelector('[data-rel-detail-field="details[relation]"]');field.value=button.dataset.relSuggestion;
            suggestions.hidden=true;suggestionsToggle.setAttribute('aria-expanded','false');field.focus();
        }));
        detailsDialog?.addEventListener('close',()=>{detailTrigger?.setAttribute('aria-expanded','false');detailTrigger?.focus();detailForm=null;});
        const custom = view.querySelector('[data-rel-custom-type]');
        custom?.addEventListener('submit', event => {
            event.preventDefault();
            if (!custom.reportValidity()) return;
            const type = custom.elements.custom_type.value.trim().toLowerCase();
            view.querySelectorAll('select[name="relationship_type"]').forEach(select => {
                if (![...select.options].some(option => option.value === type)) select.add(new Option(`🏷️ ${type[0].toUpperCase()}${type.slice(1)}`,type));
            });
            custom.querySelector('[data-rel-custom-status]').textContent = 'Type available. Select it on a row and save the NPC.';
        });
        view.addEventListener('input', event => {
            const control=event.target.closest('.npc-rel-aff');if(!control)return;
            const tier = tiers.find(item => Number(control.value) >= item[0]);
            const badge = control.closest('[data-npc-rel-row]')?.querySelector('.npc-rel-tier');
            if (!badge || !tier || !control.validity.valid) return;
            badge.textContent = tier[1];badge.style.color = tier[2];
        });
        view.addEventListener('click', event => {
            const button=event.target.closest('[data-rel-details]');if(!button||!view.contains(button))return;
            const panel = document.getElementById(button.dataset.relDetails);
            if (!panel) return;
            if(detailsDialog&&panel.matches('tr')&&button.closest('[data-npc-rel-row]')){
                detailForm=document.getElementById(button.closest('[data-npc-rel-row]').dataset.relForm);detailTrigger=button;
                detailsDialog.querySelector('[data-rel-detail-target]').textContent=button.closest('tr').querySelector('.npc-rel-target').textContent;
                for(const field of detailFields)field.value=detailForm.elements.namedItem(field.dataset.relDetailField).value;
                suggestions.hidden=true;suggestionsToggle.setAttribute('aria-expanded','false');
                detailsDialog.hidden=false;button.setAttribute('aria-controls',detailsDialog.id);button.setAttribute('aria-expanded','true');detailsDialog.showModal();return;
            }
            button.setAttribute('aria-controls', panel.id);
            if (panel instanceof HTMLDialogElement) {
                if (panel.open) panel.close();
                else {panel.hidden=false;panel.showModal();button.setAttribute('aria-expanded','true');}
                return;
            }
            panel.hidden = !panel.hidden;
            view.querySelectorAll('[data-rel-details]').forEach(toggle => {
                if (toggle.dataset.relDetails === panel.id) toggle.setAttribute('aria-expanded', String(!panel.hidden));
            });
            if (!panel.hidden) panel.querySelector('input,select,textarea,button')?.focus();
            else view.querySelector(`[data-rel-details="${panel.id}"]`)?.focus();
        });
        view.querySelectorAll('dialog.npc-rel-build').forEach(dialog => {
            dialog.addEventListener('keydown', event => {if(event.key==='Escape')event.stopPropagation();});
            dialog.addEventListener('close', () => {
                dialog.hidden=true;
                const direction=dialog.querySelector('textarea[name="direction"]');
                if(direction)direction.value='';
                const trigger=view.querySelector(`[data-rel-details="${dialog.id}"]`);
                trigger?.setAttribute('aria-expanded','false');trigger?.focus();
            });
        });
    });
    // Revisioned relationship forms return to the same NPC and category, including conflicts.
    const relationshipReturn = new URLSearchParams(window.location.search).get('rel_profile');
    if (relationshipReturn && /^[0-9a-f-]{36}$/.test(relationshipReturn)) {
        const form = document.getElementById(`management-form-profile-${relationshipReturn}`);
        const modal = form?.closest('[data-npc-modal]');
        const tabs = modal?.querySelector('[data-npc-editor-tabs]');
        if (modal && tabs) { openModal(modal.id);tabActivators.get(tabs)?.('relationships',true); }
    }
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Tab' && activeModal && !document.querySelector('dialog[open]')) {
            const focusable = [...activeModal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])')]
                .filter((element) => element.getClientRects().length > 0);
            if (focusable.length > 0) {
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (!activeModal.contains(document.activeElement) || (!event.shiftKey && document.activeElement === last)) {
                    event.preventDefault();
                    first.focus();
                } else if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                }
            }
        }
        if (event.key === 'Escape' && activeModal) closeModal(activeModal);
        const card = event.target.closest('.npc-card[data-npc-modal-target]');
        if (card && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            openModal(card.getAttribute('data-npc-modal-target'), card);
        }
    });

    const filterForm = document.querySelector('[data-npc-filter-form]');
    if (filterForm) {
        let timer = null;
        const search = filterForm.querySelector('input[name="q"]');
        const profile = filterForm.querySelector('select[name="profile"]');
        if (search) search.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => filterForm.submit(), 320);
        });
        if (profile) profile.addEventListener('change', () => filterForm.submit());
    }

    const updateNpcQuery = (name, value, resetPage = true) => {
        const url = new URL(window.location.href);
        if (value === '') url.searchParams.delete(name);
        else url.searchParams.set(name, value);
        if (resetPage) url.searchParams.delete('page');
        window.location.assign(url.toString());
    };
    document.querySelectorAll('.npc-page-link[data-page]').forEach((control) => {
        control.addEventListener('click', () => {
            if (!control.disabled) updateNpcQuery('page', control.getAttribute('data-page') || '1', false);
        });
    });
    document.querySelectorAll('.npc-letter-btn[data-letter]').forEach((control) => {
        control.addEventListener('click', () => updateNpcQuery('initial', control.getAttribute('data-letter') || ''));
    });

    const filterButton = document.querySelector('[data-filter-menu-toggle]');
    const filterMenu = document.querySelector('[data-filter-menu]');
    if (filterButton && filterMenu) {
        filterButton.addEventListener('click', () => {
            const opening = filterMenu.hidden;
            filterMenu.hidden = !opening;
            filterButton.setAttribute('aria-expanded', opening ? 'true' : 'false');
        });
        filterMenu.querySelectorAll('input[type="checkbox"]:not(:disabled)').forEach((control) => {
            control.addEventListener('change', () => filterMenu.submit());
        });
    }
    document.querySelectorAll('[data-auto-lock-form] input[type="checkbox"]').forEach((control) => {
        control.addEventListener('change', () => control.form.submit());
    });
})();

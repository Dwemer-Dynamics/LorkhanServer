(() => {
    const dirtyForms = new Set();

    // Portable imports stay outside the unsaved Player and Narrator profile forms.
    ['player','narrator'].forEach(kind => {
        const dialog = document.getElementById(`${kind}-import-dialog`);
        const opener = document.querySelector(`[data-${kind}-import-open]`);
        if (!dialog || !opener) return;
        opener.addEventListener('click', () => dialog.showModal());
        dialog.querySelectorAll(`[data-${kind}-import-close]`).forEach(button => button.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('close', () => opener.focus());
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
        });
        number.form?.addEventListener('reset', () => window.setTimeout(() => { slider.value = number.value; }, 0));
    });

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
            // History is read on demand, so it never becomes the remembered default tab.
            else try { window.localStorage.setItem('lorkhan-npc-editor-tab', name); } catch (_error) {}
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
        if (initial === 'history' || !buttons.some((button) => button.getAttribute('data-npc-editor-tab') === initial)) initial = 'general';
        activate(initial);
    });

    document.querySelectorAll('[data-npc-relationships]').forEach(view => {
        const tiers = JSON.parse(view.dataset.tiers || '[]');
        const custom = view.querySelector('[data-rel-custom-type]');
        custom?.addEventListener('submit', event => {
            event.preventDefault();
            if (!custom.reportValidity()) return;
            const type = custom.elements.custom_type.value.trim().toLowerCase();
            view.querySelectorAll('select[name="relationship_type"]').forEach(select => {
                if (![...select.options].some(option => option.value === type)) select.add(new Option(`🏷️ ${type[0].toUpperCase()}${type.slice(1)}`,type));
            });
            custom.querySelector('[data-rel-custom-status]').textContent = 'Type available. Select it on a row and save.';
        });
        view.querySelectorAll('.npc-rel-aff').forEach(control => control.addEventListener('input', () => {
            const tier = tiers.find(item => Number(control.value) >= item[0]);
            const badge = control.closest('[data-npc-rel-row]')?.querySelector('.npc-rel-tier');
            if (!badge || !tier || !control.validity.valid) return;
            badge.textContent = tier[1];badge.style.color = tier[2];
        }));
        view.querySelectorAll('[data-rel-details]').forEach(button => button.addEventListener('click', () => {
            const panel = document.getElementById(button.dataset.relDetails);
            if (!panel) return;
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
        }));
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
        if (event.key === 'Tab' && activeModal) {
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

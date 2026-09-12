/* Core Profile assignment rules: list and edit the rules used when an unknown NPC is first discovered. */
(() => {
    const overlay = document.querySelector('[data-profile-rules-overlay]');
    if (!overlay) return;

    const dialog = overlay.querySelector('[data-profile-rules-dialog]');
    const statusLine = overlay.querySelector('[data-profile-rules-status]');
    const listView = overlay.querySelector('[data-profile-rules-list-view]');
    const listHost = overlay.querySelector('[data-profile-rules-list]');
    const listOrder = overlay.querySelector('[data-profile-rules-order]');
    const form = overlay.querySelector('[data-profile-rules-form]');
    const formAnchor = document.createComment('Rule editor home');
    form.before(formAnchor);
    const formTitle = overlay.querySelector('[data-profile-rules-form-title]');
    const formError = overlay.querySelector('[data-profile-rules-error]');
    const descriptionInput = overlay.querySelector('[data-profile-rules-description]');
    const profileSelect = overlay.querySelector('[data-profile-rules-profile]');
    const priorityInput = overlay.querySelector('[data-profile-rules-priority]');
    const enabledInput = overlay.querySelector('[data-profile-rules-enabled]');
    const confirmBox = overlay.querySelector('[data-profile-rules-confirm]');
    const confirmText = overlay.querySelector('[data-profile-rules-confirm-text]');
    const confirmDelete = overlay.querySelector('[data-profile-rules-confirm-delete]');
    const confirmCancel = overlay.querySelector('[data-profile-rules-confirm-cancel]');
    const newButton = overlay.querySelector('[data-profile-rules-new]');
    const reloadButton = overlay.querySelector('[data-profile-rules-reload]');
    const saveButton = overlay.querySelector('[data-profile-rules-save]');
    const deleteButton = overlay.querySelector('[data-profile-rules-delete]');
    const cancelButton = overlay.querySelector('[data-profile-rules-cancel]');
    const openButtons = Array.from(document.querySelectorAll('[data-profile-rules-open]'));
    if (!dialog || !statusLine || !listHost || !form || !profileSelect || openButtons.length === 0) return;

    const endpoint = dialog.getAttribute('data-profile-rules-endpoint') || '';
    const csrf = dialog.getAttribute('data-profile-rules-csrf') || '';
    const installationId = dialog.getAttribute('data-profile-rules-installation') || '';

    const MATCH_FIELDS = ['names', 'races', 'classes', 'genders', 'factions', 'content_files'];
    const MATCH_LABELS = {
        names: 'Names',
        races: 'Races',
        classes: 'Classes',
        genders: 'Genders',
        factions: 'Factions',
        content_files: 'Content files',
    };
    const SUMMARY_LIMIT = 4;

    const text = (value) => (value === null || value === undefined ? '' : String(value));
    const listOf = (value) => (Array.isArray(value)
        ? value.map(text).map((entry) => entry.trim()).filter((entry) => entry !== '')
        : []);

    /* Rules, profiles and option lists are fetched only after the dialog is opened. */
    let data = null;
    let loading = false;
    let loadFailed = false;
    let busy = false;
    let view = 'list';
    let editingId = null;
    let draft = null;
    let opener = null;
    const matchFieldNodes = new Map();

    MATCH_FIELDS.forEach((key) => {
        const host = overlay.querySelector('[data-profile-rules-match="' + key + '"]');
        if (!host) return;
        matchFieldNodes.set(key, {
            host: host,
            input: host.querySelector('[data-profile-rules-match-input]'),
            add: host.querySelector('[data-profile-rules-match-add]'),
            detected: host.querySelector('[data-profile-rules-detected]'),
            addDetected: host.querySelector('[data-profile-rules-detected-add]'),
            values: host.querySelector('[data-profile-rules-match-values]'),
            empty: host.querySelector('[data-profile-rules-match-empty]'),
            options: host.querySelector('[data-profile-rules-options]'),
        });
    });

    const focusableIn = () => Array.from(dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'))
        .filter((node) => !node.hasAttribute('disabled') && !node.hidden && node.offsetParent !== null);

    const announce = (message) => { statusLine.textContent = message; };

    const showError = (message) => {
        formError.textContent = message;
        formError.hidden = message === '';
    };

    const emptyDraft = () => ({
        rule_id: null,
        description: '',
        core_profile_id: '',
        priority: 0,
        enabled: true,
        match: MATCH_FIELDS.reduce((carry, key) => { carry[key] = []; return carry; }, {}),
    });

    const profileLabel = (coreProfileId) => {
        const profiles = data && Array.isArray(data.core_profiles) ? data.core_profiles : [];
        const match = profiles.find((profile) => text(profile.core_profile_id) === text(coreProfileId));
        return match ? (text(match.label) || 'Untitled Core Profile') : 'Missing Core Profile';
    };

    const matchSummary = (match) => {
        const parts = [];
        MATCH_FIELDS.forEach((key) => {
            const values = listOf(match ? match[key] : []);
            if (values.length === 0) return;
            const shown = values.slice(0, SUMMARY_LIMIT).join(' or ');
            const extra = values.length > SUMMARY_LIMIT ? ' or ' + (values.length - SUMMARY_LIMIT) + ' more' : '';
            parts.push(MATCH_LABELS[key] + ': ' + shown + extra);
        });
        return parts;
    };

    const sortedRules = () => {
        const rules = data && Array.isArray(data.rules) ? data.rules : [];
        return rules
            .map((rule, index) => ({ rule: rule, index: index }))
            .sort((left, right) => {
                const byPriority = Number(right.rule.priority || 0) - Number(left.rule.priority || 0);
                return byPriority !== 0 ? byPriority : left.index - right.index;
            })
            .map((entry) => entry.rule);
    };

    const renderList = () => {
        formAnchor.after(form);
        listHost.textContent = '';
        const rules = sortedRules();
        listOrder.hidden = rules.length < 2;
        if (rules.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'profile-rules-empty';
            empty.textContent = 'No assignment rules yet. A newly discovered NPC keeps the default Core Profile until you add one.';
            listHost.append(empty);
            return;
        }
        rules.forEach((rule) => {
            const row = document.createElement('article');
            row.dataset.ruleId = text(rule.rule_id);
            row.className = 'profile-rules-row' + (rule.enabled ? '' : ' is-off');

            const head = document.createElement('div');
            head.className = 'profile-rules-row-head';
            const title = document.createElement('h3');
            title.className = 'profile-rules-row-title';
            title.textContent = text(rule.description) || 'Untitled rule';
            const state = document.createElement('span');
            state.className = 'profile-rules-pill profile-rules-pill-' + (rule.enabled ? 'on' : 'off');
            state.textContent = rule.enabled ? 'Enabled' : 'Disabled';
            const titleRow = document.createElement('div');
            titleRow.className = 'profile-rules-title-row';
            titleRow.append(title, state);
            head.append(titleRow);

            const summary = document.createElement('div');
            summary.className = 'profile-rules-row-match';
            const target = document.createElement('strong');
            target.className = 'profile-rules-summary-target';
            target.textContent = (text(rule.core_profile_label) || profileLabel(rule.core_profile_id));
            summary.append(target);
            const matches = matchSummary(rule.match);
            for (const caption of (matches.length ? matches : ['No match fields: this rule never runs'])) {
                const chip = document.createElement('span');
                chip.className = 'profile-rules-summary-chip';
                chip.textContent = caption;
                summary.append(chip);
            }
            const priority = document.createElement('span');
            priority.className = 'profile-rules-summary-chip';
            priority.textContent = 'Priority: ' + String(Number(rule.priority || 0));
            summary.append(priority);

            const actions = document.createElement('div');
            actions.className = 'profile-rules-row-actions';
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'btn-base';
            edit.textContent = '✎ Edit';
            edit.setAttribute('aria-label', 'Edit rule ' + (text(rule.description) || 'Untitled rule'));
            edit.addEventListener('click', () => openForm(rule));
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn-danger';
            remove.textContent = '× Delete';
            remove.setAttribute('aria-label', 'Delete rule ' + (text(rule.description) || 'Untitled rule'));
            remove.addEventListener('click', () => { openForm(rule); showConfirm(); });
            actions.append(edit, remove);
            head.append(actions);
            row.append(head, summary);
            listHost.append(row);
        });
    };

    const renderProfileOptions = () => {
        const current = draft ? text(draft.core_profile_id) : '';
        profileSelect.textContent = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Choose a Core Profile';
        profileSelect.append(placeholder);
        const profiles = data && Array.isArray(data.core_profiles) ? data.core_profiles : [];
        profiles.forEach((profile) => {
            const option = document.createElement('option');
            option.value = text(profile.core_profile_id);
            option.textContent = (text(profile.label) || 'Untitled Core Profile')
                + (profile.default_npc ? ' (default NPC profile)' : '');
            profileSelect.append(option);
        });
        profileSelect.value = current;
    };

    const renderOptionLists = () => {
        const options = data && typeof data.options === 'object' && data.options !== null ? data.options : {};
        matchFieldNodes.forEach((nodes, key) => {
            if (!nodes.options) return;
            nodes.options.textContent = '';
            const selected = draft ? listOf(draft.match[key]).map(value => value.toLowerCase()) : [];
            const available = [...new Set(listOf(options[key]))].filter(value => !selected.includes(value.toLowerCase()))
                .sort((a,b) => a.localeCompare(b, undefined, {sensitivity:'base'}));
            if (nodes.detected) {
                nodes.detected.replaceChildren(new Option('Select a detected value', ''));
                available.forEach(value => nodes.detected.add(new Option(value, value)));
            }
            available.forEach((value) => {
                const option = document.createElement('option');
                option.value = value;
                nodes.options.append(option);
            });
        });
    };

    const renderMatchValues = (key) => {
        const nodes = matchFieldNodes.get(key);
        if (!nodes) return;
        const values = draft ? listOf(draft.match[key]) : [];
        nodes.values.textContent = '';
        nodes.values.hidden = values.length === 0;
        nodes.empty.hidden = values.length > 0;
        values.forEach((value) => {
            const item = document.createElement('li');
            item.className = 'profile-rules-value';
            const label = document.createElement('span');
            label.className = 'profile-rules-value-text';
            label.textContent = value;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'profile-rules-value-remove';
            remove.textContent = '×';
            remove.title = 'Remove';
            remove.setAttribute('aria-label', 'Remove ' + value + ' from ' + MATCH_LABELS[key]);
            remove.addEventListener('click', () => {
                draft.match[key] = listOf(draft.match[key]).filter((entry) => entry !== value);
                renderMatchValues(key);
                showError('');
                announce('Removed ' + value + ' from ' + MATCH_LABELS[key] + '.');
                if (nodes.input) nodes.input.focus();
            });
            item.append(label, remove);
            nodes.values.append(item);
        });
        renderOptionLists();
    };

    const addMatchValue = (key) => {
        const nodes = matchFieldNodes.get(key);
        if (!nodes || !nodes.input || !draft) return;
        const candidate = text(nodes.input.value).trim();
        if (candidate === '') {
            announce('Type a value before adding it to ' + MATCH_LABELS[key] + '.');
            nodes.input.focus();
            return;
        }
        if (candidate.length > 256) {
            announce('Each value in ' + MATCH_LABELS[key] + ' must be 256 characters or fewer.');
            nodes.input.focus();
            return;
        }
        const existing = listOf(draft.match[key]);
        if (existing.length >= 32) {
            announce(MATCH_LABELS[key] + ' already has the maximum of 32 values.');
            nodes.input.focus();
            return;
        }
        const duplicate = existing.some((entry) => entry.toLowerCase() === candidate.toLowerCase());
        if (!duplicate) existing.push(candidate);
        draft.match[key] = existing;
        nodes.input.value = '';
        renderMatchValues(key);
        showError('');
        announce(duplicate
            ? 'Already listed in ' + MATCH_LABELS[key] + '.'
            : 'Added ' + candidate + ' to ' + MATCH_LABELS[key] + '.');
        nodes.input.focus();
    };

    const syncControls = () => {
        const inList = view === 'list';
        const noProfiles = !(data && Array.isArray(data.core_profiles) && data.core_profiles.length > 0);
        listView.hidden = false;
        formAnchor.after(form);
        listHost.querySelector('[data-new-rule-card]')?.remove();
        for (const row of listHost.querySelectorAll('[data-rule-id]')) {
            const active = !inList && row.dataset.ruleId === editingId;
            row.classList.toggle('editing', active);
            row.querySelector('.profile-rules-row-match').hidden = active;
            row.querySelector('.profile-rules-row-actions').hidden = active;
            row.querySelectorAll('.profile-rules-row-actions button').forEach(button => { button.disabled = busy; });
            if (active) row.append(form);
        }
        const empty = listHost.querySelector('.profile-rules-empty');
        if (empty) empty.hidden = !inList;
        if (!inList && editingId === null) {
            const card = document.createElement('article');
            card.className = 'profile-rules-row editing';
            card.dataset.newRuleCard = '';
            card.append(form);
            listHost.prepend(card);
        }
        form.hidden = inList;
        newButton.hidden = !inList || loadFailed;
        newButton.disabled = loading || noProfiles;
        reloadButton.hidden = !inList;
        reloadButton.disabled = loading;
        saveButton.hidden = inList;
        saveButton.disabled = busy;
        saveButton.textContent = busy ? 'Saving' : 'Save rule';
        deleteButton.hidden = inList || editingId === null;
        deleteButton.disabled = busy || !confirmBox.hidden;
        cancelButton.hidden = inList;
        cancelButton.disabled = busy;
    };

    function hideConfirm() {
        confirmBox.hidden = true;
        confirmText.textContent = '';
    }

    function openForm(rule) {
        draft = emptyDraft();
        editingId = null;
        if (rule) {
            editingId = text(rule.rule_id);
            draft.rule_id = editingId;
            draft.description = text(rule.description);
            draft.core_profile_id = text(rule.core_profile_id);
            draft.priority = Number(rule.priority || 0);
            draft.enabled = rule.enabled !== false;
            MATCH_FIELDS.forEach((key) => { draft.match[key] = listOf(rule.match ? rule.match[key] : []); });
        }
        formTitle.textContent = editingId === null ? 'New assignment rule' : 'Edit assignment rule';
        descriptionInput.value = draft.description;
        priorityInput.value = String(draft.priority);
        enabledInput.checked = draft.enabled;
        renderProfileOptions();
        MATCH_FIELDS.forEach((key) => {
            const nodes = matchFieldNodes.get(key);
            if (nodes && nodes.input) nodes.input.value = '';
            renderMatchValues(key);
        });
        showError('');
        hideConfirm();
        view = 'form';
        syncControls();
        announce(editingId === null
            ? 'Describe the new rule, choose its Core Profile, then fill at least one match field.'
            : 'Editing ' + (draft.description || 'this rule') + '. Nothing is saved until you press Save rule.');
        descriptionInput.focus();
    }

    const showList = (message) => {
        view = 'list';
        editingId = null;
        draft = null;
        hideConfirm();
        showError('');
        renderList();
        syncControls();
        if (message) announce(message);
        if (!newButton.hidden && !newButton.disabled) newButton.focus();
        else if (!reloadButton.hidden && !reloadButton.disabled) reloadButton.focus();
    };

    const collectDraft = () => {
        const priorityText = text(priorityInput.value).trim();
        return {
            description: text(descriptionInput.value).trim(),
            core_profile_id: text(profileSelect.value),
            priority: /^-?\d+$/.test(priorityText) ? Number(priorityText) : Number.NaN,
            enabled: enabledInput.checked,
        };
    };

    const validate = (current) => {
        if (current.description === '') {
            return { field: descriptionInput, message: 'Add a short description so this rule can be recognised in the list.' };
        }
        if (current.core_profile_id === '') {
            return { field: profileSelect, message: 'Choose the Core Profile this rule assigns.' };
        }
        if (!Number.isSafeInteger(current.priority) || current.priority < -100000 || current.priority > 100000) {
            return { field: priorityInput, message: 'Priority must be a whole number from -100000 to 100000.' };
        }
        const populated = MATCH_FIELDS.filter((key) => listOf(draft.match[key]).length > 0);
        if (populated.length === 0) {
            const first = matchFieldNodes.get(MATCH_FIELDS[0]);
            return { field: first ? first.input : null, message: 'Add at least one match value. A rule with no match fields would never run.' };
        }
        return null;
    };

    const send = async (body) => {
        const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, Accept: 'application/json' },
            body: JSON.stringify(body),
        });
        let payload = null;
        try { payload = await response.json(); } catch (_parseError) { payload = null; }
        if (!response.ok) {
            const reported = payload && typeof payload === 'object' ? text(payload.error) : '';
            throw new Error(reported !== '' ? 'The server reported: ' + reported : 'The server rejected the change.');
        }
        return payload;
    };

    const save = async () => {
        if (busy || !draft) return;
        const current = collectDraft();
        const problem = validate(current);
        if (problem) {
            showError(problem.message);
            announce(problem.message);
            if (problem.field) problem.field.focus();
            return;
        }
        busy = true;
        showError('');
        syncControls();
        announce(editingId === null ? 'Saving the new rule.' : 'Saving changes to this rule.');
        try {
            await send({
                operation: 'save',
                installation_id: installationId,
                rule_id: editingId,
                description: current.description,
                core_profile_id: current.core_profile_id,
                priority: current.priority,
                enabled: current.enabled,
                match: MATCH_FIELDS.reduce((carry, key) => { carry[key] = listOf(draft.match[key]); return carry; }, {}),
            });
            busy = false;
            await loadRules(false);
            showList('Saved ' + current.description + '. It applies the next time an unknown NPC is discovered.');
        } catch (error) {
            busy = false;
            const message = (error && error.message ? error.message : 'The rule could not be saved.') + ' Nothing was changed.';
            showError(message);
            announce(message);
            syncControls();
            saveButton.focus();
        }
    };

    const showConfirm = () => {
        if (editingId === null || !draft) return;
        confirmText.textContent = 'Delete ' + (text(descriptionInput.value).trim() || 'this rule')
            + '? NPCs already assigned to a Core Profile keep it.';
        confirmBox.hidden = false;
        syncControls();
        announce('Confirm the delete, or keep the rule.');
        confirmDelete.focus();
    };

    const remove = async () => {
        if (busy || editingId === null) return;
        busy = true;
        confirmDelete.disabled = true;
        confirmCancel.disabled = true;
        syncControls();
        announce('Deleting this rule.');
        try {
            await send({ operation: 'delete', installation_id: installationId, rule_id: editingId });
            busy = false;
            confirmDelete.disabled = false;
            confirmCancel.disabled = false;
            await loadRules(false);
            showList('Rule deleted. NPCs already assigned to a Core Profile keep it.');
        } catch (error) {
            busy = false;
            confirmDelete.disabled = false;
            confirmCancel.disabled = false;
            const message = (error && error.message ? error.message : 'The rule could not be deleted.') + ' Nothing was changed.';
            showError(message);
            announce(message);
            syncControls();
            confirmDelete.focus();
        }
    };

    async function loadRules(announceProgress) {
        loading = true;
        loadFailed = false;
        syncControls();
        if (announceProgress) {
            listHost.textContent = '';
            listOrder.hidden = true;
            announce('Loading assignment rules.');
        }
        try {
            const response = await fetch(endpoint + '?installation_id=' + encodeURIComponent(installationId), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error('rules-unavailable');
            const payload = await response.json();
            data = payload && typeof payload === 'object' ? payload : { rules: [], core_profiles: [], options: {} };
            renderOptionLists();
            renderList();
            if (announceProgress) {
                const count = sortedRules().length;
                announce(count === 0
                    ? 'No assignment rules yet.'
                    : count + ' assignment rule' + (count === 1 ? '' : 's') + ' loaded, highest priority first.');
            }
        } catch (_error) {
            loadFailed = true;
            data = null;
            listHost.textContent = '';
            listOrder.hidden = true;
            const failure = document.createElement('p');
            failure.className = 'profile-rules-failure';
            failure.textContent = 'The assignment rules could not be loaded and nothing was changed. Use Reload to try again.';
            listHost.append(failure);
            announce('The assignment rules could not be loaded. Use Reload to try again.');
        } finally {
            loading = false;
            syncControls();
        }
    }

    function close() {
        if (overlay.hidden || busy) return;
        overlay.hidden = true;
        document.body.classList.remove('profile-rules-open');
        document.removeEventListener('keydown', onKeydown, true);
        const restoreTo = opener;
        opener = null;
        if (restoreTo && typeof restoreTo.focus === 'function') restoreTo.focus();
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            if (busy) {
                announce('A change is still being sent. The dialog closes once it finishes.');
                return;
            }
            if (!confirmBox.hidden) {
                hideConfirm();
                syncControls();
                announce('The rule was kept.');
                deleteButton.focus();
                return;
            }
            close();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = focusableIn();
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }

    const open = (trigger) => {
        opener = trigger;
        overlay.hidden = false;
        document.body.classList.add('profile-rules-open');
        document.addEventListener('keydown', onKeydown, true);
        view = 'list';
        editingId = null;
        draft = null;
        hideConfirm();
        showError('');
        syncControls();
        const closeControl = overlay.querySelector('[data-profile-rules-close]');
        if (closeControl) closeControl.focus();
        if (data === null && !loading) loadRules(true);
    };

    matchFieldNodes.forEach((nodes, key) => {
        if (nodes.addDetected) nodes.addDetected.addEventListener('click', () => {
            if (!nodes.detected.value) { nodes.detected.focus(); return; }
            nodes.input.value = nodes.detected.value;
            addMatchValue(key);
        });
        if (nodes.add) nodes.add.addEventListener('click', () => addMatchValue(key));
        if (nodes.input) {
            nodes.input.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                addMatchValue(key);
            });
        }
    });

    openButtons.forEach((button) => { button.addEventListener('click', () => open(button)); });
    overlay.querySelectorAll('[data-profile-rules-close]').forEach((button) => { button.addEventListener('click', () => close()); });
    overlay.addEventListener('mousedown', (event) => { if (event.target === overlay) close(); });
    form.addEventListener('submit', (event) => { event.preventDefault(); save(); });
    newButton.addEventListener('click', () => openForm(null));
    reloadButton.addEventListener('click', () => loadRules(true));
    deleteButton.addEventListener('click', () => showConfirm());
    cancelButton.addEventListener('click', () => showList('No changes were saved.'));
    confirmDelete.addEventListener('click', () => remove());
    confirmCancel.addEventListener('click', () => {
        hideConfirm();
        syncControls();
        announce('The rule was kept.');
        deleteButton.focus();
    });
    syncControls();
})();

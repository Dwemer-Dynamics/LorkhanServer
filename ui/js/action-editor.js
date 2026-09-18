document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-action-editor]');
  const source = document.getElementById('action-editor-bootstrap');
  if (!root || !source || !root.querySelector('[data-installation]')) return;

  const boot = JSON.parse(source.textContent || '{}');
  const find = (selector) => root.querySelector(selector);
  const elements = {
    installation: find('[data-installation]'), profile: find('[data-profile]'), enabled: find('[data-policy-enabled]'),
    maxTier: find('[data-max-tier]'), search: find('[data-search]'), rows: find('[data-action-rows]'),
    table: find('[data-editor-table]'), noActions: find('[data-no-actions]'), visible: find('[data-visible-count]'),
    dirty: Array.from(root.querySelectorAll('[data-dirty-count]')), saves: Array.from(root.querySelectorAll('[data-save-all]')),
    reason: find('[data-change-reason]'), revision: find('[data-revision-label]'), scope: find('[data-scope-label]'),
    footerScope: find('[data-footer-scope]'), toast: find('[data-action-toast]'), resetFilters: find('[data-reset-filters]'),
    summaryTotal: find('[data-summary-total]'), summaryEnabled: find('[data-summary-enabled]'),
    summaryDisabled: find('[data-summary-disabled]'), viewActive: find('[data-view-active]'),
    activeDialog: find('[data-active-dialog]'), activeGroups: find('[data-active-groups]'),
    dialog: find('[data-action-dialog]'), dialogTitle: find('[data-dialog-title]'), dialogWire: find('[data-dialog-wire]'),
    dialogReturn: find('[data-dialog-return]'), dialogPrompt: find('[data-dialog-followup-prompt]'),
    dialogCooldown: find('[data-dialog-cooldown]'), dialogParameters: find('[data-dialog-parameters]'),
    dialogContract: find('[data-dialog-contract]'), dialogReset: find('[data-dialog-reset]'),
    dialogSave: find('[data-dialog-save]'), dialogStatus: find('[data-dialog-status]'),
  };
  const state = {
    catalog: [], policies: {}, rows: new Map(), baseline: '', savedDocument: null, loading: false, dialogRow: null, dialogResetPending: false,
  };
  const bool = (value) => value === true || value === 1 || value === '1' || value === 't' || value === 'true';
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
  })[character]);
  const same = (left, right) => JSON.stringify(left) === JSON.stringify(right);
  const currentPolicy = () => elements.profile.value ? state.policies.profile_policy : state.policies.installation_policy;
  const effectiveContent = () => state.policies.effective_policy?.content || {};
  const installationContent = () => state.policies.installation_policy?.content || {};
  const actionScopes = (action) => {
    const scopes = [];
    if (bool(action.available_to_npc)) scopes.push('npc');
    if (bool(action.available_to_followers)) scopes.push('followers');
    if (bool(action.available_to_narrator)) scopes.push('narrator');
    return scopes.length ? scopes : ['dynamic'];
  };
  const baseValue = (action) => ({
    enabled: bool(action.is_activated ?? action.enabled),
    display_name: action.action_name || action.display_name || action.name,
    description: action.description,
    return_message: action.return_message || '',
    confirmation_required: action.confirmation_mode === 'required'
      || (action.metadata?.custom_config?.confirmation_required ?? action.confirmation_default) === true,
    followup_enabled: action.continuation_capable === true
      && (action.metadata?.custom_config?.followup_enabled ?? action.followup_default) === true,
    followup_prompt: action.metadata?.custom_config?.followup_prompt ?? action.metadata?.followup?.prompt ?? '',
    allow_followup_action: action.metadata?.custom_config?.followup_use_functions_again === true,
    cooldown_seconds: Number(action.metadata?.cooldown_seconds ?? action.cooldown_seconds ?? 0),
  });
  const normalizedOverride = (action, content) => {
    const value = baseValue(action);
    const raw = content.actions?.[action.name];
    const object = raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
    if (typeof raw === 'boolean') value.enabled = raw;
    else if (typeof object.code_name === 'string') {
      const custom = object.metadata?.custom_config || {};
      value.enabled = object.is_activated === true;
      if (typeof object.action_name === 'string' && object.action_name) value.display_name = object.action_name;
      if (typeof object.description === 'string') value.description = object.description;
      if (typeof object.return_message === 'string') value.return_message = object.return_message;
      if (action.confirmation_mode === 'optional' && Object.hasOwn(custom, 'confirmation_required')) {
        value.confirmation_required = custom.confirmation_required === true;
      }
      if (action.continuation_capable === true && Object.hasOwn(custom, 'followup_enabled')) {
        value.followup_enabled = custom.followup_enabled === true;
      }
      if (typeof custom.followup_prompt === 'string') value.followup_prompt = custom.followup_prompt;
      if (action.followup_actions_supported === true && Object.hasOwn(custom, 'followup_use_functions_again')) {
        value.allow_followup_action = custom.followup_use_functions_again === true && value.followup_enabled;
      }
      if (Number.isInteger(object.metadata?.cooldown_seconds)) value.cooldown_seconds = object.metadata.cooldown_seconds;
      return value;
    }
    else if (Object.hasOwn(object, 'enabled')) value.enabled = object.enabled === true;
    else {
      if (Array.isArray(content.allowed_actions)) value.enabled = content.allowed_actions.includes(action.name);
      if (Array.isArray(content.denied_actions) && content.denied_actions.includes(action.name)) value.enabled = false;
    }
    if (typeof object.display_name === 'string' && object.display_name) value.display_name = object.display_name;
    if (typeof object.description === 'string' && object.description) value.description = object.description;
    if (typeof object.return_message === 'string') value.return_message = object.return_message;
    if (action.confirmation_mode === 'optional' && Object.hasOwn(object, 'confirmation_required')) {
      value.confirmation_required = object.confirmation_required === true;
    }
    if (action.continuation_capable === true && Object.hasOwn(object, 'followup_enabled')) {
      value.followup_enabled = object.followup_enabled === true;
    }
    if (typeof object.followup_prompt === 'string') value.followup_prompt = object.followup_prompt;
    if (action.followup_actions_supported === true && Object.hasOwn(object, 'allow_followup_action')) {
      value.allow_followup_action = object.allow_followup_action === true && value.followup_enabled;
    }
    if (Number.isInteger(object.cooldown_seconds)) value.cooldown_seconds = object.cooldown_seconds;
    return value;
  };
  const inheritedValue = (action) => elements.profile.value
    ? normalizedOverride(action, installationContent()) : baseValue(action);
  const rowValue = (row) => ({
    enabled: row.querySelector('[data-enabled]').checked,
    display_name: row.querySelector('[data-display-name]').value.trim(),
    description: row.querySelector('[data-description]').value.trim(),
    return_message: row.dataset.returnMessage || '',
    confirmation_required: row.querySelector('[data-confirmation]').checked,
    followup_enabled: row.querySelector('[data-followup]').checked,
    followup_prompt: row.dataset.followupPrompt || '',
    allow_followup_action: row.dataset.allowFollowupAction === 'true',
    cooldown_seconds: Number(row.dataset.cooldownSeconds || 0),
  });
  const documentValue = () => ({
    enabled: elements.enabled.checked,
    max_tier: Number(elements.maxTier.value),
    actions: Object.fromEntries(Array.from(state.rows, ([name, row]) => [name, rowValue(row)])),
  });
  const signature = () => JSON.stringify(documentValue());
  const selectedFilter = (name) => find(`input[name="action-${name}"]:checked`)?.value || 'all';
  const setRowValue = (row, value) => {
    row.querySelector('[data-enabled]').checked = value.enabled;
    row.querySelector('[data-display-name]').value = value.display_name;
    row.querySelector('[data-description]').value = value.description;
    row.dataset.returnMessage = value.return_message;
    row.querySelector('[data-confirmation]').checked = value.confirmation_required;
    row.querySelector('[data-followup]').checked = value.followup_enabled;
    row.dataset.followupPrompt = value.followup_prompt;
    row.dataset.allowFollowupAction = String(value.allow_followup_action);
    row.querySelector('[data-followup-actions]').checked = value.allow_followup_action;
    row.dataset.cooldownSeconds = String(value.cooldown_seconds);
  };
  const updateRowPresentation = (row) => {
    const action = state.catalog.find((item) => item.name === row.dataset.name);
    if (!action) return;
    const value = rowValue(row);
    const changed = !same(value, JSON.parse(row.dataset.baseline));
    const customized = !same(value, inheritedValue(action));
    row.classList.toggle('is-dirty', changed);
    row.dataset.state = value.enabled ? 'enabled' : 'disabled';
    row.dataset.source = customized ? 'custom' : 'base';
    row.querySelector('[data-row-status]').textContent = value.enabled ? 'Enabled' : 'Disabled';
    row.querySelector('[data-row-status]').className = `action-row-status state-${row.dataset.state}`;
    const toggle = row.querySelector('[data-toggle-enabled]');
    toggle.textContent = value.enabled ? 'Disable' : 'Enable';
    toggle.classList.toggle('danger', value.enabled);
    toggle.classList.toggle('btn-save', !value.enabled);
    toggle.disabled = state.loading;
    const baseline = JSON.parse(row.dataset.baseline);
    row.querySelector('[data-save-row]').disabled = state.loading;
    row.querySelector('[data-followup-actions]').disabled = !value.followup_enabled || !action.followup_actions_supported;
    row.querySelectorAll('.behavior-toggles label').forEach((label) => {
      const key = label.dataset.field;
      label.classList.toggle('is-dirty', value[key] !== baseline[key]);
    });
    row.dataset.search = `${action.name} ${value.display_name} ${value.description} ${action.client_capability}`.toLowerCase();
  };
  const updateSummary = () => {
    const values = Array.from(state.rows.values(), rowValue);
    const enabled = values.filter((value) => value.enabled).length;
    elements.summaryTotal.textContent = String(values.length);
    find('[data-filter-total]').textContent = String(values.length);
    elements.summaryEnabled.textContent = String(enabled);
    elements.summaryDisabled.textContent = String(values.length - enabled);
  };
  const applyFilters = () => {
    const query = elements.search.value.trim().toLowerCase();
    const filters = {
      state: selectedFilter('state'), scope: selectedFilter('scope'),
      dispatch: selectedFilter('dispatch'), source: selectedFilter('source'),
    };
    let shown = 0;
    state.rows.forEach((row) => {
      const visible = (!query || row.dataset.search.includes(query))
        && (filters.state === 'all' || row.dataset.state === filters.state)
        && (filters.scope === 'all' || row.dataset.scope.split(' ').includes(filters.scope))
        && (filters.dispatch === 'all' || row.dataset.dispatch === filters.dispatch)
        && (filters.source === 'all' || row.dataset.source === filters.source);
      row.hidden = !visible;
      if (visible) shown += 1;
    });
    elements.visible.textContent = String(shown);
    elements.noActions.hidden = shown !== 0;
  };
  const updateDirty = () => {
    let count = 0, actions = 0;
    state.rows.forEach((row) => {
      updateRowPresentation(row);
      if (row.classList.contains('is-dirty')) {
        actions += 1;
        const value=rowValue(row),baseline=JSON.parse(row.dataset.baseline);
        count += Object.keys(value).filter(key => value[key] !== baseline[key]).length;
      }
    });
    const dirty = signature() !== state.baseline;
    if (dirty && count === 0) count = 1;
    elements.dirty.forEach((item) => {
      item.textContent = dirty ? `${count} unsaved ${count === 1 ? 'change' : 'changes'}${actions ? ` across ${actions} ${actions === 1 ? 'action' : 'actions'}` : ' in action scope'}` : 'No unsaved changes';
    });
    elements.saves.forEach((button) => { button.disabled = !dirty || state.loading; });
    updateSummary();
    applyFilters();
  };
  const resetRow = (row) => {
    const action = state.catalog.find((item) => item.name === row.dataset.name);
    if (!action) return;
    setRowValue(row, inheritedValue(action));
    updateDirty();
  };
  const renderRows = () => {
    const content = effectiveContent();
    state.rows.clear();
    elements.rows.textContent = '';
    state.catalog.forEach((action) => {
      const value = normalizedOverride(action, content);
      const scopes = actionScopes(action);
      const row = document.createElement('tr');
      row.className = 'action-row';
      row.dataset.name = action.name;
      row.dataset.scope = scopes.join(' ');
      row.dataset.dispatch = bool(action.game_function) ? 'game' : 'server';
      row.dataset.cooldownSeconds = String(value.cooldown_seconds);
      row.dataset.allowFollowupAction = String(value.allow_followup_action);
      row.dataset.returnMessage = value.return_message;
      row.dataset.followupPrompt = value.followup_prompt;
      row.dataset.search = `${action.name} ${value.display_name} ${value.description} ${action.client_capability}`.toLowerCase();
      const confirmationLocked = action.confirmation_mode !== 'optional';
      row.innerHTML = `<td data-label="Name"><div class="action-name-title"><input class="basic-action-input" data-display-name maxlength="128" value="${escapeHtml(value.display_name)}" aria-label="Action name for ${escapeHtml(action.name)}"><span data-row-status></span></div><code class="action-code-hint">${escapeHtml(action.name)}</code></td>
        <td data-label="Description"><textarea class="basic-action-description" data-description maxlength="2048" rows="4" aria-label="Action description for ${escapeHtml(action.name)}">${escapeHtml(value.description)}</textarea></td>
        <td data-label="Behavior"><div class="behavior-toggles"><label data-field="confirmation_required" title="${confirmationLocked ? `OpenMW contract: confirmation is ${action.confirmation_mode}` : 'Require player confirmation'}"><input type="checkbox" data-confirmation${value.confirmation_required ? ' checked' : ''}${confirmationLocked ? ' disabled hidden' : ''}>${confirmationLocked ? '<span aria-hidden="true">–</span>' : ''}<span>${confirmationLocked ? `Confirmation ${action.confirmation_mode === 'required' ? 'required' : 'unavailable'}` : 'Require Confirmation'}</span></label><label data-field="followup_enabled"><input type="checkbox" data-followup${value.followup_enabled ? ' checked' : ''}${action.continuation_capable ? '' : ' disabled'}><span>Follow-up Enabled</span></label><label data-field="allow_followup_action"${action.followup_actions_supported ? '' : ' hidden'} title="Allow one additional safe action after a completed result"><input type="checkbox" data-followup-actions${value.allow_followup_action ? ' checked' : ''}><span>Allow Follow-up Actions</span></label></div><input type="checkbox" data-enabled${value.enabled ? ' checked' : ''} hidden></td>
        <td data-label="Action"><div class="row-actions"><button type="button" class="btn-save" data-save-row>Save</button><button type="button" class="action-button secondary" data-toggle-enabled></button><button type="button" class="action-button secondary advanced-options-button" data-advanced>Advanced Options</button></div></td>`;
      row.dataset.baseline = JSON.stringify(rowValue(row));
      row.querySelectorAll('input,textarea').forEach((input) => input.addEventListener('input', () => {
        if (input.matches('[data-followup]') && !input.checked) {
          row.dataset.allowFollowupAction = 'false';row.querySelector('[data-followup-actions]').checked = false;
        }
        if (input.matches('[data-followup-actions]')) row.dataset.allowFollowupAction = String(input.checked);
        updateDirty();
      }));
      row.querySelector('[data-toggle-enabled]').addEventListener('click', () => {
        const control = row.querySelector('[data-enabled]');
        control.checked = !control.checked;
        updateDirty();
        save(action.name, ['enabled']);
      });
      row.querySelector('[data-save-row]').addEventListener('click', () => save(action.name, ['display_name','description']));
      row.querySelector('[data-advanced]').addEventListener('click', () => openDialog(row, action));
      state.rows.set(action.name, row);
      elements.rows.appendChild(row);
    });
    state.savedDocument = documentValue();
    state.baseline = signature();
    updateDirty();
    elements.table.setAttribute('aria-busy', 'false');
  };
  const activeActions = () => {
    const groups = [
      { key: 'npc', label: 'NPC' }, { key: 'followers', label: 'Followers' },
      { key: 'narrator', label: 'Narrator' }, { key: 'dynamic', label: 'Dynamic' },
    ];
    elements.activeGroups.innerHTML = groups.map((group) => {
      const actions = state.catalog.filter((action) => actionScopes(action).includes(group.key))
        .filter((action) => state.savedDocument?.enabled && state.savedDocument.actions[action.name]?.enabled);
      const body = actions.length ? actions.map((action) => {
        const value = state.savedDocument.actions[action.name];
        return `<div class="active-action-row"><div><strong>${escapeHtml(value.display_name)}</strong><code>${escapeHtml(action.name)}</code></div><p>${escapeHtml(value.description)}</p></div>`;
      }).join('') : '<p class="active-scope-empty">No active actions in this scope.</p>';
      return `<section class="active-scope-row"><header><h3>${group.label}</h3><span>${actions.length}</span></header><div>${body}</div></section>`;
    }).join('');
    elements.activeDialog.showModal();
  };
  const openDialog = (row, action) => {
    state.dialogRow = row;
    state.dialogActionName = action.name;
    state.dialogResetPending = false;
    elements.dialogStatus.textContent = '';
    const value = rowValue(row);
    elements.dialogTitle.textContent = 'Advanced Options';
    elements.dialogWire.textContent = `${value.display_name} · ${action.name}`;
    elements.dialogReturn.value = value.return_message;
    elements.dialogPrompt.value = value.followup_prompt;
    elements.dialogPrompt.disabled = !value.followup_enabled || !action.continuation_capable;
    elements.dialogCooldown.value = String(value.cooldown_seconds);
    const scopes = actionScopes(action).map((scope) => ({
      npc: 'NPC', followers: 'Followers', narrator: 'Narrator', dynamic: 'Dynamic',
    })[scope] || scope).join(', ');
    elements.dialogParameters.innerHTML = `<pre>${escapeHtml(JSON.stringify(action.parameter_schema, null, 2))}</pre>`;
    elements.dialogContract.innerHTML = `<dt>Scope</dt><dd>${escapeHtml(scopes)}</dd><dt>Dispatch</dt><dd>${bool(action.game_function) ? 'Game' : 'Server'}</dd><dt>Safety tier</dt><dd>${escapeHtml(action.tier)}</dd><dt>Confirmation</dt><dd>${escapeHtml(action.confirmation_mode)}</dd><dt>Client capability</dt><dd><code>${escapeHtml(action.client_capability)}</code></dd><dt>Result</dt><dd><pre>${escapeHtml(JSON.stringify(action.result_schema, null, 2))}</pre></dd>`;
    elements.dialogReset.disabled = row.dataset.source !== 'custom';
    find('[data-dialog-reset-section]').hidden = row.dataset.source !== 'custom';
    elements.dialog.querySelectorAll('details').forEach(details => { details.open = false; });
    elements.dialog.showModal();
    elements.dialogReturn.focus();
  };
  const syncDialog = () => {
    const row = state.dialogRow;
    if (!row || !row.isConnected) return;
    const seconds = Math.max(0, Math.min(86400, Number(elements.dialogCooldown.value || 0)));
    row.dataset.returnMessage = elements.dialogReturn.value;
    row.dataset.followupPrompt = elements.dialogPrompt.value;
    row.dataset.cooldownSeconds = String(Math.trunc(seconds));
    updateDirty();
  };
  const sparseActions = (actions) => {
    const result = {};
    state.catalog.forEach((action) => {
      const current = actions[action.name];
      const inherited = inheritedValue(action);
      if (!same(current, inherited)) {
        const metadata = JSON.parse(JSON.stringify(action.metadata || {}));
        metadata.custom_config = {
          confirmation_required: current.confirmation_required,
          followup_enabled: current.followup_enabled,
          followup_prompt: current.followup_prompt,
          followup_use_functions_again: current.allow_followup_action,
        };
        metadata.cooldown_seconds = current.cooldown_seconds;
        result[action.name] = {
          code_name: action.code_name || action.name,
          action_name: current.display_name,
          description: current.description,
          return_message: current.return_message,
          available_to_npc: bool(action.available_to_npc),
          available_to_followers: bool(action.available_to_followers),
          available_to_narrator: bool(action.available_to_narrator),
          is_activated: current.enabled,
          parameters_json: action.parameters_json || action.parameter_schema,
          metadata,
          game_function: bool(action.game_function),
          import_version: Number(action.import_version || 0),
          script_proxy_program: action.script_proxy_program ?? null,
        };
      }
    });
    return result;
  };
  const restoreUnsaved = (document, savedRow, savedFields) => {
    elements.enabled.checked = document.enabled;
    elements.maxTier.value = String(document.max_tier);
    state.rows.forEach((row, name) => {
      if (name !== savedRow && document.actions[name]) setRowValue(row, document.actions[name]);
      else if (name === savedRow && savedFields) {
        const restored = rowValue(row);
        Object.keys(restored).forEach(key => { if (!savedFields.includes(key)) restored[key] = document.actions[name][key]; });
        setRowValue(row, restored);
      }
    });
    updateDirty();
  };
  const loadScope = async () => {
    state.loading = true;
    elements.table.setAttribute('aria-busy', 'true');
    elements.saves.forEach((button) => { button.disabled = true; });
    const params = new URLSearchParams({ installation_id: elements.installation.value });
    if (elements.profile.value) params.set('profile_id', elements.profile.value);
    const controller = new AbortController(), timeout = window.setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(`${boot.api_base}/action-policies/editor?${params}`, { headers: { Accept: 'application/json' }, signal: controller.signal });
      if (!response.ok) throw new Error(`load failed (${response.status})`);
      const data = await response.json();
      state.catalog = data.catalog || [];
      state.policies = data.policies || {};
      state.catalog.sort((left,right) => normalizedOverride(left,effectiveContent()).display_name.localeCompare(normalizedOverride(right,effectiveContent()).display_name));
      const policy = currentPolicy();
      const content = effectiveContent();
      elements.enabled.checked = content.enabled !== false;
      elements.maxTier.value = String(Number.isInteger(content.max_tier) ? content.max_tier : 3);
      const profile = (boot.profiles || []).find((item) => item.profile_id === elements.profile.value);
      const label = profile ? `${profile.name} override` : 'Installation defaults';
      elements.scope.textContent = label;
      elements.footerScope.textContent = label;
      elements.revision.textContent = policy ? `Revision ${policy.revision}` : (profile ? 'Inherited · not saved' : 'Not saved yet');
      renderRows();
      return true;
    } catch (error) {
      showToast(error.message || 'Unable to load action configuration.', true);
      return false;
    } finally {
      window.clearTimeout(timeout);
      state.loading = false;
      updateDirty();
    }
  };
  // Each row control saves only its owned fields, retaining other staged edits after refresh.
  const save = async (rowName = null, fields = null) => {
    if (state.loading) return false;
    const current = documentValue();
    const reason = elements.reason.value.trim();
    if (!reason) { showToast('Add a revision note in Action scope before saving.', true); return false; }
    const rowChanges = rowName && fields ? Object.fromEntries(fields.map(key => [key,current.actions[rowName][key]])) : (rowName ? current.actions[rowName] : null);
    const document = rowName && state.savedDocument
      ? { ...state.savedDocument, actions: { ...state.savedDocument.actions, [rowName]: { ...state.savedDocument.actions[rowName], ...rowChanges } } }
      : current;
    for (const action of Object.values(document.actions)) {
      if (!action.display_name) { showToast('Every action needs a name.', true); return false; }
    }
    const policy = currentPolicy();
    const controls = Array.from(root.querySelectorAll('input,textarea,select,button'), control => [control,control.disabled]);
    state.loading = true;
    updateDirty();
    controls.forEach(([control]) => { control.disabled = true; });
    const controller = new AbortController(), timeout = window.setTimeout(() => controller.abort(), 15000);
    const payload = {
      configuration_id: policy?.configuration_id || null,
      installation_id: elements.installation.value,
      profile_id: elements.profile.value || null,
      expected_revision: policy?.revision || null,
      enabled: document.enabled,
      max_tier: document.max_tier,
      actions: sparseActions(document.actions),
      change_reason: reason,
    };
    try {
      const response = await fetch(`${boot.api_base}/action-policies/revisions`, {
        method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': boot.csrf },
        body: JSON.stringify(payload), signal: controller.signal,
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error === 'revision_conflict'
        ? 'This configuration changed in another tab. Reload before saving.' : (data.error || `save failed (${response.status})`));
      showToast(rowName ? `Saved ${current.actions[rowName].display_name}.` : `Saved action configuration revision ${data.current_revision}.`);
      if (!await loadScope()) { showToast('Saved, but the editor could not refresh. Reload before editing.', true); return false; }
      if (rowName) restoreUnsaved(current, rowName, fields);
      if (rowName && !elements.dialog.open) state.rows.get(rowName)?.querySelector(fields?.includes('enabled') ? '[data-toggle-enabled]' : '[data-save-row]')?.focus();
      return true;
    } catch (error) {
      showToast(error.message || 'Unable to save the action configuration.', true);
      return false;
    } finally {
      window.clearTimeout(timeout);
      controls.forEach(([control,disabled]) => { if (control.isConnected) control.disabled = disabled; });
      state.loading = false;
      updateDirty();
    }
  };
  const populateProfiles = () => {
    const chosen = elements.profile.value;
    elements.profile.innerHTML = '<option value="">All NPCs</option>';
    (boot.profiles || []).filter((profile) => profile.installation_id === elements.installation.value).forEach((profile) => {
      const option = document.createElement('option'); option.value = profile.profile_id; option.textContent = profile.name;
      elements.profile.appendChild(option);
    });
    if (Array.from(elements.profile.options).some((option) => option.value === chosen)) elements.profile.value = chosen;
    elements.profile.dataset.lastValue = elements.profile.value;
  };
  const showToast = (message, error = false) => {
    elements.toast.textContent = message;
    elements.toast.classList.toggle('is-error', error);
    elements.toast.hidden = false;
    if (elements.dialog.open) elements.dialogStatus.textContent = message;
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(() => { elements.toast.hidden = true; }, 5000);
  };
  const guardedSelection = (element, action) => {
    element.dataset.lastValue = element.value;
    element.addEventListener('change', () => {
      const previous = element.dataset.lastValue || '';
      if (signature() !== state.baseline && !window.confirm('Discard unsaved Action Editor changes?')) {
        element.value = previous;
        return;
      }
      element.dataset.lastValue = element.value;
      action();
    });
  };

  elements.dialogReturn.addEventListener('input', syncDialog);
  elements.dialogPrompt.addEventListener('input', syncDialog);
  elements.dialogCooldown.addEventListener('input', syncDialog);
  elements.dialog.addEventListener('close', () => {
    syncDialog();state.dialogRow = null;
    state.rows.get(state.dialogActionName)?.querySelector('[data-advanced]')?.focus();
  });
  elements.activeDialog.addEventListener('close', () => elements.viewActive.focus());
  elements.dialogSave.addEventListener('click', async () => {
    if (!state.dialogRow || !elements.dialogCooldown.reportValidity()) return;
    syncDialog();
    if (await save(state.dialogRow.dataset.name, state.dialogResetPending ? null : ['return_message','followup_prompt','cooldown_seconds'])) {
      state.dialogRow = null;elements.dialog.close();
    }
  });
  elements.dialogReset.addEventListener('click', () => {
    if (!state.dialogRow) return;
    const row = state.dialogRow, action = state.catalog.find(item => item.name === row.dataset.name);
    resetRow(row);openDialog(row,action);state.dialogResetPending = true;
    elements.dialogStatus.textContent = 'Reset staged. Save Advanced Options to apply it.';
  });
  elements.viewActive.addEventListener('click', activeActions);
  elements.search.addEventListener('input', applyFilters);
  root.querySelectorAll('.filter-chip-row input').forEach((input) => input.addEventListener('change', applyFilters));
  elements.resetFilters.addEventListener('click', () => {
    elements.search.value = '';
    ['state', 'scope', 'dispatch', 'source'].forEach((name) => { find(`#action-${name}-all`).checked = true; });
    applyFilters();
  });
  elements.enabled.addEventListener('input', updateDirty);
  elements.saves.forEach((button) => button.addEventListener('click', () => save()));
  guardedSelection(elements.installation, () => { populateProfiles(); loadScope(); });
  guardedSelection(elements.profile, loadScope);
  window.addEventListener('beforeunload', (event) => {
    if (signature() === state.baseline) return;
    event.preventDefault();
    event.returnValue = '';
  });
  populateProfiles();
  loadScope();
});

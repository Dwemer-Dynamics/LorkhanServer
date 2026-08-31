document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-action-editor]');
  const source = document.getElementById('action-editor-bootstrap');
  if (!root || !source || !root.querySelector('[data-installation]')) return;
  const boot = JSON.parse(source.textContent || '{}');
  const elements = {
    installation: root.querySelector('[data-installation]'), profile: root.querySelector('[data-profile]'),
    policy: root.querySelector('[data-policy]'), name: root.querySelector('[data-policy-name]'),
    enabled: root.querySelector('[data-policy-enabled]'), maxTier: root.querySelector('[data-max-tier]'),
    search: root.querySelector('[data-search]'), tier: root.querySelector('[data-tier-filter]'),
    rows: root.querySelector('[data-action-rows]'), table: root.querySelector('[data-editor-table]'),
    noActions: root.querySelector('[data-no-actions]'), visible: root.querySelector('[data-visible-count]'),
    selected: root.querySelector('[data-selected-count]'), dirty: Array.from(root.querySelectorAll('[data-dirty-count]')),
    saves: Array.from(root.querySelectorAll('[data-save-all]')), reason: root.querySelector('[data-change-reason]'),
    revision: root.querySelector('[data-revision-label]'), toast: root.querySelector('[data-action-toast]'),
  };
  const state = { catalog: [], policies: [], rows: new Map(), baseline: '', loading: false };
  const bool = (value) => value === true || value === 1 || value === '1' || value === 't' || value === 'true';
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
  })[character]);
  const policyContent = () => state.policies.find((item) => item.configuration_id === elements.policy.value)?.content || {};
  const selectedPolicy = () => state.policies.find((item) => item.configuration_id === elements.policy.value) || null;
  const defaultConfirmation = (action) => Number(action.tier) >= 2;
  const normalizedOverride = (action, content) => {
    const raw = content.actions?.[action.name];
    if (raw && typeof raw === 'object' && !Array.isArray(raw)) return {
      enabled: raw.enabled === true, display_name: raw.display_name || action.name,
      description: raw.description || action.description, confirmation_required: raw.confirmation_required === true,
      followup_enabled: action.continuation_capable === true && raw.followup_enabled === true,
    };
    const allowed = Array.isArray(content.allowed_actions) ? content.allowed_actions.includes(action.name) : bool(action.enabled);
    const denied = Array.isArray(content.denied_actions) && content.denied_actions.includes(action.name);
    return { enabled: typeof raw === 'boolean' ? raw : allowed && !denied, display_name: action.name,
      description: action.description, confirmation_required: defaultConfirmation(action), followup_enabled: false };
  };
  const rowValue = (row) => ({ enabled: row.querySelector('[data-enabled]').checked,
    display_name: row.querySelector('[data-display-name]').value.trim(),
    description: row.querySelector('[data-description]').value.trim(),
    confirmation_required: row.querySelector('[data-confirmation]').checked,
    followup_enabled: row.querySelector('[data-followup]').checked });
  const documentValue = () => ({
    enabled: elements.enabled.checked, max_tier: Number(elements.maxTier.value),
    name: elements.name.value.trim(), actions: Object.fromEntries(Array.from(state.rows, ([name, row]) => [name, rowValue(row)])),
  });
  const signature = () => JSON.stringify(documentValue());
  const updateDirty = () => {
    const dirty = signature() !== state.baseline;
    let count = 0;
    state.rows.forEach((row) => {
      const changed = JSON.stringify(rowValue(row)) !== row.dataset.baseline;
      row.classList.toggle('is-dirty', changed); if (changed) count += 1;
    });
    if (dirty && count === 0) count = 1;
    elements.dirty.forEach((item) => { item.textContent = `${count} unsaved`; });
    elements.saves.forEach((button) => { button.disabled = !dirty || state.loading; });
  };
  const updateSelected = () => { elements.selected.textContent = String(root.querySelectorAll('[data-row-select]:checked').length); };
  const applyFilters = () => {
    const query = elements.search.value.trim().toLowerCase(); const tier = elements.tier.value; let shown = 0;
    state.rows.forEach((row) => {
      const visible = (!query || row.dataset.search.includes(query)) && (tier === 'all' || row.dataset.tier === tier);
      row.hidden = !visible; if (visible) shown += 1;
    });
    elements.visible.textContent = `${shown} shown`; elements.noActions.hidden = shown !== 0;
  };
  const renderRows = () => {
    const content = policyContent(); state.rows.clear(); elements.rows.textContent = '';
    state.catalog.forEach((action) => {
      const value = normalizedOverride(action, content); const row = document.createElement('tr');
      row.className = 'action-row'; row.dataset.name = action.name; row.dataset.tier = String(action.tier);
      row.dataset.search = `${action.name} ${value.display_name} ${value.description} ${action.client_capability}`.toLowerCase();
      row.innerHTML = `<td class="select-column"><input type="checkbox" data-row-select aria-label="Select ${escapeHtml(action.name)}"></td>
        <td><strong>${escapeHtml(action.name)}</strong><div class="row-meta"><span>Tier ${escapeHtml(action.tier)}</span><code>${escapeHtml(action.client_capability)}</code></div>
          <details><summary>Advanced contract</summary><dl><dt>Wire name</dt><dd><code>${escapeHtml(action.name)}</code></dd><dt>Parameters</dt><dd><pre>${escapeHtml(JSON.stringify(action.parameter_schema, null, 2))}</pre></dd><dt>Result</dt><dd><pre>${escapeHtml(JSON.stringify(action.result_schema, null, 2))}</pre></dd></dl></details></td>
        <td><label>Display label<input data-display-name maxlength="128" value="${escapeHtml(value.display_name)}"></label><label>Description<textarea data-description maxlength="2048" rows="2">${escapeHtml(value.description)}</textarea></label></td>
        <td><label class="switch-line"><input type="checkbox" data-enabled${value.enabled ? ' checked' : ''}> Enabled</label><label class="switch-line"><input type="checkbox" data-confirmation${value.confirmation_required ? ' checked' : ''}> Require confirmation</label><label class="switch-line"><input type="checkbox" data-followup${value.followup_enabled ? ' checked' : ''}${action.continuation_capable ? '' : ' disabled'}> Result follow-up</label>${action.continuation_capable ? '' : '<small>Follow-up is unavailable for this action.</small>'}</td>`;
      const followup = row.querySelector('[data-followup]'); if (!action.continuation_capable) followup.checked = false;
      row.dataset.baseline = JSON.stringify(rowValue(row));
      row.querySelectorAll('input,textarea').forEach((input) => input.addEventListener('input', () => {
        if (input.matches('[data-row-select]')) updateSelected(); else updateDirty();
      }));
      state.rows.set(action.name, row); elements.rows.appendChild(row);
    });
    state.baseline = signature(); updateDirty(); updateSelected(); applyFilters(); elements.table.setAttribute('aria-busy', 'false');
  };
  const populatePolicies = () => {
    const current = elements.policy.value; elements.policy.innerHTML = '<option value="">New policy</option>';
    state.policies.forEach((policy) => { const option = document.createElement('option'); option.value = policy.configuration_id;
      option.textContent = `${policy.name} (r${policy.current_revision})`; elements.policy.appendChild(option); });
    if (state.policies.some((policy) => policy.configuration_id === current)) elements.policy.value = current;
    elements.policy.dataset.lastValue = elements.policy.value;
  };
  const loadPolicy = () => {
    elements.policy.dataset.lastValue = elements.policy.value;
    const policy = selectedPolicy(); const content = policy?.content || {};
    elements.name.value = policy?.name || 'Action policy'; elements.enabled.checked = content.enabled !== false;
    elements.name.readOnly = Boolean(policy); elements.name.title = policy ? 'Create a new policy to use a different name.' : '';
    elements.maxTier.value = String(Number.isInteger(content.max_tier) ? content.max_tier : 3);
    elements.revision.textContent = policy ? `Revision ${policy.current_revision}` : 'New policy'; renderRows();
  };
  const loadScope = async () => {
    state.loading = true; elements.table.setAttribute('aria-busy', 'true'); elements.saves.forEach((button) => { button.disabled = true; });
    const params = new URLSearchParams({ installation_id: elements.installation.value });
    if (elements.profile.value) params.set('profile_id', elements.profile.value);
    try {
      const response = await fetch(`${boot.api_base}/action-policies/editor?${params}`, { headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error(`load failed (${response.status})`);
      const data = await response.json(); state.catalog = data.catalog || []; state.policies = data.policies || [];
      populatePolicies(); loadPolicy();
    } catch (error) { showToast(error.message || 'Unable to load action policies.', true); }
    finally { state.loading = false; updateDirty(); }
  };
  const populateProfiles = () => {
    const chosen = elements.profile.value; elements.profile.innerHTML = '<option value="">Installation-wide</option>';
    (boot.profiles || []).filter((profile) => profile.installation_id === elements.installation.value).forEach((profile) => {
      const option = document.createElement('option'); option.value = profile.profile_id; option.textContent = profile.name; elements.profile.appendChild(option);
    });
    if (Array.from(elements.profile.options).some((option) => option.value === chosen)) elements.profile.value = chosen;
    elements.profile.dataset.lastValue = elements.profile.value;
  };
  const showToast = (message, error = false) => { elements.toast.textContent = message; elements.toast.classList.toggle('is-error', error);
    elements.toast.hidden = false; window.clearTimeout(showToast.timer); showToast.timer = window.setTimeout(() => { elements.toast.hidden = true; }, 5000); };
  const save = async () => {
    const value = documentValue(); if (!value.name || !elements.reason.value.trim()) { showToast('Policy name and revision note are required.', true); return; }
    for (const action of Object.values(value.actions)) if (!action.display_name || !action.description) { showToast('Every action needs a display label and description.', true); return; }
    const policy = selectedPolicy(); state.loading = true; updateDirty();
    const payload = { configuration_id: policy?.configuration_id || null, installation_id: elements.installation.value,
      profile_id: elements.profile.value || null, name: value.name, expected_revision: policy?.current_revision || null,
      enabled: value.enabled, max_tier: value.max_tier, actions: value.actions, change_reason: elements.reason.value.trim() };
    try {
      const response = await fetch(`${boot.api_base}/action-policies/revisions`, { method: 'POST', headers: {
        'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': boot.csrf }, body: JSON.stringify(payload) });
      const data = await response.json(); if (!response.ok) throw new Error(data.error === 'revision_conflict' ? 'This policy changed in another tab. Reload the scope before saving.' : (data.error || `save failed (${response.status})`));
      showToast(`Saved ${value.name} revision ${data.current_revision}.`); await loadScope();
      if (data.configuration_id) { elements.policy.value = data.configuration_id; loadPolicy(); }
    } catch (error) { showToast(error.message || 'Unable to save the action policy.', true); }
    finally { state.loading = false; updateDirty(); }
  };
  const guardedSelection = (element, action) => {
    element.dataset.lastValue = element.value;
    element.addEventListener('change', () => {
      const previous = element.dataset.lastValue || '';
      if (signature() !== state.baseline && !window.confirm('Discard unsaved Action Editor changes?')) {
        element.value = previous; return;
      }
      element.dataset.lastValue = element.value; action();
    });
  };
  guardedSelection(elements.installation, () => { populateProfiles(); loadScope(); });
  guardedSelection(elements.profile, loadScope); guardedSelection(elements.policy, loadPolicy);
  [elements.name, elements.enabled, elements.maxTier].forEach((input) => input.addEventListener('input', updateDirty));
  elements.search.addEventListener('input', applyFilters); elements.tier.addEventListener('change', applyFilters);
  elements.saves.forEach((button) => button.addEventListener('click', save));
  root.querySelector('[data-bulk-actions]').addEventListener('click', (event) => {
    const action = event.target.closest('[data-bulk]')?.dataset.bulk; if (!action) return;
    const visible = Array.from(state.rows.values()).filter((row) => !row.hidden); const selected = Array.from(state.rows.values()).filter((row) => row.querySelector('[data-row-select]').checked);
    if (action === 'select') visible.forEach((row) => { row.querySelector('[data-row-select]').checked = true; });
    else if (action === 'clear') state.rows.forEach((row) => { row.querySelector('[data-row-select]').checked = false; });
    else selected.forEach((row) => { const control = action.startsWith('confirm') ? row.querySelector('[data-confirmation]') : action.startsWith('followup') ? row.querySelector('[data-followup]') : row.querySelector('[data-enabled]');
      if (!control.disabled) control.checked = action.endsWith('on') || action === 'enable'; });
    updateSelected(); updateDirty();
  });
  window.addEventListener('beforeunload', (event) => { if (signature() === state.baseline) return; event.preventDefault(); event.returnValue = ''; });
  populateProfiles(); loadScope();
});

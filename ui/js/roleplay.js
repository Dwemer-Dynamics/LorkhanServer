document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-roleplay-panel]').forEach((panel) => {
    const search = panel.querySelector('[data-roleplay-search]');
    const counts = panel.querySelectorAll('[data-roleplay-count]');
    const total = Number(panel.dataset.roleplayTotal || 0);
    search?.addEventListener('input', () => {
      const query = search.value.trim().toLowerCase();
      const rows = panel.querySelectorAll('[data-roleplay-data] table tbody tr, [data-roleplay-data] .profile-card');
      let shown = 0;
      rows.forEach((row) => {
        const hidden = Boolean(query) && !row.textContent.toLowerCase().includes(query);
        row.hidden = hidden;
        if (!hidden) shown += 1;
      });
      const label = query
        ? `Showing ${shown} of ${total} bounded record${total === 1 ? '' : 's'}`
        : `Showing ${total} bounded record${total === 1 ? '' : 's'}`;
      counts.forEach((element) => { element.textContent = label; });
    });
    panel.querySelector('[data-roleplay-refresh]')?.addEventListener('click', () => window.location.reload());
  });

  const app = document.querySelector('[data-eventlog-api]');
  if (!app) return;

  const state = {
    api: app.dataset.eventlogApi,
    csrf: app.dataset.eventlogCsrf,
    installationId: app.dataset.installationId,
    playthroughId: app.dataset.playthroughId,
    page: Number(app.dataset.page || 1),
    limit: Number(app.dataset.limit || 100),
    live: app.dataset.autoRefresh === 'true',
    interval: null,
    cursor: 0,
    busy: false,
  };
  const tableRoot = app.querySelector('[data-eventlog-table]');
  const paginations = app.querySelectorAll('[data-eventlog-pagination]');
  const counts = app.querySelectorAll('[data-eventlog-count]');
  const hiddenRoot = app.querySelector('[data-eventlog-hidden]');
  const hideSelect = app.querySelector('[data-eventlog-hide]');
  const liveButton = app.querySelector('[data-eventlog-live]');
  const liveIndicator = app.querySelector('[data-eventlog-live-indicator]');
  const status = app.querySelector('[data-eventlog-status]');
  const selectedButton = app.querySelector('[data-eventlog-delete-selected]');
  const selectedCount = app.querySelector('[data-eventlog-selected-count]');

  const selectedRows = () => [...app.querySelectorAll('[data-eventlog-rowid]:checked')]
    .map((checkbox) => Number(checkbox.dataset.eventlogRowid)).filter((value) => value > 0);

  const updateSelected = () => {
    const count = selectedRows().length;
    selectedCount.textContent = String(count);
    selectedButton.hidden = count === 0;
  };

  const updateCursor = () => {
    const ids = [...app.querySelectorAll('[data-eventlog-row]')]
      .map((row) => Number(row.dataset.eventlogRow)).filter((value) => value > 0);
    state.cursor = ids.length ? Math.max(state.cursor, ...ids) : state.cursor;
  };

  const cell = (row, value, className = '') => {
    const element = document.createElement('td');
    if (className) element.className = className;
    element.textContent = value === null || value === undefined ? '' : String(value);
    row.appendChild(element);
    return element;
  };

  const buildRow = (event, isNew = false) => {
    const row = document.createElement('tr');
    row.dataset.eventlogRow = String(event.rowid);
    if (isNew) row.classList.add('eventlog-new-row');
    const selectCell = document.createElement('td');
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.className = 'event-checkbox';
    checkbox.dataset.eventlogRowid = String(event.rowid);
    checkbox.setAttribute('aria-label', `Select event ${event.rowid}`);
    checkbox.addEventListener('change', updateSelected);
    selectCell.appendChild(checkbox);
    row.appendChild(selectCell);
    const chatClass = event.type === 'chat' ? 'eventlog-chat' : '';
    cell(row, event.type, chatClass);
    cell(row, event.data, chatClass);
    cell(row, event.people);
    cell(row, event.game_time);
    cell(row, event.time_utc);
    const actionCell = document.createElement('td');
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'eventlog-row-delete';
    remove.dataset.eventlogDeleteRow = String(event.rowid);
    remove.title = 'Delete event';
    remove.textContent = `${event.rowid} 🗑️`;
    actionCell.appendChild(remove);
    row.appendChild(actionCell);
    return row;
  };

  const renderTable = (events) => {
    const table = document.createElement('table');
    table.className = 'eventlog-table';
    const head = document.createElement('thead');
    const headerRow = document.createElement('tr');
    const selectHeader = document.createElement('th');
    const selectAll = document.createElement('input');
    selectAll.type = 'checkbox';
    selectAll.dataset.eventlogSelectAll = '';
    selectAll.setAttribute('aria-label', 'Select all events');
    selectHeader.appendChild(selectAll);
    headerRow.appendChild(selectHeader);
    ['Event', 'Events', 'People Present', 'Tamrielic Time', 'Time (UTC)', 'Record'].forEach((label) => {
      const header = document.createElement('th');
      header.textContent = label;
      headerRow.appendChild(header);
    });
    head.appendChild(headerRow);
    table.appendChild(head);
    const body = document.createElement('tbody');
    if (!events.length) {
      const row = document.createElement('tr');
      row.className = 'eventlog-empty';
      const empty = document.createElement('td');
      empty.colSpan = 7;
      empty.textContent = 'No roleplay events have been recorded yet.';
      row.appendChild(empty);
      body.appendChild(row);
    } else {
      events.forEach((event) => body.appendChild(buildRow(event)));
    }
    table.appendChild(body);
    tableRoot.replaceChildren(table);
    updateSelected();
    updateCursor();
  };

  const renderPagination = (data) => {
    const page = Number(data.current_page || 1);
    const pages = Number(data.total_pages || 0);
    const button = (label, target, active = false) => {
      const item = document.createElement('button');
      item.type = 'button';
      item.textContent = label;
      if (target) item.dataset.eventlogPage = String(target);
      if (active) item.className = 'active';
      return item;
    };
    const items = [];
    if (!pages) items.push(button('1', 0, true));
    else {
      if (page > 1) items.push(button('Previous', page - 1));
      let numbers;
      if (pages <= 10) numbers = Array.from({ length: pages }, (_, index) => index + 1);
      else numbers = [...new Set([1, ...Array.from({ length: Math.max(0, Math.min(pages - 1, page + 2) - Math.max(2, page - 2) + 1) },
        (_, index) => Math.max(2, page - 2) + index), pages])];
      let previous = 0;
      numbers.forEach((number) => {
        if (previous && number > previous + 1) {
          const ellipsis = document.createElement('span');
          ellipsis.className = 'pagination-ellipsis';
          ellipsis.textContent = '...';
          items.push(ellipsis);
        }
        items.push(button(String(number), number, number === page));
        previous = number;
      });
      if (page < pages) items.push(button('Next', page + 1));
    }
    paginations.forEach((target, index) => {
      target.replaceChildren(...(index === 0 ? items : items.map((item) => item.cloneNode(true))));
    });
  };

  const renderCount = (shown, data) => {
    const total = Math.max(0, Number(data.total_records || 0));
    const page = Math.max(1, Number(data.current_page || 1));
    const pages = Math.max(1, Number(data.total_pages || 0));
    const label = `Showing ${shown} of ${total} event${total === 1 ? '' : 's'} · Page ${page} of ${pages}`;
    counts.forEach((element) => { element.textContent = label; });
  };

  const renderFilters = (data) => {
    hideSelect.replaceChildren(new Option('Hide event...', ''));
    (data.event_types || []).forEach((item) => hideSelect.add(new Option(item.type, item.type)));
    hiddenRoot.replaceChildren(...(data.hidden_types || []).map((type) => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'eventlog-hidden-chip';
      chip.dataset.eventlogShowType = type;
      chip.textContent = `${type} ×`;
      return chip;
    }));
  };

  const pageUrl = (page = state.page) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', 'eventlog');
    url.searchParams.set('page', String(page));
    url.searchParams.set('limit', String(state.limit));
    if (state.installationId) url.searchParams.set('installation_id', state.installationId);
    if (state.playthroughId) url.searchParams.set('playthrough_id', state.playthroughId);
    if (state.live) url.searchParams.set('autorefresh', 'true');
    else url.searchParams.delete('autorefresh');
    return url;
  };

  const apiUrl = (parameters = {}) => {
    const url = new URL(state.api, window.location.origin);
    if (state.installationId) url.searchParams.set('installation_id', state.installationId);
    if (state.playthroughId) url.searchParams.set('playthrough_id', state.playthroughId);
    Object.entries(parameters).forEach(([key, value]) => url.searchParams.set(key, String(value)));
    return url;
  };

  const request = async (url, options = {}) => {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    const data = await response.json().catch(() => ({ error: 'invalid_response' }));
    if (!response.ok) throw new Error(data.error || 'eventlog_request_failed');
    return data;
  };

  const loadPage = async (page) => {
    if (state.busy) return;
    state.busy = true;
    try {
      const data = await request(apiUrl({ page, limit: state.limit }));
      state.page = Number(data.pagination?.current_page || page);
      app.dataset.page = String(state.page);
      if (data.scope) {
        state.installationId = data.scope.installation_id;
        state.playthroughId = data.scope.playthrough_id;
        app.dataset.installationId = state.installationId;
        app.dataset.playthroughId = state.playthroughId;
      }
      renderTable(data.data || []);
      renderPagination(data.pagination || {});
      renderCount((data.data || []).length, data.pagination || {});
      renderFilters(data);
      history.replaceState({}, '', pageUrl());
      status.textContent = '';
    } catch (error) {
      status.textContent = `Event log refresh failed: ${error.message}`;
      status.className = 'eventlog-status error';
    } finally {
      state.busy = false;
    }
  };

  const poll = async () => {
    if (!state.live || state.busy) return;
    state.busy = true;
    try {
      const data = await request(apiUrl({ since_rowid: state.cursor, limit: state.limit }));
      const events = data.data || [];
      if (events.length) {
        const body = tableRoot.querySelector('tbody');
        body?.querySelector('.eventlog-empty')?.remove();
        events.slice().reverse().forEach((event) => body?.prepend(buildRow(event, true)));
        state.cursor = Math.max(state.cursor, ...events.map((event) => Number(event.rowid)));
        const visible = app.querySelectorAll('[data-eventlog-row]').length;
        counts.forEach((element) => {
          element.textContent = `Showing ${visible} live event${visible === 1 ? '' : 's'} · Page ${state.page}`;
        });
        status.textContent = `${events.length} new event${events.length === 1 ? '' : 's'}`;
        status.className = 'eventlog-status success';
      }
    } catch (error) {
      status.textContent = `Live monitoring paused: ${error.message}`;
      status.className = 'eventlog-status error';
    } finally {
      state.busy = false;
    }
  };

  const setLive = (enabled) => {
    state.live = enabled;
    liveButton.innerHTML = enabled ? '&#x23F8;&#xFE0F; Stop Live' : 'Auto Refresh';
    liveButton.classList.toggle('active', !enabled);
    liveIndicator.hidden = !enabled;
    if (state.interval) window.clearInterval(state.interval);
    state.interval = enabled ? window.setInterval(poll, 2000) : null;
    history.replaceState({}, '', pageUrl());
    if (enabled) poll();
  };

  const mutate = async (body, confirmation) => {
    if (confirmation && !window.confirm(confirmation)) return;
    try {
      const data = await request(state.api, { method: 'DELETE', headers: {
        'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf,
      }, body: JSON.stringify({ ...body, installation_id: state.installationId, playthrough_id: state.playthroughId }) });
      status.textContent = `Successfully deleted ${data.deleted_count || 0} event(s).`;
      status.className = 'eventlog-status success';
      await loadPage(1);
    } catch (error) {
      status.textContent = `Event deletion failed: ${error.message}`;
      status.className = 'eventlog-status error';
    }
  };

  const updateHidden = async (action, type = '') => {
    try {
      await request(`${state.api}/hidden-types`, { method: 'POST', headers: {
        'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf,
      }, body: JSON.stringify({ action, type, installation_id: state.installationId, playthrough_id: state.playthroughId }) });
      await loadPage(1);
    } catch (error) {
      status.textContent = `Event filter update failed: ${error.message}`;
      status.className = 'eventlog-status error';
    }
  };

  app.addEventListener('change', (event) => {
    if (event.target.matches('[data-eventlog-select-all]')) {
      app.querySelectorAll('[data-eventlog-rowid]').forEach((checkbox) => { checkbox.checked = event.target.checked; });
      updateSelected();
    } else if (event.target.matches('[data-eventlog-rowid]')) updateSelected();
    else if (event.target.matches('[data-eventlog-hide]') && event.target.value) updateHidden('hide', event.target.value);
  });

  app.addEventListener('click', (event) => {
    const pageButton = event.target.closest('[data-eventlog-page]');
    if (pageButton) loadPage(Number(pageButton.dataset.eventlogPage));
    const rowButton = event.target.closest('[data-eventlog-delete-row]');
    if (rowButton) mutate({ mode: 'row', rowid: Number(rowButton.dataset.eventlogDeleteRow) }, 'Delete this event from AI context?');
    const showButton = event.target.closest('[data-eventlog-show-type]');
    if (showButton) updateHidden('show', showButton.dataset.eventlogShowType);
  });

  liveButton.addEventListener('click', () => setLive(!state.live));
  selectedButton.addEventListener('click', () => mutate({ mode: 'selected', rowids: selectedRows() }, 'Delete the selected events from AI context?'));
  app.querySelector('[data-eventlog-delete]').addEventListener('click', () => {
    const preset = app.querySelector('[data-eventlog-delete-preset]').value;
    if (preset === 'all') {
      const confirmation = window.prompt('THIS WILL DELETE ALL EVENTS IN THE EVENT LOG!\n\nType exactly: Delete');
      if (confirmation === 'Delete') mutate({ mode: 'all', confirmation: 'Delete' });
      else if (confirmation !== null) window.alert('Operation cancelled. You must type exactly "Delete" to confirm.');
      return;
    }
    mutate({ mode: 'latest', count: Number(preset) }, `Delete the latest ${preset} visible events?`);
  });

  updateCursor();
  if (state.live) setLive(true);
});

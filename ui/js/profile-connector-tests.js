/* Shared Core Profile / Global connector tests: plan first, explicit confirmation, deduplicated results. */
(() => {
    const overlay = document.querySelector('[data-profile-test-overlay]');
    if (!overlay) return;

    const dialog = overlay.querySelector('[data-profile-test-dialog]');
    const planHost = overlay.querySelector('[data-profile-test-plan]');
    const statusLine = overlay.querySelector('[data-profile-test-status]');
    const countsHost = overlay.querySelector('[data-profile-test-counts]');
    const scopeLine = overlay.querySelector('[data-profile-test-scope]');
    const progress = overlay.querySelector('[data-profile-test-progress]');
    const progressFill = overlay.querySelector('[data-profile-test-progress-fill]');
    const runButton = overlay.querySelector('[data-profile-test-run]');
    const stopButton = overlay.querySelector('[data-profile-test-stop]');
    const reloadButton = overlay.querySelector('[data-profile-test-reload]');
    const openButtons = Array.from(document.querySelectorAll('[data-profile-test-open]'));
    if (!dialog || !planHost || !statusLine || !runButton || openButtons.length === 0) return;

    const endpoint = dialog.getAttribute('data-profile-test-endpoint') || '';
    const csrf = dialog.getAttribute('data-profile-test-csrf') || '';
    const installationId = dialog.getAttribute('data-profile-test-installation') || '';
    const globalMode = dialog.getAttribute('data-profile-test-mode') === 'global';
    const MAX_CONCURRENT_TESTS = 2;

    const STATUS_LABELS = {
        pass: globalMode ? 'Pass' : 'Passed',
        fail: globalMode ? 'Fail' : 'Failed',
        warn: 'Warn',
        skipped: 'Skipped',
        pending: globalMode ? 'Pending' : 'Not run',
        running: 'Testing',
    };
    const KIND_LABELS = { provider: 'Text model connector', tts_provider: 'Voice connector' };

    const text = (value) => (value === null || value === undefined ? '' : String(value));
    const bucketFor = (status) => (Object.prototype.hasOwnProperty.call(STATUS_LABELS, status) ? status : 'pending');

    /* Results are held per job_key so one connector call feeds every profile slot that routes to it. */
    let plan = null;
    let jobStates = new Map();
    let slotNodes = [];
    let loading = false;
    let loadFailed = false;
    let running = false;
    let stopRequested = false;
    let completedJobs = 0;
    let opener = null;

    const focusableIn = () => Array.from(dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'))
        .filter((node) => !node.hasAttribute('disabled') && !node.hidden && node.offsetParent !== null);

    const announce = (message) => { statusLine.textContent = message; };

    const setProgress = (done, total) => {
        const active = total > 0;
        progress.hidden = !active;
        if (!active) return;
        progress.setAttribute('aria-valuemax', String(total));
        progress.setAttribute('aria-valuenow', String(done));
        progress.setAttribute('aria-valuetext', done + ' of ' + total + ' connectors tested');
        progressFill.style.width = Math.round((done / total) * 100) + '%';
    };

    const slotStatus = (slot) => {
        const state = jobStates.get(text(slot.job_key));
        if (state) return state;
        return { status: bucketFor(text(slot.status) || 'skipped'), message: text(slot.message) };
    };

    const renderCounts = () => {
        countsHost.textContent = '';
        const tally = { pass: 0, warn: 0, fail: 0, skipped: 0, pending: 0 };
        slotNodes.forEach((entry) => {
            const state = slotStatus(entry.slot);
            tally[state.status === 'running' ? 'pending' : bucketFor(state.status)] += 1;
        });
        const counters = globalMode ? [['pass', 'Passed'], ['warn', 'Warnings'], ['fail', 'Failed'], ['skipped', 'Skipped'], ['pending', 'Pending']]
            : [['pass', 'Passed'], ['fail', 'Failed'], ['skipped', 'Skipped'], ['pending', 'Not run']];
        counters.forEach((pair) => {
            const pill = document.createElement('span');
            pill.className = 'profile-test-count profile-test-count-' + pair[0];
            const value = document.createElement('strong');
            value.textContent = String(tally[pair[0]]);
            const caption = document.createElement('span');
            caption.textContent = pair[1];
            pill.append(value, caption);
            countsHost.append(pill);
        });
    };

    const paintSlot = (entry) => {
        const state = slotStatus(entry.slot);
        const bucket = bucketFor(state.status);
        entry.pill.textContent = STATUS_LABELS[bucket];
        entry.pill.className = 'profile-test-pill profile-test-pill-' + bucket;
        const detail = text(state.message);
        entry.detail.textContent = detail;
        entry.detail.hidden = detail === '';
    };

    const paintJob = (jobKey) => {
        slotNodes.forEach((entry) => { if (text(entry.slot.job_key) === jobKey) paintSlot(entry); });
        renderCounts();
    };

    const runnableJobs = () => (plan && Array.isArray(plan.jobs) ? plan.jobs : []).filter((job) => text(job.job_key) !== '');

    const jobLabels = () => {
        const labels = new Map();
        runnableJobs().forEach((job) => { labels.set(text(job.job_key), text(job.label)); });
        return labels;
    };

    const renderPlan = () => {
        planHost.textContent = '';
        slotNodes = [];
        const profiles = plan && Array.isArray(globalMode ? plan.groups : plan.profiles) ? (globalMode ? plan.groups : plan.profiles) : [];
        if (profiles.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'profile-test-empty';
            empty.textContent = globalMode ? 'No global connectors found.' : 'No Core Profiles are set up for this installation yet.';
            planHost.append(empty);
            renderCounts();
            return;
        }
        profiles.forEach((profile) => {
            const card = document.createElement('section');
            card.className = 'profile-test-profile';
            const head = document.createElement('div');
            head.className = 'profile-test-profile-head';
            const title = document.createElement('h3');
            title.textContent = text(profile.label) || 'Untitled Core Profile';
            head.append(title);
            if (profile.default_npc) {
                const flag = document.createElement('span');
                flag.className = 'profile-test-flag';
                flag.textContent = 'Default NPC profile';
                head.append(flag);
            }
            card.append(head);

            const slots = Array.isArray(profile.slots) ? profile.slots : [];
            if (slots.length === 0) {
                const none = document.createElement('p');
                none.className = 'profile-test-empty';
                none.textContent = 'This profile inherits every connector, so it has nothing of its own to test.';
                card.append(none);
            } else {
                const labels = jobLabels();
                const list = document.createElement('ul');
                list.className = 'profile-test-slots';
                slots.forEach((slot) => {
                    const row = document.createElement('li');
                    row.className = 'profile-test-slot';
                    const label = document.createElement('span');
                    label.className = 'profile-test-slot-label';
                    label.textContent = text(slot.label) || text(slot.field);
                    const connector = document.createElement('span');
                    connector.className = 'profile-test-slot-connector';
                    const connectorName = document.createElement('span');
                    connectorName.className = 'profile-test-slot-name';
                    connectorName.textContent = text(slot.connector_label) || labels.get(text(slot.job_key)) || 'No connector selected';
                    const kind = document.createElement('span');
                    kind.className = 'profile-test-slot-kind';
                    kind.textContent = KIND_LABELS[text(slot.kind)] || 'Connector';
                    connector.append(connectorName);
                    if (!globalMode) connector.append(kind);
                    const pill = document.createElement('span');
                    pill.className = 'profile-test-pill';
                    const detail = document.createElement('span');
                    detail.className = 'profile-test-slot-detail';
                    row.append(label, connector, pill, detail);
                    list.append(row);
                    slotNodes.push({ slot: slot, pill: pill, detail: detail });
                });
                card.append(list);
            }
            planHost.append(card);
        });
        slotNodes.forEach(paintSlot);
        renderCounts();
    };

    const describeScope = () => {
        const jobs = runnableJobs().length;
        const slots = slotNodes.filter((entry) => text(entry.slot.job_key) !== '').length;
        scopeLine.textContent = globalMode
            ? (jobs === 0 ? 'No enabled global connector is available to test.' : jobs + ' unique connector' + (jobs === 1 ? ' covers ' : 's cover ') + slots + ' enabled global slot' + (slots === 1 ? '' : 's') + '.')
            : jobs === 0
            ? 'No connector is routed by these Core Profiles, so there is nothing to test.'
            : jobs + ' connector' + (jobs === 1 ? '' : 's') + ' would be tested once each, covering '
                + slots + ' profile slot' + (slots === 1 ? '' : 's') + '.';
    };

    const syncControls = () => {
        runButton.disabled = loading || running || runnableJobs().length === 0;
        runButton.textContent = completedJobs > 0 && !running ? 'Run tests again' : 'Run tests';
        runButton.hidden = loadFailed;
        stopButton.hidden = !running || stopRequested;
        reloadButton.hidden = !loadFailed && !(globalMode && !loading && !running);
    };

    const loadPlan = async () => {
        loading = true;
        loadFailed = false;
        completedJobs = 0;
        syncControls();
        announce('Loading the connector test plan. No provider is contacted while the plan loads.');
        planHost.textContent = '';
        try {
            const response = await fetch(endpoint + '?installation_id=' + encodeURIComponent(installationId), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: AbortSignal.timeout(15000),
            });
            if (!response.ok) throw new Error('plan-unavailable');
            const payload = await response.json();
            plan = payload && typeof payload === 'object' ? payload : { profiles: [], jobs: [] };
            jobStates = new Map();
            renderPlan();
            describeScope();
            setProgress(0, globalMode ? runnableJobs().length : 0);
            announce(runnableJobs().length === 0
                ? (globalMode ? 'Nothing to test. Enable and save a global connector first.' : 'Nothing to test. These Core Profiles do not route a connector of their own.')
                : 'Test plan ready. Nothing has been sent to any provider yet. Press Run tests to start.');
        } catch (_error) {
            plan = null;
            slotNodes = [];
            loadFailed = true;
            planHost.textContent = '';
            renderCounts();
            scopeLine.textContent = '';
            setProgress(0, 0);
            announce('The connector test plan could not be loaded and no provider was contacted. Use Reload plan to try again.');
        } finally {
            loading = false;
            syncControls();
        }
    };

    const runJob = async (job) => {
        const jobKey = text(job.job_key);
        jobStates.set(jobKey, { status: 'running', message: 'Waiting for the connector to answer.' });
        paintJob(jobKey);
        let status = 'fail';
        let message = 'The test could not be completed.';
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                signal: AbortSignal.timeout(135000),
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, Accept: 'application/json' },
                body: JSON.stringify({
                    installation_id: installationId,
                    kind: text(job.kind),
                    configuration_id: text(job.configuration_id),
                    ...(globalMode ? {confirm: 'Run tests'} : {}),
                }),
            });
            let payload = null;
            try { payload = await response.json(); } catch (_parseError) {
                if (_parseError.name === 'TimeoutError' || _parseError.name === 'AbortError') throw _parseError;
                payload = null;
            }
            const result = payload && typeof payload === 'object' ? payload.result : null;
            if (response.ok && result && typeof result === 'object') {
                status = bucketFor(text(result.status));
                message = text(result.message);
            } else if (payload && typeof payload === 'object' && text(payload.error) !== '') {
                message = 'The server reported: ' + text(payload.error);
            }
        } catch (_error) {
            message = _error.name === 'TimeoutError'
                ? 'Timed out waiting for the connector. The server may still finish this test; its result is unknown.'
                : 'The server could not be reached for this connector.';
        }
        jobStates.set(jobKey, { status: status, message: message });
        completedJobs += 1;
        paintJob(jobKey);
        setProgress(completedJobs, runnableJobs().length);
    };

    const requestStop = () => {
        if (!running || stopRequested) return;
        stopRequested = true;
        syncControls();
        announce('Stopping. Tests already sent will finish, and no further connector will be contacted.');
    };

    const startRun = async () => {
        const jobs = runnableJobs();
        if (running || loading || jobs.length === 0) return;
        running = true;
        stopRequested = false;
        completedJobs = 0;
        jobs.forEach((job) => { jobStates.set(text(job.job_key), { status: 'pending', message: '' }); });
        slotNodes.forEach(paintSlot);
        renderCounts();
        setProgress(0, jobs.length);
        syncControls();
        announce('Running ' + jobs.length + ' connector test' + (jobs.length === 1 ? '' : 's')
            + ', up to ' + MAX_CONCURRENT_TESTS + ' at a time.');

        const queue = jobs.slice();
        const worker = async () => {
            while (!stopRequested && queue.length > 0) {
                const job = queue.shift();
                if (job) await runJob(job);
            }
        };
        const workers = [];
        for (let index = 0; index < Math.min(MAX_CONCURRENT_TESTS, queue.length); index += 1) workers.push(worker());
        await Promise.all(workers);

        const neverStarted = queue.length;
        queue.forEach((job) => {
            jobStates.set(text(job.job_key), { status: 'pending', message: 'Not started. The run was stopped before this connector was reached.' });
        });
        const wasStopped = stopRequested;
        running = false;
        stopRequested = false;
        slotNodes.forEach(paintSlot);
        renderCounts();
        syncControls();

        const states = Array.from(jobStates.values());
        const passed = states.filter((state) => state.status === 'pass').length;
        const failed = states.filter((state) => state.status === 'fail').length;
        announce(wasStopped
            ? 'Stopped after ' + completedJobs + ' of ' + jobs.length + ' connectors. ' + passed + ' passed, '
                + failed + ' failed, ' + neverStarted + ' never started.'
            : 'Finished ' + jobs.length + ' connector test' + (jobs.length === 1 ? '' : 's') + '. '
                + passed + ' passed, ' + failed + ' failed.');
    };

    function close() {
        if (overlay.hidden) return;
        requestStop();
        overlay.hidden = true;
        document.body.classList.remove('profile-test-open');
        document.removeEventListener('keydown', onKeydown, true);
        const restoreTo = opener;
        opener = null;
        if (restoreTo && typeof restoreTo.focus === 'function') restoreTo.focus();
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            if (running && !stopRequested) { requestStop(); return; }
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
        document.body.classList.add('profile-test-open');
        document.addEventListener('keydown', onKeydown, true);
        const closeControl = overlay.querySelector('[data-profile-test-close]');
        if (closeControl) closeControl.focus();
        if ((plan === null || globalMode) && !loading && !running) loadPlan();
        else syncControls();
    };

    openButtons.forEach((button) => { button.addEventListener('click', () => open(button)); });
    overlay.querySelectorAll('[data-profile-test-close]').forEach((button) => { button.addEventListener('click', () => close()); });
    overlay.addEventListener('mousedown', (event) => { if (event.target === overlay && !running) close(); });
    runButton.addEventListener('click', () => { startRun(); });
    stopButton.addEventListener('click', () => { requestStop(); });
    reloadButton.addEventListener('click', () => { loadPlan(); });
    syncControls();
})();

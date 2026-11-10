/* Server Plugins manager: installation-scoped package lifecycle over the session+CSRF management API. */
(() => {
    const root = document.querySelector('[data-server-plugins]');
    const contextControl = root && root.querySelector('[data-plugin-installation]');
    if (!root || !contextControl) return;
    const $ = selector => root.querySelector(selector);
    const list = $('[data-plugin-list]');
    const catalog = $('[data-plugin-catalog]');
    const status = $('[data-plugin-status]');
    const fileInput = $('[data-plugin-file]');
    const fileInstall = $('[data-plugin-file-install]');
    const fileSummary = $('[data-plugin-file-summary]');
    const progress = $('[data-plugin-progress]');
    const dialog = $('[data-plugin-confirm]');
    const POLL_MS = 2000;

    const MESSAGES = {
        unauthorized: 'Your session expired. Reload the page and try again.',
        package_invalid_request: 'The server refused this request. Reload the page and try again.',
        package_not_installed: 'This plugin is not installed.',
        package_operation_pending: 'Another install or update for this plugin is still running.',
        package_already_installed: 'This version is already installed.',
        package_version_not_newer: 'The installed version is the same or newer. Downgrades are not allowed.',
        duplicate_conflict: 'This request conflicts with an earlier one. Reload the page and try again.',
        package_too_large: 'The package is too large.',
        package_storage_full: 'The server has too many unfinished uploads. Try again later.',
        package_storage_busy: 'The server is busy. Try again in a moment.',
        package_storage_unavailable: 'Plugin storage is unavailable on the server.',
        package_upload_not_found: 'The upload expired. Choose the file again.',
        package_upload_out_of_order: 'The upload was interrupted. Choose the file again.',
        package_hash_mismatch: 'The package does not match its checksum.',
        package_archive_invalid: 'The file is not a valid .dwpkg package.',
        package_manifest_invalid: 'The package manifest is invalid.',
        package_contract_invalid: 'The plugin manifest is not a valid LORKHAN plugin manifest.',
        package_identity_mismatch: 'The package name or version does not match its plugin manifest.',
        package_incompatible: 'This plugin needs a newer LorkhanServer.',
        package_checksums_invalid: 'The package checksum list is missing or incomplete.',
        package_checksum_mismatch: 'A file in the package does not match its checksum.',
        package_path_unsafe: 'The package contains an unsafe file path.',
        package_path_collision: 'The package contains conflicting file paths.',
        package_duplicate_entry: 'The package contains duplicate files.',
        package_link_rejected: 'The package contains a link, which is not allowed.',
        package_bomb_rejected: 'The package expands to an unsafe size.',
        package_payload_unsupported: 'The package contains client, game or executable files. Only server files are allowed.',
        package_apply_failed: 'The server could not finish the install.',
        catalog_unavailable: 'The plugin catalog could not be read.',
        catalog_entry_not_found: 'This catalog entry is no longer listed.',
        catalog_download_failed: 'The download failed. Try again later.',
        catalog_download_rejected: 'The download address was refused.',
        catalog_redirect_rejected: 'The download address redirected, which is not allowed.',
        crypto_unavailable: 'Open this page through localhost to install from a file.',
        file_unreadable: 'The file is not a valid .dwpkg package.',
        network: 'The server could not be reached.',
    };
    const message = code => MESSAGES[code] || 'The request failed (' + code + ').';

    class Stale extends Error {}
    class ApiError extends Error { constructor(code, status) { super(code); this.code = code; this.status = status; } }

    // One context per selected installation. Switching aborts its requests and timers; late results are dropped.
    let context = null;
    let packages = [];
    let operations = [];
    let pollTimer = 0;
    let fileCandidate = null;
    let fileSelection = 0;
    let catalogEntries = [];
    const acting = new Set();
    const catalogActing = new Set();

    function newContext(installation) {
        if (context) { context.controller.abort(); window.clearTimeout(pollTimer); }
        context = {installation, controller: new AbortController()};
        return context;
    }

    async function call(ctx, method, path, body, contentType) {
        const headers = {Accept: 'application/json'};
        if (method !== 'GET') headers['X-CSRF-Token'] = root.dataset.csrf;
        if (body !== undefined) headers['Content-Type'] = contentType || 'application/json';
        let response;
        try {
            response = await fetch(root.dataset.api + path + '?installation_id=' + encodeURIComponent(ctx.installation), {
                method, headers, credentials: 'same-origin', signal: ctx.controller.signal,
                body: body === undefined ? undefined : (contentType ? body : JSON.stringify(body)),
            });
        } catch (error) {
            if (ctx !== context) throw new Stale();
            throw new ApiError('network', 0);
        }
        let data = null;
        try { data = await response.json(); } catch (_) { data = null; }
        if (ctx !== context) throw new Stale();
        if (!response.ok) throw new ApiError(data && typeof data.error === 'string' ? data.error : 'http_' + response.status, response.status);
        return data;
    }

    function say(text, tone) {
        status.textContent = text;
        status.dataset.tone = tone || 'info';
    }

    function el(tag, attributes, children) {
        const node = document.createElement(tag);
        Object.entries(attributes || {}).forEach(([key, value]) => {
            if (value === false || value === null || value === undefined) return;
            if (key === 'text') node.textContent = value; else node.setAttribute(key, value === true ? '' : value);
        });
        (children || []).forEach(child => child && node.append(child));
        return node;
    }

    function pendingFor(pluginId) { return operations.find(op => op.plugin_id === pluginId && op.state === 'queued') || null; }
    function lastFinished(pluginId) { return operations.find(op => op.plugin_id === pluginId && op.state !== 'queued') || null; }

    // Keep catalog controls locked until their download or durable install operation finishes.
    function updateCatalogButtons() {
        catalog.querySelectorAll('button[data-catalog-entry]').forEach(button => {
            const entry = catalogEntries.find(item => item.id === button.dataset.catalogEntry);
            button.disabled = !entry || !entry.compatible || catalogActing.has(entry.id) || !!pendingFor(entry.plugin_id);
        });
    }

    function renderPackages(focusKey) {
        updateCatalogButtons();
        list.removeAttribute('aria-busy');
        // A poll redraw keeps keyboard focus on the same control (or its card when that control is now disabled).
        const active = list.contains(document.activeElement) ? document.activeElement : null;
        focusKey = focusKey || (active && (active.dataset.focusKey || (active.dataset.focusCard && active.dataset.focusCard + '|card'))) || null;
        const known = new Set(packages.map(item => item.plugin_id));
        // A first install that is queued or failed has no package row yet; show it so the result is visible.
        const orphans = operations.filter(op => !known.has(op.plugin_id) && (op.state === 'queued' || op.state === 'failed'));
        if (!packages.length && !orphans.length) {
            list.replaceChildren(el('p', {class: 'plugins-empty', text: 'No server plugins are installed for this installation.'}));
            return;
        }
        const items = packages.map(item => packageCard(item));
        orphans.filter((op, index) => orphans.findIndex(other => other.plugin_id === op.plugin_id) === index)
            .forEach(op => items.push(operationCard(op)));
        list.replaceChildren(el('ul', {class: 'plugins-list'}, items));
        if (focusKey) {
            const target = list.querySelector('[data-focus-key="' + CSS.escape(focusKey) + '"]:not([disabled])')
                || list.querySelector('[data-focus-card="' + CSS.escape(focusKey.split('|')[0]) + '"]');
            if (target) target.focus(); else $('#plugins-installed-title').focus();
        }
    }

    function stateBadge(text, tone) { return el('span', {class: 'lorkhan-state-pill ' + tone, text}); }

    function operationLine(pluginId, installedVersion) {
        const pending = pendingFor(pluginId);
        if (pending) return el('p', {class: 'plugins-op is-pending', text: (pending.operation === 'update' ? 'Updating to ' : 'Installing ') + pending.version + '…'});
        const last = lastFinished(pluginId);
        if (last && last.state === 'failed') {
            const kept = installedVersion ? ' Version ' + installedVersion + ' and its data were kept.' : ' Nothing was installed.';
            return el('p', {class: 'plugins-op is-failed', text: (last.operation === 'update' ? 'Update to ' : 'Install of ') + last.version + ' failed: ' + message(last.error_code) + kept});
        }
        return null;
    }

    function packageCard(item) {
        const pending = !!pendingFor(item.plugin_id);
        const busy = pending || acting.has(item.plugin_id);
        const installed = item.state === 'installed';
        const badge = !installed ? stateBadge('Removed', 'is-neutral') : item.enabled ? stateBadge('Enabled', 'is-success') : stateBadge('Disabled', 'is-warning');
        const meta = el('p', {class: 'plugins-meta'});
        meta.append(el('code', {text: item.plugin_id}), document.createTextNode(' · Version ' + item.version
            + (item.previous_version ? ' (previously ' + item.previous_version + ')' : '')));
        const actions = el('div', {class: 'plugins-actions'});
        if (installed) {
            const toggle = item.enabled ? 'disable' : 'enable';
            actions.append(
                el('button', {type: 'button', class: 'btn-secondary', 'data-action': toggle, 'data-plugin': item.plugin_id,
                    'data-focus-key': item.plugin_id + '|toggle', disabled: busy, text: item.enabled ? 'Disable' : 'Enable',
                    'aria-label': (item.enabled ? 'Disable ' : 'Enable ') + item.display_name}),
                el('button', {type: 'button', class: 'btn-danger', 'data-action': 'remove', 'data-plugin': item.plugin_id,
                    'data-focus-key': item.plugin_id + '|remove', disabled: busy, text: 'Remove', 'aria-label': 'Remove ' + item.display_name}));
        }
        return el('li', {class: 'plugins-card', 'data-focus-card': item.plugin_id, tabindex: '-1'}, [
            el('div', {class: 'plugins-card-head'}, [el('h3', {text: item.display_name}), badge]),
            meta,
            !installed ? el('p', {class: 'plugins-note', text: 'Removed. Its data was kept; install the package again to restore it.'}) : null,
            operationLine(item.plugin_id, installed ? item.version : null),
            actions,
        ]);
    }

    function operationCard(op) {
        return el('li', {class: 'plugins-card', 'data-focus-card': op.plugin_id, tabindex: '-1'}, [
            el('div', {class: 'plugins-card-head'}, [el('h3', {text: op.plugin_id}),
                op.state === 'queued' ? stateBadge('Installing', 'is-warning') : stateBadge('Not installed', 'is-danger')]),
            operationLine(op.plugin_id, null),
        ]);
    }

    function schedulePoll(ctx) {
        window.clearTimeout(pollTimer);
        if (operations.some(op => op.state === 'queued')) pollTimer = window.setTimeout(() => loadPackages(ctx, null, true), POLL_MS);
    }

    async function loadPackages(ctx, focusKey, quiet) {
        if (!quiet) list.setAttribute('aria-busy', 'true');
        try {
            const data = await call(ctx, 'GET', '/plugin-manager');
            const before = new Set(operations.filter(op => op.state === 'queued').map(op => op.operation_id));
            packages = data.packages; operations = data.operations;
            renderPackages(focusKey);
            operations.filter(op => before.has(op.operation_id) && op.state !== 'queued').forEach(op => {
                if (op.state === 'succeeded') say((op.operation === 'update' ? 'Updated ' : 'Installed ') + op.plugin_id + ' ' + op.version + '.', 'success');
                else say(message(op.error_code), 'error');
            });
            schedulePoll(ctx);
        } catch (error) {
            if (error instanceof Stale) return;
            list.removeAttribute('aria-busy');
            if (quiet) { schedulePoll(ctx); return; }
            const retry = el('button', {type: 'button', class: 'btn-secondary', text: 'Retry'});
            retry.addEventListener('click', () => loadPackages(context, null));
            list.replaceChildren(el('div', {class: 'plugins-error', role: 'alert'}, [el('p', {text: 'Plugins could not be loaded. ' + message(error.code)}), retry]));
        }
    }

    async function loadCatalog(ctx) {
        catalog.setAttribute('aria-busy', 'true');
        try {
            const data = await call(ctx, 'GET', '/plugin-catalog');
            catalogEntries = data.entries;
            catalog.removeAttribute('aria-busy');
            if (!data.entries.length) {
                catalog.replaceChildren(el('p', {class: 'plugins-empty', text: 'No curated plugins are listed yet. You can still install a .dwpkg file you trust above.'}));
                return;
            }
            catalog.replaceChildren(el('ul', {class: 'plugins-list'}, data.entries.map(entry => el('li', {class: 'plugins-card'}, [
                el('div', {class: 'plugins-card-head'}, [el('h3', {text: entry.display_name}), entry.compatible ? null : stateBadge('Needs newer server', 'is-warning')]),
                el('p', {class: 'plugins-meta', text: entry.plugin_id + ' · Version ' + entry.version + ' · ' + entry.author}),
                entry.description ? el('p', {class: 'plugins-note', text: entry.description}) : null,
                el('div', {class: 'plugins-actions'}, [el('button', {type: 'button', class: 'btn-primary', 'data-catalog-entry': entry.id,
                    disabled: !entry.compatible, text: 'Install', 'aria-label': 'Install ' + entry.display_name + ' ' + entry.version})]),
            ]))));
            updateCatalogButtons();
        } catch (error) {
            if (error instanceof Stale) return;
            catalog.removeAttribute('aria-busy');
            const retry = el('button', {type: 'button', class: 'btn-secondary', text: 'Retry'});
            retry.addEventListener('click', () => loadCatalog(context));
            catalog.replaceChildren(el('div', {class: 'plugins-error', role: 'alert'}, [el('p', {text: message(error.code)}), retry]));
        }
    }

    function confirmRemoval(name) {
        return new Promise(resolve => {
            dialog.querySelector('#plugins-confirm-text').textContent = 'Remove ' + name + '? It stops running on this installation. '
                + 'Its data and NPC notes are kept and come back if you install it again.';
            dialog.returnValue = 'cancel';
            dialog.addEventListener('close', () => resolve(dialog.returnValue === 'confirm'), {once: true});
            dialog.showModal();
        });
    }

    list.addEventListener('click', async event => {
        const button = event.target.closest('button[data-action]');
        if (!button || button.disabled) return;
        const ctx = context; const pluginId = button.dataset.plugin; const action = button.dataset.action;
        const item = packages.find(entry => entry.plugin_id === pluginId);
        const focusKey = button.dataset.focusKey;
        if (action === 'remove' && !(await confirmRemoval(item ? item.display_name : pluginId))) { if (ctx === context) button.focus(); return; }
        if (ctx !== context) return;
        acting.add(pluginId); renderPackages(focusKey);
        try {
            await call(ctx, 'POST', '/plugin-packages/' + encodeURIComponent(pluginId) + '/' + action, {});
            say((item ? item.display_name : pluginId) + (action === 'remove' ? ' removed. Its data was kept.' : action === 'enable' ? ' enabled.' : ' disabled.'), 'success');
        } catch (error) {
            if (error instanceof Stale) return;
            say(message(error.code), 'error');
        } finally {
            if (ctx === context) acting.delete(pluginId);
        }
        if (ctx === context) await loadPackages(ctx, focusKey, true);
    });

    // Read manifest.json from the .dwpkg ZIP so the upload can name its plugin and version.
    async function readPackageManifest(file) {
        if (file.size < 22 || file.size > 64 * 1024 * 1024) throw new ApiError('package_too_large', 0);
        const bytes = new Uint8Array(await file.arrayBuffer());
        const view = new DataView(bytes.buffer);
        let end = -1;
        for (let i = bytes.length - 22; i >= Math.max(0, bytes.length - 65557); i--) if (view.getUint32(i, true) === 0x06054b50) { end = i; break; }
        if (end < 0) throw new ApiError('file_unreadable', 0);
        let offset = view.getUint32(end + 16, true);
        const count = view.getUint16(end + 10, true);
        for (let n = 0; n < count && offset + 46 <= bytes.length; n++) {
            if (view.getUint32(offset, true) !== 0x02014b50) break;
            const method = view.getUint16(offset + 10, true), compressed = view.getUint32(offset + 20, true), size = view.getUint32(offset + 24, true);
            const nameLength = view.getUint16(offset + 28, true), extra = view.getUint16(offset + 30, true), comment = view.getUint16(offset + 32, true);
            const local = view.getUint32(offset + 42, true);
            const name = new TextDecoder().decode(bytes.subarray(offset + 46, offset + 46 + nameLength));
            offset += 46 + nameLength + extra + comment;
            if (name !== 'manifest.json' || size > 65536 || compressed > 65536 || local + 30 > bytes.length) continue;
            const start = local + 30 + view.getUint16(local + 26, true) + view.getUint16(local + 28, true);
            if (view.getUint32(local, true) !== 0x04034b50 || start + compressed > bytes.length) throw new ApiError('file_unreadable', 0);
            const raw = bytes.subarray(start, start + compressed);
            let data = raw;
            if (method === 8) {
                const reader = new Blob([raw]).stream().pipeThrough(new DecompressionStream('deflate-raw')).getReader();
                const output = new Uint8Array(65536);
                let length = 0;
                try {
                    for (;;) {
                        const part = await reader.read();
                        if (part.done) break;
                        if (length + part.value.length > output.length) throw new ApiError('file_unreadable', 0);
                        output.set(part.value, length); length += part.value.length;
                    }
                    data = output.subarray(0, length);
                } finally { await reader.cancel(); reader.releaseLock(); }
            }
            else if (method !== 0) throw new ApiError('file_unreadable', 0);
            if (data.length !== size) throw new ApiError('file_unreadable', 0);
            const manifest = JSON.parse(new TextDecoder().decode(data));
            if (typeof manifest.name !== 'string' || typeof manifest.version !== 'string') break;
            return {plugin_id: manifest.name, version: manifest.version, display_name: typeof manifest.display_name === 'string' ? manifest.display_name : manifest.name, bytes};
        }
        throw new ApiError('file_unreadable', 0);
    }

    function resetFile(text) {
        fileSelection++;
        fileCandidate = null; fileInstall.disabled = true; fileInstall.textContent = 'Install';
        fileSummary.textContent = text || ''; progress.hidden = true;
    }

    fileInput.addEventListener('change', async () => {
        const ctx = context; resetFile();
        const selection = fileSelection;
        const file = fileInput.files && fileInput.files[0];
        if (!file) return;
        if (!window.crypto || !window.crypto.subtle || !window.crypto.randomUUID) { resetFile(message('crypto_unavailable')); return; }
        fileSummary.textContent = 'Checking ' + file.name + '…';
        try {
            const candidate = await readPackageManifest(file);
            const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', candidate.bytes));
            candidate.sha256 = Array.from(digest, value => value.toString(16).padStart(2, '0')).join('');
            if (ctx !== context || selection !== fileSelection) return;
            const probe = await call(ctx, 'POST', '/plugin-packages/probe', {plugin_id: candidate.plugin_id, version: candidate.version, sha256: candidate.sha256});
            if (selection !== fileSelection) return;
            const installed = probe.installed && probe.installed.state === 'installed' ? probe.installed.version : null;
            const label = candidate.display_name + ' ' + candidate.version;
            if (probe.pending) { resetFile(label + ': ' + message('package_operation_pending')); return; }
            if (probe.action === 'current') { resetFile(label + ' is already installed.'); return; }
            if (probe.action === 'conflict') { resetFile(label + ' is installed with different contents. Publish a new version number to update.'); return; }
            if (probe.action === 'older') { resetFile(label + ' is older than the installed ' + installed + '. Downgrades are not allowed.'); return; }
            candidate.action = probe.action; fileCandidate = candidate; fileInstall.disabled = false;
            fileInstall.textContent = probe.action === 'update' ? 'Update' : 'Install';
            fileSummary.textContent = probe.action === 'update' ? 'Update ' + candidate.display_name + ' from ' + installed + ' to ' + candidate.version + '.' : 'Install ' + label + '.';
        } catch (error) {
            if (error instanceof Stale || selection !== fileSelection) return;
            resetFile(message(error instanceof ApiError ? error.code : 'file_unreadable'));
        }
    });

    async function track(ctx, operation, label) {
        operations = [operation].concat(operations.filter(op => op.plugin_id !== operation.plugin_id || op.state !== 'queued'));
        renderPackages(null);
        say(label + ' queued. Waiting for the server…', 'info');
        schedulePoll(ctx);
    }

    fileInstall.addEventListener('click', async () => {
        const candidate = fileCandidate; const ctx = context;
        if (!candidate) return;
        fileInstall.disabled = true; fileInput.disabled = true; progress.hidden = false; progress.value = 0;
        try {
            const upload = await call(ctx, 'POST', '/plugin-packages/uploads', {plugin_id: candidate.plugin_id, version: candidate.version,
                size: candidate.bytes.length, sha256: candidate.sha256});
            for (let index = 0, sent = 0; sent < candidate.bytes.length; index++) {
                const chunk = candidate.bytes.subarray(sent, sent + upload.chunk_bytes);
                await call(ctx, 'PUT', '/plugin-packages/uploads/' + upload.upload_id + '/chunks/' + index, chunk, 'application/octet-stream');
                sent += chunk.length; progress.value = Math.round(100 * sent / candidate.bytes.length);
            }
            const queued = await call(ctx, 'POST', '/plugin-packages/' + candidate.action, {request_id: crypto.randomUUID(), upload_id: upload.upload_id});
            fileInput.value = ''; resetFile();
            await track(ctx, queued.operation, candidate.display_name + ' ' + candidate.version);
        } catch (error) {
            if (error instanceof Stale) return;
            resetFile(message(error.code));
            say(message(error.code), 'error');
        } finally {
            if (ctx === context) fileInput.disabled = false;
        }
    });

    catalog.addEventListener('click', async event => {
        const button = event.target.closest('button[data-catalog-entry]');
        if (!button || button.disabled) return;
        const ctx = context; const entryId = button.dataset.catalogEntry;
        catalogActing.add(entryId); updateCatalogButtons();
        say('Downloading and checking the package…', 'info');
        try {
            const queued = await call(ctx, 'POST', '/plugin-catalog/install', {request_id: crypto.randomUUID(), entry_id: button.dataset.catalogEntry});
            await track(ctx, queued.operation, queued.operation.plugin_id + ' ' + queued.operation.version);
        } catch (error) {
            if (error instanceof Stale) return;
            say(message(error.code), 'error');
        } finally {
            if (ctx === context) { catalogActing.delete(entryId); updateCatalogButtons(); button.focus(); }
        }
    });

    function load(installation) {
        const ctx = newContext(installation);
        packages = []; operations = []; acting.clear(); catalogActing.clear(); catalogEntries = [];
        fileInput.value = ''; fileInput.disabled = false; resetFile(); say('');
        list.replaceChildren(el('p', {class: 'plugins-empty', text: 'Loading plugins…'}));
        loadPackages(ctx, null);
        loadCatalog(ctx);
    }

    $('[data-plugin-refresh]').addEventListener('click', () => loadPackages(context, null));
    contextControl.addEventListener('change', () => {
        const url = new URL(window.location.href); url.searchParams.set('installation_id', contextControl.value);
        history.replaceState(null, '', url);
        load(contextControl.value);
    });
    window.addEventListener('pagehide', () => { if (context) context.controller.abort(); window.clearTimeout(pollTimer); });
    load(contextControl.value);
})();

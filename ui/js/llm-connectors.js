// Shows only the LLM connector fields the selected mode uses, without clearing any saved value.
(() => {
    // Keep the explicit saved-connector test in Herika's reader without navigating or saving drafts.
    const testForm = document.querySelector('[data-llm-test-form]');
    const testDialog = document.getElementById('llm-test-dialog');
    if (testForm && testDialog) {
        const button = testForm.querySelector('button[type="submit"]');
        const result = testDialog.querySelector('[data-llm-test-result]');
        const loader = testDialog.querySelector('[data-llm-test-loading]');
        let pending = false;
        testDialog.querySelector('[data-llm-test-close]').addEventListener('click', () => testDialog.close());
        testDialog.addEventListener('click', event => {
            const rect = testDialog.getBoundingClientRect();
            if (event.target === testDialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) testDialog.close();
        });
        testDialog.addEventListener('close', () => { if (!button.disabled) button.focus(); });
        testForm.addEventListener('submit', async event => {
            event.preventDefault();
            if (pending) return;
            pending = true;
            button.disabled = true;
            result.className = '';
            result.textContent = 'Testing saved settings… Closing this dialog does not cancel the server request.';
            testDialog.querySelector('[data-llm-test-name]').textContent = testForm.dataset.connectorName;
            loader.hidden = false;
            testDialog.showModal();
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 130000);
            try {
                const response = await fetch(testForm.action, {method:'POST', body:new FormData(testForm),
                    headers:{Accept:'application/json'}, credentials:'same-origin', referrerPolicy:'same-origin', signal:controller.signal});
                const payload = await response.json();
                if (!response.ok || payload.ok !== true || typeof payload.message !== 'string') throw new Error('test_failed');
                result.textContent = payload.message;
                result.className = 'llm-test-ok';
            } catch {
                result.textContent = 'Test failed. Check the saved connector, API key and server logs. Your editor changes were not saved.';
                result.className = 'llm-test-error';
            } finally {
                clearTimeout(timeout);
                loader.hidden = true;
                pending = false;
                button.disabled = false;
            }
        });
    }
    // Herika's sidebar Import opens a picker directly; the link remains a no-JavaScript paste fallback.
    const importOpener = document.querySelector('[data-llm-import-open]');
    const importPicker = document.getElementById('llm-import-picker');
    const importForm = document.getElementById('llm-quick-import');
    const importStatus = document.getElementById('llm-import-status');
    if (importOpener && importPicker && importForm && importStatus) {
        let importing = false;
        importOpener.addEventListener('click', event => {
            event.preventDefault();
            if (!importing) importPicker.click();
        });
        importPicker.addEventListener('change', async () => {
            const files = Array.from(importPicker.files || []);
            if (importing || !files.length) return;
            importing = true;
            importOpener.setAttribute('aria-disabled', 'true');
            importStatus.hidden = false;
            importStatus.setAttribute('role', 'status');
            let completed = 0;
            let current = '';
            try {
                if (files.length > 20 || files.some(file => file.size > 1048576)) {
                    throw new Error('Choose up to 20 JSON files, at most 1 MiB each.');
                }
                // Read and parse the entire selection before any import, so a broken JSON file makes no partial batch.
                const documents = [];
                for (const file of files) {
                    current = file.name;
                    importStatus.textContent = 'Reading ' + current + '…';
                    const text = await file.text();
                    const document = JSON.parse(text);
                    if (!document || Array.isArray(document) || document.schema !== 'lorkhan.provider-export.v1') {
                        throw new Error('Choose portable LORKHAN connector exports.');
                    }
                    documents.push(text);
                }
                for (let index = 0; index < documents.length; index++) {
                    current = files[index].name;
                    importStatus.textContent = 'Importing ' + (index + 1) + ' of ' + files.length + ': ' + current;
                    const body = new FormData(importForm);
                    body.set('provider_json', documents[index]);
                    // No automatic retry: a lost response may follow an already committed import.
                    const controller = new AbortController();
                    const timer = setTimeout(() => controller.abort(), 30000);
                    let response;
                    try {
                        response = await fetch(importForm.action, {method:'POST', body, credentials:'same-origin', referrerPolicy:'same-origin', signal:controller.signal});
                    } finally { clearTimeout(timer); }
                    if (!response.ok) throw new Error('The server rejected this connector (HTTP ' + response.status + '). Check its format and settings.');
                    const receipt = new URL(response.url);
                    if (!response.redirected || receipt.origin !== window.location.origin
                        || !receipt.pathname.endsWith('/ui/core/llm_connectors.php') || receipt.searchParams.get('status') !== 'saved') {
                        throw new Error('The server did not confirm the import. Reload to check the connector list before retrying.');
                    }
                    completed++;
                }
                const destination = new URL(importOpener.href);
                destination.searchParams.delete('import');
                destination.searchParams.set('imported', String(completed));
                window.location.assign(destination.href);
            } catch (error) {
                const detail = error instanceof SyntaxError ? 'Invalid JSON; no files were imported.'
                    : error instanceof TypeError || error.name === 'AbortError' ? 'The file could not be read or the server response was lost. Reload before retrying.'
                    : error.message || 'Import failed.';
                importStatus.textContent = (current ? current + ': ' : '') + detail
                    + (completed ? ' ' + completed + ' confirmed imported; remaining files were not attempted.' : '');
                importStatus.setAttribute('role', 'alert');
            } finally {
                importing = false;
                importPicker.value = '';
                importOpener.removeAttribute('aria-disabled');
            }
        });
    }
    const driver = document.getElementById('llm_driver');
    if (!(driver instanceof HTMLSelectElement)) return;

    // Keep hover/focus help dismissible without changing the field or losing keyboard focus.
    document.querySelectorAll('.llm-connection-field, .llm-option-field').forEach((field) => {
        field.addEventListener('input', () => {
            if (field.matches(':focus-within')) field.classList.add('llm-help-dismissed');
        });
        field.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') field.classList.add('llm-help-dismissed');
        });
        field.addEventListener('focusout', () => field.classList.remove('llm-help-dismissed'));
        field.addEventListener('mouseleave', () => {
            if (!field.matches(':focus-within')) field.classList.remove('llm-help-dismissed');
        });
    });

    const panels = Array.from(document.querySelectorAll('[data-llm-modes]'));
    if (panels.length === 0) return;
    const timeout = document.getElementById('llm_timeout_ms');
    const yamlText = document.getElementById('llm_body_yaml');
    const yamlContainer = document.getElementById('llm_body_editor');
    yamlText?.addEventListener('input', () => {
        const present = document.getElementById('llm_body_present');
        if (present) present.value = '1';
    });
    let yamlEditor = null;
    if (yamlText && yamlContainer && window.ace) {
        // Keep the native textarea as the submitted value and no-JavaScript fallback.
        window.ace.config.set('useStrictCSP', true);
        yamlContainer.hidden = false;
        yamlEditor = window.ace.edit(yamlContainer);
        yamlEditor.session.setUseWorker(false);
        yamlEditor.setTheme('ace/theme/ambiance');
        yamlEditor.session.setMode('ace/mode/yaml');
        yamlEditor.setOptions({cursorStyle:'ace', tabSize:2, useSoftTabs:true});
        yamlEditor.setValue(yamlText.value, -1);
        yamlEditor.textInput.getElement().setAttribute('aria-label', 'Include Body Parameters (YAML)');
        yamlEditor.textInput.getElement().setAttribute('aria-describedby', 'llm_body_help');
        yamlEditor.textInput.getElement().setAttribute('aria-description', 'Press Escape to leave the code editor.');
        yamlEditor.commands.addCommand({name:'leaveBodyParameters', bindKey:{win:'Esc',mac:'Esc'}, readOnly:true,
            exec:() => document.querySelector('[data-llm-clear-advanced]')?.focus()});
        yamlText.hidden = true;
        document.querySelector('label[for="llm_body_yaml"]')?.addEventListener('click', event => {
            event.preventDefault(); yamlEditor.focus();
        });
        yamlEditor.session.on('change', () => {
            yamlText.value = yamlEditor.getValue();
            yamlText.dispatchEvent(new Event('input', {bubbles:true}));
        });
    }
    const clearAdvanced = document.querySelector('[data-llm-clear-advanced]');
    if (clearAdvanced) {
        clearAdvanced.hidden = false;
        clearAdvanced.addEventListener('click', () => {
            clearAdvanced.closest('.llm-advanced-panel').querySelectorAll('input[type="number"]').forEach(number => {
                number.value = '';
                number.dispatchEvent(new Event('input', {bubbles:true}));
                number.dispatchEvent(new Event('change', {bubbles:true}));
                const slider = document.querySelector('[data-range-for="' + number.id + '"]');
                if (slider) slider.value = slider.dataset.emptyPosition ?? slider.min ?? '0';
            });
        });
    }

    const modesOf = (panel) => (panel.dataset.llmModes || '').split(' ').filter(Boolean);

    // Enhance native three-state selects without materializing inherited values on Save.
    const switches = Array.from(document.querySelectorAll('.llm-boolean-field select')).map(select => {
        const field = select.closest('.llm-boolean-field');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = select.id;
        checkbox.setAttribute('aria-describedby', select.getAttribute('aria-describedby'));
        select.id += '-stored';
        select.hidden = true;
        select.tabIndex = -1;
        const label = field.querySelector('label');
        label.classList.add('label-with-toggle');
        label.append(document.createTextNode(' '), checkbox);
        field.classList.add('llm-switch-field');
        const update = () => {
            const defaultValue = driver.value === 'configured' ? select.dataset.runtimeDefault : select.dataset.directDefault;
            const value = (select.value || defaultValue) === 'true';
            checkbox.checked = select.dataset.inverted === 'true' ? !value : value;
            checkbox.disabled = select.disabled;
            checkbox.title = select.value === '' ? 'Uses the default. Connection options can reset overrides.' : 'Connector override. Connection options can restore the default.';
        };
        checkbox.addEventListener('change', () => {
            // A completed toggle should not leave its help covering the next switch.
            field.classList.add('llm-help-dismissed');
            const value = select.dataset.inverted === 'true' ? !checkbox.checked : checkbox.checked;
            select.value = String(value);
            select.dispatchEvent(new Event('change', {bubbles: true}));
        });
        select.addEventListener('change', update);
        return {select, update};
    });
    const resetSwitches = document.querySelector('[data-llm-reset-switches]');
    if (resetSwitches) {
        resetSwitches.hidden = false;
        resetSwitches.addEventListener('click', () => switches.forEach(({select}) => {
            select.value = '';
            select.dispatchEvent(new Event('change', {bubbles: true}));
        }));
    }

    const apply = () => {
        const mode = driver.value;
        panels.forEach((panel) => {
            const active = modesOf(panel).includes(mode);
            panel.hidden = !active;
            panel.querySelectorAll('input, select, textarea').forEach((control) => {
                // Inactive controls are disabled so they neither submit nor trip native validation.
                // Values are never rewritten, so switching modes back restores what was saved.
                control.disabled = !active;
            });
        });
        if (timeout) {
            timeout.placeholder = mode === 'configured' ? 'Inherit runtime timeout' : '30000';
        }
        const keySelect = document.getElementById('llm_credential');
        const inheritedKey = keySelect?.querySelector('option[value="__inherit__"]');
        if (inheritedKey) {
            inheritedKey.hidden = inheritedKey.disabled = mode !== 'configured';
            if (mode === 'openai-compatible' && keySelect.value === '__inherit__') {
                keySelect.value = 'none';
                keySelect.dispatchEvent(new Event('change', {bubbles: true}));
            }
        }
        switches.forEach(({update}) => update());
        if (yamlEditor) {
            yamlEditor.setReadOnly(mode === 'mock');
            yamlEditor.resize();
        }
    };

    const services = {
        openrouter: ['https://openrouter.ai/api/v1/chat/completions', 'openrouter'],
        openai: ['https://api.openai.com/v1/chat/completions', 'openai'],
        google: ['https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', 'google'],
        groq: ['https://api.groq.com/openai/v1/chat/completions', 'groq'],
        nanogpt: ['https://nano-gpt.com/api/v1/chat/completions', 'nanogpt'],
        player2: ['http://127.0.0.1:4315/v1/chat/completions', 'none'],
        custom: ['', 'custom'],
    };
    // Show the current direct service without inventing an endpoint for inherited runtimes.
    const endpoint = document.getElementById('llm_endpoint');
    const serviceButtons = Array.from(document.querySelectorAll('[data-llm-service]'));
    const signupUrls = {
        openrouter: 'https://openrouter.ai/keys', openai: 'https://platform.openai.com/signup',
        google: 'https://ai.google.dev/', groq: 'https://console.groq.com/keys',
        nanogpt: 'https://nano-gpt.com/',
    };
    const updateService = () => {
        const service = driver.value === 'openai-compatible'
            ? (Object.keys(services).find((key) => key !== 'custom' && services[key][0] === endpoint?.value) || 'custom')
            : (driver.value === 'configured' ? document.getElementById('llm_model')?.dataset.runtimeService || '' : '');
        serviceButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.llmService === service)));
        const label = document.getElementById('llm-service-label');
        const active = serviceButtons.find((button) => button.dataset.llmService === service);
        if (label) label.textContent = 'Service: ' + (active?.title || (driver.value === 'mock' ? 'Deterministic mock' : 'Configured runtime'));
        const endpointRow = document.getElementById('llm_endpoint_row');
        if (endpointRow) endpointRow.hidden = driver.value !== 'openai-compatible' || service !== 'custom';
        const signup = document.getElementById('llm-service-signup');
        if (signup) {
            signup.hidden = !signupUrls[service];
            const link = signup.querySelector('a');
            if (signupUrls[service]) link.href = signupUrls[service];
            else link.removeAttribute('href');
        }
        const terms = document.getElementById('llm-service-terms');
        if (terms) terms.hidden = !['openrouter', 'openai', 'google'].includes(service);
        const custom = document.getElementById('llm-service-custom');
        if (custom) custom.hidden = service !== 'custom';
    };
    driver.addEventListener('change', () => { apply(); updateService(); });
    endpoint?.addEventListener('input', updateService);
    apply();
    updateService();
    const credentialSelect = document.getElementById('llm_credential');
    const credentialNotice = document.getElementById('llm_key_notice');
    if (credentialSelect && credentialNotice) {
        const updateCredentialNotice = () => {
            const selected = credentialSelect.selectedOptions[0];
            credentialNotice.textContent = !selected || credentialSelect.value === 'none'
                ? 'No API key selected. Some services require a key.'
                : (selected.dataset.empty === '1' ? 'Selected API key is empty. Add it on the API Keys page.' : '');
        };
        credentialSelect.addEventListener('change', updateCredentialNotice);
        updateCredentialNotice();
    }
    document.querySelectorAll('[data-llm-service]').forEach((button) => {
        button.addEventListener('click', () => {
            const preset = services[button.dataset.llmService];
            if (!preset) return;
            driver.value = 'openai-compatible';
            apply();
            const endpoint = document.getElementById('llm_endpoint');
            const credential = document.getElementById('llm_credential');
            if (endpoint) { endpoint.value = preset[0]; endpoint.dispatchEvent(new Event('input', {bubbles: true})); }
            if (credential) { credential.value = preset[1]; credential.dispatchEvent(new Event('change', {bubbles: true})); }
            updateService();
            document.querySelector('[name="model"]')?.focus();
        });
    });

    // Both Herika catalogue pickers share keyboard, sizing, caching and failure behavior.
    ['model', 'provider'].forEach(kind => {
        const modelInput = document.getElementById('llm_' + kind);
        if (!modelInput || !endpoint) return;
        const providers = kind === 'provider';
        const heading = () => providers ? 'OpenRouter Providers' : catalogueService() === 'groq' ? 'Groq Models' : 'OpenRouter Models';
        const dropdown = document.createElement('div');
        dropdown.id = 'llm-' + kind + '-catalogue';
        dropdown.className = 'orm-dropdown';
        dropdown.hidden = true;
        dropdown.setAttribute('role', 'listbox');
        dropdown.setAttribute('aria-label', providers ? 'OpenRouter Providers' : 'Models');
        document.body.append(dropdown);
        const info = document.createElement('div');
        info.className = 'orm-info-box';
        info.hidden = true;
        modelInput.after(info);
        let models = null, opened = false, active = -1, matches = [];
        const cache = new Map(), pending = new Map();

        function catalogueService() {
            let service = '';
            if (driver.value === 'configured') service = document.getElementById('llm_model')?.dataset.runtimeService || '';
            else if (driver.value === 'openai-compatible') {
                try {
                    const url = new URL(endpoint.value);
                    if (url.origin === 'https://openrouter.ai' && url.pathname.replace(/\/$/, '') === '/api/v1/chat/completions') service = 'openrouter';
                    if (url.origin === 'https://api.groq.com' && url.pathname.replace(/\/$/, '') === '/openai/v1/chat/completions') service = 'groq';
                } catch (_) { /* Custom endpoints have no automatic catalogue. */ }
            }
            return service === 'openrouter' || (!providers && service === 'groq') ? service : '';
        }
        function catalogueKey() {
            const service = catalogueService();
            return service + (service === 'groq' ? ':' + driver.value + ':' + (credentialSelect?.value || 'none') : '');
        }
        // Catalogue text is untrusted provider data, never markup or a navigation target.
        function line(parent, className, text) {
            const element = document.createElement('div');
            element.className = className;
            element.textContent = text;
            parent.append(element);
            return element;
        }
        function price(value) {
            const number = Number(value);
            return value === null || value === undefined || value === '' || !Number.isFinite(number) || number < 0
                ? 'N/A' : '$' + (number * 1000000).toFixed(4) + ' / 1M tokens';
        }
        function details(model) {
            if (providers) return [model.privacy_policy_url ? 'Privacy: ' + model.privacy_policy_url : '',
                model.terms_of_service_url ? 'TOS: ' + model.terms_of_service_url : ''].filter(Boolean).join(' • ');
            if (catalogueService() === 'groq') {
                const context = Number(model.context_window);
                return (model.owned_by || 'Groq') + (Number.isFinite(context) && context > 0 ? ' • context ' + context.toLocaleString('en-US') : '');
            }
            const context = Number(model.top_provider?.context_length || model.context_length);
            return 'Pricing (per 1M tokens): input ' + price(model.pricing?.prompt) + ' • output ' + price(model.pricing?.completion)
                + (Number.isFinite(context) && context > 0 ? ' • context ' + context.toLocaleString('en-US') : '');
        }
        function closeCatalogue() {
            opened = false;
            dropdown.hidden = true;
            active = -1;
            matches = [];
            modelInput.setAttribute('aria-expanded', 'false');
            modelInput.removeAttribute('aria-activedescendant');
        }
        function positionCatalogue() {
            if (!opened) return;
            const rect = modelInput.getBoundingClientRect();
            const viewportWidth = document.documentElement.clientWidth;
            const viewportHeight = document.documentElement.clientHeight;
            const minimumWidth = Math.min(Math.max(rect.width, 420), viewportWidth - 16);
            const height = Math.min(360, Math.max(80, viewportHeight - 16));
            dropdown.style.width = 'max-content';
            dropdown.style.minWidth = minimumWidth + 'px';
            dropdown.style.maxWidth = (viewportWidth - 16) + 'px';
            dropdown.style.maxHeight = height + 'px';
            const width = dropdown.getBoundingClientRect().width;
            dropdown.style.left = (window.scrollX + Math.max(8, Math.min(rect.left, viewportWidth - width - 8))) + 'px';
            const actualHeight = Math.min(dropdown.scrollHeight, height);
            const top = rect.bottom + 4 + actualHeight <= viewportHeight - 8 ? rect.bottom + 4 : Math.max(8, rect.top - actualHeight - 4);
            dropdown.style.top = (window.scrollY + top) + 'px';
        }
        function updateInfo() {
            const id = modelInput.value.trim();
            info.hidden = providers || catalogueService() !== 'openrouter' || !id || !models;
            info.replaceChildren();
            if (info.hidden) return;
            const model = models.find(item => item.id === id);
            if (model) {
                line(info, 'orm-info-title', 'OpenRouter model info');
                line(info, 'orm-muted orm-detail', details(model));
            } else line(info, 'orm-muted orm-detail', 'Model information not available');
        }
        function selectModel(model) {
            closeCatalogue();
            modelInput.value = model.id;
            modelInput.dispatchEvent(new Event('input', {bubbles: true}));
            modelInput.dispatchEvent(new Event('change', {bubbles: true}));
        }
        function renderModels() {
            if (!opened || !models) return;
            const query = modelInput.value.toLowerCase();
            matches = models.filter(model => model.id.toLowerCase().includes(query) || (catalogueService() !== 'groq' && model.name.toLowerCase().includes(query)));
            active = -1;
            modelInput.removeAttribute('aria-activedescendant');
            dropdown.replaceChildren();
            line(dropdown, 'orm-head', heading());
            line(dropdown, 'orm-note', providers ? 'Click to select. Value set to provider slug.'
                : catalogueService() === 'groq' ? 'Click to select a model.' : 'Click to select. Pricing shown per 1M tokens.');
            if (!matches.length) line(dropdown, 'orm-muted orm-empty', 'No matches');
            matches.forEach((model, index) => {
                const item = line(dropdown, 'orm-item', '');
                item.id = 'llm-' + kind + '-option-' + index;
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', 'false');
                item.title = model.description || model.name || model.id;
                line(item, '', model.id + (catalogueService() !== 'groq' && model.name ? ' — ' + model.name : ''));
                line(item, 'orm-muted orm-detail', details(model));
                item.addEventListener('click', () => selectModel(model));
            });
            positionCatalogue();
        }
        // Key Groq caches by the selected reference, not a secret; ignore responses for an abandoned selection.
        async function loadModels(key, service) {
            if (cache.has(key)) return cache.get(key);
            if (!pending.has(key)) pending.set(key, (async () => {
                const credential = credentialSelect?.value === '__inherit__' && driver.value === 'configured' ? '' : credentialSelect?.value || 'none';
                if (service === 'groq' && credential === 'none') throw new Error('groq_api_key_required');
                const controller = new AbortController();
                const timer = setTimeout(() => controller.abort(), 10000);
                try {
                    const options = {credentials:'same-origin', referrerPolicy:'no-referrer', signal:controller.signal};
                    if (service === 'groq') Object.assign(options, {
                        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':modelInput.form?.querySelector('[name="_csrf"]')?.value || ''},
                        body:JSON.stringify({driver:driver.value, credential}),
                    });
                    const response = await fetch(service === 'groq' ? modelInput.dataset.groqCatalogue
                        : providers ? modelInput.dataset.providerCatalogue : modelInput.dataset.modelCatalogue, options);
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.error === 'groq_api_key_required' ? 'groq_api_key_required' : 'Catalogue unavailable');
                    if (!Array.isArray(payload.data) || payload.data.length > 5000) throw new Error('Invalid catalogue');
                    const result = payload.data.filter(model => model && typeof model === 'object')
                        .map(model => providers ? {...model, id: model.slug} : model)
                        .filter(model => typeof model.id === 'string' && model.id.length > 0 && model.id.length <= (providers ? 128 : 256))
                        .map(model => ({...model, name: String(model.name || '').slice(0, 512), description: String(model.description || '').slice(0, 4000)}))
                        .sort((a, b) => providers || service === 'groq' ? a.id.localeCompare(b.id) : a.name.localeCompare(b.name) || a.id.localeCompare(b.id));
                    cache.set(key, result);
                    return result;
                } finally { clearTimeout(timer); }
            })());
            try { return await pending.get(key); } finally { pending.delete(key); }
        }
        async function openCatalogue() {
            const service = catalogueService(), key = catalogueKey();
            if (!service) return;
            opened = true;
            matches = [];
            dropdown.hidden = false;
            dropdown.setAttribute('aria-label', heading());
            modelInput.setAttribute('aria-expanded', 'true');
            dropdown.replaceChildren();
            line(dropdown, 'orm-head', heading());
            line(dropdown, 'orm-note', 'Loading…');
            positionCatalogue();
            try {
                const result = await loadModels(key, service);
                if (!opened || key !== catalogueKey()) return;
                models = result;
                renderModels();
                updateInfo();
            } catch (error) {
                if (!opened || key !== catalogueKey()) return;
                dropdown.replaceChildren();
                line(dropdown, 'orm-head', heading());
                line(dropdown, 'orm-err', service === 'groq'
                    ? error.message === 'groq_api_key_required' ? 'Please select a configured API Key first.' : 'Failed to load Groq models. Check the API key and connection. You can still enter a model ID.'
                    : providers ? 'Failed to load providers. You can still enter a provider slug.' : 'Failed to load models. Check network/CORS. You can still enter a model ID.');
                positionCatalogue();
            }
        }
        function updateCatalogueAvailability() {
            closeCatalogue();
            models = cache.get(catalogueKey()) || null;
            updateInfo();
            const available = catalogueService() !== '';
            if (providers) {
                // Custom compatible gateways may accept explicit provider hints; standard services do not.
                const custom = driver.value === 'openai-compatible' && !Object.values(services).some(preset => preset[0] && preset[0] === endpoint.value);
                const visible = available || custom;
                document.getElementById('llm_provider_row').hidden = !visible;
                modelInput.disabled = !visible;
            }
            const attributes = {role: 'combobox', 'aria-autocomplete': 'list', 'aria-controls': dropdown.id, 'aria-expanded': 'false'};
            Object.entries(attributes).forEach(([name, value]) => {
                if (available) modelInput.setAttribute(name, value);
                else modelInput.removeAttribute(name);
            });
        }
        updateCatalogueAvailability();
        modelInput.autocomplete = 'off';
        modelInput.addEventListener('focus', openCatalogue);
        modelInput.addEventListener('click', () => { if (!opened) openCatalogue(); });
        modelInput.addEventListener('input', () => { renderModels(); updateInfo(); });
        modelInput.addEventListener('change', updateInfo);
        modelInput.addEventListener('blur', closeCatalogue);
        dropdown.addEventListener('mousedown', event => event.preventDefault());
        modelInput.addEventListener('keydown', async event => {
            if (event.key === 'Escape') { event.preventDefault(); closeCatalogue(); return; }
            if (event.key === 'Enter' && opened && active >= 0) { event.preventDefault(); selectModel(matches[active]); return; }
            if (!['ArrowDown', 'ArrowUp'].includes(event.key) || !catalogueService()) return;
            event.preventDefault();
            if (!opened) await openCatalogue();
            if (!opened || !matches.length) return;
            if (active < 0) active = event.key === 'ArrowDown' ? 0 : matches.length - 1;
            else active = (active + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length;
            dropdown.querySelectorAll('[role="option"]').forEach((item, index) => {
                item.setAttribute('aria-selected', String(index === active));
                if (index === active) { modelInput.setAttribute('aria-activedescendant', item.id); item.scrollIntoView({block: 'nearest'}); }
            });
        });
        [driver, endpoint].forEach(control => {
            control.addEventListener('change', updateCatalogueAvailability);
            control.addEventListener('input', updateCatalogueAvailability);
        });
        credentialSelect?.addEventListener('change', updateCatalogueAvailability);
        window.addEventListener('resize', positionCatalogue);
        window.addEventListener('scroll', positionCatalogue, true);
    });

    // The server rejects both token limits at once, so say so before the round trip rather than after.
    const maxTokens = document.getElementById('llm_option_max_tokens');
    const maxCompletionTokens = document.getElementById('llm_option_max_completion_tokens');
    if (maxTokens && maxCompletionTokens) {
        const limits = [maxTokens, maxCompletionTokens];
        const checkLimits = () => {
            const clash = maxTokens.value !== '' && maxCompletionTokens.value !== '';
            limits.forEach((limit) => {
                limit.setCustomValidity(clash ? 'Set Max tokens or Max completion tokens, not both.' : '');
                limit.setAttribute('aria-invalid', clash ? 'true' : 'false');
            });
        };
        limits.forEach((limit) => limit.addEventListener('input', checkLimits));
        driver.addEventListener('change', checkLimits);
        checkLimits();
    }
})();

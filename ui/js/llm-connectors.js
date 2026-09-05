// Shows only the LLM connector fields the selected mode uses, without clearing any saved value.
(() => {
    const driver = document.getElementById('llm_driver');
    if (!(driver instanceof HTMLSelectElement)) return;

    // Keep hover/focus help dismissible without changing the field or losing keyboard focus.
    document.querySelectorAll('.llm-connection-field, .llm-option-field').forEach((field) => {
        field.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') field.classList.add('llm-help-dismissed');
        });
        field.addEventListener('focusout', () => field.classList.remove('llm-help-dismissed'));
        field.addEventListener('mouseleave', () => field.classList.remove('llm-help-dismissed'));
    });

    const panels = Array.from(document.querySelectorAll('[data-llm-modes]'));
    if (panels.length === 0) return;
    const timeout = document.getElementById('llm_timeout_ms');

    const modesOf = (panel) => (panel.dataset.llmModes || '').split(' ').filter(Boolean);

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
    const updateService = () => {
        const service = driver.value === 'openai-compatible'
            ? (Object.keys(services).find((key) => key !== 'custom' && services[key][0] === endpoint?.value) || 'custom') : '';
        serviceButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.llmService === service)));
        const label = document.getElementById('llm-service-label');
        const active = serviceButtons.find((button) => button.dataset.llmService === service);
        if (label) label.textContent = 'Service: ' + (active?.title || (driver.value === 'mock' ? 'Deterministic mock' : 'Configured runtime'));
    };
    driver.addEventListener('change', () => { apply(); updateService(); });
    endpoint?.addEventListener('input', updateService);
    apply();
    updateService();
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

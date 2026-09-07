(() => {
    'use strict';
    const form = document.getElementById('player-speech-ai-form');
    const button = document.querySelector('[data-player-generate]');
    const field = document.getElementById('player-speech-style');
    const status = document.getElementById('player-generation-status');
    if (!form || !button || !field || !status) return;
    button.disabled = false;
    let busy = false;
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        busy = true;
        button.disabled = true;
        const originalLabel = button.textContent;
        const originalStyle = field.value;
        button.textContent = 'Generating…';
        status.textContent = 'Generating speech style from your recent dialogue…';
        // Only this explicit submission queues work; polling never starts another job.
        const request = async body => {
            const response = await fetch(form.action, {method:'POST', credentials:'same-origin',
                headers:{Accept:'application/json'}, body, signal:AbortSignal.timeout(15000)});
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Generation request failed');
            return result;
        };
        try {
            const result = await request(new URLSearchParams(new FormData(form)));
            const poll = new URLSearchParams({_csrf:form.elements.namedItem('_csrf').value,
                installation_id:form.elements.namedItem('installation_id').value,
                profile_id:form.elements.namedItem('profile_id').value, operation:'status', job_id:result.job_id});
            for (let attempt=0; attempt<300; attempt++) {
                const draft = await request(poll);
                if (draft.state === 'stale') throw new Error('The saved player profile changed. Reload before generating again');
                if (draft.state === 'dead') throw new Error('Generation failed. Check provider logs');
                if (draft.state === 'succeeded') {
                    if (typeof draft.speech_style !== 'string' || !draft.speech_style.trim()) throw new Error('No speech style was generated');
                    if (field.value !== originalStyle) throw new Error('Speech style was edited while generating. Your edits were kept');
                    field.value = draft.speech_style;
                    field.dispatchEvent(new Event('input', {bubbles:true}));
                    status.textContent = 'Speech style generated. Save Player Settings to keep it.';
                    return;
                }
                await new Promise(resolve => setTimeout(resolve, 2000));
            }
            throw new Error('Generation is still pending. Try again to check the same request');
        } catch (error) {
            status.textContent = `Speech style was not changed: ${error.message.replaceAll('_',' ')}.`;
        } finally {
            busy = false;
            button.disabled = false;
            button.textContent = originalLabel;
        }
    });
})();

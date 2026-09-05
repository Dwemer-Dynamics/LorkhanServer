/* Bulk maintenance is explicitly confirmed and always scoped to the displayed playthrough. */
(() => {
    document.querySelectorAll('.memory-log-table .danger-form').forEach(form => form.addEventListener('submit', event => {
        if (!window.confirm('Delete this memory summary? Source events and revision history are retained.')) event.preventDefault();
    }));
    document.querySelectorAll('[data-memory-edit-cancel]').forEach(button => button.addEventListener('click', () => {
        const editor=button.closest('.memory-edit');
        button.form.reset();
        editor.open=false;
        editor.querySelector('summary').focus();
    }));
    document.querySelectorAll('[data-roleplay-clear]').forEach(button => button.addEventListener('click', async () => {
        const diaries = button.dataset.roleplayClear === 'diaries';
        const memories = button.dataset.roleplayClear === 'memories';
        const question = memories
            ? 'Delete all memory summaries in the selected playthrough, including summaries outside the visible table? This removes them from future AI context. Recent source memories, original events and revision history are retained. Type Delete to confirm.'
            : diaries
            ? 'Delete all diary entries in this playthrough? This includes entries outside the current date/person filter. Original source events are preserved.'
            : 'Clear all completed AI response log rows in this playthrough? This includes rows outside the current filter. Conversation history, prompt traces and active responses are preserved.';
        if (memories ? window.prompt(question) !== 'Delete' : !window.confirm(question)) return;
        button.disabled = true;
        const status = button.closest('.tab-content').querySelector('[data-roleplay-maintenance-status]');
        status.textContent = 'Updating log…';
        try {
            const response = await fetch(button.dataset.endpoint, {
                method:'POST', credentials:'same-origin',
                headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-Token':button.dataset.csrf},
                body:JSON.stringify({installation_id:button.dataset.installation,playthrough_id:button.dataset.playthrough,kind:button.dataset.roleplayClear,confirm:'Clear'}),
            });
            if (!response.ok) throw new Error('The log could not be cleared. Reload the page and try again.');
            const result = await response.json();
            if (!Number.isInteger(result.cleared)) throw new Error('Unexpected server response. Reload the log to check its state.');
            window.location.reload();
        } catch (error) {
            status.textContent = error.message;
            button.disabled = false;
        }
    }));
    document.querySelectorAll('[data-memory-sync]').forEach(button => button.addEventListener('click', async () => {
        if (!window.confirm('Sync missing memory summaries for every eligible NPC in the selected playthrough? This queues background jobs and may use paid model tokens. Existing summaries and source memories are preserved. Failed jobs remain available in Jobs for review.')) return;
        button.disabled = true;
        const status = button.closest('.tab-content').querySelector('[data-roleplay-maintenance-status]');
        let queued = 0;
        try {
            let more = true;
            while (more) {
                status.textContent = `Queueing memory summaries… ${queued} queued.`;
                const response = await fetch(button.dataset.endpoint, {
                    method:'POST', credentials:'same-origin',
                    headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-Token':button.dataset.csrf},
                    body:JSON.stringify({installation_id:button.dataset.installation,playthrough_id:button.dataset.playthrough,confirm:'Sync'}),
                });
                if (!response.ok) throw new Error('Could not queue summaries. Check the summary policy and connector in Global Settings.');
                const result = await response.json();
                if (!Number.isInteger(result.queued) || typeof result.has_more !== 'boolean') throw new Error('Unexpected sync response. Check Jobs before retrying.');
                queued += result.queued;
                more = result.has_more && result.queued > 0;
            }
            status.textContent = `${queued} missing summaries queued. Processing continues in the background; see Jobs for progress.`;
        } catch (error) {
            status.textContent = `${queued} summaries queued. ${error.message}`;
        } finally {
            button.disabled = false;
        }
    }));
})();

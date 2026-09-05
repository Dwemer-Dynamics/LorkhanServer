/* Bulk maintenance is explicitly confirmed and always scoped to the displayed playthrough. */
(() => {
    document.querySelectorAll('[data-roleplay-clear]').forEach(button => button.addEventListener('click', async () => {
        const diaries = button.dataset.roleplayClear === 'diaries';
        const question = diaries
            ? 'Delete all diary entries in this playthrough? This includes entries outside the current date/person filter. Original source events are preserved.'
            : 'Clear all completed AI response log rows in this playthrough? This includes rows outside the current filter. Conversation history, prompt traces and active responses are preserved.';
        if (!window.confirm(question)) return;
        button.disabled = true;
        const status = document.querySelector('[data-roleplay-maintenance-status]');
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
})();

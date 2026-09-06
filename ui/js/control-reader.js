/* Operational audio is user-started and only one cached clip plays at a time. */
(() => {
    const root = document.querySelector('[data-audio-cache]');
    if (!root) return;
    const players = [...root.querySelectorAll('audio')];
    players.forEach((player) => {
        player.addEventListener('play', () => players.forEach((other) => { if (other !== player) other.pause(); }));
        player.addEventListener('error', () => {
            const status = player.parentElement.querySelector('[data-cache-status]');
            if (status) status.textContent = 'This clip is unavailable or expired. Refresh to check its current status.';
        });
    });
    const stop = () => players.forEach((player) => player.pause());
    window.addEventListener('pagehide', stop);
    document.addEventListener('visibilitychange', () => { if (document.hidden) stop(); });
})();

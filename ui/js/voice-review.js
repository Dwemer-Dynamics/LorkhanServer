'use strict';
// Comparing candidates should never leave two voices speaking at once.
document.addEventListener('play', event => {
    if (!(event.target instanceof HTMLAudioElement)) return;
    document.querySelectorAll('.voice-review audio').forEach(audio => {
        if (audio !== event.target) audio.pause();
    });
}, true);

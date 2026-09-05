"use strict";
const snapshotFile = document.getElementById("playthrough-snapshot-file");
const snapshotText = document.getElementById("playthrough-snapshot-json");
const snapshotStatus = document.getElementById("playthrough-file-status");
if (snapshotFile && snapshotText) {
    snapshotFile.addEventListener("change", async () => {
        const file = snapshotFile.files[0];
        if (!file) return;
        if (file.size > 2097152) { snapshotStatus.textContent = "Snapshot exceeds the 2 MiB import limit."; snapshotFile.value = ""; return; }
        try {
            const text = await file.text(); JSON.parse(text); snapshotText.value = text;
            snapshotStatus.textContent = file.name + " ready to import.";
        } catch (_) { snapshotText.value = ""; snapshotStatus.textContent = "Choose a valid JSON snapshot."; }
    });
    snapshotFile.form.addEventListener("submit", event => {
        if (!snapshotText.value.trim()) { event.preventDefault(); snapshotStatus.textContent = "Choose a snapshot file or paste its JSON first."; snapshotFile.focus(); }
    });
}

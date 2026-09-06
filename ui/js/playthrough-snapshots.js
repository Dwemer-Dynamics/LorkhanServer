"use strict";
const snapshotFile = document.getElementById("playthrough-snapshot-file");
const snapshotText = document.getElementById("playthrough-snapshot-json");
const snapshotStatus = document.getElementById("playthrough-file-status");
if (snapshotFile && snapshotText) {
    let selection = 0, reading = false;
    snapshotText.addEventListener("input", () => { if (!reading) snapshotStatus.textContent = ""; });
    snapshotFile.addEventListener("change", async () => {
        const currentSelection = ++selection;
        const file = snapshotFile.files[0];
        snapshotText.value = ""; snapshotStatus.textContent = ""; reading = false;
        if (!file) return;
        if (file.size > 2097152) { snapshotStatus.textContent = "Snapshot exceeds the 2 MiB import limit."; snapshotFile.value = ""; return; }
        reading = true; snapshotStatus.textContent = "Reading snapshot…";
        try {
            const text = await file.text();
            if (currentSelection !== selection) return;
            JSON.parse(text); snapshotText.value = text;
            snapshotStatus.textContent = file.name + " ready to import.";
        } catch (_) {
            if (currentSelection !== selection) return;
            snapshotText.value = ""; snapshotStatus.textContent = "Choose a valid JSON snapshot.";
        } finally { if (currentSelection === selection) reading = false; }
    });
    snapshotFile.form.addEventListener("submit", event => {
        if (reading) { event.preventDefault(); snapshotStatus.textContent = "Wait for the selected snapshot to finish reading."; return; }
        if (!snapshotText.value.trim()) { event.preventDefault(); snapshotStatus.textContent = "Choose a snapshot file or paste its JSON first."; snapshotFile.focus(); }
    });
}

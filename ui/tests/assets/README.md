# STT test sample

`stt-test.wav` is original synthetic speech generated offline with Windows
System.Speech on 2026-09-05. It contains no game audio, microphone recording,
provider credentials or user dialogue.

Text: Ash drifts across the quiet road. A traveler stops at the inn, warms by the
fire, and asks the keeper for a room until morning.

Format: PCM WAV, mono, 22,050 Hz, 16-bit, approximately 9.33 seconds.
SHA-256: `215045ef4637a7481bbf4c8800231a1308d72f81afddf5df678ed5c64ffa91da`.

The server verifies this checksum before sending the fixed sample to STT.
Do not replace it with game dialogue or a user's recording. Update
`SttTestSample` and this provenance together if the original sample changes.

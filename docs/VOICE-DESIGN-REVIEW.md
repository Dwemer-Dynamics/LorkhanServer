# Character voice review

Open `/ui/voice_review.php` to compare two original Inworld designs for each character in
`data/voices/morrowind-design-review.json`. This is a review page, not automatic voice assignment.
Approve A/B, reject both, or clear a choice. Decisions persist server-side. Approval does not
publish the provider draft or change any NPC profile. Publishing selected drafts and assigning
their permanent IDs is a separate, explicitly authorized step.

## Generate

Run as the web runtime account with the installed server configuration:

```sh
sudo -u www-data env LORKHAN_CONFIG=/etc/lorkhanserver/server.php \
  php scripts/generate-voice-review.php
```

The optional first argument limits newly generated characters. Generation uses the current
installation's selected Inworld connector credential. It makes paid provider calls; run only
with authorization. It checkpoints each completed character and skips those already saved.
Requests are sequential and spaced; HTTP 429 receives at most two retries. Other failures stop
without retrying potentially accepted generation requests. A process lock prevents duplicate runs.

Use `--refresh` to replace candidates only when their source voice direction or preview text
changed. The previous manifest and decisions are archived first; existing WAVs are retained.
Removed characters are retired from the review. Unchanged candidates and approvals are preserved.
An approval of a replaced candidate is displayed as pending until the new pair is reviewed.

The manifest, opaque WAV previews and decisions are kept under
`<voice_storage_path>/design-review`, outside the web root and Git. Use web-account ownership.
Do not commit generated voices, credentials or account-specific provider IDs. Audio is served
through the management page using allowlisted IDs; decisions require its CSRF token.

## Voice direction

These are new character-inspired voices, not clones or actor impersonations. The seven ash-vampire
directions are creative interpretations, not claims of unique canonical recorded voices. Alternate
forms share one character review. Dagoth Ur is excluded: both forms use his existing voice sample.

References: [Inworld Voice Design](https://docs.inworld.ai/tts/voice-design) and
[Design API](https://docs.inworld.ai/api-reference/voiceAPI/voiceservice/design-voice).

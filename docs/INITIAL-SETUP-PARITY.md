# Initial setup and Quickstart defaults

Reference: Dwemer-Dynamics/HerikaServer `unstable` at `5a97fe2fc4b5be12f257193b59f118f23a6b4173`.

## Defaults and ownership

| Area | LORKHAN default / CHIM mapping |
| --- | --- |
| Playthrough initialization | Explicit CSRF-protected Setup POST; GET creates no save; existing save collection is preserved; default cannot be deleted |
| Automatic recovery | Enabled; three game days; unchanged by Quickstart |
| Standard / Fast / Powerful / Experimental | DeepSeek V4 Flash / Gemini 2.5 Flash Lite / GLM 5.2 / DeepSeek V4 Pro |
| Dialogue connector options | 750 tokens; temperature 0.6 for DeepSeek and 1 for Gemini/GLM; JSON/schema enabled, no prefill; reasoning-model flag follows the CHIM seed |
| Profile generation / Director / Diary | Standard |
| Player autochat / Oghma fallback | Fast |
| Relationship management | Mistral Small 3.2 24B |
| Memory summaries / background memory | Experimental; summaries enabled, interval 10, minimum 5 events |
| Scene classifier | Gemma 3N E4B, 128 tokens, temperature 0.2 |
| Speech | PocketTTS on 8086; Parakeet on 8022; 30-second timeout. Existing selected service is preserved |
| Semantic recall | Enabled; local MiniMe on 8082 |
| Global relationships | Enabled, 50% update chance; seven-day worst-memory lifespan; never-clear off |
| Automatic profile backfill | On, 40 events; manual profile save auto-lock on |
| Oghma | On; 1 topic, 3 results, forced race/location on; extractor fallback off, 1500 ms |
| Narrator | Enabled; random/welcome/bored/book events off; existing profile switches preserved |
| Initial Default Profile | 75 dialogue / 100 diary / 50 dynamic-profile history; unlimited words; Rechat 4 rounds/100%, actions on; boredom chance 50; combat cooldown 30 |
| Quickstart Default profile | Same history limits; Rechat 2 rounds/50%, actions on; boredom chance 30; combat cooldown 30 |
| Quickstart Local LLM profile | 20/20/20 history, 60 words; Rechat 1 round/50%, actions off; boredom chance 30; RPG comments 0%, combat cooldown 100 |
| Default context | Magic events and transformation detection on; pickup threshold 500; timestamps/power awareness/duplicate grouping off |
| Local LLM context | Magic events/transformation/power awareness off; pickup threshold 1000; descriptions-only and duplicate grouping on; reduced context detail |
| Default background tasks | Profile generation, background memory, scene classifier and director enabled |
| Local LLM background tasks | Profile generation/background memory/scene classifier off; director remains on, matching CHIM's explicit exception |
| Dynamic profiles, middle-term memory, automatic/physical/latest diary, model randomizer | Off in Default and Local LLM; optional profile presets still control them |

The initial CHIM SQL profile and the explicitly applied Quickstart Default preset have different Rechat/boredom values. They are kept separate. Ordinary new Core profiles without a creation preset use the global history fallback (50), while the initial default and Quickstart Default use 75.

## Deliberate product differences

Keep Morrowind prompts, race voices, native character bindings and menu behavior. Do not reintroduce the user's excluded ITT, server plugins, background life, AI quest manager, Soulgaze, translation UI, or LLM language selection. Skyrim bleedout/reanimation/animation options have no identical OpenMW setting. CHIM's separate formatter connector has no corresponding LORKHAN connector role; typed output handling remains native. Parakeet is the requested local Quickstart default; CHIM's legacy fallback config still names Whisper/Deepgram.

This is server initial setup / Quickstart parity, not a claim that all client MCM features, schemas or rollback-derived data are identical. Dynamic Oghma rollback remains separately tracked in PLAYTHROUGH-SAVE-PARITY.md.

## Upgrade safety

No database migration rewrites existing profiles, saved routing, service selections or credentials. Provisioning fills missing defaults only. Saving Quickstart deliberately applies the chosen preset, while preserving service addresses/keys and explicit connector choices. Deploying code alone does not switch the user's current Inworld/Parakeet or LLM selections.

## Verification

1,602 server checks pass. Isolated runtime-role tests cover fresh model/task routes, memory defaults, initial-versus-Quickstart profiles, provisioning idempotency, protected setup and preservation of user choices (20 checks). Browser checks on a separate database verify GET leaves zero saves, explicit Setup creates one protected default, and Quickstart Default saves successfully. Broad integration passes the provisioning checks and stops at the previously known active_playthrough_required fixture; it is not reported as passing. No game was launched or live playthrough restored.

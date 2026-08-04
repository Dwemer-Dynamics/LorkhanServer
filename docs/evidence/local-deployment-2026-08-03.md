# Local WSL and browser acceptance - 2026-08-03

Server source commit: `40ebe0e59be36c13d1a8e115f4432c2fd4d9097c` on `codex/herika-ui-core-port`.
Client source commit: `b60908ed18044271fa615a233e120b15d288f464` on `codex/full-dialectic-parity`.

| Check | Result |
| --- | --- |
| PHP foundation suite | PASS - 71 checks |
| Disposable PostgreSQL integration | PASS - vertical slice, ordered migrations, durable jobs, dump and restore |
| Browser-like management HTTP workflow | PASS - cookie session, CSRF, navigation, invalid/valid persistence, and logout |
| Full local WSL deployment | PASS - active tree `/var/www/html/ALMSIVIserver`; persistent database, media, logs, and secrets preserved |
| Runtime health | PASS - `almsivi.health.v1`, Apache running, worker loop and PHP worker use the active tree |
| Desktop browser acceptance | PASS at the available 1280x720 viewport for Home, Config Hub, Roleplay Hub, Control Panel, NPCs, Profiles, Player, LLM, Global Settings, and NPC modal |
| Herika presentation parity | PASS for the inspected page families: shared header/nav geometry, hubs, controls, assets, compact card language, and disabled status badges |

The browser checks do not prove mobile/responsive viewports unavailable in the current browser tool.
No game launch, OpenMW interaction, live-provider acceptance, production systemd service, release
package, or public deployment is claimed. ITT, STT, Background Life, and model-triggering autonomy
are excluded from the active product scope; compatibility protocol code does not create a shipped
entry point for those capabilities.

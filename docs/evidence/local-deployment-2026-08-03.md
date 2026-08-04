# Local WSL and browser acceptance - 2026-08-03

Server presentation commit: `38a695e` on `codex/herika-ui-core-port`.
Client source commit: `b60908ed18044271fa615a233e120b15d288f464` on `codex/full-dialectic-parity`.

| Check | Result |
| --- | --- |
| PHP foundation suite | PASS - 71 checks |
| Disposable PostgreSQL integration | PASS - vertical slice, ordered migrations, durable jobs, dump and restore |
| Browser-like management HTTP workflow | PASS - cookie session, CSRF, navigation, invalid/valid persistence, and logout |
| Full local WSL deployment | PASS - active tree `/var/www/html/ALMSIVIserver`; persistent database, media, logs, and secrets preserved |
| Runtime health | PASS - `almsivi.health.v1`, Apache running, worker loop and PHP worker use the active tree |
| Browser visual acceptance | PASS at 1280x720 desktop and true 390x844 mobile emulation; Config, Roleplay, and Control have no document-level horizontal overflow, and embedded Config/Control pages match their iframe widths |
| Herika presentation parity | PASS for the inspected page families: shared header/nav geometry, hubs, controls, assets, compact card language, and disabled status badges |

No game launch, OpenMW interaction, live-provider acceptance, production systemd service, release
package, or public deployment is claimed. ITT, STT, Background Life, and model-triggering autonomy
are excluded from the active product scope; compatibility protocol code does not create a shipped
entry point for those capabilities.

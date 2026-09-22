# LORKHAN 0.1.0 release preparation

Target: first public beta, pending maintainer confirmation. Source publication is complete;
downloadable client/server distribution is not yet release-approved.

## Required release order

1. **DwemerDistro-Core:** register LorkhanServer in `ddistro_server` and startup handling.
   Use `https://github.com/Dwemer-Dynamics/LorkhanServer.git`, stable branch `lorkhan`,
   beta `dev`, development `unstable`, database `lorkhan`, HTTP port 8090.
   Reuse the server's bootstrap and staged deploy scripts. Keep its source checkout
   separate from the runtime payload; do not apply another server's schema or credentials.
   Install/update/repair must preserve `/etc/lorkhanserver` and `/var/lib/lorkhanserver`.
   Verify uninstall scope before exposing it. Do not test destructive paths on the active installation.
2. **Launcher:** add LORKHAN to the existing typed server manager, install/repair/update
   controls and channel choices. Port forwarding 7514 to 8090 and discovery already exist.
   Preserve other products and confirmed force-update behavior. Ship a launcher patch
   release only after server-manager compatibility and clean-install checks pass.
3. **Dashboard:** review and merge [PR 13](https://github.com/Dwemer-Dynamics/Dwemer-Dashboard/pull/13).
   It supplies home, logs, version checks and playthrough navigation. Check missing-server
   handling as well as installed-server routes. Merge requires explicit maintainer approval.
4. **Client:** package the matching OpenMW executable, dependencies, Lua data, licenses,
   corresponding source, manifests and checksums using the existing packaging policy.
   Resolve the client suite failures and establish a clean source rebuild. Never package
   the whole local modlist, game data, saves, cached voices or pairing credentials.
5. **Server:** run `scripts/package-runtime.sh` from a clean source checkout. Publish its
   runtime archive, `SHA256SUMS`, and `release-manifest.json` alongside the matching source
   revision. The runtime archive is not a standalone installer: distro bootstrap uses the
   matching source checkout and its `scripts/deploy-wsl.sh`.
6. **Installer / distro image:** ensure fresh installs receive the new core and launcher;
   remove developer state and generate fresh identities and pairing secrets per installation.
   Test in a disposable installation, never by exporting the active developer environment.
7. **Promotion:** after acceptance, promote `unstable -> dev -> lorkhan` through reviewed
   PRs and publish the chosen release channel. No automatic stable promotion.

## Acceptance checklist

- [ ] Fresh server install and restart; health, database bootstrap and worker are ready.
- [ ] Update/repair retain settings, keys, voices, profiles, memories and playthrough saves.
- [ ] Launcher discovery selects the dedicated LORKHAN endpoint and all channels resolve.
- [ ] Dashboard home, logs and playthrough pages work for present and absent installations.
- [ ] Client pairs without copied developer credentials; text and microphone dialogue work.
- [ ] TTS playback, interruption, targeting, Rechat and an NPC action work in game.
- [ ] Save/load and older-save behavior preserve the intended playthrough policy.
- [ ] Client binary has complete corresponding source, dependencies and license notices.
- [ ] Runtime archives exclude private data and include manifests and checksums.
- [ ] Anonymous downloads work; checksums match the published artifacts.

## Current evidence

- Public source repos and `unstable`, `dev`, `lorkhan` branches exist.
- Server: 1,631 checks; both repositories' 137 protocol manifest entries verified.
- Launcher main contains LORKHAN proxy/discovery, but its server-manager enum lacks LORKHAN.
- Core main `fd1f246` does not register LORKHAN.
- Dashboard PR 13 is open, draft and mergeable; local deployment is not upstream release proof.
- Client full Python suite is not green; native build/package and fresh-install acceptance
  must not be inferred from prior local gameplay.
- Server GitHub Actions remain disabled by prior request. Packaging is currently a local command.

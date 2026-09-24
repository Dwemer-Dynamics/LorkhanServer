# LorkhanServer

PHP/PostgreSQL backend for the **LORKHAN AI Framework for Morrowind**.
Works with the [LORKHAN OpenMW client](https://github.com/Dwemer-Dynamics/LORKHAN)
and follows the shared Dwemer Dynamics server layout.

## Features

- AI and speech connectors, streaming dialogue and NPC voice configuration.
- Profiles, prompts, actions, memories, relationships and world knowledge.
- Browser configuration, event history and diagnostics.
- Background processing and playthrough-aware persistence and backups.
- Reference-based actor identity for distinct NPC instances.

## Runtime topology

1. LORKHAN sends authenticated game events to `index.php`.
2. The server stores events and builds the NPC's context and prompt.
3. Connectors generate dialogue and speech.
4. Ordered replies return to the game; playback and action results are reported back.

## Requirements and setup

- PHP 8.2+ with the extensions listed in [composer.json](composer.json).
- Apache and PostgreSQL; see [WSL setup](docs/WSL-APACHE-SETUP.md).
- The matching [LORKHAN client](https://github.com/Dwemer-Dynamics/LORKHAN).

This is a **0.5.0 prototype**, not a public internet service. Keep credentials outside
the repository and preserve the local access controls.

The DwemerDistro local route is `http://127.0.0.1:7514/LorkhanServer/ui/home.php`.
See [runtime layout](docs/RUNTIME-LAYOUT.md) and [build instructions](docs/building.md).
Preserve existing settings and player data during updates.

## Key paths

| Path | Purpose |
| --- | --- |
| `ui/` | Browser pages and assets |
| `processor/`, `prompts/` | Dialogue processing and prompts |
| `connector/`, `tts/`, `stt/` | AI and speech providers |
| `lib/` | Shared logic and database access |
| `service/` | Background workers |
| `data/` | Schema and bundled catalogs |
| `conf/`, `deploy/` | Configuration template and runtime setup |

## Branches and pull requests

**`unstable` → `dev` → `lorkhan`**

| Branch | Purpose |
| --- | --- |
| `unstable` | Development; submit feature and fix PRs here. |
| `dev` | Beta testing after maintainer promotion. |
| `lorkhan` | Stable/default branch after release review. |

Discuss proposed work with maintainers before submitting a PR.
See [CONTRIBUTING.md](CONTRIBUTING.md) and the [PR template](.github/PULL_REQUEST_TEMPLATE.md).
Promotions are reviewed manually. All three branches initially contain identical code.

## License

Project code is licensed under the [GNU GPL v3.0](LICENSE).
Third-party components retain their original licenses and notices.
Bethesda game content is not covered by this license and is not distributed here.

See [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) for bundled components and attribution.

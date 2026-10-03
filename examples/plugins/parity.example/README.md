# parity.example (server half)

This is the server half of the `parity.example` 1.0.0 addon. The client half is in the LORKHAN repository
under `examples/plugin-parity`. Both halves use the same `server/lorkhan-plugin.json`, byte for byte
(SHA-256 `4a44b0af71ffdda44c3a7f68fd70d340b4a0e8fa142b16fdfd2d107ef62e20b0`). Do not edit that file here.

| Path | Purpose |
| --- | --- |
| `manifest.json` | Package metadata (schema 4). `name` and `version` must match the addon manifest. |
| `server/lorkhan-plugin.json` | Addon manifest: two actions (`mark_camp`, `wander_briefly`), one event (`camp_marked`), no prompt slots, disabled by default. |
| `server/plugin.php` | Event hook. Stores camp mood, ignoring immediate duplicates and older sequential deliveries. This small state setter does not provide transactional event history across concurrent workers. |

## Build a package

The repository holds source only. Build the `.dwpkg` outside Git:

1. Copy `manifest.json` and the `server/` folder into an empty folder.
2. Add `checksums.sha256` with one line per other file: `<sha256>  <path>`, for example
   `4a44b0af...  server/lorkhan-plugin.json` and the line for `server/plugin.php`.
3. Zip the folder contents (not the folder itself) and name the file `parity.example-1.0.0.dwpkg`.

Install it from Configuration -> Server Plugins. It installs disabled. Enable it there when you want the
server to accept its registration and events.

# Herika-style server file layout

## Implementation plan

1. Move physical files and update includes, autoloading, resource paths and test references.
2. Keep PHP namespaces, database tables, protocol schemas and endpoint URLs unchanged.
3. Use one bootstrap/update layout and an explicit deployed runtime manifest.
4. Deny internal files in Apache on every virtual host and retain external persistent storage.
5. Run the existing unit, management HTTP, protocol and disposable database suites; deploy,
   verify page/assets, health, worker and file hashes, and retain a rollback copy.

## Mapping

| Former path | Current path |
| --- | --- |
| public/ui/ | ui/ |
| public/index.php | index.php |
| src/ shared classes | lib/ |
| src/Application/ dialogue processing | processor/ |
| src/Application/ LLM, embedding and translation adapters | connector/ |
| src/Application/ speech adapters | tts/ and stt/ |
| src/Application/ prompt assembly and policies | prompts/ |
| workers/worker.php | service/worker-runner.php |
| src/Application/ worker and handlers | service/ |
| config/ | conf/ |
| resources/ | data/ |
| database/migrations/ | data/migrations/ |

`lib/Autoload.php` maps relocated feature classes explicitly while preserving namespaces.
Composer classmaps include the same feature directories. New feature classes must also be added
to the explicit runtime autoload map; Composer is optional and is not the runtime loader. Worker.php is the worker class;
worker-runner.php is the CLI entrypoint, avoiding a Windows case-insensitive filename collision.

Protocol schemas stay in protocol/. Selected agent and operational docs ship through the manifest.
Tests, historical docs and catalog-authoring tools stay in Git but are
not installed in the runtime. deploy/runtime-files.txt is the deployment file manifest.
Excluded Skyrim-only features, server extensions and ITT are not reintroduced for folder parity.

## Runtime invariants

- Stable path: /var/www/html/LorkhanServer; UI at /LorkhanServer/ui/home.php.
- Backend port 8090, Windows launcher route 7514; API remains /LorkhanServer/api/v1.
- Configuration/secrets: /etc/lorkhanserver; persisted data: /var/lib/lorkhanserver.
- Logs: /var/log/lorkhanserver. Do not relocate media or keys into the web root for parity.
- No migrations, connector selection changes, model requests or game startup are required.
- Source and deployment use the same feature layout; no duplicate legacy implementation tree.

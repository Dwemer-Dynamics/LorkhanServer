# Continuation notes

Checkpoint date: 2026-07-20.

The connected server implementation through head `6ee5ba048db0f6c78e87f16553f40bf91d1bbc75`
is preserved on `main`. Continue with the private `RANGROO/LORKHAN` repository's `main` branch in a
sibling directory. Protocol schemas, fixtures, manifests, and checksums are a shared compatibility
boundary and must remain byte-identical across the two repositories.

## Current proof boundary

There is no GitHub Actions workflow in this repository at this checkpoint. The macOS local evidence
recorded in `docs/evidence/local-run-2026-07-19.md` includes PHP behavior, management HTTP,
disposable PostgreSQL, migrations/jobs, protocol parity, and a real Beast-to-PHP/PostgreSQL flow.
It is not Windows, WSL, Apache, systemd, OpenMW, browser-manual, or in-game proof.

The evidence file contains old paths into an LORKHAN Claude worktree. Replace those paths with the
root sibling checkout when resuming because the client implementation is now on `LORKHAN/main`.

## Resume on another machine

After installing PHP 8.2+, Composer, and PostgreSQL, begin with:

```bash
composer lint
composer test
composer test-management-http
composer test-integration
LORKHAN_CLIENT_ROOT=../LORKHAN composer test-cross-repo
LORKHAN_CLIENT_ROOT=../LORKHAN composer test-beast-http
```

Use mock providers until a separate live-provider run is explicitly intended. Keep Apache and the
API loopback-only, keep PostgreSQL/provider secrets outside Git, and never store game data, saves,
raw audio, generated media, or runtime database state in the repository.

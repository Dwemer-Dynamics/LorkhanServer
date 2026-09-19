# Database migrations

`001_initial_schema` is the initial release baseline. It contains the final application
schema and required seed rows, without installations, credentials, conversations or
generated content. Large biography, description and Oghma catalogs are provisioned separately.

Add future changes as `002_description.up.sql` / `002_description.down.sql`, then `003`,
and so on. Never edit an applied migration after release. The runner owns transactions.
Migration numbers track database upgrades; they do not select or gate dialogue versions.

The 137 prototype migrations were flattened from a clean database at source commit
`9d244192abf3b7496369ca5c30d4a119c7d9ab0c`. Their history remains in Git. Baseline schema
and seed rows were compared with that database, including a dump/restore round trip.

## Prototype installations and backups

Do not run the new baseline over an existing prototype schema or silently clear its ledger.
Back up first, verify its schema matches the baseline, and explicitly reconcile the ledger
under maintenance exclusion. The local development database was handled this way; no user
tables were rebuilt. Ordinary deployment never performs this reconciliation automatically.

Prototype SQL/database backups with the old migration ledger are intentionally rejected by
the new import path. Restore them with the matching pre-baseline code in an isolated database,
then verify and reconcile explicitly. Retain the corresponding old code with rollback backups.
New backups and factory resets use the new baseline fingerprint.

The baseline down migration is a destructive application reset. It removes only named
application objects, retaining schemas, extensions and the migration ledger. Use the existing
backup/confirmation workflow; it is not a data-preserving version switch.

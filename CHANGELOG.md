# Changelog

## Unreleased

- Keep generated migration table identifiers portable across WordPress prefixes without changing SQL literal values; validate the execution prefix.
- Expose explicit orm.allow_destructive_updates and orm.legacy_migration_keys service parameters; preserve the CLI --destructive gate independently of service defaults.
- Require Migration 1.0.8 for the optional bridge and use its typed operational exception for blocked schema changes and irreversible rollback.

## 0.3.2 — 2026-10-02

- Schema differences are computed lazily inside the migration lock. Real pinned ORM 0.2.0 data is adopted without losing rows or legacy history, then upgraded idempotently on MariaDB 11.8 and MySQL 8.4. Migration <1.0.4 remains incompatible.

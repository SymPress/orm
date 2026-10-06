# Changelog

## 0.3.4 — 2026-10-06

- Compare MySQL string defaults as exact literal values instead of serialized SQL. Unchanged string defaults and additive nullable columns no longer require destructive-update permission.
- Preserve case, whitespace, quotes and empty strings; real default changes still require explicit destructive intent.

## 0.3.3 — 2026-10-05

- Keep generated migration table identifiers portable across WordPress prefixes without changing SQL literal values; validate the execution prefix.
- Expose explicit orm.allow_destructive_updates and orm.legacy_migration_keys service parameters; preserve the CLI --destructive gate independently of service defaults.
- Require Migration 1.0.8 for the optional bridge and use its typed operational exception for blocked schema changes and irreversible rollback.

## 0.3.2 — 2026-10-02

- Schema differences are computed lazily inside the migration lock. Real pinned ORM 0.2.0 data is adopted without losing rows or legacy history, then upgraded idempotently on MariaDB 11.8 and MySQL 8.4. Migration <1.0.4 remains incompatible.

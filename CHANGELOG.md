# Changelog

## 0.3.6 — 2026-11-15

- Security: bind `DateTimeInterface` (`Y-m-d H:i:s`) and `BackedEnum` query parameters by their database value. `wpdb` turned them into `''`, so `t.expiresAt > :now` matched every row and enum filters matched nothing. Other non-entity objects now throw `InvalidArgumentException`.
- Bind `null` query parameters as SQL `NULL` instead of `''`; `= :value` with `null` matches no row, use `IS NULL`.
- Fail when `wpdb::prepare()` returns `''` because placeholders and arguments do not match, instead of silently running an empty statement.

## 0.3.5 — 2026-11-06

- Use the Symfony 8.1.11 security patches released on November 4.

## 0.3.4 — 2026-10-06

- Compare MySQL string defaults as exact literal values instead of serialized SQL. Unchanged string defaults and additive nullable columns no longer require destructive-update permission.
- Preserve case, whitespace, quotes and empty strings; real default changes still require explicit destructive intent.

## 0.3.3 — 2026-10-05

- Keep generated migration table identifiers portable across WordPress prefixes without changing SQL literal values; validate the execution prefix.
- Expose explicit orm.allow_destructive_updates and orm.legacy_migration_keys service parameters; preserve the CLI --destructive gate independently of service defaults.
- Require Migration 1.0.8 for the optional bridge and use its typed operational exception for blocked schema changes and irreversible rollback.

## 0.3.2 — 2026-10-02

- Schema differences are computed lazily inside the migration lock. Real pinned ORM 0.2.0 data is adopted without losing rows or legacy history, then upgraded idempotently on MariaDB 11.8 and MySQL 8.4. Migration <1.0.4 remains incompatible.

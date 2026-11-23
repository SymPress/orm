# Changelog

## 0.3.9 — 2026-11-23

- Accept a literal `%` in query builder and DQL expressions that also bind parameters. `wpdb::prepare()` needs `%%`, so `LIKE 'que%'` or a DQL function emitting `DATE_FORMAT(..., '%Y-%m')` broke once the query had a parameter. Write `%` as is; code that wrote `%%` together with parameters as a workaround now gets a literal `%%`.
- Bind parameters used in `select()` expressions. They were compiled into a throwaway list, which left placeholders without values and made `wpdb::prepare()` fail.
- Return no rows for `setMaxResults(0)`. It compiled to `LIMIT 1` and returned one row; it now compiles to `LIMIT 0`.

## 0.3.8 — 2026-11-23

- Keep unflushed changes when a managed entity is loaded again. `find()`, `findBy()`, queries and lazy collections returned the managed instance but overwrote it with the database row, so the change was lost and `flush()` wrote nothing. Only `refresh()` overwrites state now.
- Build one identity-map key for scalar, single-column and composite identifiers, compared as strings. `find()` now hits the identity map instead of always querying, and `orphanRemoval: true` deletes children removed from a loaded collection (it never did before).
- Bind float query parameters with full precision. wpdb's `%f` rounded them to six decimals (`0.0000125` became `0.000013`) in `WHERE` and DQL `UPDATE ... SET`. `NAN` and `INF` are rejected.
- Security: DQL `SELECT` throws `InvalidArgumentException` for parts it cannot compile (`JOIN ... ON`, `LIMIT`, a second `FROM` entity, a misspelled keyword, extra `ORDER BY` tokens) instead of dropping them and returning more rows than written. Use `JOIN ... WITH` and `setMaxResults()`.
- Security: reject nested arrays as query parameters; wpdb bound them as `''`, so `findBy(['token' => $_GET['token']])` with `token[][]=x` searched for an empty token.
- Hydrate int-backed `enumType` columns from wpdb strings instead of failing with a `TypeError`.
- Discover entities whose attributes use `::class` before the class declaration, such as `#[Entity(repositoryClass: Foo::class)]`.
- Accept WordPress table prefixes that start with a digit, such as `7x_`.
- Do not write rows read inside an open transaction to the second-level cache. Region versions are per process; only request-scoped caches are supported (documented).
- Add the GPL-2.0-or-later `LICENSE` file and correct the readiness and architecture docs.
- Let Dependabot update GitHub Actions pins.

## 0.3.7 — 2026-11-16

- Write second-level cache entries only after the flush transaction commits; a rollback no longer leaves uncommitted rows in the cache. Inside a caller's transaction, affected entries are evicted instead of written.
- Reject table, column, index and join column names that are not plain SQL identifiers when metadata is built, and quote all identifiers in generated DDL. The quoted DDL changes the schema hash once: the migration bridge reports one pending ORM schema migration, which plans no SQL for an unchanged schema.
- Escape backslashes in string column defaults; `'C:\temp\'` no longer breaks the generated DDL.
- Reject column types that are neither known nor plain MySQL type names instead of copying them into the DDL.
- Pin the shared `sympress/workflows` CI workflows to v1.3.2.

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

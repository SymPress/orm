# Changelog

## 0.5.0 — 2026-11-23

- Add `SymPress\Orm\EntityManagerInterface` with the application API, implemented by `EntityManager`, so consumers, decorators and test doubles no longer depend on the final class. Plumbing that only the ORM's own classes call (`registerManaged()`, `tablePrefix()`, `tableName()`, `quoteIdentifier()`, `parameterPlaceholder()`, `compileDqlFunctions()`, `applyOutputWalkers()`, `getIdentifierValue()`, `loadAssociationCollection()`, `countAssociationCollection()`, `getConnection()`) stays on `EntityManager` only.
- Add `SymPress\Orm\RepositoryInterface` with `find()`, `findAll()`, `findBy()`, `findOneBy()` and `matching()`, implemented by `Repository`. `EntityManagerInterface::getRepository()` returns it, typed by entity class for static analysis; `EntityManager::getRepository()` still returns `Repository`.
- Alias `EntityManagerInterface` to the public `EntityManager` service, so consumers can autowire the interface.
- `getEntityManager()` of `LifecycleEventArgs`, `PreUpdateEventArgs`, `OnFlushEventArgs` and `OnClearEventArgs` now declares `EntityManagerInterface` instead of `EntityManager`. The returned object is unchanged; this is the only signature change. At runtime nothing changes; for static analysis, listener code that calls a plumbing method on it needs an `instanceof EntityManager` check.

## 0.4.2 — 2026-11-23

- Hydrate `time` and `time_immutable` columns on the date `1970-01-01`, as Doctrine does. They got the current date, so the same row yielded a different object on every day and comparisons with a stored or cached value broke after midnight. Behaviour change: code that read the date part of a time value now gets `1970-01-01`. Writing back as `H:i:s` is unchanged.

## 0.4.1 — 2026-11-23

- Load rows that reference each other or themselves once. Since 0.4.0 a reference to an inheritance root with subclasses loads eagerly, and `fetch: EAGER` associations always did; when two such rows pointed at each other (`Person.spouse`) or a row at itself, loading never ended. A row that is still being hydrated now gets a lazy reference of its concrete class, filled from the same row.
- Take the foreign key of a to-one association from the referenced column only. `flush()` of two entities referencing each other extracted them recursively and never returned.

## 0.4.0 — 2026-11-23

- Load `getReference()` results and to-one associations on first access. They are native PHP lazy ghosts (`ReflectionClass::newLazyGhost()`, no generated proxies): reading the identifier does not query, the first access to any other property loads the row through the `find()` path (second-level cache, `postLoad`), and a row loaded by another query fills the reference without a second query. `$article->author->name` used to fail with "must not be accessed before initialization" unless the author had been loaded before. Each reference costs one query (N+1); join the association or load the targets first. Serializing, cloning, `json_encode()` and `==` load a reference, as any property access does.
- Throw the new `SymPress\Orm\Exception\EntityNotFoundException` when the row of a reference does not exist.
- Load references to an inheritance root whose discriminator map lists subclasses eagerly, so they get the subclass of the row; `getReference()` then queries at once and throws `EntityNotFoundException` for a missing row.
- Throw a `LogicException` on first access to a reference detached by `clear()`, `detach()` or `close()` instead of loading it; its identifier stays readable.
- Bind the lazy collections of a reference filled from a row, and of a refreshed entity, to that entity. Orphan removal deleted nothing for them and owning many-to-many join rows were rewritten on the next flush.

## 0.3.12 — 2026-11-23

- Accept named parameters called like the keywords 0.3.11 rejects. `where('l.status = :table')`, `:select`, `:union`, `:intersect` and `:except` threw `Unsupported "table" in DQL expression`; a named parameter is always bound and never reaches SQL as a keyword.
- Do not split DQL statements at named parameters called `:where` or `:having`. `WHERE l.status = :having` or `HAVING COUNT(l.id) > :where` was cut into the wrong clauses and produced broken SQL.

## 0.3.11 — 2026-11-23

- Security: reject `;`, subselects (`SELECT`, `TABLE`), `UNION`, `INTERSECT`, `EXCEPT`, SQL comments (`/*`, `#`, `--` before whitespace) and unterminated string literals in query builder fragments (`select()`, `where()`, `having()`, `groupBy()`, join conditions), `Criteria` expressions and DQL strings. They were passed through into the SQL; a comment silently cut off whatever was appended after the fragment, such as a later `andWhere()` filter, `ORDER BY` or `LIMIT`. They now throw `InvalidArgumentException`. Inside string literals they are still allowed, and `a--1` stays arithmetic.
- Keep string literals intact in DQL and query builder expressions. DQL split the statement at keywords inside literals (`l.subject = 'x ORDER BY y'`) and collapsed their whitespace, and field paths, `:name`/`?1` parameters and DQL functions were rewritten inside literals (`'see l.status'`, `'a :b'`). Literals now reach SQL byte for byte; a literal `%` is still escaped for `wpdb::prepare()`.

## 0.3.10 — 2026-11-23

- Hydrate `time` and `time_immutable` columns as `\DateTime` and `\DateTimeImmutable` and write them back as `H:i:s`. They came back as the raw string, so a `DateTime` property failed with a `TypeError`, and a `DateTime` value was written as `''`. Properties typed `string` for these columns have to change to `\DateTime` or `\DateTimeImmutable`.
- Tests: the `wpdb` test double now prepares SQL like the real `wpdb::prepare()` and is checked against it on MariaDB and MySQL; every column type is hydrated from the all-string rows `wpdb` returns.

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

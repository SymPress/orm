# Changelog

## 0.6.0 — 2026-11-23

- Write an object persisted for an existing row. `persist()` and `Repository::save()` on an unmanaged object whose identifier already existed only counted the row and attached the object as it was, so `persist(new Setting('site', 'new value')); flush();` wrote nothing and only changes made after `persist()` were written. `persist()` now reads the row instead of counting it, the same single query, and `flush()` writes every column in which the object differs from the row, including to-one foreign keys, with the update events of any managed entity; an object equal to its row writes nothing. The row is normalized like the original data of an entity loaded with `find()`, so string values from `wpdb` do not differ from integers, floats, booleans, dates, JSON or enums. Behaviour change; see `UPGRADE.md`.
- A versioned object persisted for an existing row must carry the row's current version: a stale version fails with `OptimisticLockException` at `flush()` instead of overwriting the row, and the current version is written and incremented.
- Under `DEFERRED_EXPLICIT` change tracking, `persist()` of an object that differs from its existing row is the explicit request, so the differences are written at the next `flush()`.
- `persist()` throws a `LogicException` when the existing row belongs to another class of the inheritance hierarchy instead of attaching the object to it.
- Owning many-to-many collections of such an object keep their behaviour: a collection that is not empty replaces the join rows, an empty one writes nothing. `getEntityState()` is unchanged.

## 0.5.9 — 2026-11-23

- Split DQL `SELECT` and `GROUP BY` lists only at commas outside parentheses. Since 0.5.8, legal DQL with a comma inside a function call, such as `SELECT CONCAT(a.title, a.id) FROM Article a`, `SELECT IFNULL(a.title, 'x'), a.id FROM ...` or `GROUP BY CONCAT(a.title, a.id)`, threw `Unbalanced parentheses`; it compiled in 0.5.5. The same split had kept a registered DQL function with more than one argument in a DQL `SELECT` from ever being translated; it now is. DQL clauses are also split only at keywords outside parentheses, so `HAVING GROUP_CONCAT(a.title ORDER BY a.id) ...` compiles.
- Make `getReference()` independent of what is already managed. Since 0.5.7 it threw `EntityNotFoundException` at once when an entity of another class of the inheritance hierarchy with the same identifier was managed, so hydrating a to-one association pointing at a row of the wrong class threw during hydration, and failed a whole `findAll()`, only after that row had been loaded. It now returns a lazy reference that fails on first access in both cases. `find()` still returns `null` for a managed row of another class.

## 0.5.8 — 2026-11-23

- Security: reject unbalanced parentheses in query builder fragments (`select()`, `where()`, `orWhere()`, `having()`, `groupBy()`, join conditions), `Criteria` expressions and DQL strings. Since 0.5.3 predicates are wrapped in parentheses where they are combined, and since 0.5.4 the discriminator condition of a single-table subclass is combined with them; a fragment such as `andWhere('a.x = 1) OR (1 = 1')` closed the wrapper early and let its `OR` escape both the other predicates and the discriminator filter. A fragment whose parentheses outside string literals do not balance, or close before they open, now throws `InvalidArgumentException`; `orWhere()` checks its fragments when it is called. Parentheses inside string literals are still allowed.

## 0.5.7 — 2026-11-23

- Narrow queries on a mapped single-table class without a discriminator value of its own. Such a class, for example an abstract `Visual` between the root `Media` and the mapped `Photo` and `Video`, got no filter: `getRepository(Visual::class)->findAll()` read the whole table and DQL `DELETE FROM Visual v` compiled to a `DELETE` without `WHERE` that emptied it. Every non-root class of a hierarchy now sees the rows of the mapped classes that are it or extend it, and no row (`1 = 0`) when there are none. Root queries are unchanged. Behaviour change; see `UPGRADE.md`.
- Remove orphans of a subclass of the association's target. With `orphanRemoval: true` on a one-to-many to a hierarchy root, removing a subclass child (a `Book` from `Shelf::$items` targeting `Item`) deleted nothing for it, because the identity map is keyed by concrete class and the lookup used the target class. Lookups now search the whole hierarchy, so `find()` and `getReference()` on a root or intermediate class also return an entity already managed as a subclass without querying again. `find()` returns `null` and `getReference()` throws `EntityNotFoundException` when the managed entity with that identifier is of another class.

## 0.5.6 — 2026-11-23

- Skip an associated reference whose row is already gone in a cascaded remove. Since 0.5.5 `remove()` loads an unloaded reference and throws `EntityNotFoundException` for a missing row, and `cascade()` calls `remove()` for associated entities too: a parent with a `cascade: ['remove']` to-one whose key dangles (common in WordPress tables without foreign keys) could no longer be removed through the ORM, while the children visited before that association stayed scheduled and the next `flush()` deleted them and kept the parent. A cascaded remove now skips such a reference, since nothing is left to delete, and continues; it still throws when the row exists and a load further down failed. `remove()` of the entity a caller passes still throws for a missing row.
- Schedule the cascade of `remove()` only once all of it succeeded. A `remove()` that throws, for example from a `preRemove` listener, now schedules nothing instead of leaving the children visited before the failure scheduled.

## 0.5.5 — 2026-11-23

- Skip `preFlush` and `postFlush` callbacks, entity listeners and events for references that were never loaded. A callback touching a property loaded every reference on every flush (N+1), and a reference to a deleted row made `flush()` throw `EntityNotFoundException`, roll back and close the entity manager; 0.3.8 flushed the same code.
- Load an unloaded reference before an optimistic `lock()` check. The version read as empty, so `lock($reference, LockMode::OPTIMISTIC, 3)` always failed with `actual version ""`. Pessimistic locks still need only the identifier.
- Load an unloaded reference in `remove()` (one `SELECT`). Cascade remove and orphan removal skipped its associations and left orphan rows, a `postRemove` listener reading a field failed with a misleading "detached" `LogicException` that closed the manager, and a reference to a missing row was deleted silently. A missing row now throws `EntityNotFoundException` at `remove()`. Behaviour change; see `UPGRADE.md`. Use DQL `DELETE` to delete without loading.

## 0.5.4 — 2026-11-23

- Filter queries on a single-table subclass by its discriminator. They read the whole table: `getRepository(Dog::class)->findAll()` returned animals too, `find(Dog::class, 'a1')` returned an `Animal`, and DQL `DELETE FROM Dog d` and `UPDATE Dog d` changed rows of the whole hierarchy. A query whose entity is a subclass with a discriminator value now sees only rows whose discriminator is that class's value or the value of one of its mapped subclasses: the query builder and DQL `SELECT` (root and joined entities), DQL `UPDATE` and `DELETE`, `find()`, the row loader behind lazy references, and loading and counting of to-many collections. The values are bound as parameters. `find()` for a subclass with the identifier of another class's row returns `null`. Queries on the hierarchy root are unchanged. Behaviour change: subclass queries return and change fewer rows; see `UPGRADE.md`.

## 0.5.3 — 2026-11-23

- Parenthesize query predicates where they are combined. `where()`, `andWhere()`, `having()`, `andHaving()`, join conditions and the `orWhere()` composition joined fragments with ` AND ` without parentheses, so an `OR` inside one fragment bound across the `AND`: `where('a.title = :t OR a.title = :u')->andWhere('a.author = :au')` filtered by author in the second branch only, `orWhere()` followed by `andWhere()` compiled to `(A) OR (B) AND C`, and `JOIN a.author au WITH au.name = :n OR au.name = :m` joined every author named `:m` to every article. The same reached `Criteria` and `Repository::matching()`. Each fragment now stays one predicate; a single `where()` or `having()` predicate keeps its SQL. Behaviour change: such queries return fewer rows and different SQL; see `UPGRADE.md`.

## 0.5.2 — 2026-11-23

- Security: quote string parameters written directly after a `%` in query builder fragments, `Criteria` expressions and DQL strings. Affected versions: 0.3.9 to 0.5.1. Since 0.3.9 a literal `%` is escaped as `%%`, so `where('u.id %:n = 0')` or DQL `u.id %?1` compiled to `%%%s`, and `wpdb::prepare()` leaves a `%s` unquoted when a `%` stands directly before it. A string value was escaped but not quoted and became part of the SQL: `setParameter('n', '2 OR 1=1')` produced `WHERE u.id %2 OR 1=1 = 0`, in `SELECT` as well as in DQL `UPDATE` and `DELETE`. A parameter between two string literals written without spaces (`'a':n'b'`) had the same effect, because `prepare()` drops the quotes of `'%s'`. Such a placeholder is now set off by a space and quoted. Queries without a parameter directly after `%` or between two literals were not affected, and their SQL is unchanged.

## 0.5.1 — 2026-11-23

- Mark internals `@internal`. From 1.0.0 on, everything not marked `@internal` is public API under Semantic Versioning; internals may change in any release. The markers are docblocks only and change no behaviour, signature or visibility.
- Internal classes: `Query\DqlCompiler`, `Query\LiteralMask`, `Query\DqlExtensionRegistry`, `Dbal\ConnectionProvider`, `Compiler\EntityCatalogPass`, `Metadata\CompiledEntityCatalog`, `Metadata\MappingValidator`, `Collection\PersistentCollection` (type-hint `Collection`), `Schema\SqlTypeMapper`, `Util\NameConverter`, `Bridge\Migration\OrmMigrationRegistrar` and the console command classes (the command names stay public).
- Internal members of public classes: the plumbing methods of `EntityManager` that are not on `EntityManagerInterface`; the methods of `UnitOfWork` that register, schedule, mark, snapshot or detach, its identity-map lookups and `scheduledUpdates()` (the inspection methods `contains()`, `entityState()`, `scheduledInsertions()`, `scheduledDeletions()`, `originalData()` and `managedEntities()` stay public); all methods of `EntityHydrator` (constructing it stays public); `MetadataFactory::getMetadataFor()`, `hasMetadataFor()` and `mappingResources()` (read metadata through `EntityManager::getClassMetadata()`); `EntityClassRegistry::groups()`, `managerForClass()` and `findByShortName()`; the constructors of the metadata value objects and of the event args.

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

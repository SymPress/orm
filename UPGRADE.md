# Upgrade Guide

## From 0.3.8 to 1.0

These behaviour changes from 0.3.9 to 0.5.4 can require action. `CHANGELOG.md`
lists every change.

### Queries

- **Literal `%` (0.3.9).** Write a literal `%` in query builder and DQL
  expressions as is. Code that wrote `%%` as a workaround in queries with
  parameters now gets a literal `%%`; replace it with `%`.
- **`setMaxResults(0)` (0.3.9)** returns no rows instead of one. Pass `null`, or
  do not call it, for no limit.
- **Rejected fragments (0.3.11).** `select()`, `where()`, `having()`,
  `groupBy()`, join conditions, `Criteria` expressions and DQL strings throw
  `InvalidArgumentException` for `;`, subselects (`SELECT`, `TABLE`), `UNION`,
  `INTERSECT`, `EXCEPT`, SQL comments and unterminated string literals outside
  string literals. Rewrite such queries without them, or run them as native SQL
  through `createNativeQuery()` with bound parameters.
- **Literals stay literal (0.3.11).** Field paths, `:name` and `?1` parameters
  and DQL functions inside quoted strings are no longer rewritten. Write
  `l.subject = :subject`, not `l.subject = ':subject'`.
- **Combined predicates (0.5.3).** Fragments passed to `where()`, `andWhere()`,
  `having()`, `andHaving()`, join conditions, `orWhere()` and `Criteria` are
  each parenthesized where they are combined, so an `OR` inside one no longer
  binds across the `AND`. Queries that combine a fragment containing `OR` now
  return fewer rows: the ones they were written to return. Check code or tests
  that relied on the old results or compare generated SQL.
- **Single-table subclasses (0.5.4).** Queries on a subclass of a single-table
  hierarchy (repositories, the query builder, DQL, `find()`, references and
  to-many collections) return only rows of that class and its mapped
  subclasses, and DQL `UPDATE` and `DELETE` on it change only those rows. Code
  that queried a subclass to reach rows of the whole hierarchy has to query the
  root class instead. `find()` for a subclass with the identifier of another
  class's row returns `null`.

### Column types

- **`time` and `time_immutable` (0.3.10, 0.4.2)** hydrate as `\DateTime` and
  `\DateTimeImmutable` instead of the raw string, with the date 1970-01-01.
  Type such properties `\DateTime` or `\DateTimeImmutable` instead of `string`,
  and do not read the date part.

### References

- **Lazy to-one references (0.4.0).** `getReference()` and to-one associations
  return native PHP lazy objects that load their row on first access to a
  property other than the identifier.
  - Catch `SymPress\Orm\Exception\EntityNotFoundException` where a reference may
    point at a missing row; it is thrown on first access.
  - A reference detached by `clear()`, `detach()` or `close()` throws
    `LogicException` on first access. Load the entity again with `find()`.
  - Serializing, cloning, `json_encode()` and `==` load a reference.
  - Each reference costs one query. Join the association, or load the targets
    with one query first, where many references are read in a loop.
  - `getReference()` for an inheritance root whose discriminator map lists
    subclasses queries at once and throws `EntityNotFoundException` for a
    missing row.

### Public API

- **Event args (0.5.0).** `getEntityManager()` on `LifecycleEventArgs`,
  `PreUpdateEventArgs`, `OnFlushEventArgs` and `OnClearEventArgs` declares
  `EntityManagerInterface`. The object is still the `EntityManager`; static
  analysis needs an `instanceof EntityManager` check before calling a method
  that is not on the interface.
- **Internals (0.5.1).** Classes and members marked `@internal` are not covered
  by the backward-compatibility policy. Move code that uses them to the public
  API, for example `EntityManager::getClassMetadata()` instead of
  `MetadataFactory::getMetadataFor()`, and `Collection` instead of
  `PersistentCollection` in type hints.

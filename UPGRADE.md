# Upgrade Guide

## From 0.3.8 to 1.0

These behaviour changes from 0.3.9 to 1.0.0 can require action, in release
order. Releases that are not listed need none. `CHANGELOG.md` lists every
change.

### 0.3.9

- **Literal `%`.** Write a literal `%` in query builder and DQL expressions as
  is. Code that wrote `%%` as a workaround in queries with parameters now gets a
  literal `%%`; replace it with `%`.
- **`setMaxResults(0)`** returns no rows instead of one. Pass `null`, or do not
  call it, for no limit.

### 0.3.10

- **`time` and `time_immutable`** hydrate as `\DateTime` and
  `\DateTimeImmutable` instead of the raw string. Type such properties
  `\DateTime` or `\DateTimeImmutable` instead of `string`.

### 0.3.11

- **Rejected fragments.** `select()`, `where()`, `having()`, `groupBy()`, join
  conditions, `Criteria` expressions and DQL strings throw
  `InvalidArgumentException` for `;`, subselects (`SELECT`, `TABLE`), `UNION`,
  `INTERSECT`, `EXCEPT`, SQL comments and unterminated string literals outside
  string literals. Rewrite such queries without them, or run them as native SQL
  through `createNativeQuery()` with bound parameters.
- **Literals stay literal.** Field paths, `:name` and `?1` parameters and DQL
  functions inside quoted strings are no longer rewritten. Write
  `l.subject = :subject`, not `l.subject = ':subject'`.

### 0.4.0

- **Lazy to-one references.** `getReference()` and to-one associations return
  native PHP lazy objects that load their row on first access to a property
  other than the identifier.
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

### 0.4.2

- **`time` and `time_immutable`** values carry the date 1970-01-01. Do not read
  the date part.

### 0.5.0

- **Event args.** `getEntityManager()` on `LifecycleEventArgs`,
  `PreUpdateEventArgs`, `OnFlushEventArgs` and `OnClearEventArgs` declares
  `EntityManagerInterface`. The object is still the `EntityManager`; static
  analysis needs an `instanceof EntityManager` check before calling a method
  that is not on the interface.

### 0.5.1

- **Internals.** Classes and members marked `@internal` are not covered by the
  backward-compatibility policy. Move code that uses them to the public API,
  for example `EntityManager::getClassMetadata()` instead of
  `MetadataFactory::getMetadataFor()`, and `Collection` instead of
  `PersistentCollection` in type hints.

### 0.5.3

- **Combined predicates.** Fragments passed to `where()`, `andWhere()`,
  `having()`, `andHaving()`, join conditions, `orWhere()` and `Criteria` are
  each parenthesized where they are combined, so an `OR` inside one no longer
  binds across the `AND`. Queries that combine a fragment containing `OR` now
  return fewer rows: the ones they were written to return. Check code or tests
  that relied on the old results or compare generated SQL.

### 0.5.4 and 0.5.7

- **Single-table subclasses.** Queries on a subclass of a single-table
  hierarchy (repositories, the query builder, DQL, `find()`, references and
  to-many collections) return only rows of that class and its mapped
  subclasses, and DQL `UPDATE` and `DELETE` on it change only those rows. Code
  that queried a subclass to reach rows of the whole hierarchy has to query the
  root class instead. `find()` for a subclass with the identifier of another
  class's row returns `null`. Since 0.5.7 this also holds for a mapped class
  without a discriminator value of its own, such as an abstract intermediate
  class: it sees the rows of the mapped classes that extend it, or none.

### 0.5.5 and 0.5.6

- **`remove()` loads references.** `remove()` of an unloaded reference issues
  one `SELECT` and cascades remove and orphan removal to its associations, as
  for an entity loaded with `find()`. A reference to a missing row throws
  `EntityNotFoundException` at `remove()` instead of deleting nothing; since
  0.5.6 a cascaded remove skips such a reference instead. Use DQL `DELETE` to
  delete rows without loading them.

### 0.5.8

- **Unbalanced parentheses.** The fragments listed under 0.3.11 throw
  `InvalidArgumentException` when their parentheses outside string literals do
  not balance or close before they open. Balance them; `orWhere()` checks its
  fragments when it is called.

### 0.6.0 to 0.6.2

- **`persist()` of an existing row writes the object.** `persist()` and
  `Repository::save()` on an unmanaged object whose identifier already exists
  used to attach it as it was, so only changes made after `persist()` were
  written. They now write every column in which the object differs from the
  row. Code that used `persist()` only to re-attach an object, for example one
  built with partial data, now overwrites the row with that object's state:
  load the entity with `find()` and change it instead. A versioned object must
  carry the row's current version, or `flush()` throws
  `OptimisticLockException`, and a row of another class of the inheritance
  hierarchy makes `persist()` throw a `LogicException`.
  - `prePersist` does not run for an existing row; a value that a
    `prePersist` callback sets for new rows is not set. A mapped property the
    object never initialized keeps the row's value and is readable afterwards;
    assign `null` explicitly to write `NULL`.
  - `persist()` throws a `LogicException` when another instance with the same
    identifier is already managed, for example one loaded with `find()`.
    Change that instance instead of persisting a second one.
  - An unloaded reference counts as that managed instance (0.6.2): after an
    article whose `author` points at `au1` was loaded,
    `persist(new Author('au1', ...))` throws as well. `find()` the author and
    change it instead.
  - A `persist()` that throws schedules nothing (0.6.2): neither the object nor
    what its cascade registered before the failure is written by a later
    `flush()`.

### 1.0.0

- **`Query` constructor.** The constructor of `SymPress\Orm\Query\Query` is
  marked `@internal`. Create queries with `EntityManager::createQuery()`,
  `createNativeQuery()` or `QueryBuilder::getQuery()` instead of `new Query()`.

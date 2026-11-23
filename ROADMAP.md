# Roadmap to 1.0.0

Status: 0.5.1 (2026-11-23).

1.0.0 freezes the contract of what the package already does: attribute mapping,
the unit of work, repositories, the query builder with its DQL subset,
associations, lifecycle events, schema tooling and request-scoped caching on top
of `wpdb`. It is not a port of `doctrine/orm`. From 1.0.0 on the package follows
Semantic Versioning: no breaking change to the public API within 1.x.

## Steps

Each step is one pull request. Steps with a version ship in that release.

- [x] **0.3.9** Query builder: a literal `%` in expressions works together with
  bound parameters, parameters in `select()` are bound, and `setMaxResults(0)`
  returns no rows instead of one.
- [x] **0.3.10** Tests follow the real `wpdb::prepare()` semantics, and every
  column type is hydrated from the string rows `wpdb` returns.
- [x] **0.3.11** DQL splits clauses outside string literals and rejects `;` and
  subselects instead of passing them through.
- [x] **0.4.0** To-one associations and `getReference()` load on first access
  through native PHP lazy objects.
- [x] **0.5.0** `EntityManagerInterface` and `RepositoryInterface` for consumers,
  decorators and test doubles.
- [x] **0.5.1** Internals are marked `@internal`. Everything else is public API.
- [x] Backward-compatibility policy, deprecation path, `UPGRADE.md`,
  `SECURITY.md` and the frozen column type catalog.
- [ ] **1.0.0** Stable release.

## Decisions frozen for 1.x

- **DQL stays a subset.** `where()`, `having()`, join conditions and DQL strings
  are SQL expression fragments in which mapped field paths and parameters are
  rewritten. A literal-aware scanner compiles them, and anything it does not
  understand is rejected. There is no full Doctrine grammar.
- **`json`, `array` and `simple_array` are stored as JSON** in a `longtext`
  column. `simple_array` is not Doctrine's comma-separated format; changing it
  would make existing rows unreadable.
- **The column type catalog is frozen** as listed in the README. `decimal`
  hydrates as `float`, and `time` values carry the date 1970-01-01.
- **Caches are request-scoped.** The second-level cache and the query result
  cache use `CacheInterface` with the bundled `ArrayCache`. Region versions live
  in the PHP process, so a persistent cache is not supported.
- **MySQL and MariaDB through `wpdb` are the only platform.**

## Not in 1.0

These can be added later without a breaking change:

- joined and table-per-class inheritance
- the full DQL grammar: subselects, `CASE`, AST walkers
- a persistent second-level cache with durable region versions
- a streaming `Query::toIterable()`; `wpdb` buffers the whole result set
- a shared interface for the migration identity. The bridge exposes
  `getMigrationKey()` and `getLegacyMigrationKeys()` by convention today, and
  `sympress/migration` has to publish the interface first
- other database platforms

## Where the package stands

| Area | Status |
| --- | --- |
| Entity metadata and attributes | Ready |
| Entity manager and unit of work | Ready |
| Repositories and criteria | Ready |
| Associations and composite identifiers | Ready; to-one references load lazily, inheritance roots with subclasses eagerly |
| Query builder and DQL subset | Ready as a subset; unsupported syntax is rejected |
| Lifecycle callbacks and events | Ready |
| Version columns and locking | Ready |
| Schema tool and migration bridge | Ready for WordPress on MySQL and MariaDB |
| Hydration and type conversion | Ready |
| Single-table inheritance | Ready |
| Custom DQL functions and output walkers | Ready |
| Query result cache | Ready, request scope |
| Second-level cache | Request caches only |
| Extra-lazy collections | Ready for `count()` and `isEmpty()` |

`README.md` lists the features and `docs/api-and-architecture.md` describes how
they work.

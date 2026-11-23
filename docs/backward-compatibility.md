# Backward Compatibility Policy

From 1.0.0 on, `sympress/orm` follows [Semantic Versioning](https://semver.org/).
A breaking change ships only in a new major version; minor versions add
features, patch versions fix bugs.

## Public API

Everything in `src/` that is not marked `@internal` is public API: classes,
interfaces, enums, attributes, public methods and constants. An `@internal`
class or member may change or disappear in any release. Passing internal
objects to optional constructor parameters of public classes, such as the
`$connectionProvider` and `$dqlExtensions` arguments of `EntityManager`, is
internal use as well.

## Breaking changes

These change only in a major release:

- Signatures of the public API: removing or renaming a class, method, constant,
  attribute or attribute argument, adding a required parameter, narrowing a
  parameter type or widening a return type, and adding a method to an interface
  that consumers implement (`EntityManagerInterface`, `RepositoryInterface`,
  `CacheInterface`, `ConnectionInterface`, `SqlPlatformInterface`,
  `EventSubscriberInterface`).
- Behaviour of documented features, including the accepted DQL subset, the
  lifecycle events and the exceptions they throw.
- Storage formats: how each column type is written and read (see "Supported
  Column Types" in the README), including `json`, `array` and `simple_array` as
  JSON, so rows written by one 1.x release stay readable by every later one.
- The schema generated for an unchanged mapping: tables, columns, column types,
  defaults and indexes.
- Console command names, arguments and options (`orm:mapping:info`,
  `orm:schema:sql`, `orm:migrations:diff`).
- Service ids, aliases and container parameters (`orm.entity_paths`,
  `orm.entity_classes`, `orm.allow_destructive_updates`,
  `orm.legacy_migration_keys`).

## Not breaking

- Anything marked `@internal`.
- The exact SQL text of generated queries and DDL, as long as it means the same.
- A changed schema hash that plans no SQL. When generated DDL changes text but
  not the schema, the migration bridge reports one pending schema migration
  that plans no SQL; running it changes nothing. The changelog says so.
- Exception messages. Exception classes and when they are thrown are public API.
- New classes, methods, optional parameters and column types.

## Security fixes

A security fix may reject input that was accepted before, for example a query
fragment syntax that could change the meaning of a query. It ships in a patch
release, not a major one, and the changelog calls it out with an entry that
starts with `Security:`.

## Deprecations

A public feature that is going to be removed is first marked `@deprecated` with
its replacement and triggers an `E_USER_DEPRECATED` error when used. That
happens in a minor release; the feature is removed no earlier than the next
major release. `UPGRADE.md` lists the changes needed for each major version.

# ORM API and Architecture

The SymPress ORM package provides Doctrine-inspired persistence primitives for WordPress projects while keeping `wpdb` as the database runtime. It is intentionally small: mapping, metadata, repositories, query building, schema SQL, unit of work, lifecycle events, and cache hooks live in this package; WordPress connection behavior stays delegated to `wpdb`.

## Design Goals

- Use PHP attributes for entity mapping.
- Generate WordPress-compatible SQL with table prefixes, `wpdb::prepare()` placeholders, and MySQL-oriented schema statements.
- Keep writes explicit and transactional through `EntityManager::flush()`.
- Avoid raw column and identifier fragments in repository and query-builder APIs where mapped field names can be used.
- Keep schema updates non-destructive by default.
- Require SymPress Kernel for the package bundle and expose an optional SymPress Migration bridge.

## Main Components

### EntityManager

`SymPress\Orm\EntityManager` is the primary application API. It owns the unit of work, metadata access, repositories, query creation, events, and optional second-level cache. Database connection normalization is delegated to `Dbal\ConnectionProvider`, while custom DQL functions and SQL output walkers are kept in `Query\DqlExtensionRegistry`.

Typical usage:

```php
$metadataFactory = new MetadataFactory();
$registry = new EntityClassRegistry($metadataFactory, classes: [EmailLog::class]);
$entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), $wpdb);

$log = new EmailLog('log-1', new DateTimeImmutable(), 'queued');
$entityManager->persist($log);
$entityManager->flush();
```

When no `wpdb` instance is passed, the ORM resolves the global `$wpdb` lazily through `ConnectionProvider` and `WpdbConnection`.

### UnitOfWork

`UnitOfWork` tracks entity state, identity-map entries, original data, scheduled insertions, explicit updates, removals, and collection snapshots. `flush()` is the only point where scheduled writes are pushed to the database.

Flush lifecycle:

1. Start a transaction when no transaction is active.
2. Dispatch `preFlush` and `onFlush`.
3. Compute scheduled updates from dirty managed entities.
4. Insert new entities and mark them managed.
5. Update changed entities.
6. Synchronize owning many-to-many join tables from collection snapshots.
7. Delete removed entities and their owning join rows.
8. Dispatch post events and commit.
9. Roll back and close the entity manager if an exception escapes.

### MetadataFactory and EntityClassRegistry

With `OrmBundle`, the container compiler discovers configured and bundle entity classes, keeps their manager groups, and constructs immutable `ClassMetadata` graphs. The compiled container injects a `CompiledEntityCatalog` into `EntityClassRegistry` and `MetadataFactory`. Warm mapping queries require neither entity-directory discovery/tokenization nor mapping-attribute reflection, and can return metadata without autoloading entity classes. Association targets and inheritance discriminator classes are compiled even when they are outside the root manager class list. Embeddables, mapped superclasses and mapping traits contribute source resources.

Configured path, class and manager parameter aliases (including `%kernel.project_dir%/entities`) are resolved before discovery.

The compiler exports only explicit immutable ORM DTO types plus scalar/array values as ordinary DI definitions. The private Kernel PHP container constructs them with typed constructors; no serialized application objects or `unserialize()` input is used. This is mapping metadata, not cached database rows.

Direct standalone construction without a catalog retains filesystem/attribute discovery. `register()` adds dynamic classes to this registry instance; uncatalogued metadata reflects once on demand. `refreshDiscovery()` explicitly returns an instance to standalone filesystem discovery, preserving manual registrations. `MetadataFactory::refresh($className)` invalidates one cached/compiled mapping for that instance; `refresh()` invalidates all. Already loaded PHP classes cannot acquire new attributes by editing their files: deploy/rebuild in a fresh process for source changes.

Compiler resources include recursive entity directories (additions/removals), entity/parent/embeddable files (mapping contents), missing configured roots (creation), and the normal Kernel configuration resources. Mutable/debug Kernel freshness checks still inspect/hash these resources; eliminating those checks requires explicit immutable deployments. `SYMPRESS_KERNEL_IMMUTABLE_CACHE=1` with a changed-per-release `SYMPRESS_KERNEL_BUILD_ID` allows warm lookup without an entity filesystem walk. Mutating a release without changing that ID violates this policy. No automatic global cache of WordPress activation state is introduced.

### EntityHydrator

`EntityHydrator` converts database rows into entity instances and extracts entity state back into database arrays. It supports constructor hydration, property assignment, embeddeds, enums, date/time values, booleans, JSON-like arrays, and association placeholders.

### Repository

`Repository` provides `find()`, `findAll()`, `findBy()`, `findOneBy()`, `matching()`, `save()`, and `remove()`. Criteria and ordering field names are validated against metadata before they reach SQL generation.

```php
$queued = $entityManager
    ->getRepository(EmailLog::class)
    ->findBy(['status' => 'queued'], ['createdAt' => 'DESC'], limit: 25);
```

### QueryBuilder and DQL Compiler

`QueryBuilder` compiles mapped entity paths into SQL. Parameters are converted into `wpdb` placeholders and are prepared only at execution time.

```php
$query = $entityManager
    ->createQueryBuilder()
    ->select('l')
    ->from(EmailLog::class, 'l')
    ->where('l.status = :status')
    ->orderBy('l.createdAt', 'DESC')
    ->setParameter('status', 'queued')
    ->getQuery();

$logs = $query->getResult();
```

Supported DQL is a focused subset:

- `SELECT ... FROM ...`
- `UPDATE ... SET ... WHERE ...`
- `DELETE FROM ... WHERE ...`
- joins through mapped associations
- `WHERE`, `GROUP BY`, `HAVING`, `ORDER BY`
- named and positional parameters
- array parameters for `IN (...)`
- custom DQL functions through `EntityManager::registerDqlFunction()`
- output walkers through `EntityManager::addOutputWalker()`

`ORDER BY` accepts mapped field paths only, for example `l.createdAt`. Raw SQL fragments are rejected.

Do not interpolate request values into `where()`, `having()`, join conditions, or DQL strings. Those APIs accept expression snippets so mapped fields can be compiled, but dynamic values are only safe when bound through named or positional parameters. User-controlled sort choices should be mapped through an allow-list before calling `orderBy()` or `addOrderBy()`.

### SchemaTool

`SchemaTool` produces deterministic SQL for create, update, drop, validation, and schema hashes. It is used directly by console commands and by the migration bridge.

```php
$tool = new SchemaTool($metadataFactory, $registry, new SchemaSqlGenerator(), $wpdb);

$createSql = $tool->getCreateSchemaSql();
$updateSql = $tool->getUpdateSchemaSql();
$destructiveUpdateSql = $tool->getUpdateSchemaSql(allowDestructiveUpdates: true);
```

Update SQL creates missing tables, adds missing columns, modifies changed columns, and adds missing indexes. Dropping unknown columns and indexes is opt-in through `allowDestructiveUpdates`.

Join tables use the referenced column metadata from the source and target entities, so numeric identifiers produce numeric join columns instead of generic strings.

## Mapping API

Common mapping attributes live in `SymPress\Orm\Mapping`.

```php
#[Entity(table: 'sympress_mailer_logs')]
#[Index(name: 'status_created', columns: ['status', 'createdAt'])]
final readonly class EmailLog
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[Column(type: 'datetime')]
        public DateTimeImmutable $createdAt,
        #[Column(type: 'string', length: 20)]
        public string $status,
        #[Column(type: 'json', nullable: true)]
        public array $payload = [],
    ) {
    }
}
```

Important attributes:

- `#[Entity]`, `#[Table]`, `#[MappedSuperclass]`, `#[Embeddable]`
- `#[Id]`, `#[Column]`, `#[GeneratedValue]`, `#[Version]`
- `#[Index]`, `#[UniqueConstraint]`
- `#[ManyToOne]`, `#[OneToOne]`, `#[OneToMany]`, `#[ManyToMany]`
- `#[JoinColumn]`, `#[InverseJoinColumn]`, `#[JoinTable]`
- lifecycle attributes such as `#[PrePersist]`, `#[PostLoad]`, and `#[PreUpdate]`
- inheritance attributes such as `#[InheritanceType]`, `#[DiscriminatorColumn]`, and `#[DiscriminatorMap]`
- `#[Cache]` for entity or association cache regions

## Associations and Collections

To-many associations can use `Collection` or `PersistentCollection`. Persistent collections load lazily through the entity manager and can count rows without full initialization when only a count is needed.

Owning many-to-many collections are synchronized during `flush()`. The ORM compares identifier snapshots and skips unchanged collections, which avoids delete-and-reinsert work on repeated flushes.

## Events

`EventManager` dispatches lifecycle and unit-of-work events. Subscribers implement `EventSubscriberInterface`.

Available event names are defined in `SymPress\Orm\Event\Events`, including:

- `prePersist`, `postPersist`
- `preUpdate`, `postUpdate`
- `preRemove`, `postRemove`
- `preFlush`, `onFlush`, `postFlush`
- `postLoad`
- `onClear`

## Caching

The cache layer is intentionally small and uses `CacheInterface`. The package includes `ArrayCache` for request/process usage. Metadata compiled into the container persists safely between requests as mapping definitions; ArrayCache does not become a shared persistent entity/result cache. EntityManager region versions remain local to the manager instance. Do not substitute a shared data cache and claim coherent cross-process invalidation: that requires separately implemented durable region versions and write coordination. The default entity/query cache therefore provides no cross-request data hit promise.

Two cache paths exist:

- Query result cache with `Query::useResultCache()`
- Second-level entity and association cache via `EntityManager` and `#[Cache]`

ORM DML operations evict or bump affected regions so stale results are not reused after writes.

## WordPress and wpdb Compatibility

The ORM keeps these invariants:

- All SQL uses the WordPress table prefix from `wpdb`.
- Dynamic values go through `wpdb::prepare()` placeholders.
- Identifiers are quoted by `WordPressSqlPlatform`.
- Repository fields and `ORDER BY` paths are validated against metadata.
- Schema SQL uses WordPress/MySQL table syntax and `wpdb::get_charset_collate()`.
- Transactions are emitted as SQL statements through `wpdb::query()`.

## Security Notes

Do not pass user input into field names, DQL snippets, join conditions, or raw native SQL. Use mapped repository criteria, mapped query-builder paths, and parameters for dynamic values.

Safe:

```php
$builder
    ->where('l.status = :status')
    ->setParameter('status', $requestStatus);
```

Unsafe:

```php
$builder->where($rawRequestExpression);
```

For schema updates, destructive drops are disabled by default to protect existing WordPress data from accidental removal.

## Performance Notes

- With OrmBundle, discovery and immutable mapping are compiled into the private Kernel container; standalone instances cache their first discovery/reflection result.
- Mutable Kernel freshness still checks source resources; immutable deployment identities avoid those checks explicitly.
- Standalone discovery prefilters PHP files before tokenization and skips symlink files/directories.
- The identity map returns already managed entities without re-querying.
- `flush()` writes only scheduled or dirty entities.
- Owning many-to-many synchronization uses collection snapshots and skips unchanged collections.
- `Query::toIterable()` currently iterates over the in-memory result set returned by `wpdb`; it is not a streaming cursor.

## Console and Migration Integration

The package provides console commands for schema SQL and migration diffs:

- `SchemaSqlCommand`
- `MigrationDiffCommand`
- `MappingInfoCommand`

`--destructive` enables column modifications, replacement indexes and drop SQL for schema diffs when explicitly requested. Existing column/index definitions are preserved by default.

Migration integration is implemented by:

- `Bridge\Migration\OrmMigrationRegistrar`
- `Bridge\Migration\SchemaMigrationFactory`

## Quality Gates

Run these commands from `packages/orm`:

```bash
composer cs
composer static-analysis
composer tests
composer qa
```

`composer cs` uses PHPCS with the SymPress WordPress ruleset. `composer static-analysis` runs PHPStan at max level against `src`. `composer tests` runs the unit and integration suites.

## Reviewed schema and transaction behavior

Automatically generated schema migrations are irreversible: `down()` raises an
explicit exception in both the runtime bridge and generated migration classes.
It never generates a blanket table drop. Write and review a separate inverse
migration when a rollback is required. Migration namespaces are validated before
creating any generated file.

Schema versions hash the intended CREATE schema, independent of the remaining
live diff. Applying a migration therefore does not change its version when the
diff becomes empty; changing entity metadata does produce a pending version.
The bridge publishes an explicit stable `orm-schema:<manager>` migration key.
It computes only intended-schema identity at creation. Its forward SQL and
destructive-policy check refresh live schema state during execution. Use the
Migration 1.0.4 or later WordPress executor with the deferred operation contract so this
inspection runs after the advisory lock is acquired; older/custom executors
without that contract retain their existing operation semantics.

For a deployment with applied anonymous records from the old bridge, provide
their exact stored identities in `SchemaMigrationFactory`'s optional
`$legacyMigrationKeys` constructor argument, keyed by manager name. The generated
migration exposes those through `getLegacyMigrationKeys()`. Do not derive aliases
from a basename, line number or schema hash. SymPress Migration refuses unmapped
old anonymous state before execution; inspect the state with backups in place
and configure the explicit mapping before deploying this identity transition.
Versions and append-only history remain independent of that mapping.

Entity discovery is cached for the registry lifetime. Schema inspection results
are cached separately by manager and destructive policy; call
`SchemaTool::refreshSchemaState()` after external schema changes or newly
registered entity classes. `flush()` does not scan entity directories or inspect
schema. It retains the existing scheduled-write and lifecycle contract.

Repository criteria use `IS NULL` for null and parameterized `IN (...)` for
arrays. Empty arrays compile to `IN (NULL)` and match no rows. The query-builder
`andWhereLike(field, value)` validates the mapped field and binds an escaped
contains pattern; literal percent and underscore characters cannot broaden it.
Table lookups escape SQL LIKE wildcards through `wpdb::esc_like()`.

Nested transactions use savepoints. Inner rollback preserves the outer
transaction; committing the outer transaction cannot accidentally commit rolled
back inner work. Failed transaction control statements raise an error.
`composer tests:database` verifies these contracts with real WordPress `wpdb`
and a disposable MariaDB database configured through `WORDPRESS_DB_*`.
The bootstrap requires an explicit `WORDPRESS_DB_NAME=sympress_review_*` value;
it refuses general application databases. Required database CI fails skipped or
incomplete tests and runs on pull requests, main and the weekly schedule.

The generated migration bridge refuses incompatible existing-column/index changes
unless the SchemaTool caller explicitly enables destructive updates. The changed
intended-schema hash remains pending after that refusal; neither empty safe diffs
nor a failed operation mark it applied. The CLI likewise requires --destructive
before generating those changes. Review data compatibility before granting that
intent. Ordinary additive diffs remain safe by default.

The WordPress PHPStan profile is enabled. The DBAL adapter has three narrow
`sympress.preparedSql` suppressions at its public generated/raw SQL boundary;
callers remain responsible for vetted identifiers and bound values. No package
or receiver-wide SQL policy exclusion is used.

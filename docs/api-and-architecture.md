# ORM API and Architecture

The SymPress ORM package provides Doctrine-inspired persistence primitives for WordPress projects while keeping `wpdb` as the database runtime. It is intentionally small: mapping, metadata, repositories, query building, schema SQL, unit of work, lifecycle events, and cache hooks live in this package; WordPress connection behavior stays delegated to `wpdb`.

## Design Goals

- Use PHP attributes for entity mapping.
- Generate WordPress-compatible SQL with table prefixes, `wpdb::prepare()` placeholders, and MySQL-oriented schema statements.
- Keep writes explicit and transactional through `EntityManager::flush()`.
- Avoid raw column and identifier fragments in repository and query-builder APIs where mapped field names can be used.
- Keep schema updates non-destructive by default.
- Offer integration points for SymPress Kernel and Migration without requiring them at runtime.

## Main Components

### EntityManager

`SymPress\Orm\EntityManager` is the primary application API. It owns the unit of work, metadata access, repositories, query creation, events, optional second-level cache, and a normalized database connection.

Typical usage:

```php
$metadataFactory = new MetadataFactory();
$registry = new EntityClassRegistry($metadataFactory, classes: [EmailLog::class]);
$entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), $wpdb);

$log = new EmailLog('log-1', new DateTimeImmutable(), 'queued');
$entityManager->persist($log);
$entityManager->flush();
```

When no `wpdb` instance is passed, the ORM resolves the global `$wpdb` lazily through `WpdbConnection`.

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

`MetadataFactory` reads mapping attributes through reflection and produces immutable `ClassMetadata` graphs. `EntityClassRegistry` knows which entity classes belong to a manager and can discover entities from configured paths. Discovery is prefiltered before tokenization so directories with many PHP files do not cause unnecessary autoloading.

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

The cache layer is intentionally small and uses `CacheInterface`. The package includes `ArrayCache` for tests and simple in-process usage.

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

- Metadata is cached by `MetadataFactory` after the first reflection pass.
- Entity discovery prefilters PHP files before tokenization.
- The identity map returns already managed entities without re-querying.
- `flush()` writes only scheduled or dirty entities.
- Owning many-to-many synchronization uses collection snapshots and skips unchanged collections.
- `Query::toIterable()` currently iterates over the in-memory result set returned by `wpdb`; it is not a streaming cursor.

## Console and Migration Integration

The package provides console commands for schema SQL and migration diffs:

- `SchemaSqlCommand`
- `MigrationDiffCommand`
- `MappingInfoCommand`

`--destructive` enables drop SQL for schema diffs when explicitly requested.

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

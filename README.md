# SymPress ORM

[![Checks](https://img.shields.io/github/actions/workflow/status/SymPress/orm/qa.yml?branch=main&label=checks)](https://github.com/SymPress/orm/actions/workflows/qa.yml) [![Release](https://img.shields.io/packagist/v/sympress/orm.svg?label=release)](https://packagist.org/packages/sympress/orm) [![PHP](https://img.shields.io/packagist/dependency-v/sympress/orm/php.svg?label=php)](https://packagist.org/packages/sympress/orm) [![Downloads](https://img.shields.io/packagist/dt/sympress/orm.svg?label=downloads)](https://packagist.org/packages/sympress/orm/stats) [![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](composer.json)

> **Status:** WIP (Work in Progress)

Doctrine-inspired ORM primitives for WordPress projects that keep `wpdb` as the
database runtime.

The package is intentionally not a full Doctrine ORM replacement. It brings the
parts that are useful in a WordPress package architecture: attribute-based
entity metadata, repositories, a Doctrine-like entity manager, deferred
`flush()` writes, simple query building, schema SQL generation, and optional
integration with `sympress/migration`.

WordPress remains the runtime. Existing plugins, core tables, `$wpdb`, and
WordPress APIs continue to work normally.

## Installation

```bash
composer require sympress/orm
```

When `sympress/kernel` is active, the package is discovered as a library bundle
and registers its services and console commands automatically.

The root project should also require `sympress/migration` when ORM-managed
schema migrations should be registered and executed through the migration
system.

## Features

- Attribute mapping with `#[Entity]`, `#[Table]`, `#[Column]`, `#[Index]`,
  `#[UniqueConstraint]`, `#[MappedSuperclass]`, embeddables, and PHP enums
- Identifier mapping with `#[Id]`
- Generated identifiers with `#[GeneratedValue]`
- Cache mapping with `#[Cache]` on entities and associations
- To-one and to-many association mapping with simple or composite join columns
  and join tables
- Lazy to-many collections
- Cascade persist/remove and owning many-to-many join-table synchronization
- Lifecycle callbacks and event listeners for common ORM lifecycle events
- Entity metadata discovery from active SymPress kernel bundles
- Optional metadata cache
- Doctrine-like `EntityManager`
- Deferred persistence with `persist()` and `flush()`
- `UnitOfWork` with managed entities, original data snapshots, identity map,
  entity states, scheduled insertions, scheduled deletions, and change detection
- CRUD-oriented `Repository`
- `find()`, `findAll()`, `findBy()`, and `findOneBy()`
- Explicit `remove()`, `detach()`, `clear()`, `close()`, `refresh()`, and `contains()`
- Lazy entity references with `getReference()`
- Transaction helper with `transactional()`
- Automatic transaction wrapping during `flush()`
- Version columns, optimistic lock checks, and pessimistic lock helpers
- Deferred implicit and deferred explicit change tracking
- Hydration and extraction for scalar, date, datetime, boolean, JSON, and array
  column values
- Query builder with entity-field-to-column translation, joins, grouping,
  join conditions, grouping, ordering, limits, offsets, object parameters, and
  array parameters
- DQL-style `SELECT`, `UPDATE`, and `DELETE` queries with mapped fields,
  joins, named parameters, positional parameters, expanded array parameters,
  custom DQL functions, and output walkers
- Criteria objects, scalar/array result helpers, iterable results, and optional
  query result caching
- Optional second-level entity and association cache with simple cache regions
- Schema SQL generation for WordPress-friendly `CREATE TABLE` statements
- Live schema update SQL for missing and changed columns/indexes, opt-in
  destructive drops, and missing join tables, including single-table inheritance
  table deduplication
- `dbDelta()` compatibility through generated `CREATE TABLE` SQL
- Mapping validation for identifiers, associations, join metadata, and
  inheritance discriminator metadata
- Optional migration bridge for `sympress/migration`
- Console commands for mapping inspection, schema SQL, and migration class
  generation

## Entity Mapping

```php
<?php

declare(strict_types=1);

namespace App\Mailer\Entity;

use App\Mailer\Repository\EmailLogRepository;
use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\Index;

#[Entity(table: 'sympress_mailer_logs', repositoryClass: EmailLogRepository::class)]
#[Index(name: 'status_created', columns: ['status', 'createdAt'])]
final class EmailLog
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,

        #[Column(type: 'datetime')]
        public \DateTimeImmutable $createdAt,

        #[Column(type: 'string', length: 20)]
        public string $status,

        #[Column(type: 'json', nullable: true)]
        public array $payload = [],
    ) {
    }
}
```

Table names are stored without the WordPress prefix. The ORM adds
`$wpdb->prefix` at runtime, so `sympress_mailer_logs` becomes
`wp_sympress_mailer_logs` on a default install.

If `table` is omitted, the table name is derived from the short class name:
`EmailLog` becomes `email_log`.

If a `#[Column]` name is omitted, the property name is converted to snake case:
`createdAt` becomes `created_at`.

## Supported Column Types

The schema generator maps common Doctrine-style types to MySQL types suitable
for WordPress tables:

- `string`, `varchar`
- `guid`, `uuid`
- `text`, `mediumtext`, `longtext`
- `json`, `array`, `simple_array`
- `smallint`, `integer`, `int`, `bigint`
- `boolean`, `bool`
- `float`, `double`, `decimal`
- `date`, `date_immutable`
- `datetime`, `datetime_immutable`
- `time`, `time_immutable`

Unknown types are passed through as raw SQL type names.

## Entity Manager

The entity manager follows the normal Doctrine workflow: `persist()` schedules
work, and `flush()` writes the changes.

```php
use App\Mailer\Entity\EmailLog;
use SymPress\Orm\EntityManager;

final readonly class MailerService
{
    public function __construct(private EntityManager $entities)
    {
    }

    public function logQueuedMail(string $id, array $payload): void
    {
        $log = new EmailLog(
            $id,
            new \DateTimeImmutable(),
            'queued',
            $payload,
        );

        $this->entities->persist($log);
        $this->entities->flush();
    }
}
```

Calling `persist()` does not immediately call `$wpdb->insert()` or
`$wpdb->update()`. The entity is registered in the `UnitOfWork`. The SQL write
happens during `flush()`.

## Change Tracking

Managed entities are snapshotted when they are persisted, inserted, or hydrated
from a query. Later changes are detected during `flush()`.

```php
$log = $entityManager->find(EmailLog::class, 'log_123');

if ($log === null) {
    return;
}

$log->status = 'sent';

$entityManager->flush();
```

Only changed columns are sent to `$wpdb->update()`. Identifier columns are not
updated.

## Removing Entities

```php
$log = $entityManager->find(EmailLog::class, 'log_123');

if ($log !== null) {
    $entityManager->remove($log);
    $entityManager->flush();
}
```

`remove()` schedules a deletion. The row is deleted during `flush()`.

If a new entity is persisted and then removed before the next flush, the pending
insertion is cancelled.

## Identity Map

The `UnitOfWork` keeps an identity map for managed entities. Repeated
`find()` calls for the same entity class and identifier return the already
managed object when possible.

```php
$first = $entityManager->find(EmailLog::class, 'log_123');
$second = $entityManager->find(EmailLog::class, 'log_123');

assert($first === $second);
```

Use `detach()` to stop tracking one entity or `clear()` to reset all managed
state.

```php
$entityManager->detach($log);
$entityManager->clear();
```

## Repositories

Every entity can use the base `Repository`, or a custom repository can be set on
the entity attribute.

```php
<?php

declare(strict_types=1);

namespace App\Mailer\Repository;

use App\Mailer\Entity\EmailLog;
use SymPress\Orm\Repository;

final class EmailLogRepository extends Repository
{
    /** @return list<EmailLog> */
    public function queued(int $limit = 50): array
    {
        return $this->findBy(
            ['status' => 'queued'],
            ['createdAt' => 'ASC'],
            $limit,
        );
    }
}
```

Resolve repositories through the entity manager:

```php
$logs = $entityManager->getRepository(EmailLog::class);

$one = $logs->find('log_123');
$all = $logs->findAll();
$queued = $logs->findBy(['status' => 'queued']);
$latest = $logs->findOneBy(['status' => 'sent']);

$logs->save($log);
$logs->remove($log);
$entityManager->flush();
```

The repository helpers accept an optional `$flush` argument for common small
workflows:

```php
$logs->save($log, flush: true);
$logs->remove($log, flush: true);
```

## Query Builder

The query builder accepts entity fields and compiles them to prefixed table and
column names.

```php
$query = $entityManager
    ->createQueryBuilder()
    ->select('l')
    ->from(EmailLog::class, 'l')
    ->where('l.status = :status')
    ->setParameter('status', 'queued')
    ->orderBy('l.createdAt', 'ASC')
    ->setMaxResults(50)
    ->getQuery();

$logs = $query->getResult();
```

The generated SQL still goes through `$wpdb->prepare()`.

Supported query builder features:

- `select()`
- `from()`
- `join()`
- `innerJoin()`
- `leftJoin()`
- `where()`
- `andWhere()`
- `orWhere()`
- `groupBy()`
- `addGroupBy()`
- `having()`
- `andHaving()`
- `orderBy()`
- `addOrderBy()`
- `setParameter()`
- `setParameters()`
- `setMaxResults()`
- `setFirstResult()`
- `getQuery()`
- `getSQL()`

## DQL-Style Queries

The ORM includes a pragmatic DQL-style subset for reads and bulk writes:

```php
$query = $entityManager->createQuery(
    'SELECT l FROM EmailLog l WHERE l.status = :status ORDER BY l.createdAt DESC',
    ['status' => 'queued'],
);

$logs = $query->getResult();
```

Supported DQL subset:

- one root entity
- one alias
- `SELECT alias`
- `JOIN`, `INNER JOIN`, and `LEFT JOIN` over mapped to-one, one-to-many, and
  many-to-many associations
- `WHERE` expressions using mapped fields
- named parameters and positional parameters
- `GROUP BY` and `HAVING`
- `ORDER BY` one or more mapped fields
- bulk `UPDATE`
- bulk `DELETE`

Bulk DQL queries can be executed through `execute()`:

```php
$entityManager
    ->createQuery(
        'UPDATE EmailLog l SET l.status = :status WHERE l.id = ?1',
        ['status' => 'sent', 1 => 'log_123'],
    )
    ->execute();
```

This is not a full Doctrine DQL parser. Subqueries, partial object hydration,
full AST walkers, and the complete Doctrine grammar are not part of this package
yet. Lightweight custom DQL functions and SQL output walkers can be registered
on the entity manager.

## Transactions

Use `transactional()` for unit-of-work style transactions.

```php
$entityManager->transactional(function (EntityManager $entities) use ($log): void {
    $entities->persist($log);

    $log->status = 'sent';
});
```

The callback is wrapped in:

- `START TRANSACTION`
- callback execution
- `flush()`
- `COMMIT`

If the callback or flush fails, `ROLLBACK` is executed and the original
exception is rethrown.

## Schema SQL

The `SchemaTool` generates WordPress-friendly table SQL from entity metadata.

```php
$sql = $schemaTool->getUpdateSchemaSql('sympress-mailer-pro');
```

For the example `EmailLog` entity, this produces a `CREATE TABLE` statement
with:

- prefixed table name
- mapped columns
- primary key
- indexes
- `$wpdb->get_charset_collate()`

`CREATE TABLE` statements are compatible with the migration package's
`WordPressSqlExecutor`, which routes them through `dbDelta()`.

## Migration Bridge

When both `sympress/orm` and `sympress/migration` are installed, the ORM bridge
registers schema migrations automatically during the `db_migration_register`
hook.

Each entity manager group receives one generated migration object. Its version
uses a schema hash:

```text
schema:<hash>
```

When entity metadata changes, the schema hash changes. The migration package
treats the changed hash as pending, so the generated schema migration can run
again.

The bridge is optional:

- `sympress/orm` can run without `sympress/migration`
- `sympress/migration` can run without `sympress/orm`
- when both are installed, they cooperate through the bridge

## Console Commands

The package exposes commands through the SymPress kernel console integration.

```bash
bin/console orm:mapping:info
bin/console orm:mapping:info --manager=sympress-mailer-pro

bin/console orm:schema:sql
bin/console orm:schema:sql sympress-mailer-pro
bin/console orm:schema:sql sympress-mailer-pro --drop

bin/console orm:migrations:diff sympress-mailer-pro \
    --namespace='App\Migration' \
    --path='packages/app/src/Migration'
```

`orm:migrations:diff` generates a concrete `AbstractMigration` class containing
the current SQL statements. Use this when you want explicit, reviewable
migration files instead of only dynamic schema migrations.

## WordPress Compatibility Rules

The ORM is built for custom package tables, not for replacing WordPress core
APIs.

Recommended rules:

- Use WordPress APIs for core tables such as posts, users, terms, options, and
  comments.
- Use ORM entities for your own plugin, package, or application tables.
- Do not replace the global `$wpdb`.
- Do not bypass WordPress capabilities, nonces, sanitization, or escaping.
- Avoid hard foreign keys to WordPress core tables.
- Keep table names prefix-aware by omitting `$wpdb->prefix` in `#[Entity]`.
- Use migrations for schema changes instead of ad-hoc runtime table changes.

## Current Limitations

The package is intentionally small and pragmatic. These features are not part
of the current implementation:

- lazy subclass proxies for to-one associations
- inheritance mapping beyond single-table inheritance, such as joined/class-table
  inheritance and table-per-class inheritance
- Doctrine-style cache concurrency strategies beyond simple cache regions
- complete Doctrine DQL grammar and full AST walkers
- cross-vendor platform abstraction beyond the current MySQL/WordPress target

These can be added incrementally without changing the basic entity manager and
repository workflow.

## Testing

```bash
composer install
composer tests
```

In the root project, the package can also be tested directly:

```bash
ddev exec bash -lc 'cd packages/orm && composer install --no-scripts'
ddev exec bash -lc 'cd packages/orm && vendor/bin/phpunit --configuration phpunit.xml.dist --no-coverage'
```

Local `vendor/` and PHPUnit cache directories are ignored by the package
`.gitignore`.

## License

This package is licensed under `GPL-2.0-or-later`.

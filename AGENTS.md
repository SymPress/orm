# SymPress ORM

Doctrine-inspired ORM primitives that keep WordPress `wpdb` as the database
runtime. Read `docs/api-and-architecture.md` before changing persistence,
mapping, query compilation, or schema behavior.

## Key paths

- `src/Metadata/`: attributes and metadata validation.
- `src/Query/`: DQL subset, query builder, placeholders, and hydration metadata.
- `src/Dbal/`: the `wpdb` adapter and SQL platform.
- `src/UnitOfWork.php` and `src/EntityManager.php`: write lifecycle and identity map.
- `tests/Integration/OrmWorkflowTest.php`: broad persistence contract.

## Verification

- Focused: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter <TestName>`
- Full: `composer qa`

Add a focused regression test for parser, metadata, cache, or database failure
paths before changing them. Keep PHPUnit warnings and risky tests fatal.

## Invariants

- `flush()` is the only point that sends scheduled writes to the database.
- Query values must remain `wpdb` parameters; only validated identifiers may be
  interpolated into SQL.
- Unsupported DQL and missing parameters must fail explicitly, never broaden a
  query silently.
- Preserve transaction nesting and surface an unavailable `wpdb` runtime.
- Schema changes must not become destructive without an explicit caller choice.

## Cross-repository impact

- `sympress/kernel` discovers `OrmBundle` through `composer.json`.
- `sympress/migration` consumes the bridge in `src/Bridge/Migration/`.
- Public mapping, query, and entity-manager changes require a consumer search
  before release.

## Definition of done

The smallest relevant test passes, `composer qa` passes, documentation matches
the supported DQL subset, and no generated/vendor files are committed.

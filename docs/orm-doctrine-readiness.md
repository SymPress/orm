# ORM Doctrine Readiness

Stand: 2026-06-13. Ziel ist keine 1:1-Kopie von `doctrine/orm`, sondern ein Doctrine-kompatibles Arbeitsmodell fuer SymPress/WordPress: Attribute Mapping, Unit of Work, Repository, DQL-Grundlagen, Associations, Lifecycle, Schema-Tooling und optionale Cache-Pfade.

## Readiness Matrix

| Prio | Feature | Status | Implementiert | Konkrete Akzeptanztests |
| --- | --- | --- | --- | --- |
| P0 | Entity Metadata und Attribute | Ready | `#[Entity]`, `#[Table]`, `#[Column]`, `#[Id]`, `#[GeneratedValue]`, Indizes, Unique Constraints, Embeddables, Enums | MetadataFactory liest Spalten, Identifier und Indexnamen aus `EmailLog`; MappingValidator meldet fehlende Identifier/Targets. |
| P0 | EntityManager und UnitOfWork | Ready | Identity Map, Entity States, deferred `persist/remove`, dirty checking, explicit change tracking, `flush()`-Transaktion | Persist schreibt erst bei `flush`; geaenderte Managed Entities erzeugen `UPDATE`; `DEFERRED_EXPLICIT` schreibt erst nach erneutem `persist`. |
| P0 | CRUD Repository | Ready | `find`, `findAll`, `findBy`, `findOneBy`, Criteria-Support | Repository nutzt gemappte Spalten, Parameter und Limits; QueryBuilder-Akzeptanztests pruefen SQL/Parameter. |
| P0 | Associations | Ready | ManyToOne, OneToOne, OneToMany, ManyToMany, JoinColumn, JoinTable, Lazy Collections, Cascade, Orphan Removal | Many-to-many Flush synchronisiert Join-Tabelle; Composite-FK Join kompiliert alle JoinColumns. |
| P0 | Composite Identifier/FKs | Ready | Composite IDs, Composite JoinColumns, Composite ManyToMany Join Tables | QueryBuilder erzeugt `tenant_id` und `tenant_code` Join-Bedingungen; `find/getReference` akzeptiert Identifier-Arrays. |
| P1 | DQL und QueryBuilder | Ready fuer Kernpfade | SELECT/UPDATE/DELETE, Joins, WITH, GROUP BY, HAVING, ORDER BY, IN-Arrays, Objektparameter, native Queries | DQL UPDATE/DELETE wird in wpdb SQL ausgefuehrt; Array-Parameter expandieren; registrierte DQL Function kompiliert in SQL. |
| P1 | Lifecycle und Events | Ready | Lifecycle callbacks, Entity listeners, EventManager, `onFlush`, `onClear`, PreUpdate args | Lifecycle-Tests pruefen PrePersist/PostLoad; Flush dispatcht `onFlush`; Clear dispatcht `onClear`. |
| P1 | Locking und Versioning | Ready | `#[Version]`, optimistic lock check, pessimistic read/write helper in Transaktion | Versioned Update wirft `OptimisticLockException`, wenn `UPDATE` keine Zeile trifft. |
| P1 | Schema Tool | Ready fuer WordPress/MySQL | CREATE/DROP/UPDATE SQL, changed/removed columns, changed/removed indexes, Join Tables, STI Table-Dedupe | SchemaTool erzeugt eine Single-Table-Inheritance Tabelle fuer Root und Subclass; Update-SQL erkennt fehlende/geaenderte/entfernte Spalten und Indizes. |
| P1 | Hydration und Type Conversion | Ready | Constructor Hydration, Property Assignment, Date/DateTime, Bool, JSON/Array, Enums, lazy References | Hydrator wandelt `datetime` nach `DateTimeImmutable`; STI Row mit Discriminator `dog` hydratisiert als `Dog`. |
| P2 | Inheritance | Ready fuer Single Table | `#[InheritanceType(SINGLE_TABLE)]`, `#[DiscriminatorColumn]`, `#[DiscriminatorMap]`, Subclass Hydration/Extraction | Dog-Metadata nutzt Root-Tabelle, Subclass-Spalte und Discriminator Value; Root-Hydration erzeugt Subclass-Objekt. |
| P2 | DQL-Erweiterbarkeit | Ready | Custom DQL Functions via `registerDqlFunction`, SQL Output Walkers via `addOutputWalker` | `DATE_YEAR(l.createdAt)` kompiliert zu `YEAR(l.created_at)`; Walker haengt Trace-Kommentar an SQL. |
| P2 | Query Result Cache | Ready | Per Query `useResultCache()` mit stabilem SQL/Parameter-Key | Wiederholtes `getResult()` liest Rows aus Cache, bis anderer Key oder Clear verwendet wird. |
| P2 | Second Level Cache | Ready fuer einfache Regionen | Entity Cache, `#[Cache]` an Entity/Association, Association Collection Cache, Regionsversionen, DML-Eviction | `find()` liest nach `clear()` aus Cache; DQL UPDATE leert Cache; Association Cache wird nach Target Write invalidiert. |
| P2 | Extra Lazy Collections | Ready fuer Count/Empty | `PersistentCollection::count()` und `isEmpty()` nutzen Count-Loader ohne Full Initialize | Extra-lazy Count ruft `COUNT(*)` statt Collection-Hydration. |

## Bewusste Restgrenzen

- DQL ist bewusst ein Kernsubset. Vollstaendige Doctrine-DQL-Grammatik, Subselects, CASE-Ausdruecke, komplexe verschachtelte Functions und AST-Walker sind nicht vollstaendig implementiert.
- Inheritance ist fuer Single Table abgedeckt. Joined/Class Table Inheritance bleiben bewusst ausserhalb der aktuellen P0-P2-Readiness.
- Schema-Diffing ist MySQL/WordPress-fokussiert und kein plattformuebergreifender Doctrine DBAL Comparator.
- Second Level Cache nutzt einfache Regionsversionen ueber das kleine `CacheInterface`; es gibt keine verteilten Locking-Strategien oder Cache-Concurrency-Provider wie in Doctrine.

## Definition of Done pro Feature

- Mapping: Attribute werden per Reflection gelesen, gecacht und vom Validator geprueft.
- Persistence: Aenderungen bleiben bis `flush()` im UnitOfWork und werden in einer Transaktion geschrieben.
- Queries: SQL nutzt WordPress Prefix, gemappte Spaltennamen und vorbereitete Parameter.
- Associations: Owning/Inverse Seiten koennen lazy geladen, gezaehlt und synchronisiert werden.
- Cache: Reads koennen aus Entity-/Association-Regionen kommen; alle ORM-DML-Pfade invalidieren stale Regionen.
- Schema: CREATE/UPDATE/DROP SQL ist deterministisch und kann fuer Migration-Diffs verwendet werden.

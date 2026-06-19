<?php

declare(strict_types=1);

namespace SymPress\Orm;

use SymPress\Orm\Collection\Collection;
use SymPress\Orm\Collection\PersistentCollection;
use SymPress\Orm\Cache\CacheInterface;
use SymPress\Orm\Dbal\ConnectionProvider;
use SymPress\Orm\Dbal\ConnectionInterface;
use SymPress\Orm\Exception\EntityManagerClosedException;
use SymPress\Orm\Exception\OptimisticLockException;
use SymPress\Orm\Exception\PessimisticLockException;
use SymPress\Orm\Event\EventManager;
use SymPress\Orm\Event\Events;
use SymPress\Orm\Event\LifecycleEventArgs;
use SymPress\Orm\Event\OnClearEventArgs;
use SymPress\Orm\Event\OnFlushEventArgs;
use SymPress\Orm\Event\PreUpdateEventArgs;
use SymPress\Orm\Metadata\AssociationMetadata;
use SymPress\Orm\Metadata\ClassMetadata;
use SymPress\Orm\Metadata\ColumnMetadata;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\JoinColumnMetadata;
use SymPress\Orm\Metadata\JoinTableMetadata;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Query\CompiledQuery;
use SymPress\Orm\Query\DqlCompiler;
use SymPress\Orm\Query\DqlExtensionRegistry;
use SymPress\Orm\Query\Query;
use SymPress\Orm\Query\QueryBuilder;

final class EntityManager
{
    /** @var array<class-string, Repository> */
    private array $repositories = [];

    private UnitOfWork $unitOfWork;

    private ConnectionProvider $connections;

    private EventManager $events;

    private ?CacheInterface $secondLevelCache;

    /** @var array<string, true> */
    private array $cascadeStack = [];

    /** @var array<class-string, object> */
    private array $entityListenerInstances = [];

    private DqlExtensionRegistry $dqlExtensions;

    /** @var array<string, int> */
    private array $cacheRegionVersions = [];

    private bool $closed = false;

    public function __construct(
        private readonly MetadataFactory $metadataFactory,
        private readonly EntityClassRegistry $entities,
        private readonly EntityHydrator $hydrator,
        ConnectionInterface|\wpdb|null $database = null,
        ?UnitOfWork $unitOfWork = null,
        ?EventManager $events = null,
        ?CacheInterface $secondLevelCache = null,
        ?ConnectionProvider $connectionProvider = null,
        ?DqlExtensionRegistry $dqlExtensions = null,
    ) {

        $this->unitOfWork = $unitOfWork ?? new UnitOfWork();
        $this->connections = $connectionProvider ?? ConnectionProvider::fromDatabase($database);
        $this->events = $events ?? new EventManager();
        $this->secondLevelCache = $secondLevelCache;
        $this->dqlExtensions = $dqlExtensions ?? new DqlExtensionRegistry();
    }

    /** @param class-string $entityClass */
    public function getClassMetadata(string $entityClass): ClassMetadata
    {
        $this->assertOpen();

        return $this->metadataFactory->getMetadataFor($entityClass);
    }

    /** @param class-string $entityClass */
    public function getRepository(string $entityClass): Repository
    {
        $this->assertOpen();

        if (isset($this->repositories[$entityClass])) {
            return $this->repositories[$entityClass];
        }

        $metadata = $this->getClassMetadata($entityClass);
        $repositoryClass = $metadata->repositoryClass ?? Repository::class;
        $repository = new $repositoryClass($this, $metadata);

        if (!$repository instanceof Repository) {
            throw new \RuntimeException(sprintf(
                'Repository "%s" for "%s" must extend %s.',
                $repositoryClass,
                $entityClass,
                Repository::class,
            ));
        }

        return $this->repositories[$entityClass] = $repository;
    }

    /** @param class-string $entityClass */
    public function find(string $entityClass, mixed $id): ?object
    {
        $this->assertOpen();

        $managed = $this->unitOfWork->tryGetById($entityClass, $id);

        if ($managed !== null) {
            return $managed;
        }

        $metadata = $this->getClassMetadata($entityClass);
        $cached = $this->secondLevelCache?->get($this->entityCacheKey($metadata, $id));

        if (is_array($cached)) {
            return $this->registerManaged($this->hydrator->hydrate($metadata, $cached, $this), $metadata, $cached);
        }

        return $this->getRepository($entityClass)->find($id);
    }

    public function persist(object $entity): void
    {
        $this->assertOpen();

        $metadata = $this->getClassMetadata($entity::class);

        if ($metadata->readOnly) {
            throw new \LogicException(sprintf('Entity "%s" is read-only.', $metadata->className));
        }

        if ($this->unitOfWork->contains($entity)) {
            $this->unitOfWork->scheduleExplicitUpdate($entity);
            $this->cascade($entity, $metadata, 'persist');
            return;
        }

        $data = $this->hydrator->extract($entity, $metadata, $this);
        $id = $this->identifierFromData($metadata, $data);

        if ($id !== null && $this->exists($metadata, $id)) {
            $this->unitOfWork->persistExisting($entity, $metadata, $data);
            $this->cascade($entity, $metadata, 'persist');
            return;
        }

        $this->dispatchLifecycleEvent(Events::PRE_PERSIST, $entity, $metadata);
        $this->unitOfWork->persistNew($entity, $metadata, $this->hydrator->extract($entity, $metadata, $this));
        $this->cascade($entity, $metadata, 'persist');
    }

    public function remove(object $entity): void
    {
        $this->assertOpen();

        $metadata = $this->getClassMetadata($entity::class);
        $this->dispatchLifecycleEvent(Events::PRE_REMOVE, $entity, $metadata);
        $this->cascade($entity, $metadata, 'remove');
        $this->unitOfWork->remove($entity);
    }

    public function flush(): void
    {
        $this->assertOpen();

        $startedTransaction = !$this->connection()->isTransactionActive();

        if ($startedTransaction) {
            $this->connection()->beginTransaction();
        }

        try {
            foreach ($this->unitOfWork->managedEntities() as $entity) {
                $this->dispatchLifecycleEvent(Events::PRE_FLUSH, $entity, $this->getClassMetadata($entity::class));
            }

            $this->events->dispatch(Events::ON_FLUSH, new OnFlushEventArgs($this));
            $this->scheduleOrphanRemovals();
            $scheduledUpdates = $this->unitOfWork->scheduledUpdates($this->hydrator, $this);
            $newEntityIds = [];

            foreach ($this->unitOfWork->scheduledInsertions() as $entity) {
                $newEntityIds[spl_object_id($entity)] = true;
            }

            foreach ($this->unitOfWork->scheduledInsertions() as $entity) {
                $metadata = $this->getClassMetadata($entity::class);
                $data = $this->insertEntity($entity, $metadata);
                $this->unitOfWork->markFlushed($entity, $metadata, $data);
                $this->cacheEntity($metadata, $data);
                $this->bumpAssociationRegionsFor($metadata);
                $this->dispatchLifecycleEvent(Events::POST_PERSIST, $entity, $metadata);
            }

            foreach ($scheduledUpdates as $update) {
                $this->dispatchLifecycleEvent(Events::PRE_UPDATE, $update['entity'], $update['metadata'], $update['changes']);
                $this->updateEntity($update['entity'], $update['metadata'], $update['changes'], $update['original']);
                $this->unitOfWork->markFlushed(
                    $update['entity'],
                    $update['metadata'],
                    $this->hydrator->extract($update['entity'], $update['metadata'], $this),
                );
                $this->cacheEntity($update['metadata'], $this->hydrator->extract($update['entity'], $update['metadata'], $this));
                $this->bumpAssociationRegionsFor($update['metadata']);
                $this->dispatchLifecycleEvent(Events::POST_UPDATE, $update['entity'], $update['metadata'], $update['changes']);
            }

            $scheduledDeletions = $this->unitOfWork->scheduledDeletions();
            $deletions = [];

            foreach ($scheduledDeletions as $entity) {
                $deletions[spl_object_id($entity)] = true;
            }

            foreach ($this->unitOfWork->managedEntities() as $entity) {
                if (isset($deletions[spl_object_id($entity)])) {
                    continue;
                }

                $this->synchronizeOwningManyToMany(
                    $entity,
                    $this->getClassMetadata($entity::class),
                    isset($newEntityIds[spl_object_id($entity)]),
                );
            }

            foreach ($scheduledDeletions as $entity) {
                $metadata = $this->getClassMetadata($entity::class);
                $this->deleteOwningManyToManyRows($entity, $metadata);
                $this->evictEntity($metadata, $this->identifierWhere($entity, $metadata));
                $this->deleteEntity($entity, $metadata);
                $this->bumpAssociationRegionsFor($metadata);
                $this->unitOfWork->markRemoved($entity);
                $this->dispatchLifecycleEvent(Events::POST_REMOVE, $entity, $metadata);
            }

            foreach ($this->unitOfWork->managedEntities() as $entity) {
                $this->dispatchLifecycleEvent(Events::POST_FLUSH, $entity, $this->getClassMetadata($entity::class));
            }

            if ($startedTransaction) {
                $this->connection()->commit();
            }
        } catch (\Throwable $throwable) {
            if ($startedTransaction) {
                $this->connection()->rollBack();
            }

            $this->closed = true;

            throw $throwable;
        }
    }

    /** @param class-string|null $entityClass */
    public function clear(?string $entityClass = null): void
    {
        $this->unitOfWork->clear($entityClass);
        $this->events->dispatch(Events::ON_CLEAR, new OnClearEventArgs($this, $entityClass));
    }

    public function detach(object $entity): void
    {
        $this->unitOfWork->detach($entity);
    }

    public function contains(object $entity): bool
    {
        return $this->unitOfWork->contains($entity);
    }

    public function getEntityState(object $entity, EntityState $default = EntityState::DETACHED): EntityState
    {
        $state = $this->unitOfWork->entityState($entity, null);

        if ($state instanceof EntityState) {
            return $state;
        }

        try {
            $metadata = $this->getClassMetadata($entity::class);
            $identifier = $this->identifierFromData($metadata, $this->hydrator->extract($entity, $metadata, $this));

            if ($identifier === null) {
                return EntityState::NEW;
            }

            return $this->exists($metadata, $identifier) ? EntityState::DETACHED : EntityState::NEW;
        } catch (\Throwable) {
            return $default;
        }
    }

    public function close(): void
    {
        $this->clear();
        $this->closed = true;
    }

    public function isOpen(): bool
    {
        return !$this->closed;
    }

    public function refresh(object $entity): void
    {
        $this->assertOpen();

        if (!$this->contains($entity)) {
            throw new \InvalidArgumentException(sprintf('Entity "%s" is not managed.', $entity::class));
        }

        $metadata = $this->getClassMetadata($entity::class);
        $row = $this->fetchRowByIdentifier($metadata, $this->identifierWhere($entity, $metadata));

        if ($row === null) {
            throw new \RuntimeException(sprintf('Cannot refresh "%s": row no longer exists.', $metadata->className));
        }

        $fresh = $this->hydrator->hydrate($metadata, $row, $this);
        $this->copyEntityState($fresh, $entity);
        $this->unitOfWork->markFlushed($entity, $metadata, $this->hydrator->extract($entity, $metadata, $this));
        $this->dispatchLifecycleEvent(Events::POST_LOAD, $entity, $metadata);
    }

    /** @param class-string $entityClass */
    public function getReference(string $entityClass, mixed $id): object
    {
        $this->assertOpen();

        $managed = $this->unitOfWork->tryGetById($entityClass, $id);

        if ($managed !== null) {
            return $managed;
        }

        $metadata = $this->getClassMetadata($entityClass);
        $reflection = new \ReflectionClass($entityClass);
        $entity = $reflection->newInstanceWithoutConstructor();
        $identifierData = $this->normalizeIdentifierData($metadata, $id);

        foreach ($metadata->columns() as $column) {
            if (!array_key_exists($column->columnName, $identifierData)) {
                continue;
            }

            $this->hydrator->assign($entity, $column->propertyName, $identifierData[$column->columnName]);
        }

        $data = $this->hydrator->extract($entity, $metadata, $this);

        return $this->unitOfWork->registerManaged($entity, $metadata, $data);
    }

    public function lock(object $entity, LockMode $lockMode, mixed $lockVersion = null): void
    {
        $this->assertOpen();

        $metadata = $this->getClassMetadata($entity::class);

        if ($lockMode === LockMode::OPTIMISTIC) {
            $version = $metadata->versionColumn();

            if (!$version instanceof ColumnMetadata) {
                throw OptimisticLockException::notVersioned($entity);
            }

            $data = $this->hydrator->extract($entity, $metadata, $this);
            $actual = $data[$version->columnName] ?? null;

            if ($lockVersion !== null && (string) $actual !== (string) $lockVersion) {
                throw OptimisticLockException::versionMismatch($entity, $lockVersion, $actual);
            }

            return;
        }

        if (in_array($lockMode, [LockMode::PESSIMISTIC_READ, LockMode::PESSIMISTIC_WRITE], true)) {
            if (!$this->connection()->isTransactionActive()) {
                throw PessimisticLockException::transactionRequired();
            }

            $this->executePessimisticLock($entity, $metadata, $lockMode);
        }
    }

    /** @param array<string, mixed> $row */
    public function registerManaged(object $entity, ClassMetadata $metadata, array $row): object
    {
        $metadata = $this->getClassMetadata($entity::class);
        $managed = $this->unitOfWork->registerManaged(
            $entity,
            $metadata,
            $this->hydrator->extract($entity, $metadata, $this),
        );

        if ($managed !== $entity) {
            $this->copyEntityState($entity, $managed);
            $this->unitOfWork->markFlushed($managed, $metadata, $this->hydrator->extract($managed, $metadata, $this));
        }

        $managedMetadata = $this->getClassMetadata($managed::class);
        $this->dispatchLifecycleEvent(Events::POST_LOAD, $managed, $managedMetadata);
        $this->cacheEntity($managedMetadata, $this->hydrator->extract($managed, $managedMetadata, $this));

        return $managed;
    }

    public function transactional(callable $callback): mixed
    {
        $this->assertOpen();

        $this->connection()->beginTransaction();

        try {
            $result = $callback($this);
            $this->flush();
            $this->connection()->commit();

            return $result;
        } catch (\Throwable $throwable) {
            $this->connection()->rollBack();

            throw $throwable;
        }
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return $this->unitOfWork;
    }

    public function getEventManager(): EventManager
    {
        return $this->events;
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    public function createNativeQuery(CompiledQuery $query): Query
    {
        if ($this->dqlExtensions->hasOutputWalkers()) {
            $query = new CompiledQuery(
                $this->dqlExtensions->applyOutputWalkers($query->sql),
                $query->parameters,
                $query->resultMetadata,
            );
        }

        return new Query($this->connection(), $this->hydrator, $query, $this);
    }

    /** @param array<string|int, mixed> $parameters */
    public function createQuery(string $dql, array $parameters = []): Query
    {
        $compiled = $this->compileDql($dql, $parameters);

        if ($compiled instanceof QueryBuilder) {
            return $compiled->getQuery();
        }

        return $this->createNativeQuery($compiled);
    }

    public function tablePrefix(): string
    {
        return $this->connection()->tablePrefix();
    }

    public function tableName(string $tableName): string
    {
        $prefix = $this->tablePrefix();

        if ($prefix === '' || str_starts_with($tableName, $prefix)) {
            return $tableName;
        }

        return $prefix . $tableName;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->connection()->platform()->quoteIdentifier($identifier);
    }

    public function parameterPlaceholder(mixed $value): string
    {
        return $this->connection()->platform()->parameterPlaceholder($value);
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection();
    }

    public function registerDqlFunction(string $name, callable $compiler): void
    {
        $this->dqlExtensions->registerFunction($name, $compiler);
    }

    public function addOutputWalker(callable $walker): void
    {
        $this->dqlExtensions->addOutputWalker($walker);
    }

    public function compileDqlFunctions(string $expression): string
    {
        return $this->dqlExtensions->compileFunctions($expression);
    }

    public function applyOutputWalkers(string $sql): string
    {
        return $this->dqlExtensions->applyOutputWalkers($sql);
    }

    /** @param class-string|null $className */
    public function evictSecondLevelCache(?string $className = null): void
    {
        if (!$this->secondLevelCache instanceof CacheInterface) {
            return;
        }

        if ($className === null) {
            $this->secondLevelCache->clear();
            $this->cacheRegionVersions = [];
            return;
        }

        $metadata = $this->getClassMetadata($className);
        $this->bumpEntityRegion($metadata);
        $this->bumpAssociationRegionsFor($metadata);
    }

    public function getIdentifierValue(object $entity): mixed
    {
        return $this->identifierValue($entity, $this->getClassMetadata($entity::class));
    }

    /** @return list<object> */
    public function loadAssociationCollection(object $owner, AssociationMetadata $association): array
    {
        $ownerMetadata = $this->getClassMetadata($owner::class);
        $cached = $this->cachedAssociationCollection($owner, $ownerMetadata, $association);

        if ($cached !== null) {
            return $cached;
        }

        $results = match ($association->type) {
            AssociationMetadata::ONE_TO_MANY => $this->loadOneToMany($owner, $association),
            AssociationMetadata::MANY_TO_MANY => $this->loadManyToMany($owner, $association),
            default => [],
        };

        $this->cacheAssociationCollection($owner, $ownerMetadata, $association, $results);

        return $results;
    }

    public function countAssociationCollection(object $owner, AssociationMetadata $association): int
    {
        return match ($association->type) {
            AssociationMetadata::ONE_TO_MANY => $this->countOneToMany($owner, $association),
            AssociationMetadata::MANY_TO_MANY => $this->countManyToMany($owner, $association),
            default => 0,
        };
    }

    /** @param array<string, mixed> $identifier */
    private function exists(ClassMetadata $metadata, array $identifier): bool
    {
        if ($identifier === []) {
            return false;
        }

        $predicates = [];
        $parameters = [];

        foreach ($identifier as $column => $value) {
            $predicates[] = sprintf('%s = %s', $this->quoteIdentifier($column), $this->parameterPlaceholder($value));
            $parameters[] = $value;
        }

        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s',
            $this->quoteIdentifier($metadata->tableName($this->tablePrefix())),
            implode(' AND ', $predicates),
        );

        return (int) $this->connection()->fetchOne($sql, ...$parameters) > 0;
    }

    /** @return array<string, mixed> */
    private function insertEntity(object $entity, ClassMetadata $metadata): array
    {
        $data = $this->hydrator->extract($entity, $metadata, $this);
        $identifier = $metadata->identifierColumn();
        $version = $metadata->versionColumn();

        if (
            $identifier !== null
            && $identifier->generated
            && in_array($data[$identifier->columnName] ?? null, [null, '', 0, '0'], true)
        ) {
            unset($data[$identifier->columnName]);
        }

        if ($version instanceof ColumnMetadata && in_array($data[$version->columnName] ?? null, [null, '', 0, '0'], true)) {
            $this->hydrator->assign($entity, $version->propertyName, 1);
            $data[$version->columnName] = 1;
        }

        $result = $this->connection()->insert($metadata->tableName($this->tablePrefix()), $data);

        if ($result === false) {
            throw new \RuntimeException(sprintf('Failed to insert "%s".', $metadata->className));
        }

        if ($identifier !== null && $identifier->generated) {
            $insertId = $this->connection()->lastInsertId();

            if ($insertId !== null && $insertId !== 0 && $insertId !== '0') {
                $this->hydrator->assign($entity, $identifier->propertyName, (int) $insertId);
            }
        }

        return $this->hydrator->extract($entity, $metadata, $this);
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $original
     */
    private function updateEntity(object $entity, ClassMetadata $metadata, array $changes, array $original): void
    {
        $identifiers = $metadata->identifierColumns();

        if ($identifiers === []) {
            throw new \LogicException(sprintf('Entity "%s" has no identifier column.', $metadata->className));
        }

        foreach ($identifiers as $identifier) {
            unset($changes[$identifier->columnName]);
        }

        $where = $this->identifierWhere($entity, $metadata);
        $version = $metadata->versionColumn();

        if ($version instanceof ColumnMetadata) {
            $where[$version->columnName] = $original[$version->columnName] ?? null;
            $nextVersion = (int) ($original[$version->columnName] ?? 0) + 1;
            $changes[$version->columnName] = $nextVersion;
        }

        if ($changes === []) {
            return;
        }

        $result = $this->connection()->update(
            $metadata->tableName($this->tablePrefix()),
            $changes,
            $where,
        );

        if ($result === false) {
            throw new \RuntimeException(sprintf('Failed to update "%s".', $metadata->className));
        }

        if ($version instanceof ColumnMetadata && $result === 0) {
            throw OptimisticLockException::lockFailed($entity);
        }

        if ($version instanceof ColumnMetadata) {
            $this->hydrator->assign($entity, $version->propertyName, $nextVersion);
        }
    }

    private function deleteEntity(object $entity, ClassMetadata $metadata): void
    {
        $where = $this->identifierWhere($entity, $metadata);

        if ($where === []) {
            return;
        }

        $result = $this->connection()->delete(
            $metadata->tableName($this->tablePrefix()),
            $where,
        );

        if ($result === false) {
            throw new \RuntimeException(sprintf('Failed to delete "%s".', $metadata->className));
        }
    }

    private function synchronizeOwningManyToMany(object $entity, ClassMetadata $metadata, bool $newEntity = false): void
    {
        foreach ($metadata->associations() as $association) {
            if ($association->type !== AssociationMetadata::MANY_TO_MANY || !$association->isOwningSide()) {
                continue;
            }

            $value = $this->propertyValue($entity, $association->propertyName);

            if ($value instanceof PersistentCollection && !$value->isInitialized()) {
                continue;
            }

            if (!$value instanceof Collection && !is_array($value)) {
                continue;
            }

            $joinTable = $association->joinTable;

            if (!$joinTable instanceof JoinTableMetadata) {
                continue;
            }

            if ($this->joinTableWhere($entity, $metadata, $joinTable->joinColumns) === []) {
                continue;
            }

            $targetMetadata = $this->getClassMetadata($association->targetEntity);
            $currentIdentifiers = $this->collectionIdentifierKeys($value, $targetMetadata);
            $hasSnapshot = $this->unitOfWork->hasCollectionSnapshot($entity, $association->propertyName);
            $snapshot = $this->unitOfWork->collectionSnapshot($entity, $association->propertyName);

            if (
                !$newEntity
                && $hasSnapshot
                && $this->sameIdentifierKeys($snapshot, $currentIdentifiers)
            ) {
                continue;
            }

            if (!$newEntity && !$hasSnapshot && $currentIdentifiers === []) {
                $this->unitOfWork->setCollectionSnapshot($entity, $association->propertyName, []);
                continue;
            }

            if (!$newEntity) {
                $this->deleteManyToManyRows($entity, $metadata, $association);
            }

            foreach ($value as $target) {
                if (!is_object($target)) {
                    continue;
                }

                $row = $this->joinTableRow($entity, $metadata, $target, $targetMetadata, $association);

                if ($row === []) {
                    continue;
                }

                $result = $this->connection()->insert($this->tableName($joinTable->name), $row);

                if ($result === false) {
                    throw new \RuntimeException(sprintf('Failed to synchronize many-to-many association "%s".', $association->propertyName));
                }
            }

            $this->unitOfWork->setCollectionSnapshot($entity, $association->propertyName, $currentIdentifiers);
        }
    }

    private function deleteOwningManyToManyRows(object $entity, ClassMetadata $metadata): void
    {
        foreach ($metadata->associations() as $association) {
            if ($association->type === AssociationMetadata::MANY_TO_MANY && $association->isOwningSide()) {
                $this->deleteManyToManyRows($entity, $metadata, $association);
            }
        }
    }

    private function deleteManyToManyRows(object $entity, ClassMetadata $metadata, AssociationMetadata $association): void
    {
        $joinTable = $association->joinTable;

        if (!$joinTable instanceof JoinTableMetadata) {
            return;
        }

        $where = $this->joinTableWhere($entity, $metadata, $joinTable->joinColumns);

        if ($where === []) {
            return;
        }

        $result = $this->connection()->delete($this->tableName($joinTable->name), $where);

        if ($result === false) {
            throw new \RuntimeException(sprintf('Failed to clear many-to-many association "%s".', $association->propertyName));
        }

        $this->bumpAssociationRegion($metadata, $association);
    }

    /**
     * @param iterable<mixed> $collection
     * @return list<string>
     */
    private function collectionIdentifierKeys(iterable $collection, ClassMetadata $targetMetadata): array
    {
        $keys = [];

        foreach ($collection as $target) {
            if (!is_object($target)) {
                continue;
            }

            $identifier = $this->identifierValue($target, $targetMetadata);

            if ($identifier !== null) {
                $keys[] = $this->identifierValueKey($identifier);
            }
        }

        sort($keys);

        return array_values(array_unique($keys));
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function sameIdentifierKeys(array $left, array $right): bool
    {
        sort($left);
        sort($right);

        return $left === $right;
    }

    private function referencedColumnValue(object $entity, ClassMetadata $metadata, string $columnName): mixed
    {
        return $this->hydrator->extract($entity, $metadata, $this)[$columnName] ?? null;
    }

    /**
     * @param list<JoinColumnMetadata> $joinColumns
     * @return array<string, mixed>
     */
    private function joinTableWhere(object $entity, ClassMetadata $metadata, array $joinColumns): array
    {
        $where = [];

        foreach ($joinColumns as $joinColumn) {
            $value = $this->referencedColumnValue($entity, $metadata, $joinColumn->referencedColumnName);

            if ($value === null || $value === '') {
                return [];
            }

            $where[$joinColumn->name] = $value;
        }

        return $where;
    }

    /** @return array<string, mixed> */
    private function joinTableRow(
        object $source,
        ClassMetadata $sourceMetadata,
        object $target,
        ClassMetadata $targetMetadata,
        AssociationMetadata $association,
    ): array {

        $joinTable = $association->joinTable;

        if (!$joinTable instanceof JoinTableMetadata) {
            return [];
        }

        return [
            ...$this->joinTableWhere($source, $sourceMetadata, $joinTable->joinColumns),
            ...$this->joinTableWhere($target, $targetMetadata, $joinTable->inverseJoinColumns),
        ];
    }

    /** @return array<string, mixed> */
    private function identifierWhere(object $entity, ClassMetadata $metadata): array
    {
        $identifiers = $metadata->identifierColumns();

        if ($identifiers === []) {
            throw new \LogicException(sprintf('Entity "%s" has no identifier column.', $metadata->className));
        }

        $data = $this->hydrator->extract($entity, $metadata, $this);
        $where = [];

        foreach ($identifiers as $identifier) {
            $id = $data[$identifier->columnName] ?? null;

            if ($id === null || $id === '') {
                return [];
            }

            $where[$identifier->columnName] = $id;
        }

        return $where;
    }

    /** @param array<string, mixed> $changes */
    private function dispatchLifecycleEvent(
        string $event,
        object $entity,
        ClassMetadata $metadata,
        array $changes = [],
    ): void {

        $args = $event === Events::PRE_UPDATE
            ? new PreUpdateEventArgs($entity, $this, $metadata, $changes)
            : new LifecycleEventArgs($entity, $this, $metadata, $changes);

        foreach ($metadata->lifecycleCallbacks($event) as $method) {
            $reflection = new \ReflectionMethod($entity, $method);
            $reflection->invokeArgs($entity, $reflection->getNumberOfParameters() > 0 ? [$args] : []);
        }

        foreach ($metadata->entityListeners($event) as $listenerClass) {
            $listener = $this->entityListener($listenerClass);
            $reflection = new \ReflectionMethod($listener, $event);
            $parameters = $reflection->getNumberOfParameters();
            $reflection->invokeArgs($listener, $parameters > 1 ? [$entity, $args] : [$entity]);
        }

        $this->events->dispatch($event, $args);
    }

    /** @param class-string $listenerClass */
    private function entityListener(string $listenerClass): object
    {
        return $this->entityListenerInstances[$listenerClass] ??= new $listenerClass();
    }

    private function scheduleOrphanRemovals(): void
    {
        foreach ($this->unitOfWork->managedEntities() as $entity) {
            if (!$this->unitOfWork->contains($entity)) {
                continue;
            }

            $metadata = $this->getClassMetadata($entity::class);

            foreach ($metadata->associations() as $association) {
                if (!$association->orphanRemoval || !$association->isToMany()) {
                    continue;
                }

                $value = $this->propertyValue($entity, $association->propertyName);

                if (!$value instanceof PersistentCollection || !$value->isInitialized()) {
                    continue;
                }

                $snapshot = $this->unitOfWork->collectionSnapshot($entity, $association->propertyName);

                if ($snapshot === []) {
                    continue;
                }

                $current = [];

                foreach ($value as $child) {
                    if (is_object($child)) {
                        $current[$this->identifierKey($child, $this->getClassMetadata($child::class))] = true;
                    }
                }

                foreach ($snapshot as $identifierKey) {
                    if (isset($current[$identifierKey])) {
                        continue;
                    }

                    $orphan = $this->unitOfWork->tryGetById($association->targetEntity, $identifierKey);

                    if (is_object($orphan)) {
                        $this->remove($orphan);
                    }
                }
            }
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw EntityManagerClosedException::closed();
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function identifierFromData(ClassMetadata $metadata, array $data): ?array
    {
        $identifier = [];

        foreach ($metadata->identifierColumns() as $column) {
            $value = $data[$column->columnName] ?? null;

            if ($value === null || $value === '') {
                return null;
            }

            $identifier[$column->columnName] = $value;
        }

        return $identifier === [] ? null : $identifier;
    }

    /**
     * @param array<string, mixed> $identifier
     * @return array<string, mixed>|null
     */
    private function fetchRowByIdentifier(ClassMetadata $metadata, array $identifier): ?array
    {
        if ($identifier === []) {
            return null;
        }

        $predicates = [];
        $parameters = [];

        foreach ($identifier as $column => $value) {
            $predicates[] = sprintf('%s = %s', $this->quoteIdentifier($column), $this->parameterPlaceholder($value));
            $parameters[] = $value;
        }

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s LIMIT 1',
            $this->quoteIdentifier($metadata->tableName($this->tablePrefix())),
            implode(' AND ', $predicates),
        );
        $rows = $this->connection()->fetchAllAssociative($sql, ...$parameters);
        $row = $rows[0] ?? null;

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function normalizeIdentifierData(ClassMetadata $metadata, mixed $id): array
    {
        $columns = $metadata->identifierColumns();

        if ($columns === []) {
            throw new \LogicException(sprintf('Entity "%s" has no identifier column.', $metadata->className));
        }

        if (!is_array($id)) {
            if (count($columns) > 1) {
                throw new \InvalidArgumentException(sprintf('Composite identifier for "%s" must be passed as an array.', $metadata->className));
            }

            return [$columns[0]->columnName => $id];
        }

        $data = [];

        foreach ($columns as $column) {
            if (array_key_exists($column->columnName, $id)) {
                $data[$column->columnName] = $id[$column->columnName];
                continue;
            }

            if (array_key_exists($column->propertyName, $id)) {
                $data[$column->columnName] = $id[$column->propertyName];
                continue;
            }

            throw new \InvalidArgumentException(sprintf(
                'Missing identifier field "%s" for "%s".',
                $column->propertyName,
                $metadata->className,
            ));
        }

        return $data;
    }

    private function copyEntityState(object $source, object $target): void
    {
        $reflection = new \ReflectionObject($source);

        foreach ($reflection->getProperties() as $sourceProperty) {
            if (!$sourceProperty->isInitialized($source)) {
                continue;
            }

            $targetReflection = new \ReflectionObject($target);

            if (!$targetReflection->hasProperty($sourceProperty->getName())) {
                continue;
            }

            $targetProperty = $targetReflection->getProperty($sourceProperty->getName());

            if ($targetProperty->isReadOnly() && $targetProperty->isInitialized($target)) {
                continue;
            }

            $targetProperty->setValue($target, $sourceProperty->getValue($source));
        }
    }

    private function executePessimisticLock(object $entity, ClassMetadata $metadata, LockMode $lockMode): void
    {
        $where = $this->identifierWhere($entity, $metadata);

        if ($where === []) {
            throw new \LogicException(sprintf('Entity "%s" has no identifier value.', $metadata->className));
        }

        $predicates = [];
        $parameters = [];

        foreach ($where as $column => $value) {
            $predicates[] = sprintf('%s = %s', $this->quoteIdentifier($column), $this->parameterPlaceholder($value));
            $parameters[] = $value;
        }

        $suffix = $lockMode === LockMode::PESSIMISTIC_READ ? 'LOCK IN SHARE MODE' : 'FOR UPDATE';
        $sql = sprintf(
            'SELECT 1 FROM %s WHERE %s %s',
            $this->quoteIdentifier($metadata->tableName($this->tablePrefix())),
            implode(' AND ', $predicates),
            $suffix,
        );

        $this->connection()->executeStatement($sql, ...$parameters);
    }

    private function identifierKey(object $entity, ClassMetadata $metadata): string
    {
        $identifier = $this->identifierWhere($entity, $metadata);
        ksort($identifier);

        return count($identifier) === 1 ? (string) reset($identifier) : hash('sha256', serialize($identifier));
    }

    private function identifierValueKey(mixed $identifier): string
    {
        if (!is_array($identifier)) {
            return is_scalar($identifier) ? (string) $identifier : hash('sha256', serialize($identifier));
        }

        ksort($identifier);

        return hash('sha256', serialize($identifier));
    }

    /** @param array<string, mixed> $data */
    private function cacheEntity(ClassMetadata $metadata, array $data): void
    {
        if (!$this->secondLevelCache instanceof CacheInterface) {
            return;
        }

        $identifier = $this->identifierFromData($metadata, $data);

        if ($identifier === null) {
            return;
        }

        $this->secondLevelCache->set($this->entityCacheKey($metadata, $identifier), $data);
    }

    /** @param array<string, mixed> $identifier */
    private function evictEntity(ClassMetadata $metadata, array $identifier): void
    {
        if (!$this->secondLevelCache instanceof CacheInterface || $identifier === []) {
            return;
        }

        $this->secondLevelCache->delete($this->entityCacheKey($metadata, $identifier));
    }

    private function entityCacheKey(ClassMetadata $metadata, mixed $identifier): string
    {
        if (!is_array($identifier)) {
            $identifier = $this->normalizeIdentifierData($metadata, $identifier);
        }

        ksort($identifier);

        return sprintf(
            'orm.entity.%s.v%d.%s',
            str_replace('\\', '.', $metadata->className),
            $this->cacheRegionVersion($this->entityCacheRegion($metadata)),
            hash('sha256', serialize($identifier)),
        );
    }

    private function entityCacheRegion(ClassMetadata $metadata): string
    {
        return $metadata->cacheRegion ?? 'entity.' . $metadata->className;
    }

    private function bumpEntityRegion(ClassMetadata $metadata): void
    {
        $this->bumpCacheRegion($this->entityCacheRegion($metadata));
    }

    private function cacheRegionVersion(string $region): int
    {
        return $this->cacheRegionVersions[$region] ?? 0;
    }

    private function bumpCacheRegion(string $region): void
    {
        if (!$this->secondLevelCache instanceof CacheInterface) {
            return;
        }

        $this->cacheRegionVersions[$region] = ($this->cacheRegionVersions[$region] ?? 0) + 1;
    }

    /**
     * @param list<object> $results
     */
    private function cacheAssociationCollection(
        object $owner,
        ClassMetadata $ownerMetadata,
        AssociationMetadata $association,
        array $results,
    ): void {

        if (!$this->secondLevelCache instanceof CacheInterface || $association->cacheRegion === null) {
            return;
        }

        $key = $this->associationCacheKey($owner, $ownerMetadata, $association);

        if ($key === null) {
            return;
        }

        $identifiers = [];

        foreach ($results as $entity) {
            $identifier = $this->identifierValue($entity, $this->getClassMetadata($entity::class));

            if ($identifier !== null) {
                $identifiers[] = $identifier;
            }
        }

        $this->secondLevelCache->set($key, $identifiers);
    }

    /** @return list<object>|null */
    private function cachedAssociationCollection(
        object $owner,
        ClassMetadata $ownerMetadata,
        AssociationMetadata $association,
    ): ?array {

        if (!$this->secondLevelCache instanceof CacheInterface || $association->cacheRegion === null) {
            return null;
        }

        $key = $this->associationCacheKey($owner, $ownerMetadata, $association);

        if ($key === null) {
            return null;
        }

        $cached = $this->secondLevelCache->get($key);

        if (!is_array($cached)) {
            return null;
        }

        $results = [];

        foreach ($cached as $identifier) {
            $entity = $this->find($association->targetEntity, $identifier);

            if (is_object($entity)) {
                $results[] = $entity;
            }
        }

        $this->unitOfWork->setCollectionSnapshot(
            $owner,
            $association->propertyName,
            array_map(fn (object $entity): string => $this->identifierKey($entity, $this->getClassMetadata($entity::class)), $results),
        );

        return $results;
    }

    private function associationCacheKey(
        object $owner,
        ClassMetadata $ownerMetadata,
        AssociationMetadata $association,
    ): ?string {

        $identifier = $this->identifierValue($owner, $ownerMetadata);

        if ($identifier === null) {
            return null;
        }

        $region = $this->associationCacheRegion($ownerMetadata, $association);

        return sprintf(
            'orm.association.%s.v%d.%s',
            str_replace('\\', '.', $region),
            $this->cacheRegionVersion($region),
            hash('sha256', serialize([$ownerMetadata->className, $association->propertyName, $identifier])),
        );
    }

    private function associationCacheRegion(ClassMetadata $ownerMetadata, AssociationMetadata $association): string
    {
        return $association->cacheRegion ?? $ownerMetadata->className . '.' . $association->propertyName;
    }

    private function bumpAssociationRegion(ClassMetadata $ownerMetadata, AssociationMetadata $association): void
    {
        if ($association->cacheRegion === null) {
            return;
        }

        $this->bumpCacheRegion($this->associationCacheRegion($ownerMetadata, $association));
    }

    private function bumpAssociationRegionsFor(ClassMetadata $changedMetadata): void
    {
        foreach ($this->entities->classes() as $className) {
            try {
                $ownerMetadata = $this->getClassMetadata($className);
            } catch (\Throwable) {
                continue;
            }

            foreach ($ownerMetadata->associations() as $association) {
                if (
                    $association->cacheRegion !== null
                    && ($ownerMetadata->className === $changedMetadata->className || $association->targetEntity === $changedMetadata->className)
                ) {
                    $this->bumpAssociationRegion($ownerMetadata, $association);
                }
            }
        }
    }

    private function cascade(object $entity, ClassMetadata $metadata, string $operation): void
    {
        $key = $operation . ':' . spl_object_id($entity);

        if (isset($this->cascadeStack[$key])) {
            return;
        }

        $this->cascadeStack[$key] = true;

        try {
            foreach ($metadata->associations() as $association) {
                if (!$association->cascades($operation) && !($operation === 'remove' && $association->orphanRemoval)) {
                    continue;
                }

                $value = $this->propertyValue($entity, $association->propertyName);

                if ($value instanceof Collection || is_array($value)) {
                    foreach ($value as $child) {
                        if (is_object($child)) {
                            $operation === 'remove' ? $this->remove($child) : $this->persist($child);
                        }
                    }
                    continue;
                }

                if (is_object($value)) {
                    $operation === 'remove' ? $this->remove($value) : $this->persist($value);
                }
            }
        } finally {
            unset($this->cascadeStack[$key]);
        }
    }

    private function propertyValue(object $entity, string $propertyName): mixed
    {
        $property = (new \ReflectionObject($entity))->getProperty($propertyName);

        return $property->isInitialized($entity) ? $property->getValue($entity) : null;
    }

    /** @return list<object> */
    private function loadOneToMany(object $owner, AssociationMetadata $association): array
    {
        if ($association->mappedBy === null) {
            return [];
        }

        $ownerMetadata = $this->getClassMetadata($owner::class);
        $targetMetadata = $this->getClassMetadata($association->targetEntity);
        $targetAssociation = $targetMetadata->associationForProperty($association->mappedBy);
        $joinColumns = $targetAssociation instanceof AssociationMetadata ? $targetAssociation->joinColumns : [];
        $predicates = [];
        $parameters = [];

        foreach ($joinColumns as $joinColumn) {
            if (!$joinColumn instanceof JoinColumnMetadata) {
                continue;
            }

            $ownerValue = $this->referencedColumnValue($owner, $ownerMetadata, $joinColumn->referencedColumnName);

            if ($ownerValue === null || $ownerValue === '') {
                return [];
            }

            $predicates[] = sprintf('%s = %s', $this->quoteIdentifier($joinColumn->name), $this->parameterPlaceholder($ownerValue));
            $parameters[] = $ownerValue;
        }

        if ($predicates === []) {
            return [];
        }

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s',
            $this->quoteIdentifier($targetMetadata->tableName($this->tablePrefix())),
            implode(' AND ', $predicates),
        );

        $orders = [];
        foreach ($association->orderBy as $field => $direction) {
            $metadataColumn = $targetMetadata->columnForProperty($field);
            $column = $metadataColumn instanceof ColumnMetadata ? $metadataColumn->columnName : $field;
            $orders[] = sprintf(
                '%s %s',
                $this->quoteIdentifier($column),
                strtoupper((string) $direction) === 'DESC' ? 'DESC' : 'ASC',
            );
        }

        if ($orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        $results = $this->hydrateRows($targetMetadata, $this->connection()->fetchAllAssociative($sql, ...$parameters));
        $this->unitOfWork->setCollectionSnapshot(
            $owner,
            $association->propertyName,
            array_map(fn (object $entity): string => $this->identifierKey($entity, $this->getClassMetadata($entity::class)), $results),
        );

        return $results;
    }

    private function countOneToMany(object $owner, AssociationMetadata $association): int
    {
        if ($association->mappedBy === null) {
            return 0;
        }

        $ownerMetadata = $this->getClassMetadata($owner::class);
        $targetMetadata = $this->getClassMetadata($association->targetEntity);
        $targetAssociation = $targetMetadata->associationForProperty($association->mappedBy);
        $joinColumns = $targetAssociation instanceof AssociationMetadata ? $targetAssociation->joinColumns : [];
        $predicates = [];
        $parameters = [];

        foreach ($joinColumns as $joinColumn) {
            if (!$joinColumn instanceof JoinColumnMetadata) {
                continue;
            }

            $ownerValue = $this->referencedColumnValue($owner, $ownerMetadata, $joinColumn->referencedColumnName);

            if ($ownerValue === null || $ownerValue === '') {
                return 0;
            }

            $predicates[] = sprintf('%s = %s', $this->quoteIdentifier($joinColumn->name), $this->parameterPlaceholder($ownerValue));
            $parameters[] = $ownerValue;
        }

        if ($predicates === []) {
            return 0;
        }

        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s',
            $this->quoteIdentifier($targetMetadata->tableName($this->tablePrefix())),
            implode(' AND ', $predicates),
        );

        return (int) $this->connection()->fetchOne($sql, ...$parameters);
    }

    /** @return list<object> */
    private function loadManyToMany(object $owner, AssociationMetadata $association): array
    {
        $ownerMetadata = $this->getClassMetadata($owner::class);
        $targetMetadata = $this->getClassMetadata($association->targetEntity);
        $joinTable = $association->joinTable;
        $sourceJoinColumns = $joinTable instanceof JoinTableMetadata ? $joinTable->joinColumns : [];
        $targetJoinColumns = $joinTable instanceof JoinTableMetadata ? $joinTable->inverseJoinColumns : [];

        if (!$association->isOwningSide()) {
            $targetAssociation = $targetMetadata->associationForProperty((string) $association->mappedBy);
            $joinTable = $targetAssociation instanceof AssociationMetadata ? $targetAssociation->joinTable : null;
            $sourceJoinColumns = $joinTable instanceof JoinTableMetadata ? $joinTable->inverseJoinColumns : [];
            $targetJoinColumns = $joinTable instanceof JoinTableMetadata ? $joinTable->joinColumns : [];
        }

        if (!$joinTable instanceof JoinTableMetadata) {
            return [];
        }

        $targetConditions = [];
        $ownerPredicates = [];
        $parameters = [];

        foreach ($targetJoinColumns as $targetJoinColumn) {
            if (!$targetJoinColumn instanceof JoinColumnMetadata) {
                continue;
            }

            $targetConditions[] = sprintf(
                '%s.%s = %s.%s',
                $this->quoteIdentifier('e'),
                $this->quoteIdentifier($targetJoinColumn->referencedColumnName),
                $this->quoteIdentifier('j'),
                $this->quoteIdentifier($targetJoinColumn->name),
            );
        }

        foreach ($sourceJoinColumns as $sourceJoinColumn) {
            if (!$sourceJoinColumn instanceof JoinColumnMetadata) {
                continue;
            }

            $ownerValue = $this->referencedColumnValue($owner, $ownerMetadata, $sourceJoinColumn->referencedColumnName);

            if ($ownerValue === null || $ownerValue === '') {
                return [];
            }

            $ownerPredicates[] = sprintf(
                '%s.%s = %s',
                $this->quoteIdentifier('j'),
                $this->quoteIdentifier($sourceJoinColumn->name),
                $this->parameterPlaceholder($ownerValue),
            );
            $parameters[] = $ownerValue;
        }

        if ($targetConditions === [] || $ownerPredicates === []) {
            return [];
        }

        $sql = sprintf(
            'SELECT %1$s.* FROM %2$s %1$s INNER JOIN %3$s %4$s ON %5$s WHERE %6$s',
            $this->quoteIdentifier('e'),
            $this->quoteIdentifier($targetMetadata->tableName($this->tablePrefix())),
            $this->quoteIdentifier($this->tableName($joinTable->name)),
            $this->quoteIdentifier('j'),
            implode(' AND ', $targetConditions),
            implode(' AND ', $ownerPredicates),
        );

        $results = $this->hydrateRows($targetMetadata, $this->connection()->fetchAllAssociative($sql, ...$parameters));
        $this->unitOfWork->setCollectionSnapshot(
            $owner,
            $association->propertyName,
            array_map(fn (object $entity): string => $this->identifierKey($entity, $targetMetadata), $results),
        );

        return $results;
    }

    private function countManyToMany(object $owner, AssociationMetadata $association): int
    {
        $ownerMetadata = $this->getClassMetadata($owner::class);
        $targetMetadata = $this->getClassMetadata($association->targetEntity);
        $joinTable = $association->joinTable;
        $sourceJoinColumns = $joinTable instanceof JoinTableMetadata ? $joinTable->joinColumns : [];

        if (!$association->isOwningSide()) {
            $targetAssociation = $targetMetadata->associationForProperty((string) $association->mappedBy);
            $joinTable = $targetAssociation instanceof AssociationMetadata ? $targetAssociation->joinTable : null;
            $sourceJoinColumns = $joinTable instanceof JoinTableMetadata ? $joinTable->inverseJoinColumns : [];
        }

        if (!$joinTable instanceof JoinTableMetadata) {
            return 0;
        }

        $predicates = [];
        $parameters = [];

        foreach ($sourceJoinColumns as $sourceJoinColumn) {
            if (!$sourceJoinColumn instanceof JoinColumnMetadata) {
                continue;
            }

            $ownerValue = $this->referencedColumnValue($owner, $ownerMetadata, $sourceJoinColumn->referencedColumnName);

            if ($ownerValue === null || $ownerValue === '') {
                return 0;
            }

            $predicates[] = sprintf('%s = %s', $this->quoteIdentifier($sourceJoinColumn->name), $this->parameterPlaceholder($ownerValue));
            $parameters[] = $ownerValue;
        }

        if ($predicates === []) {
            return 0;
        }

        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s',
            $this->quoteIdentifier($this->tableName($joinTable->name)),
            implode(' AND ', $predicates),
        );

        return (int) $this->connection()->fetchOne($sql, ...$parameters);
    }

    private function identifierValue(object $entity, ClassMetadata $metadata): mixed
    {
        $where = $this->identifierWhere($entity, $metadata);

        if ($where === []) {
            return null;
        }

        if (count($where) === 1) {
            return reset($where);
        }

        return $where;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<object>
     */
    private function hydrateRows(ClassMetadata $metadata, array $rows): array
    {
        return array_map(function (array $row) use ($metadata): object {
            $entity = $this->hydrator->hydrate($metadata, $row, $this);

            return $this->registerManaged($entity, $this->getClassMetadata($entity::class), $row);
        }, $rows);
    }

    private function connection(): ConnectionInterface
    {
        return $this->connections->connection();
    }

    /** @param array<string|int, mixed> $parameters */
    private function compileDql(string $dql, array $parameters = []): QueryBuilder|CompiledQuery
    {
        return DqlCompiler::compile($dql, $this, $this->entities, $parameters);
    }
}

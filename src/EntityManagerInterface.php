<?php

declare(strict_types=1);

namespace SymPress\Orm;

use SymPress\Orm\Event\EventManager;
use SymPress\Orm\Metadata\ClassMetadata;
use SymPress\Orm\Query\CompiledQuery;
use SymPress\Orm\Query\Query;
use SymPress\Orm\Query\QueryBuilder;

/** Application API of the entity manager, for consumers, decorators and test doubles. */
interface EntityManagerInterface
{
    /** @param class-string $entityClass */
    public function getClassMetadata(string $entityClass): ClassMetadata;

    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @return RepositoryInterface<T>
     */
    public function getRepository(string $entityClass): RepositoryInterface;

    /** @param class-string $entityClass */
    public function find(string $entityClass, mixed $id): ?object;

    public function persist(object $entity): void;

    public function remove(object $entity): void;

    public function flush(): void;

    /** @param class-string|null $entityClass */
    public function clear(?string $entityClass = null): void;

    public function detach(object $entity): void;

    public function contains(object $entity): bool;

    public function getEntityState(object $entity, EntityState $default = EntityState::DETACHED): EntityState;

    public function close(): void;

    public function isOpen(): bool;

    public function refresh(object $entity): void;

    /** @param class-string $entityClass */
    public function getReference(string $entityClass, mixed $id): object;

    public function lock(object $entity, LockMode $lockMode, mixed $lockVersion = null): void;

    public function transactional(callable $callback): mixed;

    public function getUnitOfWork(): UnitOfWork;

    public function getEventManager(): EventManager;

    public function createQueryBuilder(): QueryBuilder;

    /** @param array<string|int, mixed> $parameters */
    public function createQuery(string $dql, array $parameters = []): Query;

    public function createNativeQuery(CompiledQuery $query): Query;

    public function registerDqlFunction(string $name, callable $compiler): void;

    public function addOutputWalker(callable $walker): void;

    /** @param class-string|null $className */
    public function evictSecondLevelCache(?string $className = null): void;
}

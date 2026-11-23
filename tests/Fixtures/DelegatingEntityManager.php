<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\EntityManagerInterface;
use SymPress\Orm\EntityState;
use SymPress\Orm\Event\EventManager;
use SymPress\Orm\LockMode;
use SymPress\Orm\Metadata\ClassMetadata;
use SymPress\Orm\Query\CompiledQuery;
use SymPress\Orm\Query\Query;
use SymPress\Orm\Query\QueryBuilder;
use SymPress\Orm\RepositoryInterface;
use SymPress\Orm\UnitOfWork;

/** The smallest consumer decorator: it delegates every call to the wrapped manager. */
final class DelegatingEntityManager implements EntityManagerInterface
{
    public function __construct(private readonly EntityManagerInterface $inner)
    {
    }

    public function getClassMetadata(string $entityClass): ClassMetadata
    {
        return $this->inner->getClassMetadata($entityClass);
    }

    public function getRepository(string $entityClass): RepositoryInterface
    {
        return $this->inner->getRepository($entityClass);
    }

    public function find(string $entityClass, mixed $id): ?object
    {
        return $this->inner->find($entityClass, $id);
    }

    public function persist(object $entity): void
    {
        $this->inner->persist($entity);
    }

    public function remove(object $entity): void
    {
        $this->inner->remove($entity);
    }

    public function flush(): void
    {
        $this->inner->flush();
    }

    public function clear(?string $entityClass = null): void
    {
        $this->inner->clear($entityClass);
    }

    public function detach(object $entity): void
    {
        $this->inner->detach($entity);
    }

    public function contains(object $entity): bool
    {
        return $this->inner->contains($entity);
    }

    public function getEntityState(object $entity, EntityState $default = EntityState::DETACHED): EntityState
    {
        return $this->inner->getEntityState($entity, $default);
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function isOpen(): bool
    {
        return $this->inner->isOpen();
    }

    public function refresh(object $entity): void
    {
        $this->inner->refresh($entity);
    }

    public function getReference(string $entityClass, mixed $id): object
    {
        return $this->inner->getReference($entityClass, $id);
    }

    public function lock(object $entity, LockMode $lockMode, mixed $lockVersion = null): void
    {
        $this->inner->lock($entity, $lockMode, $lockVersion);
    }

    public function transactional(callable $callback): mixed
    {
        return $this->inner->transactional($callback);
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return $this->inner->getUnitOfWork();
    }

    public function getEventManager(): EventManager
    {
        return $this->inner->getEventManager();
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return $this->inner->createQueryBuilder();
    }

    public function createQuery(string $dql, array $parameters = []): Query
    {
        return $this->inner->createQuery($dql, $parameters);
    }

    public function createNativeQuery(CompiledQuery $query): Query
    {
        return $this->inner->createNativeQuery($query);
    }

    public function registerDqlFunction(string $name, callable $compiler): void
    {
        $this->inner->registerDqlFunction($name, $compiler);
    }

    public function addOutputWalker(callable $walker): void
    {
        $this->inner->addOutputWalker($walker);
    }

    public function evictSecondLevelCache(?string $className = null): void
    {
        $this->inner->evictSecondLevelCache($className);
    }
}

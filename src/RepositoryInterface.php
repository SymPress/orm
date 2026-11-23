<?php

declare(strict_types=1);

namespace SymPress\Orm;

use SymPress\Orm\Query\Criteria;

/**
 * Read API of an entity repository, for consumers, decorators and test doubles.
 *
 * @template-covariant T of object
 */
interface RepositoryInterface
{
    /** @return T|null */
    public function find(mixed $id): ?object;

    /** @return list<T> */
    public function findAll(): array;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, 'ASC'|'DESC'|string>|null $orderBy
     * @return list<T>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;

    /**
     * @param array<string, mixed> $criteria
     * @return T|null
     */
    public function findOneBy(array $criteria): ?object;

    /** @return list<T> */
    public function matching(Criteria $criteria): array;
}

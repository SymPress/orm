<?php

declare(strict_types=1);

namespace SymPress\Orm;

use SymPress\Orm\Query\Criteria;

/** Read API of an entity repository, for consumers, decorators and test doubles. */
interface RepositoryInterface
{
    public function find(mixed $id): ?object;

    /** @return list<object> */
    public function findAll(): array;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, 'ASC'|'DESC'|string>|null $orderBy
     * @return list<object>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;

    /** @param array<string, mixed> $criteria */
    public function findOneBy(array $criteria): ?object;

    /** @return list<object> */
    public function matching(Criteria $criteria): array;
}

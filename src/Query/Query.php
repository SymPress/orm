<?php

declare(strict_types=1);

namespace SymPress\Orm\Query;

use SymPress\Orm\Cache\CacheInterface;
use SymPress\Orm\Dbal\ConnectionInterface;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;

final class Query
{
    private ?CacheInterface $resultCache = null;
    private ?string $resultCacheKey = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly EntityHydrator $hydrator,
        private readonly CompiledQuery $compiled,
        private readonly ?EntityManager $entityManager = null,
    ) {
    }

    public function getSQL(): string
    {
        return $this->preparedSql();
    }

    /** @return list<object|array<string, mixed>> */
    public function getResult(): array
    {
        $rows = $this->rows();

        if ($this->compiled->resultMetadata === null) {
            return $rows;
        }

        return array_map(function (array $row): object {
            $entity = $this->hydrator->hydrate($this->compiled->resultMetadata, $row, $this->entityManager);

            if ($this->entityManager === null) {
                return $entity;
            }

            return $this->entityManager->registerManaged(
                $entity,
                $this->entityManager->getClassMetadata($entity::class),
                $row,
            );
        }, $rows);
    }

    public function getOneOrNullResult(): ?object
    {
        $results = $this->getResult();
        $result = $results[0] ?? null;

        return is_object($result) ? $result : null;
    }

    /** @return list<array<string, mixed>> */
    public function getArrayResult(): array
    {
        return $this->getScalarResult();
    }

    /** @return list<array<string, mixed>> */
    public function getScalarResult(): array
    {
        return $this->rows();
    }

    public function getSingleScalarResult(): mixed
    {
        $row = $this->getScalarResult()[0] ?? [];

        if (!is_array($row) || $row === []) {
            return null;
        }

        return reset($row);
    }

    /** @return \Traversable<object|array<string, mixed>> */
    public function toIterable(): \Traversable
    {
        foreach ($this->getResult() as $result) {
            yield $result;
        }
    }

    public function execute(): bool|int
    {
        $result = $this->connection->executeStatement($this->compiled->sql, ...$this->compiled->parameters);

        if (preg_match('/^\s*(?:UPDATE|DELETE|INSERT|REPLACE|ALTER|DROP|TRUNCATE)\b/i', $this->compiled->sql) === 1) {
            $this->entityManager?->evictSecondLevelCache();
        }

        return $result;
    }

    public function useResultCache(CacheInterface $cache, ?string $key = null): self
    {
        $this->resultCache = $cache;
        $this->resultCacheKey = $key;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $cacheKey = $this->cacheKey();
        $cached = $cacheKey === null ? null : $this->resultCache?->get($cacheKey);

        if (is_array($cached)) {
            /** @var list<array<string, mixed>> $cached */
            return $cached;
        }

        $rows = $this->connection->fetchAllAssociative($this->compiled->sql, ...$this->compiled->parameters);

        if ($cacheKey !== null) {
            $this->resultCache?->set($cacheKey, $rows);
        }

        return $rows;
    }

    private function cacheKey(): ?string
    {
        if (!$this->resultCache instanceof CacheInterface) {
            return null;
        }

        return $this->resultCacheKey ?? 'orm.query.' . hash('sha256', serialize([
            $this->compiled->sql,
            $this->compiled->parameters,
        ]));
    }

    private function preparedSql(): string
    {
        return $this->connection->prepare($this->compiled->sql, ...$this->compiled->parameters);
    }
}

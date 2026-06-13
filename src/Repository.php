<?php

declare(strict_types=1);

namespace SymPress\Orm;

use SymPress\Orm\Metadata\ClassMetadata;
use SymPress\Orm\Query\Criteria;
use SymPress\Orm\Query\QueryBuilder;

class Repository
{
    public function __construct(
        protected readonly EntityManager $entityManager,
        protected readonly ClassMetadata $metadata,
    ) {
    }

    public function find(mixed $id): ?object
    {
        $identifiers = $this->metadata->identifierColumns();

        if ($identifiers === []) {
            throw new \LogicException(sprintf('Entity "%s" has no identifier column.', $this->metadata->className));
        }

        if (count($identifiers) === 1 && !is_array($id)) {
            return $this->findOneBy([$identifiers[0]->propertyName => $id]);
        }

        if (!is_array($id)) {
            throw new \InvalidArgumentException(sprintf('Composite identifier for "%s" must be passed as an array.', $this->metadata->className));
        }

        $criteria = [];

        foreach ($identifiers as $identifier) {
            if (array_key_exists($identifier->propertyName, $id)) {
                $criteria[$identifier->propertyName] = $id[$identifier->propertyName];
                continue;
            }

            if (array_key_exists($identifier->columnName, $id)) {
                $criteria[$identifier->propertyName] = $id[$identifier->columnName];
                continue;
            }

            throw new \InvalidArgumentException(sprintf(
                'Missing identifier field "%s" for "%s".',
                $identifier->propertyName,
                $this->metadata->className,
            ));
        }

        return $this->findOneBy($criteria);
    }

    /** @return list<object> */
    public function findAll(): array
    {
        return $this->findBy([]);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, 'ASC'|'DESC'|string>|null $orderBy
     * @return list<object>
     */
    public function findBy(
        array $criteria,
        ?array $orderBy = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {

        $builder = $this->createQueryBuilder('e');
        $index = 0;

        foreach ($criteria as $property => $value) {
            $this->assertMappedLookupProperty($property);
            $index++;
            $parameter = sprintf('p_%d', $index);
            $builder
                ->andWhere(sprintf('e.%s = :%s', $property, $parameter))
                ->setParameter($parameter, $value);
        }

        foreach ($orderBy ?? [] as $property => $direction) {
            $this->assertMappedLookupProperty($property);
            $builder->addOrderBy(sprintf('e.%s', $property), (string) $direction);
        }

        if ($limit !== null) {
            $builder->setMaxResults($limit);
        }

        if ($offset !== null) {
            $builder->setFirstResult($offset);
        }

        return $this->objectResults($builder->getQuery()->getResult());
    }

    /** @param array<string, mixed> $criteria */
    public function findOneBy(array $criteria): ?object
    {
        $results = $this->findBy($criteria, null, 1);

        return $results[0] ?? null;
    }

    /** @return list<object> */
    public function matching(Criteria $criteria): array
    {
        $builder = $this->createQueryBuilder('e');

        foreach ($criteria->whereExpressions() as $expression) {
            $builder->andWhere($expression);
        }

        $builder->setParameters($criteria->parameters());

        foreach ($criteria->orderings() as $property => $direction) {
            if (!str_contains($property, '.')) {
                $this->assertMappedLookupProperty($property);
            }

            $builder->addOrderBy(str_contains($property, '.') ? $property : 'e.' . $property, (string) $direction);
        }

        if ($criteria->maxResults() !== null) {
            $builder->setMaxResults($criteria->maxResults());
        }

        if ($criteria->firstResult() !== null) {
            $builder->setFirstResult($criteria->firstResult());
        }

        return $this->objectResults($builder->getQuery()->getResult());
    }

    public function save(object $entity, bool $flush = false): void
    {
        $this->entityManager->persist($entity);

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function add(object $entity, bool $flush = false): void
    {
        $this->save($entity, $flush);
    }

    public function remove(object $entity, bool $flush = false): void
    {
        $this->entityManager->remove($entity);

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function delete(object $entity, bool $flush = false): void
    {
        $this->remove($entity, $flush);
    }

    public function createQueryBuilder(string $alias): QueryBuilder
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select($alias)
            ->from($this->metadata->className, $alias);
    }

    private function assertMappedLookupProperty(string|int $property): void
    {
        if (!is_string($property) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $property)) {
            throw new \InvalidArgumentException(sprintf('Invalid repository field "%s".', (string) $property));
        }

        if ($this->metadata->columnForProperty($property) !== null) {
            return;
        }

        $association = $this->metadata->associationForProperty($property);

        if ($association !== null && $association->isToOne()) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'Unknown repository field "%s" on "%s".',
            $property,
            $this->metadata->className,
        ));
    }

    /**
     * @param list<object|array<string, mixed>> $results
     * @return list<object>
     */
    private function objectResults(array $results): array
    {
        $objects = [];

        foreach ($results as $result) {
            if (!is_object($result)) {
                throw new \RuntimeException('Repository queries must return objects.');
            }

            $objects[] = $result;
        }

        return $objects;
    }
}

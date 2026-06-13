<?php

declare(strict_types=1);

namespace SymPress\Orm\Query;

use SymPress\Orm\EntityManager;
use SymPress\Orm\Metadata\AssociationMetadata;
use SymPress\Orm\Metadata\ClassMetadata;
use SymPress\Orm\Metadata\ColumnMetadata;
use SymPress\Orm\Metadata\JoinColumnMetadata;
use SymPress\Orm\Metadata\JoinTableMetadata;

final class QueryBuilder
{
    /** @var list<string> */
    private array $select = [];

    private ?ClassMetadata $from = null;
    private string $alias = 'e';

    /** @var array<string, ClassMetadata> */
    private array $aliases = [];

    /** @var list<array{type: string, parentAlias: string, association: AssociationMetadata, alias: string, metadata: ClassMetadata, condition: string|null}> */
    private array $joins = [];

    /** @var list<string> */
    private array $where = [];

    /** @var list<string> */
    private array $groupBy = [];

    /** @var list<string> */
    private array $having = [];

    /** @var list<string> */
    private array $orderBy = [];

    /** @var array<string, mixed> */
    private array $parameters = [];

    private ?int $maxResults = null;
    private ?int $firstResult = null;

    public function __construct(private readonly EntityManager $entityManager)
    {
    }

    public function select(string ...$select): self
    {
        $this->select = array_values($select);

        return $this;
    }

    /** @param class-string $entityClass */
    public function from(string $entityClass, string $alias): self
    {
        $this->from = $this->entityManager->getClassMetadata($entityClass);
        $this->alias = $alias;
        $this->aliases[$alias] = $this->from;

        return $this;
    }

    public function join(string $associationPath, string $alias, ?string $condition = null): self
    {
        return $this->innerJoin($associationPath, $alias, $condition);
    }

    public function innerJoin(string $associationPath, string $alias, ?string $condition = null): self
    {
        return $this->addJoin('INNER JOIN', $associationPath, $alias, $condition);
    }

    public function leftJoin(string $associationPath, string $alias, ?string $condition = null): self
    {
        return $this->addJoin('LEFT JOIN', $associationPath, $alias, $condition);
    }

    public function where(string $predicate): self
    {
        $this->where = [$predicate];

        return $this;
    }

    public function andWhere(string $predicate): self
    {
        $this->where[] = $predicate;

        return $this;
    }

    public function orWhere(string $predicate): self
    {
        $previous = $this->where === [] ? '1 = 0' : sprintf('(%s)', implode(' AND ', $this->where));
        $this->where = [sprintf('%s OR (%s)', $previous, $predicate)];

        return $this;
    }

    public function groupBy(string ...$groupBy): self
    {
        $this->groupBy = array_values($groupBy);

        return $this;
    }

    public function addGroupBy(string $groupBy): self
    {
        $this->groupBy[] = $groupBy;

        return $this;
    }

    public function having(string $predicate): self
    {
        $this->having = [$predicate];

        return $this;
    }

    public function andHaving(string $predicate): self
    {
        $this->having[] = $predicate;

        return $this;
    }

    public function orderBy(string $sort, string $order = 'ASC'): self
    {
        $this->orderBy = [$this->orderByClause($sort, $order)];

        return $this;
    }

    public function addOrderBy(string $sort, string $order = 'ASC'): self
    {
        $this->orderBy[] = $this->orderByClause($sort, $order);

        return $this;
    }

    public function setParameter(string|int $name, mixed $value): self
    {
        $this->parameters[ltrim((string) $name, ':?')] = $this->normalizeParameter($value);

        return $this;
    }

    /** @param array<string|int, mixed> $parameters */
    public function setParameters(array $parameters): self
    {
        foreach ($parameters as $name => $value) {
            $this->setParameter($name, $value);
        }

        return $this;
    }

    public function setMaxResults(?int $maxResults): self
    {
        $this->maxResults = $maxResults;

        return $this;
    }

    public function setFirstResult(?int $firstResult): self
    {
        $this->firstResult = $firstResult;

        return $this;
    }

    public function getQuery(): Query
    {
        return $this->entityManager->createNativeQuery($this->compile());
    }

    public function getSQL(): string
    {
        return $this->entityManager->applyOutputWalkers($this->compile()->sql);
    }

    public function compile(): CompiledQuery
    {
        $metadata = $this->requireFrom();
        /** @var list<mixed> $parameters */
        $parameters = [];
        $sql = sprintf(
            'SELECT %s FROM %s %s',
            $this->selectSql(),
            $this->entityManager->quoteIdentifier($metadata->tableName($this->entityManager->tablePrefix())),
            $this->entityManager->quoteIdentifier($this->alias),
        );

        foreach ($this->joins as $join) {
            $sql .= ' ' . $this->joinSql($join, $parameters);
        }

        if ($this->where !== []) {
            $sql .= ' WHERE ' . $this->compilePredicateList($this->where, $parameters);
        }

        if ($this->groupBy !== []) {
            $groups = [];

            foreach ($this->groupBy as $groupBy) {
                $groups[] = $this->compileExpression($groupBy, $parameters);
            }

            $sql .= ' GROUP BY ' . implode(', ', $groups);
        }

        if ($this->having !== []) {
            $sql .= ' HAVING ' . $this->compilePredicateList($this->having, $parameters);
        }

        if ($this->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->maxResults !== null) {
            $sql .= ' LIMIT %d';
            $parameters[] = max(1, $this->maxResults);
        }

        if ($this->firstResult !== null) {
            $sql .= $this->maxResults === null ? ' LIMIT 18446744073709551615' : '';
            $sql .= ' OFFSET %d';
            $parameters[] = max(0, $this->firstResult);
        }

        return new CompiledQuery($sql, $parameters, $this->returnsRootEntity() ? $metadata : null);
    }

    private function addJoin(string $type, string $associationPath, string $alias, ?string $condition = null): self
    {
        [$parentAlias, $property] = array_pad(explode('.', $associationPath, 2), 2, null);

        if (!is_string($parentAlias) || !is_string($property) || !isset($this->aliases[$parentAlias])) {
            throw new \InvalidArgumentException(sprintf('Invalid association path "%s".', $associationPath));
        }

        $association = $this->aliases[$parentAlias]->associationForProperty($property);

        if (!$association instanceof AssociationMetadata) {
            throw new \InvalidArgumentException(sprintf('Unknown association "%s".', $associationPath));
        }

        $metadata = $this->entityManager->getClassMetadata($association->targetEntity);
        $this->aliases[$alias] = $metadata;
        $this->joins[] = [
            'type' => $type,
            'parentAlias' => $parentAlias,
            'association' => $association,
            'alias' => $alias,
            'metadata' => $metadata,
            'condition' => $condition,
        ];

        return $this;
    }

    /**
     * @param array{type: string, parentAlias: string, association: AssociationMetadata, alias: string, metadata: ClassMetadata, condition: string|null} $join
     * @param list<mixed> $parameters
     */
    private function joinSql(array $join, array &$parameters): string
    {
        $association = $join['association'];
        $targetMetadata = $join['metadata'];
        $targetAlias = $this->entityManager->quoteIdentifier($join['alias']);
        $parentAlias = $this->entityManager->quoteIdentifier($join['parentAlias']);
        $targetTable = $this->entityManager->quoteIdentifier($targetMetadata->tableName($this->entityManager->tablePrefix()));

        if ($association->isToOne() && $association->isOwningSide()) {
            $conditions = [];

            foreach ($association->joinColumns as $joinColumn) {
                $conditions[] = sprintf(
                    '%s.%s = %s.%s',
                    $parentAlias,
                    $this->entityManager->quoteIdentifier($joinColumn->name),
                    $targetAlias,
                    $this->entityManager->quoteIdentifier($joinColumn->referencedColumnName),
                );
            }

            if ($conditions === []) {
                $conditions[] = sprintf(
                    '%s.%s = %s.%s',
                    $parentAlias,
                    $this->entityManager->quoteIdentifier($association->propertyName . '_id'),
                    $targetAlias,
                    $this->entityManager->quoteIdentifier('id'),
                );
            }

            return $this->appendJoinCondition(sprintf(
                '%s %s %s ON %s',
                $join['type'],
                $targetTable,
                $targetAlias,
                implode(' AND ', $conditions),
            ), $join['condition'], $parameters);
        }

        if ($association->type === AssociationMetadata::ONE_TO_MANY && $association->mappedBy !== null) {
            $targetAssociation = $targetMetadata->associationForProperty($association->mappedBy);
            $joinColumns = $targetAssociation instanceof AssociationMetadata ? $targetAssociation->joinColumns : [];
            $conditions = [];

            foreach ($joinColumns as $joinColumn) {
                if (!$joinColumn instanceof JoinColumnMetadata) {
                    continue;
                }

                $conditions[] = sprintf(
                    '%s.%s = %s.%s',
                    $targetAlias,
                    $this->entityManager->quoteIdentifier($joinColumn->name),
                    $parentAlias,
                    $this->entityManager->quoteIdentifier($joinColumn->referencedColumnName),
                );
            }

            if ($conditions !== []) {
                return $this->appendJoinCondition(sprintf(
                    '%s %s %s ON %s',
                    $join['type'],
                    $targetTable,
                    $targetAlias,
                    implode(' AND ', $conditions),
                ), $join['condition'], $parameters);
            }
        }

        if ($association->type === AssociationMetadata::MANY_TO_MANY) {
            $joinTable = $association->joinTable;
            $sourceJoinColumns = $joinTable instanceof JoinTableMetadata ? $joinTable->joinColumns : [];
            $targetJoinColumns = $joinTable instanceof JoinTableMetadata ? $joinTable->inverseJoinColumns : [];

            if (!$association->isOwningSide()) {
                $targetAssociation = $targetMetadata->associationForProperty((string) $association->mappedBy);
                $joinTable = $targetAssociation instanceof AssociationMetadata ? $targetAssociation->joinTable : null;
                $sourceJoinColumns = $joinTable instanceof JoinTableMetadata ? $joinTable->inverseJoinColumns : [];
                $targetJoinColumns = $joinTable instanceof JoinTableMetadata ? $joinTable->joinColumns : [];
            }

            if ($joinTable instanceof JoinTableMetadata && $sourceJoinColumns !== [] && $targetJoinColumns !== []) {
                $joinTableAlias = $this->entityManager->quoteIdentifier($join['alias'] . '_join');
                $sourceConditions = [];
                $targetConditions = [];

                foreach ($sourceJoinColumns as $sourceJoinColumn) {
                    if (!$sourceJoinColumn instanceof JoinColumnMetadata) {
                        continue;
                    }

                    $sourceConditions[] = sprintf(
                        '%s.%s = %s.%s',
                        $parentAlias,
                        $this->entityManager->quoteIdentifier($sourceJoinColumn->referencedColumnName),
                        $joinTableAlias,
                        $this->entityManager->quoteIdentifier($sourceJoinColumn->name),
                    );
                }

                foreach ($targetJoinColumns as $targetJoinColumn) {
                    if (!$targetJoinColumn instanceof JoinColumnMetadata) {
                        continue;
                    }

                    $targetConditions[] = sprintf(
                        '%s.%s = %s.%s',
                        $targetAlias,
                        $this->entityManager->quoteIdentifier($targetJoinColumn->referencedColumnName),
                        $joinTableAlias,
                        $this->entityManager->quoteIdentifier($targetJoinColumn->name),
                    );
                }

                if ($sourceConditions === [] || $targetConditions === []) {
                    throw new \InvalidArgumentException(sprintf('Cannot join association "%s".', $association->propertyName));
                }

                return $this->appendJoinCondition(sprintf(
                    '%1$s %2$s %3$s ON %4$s %1$s %5$s %6$s ON %7$s',
                    $join['type'],
                    $this->entityManager->quoteIdentifier($this->entityManager->tableName($joinTable->name)),
                    $joinTableAlias,
                    implode(' AND ', $sourceConditions),
                    $targetTable,
                    $targetAlias,
                    implode(' AND ', $targetConditions),
                ), $join['condition'], $parameters);
            }
        }

        throw new \InvalidArgumentException(sprintf('Cannot join association "%s".', $association->propertyName));
    }

    /** @param list<mixed> $parameters */
    private function appendJoinCondition(string $sql, ?string $condition, array &$parameters): string
    {
        if ($condition === null || trim($condition) === '') {
            return $sql;
        }

        return $sql . ' AND ' . $this->compileExpression($condition, $parameters);
    }

    private function selectSql(): string
    {
        if ($this->select === [] || $this->select === [$this->alias]) {
            return $this->entityManager->quoteIdentifier($this->alias) . '.*';
        }

        $columns = [];

        foreach ($this->select as $select) {
            /** @var list<mixed> $unused */
            $unused = [];
            $columns[] = $this->compileExpression($select, $unused);
        }

        return implode(', ', $columns);
    }

    /**
     * @param list<string> $predicates
     * @param list<mixed> $parameters
     */
    private function compilePredicateList(array $predicates, array &$parameters): string
    {
        $compiled = [];

        foreach ($predicates as $predicate) {
            $compiled[] = $this->compileExpression($predicate, $parameters);
        }

        return implode(' AND ', $compiled);
    }

    /** @param list<mixed> $parameters */
    private function compileExpression(string $expression, array &$parameters): string
    {
        $expression = $this->entityManager->compileDqlFunctions($expression);
        $expression = preg_replace_callback(
            '/\b([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)\b/',
            function (array $matches): string {
                $alias = $matches[1];
                $property = $matches[2];
                $metadata = $this->aliases[$alias] ?? null;

                if (!$metadata instanceof ClassMetadata) {
                    return $matches[0];
                }

                $column = $metadata->columnForProperty($property);

                if ($column instanceof ColumnMetadata) {
                    return sprintf(
                        '%s.%s',
                        $this->entityManager->quoteIdentifier($alias),
                        $this->entityManager->quoteIdentifier($column->columnName),
                    );
                }

                $association = $metadata->associationForProperty($property);
                $joinColumn = $association?->joinColumns[0] ?? null;

                if ($association instanceof AssociationMetadata && $association->isToOne() && $joinColumn instanceof JoinColumnMetadata) {
                    return sprintf(
                        '%s.%s',
                        $this->entityManager->quoteIdentifier($alias),
                        $this->entityManager->quoteIdentifier($joinColumn->name),
                    );
                }

                throw new \InvalidArgumentException(sprintf('Unknown field "%s" on "%s".', $property, $metadata->className));
            },
            $expression,
        );

        if (!is_string($expression)) {
            return '';
        }

        return preg_replace_callback(
            '/(?::([a-zA-Z_][a-zA-Z0-9_]*)|\?([0-9]+))/',
            function (array $matches) use (&$parameters): string {
                $name = ($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? '');
                $value = $this->parameters[$name] ?? null;

                if (!array_key_exists($name, $this->parameters)) {
                    throw new \InvalidArgumentException(sprintf('Missing query parameter "%s".', $matches[0]));
                }

                if (is_array($value)) {
                    if ($value === []) {
                        return 'NULL';
                    }

                    foreach ($value as $item) {
                        $parameters[] = $item;
                    }

                    return implode(', ', array_map(fn (mixed $item): string => $this->placeholder($item), $value));
                }

                $parameters[] = $value;

                return $this->placeholder($value);
            },
            $expression,
        ) ?? '';
    }

    private function placeholder(mixed $value): string
    {
        return $this->entityManager->parameterPlaceholder($value);
    }

    private function orderByClause(string $sort, string $order): string
    {
        $compiledSort = $this->compileFieldPath($sort);
        $direction = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        return sprintf('%s %s', $compiledSort, $direction);
    }

    private function compileFieldPath(string $path): string
    {
        $path = trim($path);

        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)$/', $path, $matches) !== 1) {
            throw new \InvalidArgumentException(sprintf('ORDER BY expects a mapped field path, got "%s".', $path));
        }

        $alias = $matches[1];
        $property = $matches[2];
        $metadata = $this->aliases[$alias] ?? null;

        if (!$metadata instanceof ClassMetadata) {
            throw new \InvalidArgumentException(sprintf('Unknown query alias "%s".', $alias));
        }

        $column = $metadata->columnForProperty($property);

        if ($column instanceof ColumnMetadata) {
            return sprintf(
                '%s.%s',
                $this->entityManager->quoteIdentifier($alias),
                $this->entityManager->quoteIdentifier($column->columnName),
            );
        }

        $association = $metadata->associationForProperty($property);
        $joinColumn = $association?->joinColumns[0] ?? null;

        if ($association instanceof AssociationMetadata && $association->isToOne() && $joinColumn instanceof JoinColumnMetadata) {
            return sprintf(
                '%s.%s',
                $this->entityManager->quoteIdentifier($alias),
                $this->entityManager->quoteIdentifier($joinColumn->name),
            );
        }

        throw new \InvalidArgumentException(sprintf('Unknown field "%s" on "%s".', $property, $metadata->className));
    }

    private function normalizeParameter(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeParameter($item), $value);
        }

        if (!is_object($value)) {
            return $value;
        }

        try {
            return $this->entityManager->getIdentifierValue($value);
        } catch (\Throwable) {
            return $value;
        }
    }

    private function returnsRootEntity(): bool
    {
        return $this->select === [] || $this->select === [$this->alias];
    }

    private function requireFrom(): ClassMetadata
    {
        if (!$this->from instanceof ClassMetadata) {
            throw new \LogicException('No entity source configured for query builder.');
        }

        return $this->from;
    }
}

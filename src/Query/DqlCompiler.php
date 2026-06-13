<?php

declare(strict_types=1);

namespace SymPress\Orm\Query;

use SymPress\Orm\EntityManager;
use SymPress\Orm\Metadata\AssociationMetadata;
use SymPress\Orm\Metadata\ClassMetadata;
use SymPress\Orm\Metadata\ColumnMetadata;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\JoinColumnMetadata;

final readonly class DqlCompiler
{
    private function __construct()
    {
    }

    /** @param array<string|int, mixed> $parameters */
    public static function compile(
        string $dql,
        EntityManager $entityManager,
        EntityClassRegistry $entities,
        array $parameters = [],
    ): QueryBuilder|CompiledQuery {

        $dql = trim(preg_replace('/\s+/', ' ', $dql) ?? $dql);

        if (preg_match('/^SELECT\s+(.+?)\s+FROM\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\s+([a-zA-Z_][a-zA-Z0-9_]*)(.*)$/i', $dql, $matches) === 1) {
            return self::compileSelect($matches, $entityManager, $entities, $parameters);
        }

        if (preg_match('/^UPDATE\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\s+([a-zA-Z_][a-zA-Z0-9_]*)\s+SET\s+(.+?)(?:\s+WHERE\s+(.+))?$/i', $dql, $matches) === 1) {
            return self::compileUpdate($matches, $entityManager, $entities, $parameters);
        }

        if (preg_match('/^DELETE\s+FROM\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\s+([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+WHERE\s+(.+))?$/i', $dql, $matches) === 1) {
            return self::compileDelete($matches, $entityManager, $entities, $parameters);
        }

        throw new \InvalidArgumentException('Unsupported DQL query. SELECT, UPDATE and DELETE are supported.');
    }

    /**
     * @param array<int, string> $matches
     * @param array<string|int, mixed> $parameters
     */
    private static function compileSelect(
        array $matches,
        EntityManager $entityManager,
        EntityClassRegistry $entities,
        array $parameters,
    ): QueryBuilder {

        $builder = $entityManager
            ->createQueryBuilder()
            ->select(...array_map('trim', explode(',', $matches[1])))
            ->from(self::entityClass($matches[2], $entities), $matches[3])
            ->setParameters($parameters);

        self::compileTail(trim($matches[4]), $builder);

        return $builder;
    }

    /**
     * @param array<int, string> $matches
     * @param array<string|int, mixed> $parameters
     */
    private static function compileUpdate(
        array $matches,
        EntityManager $entityManager,
        EntityClassRegistry $entities,
        array $parameters,
    ): CompiledQuery {

        $metadata = $entityManager->getClassMetadata(self::entityClass($matches[1], $entities));
        $alias = $matches[2];
        $aliases = [$alias => $metadata];
        $values = [];
        $normalizedParameters = self::normalizeParameters($parameters, $entityManager);

        $sql = sprintf(
            'UPDATE %s %s SET %s',
            $entityManager->quoteIdentifier($metadata->tableName($entityManager->tablePrefix())),
            $entityManager->quoteIdentifier($alias),
            self::compileExpression($matches[3], $aliases, $entityManager, $normalizedParameters, $values),
        );

        if (($matches[4] ?? '') !== '') {
            $sql .= ' WHERE ' . self::compileExpression($matches[4], $aliases, $entityManager, $normalizedParameters, $values);
        }

        return new CompiledQuery($sql, $values);
    }

    /**
     * @param array<int, string> $matches
     * @param array<string|int, mixed> $parameters
     */
    private static function compileDelete(
        array $matches,
        EntityManager $entityManager,
        EntityClassRegistry $entities,
        array $parameters,
    ): CompiledQuery {

        $metadata = $entityManager->getClassMetadata(self::entityClass($matches[1], $entities));
        $alias = $matches[2];
        $aliases = [$alias => $metadata];
        $values = [];
        $normalizedParameters = self::normalizeParameters($parameters, $entityManager);

        $sql = sprintf(
            'DELETE %1$s FROM %2$s %1$s',
            $entityManager->quoteIdentifier($alias),
            $entityManager->quoteIdentifier($metadata->tableName($entityManager->tablePrefix())),
        );

        if (($matches[3] ?? '') !== '') {
            $sql .= ' WHERE ' . self::compileExpression($matches[3], $aliases, $entityManager, $normalizedParameters, $values);
        }

        return new CompiledQuery($sql, $values);
    }

    private static function compileTail(string $tail, QueryBuilder $builder): void
    {
        if ($tail === '') {
            return;
        }

        $joins = self::extractJoins($tail);
        $tail = $joins['tail'];

        foreach ($joins['joins'] as $join) {
            $join['type'] === 'LEFT'
                ? $builder->leftJoin($join['path'], $join['alias'], $join['condition'])
                : $builder->join($join['path'], $join['alias'], $join['condition']);
        }

        $clauses = self::clauses($tail);

        if (isset($clauses['WHERE'])) {
            $builder->where($clauses['WHERE']);
        }

        if (isset($clauses['GROUP BY'])) {
            foreach (explode(',', $clauses['GROUP BY']) as $groupBy) {
                $builder->addGroupBy(trim($groupBy));
            }
        }

        if (isset($clauses['HAVING'])) {
            $builder->having($clauses['HAVING']);
        }

        if (isset($clauses['ORDER BY'])) {
            foreach (explode(',', $clauses['ORDER BY']) as $orderClause) {
                $parts = preg_split('/\s+/', trim($orderClause));
                $sort = $parts[0] ?? '';

                if ($sort !== '') {
                    $builder->addOrderBy($sort, $parts[1] ?? 'ASC');
                }
            }
        }
    }

    /**
     * @return array{tail: string, joins: list<array{type: string, path: string, alias: string, condition: string|null}>}
     */
    private static function extractJoins(string $tail): array
    {
        $joins = [];
        $pattern = '/\b(LEFT\s+JOIN|INNER\s+JOIN|JOIN)\s+([a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*)\s+([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+WITH\s+(.+?))?(?=\s+(?:LEFT\s+JOIN|INNER\s+JOIN|JOIN|WHERE|GROUP\s+BY|HAVING|ORDER\s+BY)\b|$)/i';

        while (preg_match($pattern, $tail, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $joins[] = [
                'type' => str_starts_with(strtoupper($matches[1][0]), 'LEFT') ? 'LEFT' : 'INNER',
                'path' => $matches[2][0],
                'alias' => $matches[3][0],
                'condition' => isset($matches[4][0]) && trim($matches[4][0]) !== '' ? trim($matches[4][0]) : null,
            ];
            $tail = substr_replace($tail, '', $matches[0][1], strlen($matches[0][0]));
        }

        return ['tail' => trim($tail), 'joins' => $joins];
    }

    /** @return array<string, string> */
    private static function clauses(string $tail): array
    {
        $keywords = ['WHERE', 'GROUP BY', 'HAVING', 'ORDER BY'];
        /** @var array<string, int> $positions */
        $positions = [];

        foreach ($keywords as $keyword) {
            if (preg_match('/\b' . str_replace(' ', '\s+', $keyword) . '\b/i', $tail, $match, PREG_OFFSET_CAPTURE) === 1) {
                $positions[$keyword] = $match[0][1];
            }
        }

        asort($positions);
        $clauses = [];
        $ordered = array_keys($positions);

        foreach ($ordered as $index => $keyword) {
            $start = $positions[$keyword] + strlen(str_replace(' ', ' ', $keyword));
            $nextKeyword = $ordered[$index + 1] ?? null;
            $end = is_string($nextKeyword) ? $positions[$nextKeyword] : strlen($tail);
            $clauses[$keyword] = trim(substr($tail, $start, $end - $start));
        }

        return $clauses;
    }

    /** @return class-string */
    private static function entityClass(string $name, EntityClassRegistry $entities): string
    {
        $entityClass = $entities->findByShortName($name) ?? $name;

        if (!class_exists($entityClass)) {
            throw new \InvalidArgumentException(sprintf('Unknown DQL entity "%s".', $name));
        }

        return $entityClass;
    }

    /**
     * @param array<string, ClassMetadata> $aliases
     * @param array<string, mixed> $queryParameters
     * @param list<mixed> $values
     */
    private static function compileExpression(
        string $expression,
        array $aliases,
        EntityManager $entityManager,
        array $queryParameters,
        array &$values,
    ): string {

        $expression = $entityManager->compileDqlFunctions($expression);
        $expression = preg_replace_callback(
            '/\b([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)\b/',
            static function (array $matches) use ($aliases, $entityManager): string {
                $alias = $matches[1];
                $property = $matches[2];
                $metadata = $aliases[$alias] ?? null;

                if (!$metadata instanceof ClassMetadata) {
                    return $matches[0];
                }

                $column = $metadata->columnForProperty($property);

                if ($column instanceof ColumnMetadata) {
                    return sprintf(
                        '%s.%s',
                        $entityManager->quoteIdentifier($alias),
                        $entityManager->quoteIdentifier($column->columnName),
                    );
                }

                $association = $metadata->associationForProperty($property);
                $joinColumn = $association?->joinColumns[0] ?? null;

                if ($association instanceof AssociationMetadata && $association->isToOne() && $joinColumn instanceof JoinColumnMetadata) {
                    return sprintf(
                        '%s.%s',
                        $entityManager->quoteIdentifier($alias),
                        $entityManager->quoteIdentifier($joinColumn->name),
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
            static function (array $matches) use ($queryParameters, $entityManager, &$values): string {
                $name = ($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? '');
                $value = $queryParameters[$name] ?? null;

                if (!array_key_exists($name, $queryParameters)) {
                    throw new \InvalidArgumentException(sprintf('Missing query parameter "%s".', $matches[0]));
                }

                if (is_array($value)) {
                    if ($value === []) {
                        return 'NULL';
                    }

                    foreach ($value as $item) {
                        $values[] = $item;
                    }

                    return implode(', ', array_map(
                        static fn (mixed $item): string => $entityManager->parameterPlaceholder($item),
                        $value,
                    ));
                }

                $values[] = $value;

                return $entityManager->parameterPlaceholder($value);
            },
            $expression,
        ) ?? '';
    }

    /**
     * @param array<string|int, mixed> $parameters
     * @return array<string, mixed>
     */
    private static function normalizeParameters(array $parameters, EntityManager $entityManager): array
    {
        $normalized = [];

        foreach ($parameters as $name => $value) {
            $normalized[ltrim((string) $name, ':?')] = self::normalizeParameter($value, $entityManager);
        }

        return $normalized;
    }

    private static function normalizeParameter(mixed $value, EntityManager $entityManager): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalizeParameter($item, $entityManager), $value);
        }

        if (!is_object($value)) {
            return $value;
        }

        try {
            return $entityManager->getIdentifierValue($value);
        } catch (\Throwable) {
            return $value;
        }
    }
}

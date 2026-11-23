<?php

declare(strict_types=1);

namespace SymPress\Orm\Query;

use SymPress\Orm\EntityManager;
use SymPress\Orm\Metadata\AssociationMetadata;
use SymPress\Orm\Metadata\ClassMetadata;
use SymPress\Orm\Metadata\ColumnMetadata;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\JoinColumnMetadata;

/** @internal */
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

        // Keywords, whitespace and commas are only looked for outside literals; each part is restored before use.
        $mask = LiteralMask::mask($dql);
        $dql = trim(preg_replace('/\s+/', ' ', $mask->code) ?? $mask->code);

        if (preg_match('/^SELECT\s+(.+?)\s+FROM\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\s+([a-zA-Z_][a-zA-Z0-9_]*)(.*)$/i', $dql, $matches) === 1) {
            return self::compileSelect($matches, $mask, $entityManager, $entities, $parameters);
        }

        if (preg_match('/^UPDATE\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\s+([a-zA-Z_][a-zA-Z0-9_]*)\s+SET\s+(.+?)(?:\s+WHERE\s+(.+))?$/i', $dql, $matches) === 1) {
            return self::compileUpdate(array_map($mask->restore(...), $matches), $entityManager, $entities, $parameters);
        }

        if (preg_match('/^DELETE\s+FROM\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\s+([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+WHERE\s+(.+))?$/i', $dql, $matches) === 1) {
            return self::compileDelete(array_map($mask->restore(...), $matches), $entityManager, $entities, $parameters);
        }

        throw new \InvalidArgumentException('Unsupported DQL query. SELECT, UPDATE and DELETE are supported.');
    }

    /**
     * @param array<int, string> $matches
     * @param array<string|int, mixed> $parameters
     */
    private static function compileSelect(
        array $matches,
        LiteralMask $mask,
        EntityManager $entityManager,
        EntityClassRegistry $entities,
        array $parameters,
    ): QueryBuilder {

        $builder = $entityManager
            ->createQueryBuilder()
            ->select(...self::splitList($matches[1], $mask))
            ->from(self::entityClass($matches[2], $entities), $matches[3])
            ->setParameters($parameters);

        self::compileTail(trim($matches[4]), $mask, $builder);

        return $builder;
    }

    /** @return list<string> the trimmed comma-separated parts of masked code, with their literals restored */
    private static function splitList(string $code, LiteralMask $mask): array
    {
        return array_map(static fn (string $part): string => $mask->restore(trim($part)), explode(',', $code));
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

        return new CompiledQuery(self::unpreparedSql($sql, $values), $values);
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

        return new CompiledQuery(self::unpreparedSql($sql, $values), $values);
    }

    /** The WHERE clause of a query, narrowed to a single-table subclass by its discriminator condition if it has one. */
    public static function whereClause(?string $where, ?string $discriminator): string
    {
        return match (true) {
            $discriminator === null => $where === null ? '' : ' WHERE ' . $where,
            $where === null => ' WHERE ' . $discriminator,
            default => sprintf(' WHERE (%s) AND %s', $where, $discriminator),
        };
    }

    private static function compileTail(string $tail, LiteralMask $mask, QueryBuilder $builder): void
    {
        if ($tail === '') {
            return;
        }

        $joins = self::extractJoins($tail);
        $tail = $joins['tail'];

        foreach ($joins['joins'] as $join) {
            $condition = $join['condition'] === null ? null : $mask->restore($join['condition']);
            $join['type'] === 'LEFT'
                ? $builder->leftJoin($join['path'], $join['alias'], $condition)
                : $builder->join($join['path'], $join['alias'], $condition);
        }

        $clauses = self::clauses($tail, $mask);

        if (isset($clauses['WHERE'])) {
            $builder->where($mask->restore($clauses['WHERE']));
        }

        if (isset($clauses['GROUP BY'])) {
            foreach (self::splitList($clauses['GROUP BY'], $mask) as $groupBy) {
                $builder->addGroupBy($groupBy);
            }
        }

        if (isset($clauses['HAVING'])) {
            $builder->having($mask->restore($clauses['HAVING']));
        }

        if (isset($clauses['ORDER BY'])) {
            foreach (self::splitList($clauses['ORDER BY'], $mask) as $orderClause) {
                $parts = preg_split('/\s+/', $orderClause) ?: [];

                if (count($parts) > 2) {
                    throw new \InvalidArgumentException(sprintf('Unsupported DQL ORDER BY item "%s".', $orderClause));
                }

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
    private static function clauses(string $tail, LiteralMask $mask): array
    {
        $keywords = ['WHERE', 'GROUP BY', 'HAVING', 'ORDER BY'];
        /** @var array<string, int> $positions */
        $positions = [];

        foreach ($keywords as $keyword) {
            // After a dot the word is a column, after a colon a named parameter.
            if (preg_match('/(?<![.:])\b' . str_replace(' ', '\s+', $keyword) . '\b/i', $tail, $match, PREG_OFFSET_CAPTURE) === 1) {
                $positions[$keyword] = $match[0][1];
            }
        }

        asort($positions);
        $first = reset($positions);

        // Text before the first known clause would otherwise be dropped and broaden the query.
        if ($tail !== '' && $first !== 0) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported DQL near "%s"; SELECT supports JOIN ... WITH, WHERE, GROUP BY, HAVING and ORDER BY.',
                $mask->restore($first === false ? $tail : trim(substr($tail, 0, $first))),
            ));
        }

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

    /**
     * Expressions escape a literal % as %% for wpdb::prepare(). SQL without values is never prepared, so the
     * escape is undone there; every %% in it comes from that escape because no placeholder was added.
     *
     * @param list<mixed> $values
     */
    public static function unpreparedSql(string $sql, array $values): string
    {
        return $values === [] ? str_replace('%%', '%', $sql) : $sql;
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

        // A literal % has to reach wpdb::prepare() as %%; see unpreparedSql().
        $mask = LiteralMask::expression(str_replace('%', '%%', $entityManager->compileDqlFunctions($expression)));
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
            $mask->code,
        );

        if (!is_string($expression)) {
            return '';
        }

        return $mask->restore(self::replaceParameters(
            $expression,
            static function (string $token, string $name) use ($queryParameters, $entityManager, &$values): string {
                $value = $queryParameters[$name] ?? null;

                if (!array_key_exists($name, $queryParameters)) {
                    throw new \InvalidArgumentException(sprintf('Missing query parameter "%s".', $token));
                }

                if (is_array($value)) {
                    if ($value === []) {
                        return 'NULL';
                    }

                    $placeholders = [];

                    foreach ($value as $item) {
                        $placeholders[] = self::bindParameter($item, $entityManager, $values);
                    }

                    return implode(', ', $placeholders);
                }

                return self::bindParameter($value, $entityManager, $values);
            },
        ));
    }

    /**
     * Replaces each :name and ?1 in masked expression code with what $bind returns for it.
     *
     * wpdb::prepare() leaves a %s unquoted when a % stands directly before it, and drops the quotes of '%s' and
     * "%s". A parameter that touches a % or a masked literal is therefore set off by a space, so that no
     * placeholder the ORM emits can follow a % or sit between quotes.
     *
     * @param \Closure(string $token, string $name): string $bind
     */
    public static function replaceParameters(string $code, \Closure $bind): string
    {
        return preg_replace_callback(
            '/([%\x01]?)(:([a-zA-Z_][a-zA-Z0-9_]*)|\?([0-9]+))(\x01?)/',
            static fn (array $matches): string => $matches[1] . ($matches[1] === '' ? '' : ' ')
                . $bind($matches[2], $matches[3] !== '' ? $matches[3] : $matches[4])
                . ($matches[5] === '' ? '' : ' ') . $matches[5],
            $code,
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

    /**
     * Converts a query parameter to the value bound by wpdb, which turns any non-scalar into ''.
     */
    public static function normalizeParameter(mixed $value, EntityManager $entityManager): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalizeParameter($item, $entityManager), $value);
        }

        if (!is_object($value)) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        // Same format EntityHydrator::toDatabase() persists for datetime columns.
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        try {
            $identifier = $entityManager->getIdentifierValue($value);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported query parameter of type "%s"; use a scalar, null, array, BackedEnum, DateTimeInterface or mapped entity.',
                $value::class,
            ), 0, $exception);
        }

        return self::normalizeParameter($identifier, $entityManager);
    }

    /** @param list<mixed> $values */
    public static function bindParameter(mixed $value, EntityManager $entityManager, array &$values): string
    {
        // wpdb cannot bind NULL, so it is inlined; "= NULL" then matches no row, as in SQL and Doctrine.
        if ($value === null) {
            return 'NULL';
        }

        // wpdb binds nested arrays and other non-scalars as '' (or unwraps a lone one), changing the filter.
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException(sprintf('Unsupported query parameter of type "%s"; list items must be scalar.', get_debug_type($value)));
        }

        $values[] = $value;

        return $entityManager->parameterPlaceholder($value);
    }
}

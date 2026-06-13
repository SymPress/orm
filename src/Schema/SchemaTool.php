<?php

declare(strict_types=1);

namespace SymPress\Orm\Schema;

use SymPress\Orm\Dbal\ConnectionInterface;
use SymPress\Orm\Dbal\WpdbConnection;
use SymPress\Orm\Metadata\AssociationMetadata;
use SymPress\Orm\Metadata\ClassMetadata;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\IndexMetadata;
use SymPress\Orm\Metadata\JoinTableMetadata;
use SymPress\Orm\Metadata\MetadataFactory;

final readonly class SchemaTool
{
    private ?ConnectionInterface $connection;

    public function __construct(
        private MetadataFactory $metadataFactory,
        private EntityClassRegistry $entities,
        private SchemaSqlGenerator $sql,
        ConnectionInterface|\wpdb|null $database = null,
        private bool $allowDestructiveUpdates = false,
    ) {

        $this->connection = $this->normalizeConnection($database);
    }

    /** @return list<string> */
    public function getCreateSchemaSql(?string $manager = null): array
    {
        return $this->schemaSql($manager);
    }

    /** @return list<string> */
    public function getUpdateSchemaSql(?string $manager = null, ?bool $allowDestructiveUpdates = null): array
    {
        if (!$this->connection instanceof ConnectionInterface) {
            return $this->schemaSql($manager);
        }

        $statements = [];
        $destructive = $allowDestructiveUpdates ?? $this->allowDestructiveUpdates;

        foreach ($this->metadataByTable($manager) as $metadata) {
            $statements = [
                ...$statements,
                ...$this->updateTableSql(
                    $metadata->tableName($this->prefix()),
                    $metadata->columns(),
                    $metadata->indexes,
                    $destructive,
                    fn () => $this->sql->createTableSql(
                        $metadata,
                        $this->prefix(),
                        $this->charsetCollate(),
                    ),
                ),
            ];

            foreach ($this->owningJoinTableAssociations($metadata) as $association) {
                $joinTable = $association->joinTable;

                if (!$joinTable instanceof JoinTableMetadata) {
                    continue;
                }

                $tableName = $this->prefix() !== '' && !str_starts_with($joinTable->name, $this->prefix())
                    ? $this->prefix() . $joinTable->name
                    : $joinTable->name;
                $statements = [
                    ...$statements,
                    ...$this->updateJoinTableSql(
                        $tableName,
                        $joinTable,
                        $metadata,
                        $this->metadataFactory->getMetadataFor($association->targetEntity),
                    ),
                ];
            }
        }

        return $statements;
    }

    /** @return list<string> */
    public function getDropSchemaSql(?string $manager = null): array
    {
        $statements = [];

        foreach ($this->metadataByTable($manager) as $metadata) {
            $statements[] = $this->sql->dropTableSql($metadata, $this->prefix());

            foreach ($this->owningJoinTableAssociations($metadata) as $association) {
                $joinTable = $association->joinTable;

                if (!$joinTable instanceof JoinTableMetadata) {
                    continue;
                }

                $statements[] = $this->sql->dropJoinTableSql($joinTable, $this->prefix());
            }
        }

        return $statements;
    }

    public function getSchemaHash(?string $manager = null): string
    {
        return substr(hash('sha256', implode("\n\n", $this->getUpdateSchemaSql($manager))), 0, 16);
    }

    /** @return list<string> */
    public function validateMapping(?string $manager = null): array
    {
        return (new \SymPress\Orm\Metadata\MappingValidator($this->metadataFactory))
            ->validate($this->entities->classes($manager));
    }

    /** @return list<string> */
    private function schemaSql(?string $manager): array
    {
        $statements = [];

        foreach ($this->metadataByTable($manager) as $metadata) {
            $statements[] = $this->sql->createTableSql($metadata, $this->prefix(), $this->charsetCollate());

            foreach ($this->owningJoinTableAssociations($metadata) as $association) {
                $joinTable = $association->joinTable;

                if (!$joinTable instanceof JoinTableMetadata) {
                    continue;
                }

                $statements[] = $this->sql->createJoinTableSql(
                    $joinTable,
                    $this->prefix(),
                    $this->charsetCollate(),
                    $metadata,
                    $this->metadataFactory->getMetadataFor($association->targetEntity),
                );
            }
        }

        return $statements;
    }

    /** @return array<string, ClassMetadata> */
    private function metadataByTable(?string $manager): array
    {
        $metadataByTable = [];

        foreach ($this->entities->classes($manager) as $className) {
            $metadata = $this->metadataFactory->getMetadataFor($className);
            $existing = $metadataByTable[$metadata->tableName] ?? null;
            $metadataByTable[$metadata->tableName] = $existing instanceof ClassMetadata
                ? $this->mergeTableMetadata($existing, $metadata)
                : $metadata;
        }

        return $metadataByTable;
    }

    private function mergeTableMetadata(ClassMetadata $left, ClassMetadata $right): ClassMetadata
    {
        return new ClassMetadata(
            $left->className,
            $left->tableName,
            $left->repositoryClass,
            [...$left->columnsByProperty, ...$right->columnsByProperty],
            [...$left->columnsByName, ...$right->columnsByName],
            $left->identifier !== [] ? $left->identifier : $right->identifier,
            $this->mergeIndexes($left->indexes, $right->indexes),
            $left->readOnly,
            [...$left->associationsByProperty, ...$right->associationsByProperty],
            [...$left->embeddeds, ...$right->embeddeds],
            [...$left->lifecycleCallbacks, ...$right->lifecycleCallbacks],
            $left->changeTrackingPolicy,
            [...$left->entityListeners, ...$right->entityListeners],
            $left->inheritanceType,
            $left->discriminatorColumn,
            $left->discriminatorType,
            $left->discriminatorMap,
            $left->discriminatorValue,
            $left->rootClassName,
            $left->cacheRegion,
            $left->cacheUsage,
        );
    }

    /**
     * @param list<IndexMetadata> $left
     * @param list<IndexMetadata> $right
     * @return list<IndexMetadata>
     */
    private function mergeIndexes(array $left, array $right): array
    {
        $indexes = [];

        foreach ([...$left, ...$right] as $index) {
            $indexes[$index->name] = $index;
        }

        return array_values($indexes);
    }

    /** @return list<AssociationMetadata> */
    private function owningJoinTableAssociations(ClassMetadata $metadata): array
    {
        $associations = [];

        foreach ($metadata->associations() as $association) {
            if (
                $association instanceof AssociationMetadata
                && $association->joinTable instanceof JoinTableMetadata
                && $association->isOwningSide()
            ) {
                $associations[$association->joinTable->name] = $association;
            }
        }

        return array_values($associations);
    }

    /**
     * @param list<\SymPress\Orm\Metadata\ColumnMetadata> $columns
     * @param list<\SymPress\Orm\Metadata\IndexMetadata> $indexes
     * @return list<string>
     */
    private function updateTableSql(string $table, array $columns, array $indexes, bool $destructive, callable $createSql): array
    {
        if (!$this->tableExists($table)) {
            return [$createSql()];
        }

        $statements = [];
        $existingColumnRows = $this->existingColumnRows($table);
        $existingColumns = array_keys($existingColumnRows);
        $mappedColumns = [];

        foreach ($columns as $column) {
            $mappedColumns[] = $column->columnName;

            if (in_array($column->columnName, $existingColumns, true)) {
                if (!$this->columnMatches($column, $existingColumnRows[$column->columnName])) {
                    $statements[] = $this->sql->modifyColumnSql($table, $column);
                }

                continue;
            }

            $statements[] = $this->sql->addColumnSql($table, $column);
        }

        foreach ($existingColumns as $existingColumn) {
            if ($destructive && !in_array($existingColumn, $mappedColumns, true)) {
                $statements[] = $this->sql->dropColumnSql($table, $existingColumn);
            }
        }

        $existingIndexes = $this->existingIndexRows($table);
        $mappedIndexes = [];

        foreach ($indexes as $index) {
            $mappedIndexes[] = $index->name;

            if (isset($existingIndexes[$index->name]) && $this->indexMatches($index, $existingIndexes[$index->name])) {
                continue;
            }

            if (isset($existingIndexes[$index->name])) {
                $statements[] = $this->sql->dropIndexSql($table, $index->name);
            }

            $statements[] = $this->sql->addIndexSql($table, $index);
        }

        foreach (array_keys($existingIndexes) as $existingIndex) {
            if ($destructive && $existingIndex !== 'PRIMARY' && !in_array($existingIndex, $mappedIndexes, true)) {
                $statements[] = $this->sql->dropIndexSql($table, $existingIndex);
            }
        }

        return $statements;
    }

    /** @return list<string> */
    private function updateJoinTableSql(
        string $table,
        JoinTableMetadata $joinTable,
        ClassMetadata $sourceMetadata,
        ClassMetadata $targetMetadata,
    ): array {

        if (!$this->tableExists($table)) {
            return [
            $this->sql->createJoinTableSql(
                $joinTable,
                $this->prefix(),
                $this->charsetCollate(),
                $sourceMetadata,
                $targetMetadata,
            ),
            ];
        }

        return [];
    }

    private function tableExists(string $table): bool
    {
        return !in_array($this->connection?->fetchOne('SHOW TABLES LIKE %s', $table), [null, false, '', 0, '0'], true);
    }

    /** @return array<string, array<string, mixed>> */
    private function existingColumnRows(string $table): array
    {
        $columns = [];

        foreach ($this->connection?->fetchAllAssociative(sprintf('DESCRIBE %s', $table)) ?? [] as $row) {
            if (is_string($row['Field'] ?? null)) {
                $columns[$row['Field']] = $row;
            }
        }

        return $columns;
    }

    /**
     * @return array<string, array{unique: bool, columns: list<string>}>
     */
    private function existingIndexRows(string $table): array
    {
        $indexes = [];

        foreach ($this->connection?->fetchAllAssociative(sprintf('SHOW INDEX FROM %s', $table)) ?? [] as $row) {
            $name = $row['Key_name'] ?? null;
            $column = $row['Column_name'] ?? null;

            if (!is_string($name) || !is_string($column)) {
                continue;
            }

            $indexes[$name] ??= [
                'unique' => (int) ($row['Non_unique'] ?? 1) === 0,
                'columns' => [],
            ];
            $indexes[$name]['columns'][(int) ($row['Seq_in_index'] ?? count($indexes[$name]['columns']) + 1)] = $column;
        }

        foreach ($indexes as $name => $index) {
            ksort($indexes[$name]['columns']);
            $indexes[$name]['columns'] = array_values($indexes[$name]['columns']);
        }

        return $indexes;
    }

    /** @param array<string, mixed> $row */
    private function columnMatches(\SymPress\Orm\Metadata\ColumnMetadata $column, array $row): bool
    {
        $expected = strtolower($this->sql->columnDefinition($column));
        $actual = strtolower(trim(sprintf(
            '%s %s %s%s',
            $column->columnName,
            (string) ($row['Type'] ?? ''),
            strtoupper((string) ($row['Null'] ?? 'NO')) === 'YES' ? 'NULL' : 'NOT NULL',
            str_contains(strtolower((string) ($row['Extra'] ?? '')), 'auto_increment') ? ' AUTO_INCREMENT' : '',
        )));

        if ($column->default !== null) {
            $actual .= ' default ' . strtolower((string) $row['Default']);
        }

        return $this->normalizeDefinition($expected) === $this->normalizeDefinition($actual);
    }

    /**
     * @param array{unique: bool, columns: list<string>} $existing
     */
    private function indexMatches(IndexMetadata $index, array $existing): bool
    {
        return $index->unique === $existing['unique'] && $index->columns === $existing['columns'];
    }

    private function normalizeDefinition(string $definition): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower($definition)) ?? $definition);
    }

    private function prefix(): string
    {
        return $this->connection instanceof ConnectionInterface ? $this->connection->tablePrefix() : '';
    }

    private function charsetCollate(): string
    {
        return $this->connection instanceof ConnectionInterface ? $this->connection->charsetCollate() : '';
    }

    private function normalizeConnection(ConnectionInterface|\wpdb|null $database): ?ConnectionInterface
    {
        if ($database instanceof ConnectionInterface) {
            return $database;
        }

        if ($database instanceof \wpdb) {
            return new WpdbConnection($database);
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace SymPress\Orm\Schema;

use SymPress\Orm\Metadata\ClassMetadata;
use SymPress\Orm\Metadata\ColumnMetadata;
use SymPress\Orm\Metadata\IndexMetadata;
use SymPress\Orm\Metadata\JoinColumnMetadata;
use SymPress\Orm\Metadata\JoinTableMetadata;

final readonly class SchemaSqlGenerator
{
    public function __construct(private SqlTypeMapper $types = new SqlTypeMapper())
    {
    }

    public function createTableSql(ClassMetadata $metadata, string $prefix = '', string $charsetCollate = ''): string
    {
        $lines = [];

        foreach ($metadata->columns() as $column) {
            $lines[] = '    ' . $this->columnDefinition($column);
        }

        $primaryColumns = array_values(array_filter(
            array_map(
                static fn (string $property): ?string => $metadata->columnForProperty($property)?->columnName,
                $metadata->identifier,
            ),
            static fn (?string $column): bool => is_string($column) && $column !== '',
        ));

        if ($primaryColumns !== []) {
            $lines[] = sprintf('    PRIMARY KEY  (%s)', implode(', ', $primaryColumns));
        }

        foreach ($metadata->indexes as $index) {
            $lines[] = '    ' . $this->indexDefinition($index);
        }

        $suffix = trim($charsetCollate);

        return sprintf(
            "CREATE TABLE %s (\n%s\n) %s;",
            $metadata->tableName($prefix),
            implode(",\n", $lines),
            $suffix,
        );
    }

    public function dropTableSql(ClassMetadata $metadata, string $prefix = ''): string
    {
        return sprintf('DROP TABLE IF EXISTS %s;', $metadata->tableName($prefix));
    }

    public function createJoinTableSql(
        JoinTableMetadata $joinTable,
        string $prefix = '',
        string $charsetCollate = '',
        ?ClassMetadata $sourceMetadata = null,
        ?ClassMetadata $targetMetadata = null,
    ): string {

        $columns = [];

        foreach ($joinTable->joinColumns as $joinColumn) {
            $columns[] = '    ' . $this->joinColumnDefinition($joinColumn, $sourceMetadata);
        }

        foreach ($joinTable->inverseJoinColumns as $joinColumn) {
            $columns[] = '    ' . $this->joinColumnDefinition($joinColumn, $targetMetadata);
        }

        $primaryColumns = array_map(static fn ($column): string => $column->name, [
            ...$joinTable->joinColumns,
            ...$joinTable->inverseJoinColumns,
        ]);

        if ($primaryColumns !== []) {
            $columns[] = sprintf('    PRIMARY KEY  (%s)', implode(', ', $primaryColumns));
        }

        $table = $prefix !== '' && !str_starts_with($joinTable->name, $prefix)
            ? $prefix . $joinTable->name
            : $joinTable->name;
        $suffix = trim($charsetCollate);

        return sprintf(
            "CREATE TABLE %s (\n%s\n) %s;",
            $table,
            implode(",\n", $columns),
            $suffix,
        );
    }

    public function dropJoinTableSql(JoinTableMetadata $joinTable, string $prefix = ''): string
    {
        $table = $prefix !== '' && !str_starts_with($joinTable->name, $prefix)
            ? $prefix . $joinTable->name
            : $joinTable->name;

        return sprintf('DROP TABLE IF EXISTS %s;', $table);
    }

    public function addColumnSql(string $table, ColumnMetadata $column): string
    {
        return sprintf('ALTER TABLE %s ADD COLUMN %s;', $table, $this->columnDefinition($column));
    }

    public function modifyColumnSql(string $table, ColumnMetadata $column): string
    {
        return sprintf('ALTER TABLE %s MODIFY COLUMN %s;', $table, $this->columnDefinition($column));
    }

    public function dropColumnSql(string $table, string $column): string
    {
        return sprintf('ALTER TABLE %s DROP COLUMN %s;', $table, $column);
    }

    public function addIndexSql(string $table, IndexMetadata $index): string
    {
        return sprintf('ALTER TABLE %s ADD %s;', $table, $this->indexDefinition($index));
    }

    public function dropIndexSql(string $table, string $index): string
    {
        return sprintf('ALTER TABLE %s DROP INDEX %s;', $table, $index);
    }

    public function columnDefinition(ColumnMetadata $column): string
    {
        $definition = sprintf('%s %s', $column->columnName, $this->types->sqlType($column));

        if ($column->generated) {
            $definition .= ' NOT NULL AUTO_INCREMENT';
        } elseif ($column->nullable) {
            $definition .= ' NULL';
        } else {
            $definition .= ' NOT NULL';
        }

        if ($column->default !== null) {
            $definition .= ' DEFAULT ' . $this->defaultValue($column->default);
        }

        return $definition;
    }

    public function indexDefinition(IndexMetadata $index): string
    {
        $keyword = $index->unique ? 'UNIQUE KEY' : 'KEY';

        return sprintf(
            '%s %s (%s)',
            $keyword,
            $index->name,
            implode(', ', $index->columns),
        );
    }

    private function defaultValue(mixed $default): string
    {
        if (is_int($default) || is_float($default)) {
            return (string) $default;
        }

        if (is_bool($default)) {
            return $default ? '1' : '0';
        }

        if ($default === 'CURRENT_TIMESTAMP') {
            return $default;
        }

        return "'" . str_replace("'", "''", (string) $default) . "'";
    }

    private function joinColumnDefinition(JoinColumnMetadata $joinColumn, ?ClassMetadata $referencedMetadata): string
    {
        $referencedColumn = $referencedMetadata?->columnForName($joinColumn->referencedColumnName)
            ?? $referencedMetadata?->identifierColumn();

        if (!$referencedColumn instanceof ColumnMetadata) {
            return sprintf(
                '%s varchar(191) %s',
                $joinColumn->name,
                $joinColumn->nullable ? 'NULL' : 'NOT NULL',
            );
        }

        return $this->columnDefinition(new ColumnMetadata(
            $joinColumn->name,
            $joinColumn->name,
            $referencedColumn->type,
            $referencedColumn->length,
            $joinColumn->nullable,
            false,
            false,
            false,
            $referencedColumn->unsigned,
            $referencedColumn->precision,
            $referencedColumn->scale,
            null,
            $referencedColumn->options,
            [],
            $referencedColumn->enumType,
        ));
    }
}

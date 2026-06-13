<?php

declare(strict_types=1);

namespace SymPress\Orm\Schema;

use SymPress\Orm\Metadata\ColumnMetadata;

final readonly class SqlTypeMapper
{
    public function sqlType(ColumnMetadata $column): string
    {
        $type = strtolower($column->type);

        return match ($type) {
            'string', 'varchar' => sprintf('varchar(%d)', $column->length ?? 255),
            'guid', 'uuid' => 'varchar(36)',
            'text' => 'text',
            'mediumtext' => 'mediumtext',
            'longtext', 'json', 'array', 'simple_array' => 'longtext',
            'smallint' => $this->integer('smallint', $column),
            'integer', 'int' => $this->integer('int', $column),
            'bigint' => $this->integer('bigint', $column),
            'boolean', 'bool' => 'tinyint(1)',
            'float' => 'float',
            'double' => 'double',
            'decimal' => sprintf('decimal(%d,%d)', $column->precision, $column->scale),
            'datetime', 'datetime_immutable' => 'datetime',
            'date', 'date_immutable' => 'date',
            'time', 'time_immutable' => 'time',
            default => $type,
        };
    }

    private function integer(string $type, ColumnMetadata $column): string
    {
        return $column->unsigned ? $type . ' unsigned' : $type;
    }
}

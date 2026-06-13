<?php

declare(strict_types=1);

namespace SymPress\Orm\Util;

final readonly class NameConverter
{
    public function tableName(string $className): string
    {
        $shortName = $this->shortName($className);

        return strtolower($this->snakeCase($shortName));
    }

    public function columnName(string $propertyName): string
    {
        return strtolower($this->snakeCase($propertyName));
    }

    /** @param list<string> $columns */
    public function indexName(string $tableName, array $columns, bool $unique = false): string
    {
        $prefix = $unique ? 'uniq' : 'idx';
        $name = sprintf('%s_%s_%s', $prefix, $tableName, implode('_', $columns));
        $name = preg_replace('/[^a-zA-Z0-9_]+/', '_', strtolower($name));

        return substr(is_string($name) ? $name : sprintf('%s_%s', $prefix, $tableName), 0, 64);
    }

    public function shortName(string $className): string
    {
        $position = strrpos($className, '\\');

        return $position === false ? $className : substr($className, $position + 1);
    }

    private function snakeCase(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);

        return is_string($value) ? $value : '';
    }
}

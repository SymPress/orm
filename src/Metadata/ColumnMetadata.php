<?php

declare(strict_types=1);

namespace SymPress\Orm\Metadata;

final readonly class ColumnMetadata
{
    /**
     * @param scalar|null $default
     * @param array<string, scalar|null> $options
     * @param list<string> $propertyPath
     * @param class-string<\BackedEnum>|null $enumType
     */
    public function __construct(
        public string $propertyName,
        public string $columnName,
        public string $type,
        public ?int $length = null,
        public bool $nullable = false,
        public bool $primary = false,
        public bool $generated = false,
        public bool $unique = false,
        public bool $unsigned = false,
        public int $precision = 10,
        public int $scale = 0,
        public mixed $default = null,
        public array $options = [],
        public array $propertyPath = [],
        public ?string $enumType = null,
        public bool $version = false,
    ) {
    }

    /** @return list<string> */
    public function propertyPath(): array
    {
        return $this->propertyPath === [] ? [$this->propertyName] : $this->propertyPath;
    }
}

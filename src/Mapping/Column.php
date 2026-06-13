<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Column
{
    /**
     * @param scalar|null $default
     * @param array<string, scalar|null> $options
     * @param class-string<\BackedEnum>|null $enumType
     */
    public function __construct(
        public ?string $name = null,
        public string $type = 'string',
        public ?int $length = null,
        public bool $nullable = false,
        public bool $unique = false,
        public bool $unsigned = false,
        public int $precision = 10,
        public int $scale = 0,
        public mixed $default = null,
        public array $options = [],
        public ?string $enumType = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Table
{
    /**
     * @param list<Index> $indexes
     * @param list<UniqueConstraint> $uniqueConstraints
     * @param array<string, scalar|null> $options
     */
    public function __construct(
        public ?string $name = null,
        public array $indexes = [],
        public array $uniqueConstraints = [],
        public array $options = [],
    ) {
    }
}

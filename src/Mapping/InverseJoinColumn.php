<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final readonly class InverseJoinColumn
{
    public function __construct(
        public ?string $name = null,
        public string $referencedColumnName = 'id',
        public bool $nullable = false,
        public bool $unique = false,
        public ?string $onDelete = null,
    ) {
    }
}

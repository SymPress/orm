<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class UniqueConstraint
{
    /** @param list<string> $columns */
    public function __construct(
        public ?string $name = null,
        public array $columns = [],
    ) {
    }
}

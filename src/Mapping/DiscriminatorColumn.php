<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class DiscriminatorColumn
{
    public function __construct(
        public string $name = 'dtype',
        public string $type = 'string',
        public int $length = 255,
    ) {
    }
}

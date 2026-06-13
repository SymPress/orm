<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Embedded
{
    /** @param class-string|null $class */
    public function __construct(
        public ?string $class = null,
        public string|false|null $columnPrefix = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Entity
{
    /** @param class-string|null $repositoryClass */
    public function __construct(
        public ?string $table = null,
        public ?string $repositoryClass = null,
        public bool $readOnly = false,
    ) {
    }
}

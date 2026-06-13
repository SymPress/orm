<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class ManyToMany
{
    /**
     * @param class-string $targetEntity
     * @param list<string> $cascade
     */
    public function __construct(
        public string $targetEntity,
        public array $cascade = [],
        public string $fetch = 'LAZY',
        public ?string $mappedBy = null,
        public ?string $inversedBy = null,
        public ?string $indexBy = null,
    ) {
    }
}

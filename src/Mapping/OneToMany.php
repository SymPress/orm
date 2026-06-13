<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class OneToMany
{
    /**
     * @param class-string $targetEntity
     * @param list<string> $cascade
     */
    public function __construct(
        public string $targetEntity,
        public string $mappedBy,
        public array $cascade = [],
        public string $fetch = 'LAZY',
        public ?string $indexBy = null,
        public bool $orphanRemoval = false,
    ) {
    }
}

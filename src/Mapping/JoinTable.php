<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class JoinTable
{
    /**
     * @param list<JoinColumn> $joinColumns
     * @param list<InverseJoinColumn> $inverseJoinColumns
     */
    public function __construct(
        public ?string $name = null,
        public array $joinColumns = [],
        public array $inverseJoinColumns = [],
    ) {
    }
}

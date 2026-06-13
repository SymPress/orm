<?php

declare(strict_types=1);

namespace SymPress\Orm\Metadata;

final readonly class JoinTableMetadata
{
    /**
     * @param list<JoinColumnMetadata> $joinColumns
     * @param list<JoinColumnMetadata> $inverseJoinColumns
     */
    public function __construct(
        public string $name,
        public array $joinColumns,
        public array $inverseJoinColumns,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace SymPress\Orm\Metadata;

final readonly class JoinColumnMetadata
{
    public function __construct(
        public string $name,
        public string $referencedColumnName = 'id',
        public bool $nullable = true,
        public bool $unique = false,
        public ?string $onDelete = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace SymPress\Orm\Metadata;

final readonly class IndexMetadata
{
    /** @param list<string> $columns */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique = false,
    ) {
    }
}

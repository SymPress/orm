<?php

declare(strict_types=1);

namespace SymPress\Orm\Metadata;

final readonly class EmbeddedMetadata
{
    /**
     * @param class-string $className
     * @param list<ColumnMetadata> $columns
     */
    public function __construct(
        public string $propertyName,
        public string $className,
        public string $columnPrefix,
        public array $columns,
    ) {
    }
}

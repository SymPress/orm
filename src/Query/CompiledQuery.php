<?php

declare(strict_types=1);

namespace SymPress\Orm\Query;

use SymPress\Orm\Metadata\ClassMetadata;

final readonly class CompiledQuery
{
    /** @param list<mixed> $parameters */
    public function __construct(
        public string $sql,
        public array $parameters = [],
        public ?ClassMetadata $resultMetadata = null,
    ) {
    }
}

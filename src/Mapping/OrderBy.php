<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class OrderBy
{
    /** @param array<string, 'ASC'|'DESC'|string> $value */
    public function __construct(public array $value)
    {
    }
}

<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class DiscriminatorMap
{
    /** @param array<string, class-string> $map */
    public function __construct(public array $map)
    {
    }
}

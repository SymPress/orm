<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class GeneratedValue
{
    public function __construct(public string $strategy = 'AUTO')
    {
    }
}

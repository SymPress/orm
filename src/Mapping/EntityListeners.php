<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class EntityListeners
{
    /** @param list<class-string> $listeners */
    public function __construct(public array $listeners)
    {
    }
}

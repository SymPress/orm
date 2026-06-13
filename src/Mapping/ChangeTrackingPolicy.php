<?php

declare(strict_types=1);

namespace SymPress\Orm\Mapping;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class ChangeTrackingPolicy
{
    public const string DEFERRED_IMPLICIT = 'DEFERRED_IMPLICIT';
    public const string DEFERRED_EXPLICIT = 'DEFERRED_EXPLICIT';

    public function __construct(public string $value = self::DEFERRED_IMPLICIT)
    {
    }
}

<?php

declare(strict_types=1);

namespace SymPress\Orm;

final readonly class EntityState
{
    public const string NEW = 'NEW';
    public const string MANAGED = 'MANAGED';
    public const string DETACHED = 'DETACHED';
    public const string REMOVED = 'REMOVED';

    private function __construct()
    {
    }
}

<?php

declare(strict_types=1);

namespace SymPress\Orm;

enum EntityState: string
{
    case NEW = 'NEW';
    case MANAGED = 'MANAGED';
    case DETACHED = 'DETACHED';
    case REMOVED = 'REMOVED';
}

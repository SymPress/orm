<?php

declare(strict_types=1);

namespace SymPress\Orm;

enum LockMode: int
{
    case NONE = 0;
    case OPTIMISTIC = 1;
    case PESSIMISTIC_READ = 2;
    case PESSIMISTIC_WRITE = 4;
}

<?php

declare(strict_types=1);

namespace SymPress\Orm\Exception;

final class EntityManagerClosedException extends \RuntimeException
{
    public static function closed(): self
    {
        return new self('The EntityManager is closed.');
    }
}

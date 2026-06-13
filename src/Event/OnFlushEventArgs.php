<?php

declare(strict_types=1);

namespace SymPress\Orm\Event;

use SymPress\Orm\EntityManager;

final readonly class OnFlushEventArgs
{
    public function __construct(private EntityManager $entityManager)
    {
    }

    public function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }
}

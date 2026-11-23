<?php

declare(strict_types=1);

namespace SymPress\Orm\Event;

use SymPress\Orm\EntityManager;
use SymPress\Orm\EntityManagerInterface;

final readonly class OnFlushEventArgs
{
    /** @internal */
    public function __construct(private EntityManager $entityManager)
    {
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}

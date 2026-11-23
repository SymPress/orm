<?php

declare(strict_types=1);

namespace SymPress\Orm\Event;

use SymPress\Orm\EntityManager;
use SymPress\Orm\EntityManagerInterface;

final readonly class OnClearEventArgs
{
    /** @internal */
    public function __construct(
        private EntityManager $entityManager,
        private ?string $entityClass = null,
    ) {
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    public function getEntityClass(): ?string
    {
        return $this->entityClass;
    }
}

<?php

declare(strict_types=1);

namespace SymPress\Orm\Event;

use SymPress\Orm\EntityManager;

final readonly class OnClearEventArgs
{
    public function __construct(
        private EntityManager $entityManager,
        private ?string $entityClass = null,
    ) {
    }

    public function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    public function getEntityClass(): ?string
    {
        return $this->entityClass;
    }
}

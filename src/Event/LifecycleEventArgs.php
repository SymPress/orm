<?php

declare(strict_types=1);

namespace SymPress\Orm\Event;

use SymPress\Orm\EntityManager;
use SymPress\Orm\Metadata\ClassMetadata;

class LifecycleEventArgs
{
    /** @param array<string, mixed> $changes */
    public function __construct(
        private readonly object $entity,
        private readonly EntityManager $entityManager,
        private readonly ClassMetadata $metadata,
        private readonly array $changes = [],
    ) {
    }

    public function getEntity(): object
    {
        return $this->entity;
    }

    public function getObject(): object
    {
        return $this->entity;
    }

    public function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    public function getMetadata(): ClassMetadata
    {
        return $this->metadata;
    }

    /** @return array<string, mixed> */
    public function getChanges(): array
    {
        return $this->changes;
    }
}

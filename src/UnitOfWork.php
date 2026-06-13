<?php

declare(strict_types=1);

namespace SymPress\Orm;

use SymPress\Orm\Mapping\ChangeTrackingPolicy;
use SymPress\Orm\Metadata\ClassMetadata;

final class UnitOfWork
{
    /** @var array<int, object> */
    private array $managed = [];

    /** @var array<int, EntityState> */
    private array $states = [];

    /** @var array<int, ClassMetadata> */
    private array $metadata = [];

    /** @var array<int, array<string, mixed>> */
    private array $originalData = [];

    /** @var array<class-string, array<string, object>> */
    private array $identityMap = [];

    /** @var array<int, object> */
    private array $insertions = [];

    /** @var array<int, object> */
    private array $deletions = [];

    /** @var array<int, true> */
    private array $explicitUpdates = [];

    /** @var array<int, array<string, list<string>>> */
    private array $collectionSnapshots = [];

    /** @param array<string, mixed> $data */
    public function registerManaged(object $entity, ClassMetadata $metadata, array $data): object
    {
        $existing = $this->entityByIdentity($metadata, $data);

        if ($existing !== null) {
            return $existing;
        }

        $id = spl_object_id($entity);
        $this->managed[$id] = $entity;
        $this->states[$id] = EntityState::MANAGED;
        $this->metadata[$id] = $metadata;
        $this->originalData[$id] = $data;
        $this->mapIdentity($entity, $metadata, $data);

        return $entity;
    }

    /** @param array<string, mixed> $data */
    public function persistNew(object $entity, ClassMetadata $metadata, array $data): void
    {
        $id = spl_object_id($entity);
        $this->managed[$id] = $entity;
        $this->states[$id] = EntityState::MANAGED;
        $this->metadata[$id] = $metadata;
        $this->originalData[$id] = [];
        $this->insertions[$id] = $entity;
        unset($this->deletions[$id]);

        $this->mapIdentity($entity, $metadata, $data);
    }

    /** @param array<string, mixed> $data */
    public function persistExisting(object $entity, ClassMetadata $metadata, array $data): void
    {
        $id = spl_object_id($entity);

        if (isset($this->managed[$id])) {
            unset($this->deletions[$id]);
            return;
        }

        $this->managed[$id] = $entity;
        $this->states[$id] = EntityState::MANAGED;
        $this->metadata[$id] = $metadata;
        $this->originalData[$id] = $data;
        unset($this->deletions[$id]);

        $this->mapIdentity($entity, $metadata, $data);
    }

    public function remove(object $entity): void
    {
        $id = spl_object_id($entity);

        if (isset($this->insertions[$id])) {
            $this->detach($entity);
            return;
        }

        $this->deletions[$id] = $entity;
        $this->states[$id] = EntityState::REMOVED;
    }

    public function scheduleExplicitUpdate(object $entity): void
    {
        if (!$this->contains($entity)) {
            return;
        }

        $this->explicitUpdates[spl_object_id($entity)] = true;
    }

    public function contains(object $entity): bool
    {
        return ($this->states[spl_object_id($entity)] ?? null) === EntityState::MANAGED;
    }

    public function entityState(object $entity, ?EntityState $default = EntityState::DETACHED): ?EntityState
    {
        $id = spl_object_id($entity);

        if (isset($this->states[$id])) {
            return $this->states[$id];
        }

        $metadata = $this->metadata[$id] ?? null;

        return $metadata instanceof ClassMetadata ? EntityState::DETACHED : $default;
    }

    public function detach(object $entity): void
    {
        $id = spl_object_id($entity);
        $metadata = $this->metadata[$id] ?? null;
        $data = $this->originalData[$id] ?? [];

        unset(
            $this->managed[$id],
            $this->states[$id],
            $this->metadata[$id],
            $this->originalData[$id],
            $this->insertions[$id],
            $this->deletions[$id],
            $this->explicitUpdates[$id],
            $this->collectionSnapshots[$id],
        );

        if ($metadata instanceof ClassMetadata) {
            $this->unmapIdentity($metadata, $data);
        }
    }

    /** @param class-string|null $entityClass */
    public function clear(?string $entityClass = null): void
    {
        if ($entityClass !== null) {
            foreach ($this->managed as $entity) {
                if ($entity::class === $entityClass || is_a($entity, $entityClass)) {
                    $this->detach($entity);
                }
            }

            return;
        }

        $this->managed = [];
        $this->states = [];
        $this->metadata = [];
        $this->originalData = [];
        $this->identityMap = [];
        $this->insertions = [];
        $this->deletions = [];
        $this->explicitUpdates = [];
        $this->collectionSnapshots = [];
    }

    /** @return list<object> */
    public function scheduledInsertions(): array
    {
        return array_values($this->insertions);
    }

    /** @return list<object> */
    public function scheduledDeletions(): array
    {
        return array_values($this->deletions);
    }

    /**
     * @return list<array{entity: object, metadata: ClassMetadata, changes: array<string, mixed>, original: array<string, mixed>}>
     */
    public function scheduledUpdates(EntityHydrator $hydrator, ?EntityManager $entityManager = null): array
    {
        $updates = [];

        foreach ($this->managed as $id => $entity) {
            if (isset($this->insertions[$id]) || isset($this->deletions[$id])) {
                continue;
            }

            $metadata = $this->metadata[$id] ?? null;

            if (!$metadata instanceof ClassMetadata || $metadata->readOnly) {
                continue;
            }

            if (
                $metadata->changeTrackingPolicy === ChangeTrackingPolicy::DEFERRED_EXPLICIT
                && !isset($this->explicitUpdates[$id])
            ) {
                continue;
            }

            $original = $this->originalData[$id] ?? [];
            $changes = $this->changes($original, $hydrator->extract($entity, $metadata, $entityManager));

            if ($changes === []) {
                continue;
            }

            $updates[] = [
                'entity' => $entity,
                'metadata' => $metadata,
                'changes' => $changes,
                'original' => $original,
            ];
        }

        return $updates;
    }

    /** @param array<string, mixed> $data */
    public function markFlushed(object $entity, ClassMetadata $metadata, array $data): void
    {
        $id = spl_object_id($entity);
        $previous = $this->originalData[$id] ?? [];
        $this->unmapIdentity($metadata, $previous);
        $this->managed[$id] = $entity;
        $this->states[$id] = EntityState::MANAGED;
        $this->metadata[$id] = $metadata;
        $this->originalData[$id] = $data;
        unset($this->insertions[$id], $this->deletions[$id], $this->explicitUpdates[$id]);
        $this->mapIdentity($entity, $metadata, $data);
    }

    public function markRemoved(object $entity): void
    {
        $this->detach($entity);
    }

    /** @param class-string $className */
    public function tryGetById(string $className, mixed $id): ?object
    {
        $key = $this->identityKeyFromIdentifier($id);

        return $this->identityMap[$className][$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function originalData(object $entity): array
    {
        return $this->originalData[spl_object_id($entity)] ?? [];
    }

    /** @param list<mixed> $identifiers */
    public function setCollectionSnapshot(object $entity, string $association, array $identifiers): void
    {
        $this->collectionSnapshots[spl_object_id($entity)][$association] = array_values(array_map(
            fn (mixed $value): string => $this->stringKey($value),
            $identifiers,
        ));
    }

    /** @return list<string> */
    public function collectionSnapshot(object $entity, string $association): array
    {
        return $this->collectionSnapshots[spl_object_id($entity)][$association] ?? [];
    }

    public function hasCollectionSnapshot(object $entity, string $association): bool
    {
        return array_key_exists($association, $this->collectionSnapshots[spl_object_id($entity)] ?? []);
    }

    /** @return list<object> */
    public function managedEntities(): array
    {
        return array_values($this->managed);
    }

    /** @param array<string, mixed> $data */
    private function entityByIdentity(ClassMetadata $metadata, array $data): ?object
    {
        $key = $this->identityKey($metadata, $data);

        if ($key === null) {
            return null;
        }

        return $this->identityMap[$metadata->className][$key] ?? null;
    }

    /** @param array<string, mixed> $data */
    private function mapIdentity(object $entity, ClassMetadata $metadata, array $data): void
    {
        $key = $this->identityKey($metadata, $data);

        if ($key === null) {
            return;
        }

        $this->identityMap[$metadata->className][$key] = $entity;
    }

    /** @param array<string, mixed> $data */
    private function unmapIdentity(ClassMetadata $metadata, array $data): void
    {
        $key = $this->identityKey($metadata, $data);

        if ($key === null) {
            return;
        }

        unset($this->identityMap[$metadata->className][$key]);
    }

    /** @param array<string, mixed> $data */
    private function identityKey(ClassMetadata $metadata, array $data): ?string
    {
        $identifierColumns = $metadata->identifierColumns();

        if ($identifierColumns === []) {
            return null;
        }

        $values = [];

        foreach ($identifierColumns as $column) {
            if (!array_key_exists($column->columnName, $data)) {
                return null;
            }

            $value = $data[$column->columnName];

            if ($value === null || $value === '') {
                return null;
            }

            $values[$column->columnName] = $value;
        }

        return $this->identityKeyFromIdentifier($values);
    }

    private function identityKeyFromIdentifier(mixed $identifier): string
    {
        if (!is_array($identifier)) {
            return $this->stringKey($identifier);
        }

        ksort($identifier);

        return hash('sha256', serialize($identifier));
    }

    private function stringKey(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : hash('sha256', serialize($value));
    }

    /**
     * @param array<string, mixed> $original
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function changes(array $original, array $current): array
    {
        $changes = [];

        foreach ($current as $column => $value) {
            if (array_key_exists($column, $original) && $original[$column] === $value) {
                continue;
            }

            $changes[$column] = $value;
        }

        return $changes;
    }
}

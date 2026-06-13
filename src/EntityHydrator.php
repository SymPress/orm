<?php

declare(strict_types=1);

namespace SymPress\Orm;

use SymPress\Orm\Collection\Collection;
use SymPress\Orm\Collection\PersistentCollection;
use SymPress\Orm\Metadata\AssociationMetadata;
use SymPress\Orm\Metadata\ClassMetadata;
use SymPress\Orm\Metadata\ColumnMetadata;
use SymPress\Orm\Metadata\EmbeddedMetadata;
use SymPress\Orm\Metadata\JoinColumnMetadata;

final readonly class EntityHydrator
{
    /** @param array<string, mixed> $row */
    public function hydrate(ClassMetadata $metadata, array $row, ?EntityManager $entityManager = null): object
    {
        $metadata = $this->resolveDiscriminatorMetadata($metadata, $row, $entityManager);
        $reflection = new \ReflectionClass($metadata->className);
        $constructor = $reflection->getConstructor();

        if ($constructor instanceof \ReflectionMethod) {
            $entity = $reflection->newInstanceArgs($this->constructorArguments($constructor, $metadata, $row, $entityManager));
            $this->assignMissingAssociations($entity, $metadata, $row, $entityManager);

            return $entity;
        }

        $entity = $reflection->newInstanceWithoutConstructor();

        foreach ($metadata->embeddeds as $embedded) {
            $this->assign($entity, $embedded->propertyName, $this->hydrateEmbedded($embedded, $row));
        }

        foreach ($metadata->columns() as $column) {
            if (!array_key_exists($column->columnName, $row) || $metadata->associationForProperty($column->propertyName) !== null) {
                continue;
            }

            $this->assignPath($entity, $column->propertyPath(), $this->fromDatabase($column, $row[$column->columnName]));
        }

        $this->assignMissingAssociations($entity, $metadata, $row, $entityManager);

        return $entity;
    }

    /** @return array<string, mixed> */
    public function extract(object $entity, ClassMetadata $metadata, ?EntityManager $entityManager = null): array
    {
        $data = [];

        foreach ($metadata->columns() as $column) {
            $association = $metadata->associationForProperty($column->propertyName);

            if ($association instanceof AssociationMetadata && $association->isToOne()) {
                $data[$column->columnName] = $this->associationDatabaseValue($entity, $association, $column, $entityManager);
                continue;
            }

            if ($metadata->discriminatorColumn === $column->columnName && $metadata->discriminatorValue !== null) {
                $data[$column->columnName] = $metadata->discriminatorValue;
                continue;
            }

            if (!$this->hasPropertyPath($entity, $column->propertyPath())) {
                continue;
            }

            $value = $this->readPath($entity, $column->propertyPath());
            $data[$column->columnName] = $this->toDatabase($column, $value);
        }

        return $data;
    }

    public function toDatabase(ColumnMetadata $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        $type = strtolower($column->type);

        if ($value instanceof \DateTimeInterface && str_contains($type, 'date')) {
            return $type === 'date' || $type === 'date_immutable'
                ? $value->format('Y-m-d')
                : $value->format('Y-m-d H:i:s');
        }

        if (in_array($type, ['boolean', 'bool'], true)) {
            return $value ? 1 : 0;
        }

        if (in_array($type, ['json', 'array', 'simple_array'], true)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }

    public function fromDatabase(ColumnMetadata $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($column->enumType !== null && is_subclass_of($column->enumType, \BackedEnum::class)) {
            return $column->enumType::from($value);
        }

        $type = strtolower($column->type);

        if ($type === 'datetime_immutable') {
            return new \DateTimeImmutable((string) $value);
        }

        if ($type === 'datetime') {
            return new \DateTime((string) $value);
        }

        if ($type === 'date_immutable') {
            return new \DateTimeImmutable((string) $value);
        }

        if ($type === 'date') {
            return new \DateTime((string) $value);
        }

        if (in_array($type, ['boolean', 'bool'], true)) {
            return (bool) $value;
        }

        if (in_array($type, ['integer', 'int', 'smallint', 'bigint'], true)) {
            return (int) $value;
        }

        if (in_array($type, ['float', 'double', 'decimal'], true)) {
            return (float) $value;
        }

        if (in_array($type, ['json', 'array', 'simple_array'], true)) {
            $decoded = json_decode((string) $value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return $value;
    }

    public function assign(object $entity, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionObject($entity);

        if (!$reflection->hasProperty($propertyName)) {
            return;
        }

        $property = $reflection->getProperty($propertyName);

        if ($property->isReadOnly() && $property->isInitialized($entity)) {
            return;
        }

        $property->setValue($entity, $value);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<mixed>
     */
    private function constructorArguments(
        \ReflectionMethod $constructor,
        ClassMetadata $metadata,
        array $row,
        ?EntityManager $entityManager,
    ): array {

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $column = $metadata->columnForProperty($name);

            if ($column instanceof ColumnMetadata && array_key_exists($column->columnName, $row)) {
                $arguments[] = $this->fromDatabase($column, $row[$column->columnName]);
                continue;
            }

            $embedded = $this->embeddedForProperty($metadata, $name);

            if ($embedded instanceof EmbeddedMetadata) {
                $arguments[] = $this->hydrateEmbedded($embedded, $row);
                continue;
            }

            $association = $metadata->associationForProperty($name);

            if ($association instanceof AssociationMetadata) {
                $arguments[] = $this->associationValue(null, $association, $row, $entityManager);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;
                continue;
            }

            throw new \RuntimeException(sprintf(
                'Cannot hydrate "%s": missing constructor value for "$%s".',
                $metadata->className,
                $name,
            ));
        }

        return $arguments;
    }

    /** @param array<string, mixed> $row */
    private function assignMissingAssociations(
        object $entity,
        ClassMetadata $metadata,
        array $row,
        ?EntityManager $entityManager,
    ): void {

        foreach ($metadata->associations() as $association) {
            $property = (new \ReflectionObject($entity))->getProperty($association->propertyName);

            if ($property->isReadOnly() && $property->isInitialized($entity)) {
                continue;
            }

            if ($property->isInitialized($entity) && !$association->isToMany()) {
                continue;
            }

            $this->assign($entity, $association->propertyName, $this->associationValue($entity, $association, $row, $entityManager));
        }
    }

    /** @param array<string, mixed> $row */
    private function associationValue(
        ?object $owner,
        AssociationMetadata $association,
        array $row,
        ?EntityManager $entityManager,
    ): mixed {

        if ($association->isToMany()) {
            if ($entityManager === null || $owner === null) {
                return new Collection();
            }

            return new PersistentCollection(
                $owner,
                $association->propertyName,
                static fn (): array => $entityManager->loadAssociationCollection($owner, $association),
                strtoupper($association->fetch) === 'EXTRA_LAZY'
                    ? static fn (): int => $entityManager->countAssociationCollection($owner, $association)
                    : null,
            );
        }

        if (!$association->isOwningSide()) {
            return null;
        }

        $id = $this->associationIdentifier($association, $row);

        if ($id === null) {
            return null;
        }

        if ($entityManager === null) {
            return null;
        }

        return strtoupper($association->fetch) === 'EAGER'
            ? $entityManager->find($association->targetEntity, $id)
            : $entityManager->getReference($association->targetEntity, $id);
    }

    /** @param array<string, mixed> $row */
    private function associationIdentifier(AssociationMetadata $association, array $row): mixed
    {
        $identifier = [];

        foreach ($association->joinColumns as $joinColumn) {
            if (!array_key_exists($joinColumn->name, $row)) {
                return null;
            }

            $value = $row[$joinColumn->name];

            if ($value === null || $value === '') {
                return null;
            }

            $identifier[$joinColumn->referencedColumnName] = $value;
        }

        if ($identifier === []) {
            return null;
        }

        return count($identifier) === 1 ? reset($identifier) : $identifier;
    }

    private function associationDatabaseValue(
        object $entity,
        AssociationMetadata $association,
        ColumnMetadata $column,
        ?EntityManager $entityManager,
    ): mixed {

        $value = $this->readPath($entity, [$association->propertyName]);

        if (!is_object($value)) {
            return null;
        }

        $targetMetadata = $entityManager?->getClassMetadata($association->targetEntity);
        $joinColumn = $this->joinColumnForColumn($association, $column);
        $referencedColumnName = $joinColumn instanceof JoinColumnMetadata ? $joinColumn->referencedColumnName : 'id';
        $referencedColumn = $targetMetadata?->columnForName($referencedColumnName)
            ?? $targetMetadata?->identifierColumn();

        if ($targetMetadata instanceof ClassMetadata && $referencedColumn instanceof ColumnMetadata) {
            return $this->extract($value, $targetMetadata, $entityManager)[$referencedColumn->columnName] ?? null;
        }

        return $this->readPath($value, [$referencedColumnName]);
    }

    private function joinColumnForColumn(AssociationMetadata $association, ColumnMetadata $column): ?JoinColumnMetadata
    {
        foreach ($association->joinColumns as $joinColumn) {
            if ($joinColumn->name === $column->columnName) {
                return $joinColumn;
            }
        }

        return $association->joinColumns[0] ?? null;
    }

    /** @param array<string, mixed> $row */
    private function hydrateEmbedded(EmbeddedMetadata $embedded, array $row): object
    {
        $reflection = new \ReflectionClass($embedded->className);
        $constructor = $reflection->getConstructor();

        if ($constructor instanceof \ReflectionMethod) {
            return $reflection->newInstanceArgs($this->embeddedConstructorArguments($constructor, $embedded, $row));
        }

        $entity = $reflection->newInstanceWithoutConstructor();

        foreach ($embedded->columns as $column) {
            if (!array_key_exists($column->columnName, $row)) {
                continue;
            }

            $this->assign($entity, $column->propertyPath()[1] ?? $column->propertyName, $this->fromDatabase($column, $row[$column->columnName]));
        }

        return $entity;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<mixed>
     */
    private function embeddedConstructorArguments(
        \ReflectionMethod $constructor,
        EmbeddedMetadata $embedded,
        array $row,
    ): array {

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $column = null;

            foreach ($embedded->columns as $candidate) {
                if (($candidate->propertyPath()[1] ?? null) === $parameter->getName()) {
                    $column = $candidate;
                    break;
                }
            }

            if ($column instanceof ColumnMetadata && array_key_exists($column->columnName, $row)) {
                $arguments[] = $this->fromDatabase($column, $row[$column->columnName]);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            $arguments[] = $parameter->allowsNull() ? null : throw new \RuntimeException(sprintf(
                'Cannot hydrate embedded "%s": missing constructor value for "$%s".',
                $embedded->className,
                $parameter->getName(),
            ));
        }

        return $arguments;
    }

    private function embeddedForProperty(ClassMetadata $metadata, string $propertyName): ?EmbeddedMetadata
    {
        foreach ($metadata->embeddeds as $embedded) {
            if ($embedded->propertyName === $propertyName) {
                return $embedded;
            }
        }

        return null;
    }

    /** @param list<string> $propertyPath */
    private function assignPath(object $entity, array $propertyPath, mixed $value): void
    {
        if (count($propertyPath) === 1) {
            $this->assign($entity, $propertyPath[0], $value);
            return;
        }

        $head = array_shift($propertyPath);

        if (!is_string($head)) {
            return;
        }

        $target = $this->readPath($entity, [$head]);

        if (!is_object($target)) {
            return;
        }

        $this->assignPath($target, $propertyPath, $value);
    }

    /** @param list<string> $propertyPath */
    private function readPath(object $entity, array $propertyPath): mixed
    {
        $current = $entity;

        foreach ($propertyPath as $propertyName) {
            if (!is_object($current)) {
                return null;
            }

            $property = (new \ReflectionObject($current))->getProperty($propertyName);

            if (!$property->isInitialized($current)) {
                return null;
            }

            $current = $property->getValue($current);
        }

        return $current;
    }

    /** @param array<string, mixed> $row */
    private function resolveDiscriminatorMetadata(
        ClassMetadata $metadata,
        array $row,
        ?EntityManager $entityManager,
    ): ClassMetadata {

        if (
            $entityManager === null
            || $metadata->discriminatorColumn === null
            || !array_key_exists($metadata->discriminatorColumn, $row)
        ) {
            return $metadata;
        }

        $value = (string) $row[$metadata->discriminatorColumn];
        $className = $metadata->discriminatorMap[$value] ?? null;

        if (!is_string($className) || $className === $metadata->className || !class_exists($className)) {
            return $metadata;
        }

        return $entityManager->getClassMetadata($className);
    }

    /** @param list<string> $propertyPath */
    private function hasPropertyPath(object $entity, array $propertyPath): bool
    {
        $current = $entity;

        foreach ($propertyPath as $propertyName) {
            if (!is_object($current)) {
                return false;
            }

            $reflection = new \ReflectionObject($current);

            if (!$reflection->hasProperty($propertyName)) {
                return false;
            }

            $property = $reflection->getProperty($propertyName);

            if (!$property->isInitialized($current)) {
                return true;
            }

            $current = $property->getValue($current);
        }

        return true;
    }
}

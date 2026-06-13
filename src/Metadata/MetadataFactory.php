<?php

declare(strict_types=1);

namespace SymPress\Orm\Metadata;

use SymPress\Orm\Cache\CacheInterface;
use SymPress\Orm\Mapping\Cache as CacheMapping;
use SymPress\Orm\Mapping\ChangeTrackingPolicy;
use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\DiscriminatorColumn;
use SymPress\Orm\Mapping\DiscriminatorMap;
use SymPress\Orm\Mapping\Embeddable;
use SymPress\Orm\Mapping\Embedded;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\EntityListeners;
use SymPress\Orm\Mapping\GeneratedValue;
use SymPress\Orm\Mapping\HasLifecycleCallbacks;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\InheritanceType;
use SymPress\Orm\Mapping\Index;
use SymPress\Orm\Mapping\InverseJoinColumn;
use SymPress\Orm\Mapping\JoinColumn;
use SymPress\Orm\Mapping\JoinTable;
use SymPress\Orm\Mapping\ManyToMany;
use SymPress\Orm\Mapping\ManyToOne;
use SymPress\Orm\Mapping\MappedSuperclass;
use SymPress\Orm\Mapping\OneToMany;
use SymPress\Orm\Mapping\OneToOne;
use SymPress\Orm\Mapping\OrderBy;
use SymPress\Orm\Mapping\PostFlush;
use SymPress\Orm\Mapping\PostLoad;
use SymPress\Orm\Mapping\PostPersist;
use SymPress\Orm\Mapping\PostRemove;
use SymPress\Orm\Mapping\PostUpdate;
use SymPress\Orm\Mapping\PreFlush;
use SymPress\Orm\Mapping\PrePersist;
use SymPress\Orm\Mapping\PreRemove;
use SymPress\Orm\Mapping\PreUpdate;
use SymPress\Orm\Mapping\Table;
use SymPress\Orm\Mapping\UniqueConstraint;
use SymPress\Orm\Mapping\Version;
use SymPress\Orm\Util\NameConverter;

final class MetadataFactory
{
    /** @var array<class-string, ClassMetadata> */
    private array $metadata = [];

    public function __construct(
        private readonly NameConverter $names = new NameConverter(),
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /** @param class-string $className */
    public function hasMetadataFor(string $className): bool
    {
        return $this->entityAttribute($this->reflection($className)) instanceof Entity;
    }

    /** @param class-string $className */
    public function getMetadataFor(string $className): ClassMetadata
    {
        if (isset($this->metadata[$className])) {
            return $this->metadata[$className];
        }

        $cacheKey = 'orm.metadata.' . str_replace('\\', '.', $className);
        $cached = $this->cache?->get($cacheKey);

        if ($cached instanceof ClassMetadata) {
            return $this->metadata[$className] = $cached;
        }

        $reflection = $this->reflection($className);
        $entity = $this->entityAttribute($reflection);

        if (!$entity instanceof Entity) {
            throw new \InvalidArgumentException(sprintf('Class "%s" is not an ORM entity.', $className));
        }

        $inheritanceRoot = $this->inheritanceRoot($reflection);
        $rootReflection = $inheritanceRoot ?? $reflection;
        $rootEntity = $this->entityAttribute($rootReflection) ?? $entity;
        $inheritanceType = $this->inheritanceType($rootReflection);
        $table = $this->tableAttribute($inheritanceType === InheritanceType::SINGLE_TABLE ? $rootReflection : $reflection);
        $tableEntity = $inheritanceType === InheritanceType::SINGLE_TABLE ? $rootEntity : $entity;
        $tableName = $table instanceof Table && $table->name !== null
            ? $table->name
            : ($tableEntity->table ?? $this->names->tableName($rootReflection->getName()));
        $columnsByProperty = [];
        $columnsByName = [];
        $identifier = [];
        $embeddeds = [];

        foreach ($this->mappedProperties($reflection, $inheritanceRoot) as $property) {
            $column = $this->columnAttribute($property);

            if ($column instanceof Column) {
                $metadata = $this->columnMetadata($property, $column);
                $columnsByProperty[$metadata->propertyName] = $metadata;
                $columnsByName[$metadata->columnName] = $metadata;

                if ($metadata->primary) {
                    $identifier[] = $metadata->propertyName;
                }

                continue;
            }

            $embedded = $this->embeddedAttribute($property);

            if ($embedded instanceof Embedded) {
                foreach ($this->embeddedColumns($property, $embedded) as $embeddedColumn) {
                    $columnsByProperty[$embeddedColumn->propertyName] = $embeddedColumn;
                    $columnsByName[$embeddedColumn->columnName] = $embeddedColumn;
                }

                $embeddedClass = $this->embeddedClass($property, $embedded);
                $embeddeds[] = new EmbeddedMetadata(
                    $property->getName(),
                    $embeddedClass,
                    $this->embeddedPrefix($property, $embedded),
                    array_values(array_filter(
                        $columnsByProperty,
                        static fn (ColumnMetadata $column): bool => str_starts_with($column->propertyName, $property->getName() . '.'),
                    )),
                );
            }
        }

        $associationsByProperty = $this->associations($reflection, $tableName, $inheritanceRoot);
        $discriminator = $this->discriminatorColumn($rootReflection);
        $discriminatorMap = $this->discriminatorMap($rootReflection);
        $discriminatorValue = $this->discriminatorValue($className, $discriminatorMap);
        $cache = $this->cacheAttribute($reflection) ?? ($inheritanceRoot instanceof \ReflectionClass ? $this->cacheAttribute($inheritanceRoot) : null);

        if (
            $inheritanceType !== InheritanceType::NONE
            && $discriminator instanceof DiscriminatorColumn
            && !isset($columnsByName[$discriminator->name])
        ) {
            $columnsByName[$discriminator->name] = new ColumnMetadata(
                $discriminator->name,
                $discriminator->name,
                $discriminator->type,
                $discriminator->length,
            );
        }

        foreach ($associationsByProperty as $association) {
            foreach ($association->joinColumns as $joinColumn) {
                if ($columnsByName[$joinColumn->name] ?? null) {
                    continue;
                }

                $columnsByName[$joinColumn->name] = new ColumnMetadata(
                    $association->propertyName,
                    $joinColumn->name,
                    'string',
                    191,
                    $joinColumn->nullable,
                    false,
                    false,
                    $joinColumn->unique,
                );
            }
        }

        $indexes = $this->indexes($reflection, $tableName, $columnsByProperty, $table);

        foreach ($columnsByName as $column) {
            if (!$column->unique || $column->primary) {
                continue;
            }

            $indexes[] = new IndexMetadata(
                $this->names->indexName($tableName, [$column->columnName], true),
                [$column->columnName],
                true,
            );
        }

        $metadata = new ClassMetadata(
            $className,
            $tableName,
            $entity->repositoryClass,
            $columnsByProperty,
            $columnsByName,
            $identifier,
            $indexes,
            $entity->readOnly,
            $associationsByProperty,
            $embeddeds,
            $this->lifecycleCallbacks($reflection),
            $this->changeTrackingPolicy($reflection),
            $this->entityListeners($reflection),
            $inheritanceType,
            $discriminator?->name,
            $discriminator?->type,
            $discriminatorMap,
            $discriminatorValue,
            $inheritanceRoot?->getName(),
            $cache instanceof CacheMapping ? ($cache->region ?? $this->names->tableName($className)) : null,
            $cache instanceof CacheMapping ? $cache->usage : null,
        );
        $this->cache?->set($cacheKey, $metadata);

        return $this->metadata[$className] = $metadata;
    }

    /**
     * @param class-string $className
     * @return \ReflectionClass<object>
     */
    private function reflection(string $className): \ReflectionClass
    {
        if (!class_exists($className)) {
            throw new \InvalidArgumentException(sprintf('Class "%s" does not exist.', $className));
        }

        return new \ReflectionClass($className);
    }

    /** @param \ReflectionClass<object> $reflection */
    private function entityAttribute(\ReflectionClass $reflection): ?Entity
    {
        return $this->attributeInstance($reflection, Entity::class);
    }

    /** @param \ReflectionClass<object> $reflection */
    private function tableAttribute(\ReflectionClass $reflection): ?Table
    {
        return $this->attributeInstance($reflection, Table::class);
    }

    private function columnAttribute(\ReflectionProperty $property): ?Column
    {
        return $this->attributeInstance($property, Column::class);
    }

    private function embeddedAttribute(\ReflectionProperty $property): ?Embedded
    {
        return $this->attributeInstance($property, Embedded::class);
    }

    /**
     * @template T of object
     * @param \ReflectionClass<object>|\ReflectionProperty|\ReflectionMethod $reflection
     * @param class-string<T> $attributeClass
     * @return T|null
     */
    private function attributeInstance(\ReflectionClass|\ReflectionProperty|\ReflectionMethod $reflection, string $attributeClass): ?object
    {
        $attribute = $reflection->getAttributes($attributeClass)[0] ?? null;

        return $attribute instanceof \ReflectionAttribute ? $attribute->newInstance() : null;
    }

    /**
     * @param \ReflectionClass<object>|\ReflectionProperty|\ReflectionMethod $reflection
     * @param class-string $attributeClass
     */
    private function hasAttribute(\ReflectionClass|\ReflectionProperty|\ReflectionMethod $reflection, string $attributeClass): bool
    {
        return $reflection->getAttributes($attributeClass) !== [];
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @param \ReflectionClass<object>|null $inheritanceRoot
     * @return list<\ReflectionProperty>
     */
    private function mappedProperties(\ReflectionClass $reflection, ?\ReflectionClass $inheritanceRoot = null): array
    {
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            $declaring = $property->getDeclaringClass();

            if (
                $declaring->getName() === $reflection->getName()
                || $this->hasAttribute($declaring, MappedSuperclass::class)
                || (
                    $inheritanceRoot instanceof \ReflectionClass
                    && (
                        $declaring->getName() === $inheritanceRoot->getName()
                        || $declaring->isSubclassOf($inheritanceRoot->getName())
                    )
                )
            ) {
                $properties[] = $property;
            }
        }

        return $properties;
    }

    /** @param list<string> $propertyPath */
    private function columnMetadata(
        \ReflectionProperty $property,
        Column $column,
        string $prefix = '',
        array $propertyPath = [],
        ?string $propertyName = null,
    ): ColumnMetadata {

        $isIdentifier = $prefix === '' && $this->hasAttribute($property, Id::class);

        return new ColumnMetadata(
            $propertyName ?? $property->getName(),
            $prefix . ($column->name ?? $this->names->columnName($property->getName())),
            $column->type,
            $column->length,
            $column->nullable,
            $isIdentifier,
            $prefix === '' && $this->hasAttribute($property, GeneratedValue::class),
            $column->unique,
            $column->unsigned,
            $column->precision,
            $column->scale,
            $column->default,
            $column->options,
            $propertyPath,
            $column->enumType,
            $prefix === '' && $this->hasAttribute($property, Version::class),
        );
    }

    /** @return list<ColumnMetadata> */
    private function embeddedColumns(\ReflectionProperty $property, Embedded $embedded): array
    {
        $embeddedClass = $this->embeddedClass($property, $embedded);
        $reflection = $this->reflection($embeddedClass);

        if (!$this->hasAttribute($reflection, Embeddable::class)) {
            throw new \InvalidArgumentException(sprintf('Embedded class "%s" is not marked as embeddable.', $embeddedClass));
        }

        $columns = [];
        $prefix = $this->embeddedPrefix($property, $embedded);

        foreach ($reflection->getProperties() as $embeddedProperty) {
            $column = $this->columnAttribute($embeddedProperty);

            if (!$column instanceof Column) {
                continue;
            }

            $columns[] = $this->columnMetadata(
                $embeddedProperty,
                $column,
                $prefix,
                [$property->getName(), $embeddedProperty->getName()],
                $property->getName() . '.' . $embeddedProperty->getName(),
            );
        }

        return $columns;
    }

    /** @return class-string */
    private function embeddedClass(\ReflectionProperty $property, Embedded $embedded): string
    {
        if ($embedded->class !== null) {
            return $embedded->class;
        }

        $type = $property->getType();

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            /** @var class-string $className */
            $className = $type->getName();

            return $className;
        }

        throw new \InvalidArgumentException(sprintf('Cannot infer embedded class for "%s".', $property->getName()));
    }

    private function embeddedPrefix(\ReflectionProperty $property, Embedded $embedded): string
    {
        if ($embedded->columnPrefix === false) {
            return '';
        }

        if (is_string($embedded->columnPrefix)) {
            return $embedded->columnPrefix;
        }

        return $this->names->columnName($property->getName()) . '_';
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @param \ReflectionClass<object>|null $inheritanceRoot
     * @return array<string, AssociationMetadata>
     */
    private function associations(\ReflectionClass $reflection, string $tableName, ?\ReflectionClass $inheritanceRoot = null): array
    {
        $associations = [];

        foreach ($this->mappedProperties($reflection, $inheritanceRoot) as $property) {
            $metadata = $this->association($property, $tableName);

            if ($metadata instanceof AssociationMetadata) {
                $associations[$metadata->propertyName] = $metadata;
            }
        }

        return $associations;
    }

    private function association(\ReflectionProperty $property, string $tableName): ?AssociationMetadata
    {
        $cache = $this->cacheAttribute($property);
        $cacheRegion = $cache instanceof CacheMapping
            ? ($cache->region ?? $tableName . '.' . $property->getName())
            : null;
        $cacheUsage = $cache instanceof CacheMapping ? $cache->usage : null;

        $manyToOne = $this->attributeInstance($property, ManyToOne::class);
        if ($manyToOne instanceof ManyToOne) {
            return new AssociationMetadata(
                $property->getName(),
                AssociationMetadata::MANY_TO_ONE,
                $manyToOne->targetEntity,
                $manyToOne->cascade,
                $manyToOne->fetch,
                null,
                $manyToOne->inversedBy,
                $this->joinColumns($property),
                null,
                null,
                false,
                $this->orderBy($property),
                $cacheRegion,
                $cacheUsage,
            );
        }

        $oneToOne = $this->attributeInstance($property, OneToOne::class);
        if ($oneToOne instanceof OneToOne) {
            return new AssociationMetadata(
                $property->getName(),
                AssociationMetadata::ONE_TO_ONE,
                $oneToOne->targetEntity,
                $oneToOne->cascade,
                $oneToOne->fetch,
                $oneToOne->mappedBy,
                $oneToOne->inversedBy,
                $oneToOne->mappedBy === null ? $this->joinColumns($property, unique: true) : [],
                null,
                null,
                $oneToOne->orphanRemoval,
                $this->orderBy($property),
                $cacheRegion,
                $cacheUsage,
            );
        }

        $oneToMany = $this->attributeInstance($property, OneToMany::class);
        if ($oneToMany instanceof OneToMany) {
            return new AssociationMetadata(
                $property->getName(),
                AssociationMetadata::ONE_TO_MANY,
                $oneToMany->targetEntity,
                $oneToMany->cascade,
                $oneToMany->fetch,
                $oneToMany->mappedBy,
                null,
                [],
                null,
                $oneToMany->indexBy,
                $oneToMany->orphanRemoval,
                $this->orderBy($property),
                $cacheRegion,
                $cacheUsage,
            );
        }

        $manyToMany = $this->attributeInstance($property, ManyToMany::class);
        if ($manyToMany instanceof ManyToMany) {
            return new AssociationMetadata(
                $property->getName(),
                AssociationMetadata::MANY_TO_MANY,
                $manyToMany->targetEntity,
                $manyToMany->cascade,
                $manyToMany->fetch,
                $manyToMany->mappedBy,
                $manyToMany->inversedBy,
                [],
                $manyToMany->mappedBy === null ? $this->joinTable($property, $tableName, $manyToMany->targetEntity) : null,
                $manyToMany->indexBy,
                false,
                $this->orderBy($property),
                $cacheRegion,
                $cacheUsage,
            );
        }

        return null;
    }

    /** @return list<JoinColumnMetadata> */
    private function joinColumns(\ReflectionProperty $property, bool $unique = false): array
    {
        $columns = [];

        foreach ($property->getAttributes(JoinColumn::class) as $attribute) {
            $joinColumn = $attribute->newInstance();

            if (!$joinColumn instanceof JoinColumn) {
                continue;
            }

            $columns[] = new JoinColumnMetadata(
                $joinColumn->name ?? $this->names->columnName($property->getName()) . '_id',
                $joinColumn->referencedColumnName,
                $joinColumn->nullable,
                $joinColumn->unique || $unique,
                $joinColumn->onDelete,
            );
        }

        if ($columns !== []) {
            return $columns;
        }

        return [new JoinColumnMetadata($this->names->columnName($property->getName()) . '_id', unique: $unique)];
    }

    /** @param class-string $targetEntity */
    private function joinTable(\ReflectionProperty $property, string $sourceTable, string $targetEntity): JoinTableMetadata
    {
        $joinTable = $this->attributeInstance($property, JoinTable::class);
        $targetTable = $this->names->tableName($targetEntity);
        $sourceShort = $this->names->shortName($sourceTable);
        $targetShort = $this->names->shortName($targetTable);

        if ($joinTable instanceof JoinTable) {
            return new JoinTableMetadata(
                $joinTable->name ?? $sourceTable . '_' . $this->names->columnName($property->getName()),
                $this->joinTableColumns($joinTable->joinColumns, $sourceShort . '_id', JoinColumn::class),
                $this->joinTableColumns($joinTable->inverseJoinColumns, $targetShort . '_id', InverseJoinColumn::class),
            );
        }

        return new JoinTableMetadata(
            $sourceTable . '_' . $targetTable,
            [new JoinColumnMetadata($sourceShort . '_id', nullable: false)],
            [new JoinColumnMetadata($targetShort . '_id', nullable: false)],
        );
    }

    /**
     * @param list<JoinColumn|InverseJoinColumn> $columns
     * @param class-string $expectedClass
     * @return list<JoinColumnMetadata>
     */
    private function joinTableColumns(array $columns, string $defaultName, string $expectedClass): array
    {
        if ($columns === []) {
            return [new JoinColumnMetadata($defaultName, nullable: false)];
        }

        $metadata = [];

        foreach ($columns as $column) {
            if (!$column instanceof $expectedClass) {
                continue;
            }

            $metadata[] = new JoinColumnMetadata(
                $column->name ?? $defaultName,
                $column->referencedColumnName,
                $column->nullable,
                $column->unique,
                $column->onDelete,
            );
        }

        return $metadata;
    }

    /** @return array<string, 'ASC'|'DESC'|string> */
    private function orderBy(\ReflectionProperty $property): array
    {
        $orderBy = $this->attributeInstance($property, OrderBy::class);

        return $orderBy instanceof OrderBy ? $orderBy->value : [];
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @param array<string, ColumnMetadata> $columnsByProperty
     * @return list<IndexMetadata>
     */
    private function indexes(
        \ReflectionClass $reflection,
        string $tableName,
        array $columnsByProperty,
        ?Table $table,
    ): array {

        $indexes = [];
        $indexAttributes = $reflection->getAttributes(Index::class);
        $uniqueAttributes = $reflection->getAttributes(UniqueConstraint::class);

        foreach ($table instanceof Table ? $table->indexes : [] as $index) {
            $indexes[] = $this->indexMetadata($tableName, $columnsByProperty, $index);
        }

        foreach ($table instanceof Table ? $table->uniqueConstraints : [] as $constraint) {
            $indexes[] = $this->uniqueConstraintMetadata($tableName, $columnsByProperty, $constraint);
        }

        foreach ($indexAttributes as $attribute) {
            $index = $attribute->newInstance();

            if ($index instanceof Index) {
                $indexes[] = $this->indexMetadata($tableName, $columnsByProperty, $index);
            }
        }

        foreach ($uniqueAttributes as $attribute) {
            $constraint = $attribute->newInstance();

            if ($constraint instanceof UniqueConstraint) {
                $indexes[] = $this->uniqueConstraintMetadata($tableName, $columnsByProperty, $constraint);
            }
        }

        return array_values(array_filter($indexes));
    }

    /**
     * @param array<string, ColumnMetadata> $columnsByProperty
     */
    private function indexMetadata(string $tableName, array $columnsByProperty, Index $index): ?IndexMetadata
    {
        if ($index->columns === []) {
            return null;
        }

        $columns = $this->columnNames($index->columns, $columnsByProperty);

        return new IndexMetadata(
            $index->name ?? $this->names->indexName($tableName, $columns, $index->unique),
            $columns,
            $index->unique,
        );
    }

    /**
     * @param array<string, ColumnMetadata> $columnsByProperty
     */
    private function uniqueConstraintMetadata(
        string $tableName,
        array $columnsByProperty,
        UniqueConstraint $constraint,
    ): ?IndexMetadata {

        if ($constraint->columns === []) {
            return null;
        }

        $columns = $this->columnNames($constraint->columns, $columnsByProperty);

        return new IndexMetadata(
            $constraint->name ?? $this->names->indexName($tableName, $columns, true),
            $columns,
            true,
        );
    }

    /**
     * @param list<string> $columns
     * @param array<string, ColumnMetadata> $columnsByProperty
     * @return list<string>
     */
    private function columnNames(array $columns, array $columnsByProperty): array
    {
        return array_values(array_map(
            static fn (string $column): string => $columnsByProperty[$column]->columnName ?? $column,
            $columns,
        ));
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @return array<string, list<string>>
     */
    private function lifecycleCallbacks(\ReflectionClass $reflection): array
    {
        if (!$this->hasAttribute($reflection, HasLifecycleCallbacks::class)) {
            return [];
        }

        $events = [
            'prePersist' => PrePersist::class,
            'postPersist' => PostPersist::class,
            'preUpdate' => PreUpdate::class,
            'postUpdate' => PostUpdate::class,
            'preRemove' => PreRemove::class,
            'postRemove' => PostRemove::class,
            'postLoad' => PostLoad::class,
            'preFlush' => PreFlush::class,
            'postFlush' => PostFlush::class,
        ];
        $callbacks = [];

        foreach ($reflection->getMethods() as $method) {
            foreach ($events as $event => $attributeClass) {
                if ($this->hasAttribute($method, $attributeClass)) {
                    $callbacks[$event][] = $method->getName();
                }
            }
        }

        return $callbacks;
    }

    /** @param \ReflectionClass<object> $reflection */
    private function changeTrackingPolicy(\ReflectionClass $reflection): string
    {
        $policy = $this->attributeInstance($reflection, ChangeTrackingPolicy::class);

        return $policy instanceof ChangeTrackingPolicy ? $policy->value : ChangeTrackingPolicy::DEFERRED_IMPLICIT;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @return array<string, list<class-string>>
     */
    private function entityListeners(\ReflectionClass $reflection): array
    {
        $attribute = $this->attributeInstance($reflection, EntityListeners::class);

        if (!$attribute instanceof EntityListeners) {
            return [];
        }

        $callbacks = [];
        $events = [
            'prePersist',
            'postPersist',
            'preUpdate',
            'postUpdate',
            'preRemove',
            'postRemove',
            'postLoad',
            'preFlush',
            'postFlush',
        ];

        foreach ($events as $event) {
            foreach ($attribute->listeners as $listener) {
                if (!class_exists($listener) || !method_exists($listener, $event)) {
                    continue;
                }

                $callbacks[$event][] = $listener;
            }
        }

        return $callbacks;
    }

    /** @param \ReflectionClass<object> $reflection */
    private function inheritanceType(\ReflectionClass $reflection): string
    {
        $attribute = $this->attributeInstance($reflection, InheritanceType::class);

        return $attribute instanceof InheritanceType ? $attribute->value : InheritanceType::NONE;
    }

    /** @param \ReflectionClass<object> $reflection */
    private function discriminatorColumn(\ReflectionClass $reflection): ?DiscriminatorColumn
    {
        return $this->attributeInstance($reflection, DiscriminatorColumn::class);
    }

    /** @param \ReflectionClass<object>|\ReflectionProperty $reflection */
    private function cacheAttribute(\ReflectionClass|\ReflectionProperty $reflection): ?CacheMapping
    {
        return $this->attributeInstance($reflection, CacheMapping::class);
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @return array<string, class-string>
     */
    private function discriminatorMap(\ReflectionClass $reflection): array
    {
        $attribute = $this->attributeInstance($reflection, DiscriminatorMap::class);

        return $attribute instanceof DiscriminatorMap ? $attribute->map : [];
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @return \ReflectionClass<object>|null
     */
    private function inheritanceRoot(\ReflectionClass $reflection): ?\ReflectionClass
    {
        $root = null;
        $current = $reflection;

        do {
            if (
                $this->entityAttribute($current) instanceof Entity
                && $this->inheritanceType($current) !== InheritanceType::NONE
            ) {
                $root = $current;
            }

            $current = $current->getParentClass();
        } while ($current instanceof \ReflectionClass);

        return $root;
    }

    /** @param array<string, class-string> $map */
    private function discriminatorValue(string $className, array $map): ?string
    {
        foreach ($map as $value => $mappedClass) {
            if ($mappedClass === $className) {
                return (string) $value;
            }
        }

        return null;
    }
}

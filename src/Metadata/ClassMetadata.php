<?php

declare(strict_types=1);

namespace SymPress\Orm\Metadata;

final readonly class ClassMetadata
{
    /**
     * @param class-string $className
     * @param class-string|null $repositoryClass
     * @param array<string, ColumnMetadata> $columnsByProperty
     * @param array<string, ColumnMetadata> $columnsByName
     * @param list<string> $identifier
     * @param list<IndexMetadata> $indexes
     * @param array<string, AssociationMetadata> $associationsByProperty
     * @param list<EmbeddedMetadata> $embeddeds
     * @param array<string, list<string>> $lifecycleCallbacks
     * @param array<string, list<class-string>> $entityListeners
     * @param array<string, class-string> $discriminatorMap
     */
    public function __construct(
        public string $className,
        public string $tableName,
        public ?string $repositoryClass,
        public array $columnsByProperty,
        public array $columnsByName,
        public array $identifier,
        public array $indexes,
        public bool $readOnly = false,
        public array $associationsByProperty = [],
        public array $embeddeds = [],
        public array $lifecycleCallbacks = [],
        public string $changeTrackingPolicy = 'DEFERRED_IMPLICIT',
        public array $entityListeners = [],
        public string $inheritanceType = 'NONE',
        public ?string $discriminatorColumn = null,
        public ?string $discriminatorType = null,
        public array $discriminatorMap = [],
        public ?string $discriminatorValue = null,
        public ?string $rootClassName = null,
        public ?string $cacheRegion = null,
        public ?string $cacheUsage = null,
    ) {
    }

    /** @return list<ColumnMetadata> */
    public function columns(): array
    {
        return array_values($this->columnsByName);
    }

    public function columnForProperty(string $propertyName): ?ColumnMetadata
    {
        return $this->columnsByProperty[$propertyName] ?? null;
    }

    public function columnForName(string $columnName): ?ColumnMetadata
    {
        return $this->columnsByName[$columnName] ?? null;
    }

    public function tableName(string $prefix = ''): string
    {
        if ($prefix === '' || str_starts_with($this->tableName, $prefix)) {
            return $this->tableName;
        }

        return $prefix . $this->tableName;
    }

    public function identifierColumn(): ?ColumnMetadata
    {
        $property = $this->identifier[0] ?? null;

        return is_string($property) ? $this->columnForProperty($property) : null;
    }

    /** @return list<ColumnMetadata> */
    public function identifierColumns(): array
    {
        return array_values(array_filter(array_map(
            fn (string $property): ?ColumnMetadata => $this->columnForProperty($property),
            $this->identifier,
        )));
    }

    public function isIdentifierComposite(): bool
    {
        return count($this->identifier) > 1;
    }

    /** @return list<AssociationMetadata> */
    public function associations(): array
    {
        return array_values($this->associationsByProperty);
    }

    public function associationForProperty(string $propertyName): ?AssociationMetadata
    {
        return $this->associationsByProperty[$propertyName] ?? null;
    }

    public function versionColumn(): ?ColumnMetadata
    {
        foreach ($this->columns() as $column) {
            if ($column->version) {
                return $column;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function lifecycleCallbacks(string $event): array
    {
        return $this->lifecycleCallbacks[$event] ?? [];
    }

    /** @return list<class-string> */
    public function entityListeners(string $event): array
    {
        return $this->entityListeners[$event] ?? [];
    }
}

<?php

declare(strict_types=1);

namespace SymPress\Orm\Metadata;

final readonly class AssociationMetadata
{
    public const string MANY_TO_ONE = 'manyToOne';
    public const string ONE_TO_ONE = 'oneToOne';
    public const string ONE_TO_MANY = 'oneToMany';
    public const string MANY_TO_MANY = 'manyToMany';

    /**
     * @param class-string $targetEntity
     * @param list<string> $cascade
     * @param list<JoinColumnMetadata> $joinColumns
     * @param array<string, 'ASC'|'DESC'|string> $orderBy
     */
    public function __construct(
        public string $propertyName,
        public string $type,
        public string $targetEntity,
        public array $cascade = [],
        public string $fetch = 'LAZY',
        public ?string $mappedBy = null,
        public ?string $inversedBy = null,
        public array $joinColumns = [],
        public ?JoinTableMetadata $joinTable = null,
        public ?string $indexBy = null,
        public bool $orphanRemoval = false,
        public array $orderBy = [],
        public ?string $cacheRegion = null,
        public ?string $cacheUsage = null,
    ) {
    }

    public function isOwningSide(): bool
    {
        return $this->mappedBy === null;
    }

    public function isToOne(): bool
    {
        return in_array($this->type, [self::MANY_TO_ONE, self::ONE_TO_ONE], true);
    }

    public function isToMany(): bool
    {
        return in_array($this->type, [self::ONE_TO_MANY, self::MANY_TO_MANY], true);
    }

    public function cascades(string $operation): bool
    {
        return in_array('all', $this->cascade, true) || in_array($operation, $this->cascade, true);
    }
}

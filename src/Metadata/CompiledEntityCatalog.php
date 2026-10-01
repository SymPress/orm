<?php

declare(strict_types=1);

namespace SymPress\Orm\Metadata;

/** Immutable mapping objects constructed by the trusted compiled DI container. */
final readonly class CompiledEntityCatalog
{
    /**
     * @param array<string, list<class-string>> $groups
     * @param array<class-string, ClassMetadata> $metadata
     */
    public function __construct(public array $groups = [], public array $metadata = [])
    {
        foreach ($metadata as $class => $mapping) {
            if (!$mapping instanceof ClassMetadata || $class !== $mapping->className) {
                throw new \InvalidArgumentException('Compiled ORM metadata must be keyed by its mapped class.');
            }
        }
    }
}

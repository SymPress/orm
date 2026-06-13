<?php

declare(strict_types=1);

namespace SymPress\Orm\Metadata;

use SymPress\Orm\Exception\MappingException;
use SymPress\Orm\Mapping\InheritanceType;

final readonly class MappingValidator
{
    public function __construct(private MetadataFactory $metadataFactory)
    {
    }

    /**
     * @param list<class-string> $classes
     * @return list<string>
     */
    public function validate(array $classes): array
    {
        $errors = [];

        foreach ($classes as $className) {
            $metadata = $this->metadataFactory->getMetadataFor($className);
            $errors = [...$errors, ...$this->validateClass($metadata)];
        }

        return $errors;
    }

    /** @param list<class-string> $classes */
    public function assertValid(array $classes): void
    {
        $errors = $this->validate($classes);

        if ($errors !== []) {
            throw MappingException::invalid($errors);
        }
    }

    /** @return list<string> */
    private function validateClass(ClassMetadata $metadata): array
    {
        $errors = [];

        if ($metadata->identifier === []) {
            $errors[] = sprintf('Entity "%s" has no identifier.', $metadata->className);
        }

        $generatedIdentifiers = array_filter(
            $metadata->identifierColumns(),
            static fn (ColumnMetadata $column): bool => $column->generated,
        );

        if ($metadata->isIdentifierComposite() && $generatedIdentifiers !== []) {
            $errors[] = sprintf('Entity "%s" uses a generated value inside a composite identifier.', $metadata->className);
        }

        if ($metadata->inheritanceType !== InheritanceType::NONE) {
            if ($metadata->discriminatorColumn === null) {
                $errors[] = sprintf('Entity "%s" uses inheritance without a discriminator column.', $metadata->className);
            }

            if ($metadata->discriminatorMap === []) {
                $errors[] = sprintf('Entity "%s" uses inheritance without a discriminator map.', $metadata->className);
            }
        }

        foreach ($metadata->discriminatorMap as $value => $className) {
            if (!class_exists($className)) {
                $errors[] = sprintf('Discriminator "%s" on "%s" points to missing class "%s".', $value, $metadata->className, $className);
            }
        }

        foreach ($metadata->associations() as $association) {
            if (!class_exists($association->targetEntity)) {
                $errors[] = sprintf(
                    'Association "%s::$%s" points to missing target entity "%s".',
                    $metadata->className,
                    $association->propertyName,
                    $association->targetEntity,
                );
                continue;
            }

            $target = $this->metadataFactory->getMetadataFor($association->targetEntity);

            if ($association->mappedBy !== null && $target->associationForProperty($association->mappedBy) === null) {
                $errors[] = sprintf(
                    'Association "%s::$%s" maps by unknown target association "%s::$%s".',
                    $metadata->className,
                    $association->propertyName,
                    $target->className,
                    $association->mappedBy,
                );
            }

            if ($association->inversedBy !== null && $target->associationForProperty($association->inversedBy) === null) {
                $errors[] = sprintf(
                    'Association "%s::$%s" inversed by unknown target association "%s::$%s".',
                    $metadata->className,
                    $association->propertyName,
                    $target->className,
                    $association->inversedBy,
                );
            }

            if ($association->isOwningSide() && $association->isToOne() && $association->joinColumns === []) {
                $errors[] = sprintf('Owning association "%s::$%s" has no join column.', $metadata->className, $association->propertyName);
            }

            if ($association->type === AssociationMetadata::MANY_TO_MANY && $association->isOwningSide() && $association->joinTable === null) {
                $errors[] = sprintf('Owning many-to-many association "%s::$%s" has no join table.', $metadata->className, $association->propertyName);
            }
        }

        return $errors;
    }
}

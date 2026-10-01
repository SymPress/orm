<?php

declare(strict_types=1);

namespace SymPress\Orm\Compiler;

use SymPress\Orm\Metadata\AssociationMetadata;
use SymPress\Orm\Metadata\ClassMetadata;
use SymPress\Orm\Metadata\ColumnMetadata;
use SymPress\Orm\Metadata\CompiledEntityCatalog;
use SymPress\Orm\Metadata\EmbeddedMetadata;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\IndexMetadata;
use SymPress\Orm\Metadata\JoinColumnMetadata;
use SymPress\Orm\Metadata\JoinTableMetadata;
use SymPress\Orm\Metadata\MetadataFactory;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class EntityCatalogPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(EntityClassRegistry::class) || !$container->hasDefinition(MetadataFactory::class)) {
            return;
        }

        $parameters = $container->getParameterBag();
        /** @var array<string, array{path?: string, package?: string, type?: string, entry?: string}> $bundles */
        $bundles = $parameters->resolveValue($container->getParameter('kernel.bundles_metadata'));
        /** @var list<string> $paths */
        $paths = $parameters->resolveValue($container->getParameter('orm.entity_paths'));
        /** @var list<class-string>|array<string, list<class-string>> $classes */
        $classes = $parameters->resolveValue($container->getParameter('orm.entity_classes'));
        $factory = new MetadataFactory();
        $registry = new EntityClassRegistry($factory, $bundles, $paths, $classes);
        $groups = $registry->groups();

        foreach ($bundles as $bundle) {
            if (isset($bundle['path'])) {
                $paths[] = $bundle['path'] . '/src';
            }
        }

        foreach (array_unique($paths) as $path) {
            if (is_link($path)) {
                throw new \InvalidArgumentException('ORM entity directory roots must not be symlinks.');
            }
            $container->addResource(new FileExistenceResource($path));
            if (is_dir($path)) {
                $container->addResource(new DirectoryResource($path, '/\\.php$/D'));
            }
        }

        $pending = $registry->classes();
        $metadata = [];
        while ($pending !== []) {
            $class = array_shift($pending);
            if (isset($metadata[$class])) {
                continue;
            }

            $mapping = $factory->getMetadataFor($class);
            $metadata[$class] = $this->definition($mapping);
            foreach ($mapping->associations() as $association) {
                $pending[] = $association->targetEntity;
            }
            foreach ($mapping->discriminatorMap as $subclass) {
                $pending[] = $subclass;
            }
        }

        foreach ($factory->mappingResources() as $file) {
            $container->addResource(new FileResource($file));
        }

        ksort($metadata);
        $container->setDefinition(CompiledEntityCatalog::class, new Definition(CompiledEntityCatalog::class, [$groups, $metadata]));
        $container->getDefinition(MetadataFactory::class)->setArgument('$catalog', new Reference(CompiledEntityCatalog::class));
        $container->getDefinition(EntityClassRegistry::class)->setArgument('$catalog', new Reference(CompiledEntityCatalog::class));
    }

    /** Only immutable ORM DTO types can enter the trusted compiled container. */
    private function definition(
        ClassMetadata|ColumnMetadata|AssociationMetadata|EmbeddedMetadata|IndexMetadata|JoinColumnMetadata|JoinTableMetadata $metadata,
    ): Definition {

        return new Definition($metadata::class, array_values(array_map($this->value(...), get_object_vars($metadata))));
    }

    private function value(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->value(...), $value);
        }
        if (is_object($value)) {
            if (
                !$value instanceof ClassMetadata && !$value instanceof ColumnMetadata
                && !$value instanceof AssociationMetadata && !$value instanceof EmbeddedMetadata
                && !$value instanceof IndexMetadata && !$value instanceof JoinColumnMetadata
                && !$value instanceof JoinTableMetadata
            ) {
                throw new \InvalidArgumentException('Only immutable ORM metadata DTOs may be compiled.');
            }
            return $this->definition($value);
        }
        if ($value !== null && !is_scalar($value)) {
            throw new \InvalidArgumentException('ORM mapping values must be scalar, arrays or metadata DTOs.');
        }
        return $value;
    }
}

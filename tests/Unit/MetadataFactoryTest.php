<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\Animal;
use SymPress\Orm\Tests\Fixtures\CachedAuthor;
use SymPress\Orm\Tests\Fixtures\Dog;
use SymPress\Orm\Tests\Fixtures\EmailLog;

final class MetadataFactoryTest extends TestCase
{
    public function testItBuildsMetadataFromAttributes(): void
    {
        $metadata = (new MetadataFactory())->getMetadataFor(EmailLog::class);

        self::assertSame(EmailLog::class, $metadata->className);
        self::assertSame('sympress_mailer_logs', $metadata->tableName);
        self::assertSame(['id'], $metadata->identifier);
        self::assertSame('created_at', $metadata->columnForProperty('createdAt')?->columnName);
        self::assertSame('status_created', $metadata->indexes[0]?->name);
        self::assertSame(['status', 'created_at'], $metadata->indexes[0]?->columns);
    }

    public function testItReadsInheritanceDiscriminatorsAndSubclassColumns(): void
    {
        $factory = new MetadataFactory();
        $animal = $factory->getMetadataFor(Animal::class);
        $dog = $factory->getMetadataFor(Dog::class);

        self::assertSame('SINGLE_TABLE', $animal->inheritanceType);
        self::assertSame('kind', $animal->discriminatorColumn);
        self::assertSame('dog', $dog->discriminatorValue);
        self::assertSame(Animal::class, $dog->rootClassName);
        self::assertSame('sympress_animals', $dog->tableName);
        self::assertSame('bark_volume', $dog->columnForProperty('barkVolume')?->columnName);
    }

    public function testItHydratesSingleTableInheritanceRowsAsSubclass(): void
    {
        $factory = new MetadataFactory();
        $registry = new EntityClassRegistry($factory, classes: [Animal::class, Dog::class]);
        $entityManager = new EntityManager($factory, $registry, new EntityHydrator(), new \wpdb());

        $entity = (new EntityHydrator())->hydrate(
            $factory->getMetadataFor(Animal::class),
            ['id' => 'dog-1', 'name' => 'Ada', 'kind' => 'dog', 'bark_volume' => 4],
            $entityManager,
        );

        self::assertInstanceOf(Dog::class, $entity);
        self::assertSame(4, $entity->barkVolume);
    }

    public function testItRegistersSingleTableInheritanceQueriesByConcreteClass(): void
    {
        $factory = new MetadataFactory();
        $registry = new EntityClassRegistry($factory, classes: [Animal::class, Dog::class]);
        $database = new \wpdb();
        $database->resultRows = [
            ['id' => 'dog-1', 'name' => 'Ada', 'kind' => 'dog', 'bark_volume' => 4],
        ];
        $entityManager = new EntityManager($factory, $registry, new EntityHydrator(), $database);

        $dog = $entityManager->createQuery('SELECT a FROM Animal a')->getResult()[0] ?? null;

        self::assertInstanceOf(Dog::class, $dog);
        self::assertSame($dog, $entityManager->find(Dog::class, 'dog-1'));
    }

    public function testItReadsEntityAndAssociationCacheMetadata(): void
    {
        $metadata = (new MetadataFactory())->getMetadataFor(CachedAuthor::class);

        self::assertSame('authors', $metadata->cacheRegion);
        self::assertSame('READ_ONLY', $metadata->cacheUsage);
        self::assertSame('author_articles', $metadata->associationForProperty('articles')?->cacheRegion);
    }
}

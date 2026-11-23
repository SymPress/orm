<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\DelegatingEntityManager;
use SymPress\Orm\Tests\Fixtures\MutableEmailLog;

final class EntityManagerInterfaceTest extends TestCase
{
    public function testDecoratorOfTheEntityManagerInterfaceRoundTripsAnEntity(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [MutableEmailLog::class]);
        $database = new \wpdb();
        $manager = new DelegatingEntityManager(new EntityManager($metadataFactory, $registry, new EntityHydrator(), $database));

        $manager->persist(new MutableEmailLog('log-1', new \DateTimeImmutable('2026-06-13 10:00:00'), 'queued'));
        $manager->flush();
        $manager->clear();
        $database->resultRows = [
            ['id' => 'log-1', 'created_at' => '2026-06-13 10:00:00', 'status' => 'queued'],
        ];
        $found = $manager->find(MutableEmailLog::class, 'log-1');

        self::assertSame('queued', $database->inserted[0]['data']['status']);
        self::assertInstanceOf(MutableEmailLog::class, $found);
        self::assertSame('2026-06-13 10:00:00', $found->createdAt->format('Y-m-d H:i:s'));
        self::assertTrue($manager->contains($found));
    }
}

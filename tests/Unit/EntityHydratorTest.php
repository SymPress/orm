<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\MailStatus;
use SymPress\Orm\Tests\Fixtures\Priority;
use SymPress\Orm\Tests\Fixtures\TypeCatalogEntity;

final class EntityHydratorTest extends TestCase
{
    public function testEveryColumnTypeHydratesFromWpdbStringRowAndExtractsBack(): void
    {
        $metadata = (new MetadataFactory())->getMetadataFor(TypeCatalogEntity::class);
        $hydrator = new EntityHydrator();
        // wpdb returns every column as a string, and SQL NULL as null.
        $row = [
            'id' => '0b9f7c4e-5a1d-4c2e-9f3a-7d6b8e1c2a40',
            'title' => 'Queued mail',
            'body' => "Line one\nLine two",
            'payload' => '{"to":"a@example.com","retries":[1,2]}',
            'tags' => '["urgent",3]',
            'labels' => '["a","b"]',
            'rank' => '-3',
            'attempts' => '42',
            'bytes' => '9007199254740993',
            'active' => '1',
            'archived' => '0',
            'ratio' => '0.25',
            'score' => '0.0000125',
            'price' => '12.50',
            'day' => '2026-11-23',
            'day_immutable' => '2026-11-24',
            'at' => '2026-11-23 08:15:30',
            'at_immutable' => '2026-11-23 09:45:00',
            'clock' => '08:15:30',
            'clock_immutable' => '23:59:59',
            'status' => 'queued',
            'priority' => '2',
            'note' => null,
        ];

        $entity = $hydrator->hydrate($metadata, $row);

        self::assertInstanceOf(TypeCatalogEntity::class, $entity);
        self::assertSame(['to' => 'a@example.com', 'retries' => [1, 2]], $entity->payload);
        self::assertSame(['urgent', 3], $entity->tags);
        self::assertSame(['a', 'b'], $entity->labels);
        self::assertSame(-3, $entity->rank);
        self::assertSame(42, $entity->attempts);
        self::assertSame(9007199254740993, $entity->bytes);
        self::assertTrue($entity->active);
        self::assertFalse($entity->archived);
        self::assertSame(0.25, $entity->ratio);
        self::assertSame(0.0000125, $entity->score);
        self::assertSame(12.5, $entity->price);
        self::assertSame('2026-11-23', $entity->day->format('Y-m-d'));
        self::assertSame('2026-11-24', $entity->dayImmutable->format('Y-m-d'));
        self::assertSame('2026-11-23 08:15:30', $entity->at->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-23 09:45:00', $entity->atImmutable->format('Y-m-d H:i:s'));
        self::assertSame('08:15:30', $entity->clock->format('H:i:s'));
        self::assertSame('23:59:59', $entity->clockImmutable->format('H:i:s'));
        self::assertSame(MailStatus::Queued, $entity->status);
        self::assertSame(Priority::High, $entity->priority);
        self::assertNull($entity->note);

        self::assertSame([
            'id' => '0b9f7c4e-5a1d-4c2e-9f3a-7d6b8e1c2a40',
            'title' => 'Queued mail',
            'body' => "Line one\nLine two",
            'payload' => '{"to":"a@example.com","retries":[1,2]}',
            'tags' => '["urgent",3]',
            'labels' => '["a","b"]',
            'rank' => -3,
            'attempts' => 42,
            'bytes' => 9007199254740993,
            'active' => 1,
            'archived' => 0,
            'ratio' => 0.25,
            'score' => 0.0000125,
            'price' => 12.5,
            'day' => '2026-11-23',
            'day_immutable' => '2026-11-24',
            'at' => '2026-11-23 08:15:30',
            'at_immutable' => '2026-11-23 09:45:00',
            'clock' => '08:15:30',
            'clock_immutable' => '23:59:59',
            'status' => 'queued',
            'priority' => 2,
            'note' => null,
        ], $hydrator->extract($entity, $metadata));
    }

    public function testTimeColumnsHydrateOnTheEpochDateSoTheSameRowYieldsEqualValues(): void
    {
        $metadata = (new MetadataFactory())->getMetadataFor(TypeCatalogEntity::class);
        $hydrator = new EntityHydrator();
        $clock = $metadata->columnForProperty('clock');
        $clockImmutable = $metadata->columnForProperty('clockImmutable');
        self::assertNotNull($clock);
        self::assertNotNull($clockImmutable);

        $first = $hydrator->fromDatabase($clock, '08:15:30');
        $second = $hydrator->fromDatabase($clock, '08:15:30');
        $immutable = $hydrator->fromDatabase($clockImmutable, '23:59:59');

        self::assertInstanceOf(\DateTime::class, $first);
        self::assertInstanceOf(\DateTimeImmutable::class, $immutable);
        self::assertSame('1970-01-01 08:15:30', $first->format('Y-m-d H:i:s'));
        self::assertSame('1970-01-01 23:59:59', $immutable->format('Y-m-d H:i:s'));
        self::assertEquals($first, $second);
        self::assertSame('08:15:30', $hydrator->toDatabase($clock, $first));
        self::assertSame('23:59:59', $hydrator->toDatabase($clockImmutable, $immutable));
    }
}

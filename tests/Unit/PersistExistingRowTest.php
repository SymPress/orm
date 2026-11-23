<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\EntityState;
use SymPress\Orm\Event\Events;
use SymPress\Orm\Event\LifecycleEventArgs;
use SymPress\Orm\Exception\OptimisticLockException;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\Article;
use SymPress\Orm\Tests\Fixtures\Author;
use SymPress\Orm\Tests\Fixtures\ExplicitEmailLog;
use SymPress\Orm\Tests\Fixtures\TypeCatalogEntity;
use SymPress\Orm\Tests\Fixtures\VersionedEmailLog;

final class PersistExistingRowTest extends TestCase
{
    private \wpdb $database;

    protected function setUp(): void
    {
        parent::setUp();

        // Answers a SELECT with the rows of its table, narrowed to the requested id; an UPDATE that expects another
        // version than the stored one matches no row, as in the database.
        $this->database = new class extends \wpdb {
            /** @var array<string, list<array<string, mixed>>> */
            public array $rows = [];

            public function get_results(string $query, string|int $output = ARRAY_A): array
            {
                $id = preg_match("/`id` = '([^']*)'/", $query, $match) === 1 ? $match[1] : null;

                foreach ($this->rows as $table => $rows) {
                    if (str_contains($query, '`' . $table . '`')) {
                        return array_values(array_filter($rows, static fn (array $row): bool => $id === null || $row['id'] === $id));
                    }
                }

                return [];
            }

            public function update(string $table, array $data, array $where): bool|int
            {
                parent::update($table, $data, $where);
                $stored = $this->rows[$table][0]['version'] ?? null;

                return array_key_exists('version', $where) && (string) $where['version'] !== (string) $stored ? 0 : 1;
            }
        };
        // Until 0.6.0 persist() only counted the row and attached the object as it was.
        $this->database->countResult = 1;
        $this->database->rows = [
            'wp_sympress_articles' => [['id' => 'a1', 'title' => 'Old', 'author_id' => 'ada']],
            'wp_sympress_authors' => [['id' => 'ada', 'name' => 'Ada'], ['id' => 'bob', 'name' => 'Bob']],
        ];
    }

    public function testPersistingAnObjectForAnExistingRowWritesExactlyTheChangedColumns(): void
    {
        $manager = $this->manager();
        $events = [];
        foreach ([Events::PRE_PERSIST, Events::PRE_UPDATE, Events::POST_UPDATE] as $event) {
            $manager->getEventManager()->addEventListener($event, static function (LifecycleEventArgs $args) use (&$events, $event): void {
                $events[] = $event;
            });
        }

        $manager->persist(new Article('a1', 'New', $manager->getReference(Author::class, 'ada')));
        $manager->flush();

        self::assertSame([], $this->database->inserted);
        self::assertSame([['table' => 'wp_sympress_articles', 'data' => ['title' => 'New'], 'where' => ['id' => 'a1']]], $this->database->updated);
        self::assertSame([Events::PRE_UPDATE, Events::POST_UPDATE], $events);
    }

    public function testObjectThatEqualsItsRowWritesNothing(): void
    {
        $manager = $this->manager();

        $manager->persist(new Article('a1', 'Old', $manager->getReference(Author::class, 'ada')));
        $manager->flush();

        self::assertSame([], $this->database->inserted);
        self::assertSame([], $this->database->updated);
    }

    public function testChangedToOneKeyIsWritten(): void
    {
        $manager = $this->manager();

        $manager->persist(new Article('a1', 'Old', $manager->getReference(Author::class, 'bob')));
        $manager->flush();

        self::assertSame([['table' => 'wp_sympress_articles', 'data' => ['author_id' => 'bob'], 'where' => ['id' => 'a1']]], $this->database->updated);
    }

    public function testEveryColumnTypeRoundTripsWithoutASpuriousUpdate(): void
    {
        $row = [
            'id' => 'g-1',
            'title' => 'T',
            'body' => 'B',
            'payload' => '{"a":1,"b":[true,null]}',
            'tags' => '[1,"x"]',
            'labels' => '["a","b"]',
            'rank' => '3',
            'attempts' => '7',
            'bytes' => '9007199254740993',
            'active' => '1',
            'archived' => '0',
            'ratio' => '0.5',
            'score' => '1.25',
            'price' => '9.90',
            'day' => '2026-11-23',
            'day_immutable' => '2026-11-24',
            'at' => '2026-11-23 10:00:00',
            'at_immutable' => '2026-11-23 11:00:00',
            'clock' => '10:30:00',
            'clock_immutable' => '11:45:00',
            'status' => 'queued',
            'priority' => '2',
            'note' => null,
        ];
        $this->database->rows = ['wp_type_catalog' => [$row]];
        // The same row loaded through find() in another entity manager, then persisted here as an unmanaged object.
        $copy = $this->manager()->find(TypeCatalogEntity::class, 'g-1');
        self::assertInstanceOf(TypeCatalogEntity::class, $copy);
        $manager = $this->manager();

        $manager->persist($copy);
        $manager->flush();

        self::assertSame([], $this->database->updated);
        self::assertSame(EntityState::MANAGED, $manager->getUnitOfWork()->entityState($copy));
    }

    public function testStaleVersionFailsAndCurrentVersionIsWrittenAndIncremented(): void
    {
        $this->database->rows = ['wp_sympress_mailer_logs' => [['id' => 'log-1', 'status' => 'queued', 'version' => '3']]];
        $stale = $this->manager();
        $stale->persist(new VersionedEmailLog('log-1', 'sent', 2));

        try {
            $stale->flush();
            self::fail('Expected an optimistic lock failure.');
        } catch (OptimisticLockException) {
        }

        $manager = $this->manager();
        $current = new VersionedEmailLog('log-1', 'sent', 3);
        $manager->persist($current);
        $manager->flush();

        self::assertSame(['status' => 'sent', 'version' => 4], $this->database->updated[1]['data']);
        self::assertSame(['id' => 'log-1', 'version' => 3], $this->database->updated[1]['where']);
        self::assertSame(4, $current->version);
    }

    public function testExplicitChangeTrackingWritesThePersistedState(): void
    {
        $this->database->rows = ['wp_sympress_mailer_logs' => [['id' => 'log-1', 'status' => 'queued']]];
        $manager = $this->manager();

        $manager->persist(new ExplicitEmailLog('log-1', 'sent'));
        $manager->flush();

        self::assertSame([['table' => 'wp_sympress_mailer_logs', 'data' => ['status' => 'sent'], 'where' => ['id' => 'log-1']]], $this->database->updated);
    }

    private function manager(): EntityManager
    {
        $metadata = new MetadataFactory();
        $registry = new EntityClassRegistry($metadata, classes: [
            Article::class, Author::class, TypeCatalogEntity::class, VersionedEmailLog::class, ExplicitEmailLog::class,
        ]);

        return new EntityManager($metadata, $registry, new EntityHydrator(), $this->database);
    }
}

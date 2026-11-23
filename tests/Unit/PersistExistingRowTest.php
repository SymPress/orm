<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SymPress\Orm\Cache\ArrayCache;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\EntityState;
use SymPress\Orm\Event\Events;
use SymPress\Orm\Event\LifecycleEventArgs;
use SymPress\Orm\Exception\OptimisticLockException;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\Address;
use SymPress\Orm\Tests\Fixtures\Article;
use SymPress\Orm\Tests\Fixtures\Author;
use SymPress\Orm\Tests\Fixtures\Car;
use SymPress\Orm\Tests\Fixtures\ExplicitEmailLog;
use SymPress\Orm\Tests\Fixtures\Garage;
use SymPress\Orm\Tests\Fixtures\Memo;
use SymPress\Orm\Tests\Fixtures\Note;
use SymPress\Orm\Tests\Fixtures\Profile;
use SymPress\Orm\Tests\Fixtures\SportsCar;
use SymPress\Orm\Tests\Fixtures\TypeCatalogEntity;
use SymPress\Orm\Tests\Fixtures\Vehicle;
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

    public function testRowOfAnotherClassOfTheHierarchyIsRejected(): void
    {
        $this->database->rows = ['wp_sympress_vehicles' => [['id' => 'c1', 'kind' => 'car', 'garage_id' => null]]];
        $manager = $this->manager();

        try {
            $manager->persist(new SportsCar('c1'));
            self::fail('Expected the row of another class to be rejected.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString(Car::class, $exception->getMessage());
        }

        $manager->flush();
        self::assertSame([], $this->database->inserted);
        self::assertSame([], $this->database->updated);
    }

    public function testRepositorySaveWritesThePersistedState(): void
    {
        $manager = $this->manager();

        $manager->getRepository(Article::class)->save(new Article('a1', 'Saved', $manager->getReference(Author::class, 'ada')), flush: true);

        self::assertSame([['table' => 'wp_sympress_articles', 'data' => ['title' => 'Saved'], 'where' => ['id' => 'a1']]], $this->database->updated);
    }

    public function testSecondLevelCacheDoesNotServeTheOldRowAfterTheWrite(): void
    {
        $manager = $this->manager(new ArrayCache());
        self::assertSame('Old', $manager->find(Article::class, 'a1')?->title);
        $manager->clear();

        $manager->persist(new Article('a1', 'New', $manager->getReference(Author::class, 'ada')));
        $manager->flush();
        $manager->clear();

        self::assertSame('New', $manager->find(Article::class, 'a1')?->title);
        self::assertSame([], $this->database->inserted);
    }

    public function testUninitializedPropertiesKeepTheRowsValues(): void
    {
        $this->database->rows['wp_sympress_memos'] = [self::memoRow('keep me')];
        $manager = $this->manager();
        $memo = new Memo('m1', 'New');

        $manager->persist($memo);
        $manager->flush();

        // An uninitialized property is no value: it neither overwrites the row with NULL nor stays unreadable.
        self::assertSame([['table' => 'wp_sympress_memos', 'data' => ['title' => 'New'], 'where' => ['id' => 'm1']]], $this->database->updated);
        self::assertSame('2020-01-01 00:00:00', $memo->createdAt->format('Y-m-d H:i:s'));
        self::assertSame('keep me', $memo->note);
        self::assertSame('ada', $memo->author?->id);
        self::assertSame('Graz', $memo->address->city);
    }

    public function testExplicitlyAssignedNullIsWritten(): void
    {
        $this->database->rows['wp_sympress_memos'] = [self::memoRow('drop me')];
        $manager = $this->manager();
        $memo = new Memo('m1', 'Old');
        $memo->note = null;
        $memo->author = null;

        $manager->persist($memo);
        $manager->flush();

        self::assertSame([['table' => 'wp_sympress_memos', 'data' => ['note' => null, 'author_id' => null], 'where' => ['id' => 'm1']]], $this->database->updated);
    }

    public function testIdentityManagedByAnotherInstanceIsRejected(): void
    {
        $manager = $this->manager();
        $managed = $manager->find(Author::class, 'ada');
        self::assertInstanceOf(Author::class, $managed);
        $managed->name = 'From the managed instance';

        try {
            $manager->persist(new Author('ada', 'From a second instance'));
            self::fail('Expected the second instance to be rejected.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString(Author::class, $exception->getMessage());
            self::assertStringContainsString('"ada"', $exception->getMessage());
        }

        // Persisting the managed instance itself stays allowed.
        $manager->persist($managed);
        $manager->flush();
        self::assertSame([['table' => 'wp_sympress_authors', 'data' => ['name' => 'From the managed instance'], 'where' => ['id' => 'ada']]], $this->database->updated);
    }

    /** @return iterable<string, array{bool}> */
    public static function profileRowExists(): iterable
    {
        yield 'new object' => [false];
        yield 'existing row' => [true];
    }

    #[DataProvider('profileRowExists')]
    public function testPersistThatThrowsInACascadeLeavesNothingScheduled(bool $rowExists): void
    {
        $this->database->rows['wp_sympress_notes'] = [['id' => 'n1', 'profile_id' => 'p0']];
        $this->database->rows['wp_sympress_profiles'] = $rowExists ? [['id' => 'p1', 'billing_id' => null, 'shipping_id' => null]] : [];
        $manager = $this->manager();
        self::assertInstanceOf(Note::class, $manager->find(Note::class, 'n1'));
        $profile = new Profile('p1');
        // The new note n2 is cascaded first, then the second instance of the managed note n1 fails.
        $profile->notes->add(new Note('n2', $profile));
        $profile->notes->add(new Note('n1', $profile));

        try {
            $manager->persist($profile);
            self::fail('Expected the second instance of n1 to be rejected.');
        } catch (\LogicException) {
        }

        self::assertSame([], $manager->getUnitOfWork()->scheduledInsertions());
        self::assertFalse($manager->contains($profile));
        self::assertNull($manager->getUnitOfWork()->tryGetById($manager->getClassMetadata(Note::class), 'n2'));
        $manager->flush();
        self::assertSame([], $this->database->inserted);
        self::assertSame([], $this->database->updated);
    }

    /** @return array<string, mixed> */
    private static function memoRow(string $note): array
    {
        return [
            'id' => 'm1',
            'title' => 'Old',
            'created_at' => '2020-01-01 00:00:00',
            'note' => $note,
            'author_id' => 'ada',
            'address_city' => 'Graz',
        ];
    }

    private function manager(?ArrayCache $cache = null): EntityManager
    {
        $metadata = new MetadataFactory();
        $registry = new EntityClassRegistry($metadata, classes: [
            Article::class, Author::class, TypeCatalogEntity::class, VersionedEmailLog::class, ExplicitEmailLog::class,
            Vehicle::class, Car::class, SportsCar::class, Garage::class, Memo::class,
            Address::class, Note::class, Profile::class,
        ]);

        return new EntityManager($metadata, $registry, new EntityHydrator(), $this->database, secondLevelCache: $cache);
    }
}

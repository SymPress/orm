<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\Cache\ArrayCache;
use SymPress\Orm\Collection\PersistentCollection;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\EntityState;
use SymPress\Orm\Exception\OptimisticLockException;
use SymPress\Orm\LockMode;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\CachedArticle;
use SymPress\Orm\Tests\Fixtures\CachedAuthor;
use SymPress\Orm\Tests\Fixtures\Course;
use SymPress\Orm\Tests\Fixtures\ExplicitEmailLog;
use SymPress\Orm\Tests\Fixtures\MutableEmailLog;
use SymPress\Orm\Tests\Fixtures\Student;
use SymPress\Orm\Tests\Fixtures\VersionedEmailLog;

final class EntityManagerTest extends TestCase
{
    private \wpdb $database;
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [MutableEmailLog::class]);
        $this->database = new \wpdb();
        $this->entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), $this->database);
    }

    public function testPersistSchedulesInsertionUntilFlush(): void
    {
        $log = new MutableEmailLog('log-1', new \DateTimeImmutable('2026-06-13 10:00:00'), 'queued');

        self::assertSame(EntityState::NEW, $this->entityManager->getEntityState($log));

        $this->entityManager->persist($log);

        self::assertSame([], $this->database->inserted);
        self::assertTrue($this->entityManager->contains($log));
        self::assertSame(EntityState::MANAGED, $this->entityManager->getEntityState($log));

        $this->entityManager->flush();

        self::assertSame('wp_sympress_mailer_logs', $this->database->inserted[0]['table']);
        self::assertSame('queued', $this->database->inserted[0]['data']['status']);
        self::assertSame('2026-06-13 10:00:00', $this->database->inserted[0]['data']['created_at']);
    }

    public function testFlushPersistsChangedManagedEntities(): void
    {
        $log = new MutableEmailLog('log-1', new \DateTimeImmutable('2026-06-13 10:00:00'), 'queued');
        $this->entityManager->persist($log);
        $this->entityManager->flush();
        $this->database->updated = [];

        $log->status = 'sent';
        $this->entityManager->flush();

        self::assertSame('wp_sympress_mailer_logs', $this->database->updated[0]['table']);
        self::assertSame(['status' => 'sent'], $this->database->updated[0]['data']);
        self::assertSame(['id' => 'log-1'], $this->database->updated[0]['where']);
    }

    public function testRemoveSchedulesDeletionUntilFlush(): void
    {
        $log = new MutableEmailLog('log-1', new \DateTimeImmutable('2026-06-13 10:00:00'), 'queued');
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        $this->entityManager->remove($log);

        self::assertSame([], $this->database->deleted);

        $this->entityManager->flush();

        self::assertSame('wp_sympress_mailer_logs', $this->database->deleted[0]['table']);
        self::assertSame(['id' => 'log-1'], $this->database->deleted[0]['where']);
        self::assertFalse($this->entityManager->contains($log));
    }

    public function testFlushSynchronizesOwningManyToManyJoinTable(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [Student::class, Course::class]);
        $database = new \wpdb();
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), $database);

        $course = new Course('course-1', 'Math');
        $student = new Student('student-1', 'Grace');
        $student->courses->add($course);

        $entityManager->persist($course);
        $entityManager->persist($student);
        $entityManager->flush();

        self::assertSame([], $database->deleted);
        self::assertSame('wp_sympress_student_courses', $database->inserted[2]['table']);
        self::assertSame(
            ['student_id' => 'student-1', 'course_id' => 'course-1'],
            $database->inserted[2]['data'],
        );

        $database->inserted = [];
        $database->deleted = [];
        $entityManager->flush();

        self::assertSame([], $database->inserted);
        self::assertSame([], $database->deleted);
    }

    public function testDeferredExplicitEntitiesOnlyUpdateAfterPersist(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [ExplicitEmailLog::class]);
        $database = new \wpdb();
        $database->countResult = 1;
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), $database);
        $log = new ExplicitEmailLog('log-1', 'queued');

        $entityManager->persist($log);
        $entityManager->flush();
        $database->updated = [];

        $log->status = 'sent';
        $entityManager->flush();

        self::assertSame([], $database->updated);

        $entityManager->persist($log);
        $entityManager->flush();

        self::assertSame(['status' => 'sent'], $database->updated[0]['data']);
    }

    public function testVersionedUpdateThrowsOptimisticLockExceptionWhenNoRowWasUpdated(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [VersionedEmailLog::class]);
        $database = new \wpdb();
        $database->countResult = 1;
        $database->updateResult = 0;
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), $database);
        $log = new VersionedEmailLog('log-1', 'queued', 1);

        $entityManager->persist($log);
        $log->status = 'sent';

        try {
            $entityManager->flush();
            self::fail('Expected an optimistic lock failure.');
        } catch (OptimisticLockException) {
            self::assertFalse($entityManager->isOpen());
            self::assertSame(1, $log->version);
            self::assertSame(['START TRANSACTION', 'ROLLBACK'], $database->queries);
        }
    }

    public function testVersionedUpdateIncrementsVersionAfterSuccessfulFlush(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [VersionedEmailLog::class]);
        $database = new \wpdb();
        $database->countResult = 1;
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), $database);
        $log = new VersionedEmailLog('log-1', 'queued', 1);

        $entityManager->persist($log);
        $log->status = 'sent';
        $entityManager->flush();

        self::assertSame(2, $log->version);
        self::assertSame(['status' => 'sent', 'version' => 2], $database->updated[0]['data']);
        self::assertSame(['id' => 'log-1', 'version' => 1], $database->updated[0]['where']);
        self::assertSame(['START TRANSACTION', 'COMMIT'], $database->queries);
    }

    public function testFlushRollsBackAndClosesEntityManagerWhenBatchInsertFails(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [MutableEmailLog::class]);
        $database = new class extends \wpdb {
            private int $insertCalls = 0;

            /** @param array<string, mixed> $data */
            public function insert(string $table, array $data): bool|int
            {
                $this->insertCalls++;

                if ($this->insertCalls === 2) {
                    return false;
                }

                return parent::insert($table, $data);
            }
        };
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), $database);

        $entityManager->persist(new MutableEmailLog('log-1', new \DateTimeImmutable('2026-06-13 10:00:00'), 'queued'));
        $entityManager->persist(new MutableEmailLog('log-2', new \DateTimeImmutable('2026-06-13 10:01:00'), 'queued'));

        try {
            $entityManager->flush();
            self::fail('Expected the second insert to fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Failed to insert', $exception->getMessage());
            self::assertFalse($entityManager->isOpen());
            self::assertCount(1, $database->inserted);
            self::assertSame(['START TRANSACTION', 'ROLLBACK'], $database->queries);
        }
    }

    public function testFlushPersistsEntityChangesAfterLazyCollectionLoad(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [CachedAuthor::class, CachedArticle::class]);
        $database = new \wpdb();
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), $database);
        $database->resultRows = [
            ['id' => 'author-1', 'name' => 'Ada'],
        ];

        $author = $entityManager->find(CachedAuthor::class, 'author-1');

        self::assertInstanceOf(CachedAuthor::class, $author);
        self::assertInstanceOf(PersistentCollection::class, $author->articles);
        self::assertFalse($author->articles->isInitialized());

        $author->name = 'Grace';
        $database->resultRows = [
            ['id' => 'article-1', 'title' => 'First', 'author_id' => 'author-1'],
        ];

        $articles = $author->articles->toArray();
        $entityManager->flush();

        self::assertCount(1, $articles);
        self::assertSame('article-1', $articles[0]->id);
        self::assertSame(['name' => 'Grace'], $database->updated[0]['data']);
        self::assertSame(['id' => 'author-1'], $database->updated[0]['where']);
        self::assertSame([], $database->deleted);
    }

    public function testPessimisticLockUsesBackedEnum(): void
    {
        $log = new MutableEmailLog('log-1', new \DateTimeImmutable('2026-06-13 10:00:00'), 'queued');

        $this->entityManager->getConnection()->beginTransaction();
        $this->entityManager->lock($log, LockMode::PESSIMISTIC_WRITE);

        self::assertSame(
            [
                'START TRANSACTION',
                "SELECT 1 FROM `wp_sympress_mailer_logs` WHERE `id` = 'log-1' FOR UPDATE",
            ],
            $this->database->queries,
        );
    }

    public function testSecondLevelCacheUsesRegionsAndDmlEvictsIt(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [CachedAuthor::class]);
        $database = new \wpdb();
        $database->resultRows = [
            ['id' => 'author-1', 'name' => 'Ada'],
        ];
        $cache = new ArrayCache();
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), $database, secondLevelCache: $cache);

        $first = $entityManager->find(CachedAuthor::class, 'author-1');
        $database->resultRows = [
            ['id' => 'author-1', 'name' => 'Changed in database'],
        ];
        $entityManager->clear();
        $second = $entityManager->find(CachedAuthor::class, 'author-1');

        self::assertSame('Ada', $second?->name);

        $entityManager
            ->createQuery('UPDATE CachedAuthor a SET a.name = :name WHERE a.id = :id', [
                'name' => 'Grace',
                'id' => 'author-1',
            ])
            ->execute();
        $entityManager->clear();
        $third = $entityManager->find(CachedAuthor::class, 'author-1');

        self::assertSame('Changed in database', $third?->name);
        self::assertNotSame($first, $third);
    }

    public function testAssociationCacheIsInvalidatedAfterTargetWrite(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [CachedAuthor::class, CachedArticle::class]);
        $database = new \wpdb();
        $cache = new ArrayCache();
        $entityManager = new EntityManager($metadataFactory, $registry, new EntityHydrator(), $database, secondLevelCache: $cache);
        $author = new CachedAuthor('author-1', 'Ada');
        $metadata = $entityManager->getClassMetadata(CachedAuthor::class);
        $association = $metadata->associationForProperty('articles');

        self::assertNotNull($association);

        $database->resultRows = [
            ['id' => 'article-1', 'title' => 'First', 'author_id' => 'author-1'],
        ];
        $first = $entityManager->loadAssociationCollection($author, $association);

        $database->resultRows = [
            ['id' => 'article-2', 'title' => 'Second', 'author_id' => 'author-1'],
        ];
        $second = $entityManager->loadAssociationCollection($author, $association);

        self::assertSame('article-1', $second[0]->id);

        $entityManager->persist(new CachedArticle('article-3', 'Third', $author));
        $entityManager->flush();
        $database->resultRows = [
            ['id' => 'article-2', 'title' => 'Second', 'author_id' => 'author-1'],
        ];
        $third = $entityManager->loadAssociationCollection($author, $association);

        self::assertSame('article-2', $third[0]->id);
        self::assertSame('article-1', $first[0]->id);
    }
}

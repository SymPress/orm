<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\Cache\ArrayCache;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\Event\Events;
use SymPress\Orm\Event\LifecycleEventArgs;
use SymPress\Orm\Exception\EntityNotFoundException;
use SymPress\Orm\Exception\OptimisticLockException;
use SymPress\Orm\LockMode;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\Animal;
use SymPress\Orm\Tests\Fixtures\Article;
use SymPress\Orm\Tests\Fixtures\Author;
use SymPress\Orm\Tests\Fixtures\CachedAuthor;
use SymPress\Orm\Tests\Fixtures\Dog;
use SymPress\Orm\Tests\Fixtures\EmailLog;
use SymPress\Orm\Tests\Fixtures\Playlist;
use SymPress\Orm\Tests\Fixtures\Track;
use SymPress\Orm\Tests\Fixtures\VersionedEmailLog;

final class LazyReferenceTest extends TestCase
{
    private \wpdb $database;
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $metadata = new MetadataFactory();
        $registry = new EntityClassRegistry($metadata, classes: [Article::class, Author::class, EmailLog::class, Animal::class, Dog::class, Playlist::class, Track::class, VersionedEmailLog::class]);
        // Records every SELECT and answers it with the rows of the table it reads, narrowed to the requested id.
        $this->database = new class extends \wpdb {
            /** @var list<string> */
            public array $selects = [];

            /** @var array<string, list<array<string, mixed>>> */
            public array $rows = [];

            public function get_results(string $query, string|int $output = ARRAY_A): array
            {
                $this->selects[] = $query;
                $id = preg_match("/`id` = '([^']*)'/", $query, $match) === 1 ? $match[1] : null;

                foreach ($this->rows as $table => $rows) {
                    if (str_contains($query, '`' . $table . '`')) {
                        return array_values(array_filter($rows, static fn (array $row): bool => $id === null || $row['id'] === $id));
                    }
                }

                return [];
            }
        };
        $this->database->rows = [
            'wp_sympress_articles' => [['id' => 'article-1', 'title' => 'First', 'author_id' => 'author-1']],
            'wp_sympress_authors' => [['id' => 'author-1', 'name' => 'Ada']],
        ];
        $this->entityManager = new EntityManager($metadata, $registry, new EntityHydrator(), $this->database);
    }

    public function testToOneAssociationLoadsOnceOnFirstAccess(): void
    {
        $article = $this->entityManager->find(Article::class, 'article-1');
        self::assertInstanceOf(Article::class, $article);
        $author = $article->author;

        self::assertTrue(self::isLazy($author));
        self::assertSame('author-1', $author->id, 'Reading the identifier must not load.');
        self::assertCount(1, $this->database->selects);

        self::assertSame('Ada', $author->name);
        self::assertSame('Ada', $author->name);
        self::assertCount(2, $this->database->selects);
        self::assertFalse(self::isLazy($author));
        self::assertSame(1, $author->postLoadCalls);
        self::assertFalse($this->entityManager->getUnitOfWork()->isUninitializedReference($author));
        self::assertSame(['id' => 'author-1', 'name' => 'Ada'], $this->entityManager->getUnitOfWork()->originalData($author));
    }

    public function testReferenceLoadsFromSecondLevelCache(): void
    {
        $metadata = new MetadataFactory();
        $registry = new EntityClassRegistry($metadata, classes: [CachedAuthor::class]);
        $entityManager = new EntityManager($metadata, $registry, new EntityHydrator(), $this->database, secondLevelCache: new ArrayCache());
        $this->database->rows = ['wp_sympress_cached_authors' => [['id' => 'author-1', 'name' => 'Ada']]];
        $entityManager->find(CachedAuthor::class, 'author-1');
        $entityManager->clear();

        $author = $entityManager->getReference(CachedAuthor::class, 'author-1');

        self::assertSame('Ada', $author->name);
        self::assertCount(1, $this->database->selects);
    }

    public function testFlushLeavesUntouchedReferencesUnloaded(): void
    {
        $article = $this->entityManager->find(Article::class, 'article-1');
        self::assertInstanceOf(Article::class, $article);
        $article->title = 'Changed';
        $this->entityManager->persist(new Article('article-2', 'Second', $article->author));

        self::assertTrue($this->entityManager->contains($article->author));
        $this->entityManager->flush();

        // find() and the existence check of article-2 in persist().
        self::assertCount(2, $this->database->selects);
        self::assertTrue(self::isLazy($article->author));
        self::assertSame(['id' => 'article-2', 'title' => 'Second', 'author_id' => 'author-1'], $this->database->inserted[0]['data']);
        self::assertCount(1, $this->database->updated);
        self::assertSame(['title' => 'Changed'], $this->database->updated[0]['data']);
    }

    public function testCollectionsOfLoadedReferenceBelongToIt(): void
    {
        $this->database->rows = [
            'wp_sympress_playlists' => [['id' => 'playlist-1']],
            'wp_sympress_tracks' => [['id' => 'track-1', 'playlist_id' => 'playlist-1'], ['id' => 'track-2', 'playlist_id' => 'playlist-1']],
        ];
        $playlist = $this->entityManager->getReference(Playlist::class, 'playlist-1');
        self::assertInstanceOf(Playlist::class, $playlist);
        $tracks = $playlist->tracks->toArray();

        $playlist->tracks->removeElement($tracks[0]);
        $this->entityManager->flush();

        self::assertSame([['table' => 'wp_sympress_tracks', 'where' => ['id' => 'track-1']]], $this->database->deleted);
    }

    public function testFlushSkipsFlushCallbacksOfUnloadedReferences(): void
    {
        $article = $this->entityManager->find(Article::class, 'article-1');
        self::assertInstanceOf(Article::class, $article);
        $author = new Author('author-2', 'Grace');
        $this->entityManager->persist($author);

        $this->entityManager->flush();
        // WordPress tables have no foreign keys, so a reference may point at a deleted row.
        $this->database->rows['wp_sympress_authors'] = [];
        $this->entityManager->flush();

        self::assertTrue(self::isLazy($article->author));
        // find() and the existence check of author-2 in persist().
        self::assertCount(2, $this->database->selects);
        self::assertTrue($this->entityManager->isOpen());
        self::assertSame(4, $author->flushCalls, 'Loaded entities still get preFlush and postFlush.');
    }

    public function testOptimisticLockLoadsReferenceToCompareItsVersion(): void
    {
        $this->database->rows = ['wp_sympress_mailer_logs' => [['id' => 'log-1', 'status' => 'queued', 'version' => '3']]];
        $log = $this->entityManager->getReference(VersionedEmailLog::class, 'log-1');

        $this->entityManager->lock($log, LockMode::OPTIMISTIC, 3);

        self::assertFalse(self::isLazy($log));
        self::assertCount(1, $this->database->selects);

        $stale = $this->entityManager->getReference(VersionedEmailLog::class, 'log-2');
        $this->database->rows = ['wp_sympress_mailer_logs' => [['id' => 'log-2', 'status' => 'queued', 'version' => '3']]];
        $this->expectException(OptimisticLockException::class);
        $this->expectExceptionMessage('expected version "2", actual version "3"');
        $this->entityManager->lock($stale, LockMode::OPTIMISTIC, 2);
    }

    public function testRemovingReferenceRemovesOrphans(): void
    {
        $this->database->rows = [
            'wp_sympress_playlists' => [['id' => 'playlist-1']],
            'wp_sympress_tracks' => [['id' => 'track-1', 'playlist_id' => 'playlist-1'], ['id' => 'track-2', 'playlist_id' => 'playlist-1']],
        ];
        $playlist = $this->entityManager->getReference(Playlist::class, 'playlist-1');

        $this->entityManager->remove($playlist);
        $this->entityManager->flush();

        self::assertSame(['wp_sympress_tracks', 'wp_sympress_tracks', 'wp_sympress_playlists'], array_column($this->database->deleted, 'table'));
    }

    public function testRemovingReferenceCascadesAndLetsRemoveListenersReadIt(): void
    {
        $names = [];
        $this->entityManager->getEventManager()->addEventListener(Events::POST_REMOVE, static function (LifecycleEventArgs $args) use (&$names): void {
            $entity = $args->getEntity();
            $names[] = $entity instanceof Author ? $entity->name : $entity::class;
        });
        $author = $this->entityManager->getReference(Author::class, 'author-1');

        $this->entityManager->remove($author);
        $this->entityManager->flush();

        self::assertSame(['wp_sympress_articles', 'wp_sympress_authors'], array_column($this->database->deleted, 'table'));
        self::assertSame([Article::class, 'Ada'], $names);
        self::assertTrue($this->entityManager->isOpen());
    }

    public function testRemovingReferenceToMissingRowThrows(): void
    {
        $author = $this->entityManager->getReference(Author::class, 'missing');

        try {
            $this->entityManager->remove($author);
            self::fail('Expected EntityNotFoundException.');
        } catch (EntityNotFoundException) {
        }

        $this->entityManager->flush();
        self::assertSame([], $this->database->deleted);
    }

    public function testMissingRowThrowsEntityNotFoundException(): void
    {
        $author = $this->entityManager->getReference(Author::class, 'missing');
        $this->database->rows = [];

        try {
            $author->name;
            self::fail('Expected EntityNotFoundException.');
        } catch (EntityNotFoundException $exception) {
            self::assertStringContainsString(Author::class, $exception->getMessage());
            self::assertStringContainsString('missing', $exception->getMessage());
        }

        self::assertTrue(self::isLazy($author));
        self::assertSame('missing', $author->id);
    }

    public function testLaterQueryFillsReferenceWithoutAnotherSelect(): void
    {
        $author = $this->entityManager->getReference(Author::class, 'author-1');
        self::assertTrue(self::isLazy($author));

        self::assertSame([$author], $this->entityManager->getRepository(Author::class)->findAll());
        self::assertFalse(self::isLazy($author));
        self::assertFalse($this->entityManager->getUnitOfWork()->isUninitializedReference($author));
        self::assertSame('Ada', $author->name);
        self::assertSame(1, $author->postLoadCalls);
        self::assertCount(1, $this->database->selects);
    }

    public function testReadonlyConstructorHydratedEntityWorksAsReference(): void
    {
        $this->database->rows = ['wp_sympress_mailer_logs' => [['id' => 'log-1', 'created_at' => '2026-06-13 10:00:00', 'status' => 'queued', 'payload' => '{"a":1}']]];
        $log = $this->entityManager->getReference(EmailLog::class, 'log-1');

        self::assertTrue(self::isLazy($log));
        self::assertSame('queued', $log->status);
        self::assertSame(['a' => 1], $log->payload);
        self::assertSame('2026-06-13 10:00:00', $log->createdAt->format('Y-m-d H:i:s'));
        self::assertCount(1, $this->database->selects);
    }

    public function testInheritanceRootWithSubclassesLoadsEagerly(): void
    {
        $this->database->rows = ['wp_sympress_animals' => [['id' => 'animal-1', 'name' => 'Rex', 'kind' => 'dog', 'bark_volume' => '7']]];

        $animal = $this->entityManager->getReference(Animal::class, 'animal-1');

        self::assertInstanceOf(Dog::class, $animal);
        self::assertFalse(self::isLazy($animal));
        self::assertSame(7, $animal->barkVolume);
        self::assertCount(1, $this->database->selects);

        self::assertTrue(self::isLazy($this->entityManager->getReference(Dog::class, 'dog-2')), 'A leaf class stays lazy.');
        self::assertCount(1, $this->database->selects);

        $this->database->rows = [];
        $this->expectException(EntityNotFoundException::class);
        $this->entityManager->getReference(Animal::class, 'missing');
    }

    public function testDetachedReferenceFailsWithoutQuery(): void
    {
        $cleared = $this->entityManager->getReference(Author::class, 'author-1');
        $this->entityManager->clear();
        $detached = $this->entityManager->getReference(Author::class, 'author-1');
        $this->entityManager->detach($detached);
        $closed = $this->entityManager->getReference(Author::class, 'author-1');
        $this->entityManager->close();

        foreach ([$cleared, $detached, $closed] as $author) {
            try {
                $author->name;
                self::fail('Expected a detached reference to fail.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString('detached', $exception->getMessage());
            }

            self::assertSame('author-1', $author->id);
        }

        self::assertSame([], $this->database->selects);
    }

    private static function isLazy(object $entity): bool
    {
        return (new \ReflectionClass($entity))->isUninitializedLazyObject($entity);
    }
}

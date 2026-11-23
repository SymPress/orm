<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\Exception\EntityNotFoundException;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Tests\Fixtures\Animal;
use SymPress\Orm\Tests\Fixtures\Article;
use SymPress\Orm\Tests\Fixtures\Author;
use SymPress\Orm\Tests\Fixtures\Dog;
use SymPress\Orm\Tests\Fixtures\EmailLog;
use SymPress\Orm\Tests\Fixtures\Playlist;
use SymPress\Orm\Tests\Fixtures\Track;

final class LazyReferenceTest extends TestCase
{
    private \wpdb $database;
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $metadata = new MetadataFactory();
        $registry = new EntityClassRegistry($metadata, classes: [Article::class, Author::class, EmailLog::class, Animal::class, Dog::class, Playlist::class, Track::class]);
        // Records every SELECT and answers it with the rows of the table it reads.
        $this->database = new class extends \wpdb {
            /** @var list<string> */
            public array $selects = [];

            /** @var array<string, list<array<string, mixed>>> */
            public array $rows = [];

            public function get_results(string $query, string|int $output = ARRAY_A): array
            {
                $this->selects[] = $query;

                foreach ($this->rows as $table => $rows) {
                    if (str_contains($query, '`' . $table . '`')) {
                        return $rows;
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

    public function testInheritanceRootWithSubclassesLoadsEagerly(): void
    {
        $this->database->rows = ['wp_sympress_animals' => [['id' => 'animal-1', 'name' => 'Rex', 'kind' => 'dog', 'bark_volume' => '7']]];

        $animal = $this->entityManager->getReference(Animal::class, 'animal-1');

        self::assertInstanceOf(Dog::class, $animal);
        self::assertFalse(self::isLazy($animal));
        self::assertSame(7, $animal->barkVolume);
        self::assertCount(1, $this->database->selects);

        $this->database->rows = [];
        $this->expectException(EntityNotFoundException::class);
        $this->entityManager->getReference(Animal::class, 'missing');
    }

    private static function isLazy(object $entity): bool
    {
        return (new \ReflectionClass($entity))->isUninitializedLazyObject($entity);
    }
}

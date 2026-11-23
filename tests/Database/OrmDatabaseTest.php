<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use SymPress\Orm\Compiler\EntityCatalogPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use SymPress\Orm\Bridge\Migration\SchemaMigrationFactory;
use SymPress\Orm\Dbal\WpdbConnection;
use SymPress\Orm\EntityHydrator;
use SymPress\Orm\EntityManager;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Schema\SchemaSqlGenerator;
use SymPress\Orm\Schema\SchemaTool;
use SymPress\Orm\Tests\Fixtures\Article;
use SymPress\Orm\Tests\Fixtures\Author;
use SymPress\Orm\Tests\Fixtures\Car;
use SymPress\Orm\Tests\Fixtures\EmailLog;
use SymPress\Orm\Tests\Fixtures\Garage;
use SymPress\Orm\Tests\Fixtures\MailStatus;
use SymPress\Orm\Tests\Fixtures\NumericRole;
use SymPress\Orm\Tests\Fixtures\NarrowEmailLog;
use SymPress\Orm\Tests\Fixtures\Playlist;
use SymPress\Orm\Tests\Fixtures\SportsCar;
use SymPress\Orm\Tests\Fixtures\StringDefaults;
use SymPress\Orm\Tests\Fixtures\StringDefaultsWithMemo;
use SymPress\Orm\Tests\Fixtures\Track;
use SymPress\Orm\Tests\Fixtures\Vehicle;
use SymPress\WordPress\Migration\Application\MigrationLifecycle;
use SymPress\WordPress\Migration\Domain\MigrationCollection;
use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Infrastructure\MigrationTracker;
use SymPress\WordPress\Migration\Infrastructure\WordPressSqlExecutor;
use SymPress\WordPress\Migration\Value\PluginSlug;

final class OrmDatabaseTest extends TestCase
{
    private \wpdb $database;

    protected function setUp(): void
    {
        $this->database = $GLOBALS['wpdb'];
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        foreach (['sympress_articles', 'sympress_authors', 'sympress_mailer_logs', 'sympress_numeric_roles', 'orm_review_state', 'orm_review_state_history', 'orm_string_defaults', 'sympress_vehicles', 'sympress_garages', 'sympress_garage_rentals', 'sympress_playlists', 'sympress_tracks'] as $name) {
            $this->database->query($this->database->prepare('DROP TABLE IF EXISTS %i', $this->database->prefix . $name));
        }
    }

    public function testDecodedStringDefaultsPermitAdditiveMigrationsAndDetectActualChanges(): void
    {
        $metadata = new MetadataFactory();
        $tool = new SchemaTool($metadata, new EntityClassRegistry($metadata, classes: [StringDefaults::class]), new SchemaSqlGenerator(), $this->database);
        self::assertTrue(new WordPressSqlExecutor($this->database)->execute($tool->getCreateSchemaSql()));
        self::assertSame([], $tool->getUpdateSchemaSql('default'));
        self::assertFalse($tool->requiresDestructiveUpdates('default'));
        $additive = new SchemaTool($metadata, new EntityClassRegistry($metadata, classes: [StringDefaultsWithMemo::class]), new SchemaSqlGenerator(), $this->database);
        $sql = new SchemaMigrationFactory($additive)->create('default')->up();
        self::assertCount(1, $sql);
        self::assertStringContainsString('ADD COLUMN `memo`', $sql[0]);
        self::assertTrue(new WordPressSqlExecutor($this->database)->execute($sql));
        $additive->refreshSchemaState();
        self::assertSame([], $additive->getUpdateSchemaSql('default'));

        $this->database->query("ALTER TABLE wp_orm_string_defaults MODIFY status VARCHAR(40) NOT NULL DEFAULT 'pending'");
        $additive->refreshSchemaState();
        self::assertTrue($additive->requiresDestructiveUpdates('default'));
        $this->expectException(\SymPress\WordPress\Migration\Exception\MigrationOperationException::class);
        new SchemaMigrationFactory($additive)->create('default')->up();
    }

    public function testRemovingReferenceRemovesOrphansWithRealWpdb(): void
    {
        $metadata = new MetadataFactory();
        $entities = new EntityClassRegistry($metadata, classes: [Playlist::class, Track::class]);
        $tool = new SchemaTool($metadata, $entities, new SchemaSqlGenerator(), $this->database);
        self::assertTrue(new WordPressSqlExecutor($this->database)->execute($tool->getCreateSchemaSql()));
        $manager = new EntityManager($metadata, $entities, new EntityHydrator(), $this->database);
        $playlist = new Playlist('playlist-1');
        $manager->persist($playlist);
        $manager->persist(new Track('track-1', $playlist));
        $manager->persist(new Track('track-2', $playlist));
        $manager->flush();
        $manager->clear();

        $manager->remove($manager->getReference(Playlist::class, 'playlist-1'));
        $manager->flush();

        self::assertSame('0', $this->database->get_var('SELECT COUNT(*) FROM wp_sympress_tracks'));
        self::assertSame('0', $this->database->get_var('SELECT COUNT(*) FROM wp_sympress_playlists'));
    }

    public function testToOneReferenceLoadsOnFirstAccessWithRealWpdb(): void
    {
        $metadata = new MetadataFactory();
        $entities = new EntityClassRegistry($metadata, classes: [Article::class, Author::class]);
        $tool = new SchemaTool($metadata, $entities, new SchemaSqlGenerator(), $this->database);
        self::assertTrue(new WordPressSqlExecutor($this->database)->execute($tool->getCreateSchemaSql()));
        $manager = new EntityManager($metadata, $entities, new EntityHydrator(), $this->database);
        $author = new Author('author-1', 'Ada');
        $manager->persist($author);
        $manager->persist(new Article('article-1', 'First', $author));
        $manager->flush();
        $manager->clear();

        $article = $manager->find(Article::class, 'article-1');

        self::assertInstanceOf(Article::class, $article);
        self::assertTrue((new \ReflectionClass($article->author))->isUninitializedLazyObject($article->author));
        self::assertSame('Ada', $article->author->name);
        self::assertSame(1, $article->author->postLoadCalls);
    }

    public function testCompiledCatalogPersistsWithRealWpdbWithoutMappingReflection(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles_metadata', []);
        $container->setParameter('orm.entity_paths', []);
        $container->setParameter('orm.entity_classes', [EmailLog::class]);
        $container->setDefinition(MetadataFactory::class, (new Definition(MetadataFactory::class))->setPublic(true));
        $container->setDefinition(EntityClassRegistry::class, (new Definition(EntityClassRegistry::class, [new Reference(MetadataFactory::class)]))->setPublic(true));
        $container->addCompilerPass(new EntityCatalogPass());
        $container->compile(true);
        $metadata = $container->get(MetadataFactory::class);
        $entities = $container->get(EntityClassRegistry::class);
        self::assertInstanceOf(MetadataFactory::class, $metadata);
        self::assertInstanceOf(EntityClassRegistry::class, $entities);
        $connection = new WpdbConnection($this->database);
        $tool = new SchemaTool($metadata, $entities, new SchemaSqlGenerator(), $connection);
        foreach ($tool->getCreateSchemaSql() as $sql) {
            $connection->executeStatement($sql);
        }
        $manager = new EntityManager($metadata, $entities, new EntityHydrator(), $connection);
        $manager->persist(new EmailLog('compiled', new \DateTimeImmutable(), 'queued'));
        self::assertSame('0', $this->database->get_var('SELECT COUNT(*) FROM wp_sympress_mailer_logs'));
        $manager->flush();
        self::assertSame('1', $this->database->get_var('SELECT COUNT(*) FROM wp_sympress_mailer_logs'));
        $manager->clear();
        self::assertCount(1, $manager->getRepository(EmailLog::class)->findBy(['status' => 'queued']));
        self::assertCount(1, $manager->getRepository(EmailLog::class)->findBy(['status' => MailStatus::Queued]));
        $future = $manager->createQueryBuilder()->select('e')->from(EmailLog::class, 'e')->where('e.createdAt > :now');
        self::assertSame([], $future->setParameter('now', new \DateTimeImmutable('+1 day'))->getQuery()->getResult());
        $like = $manager->createQueryBuilder()->select('e')->from(EmailLog::class, 'e')
            ->where("e.status LIKE 'que%' AND e.id = :id")
            ->setParameter('id', 'compiled');
        $rows = $like->getQuery()->getResult();
        self::assertCount(1, $rows);
        self::assertInstanceOf(EmailLog::class, $rows[0]);
        self::assertSame('compiled', $rows[0]->id);
        self::assertSame([], $like->setMaxResults(0)->getQuery()->getResult());
        self::assertSame([], $metadata->mappingResources());
    }

    public function testSchemaBridgeKeepsAppliedIdentityAndFlushAndRollbackProtectRows(): void
    {
        $metadata = new MetadataFactory();
        $entities = new EntityClassRegistry($metadata, classes: [EmailLog::class]);
        $tool = new SchemaTool($metadata, $entities, new SchemaSqlGenerator(), $this->database);
        $factory = new SchemaMigrationFactory($tool);
        $migration = $factory->create('default');
        $tracker = new MigrationTracker($this->database, $this->database->prefix . 'orm_review_state');
        $manager = new MigrationManager(PluginSlug::fromString('default'), new MigrationLifecycle($tracker, new WordPressSqlExecutor($this->database)), MigrationCollection::fromIterable([$migration]));
        self::assertTrue($manager->runMigrations());
        $tool->refreshSchemaState();
        self::assertSame([], $tool->getUpdateSchemaSql('default'));
        $queriesAfterInspection = $this->database->num_queries;
        self::assertSame([], $tool->getUpdateSchemaSql('default'));
        self::assertSame($queriesAfterInspection, $this->database->num_queries);
        self::assertSame($migration->getVersion(), $factory->create('default')->getVersion());
        self::assertFalse($manager->hasPendingMigrations());
        self::assertTrue($manager->runMigrations());
        self::assertCount(1, $manager->getMigrationHistory());

        $entityManager = new EntityManager($metadata, $entities, new EntityHydrator(), $this->database);
        $entityManager->persist(new EmailLog('kept', new \DateTimeImmutable(), 'queued'));
        self::assertSame('0', $this->database->get_var('SELECT COUNT(*) FROM wp_sympress_mailer_logs'));
        $entityManager->flush();
        self::assertSame('1', $this->database->get_var('SELECT COUNT(*) FROM wp_sympress_mailer_logs'));
        self::assertCount(1, $entityManager->getRepository(EmailLog::class)->findBy(['status' => ['queued', 'sent']]));
        self::assertSame([], $entityManager->getRepository(EmailLog::class)->findBy(['payload' => null]));
        try {
            $manager->rollbackMigrations();
            self::fail('An irreversible migration must not roll back.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('irreversible', $exception->getMessage());
        }
        self::assertSame('1', $this->database->get_var('SELECT COUNT(*) FROM wp_sympress_mailer_logs'));
        $entities->register(NumericRole::class);
        $tool->refreshSchemaState();
        $changed = $factory->create('default');
        self::assertNotSame($migration->getVersion(), $changed->getVersion());
        $manager->replaceMigration($changed);
        self::assertTrue($manager->hasPendingMigrations());
        self::assertTrue($manager->runMigrations());
        self::assertFalse($manager->hasPendingMigrations());
        $narrowEntities = new EntityClassRegistry($metadata, classes: [NarrowEmailLog::class, NumericRole::class]);
        $narrowTool = new SchemaTool($metadata, $narrowEntities, new SchemaSqlGenerator(), $this->database);
        $narrowMigration = (new SchemaMigrationFactory($narrowTool))->create('default');
        $manager->replaceMigration($narrowMigration);
        self::assertTrue($manager->hasPendingMigrations());
        try {
            $manager->runMigrations();
            self::fail('Column narrowing must require explicit intent.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('explicit', $exception->getMessage());
        }
        self::assertTrue($manager->hasPendingMigrations());
        self::assertSame($changed->getVersion(), $tracker->getVersion('default', 'orm-schema:default'));
        $reviewed = new SchemaTool($metadata, $narrowEntities, new SchemaSqlGenerator(), $this->database, allowDestructiveUpdates: true);
        $manager->replaceMigration((new SchemaMigrationFactory($reviewed))->create('default'));
        self::assertTrue($manager->runMigrations());
        self::assertFalse($manager->hasPendingMigrations());
        $reviewed->refreshSchemaState();
        self::assertSame([], $reviewed->getUpdateSchemaSql('default'));
    }

    public function testLiteralsWithKeywordsAndParameterMarkersMatchTheirRows(): void
    {
        $metadata = new MetadataFactory();
        $entities = new EntityClassRegistry($metadata, classes: [EmailLog::class]);
        $tool = new SchemaTool($metadata, $entities, new SchemaSqlGenerator(), $this->database);
        self::assertTrue((new WordPressSqlExecutor($this->database))->execute($tool->getCreateSchemaSql()));
        $manager = new EntityManager($metadata, $entities, new EntityHydrator(), new WpdbConnection($this->database));
        $manager->persist(new EmailLog('keyword', new \DateTimeImmutable(), 'a ORDER BY b'));
        $manager->persist(new EmailLog('marker', new \DateTimeImmutable(), 'at 10:30 :x'));
        $manager->flush();
        $manager->clear();
        $ids = static fn (array $logs): array => array_map(static fn (EmailLog $log): string => $log->id, $logs);

        $byKeyword = $manager->createQuery("SELECT l FROM EmailLog l WHERE l.status = 'a ORDER BY b' ORDER BY l.id DESC")->getResult();
        self::assertSame(['keyword'], $ids($byKeyword));
        $byMarker = $manager->createQueryBuilder()->select('l')->from(EmailLog::class, 'l')
            ->where("l.status = 'at 10:30 :x' AND l.id <> :other")->setParameter('other', 'keyword');
        self::assertSame(['marker'], $ids($byMarker->getQuery()->getResult()));
        $manager->createQuery("DELETE FROM EmailLog l WHERE l.status = 'at 10:30 :x' AND l.id = :id", ['id' => 'marker'])->execute();
        self::assertSame(['keyword'], $this->database->get_col('SELECT id FROM wp_sympress_mailer_logs'));
    }

    public function testHostileStringAfterPercentDoesNotChangeTheMatchingRows(): void
    {
        $metadata = new MetadataFactory();
        $entities = new EntityClassRegistry($metadata, classes: [EmailLog::class]);
        $tool = new SchemaTool($metadata, $entities, new SchemaSqlGenerator(), $this->database);
        self::assertTrue((new WordPressSqlExecutor($this->database))->execute($tool->getCreateSchemaSql()));
        $manager = new EntityManager($metadata, $entities, new EntityHydrator(), new WpdbConnection($this->database));
        $manager->persist(new EmailLog('even', new \DateTimeImmutable(), 'queued'));
        $manager->persist(new EmailLog('odd', new \DateTimeImmutable(), 'queued'));
        $manager->flush();
        $manager->clear();
        $ids = static fn (array $logs): array => array_map(static fn (EmailLog $log): string => $log->id, $logs);
        $query = static fn (mixed $divisor): array => $ids($manager->createQueryBuilder()->select('l')->from(EmailLog::class, 'l')
            ->where('LENGTH(l.id) %:n = 0')->setParameter('n', $divisor)->getQuery()->getResult());

        self::assertSame(['even'], $query(2));
        // Unquoted, the value read "% 2 OR 1=1 = 0" and matched the odd row instead; quoted, MySQL casts it to 2.
        self::assertSame(['even'], $query('2 OR 1=1'));
    }

    public function testAndWhereAfterAnOrPredicateNarrowsTheResult(): void
    {
        $metadata = new MetadataFactory();
        $entities = new EntityClassRegistry($metadata, classes: [Article::class, Author::class]);
        $tool = new SchemaTool($metadata, $entities, new SchemaSqlGenerator(), $this->database);
        self::assertTrue((new WordPressSqlExecutor($this->database))->execute($tool->getCreateSchemaSql()));
        $manager = new EntityManager($metadata, $entities, new EntityHydrator(), $this->database);
        $ada = new Author('ada', 'Ada');
        $bob = new Author('bob', 'Bob');
        $manager->persist($ada);
        $manager->persist($bob);
        $manager->persist(new Article('by-bob', 'x', $bob));
        $manager->persist(new Article('by-ada', 'y', $ada));
        $manager->flush();
        $manager->clear();

        $articles = $manager->createQueryBuilder()->select('a')->from(Article::class, 'a')
            ->where('a.title = :x OR a.title = :y')->andWhere('a.author = :author')
            ->setParameters(['x' => 'x', 'y' => 'y', 'author' => 'ada'])
            ->getQuery()->getResult();

        // Without parentheses the author filter applied to the second branch only and Bob's article matched too.
        self::assertSame(['by-ada'], array_map(static fn (Article $article): string => $article->id, $articles));
    }

    public function testQueriesOnASingleTableSubclassSeeOnlyItsRows(): void
    {
        $metadata = new MetadataFactory();
        $entities = new EntityClassRegistry($metadata, classes: [Vehicle::class, Car::class, SportsCar::class, Garage::class]);
        $tool = new SchemaTool($metadata, $entities, new SchemaSqlGenerator(), $this->database);
        self::assertTrue((new WordPressSqlExecutor($this->database))->execute($tool->getCreateSchemaSql()));
        $manager = new EntityManager($metadata, $entities, new EntityHydrator(), $this->database);
        $manager->persist(new Vehicle('v1'));
        $manager->persist(new Car('c1'));
        $manager->persist(new SportsCar('s1'));
        $manager->flush();
        $manager->clear();
        $ids = static fn (array $vehicles): array => array_map(static fn (Vehicle $vehicle): string => $vehicle->id, $vehicles);

        self::assertSame(['c1', 's1'], $ids($manager->getRepository(Car::class)->findBy([], ['id' => 'ASC'])));
        self::assertNull($manager->find(Car::class, 'v1'));
        $manager->createQuery('DELETE FROM Car c WHERE c.id <> :none', ['none' => 'x'])->execute();
        self::assertSame(['v1'], $this->database->get_col('SELECT id FROM wp_sympress_vehicles'));
    }

    public function testPersistingANewObjectForAnExistingRowWritesItsState(): void
    {
        $metadata = new MetadataFactory();
        $entities = new EntityClassRegistry($metadata, classes: [Article::class, Author::class]);
        $tool = new SchemaTool($metadata, $entities, new SchemaSqlGenerator(), $this->database);
        self::assertTrue((new WordPressSqlExecutor($this->database))->execute($tool->getCreateSchemaSql()));
        $manager = new EntityManager($metadata, $entities, new EntityHydrator(), $this->database);
        $author = new Author('ada', 'Ada');
        $manager->persist($author);
        $manager->persist(new Article('a1', 'Old', $author));
        $manager->flush();
        $manager->clear();

        $manager->persist(new Article('a1', 'New', $manager->getReference(Author::class, 'ada')));
        $manager->flush();
        $manager->clear();

        self::assertSame('New', $manager->find(Article::class, 'a1')?->title);
        self::assertSame('1', $this->database->get_var('SELECT COUNT(*) FROM wp_sympress_articles'));
    }

    public function testSavepointsRollBackInnerWritesWhileCommittingOuterWrites(): void
    {
        $metadata = new MetadataFactory();
        $tool = new SchemaTool($metadata, new EntityClassRegistry($metadata, classes: [EmailLog::class]), new SchemaSqlGenerator(), $this->database);
        self::assertTrue((new WordPressSqlExecutor($this->database))->execute($tool->getCreateSchemaSql()));
        $connection = new WpdbConnection($this->database);
        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO wp_sympress_mailer_logs (id, created_at, status) VALUES (%s, NOW(), %s)', 'outer', 'queued');
        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO wp_sympress_mailer_logs (id, created_at, status) VALUES (%s, NOW(), %s)', 'inner', 'queued');
        $connection->rollBack();
        $connection->commit();
        self::assertSame(['outer'], $this->database->get_col('SELECT id FROM wp_sympress_mailer_logs'));
    }

    /** @return iterable<string, array{bool}> */
    public static function schemaPolicies(): iterable
    {
        yield 'explicit destructive intent' => [true];
        yield 'safe default' => [false];
    }

    #[DataProvider('schemaPolicies')]
    public function testDestructiveSchemaPlanRefreshesChangesBetweenGenerationAndLockAcquisition(bool $destructive): void
    {
        $metadata = new MetadataFactory();
        $entities = new EntityClassRegistry($metadata, classes: [NarrowEmailLog::class]);
        $tool = new SchemaTool($metadata, $entities, new SchemaSqlGenerator(), $this->database, allowDestructiveUpdates: $destructive);
        $executor = new WordPressSqlExecutor($this->database);
        self::assertTrue($executor->execute($tool->getCreateSchemaSql('default')));
        self::assertSame([], $tool->getUpdateSchemaSql('default'));
        $migration = (new SchemaMigrationFactory($tool))->create('default');
        $version = $migration->getVersion();
        // Another worker changes the live schema after generation, before the operation lock.
        self::assertNotFalse($this->database->query('ALTER TABLE wp_sympress_mailer_logs MODIFY COLUMN status varchar(40) NOT NULL'));
        self::assertSame(1, $this->database->insert('wp_sympress_mailer_logs', ['id' => 'preserved', 'created_at' => '2026-10-02 10:00:00', 'status' => 'queued']));
        $manager = new MigrationManager(
            PluginSlug::fromString('drift'),
            new MigrationLifecycle(new MigrationTracker($this->database, $this->database->prefix . 'orm_review_state'), $executor),
            MigrationCollection::fromIterable([$migration]),
        );
        if ($destructive) {
            self::assertTrue($manager->runMigrations());
        } else {
            try {
                $manager->runMigrations();
                self::fail('A newly incompatible live schema must remain pending.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('explicit', $exception->getMessage());
            }
        }
        self::assertSame($version, $migration->getVersion());
        $columns = $this->database->get_results('DESCRIBE wp_sympress_mailer_logs', ARRAY_A);
        self::assertSame($destructive ? 'varchar(10)' : 'varchar(40)', array_column($columns, 'Type', 'Field')['status']);
        self::assertSame('queued', $this->database->get_var("SELECT status FROM wp_sympress_mailer_logs WHERE id = 'preserved'"));
        self::assertSame(!$destructive, $manager->hasPendingMigrations());
    }
}

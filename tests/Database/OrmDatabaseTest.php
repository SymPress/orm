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
use SymPress\Orm\Tests\Fixtures\EmailLog;
use SymPress\Orm\Tests\Fixtures\NumericRole;
use SymPress\Orm\Tests\Fixtures\NarrowEmailLog;
use SymPress\Orm\Tests\Fixtures\StringDefaults;
use SymPress\Orm\Tests\Fixtures\StringDefaultsWithMemo;
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
        foreach (['sympress_mailer_logs', 'sympress_numeric_roles', 'orm_review_state', 'orm_review_state_history', 'orm_string_defaults'] as $name) {
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
        self::assertStringContainsString('ADD COLUMN memo', $sql[0]);
        self::assertTrue(new WordPressSqlExecutor($this->database)->execute($sql));
        $additive->refreshSchemaState();
        self::assertSame([], $additive->getUpdateSchemaSql('default'));

        $this->database->query("ALTER TABLE wp_orm_string_defaults MODIFY status VARCHAR(40) NOT NULL DEFAULT 'pending'");
        $additive->refreshSchemaState();
        self::assertTrue($additive->requiresDestructiveUpdates('default'));
        $this->expectException(\SymPress\WordPress\Migration\Exception\MigrationOperationException::class);
        new SchemaMigrationFactory($additive)->create('default')->up();
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

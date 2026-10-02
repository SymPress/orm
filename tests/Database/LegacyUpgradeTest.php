<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Database;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\Bridge\Migration\SchemaMigrationFactory;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Schema\SchemaSqlGenerator;
use SymPress\Orm\Schema\SchemaTool;
use SymPress\Orm\Tests\Fixtures\EmailLog;
use SymPress\Orm\Tests\Fixtures\NumericRole;
use SymPress\WordPress\Migration\Application\MigrationLifecycle;
use SymPress\WordPress\Migration\Domain\MigrationCollection;
use SymPress\WordPress\Migration\Domain\MigrationManager;
use SymPress\WordPress\Migration\Infrastructure\MigrationTracker;
use SymPress\WordPress\Migration\Infrastructure\WordPressSqlExecutor;
use SymPress\WordPress\Migration\Value\PluginSlug;
use Symfony\Component\Process\Process;

final class LegacyUpgradeTest extends TestCase
{
    protected function setUp(): void
    {
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $database = $GLOBALS['wpdb'];
        foreach (['sympress_mailer_logs', 'sympress_numeric_roles', 'orm_review_state', 'orm_review_state_history'] as $table) {
            $database->query($database->prepare('DROP TABLE IF EXISTS %i', $database->prefix . $table));
        }
    }

    public function testUpgradeFromActualOrm020PreservesRowsAndLegacyHistory(): void
    {
        $source = getenv('SYMPRESS_ORM_LEGACY_SOURCE');
        self::assertIsString($source, 'The database suite requires the pinned ORM 0.2.0 source fixture.');
        $revision = new Process(['git', '-C', $source, 'rev-parse', 'HEAD']);
        $revision->mustRun();
        self::assertSame('4ef5ec98a971e27c1180084ada76befbb8bf0b5a', trim($revision->getOutput()));
        $seed = new Process([PHP_BINARY, __DIR__ . '/seed-legacy-orm.php']);
        $seed->mustRun();
        $database = $GLOBALS['wpdb'];
        $before = $database->get_results('SELECT * FROM wp_sympress_mailer_logs ORDER BY id', ARRAY_A);
        self::assertSame('legacy-020', $before[0]['id']);
        $tracker = new MigrationTracker($database, $database->prefix . 'orm_review_state');
        $legacy = $tracker->findRecordsForPlugin('default')[0];
        $history = $tracker->findHistoryForPlugin('default')[0];
        self::assertStringContainsString('@anonymous', $legacy->migration);
        $metadata = new MetadataFactory();
        $entities = new EntityClassRegistry($metadata, classes: [EmailLog::class, NumericRole::class]);
        $tool = new SchemaTool($metadata, $entities, new SchemaSqlGenerator(), $database);
        $migration = (new SchemaMigrationFactory($tool))->create('default');
        $manager = new MigrationManager(PluginSlug::fromString('default'), new MigrationLifecycle($tracker, new WordPressSqlExecutor($database)), MigrationCollection::fromIterable([$migration]));
        try {
            $manager->runMigrations();
            self::fail('Unmapped legacy state must stop before schema mutation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Unmapped legacy', $exception->getMessage());
        }
        self::assertTrue($manager->adoptLegacyMigration('orm-schema:default', $legacy->migration, $legacy->version));
        self::assertSame($legacy->version, $tracker->getVersion('default', 'orm-schema:default'));
        self::assertTrue($manager->hasPendingMigrations());
        self::assertTrue($manager->runMigrations());
        self::assertFalse($manager->hasPendingMigrations());
        self::assertSame($before, $database->get_results('SELECT * FROM wp_sympress_mailer_logs ORDER BY id', ARRAY_A));
        self::assertEquals($history, $tracker->findHistoryForPlugin('default')[2]);
        self::assertSame(['up', 'adopt', 'up'], array_map(static fn ($entry): string => $entry->direction, $tracker->findHistoryForPlugin('default')));
        self::assertNull($tracker->getVersion('default', $legacy->migration));
        self::assertSame($migration->getVersion(), $tracker->getVersion('default', 'orm-schema:default'));
        self::assertTrue($manager->runMigrations());
        self::assertCount(3, $tracker->findHistoryForPlugin('default'));
    }
}

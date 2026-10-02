<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\Bridge\Migration\SchemaMigrationFactory;
use SymPress\Orm\Command\MigrationDiffCommand;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Schema\SchemaSqlGenerator;
use SymPress\Orm\Schema\SchemaTool;
use SymPress\Orm\Tests\Fixtures\EmailLog;
use SymPress\Orm\Tests\Fixtures\NarrowEmailLog;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrationBridgeTest extends TestCase
{
    private function tool(): SchemaTool
    {
        $factory = new MetadataFactory();
        return new SchemaTool($factory, new EntityClassRegistry($factory, classes: [EmailLog::class]), new SchemaSqlGenerator(), new \wpdb());
    }

    public function testEntityDiscoveryIsCachedAndExplicitRegistrationStillWorks(): void
    {
        $path = sys_get_temp_dir() . '/orm-discovery-' . bin2hex(random_bytes(4));
        mkdir($path);
        try {
            $factory = new MetadataFactory();
            $registry = new EntityClassRegistry($factory, paths: [$path]);
            self::assertSame([], $registry->classes());
            copy(dirname(__DIR__) . '/Fixtures/EmailLog.php', $path . '/EmailLog.php');
            self::assertSame([], $registry->classes());
            self::assertSame([EmailLog::class], (new EntityClassRegistry($factory, paths: [$path]))->classes());
            $registry->register(EmailLog::class);
            self::assertSame([EmailLog::class], $registry->classes());
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- disposable discovery fixture.
            unlink($path . '/EmailLog.php');
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- disposable discovery directory.
            rmdir($path);
        }
    }

    public function testFactoryRollbackIsIrreversibleAndIdentityUsesIntendedSchema(): void
    {
        $tool = $this->tool();
        $migration = (new SchemaMigrationFactory($tool))->create('default');
        self::assertSame('orm-schema:default', $migration->getMigrationKey());
        self::assertSame('schema:' . substr(hash('sha256', implode("\n\n", $tool->getCreateSchemaSql('default'))), 0, 16), $migration->getVersion());
        self::assertStringContainsString('CREATE TABLE', implode("\n", $migration->up()));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('irreversible');
        $migration->down();
    }

    public function testLegacySchemaIdentitiesAreExplicitAndScopedToEachManager(): void
    {
        $legacy = "Migration@anonymous\0/old/releases/1/SchemaMigrationFactory.php:40";
        $factory = new SchemaMigrationFactory($this->tool(), ['default' => [$legacy]]);
        self::assertSame([$legacy], $factory->create('default')->getLegacyMigrationKeys());
        self::assertSame([], $factory->create('other')->getLegacyMigrationKeys());
        self::assertSame([], (new SchemaMigrationFactory($this->tool()))->create('default')->getLegacyMigrationKeys());
    }

    public function testSchemaPlanRefreshesLiveStateAfterFactoryCreation(): void
    {
        $database = new class extends \wpdb {
            public int $statusLength = 10;

            public function get_results(string $query, string|int $output = ARRAY_A): array
            {
                if (!str_starts_with($query, 'DESCRIBE')) {
                    return [];
                }
                return [
                    ['Field' => 'id', 'Type' => 'varchar(32)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                    ['Field' => 'created_at', 'Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                    ['Field' => 'status', 'Type' => 'varchar(' . $this->statusLength . ')', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                    ['Field' => 'payload', 'Type' => 'longtext', 'Null' => 'YES', 'Default' => null, 'Extra' => ''],
                ];
            }
        };
        $database->countResult = 1;
        $metadata = new MetadataFactory();
        $tool = new SchemaTool($metadata, new EntityClassRegistry($metadata, classes: [NarrowEmailLog::class]), new SchemaSqlGenerator(), $database, allowDestructiveUpdates: true);
        $tool->getUpdateSchemaSql('default');
        $migration = (new SchemaMigrationFactory($tool))->create('default');
        $version = $migration->getVersion();
        $database->statusLength = 20;
        self::assertStringContainsString('MODIFY COLUMN status varchar(10)', implode("\n", $migration->up()));
        self::assertSame($version, $migration->getVersion());
        $database->statusLength = 10;
        self::assertStringNotContainsString('MODIFY COLUMN status', implode("\n", $migration->up()));
    }

    public function testGeneratedCommandUsesIrreversibleRollbackAndRejectsNamespaceInjection(): void
    {
        $path = sys_get_temp_dir() . '/orm-diff-' . bin2hex(random_bytes(4));
        $tester = new CommandTester(new MigrationDiffCommand($this->tool()));
        self::assertSame(2, $tester->execute(['--path' => $path, '--namespace' => 'App; malicious()']));
        self::assertDirectoryDoesNotExist($path);
        self::assertSame(0, $tester->execute(['--path' => $path, '--namespace' => 'App\\Migration']));
        $files = glob($path . '/*.php') ?: [];
        self::assertCount(1, $files);
        try {
            $source = (string) file_get_contents($files[0]);
            self::assertStringNotContainsString('DROP TABLE', $source);
            self::assertStringContainsString('throw new \\RuntimeException', $source);
            self::assertStringContainsString('namespace App\\Migration;', $source);
            require $files[0];
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- disposable test artifact, no WordPress runtime.
            unlink($files[0]);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- disposable test directory.
            rmdir($path);
        }
    }
}

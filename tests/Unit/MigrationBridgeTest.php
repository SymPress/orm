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

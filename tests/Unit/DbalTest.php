<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\Dbal\ConnectionProvider;
use SymPress\Orm\Dbal\WordPressSqlPlatform;
use SymPress\Orm\Dbal\WpdbConnection;

final class DbalTest extends TestCase
{
    public function testWordPressPlatformQuotesIdentifiersAndUsesWpdbPlaceholders(): void
    {
        $platform = new WordPressSqlPlatform();

        self::assertSame('`post_id`', $platform->quoteIdentifier('post_id'));
        self::assertSame('%d', $platform->parameterPlaceholder(123));
        self::assertSame('%d', $platform->parameterPlaceholder(true));
        self::assertSame('%f', $platform->parameterPlaceholder(12.5));
        self::assertSame('%s', $platform->parameterPlaceholder('queued'));
    }

    public function testWpdbConnectionKeepsWpdbAsRuntime(): void
    {
        $database = new \wpdb();
        $connection = new WpdbConnection($database);

        self::assertSame('wp_', $connection->tablePrefix());
        self::assertSame('DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $connection->charsetCollate());

        $connection->beginTransaction();
        $connection->commit();
        $connection->rollBack();

        self::assertSame(['START TRANSACTION', 'COMMIT'], $database->queries);
    }

    public function testWpdbConnectionFallsBackToGlobalWpdb(): void
    {
        $previousDatabase = $GLOBALS['wpdb'] ?? null;
        $database = new \wpdb();
        $database->prefix = 'site_';
        $GLOBALS['wpdb'] = $database;

        try {
            $connection = new WpdbConnection();

            self::assertSame('site_', $connection->tablePrefix());

            $connection->executeStatement('SELECT %s', 'ready');

            self::assertSame(["SELECT 'ready'"], $database->queries);
        } finally {
            if ($previousDatabase instanceof \wpdb) {
                $GLOBALS['wpdb'] = $previousDatabase;
            } else {
                unset($GLOBALS['wpdb']);
            }
        }
    }

    public function testWpdbConnectionFailsWhenNoDatabaseIsAvailable(): void
    {
        $hadDatabase = array_key_exists('wpdb', $GLOBALS);
        $previousDatabase = $GLOBALS['wpdb'] ?? null;
        unset($GLOBALS['wpdb']);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Global $wpdb is not available.');

            (new WpdbConnection())->tablePrefix();
        } finally {
            if ($hadDatabase) {
                $GLOBALS['wpdb'] = $previousDatabase;
            }
        }
    }

    public function testConnectionProviderNormalizesWpdbAndResolvesGlobalConnectionLazily(): void
    {
        $database = new \wpdb();
        $database->prefix = 'custom_';

        self::assertSame('custom_', ConnectionProvider::fromDatabase($database)->connection()->tablePrefix());

        $previousDatabase = $GLOBALS['wpdb'] ?? null;
        $globalDatabase = new \wpdb();
        $globalDatabase->prefix = 'global_';
        $GLOBALS['wpdb'] = $globalDatabase;

        try {
            self::assertSame('global_', ConnectionProvider::fromDatabase(null)->connection()->tablePrefix());
        } finally {
            if ($previousDatabase instanceof \wpdb) {
                $GLOBALS['wpdb'] = $previousDatabase;
            } else {
                unset($GLOBALS['wpdb']);
            }
        }
    }

    public function testNestedRollbackPreservesOuterTransaction(): void
    {
        $database = new \wpdb();
        $connection = new WpdbConnection($database);
        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->rollBack();
        self::assertTrue($connection->isTransactionActive());
        $connection->commit();
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(['START TRANSACTION', 'SAVEPOINT sympress_1', 'ROLLBACK TO SAVEPOINT sympress_1', 'COMMIT'], $database->queries);
        self::assertSame('table\\_name\\%', $connection->escapeLike('table_name%'));
    }

    public function testFailedCommitStillAllowsRollback(): void
    {
        $database = new class extends \wpdb {
            public function query(string $query): bool|int
            {
                $this->queries[] = $query;
                return $query !== 'COMMIT';
            }
        };
        $connection = new WpdbConnection($database);
        $connection->beginTransaction();
        try {
            $connection->commit();
            self::fail('Expected failed commit.');
        } catch (\RuntimeException) {
            self::assertTrue($connection->isTransactionActive());
        }
        $connection->rollBack();
        self::assertSame(['START TRANSACTION', 'COMMIT', 'ROLLBACK'], $database->queries);
    }
}

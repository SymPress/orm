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

        self::assertSame(['START TRANSACTION', 'COMMIT', 'ROLLBACK'], $database->queries);
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
}

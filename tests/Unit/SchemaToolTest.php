<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\Orm\Metadata\EntityClassRegistry;
use SymPress\Orm\Metadata\MetadataFactory;
use SymPress\Orm\Schema\SchemaSqlGenerator;
use SymPress\Orm\Schema\SchemaTool;
use SymPress\Orm\Tests\Fixtures\EmailLog;
use SymPress\Orm\Tests\Fixtures\NumericRole;
use SymPress\Orm\Tests\Fixtures\NumericUser;

final class SchemaToolTest extends TestCase
{
    public function testItGeneratesWordPressCompatibleCreateTableSql(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [EmailLog::class]);
        $tool = new SchemaTool($metadataFactory, $registry, new SchemaSqlGenerator(), new \wpdb());

        $sql = $tool->getUpdateSchemaSql()[0] ?? '';

        self::assertStringContainsString('CREATE TABLE wp_sympress_mailer_logs', $sql);
        self::assertStringContainsString('id varchar(32) NOT NULL', $sql);
        self::assertStringContainsString('created_at datetime NOT NULL', $sql);
        self::assertStringContainsString('payload longtext NULL', $sql);
        self::assertStringContainsString('PRIMARY KEY  (id)', $sql);
        self::assertStringContainsString('KEY status_created (status, created_at)', $sql);
    }

    public function testJoinTablesUseReferencedColumnTypes(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [NumericUser::class, NumericRole::class]);
        $tool = new SchemaTool($metadataFactory, $registry, new SchemaSqlGenerator(), new \wpdb());

        $sql = implode("\n\n", $tool->getCreateSchemaSql());

        self::assertStringContainsString('CREATE TABLE wp_sympress_numeric_user_roles', $sql);
        self::assertStringContainsString('user_id bigint unsigned NOT NULL', $sql);
        self::assertStringContainsString('role_id bigint unsigned NOT NULL', $sql);
    }

    public function testUpdateSchemaSqlIsNonDestructiveByDefault(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [EmailLog::class]);
        $database = $this->databaseWithLegacySchema();
        $database->countResult = 1;
        $tool = new SchemaTool($metadataFactory, $registry, new SchemaSqlGenerator(), $database);

        $safeSql = implode("\n", $tool->getUpdateSchemaSql());
        $destructiveSql = implode("\n", $tool->getUpdateSchemaSql(null, true));

        self::assertStringNotContainsString('DROP COLUMN legacy', $safeSql);
        self::assertStringNotContainsString('DROP INDEX legacy_idx', $safeSql);
        self::assertStringContainsString('DROP COLUMN legacy', $destructiveSql);
        self::assertStringContainsString('DROP INDEX legacy_idx', $destructiveSql);
    }

    public function testConstructorDestructiveFlagControlsSchemaDiff(): void
    {
        $metadataFactory = new MetadataFactory();
        $registry = new EntityClassRegistry($metadataFactory, classes: [EmailLog::class]);
        $database = $this->databaseWithLegacySchema();
        $database->countResult = 1;
        $tool = new SchemaTool(
            $metadataFactory,
            $registry,
            new SchemaSqlGenerator(),
            $database,
            allowDestructiveUpdates: true,
        );

        $sql = implode("\n", $tool->getUpdateSchemaSql());

        self::assertStringContainsString('DROP COLUMN legacy', $sql);
        self::assertStringContainsString('DROP INDEX legacy_idx', $sql);
    }

    private function databaseWithLegacySchema(): \wpdb
    {
        return new class extends \wpdb {
            public function get_results(string $query, string|int $output = ARRAY_A): array
            {
                if (str_starts_with($query, 'DESCRIBE')) {
                    return [
                        ['Field' => 'id', 'Type' => 'varchar(32)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                        ['Field' => 'created_at', 'Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                        ['Field' => 'status', 'Type' => 'varchar(20)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                        ['Field' => 'payload', 'Type' => 'longtext', 'Null' => 'YES', 'Default' => null, 'Extra' => ''],
                        ['Field' => 'legacy', 'Type' => 'varchar(20)', 'Null' => 'YES', 'Default' => null, 'Extra' => ''],
                    ];
                }

                if (str_starts_with($query, 'SHOW INDEX')) {
                    return [
                        ['Key_name' => 'legacy_idx', 'Column_name' => 'legacy', 'Non_unique' => 1, 'Seq_in_index' => 1],
                    ];
                }

                return [];
            }
        };
    }
}

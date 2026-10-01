<?php

declare(strict_types=1);

namespace SymPress\Orm\Bridge\Migration;

use SymPress\Orm\Schema\SchemaTool;
use SymPress\WordPress\Migration\Contract\Migration;

final readonly class SchemaMigrationFactory
{
    /** @param array<string, list<string>> $legacyMigrationKeys Exact recorded identities by manager name. */
    public function __construct(
        private SchemaTool $schemaTool,
        private array $legacyMigrationKeys = [],
    ) {
    }

    public function isAvailable(): bool
    {
        return interface_exists('SymPress\\WordPress\\Migration\\Contract\\Migration');
    }

    public function create(string $manager): object
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('sympress/migration is not available.');
        }

        $up = $this->schemaTool->getUpdateSchemaSql($manager);
        $key = 'orm-schema:' . $manager;
        $version = 'schema:' . $this->schemaTool->getSchemaHash($manager);

        $blocked = $this->schemaTool->requiresDestructiveUpdates($manager);

        $legacy = $this->legacyMigrationKeys[$manager] ?? [];

        return new class ($version, $up, $key, $blocked, $legacy) implements Migration {
            /**
             * @param list<string> $up
             * @param list<string> $legacyKeys
             */
            public function __construct(
                private readonly string $version,
                private readonly array $up,
                private readonly string $key,
                private readonly bool $blocked,
                private readonly array $legacyKeys,
            ) {
            }

            public function getMigrationKey(): string
            {
                return $this->key;
            }

            /** @return list<string> */
            public function getLegacyMigrationKeys(): array
            {
                return $this->legacyKeys;
            }

            public function getVersion(): string
            {
                return $this->version;
            }

            /** @return list<string> */
            public function up(): array
            {
                if ($this->blocked) {
                    throw new \RuntimeException('Schema changes require explicit destructive-update intent; the intended schema remains pending.');
                }

                return $this->up;
            }

            /** @return list<string> */
            public function down(): array
            {
                throw new \RuntimeException('Generated ORM schema migrations are irreversible; supply an explicit reviewed inverse migration.');
            }
        };
    }
}

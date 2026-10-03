<?php

declare(strict_types=1);

namespace SymPress\Orm\Bridge\Migration;

use SymPress\Orm\Schema\SchemaTool;
use SymPress\WordPress\Migration\Contract\Migration;
use SymPress\WordPress\Migration\Exception\MigrationOperationException;

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

        $key = 'orm-schema:' . $manager;
        $version = 'schema:' . $this->schemaTool->getSchemaHash($manager);

        $legacy = $this->legacyMigrationKeys[$manager] ?? [];

        return new class ($version, $this->schemaTool, $manager, $key, $legacy) implements Migration {
            /**
             * @param list<string> $legacyKeys
             */
            public function __construct(
                private readonly string $version,
                private readonly SchemaTool $schemaTool,
                private readonly string $manager,
                private readonly string $key,
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
                $this->schemaTool->refreshSchemaState();
                $up = $this->schemaTool->getUpdateSchemaSql($this->manager);
                if ($this->schemaTool->requiresDestructiveUpdates($this->manager)) {
                    throw new MigrationOperationException('Schema changes require explicit destructive-update intent; the intended schema remains pending.');
                }

                return $up;
            }

            /** @return list<string> */
            public function down(): array
            {
                throw new MigrationOperationException('Generated ORM schema migrations are irreversible; supply an explicit reviewed inverse migration.');
            }
        };
    }
}

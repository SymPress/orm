<?php

declare(strict_types=1);

namespace SymPress\Orm\Bridge\Migration;

use SymPress\Orm\Schema\SchemaTool;

final readonly class SchemaMigrationFactory
{
    public function __construct(private SchemaTool $schemaTool)
    {
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

        return new class ($version, $up, $key, $blocked) implements \SymPress\WordPress\Migration\Contract\Migration {
            /**
             * @param list<string> $up
             */
            public function __construct(
                private readonly string $version,
                private readonly array $up,
                private readonly string $key,
                private readonly bool $blocked,
            ) {
            }

            public function getMigrationKey(): string
            {
                return $this->key;
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

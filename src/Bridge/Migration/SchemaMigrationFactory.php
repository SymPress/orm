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
        $down = $this->schemaTool->getDropSchemaSql($manager);
        $version = 'schema:' . $this->schemaTool->getSchemaHash($manager);

        return new class ($version, $up, $down) implements \SymPress\WordPress\Migration\Contract\Migration {
            /**
             * @param list<string> $up
             * @param list<string> $down
             */
            public function __construct(
                private readonly string $version,
                private readonly array $up,
                private readonly array $down,
            ) {
            }

            public function getVersion(): string
            {
                return $this->version;
            }

            /** @return list<string> */
            public function up(): array
            {
                return $this->up;
            }

            /** @return list<string> */
            public function down(): array
            {
                return $this->down;
            }
        };
    }
}

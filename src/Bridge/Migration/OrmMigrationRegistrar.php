<?php

declare(strict_types=1);

namespace SymPress\Orm\Bridge\Migration;

use SymPress\Orm\Metadata\EntityClassRegistry;

final readonly class OrmMigrationRegistrar
{
    public function __construct(
        private EntityClassRegistry $entities,
        private SchemaMigrationFactory $factory,
    ) {
    }

    public function register(mixed $migrationSystem): void
    {
        if (!$this->factory->isAvailable() || !is_object($migrationSystem)) {
            return;
        }

        if (!method_exists($migrationSystem, 'registerMigration')) {
            return;
        }

        foreach ($this->entities->groups() as $manager => $classes) {
            if ($classes === []) {
                continue;
            }

            $migrationSystem->registerMigration($manager, $this->factory->create($manager));
        }
    }
}

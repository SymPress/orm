<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Repository;

#[Entity(repositoryClass: Repository::class, table: 'sympress_repository_logs')]
final class RepositoryLog
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
    ) {
    }
}

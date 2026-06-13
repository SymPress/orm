<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;

#[Entity(table: 'sympress_numeric_roles')]
final class NumericRole
{
    public function __construct(
        #[Id]
        #[Column(type: 'bigint', unsigned: true)]
        public int $id,
        #[Column(type: 'string', length: 100)]
        public string $name,
    ) {
    }
}

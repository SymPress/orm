<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;

#[Entity(table: 'portable_values')]
final readonly class PortableValueEntity
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[Column(type: 'string', length: 32, default: 'wp_literal')]
        public string $label = 'wp_literal',
    ) {
    }
}

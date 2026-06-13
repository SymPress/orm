<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\DiscriminatorColumn;
use SymPress\Orm\Mapping\DiscriminatorMap;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\InheritanceType;

#[Entity(table: 'sympress_animals')]
#[InheritanceType(InheritanceType::SINGLE_TABLE)]
#[DiscriminatorColumn(name: 'kind', type: 'string', length: 20)]
#[DiscriminatorMap(['animal' => Animal::class, 'dog' => Dog::class])]
class Animal
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[Column(type: 'string', length: 100)]
        public string $name,
    ) {
    }
}

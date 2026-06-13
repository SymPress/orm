<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;

#[Entity]
class Dog extends Animal
{
    public function __construct(
        string $id,
        string $name,
        #[Column(type: 'integer', nullable: true)]
        public int $barkVolume = 0,
    ) {

        parent::__construct($id, $name);
    }
}

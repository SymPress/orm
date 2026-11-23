<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Collection\Collection;
use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\InverseJoinColumn;
use SymPress\Orm\Mapping\JoinColumn;
use SymPress\Orm\Mapping\JoinTable;
use SymPress\Orm\Mapping\ManyToMany;
use SymPress\Orm\Mapping\OneToMany;

#[Entity(table: 'sympress_garages')]
final class Garage
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[OneToMany(targetEntity: Car::class, mappedBy: 'garage')]
        public Collection $cars = new Collection(),
        #[ManyToMany(targetEntity: Car::class)]
        #[JoinTable(
            name: 'sympress_garage_rentals',
            joinColumns: [new JoinColumn(name: 'garage_id', referencedColumnName: 'id', nullable: false)],
            inverseJoinColumns: [new InverseJoinColumn(name: 'vehicle_id', referencedColumnName: 'id', nullable: false)],
        )]
        public Collection $rentals = new Collection(),
    ) {
    }
}

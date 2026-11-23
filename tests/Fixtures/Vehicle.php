<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\DiscriminatorColumn;
use SymPress\Orm\Mapping\DiscriminatorMap;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\InheritanceType;
use SymPress\Orm\Mapping\JoinColumn;
use SymPress\Orm\Mapping\ManyToOne;

#[Entity(table: 'sympress_vehicles')]
#[InheritanceType(InheritanceType::SINGLE_TABLE)]
#[DiscriminatorColumn(name: 'kind', type: 'string', length: 20)]
#[DiscriminatorMap(['vehicle' => Vehicle::class, 'car' => Car::class, 'sports_car' => SportsCar::class])]
class Vehicle
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[ManyToOne(targetEntity: Garage::class)]
        #[JoinColumn(name: 'garage_id', referencedColumnName: 'id', nullable: true)]
        public ?Garage $garage = null,
    ) {
    }
}

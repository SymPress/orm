<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Collection\Collection;
use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\JoinColumn;
use SymPress\Orm\Mapping\ManyToOne;
use SymPress\Orm\Mapping\OneToMany;

/** Cascades remove to a to-one before and after a to-many, so both visiting orders are covered. */
#[Entity(table: 'sympress_profiles')]
final class Profile
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[ManyToOne(targetEntity: Address::class, cascade: ['remove'])]
        #[JoinColumn(name: 'billing_id', referencedColumnName: 'id', nullable: true)]
        public ?Address $billing = null,
        #[OneToMany(targetEntity: Note::class, mappedBy: 'profile', cascade: ['remove'])]
        public Collection $notes = new Collection(),
        #[ManyToOne(targetEntity: Address::class, cascade: ['remove'])]
        #[JoinColumn(name: 'shipping_id', referencedColumnName: 'id', nullable: true)]
        public ?Address $shipping = null,
    ) {
    }
}

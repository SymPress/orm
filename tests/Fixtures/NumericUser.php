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

#[Entity(table: 'sympress_numeric_users')]
final class NumericUser
{
    public function __construct(
        #[Id]
        #[Column(type: 'bigint', unsigned: true)]
        public int $id,
        #[Column(type: 'string', length: 100)]
        public string $name,
        #[ManyToMany(targetEntity: NumericRole::class)]
        #[JoinTable(
            name: 'sympress_numeric_user_roles',
            joinColumns: [new JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)],
            inverseJoinColumns: [new InverseJoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false)],
        )]
        public Collection $roles = new Collection(),
    ) {
    }
}

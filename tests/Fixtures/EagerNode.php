<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\JoinColumn;
use SymPress\Orm\Mapping\ManyToOne;

#[Entity(table: 'sympress_eager_nodes')]
final class EagerNode
{
    #[Id]
    #[Column(type: 'string', length: 32)]
    public string $id;

    #[ManyToOne(targetEntity: self::class, fetch: 'EAGER')]
    #[JoinColumn(name: 'partner_id', referencedColumnName: 'id', nullable: true)]
    public ?EagerNode $partner;
}

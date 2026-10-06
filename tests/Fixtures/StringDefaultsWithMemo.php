<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\Table;

#[Entity]
#[Table(name: 'orm_string_defaults')]
final class StringDefaultsWithMemo
{
    #[Id]
    #[Column(type: 'integer')]
    public int $id;

    #[Column(length: 40, default: 'Pending')]
    public string $status;

    #[Column(name: 'empty_value', length: 40, default: '')]
    public string $empty;

    #[Column(length: 40, default: "'Quoted'")]
    public string $quoted;

    #[Column(length: 40, default: ' two  spaces ')]
    public string $spaces;

    #[Column(nullable: true)]
    public ?string $memo = null;
}

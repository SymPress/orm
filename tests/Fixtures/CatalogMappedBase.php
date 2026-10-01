<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\MappedSuperclass;

#[MappedSuperclass]
abstract class CatalogMappedBase
{
    #[Id]
    #[Column]
    public string $id;
}

<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Embeddable;

#[Embeddable]
final class CatalogAddress
{
    #[Column(length: 80)]
    public string $city;
}

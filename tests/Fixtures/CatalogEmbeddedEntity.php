<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Embedded;
use SymPress\Orm\Mapping\Entity;

#[Entity(table: 'catalog_embedded')]
final class CatalogEmbeddedEntity extends CatalogMappedBase
{
    #[Embedded(columnPrefix: 'address_')]
    public CatalogAddress $address;
}

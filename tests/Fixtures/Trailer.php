<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Entity;

/** A mapped class without a discriminator value and without mapped subclasses: no row can belong to it. */
#[Entity]
abstract class Trailer extends Vehicle
{
}

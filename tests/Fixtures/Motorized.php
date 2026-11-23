<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Entity;

/** A mapped intermediate class without a discriminator value of its own. */
#[Entity]
abstract class Motorized extends Vehicle
{
}

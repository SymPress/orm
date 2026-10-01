<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Kernel\Bundle\AbstractBundle;

final class CatalogBundle extends AbstractBundle
{
    public function __construct(string $path)
    {
        $this->path = $path;
    }
}

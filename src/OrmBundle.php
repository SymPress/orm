<?php

declare(strict_types=1);

namespace SymPress\Orm;

use SymPress\Kernel\Bundle\AbstractBundle;
use SymPress\Orm\Compiler\EntityCatalogPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class OrmBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new EntityCatalogPass());
    }
}

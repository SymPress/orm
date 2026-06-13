<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;

#[Entity(table: 'sympress_tenants')]
final class Tenant
{
    public function __construct(
        #[Id]
        #[Column(name: 'tenant_id', type: 'string', length: 32)]
        public string $tenantId,
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $code,
        #[Column(type: 'string', length: 100)]
        public string $name,
    ) {
    }
}

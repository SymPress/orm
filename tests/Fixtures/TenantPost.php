<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\JoinColumn;
use SymPress\Orm\Mapping\ManyToOne;

#[Entity(table: 'sympress_tenant_posts')]
final class TenantPost
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[Column(type: 'string', length: 150)]
        public string $title,
        #[ManyToOne(targetEntity: Tenant::class)]
        #[JoinColumn(name: 'tenant_id', referencedColumnName: 'tenant_id', nullable: false)]
        #[JoinColumn(name: 'tenant_code', referencedColumnName: 'code', nullable: false)]
        public Tenant $tenant,
    ) {
    }
}

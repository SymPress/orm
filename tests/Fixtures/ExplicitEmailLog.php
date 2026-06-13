<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\ChangeTrackingPolicy;
use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;

#[Entity(table: 'sympress_mailer_logs')]
#[ChangeTrackingPolicy(ChangeTrackingPolicy::DEFERRED_EXPLICIT)]
final class ExplicitEmailLog
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[Column(type: 'string', length: 20)]
        public string $status,
    ) {
    }
}

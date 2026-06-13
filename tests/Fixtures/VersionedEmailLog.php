<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\Version;

#[Entity(table: 'sympress_mailer_logs')]
final class VersionedEmailLog
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[Column(type: 'string', length: 20)]
        public string $status,
        #[Version]
        #[Column(type: 'integer')]
        public int $version = 1,
    ) {
    }
}

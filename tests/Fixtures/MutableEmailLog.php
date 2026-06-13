<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;

#[Entity(table: 'sympress_mailer_logs')]
final class MutableEmailLog
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[Column(type: 'datetime')]
        public \DateTimeImmutable $createdAt,
        #[Column(type: 'string', length: 20)]
        public string $status,
    ) {
    }
}

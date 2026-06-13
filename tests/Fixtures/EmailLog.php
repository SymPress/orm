<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\Index;

#[Entity(table: 'sympress_mailer_logs')]
#[Index(name: 'status_created', columns: ['status', 'createdAt'])]
final readonly class EmailLog
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[Column(type: 'datetime')]
        public \DateTimeImmutable $createdAt,
        #[Column(type: 'string', length: 20)]
        public string $status,
        #[Column(type: 'json', nullable: true)]
        public array $payload = [],
    ) {
    }
}

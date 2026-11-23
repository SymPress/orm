<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;

/** One column per type in the SqlTypeMapper catalog. */
#[Entity(table: 'type_catalog')]
final class TypeCatalogEntity
{
    /**
     * @param array<string, mixed> $payload
     * @param list<mixed> $tags
     * @param list<string> $labels
     */
    public function __construct(
        #[Id]
        #[Column(type: 'guid')]
        public string $id,
        #[Column(type: 'string', length: 64)]
        public string $title,
        #[Column(type: 'text')]
        public string $body,
        #[Column(type: 'json')]
        public array $payload,
        #[Column(type: 'array')]
        public array $tags,
        #[Column(type: 'simple_array')]
        public array $labels,
        #[Column(type: 'smallint')]
        public int $rank,
        #[Column(type: 'integer')]
        public int $attempts,
        #[Column(type: 'bigint')]
        public int $bytes,
        #[Column(type: 'boolean')]
        public bool $active,
        #[Column(type: 'boolean')]
        public bool $archived,
        #[Column(type: 'float')]
        public float $ratio,
        #[Column(type: 'double')]
        public float $score,
        #[Column(type: 'decimal', precision: 10, scale: 2)]
        public float $price,
        #[Column(type: 'date')]
        public \DateTime $day,
        #[Column(type: 'date_immutable')]
        public \DateTimeImmutable $dayImmutable,
        #[Column(type: 'datetime')]
        public \DateTime $at,
        #[Column(type: 'datetime_immutable')]
        public \DateTimeImmutable $atImmutable,
        #[Column(type: 'time')]
        public \DateTime $clock,
        #[Column(type: 'time_immutable')]
        public \DateTimeImmutable $clockImmutable,
        #[Column(type: 'string', length: 16, enumType: MailStatus::class)]
        public MailStatus $status,
        #[Column(type: 'smallint', enumType: Priority::class)]
        public Priority $priority,
        #[Column(type: 'string', length: 64, nullable: true)]
        public ?string $note,
    ) {
    }
}

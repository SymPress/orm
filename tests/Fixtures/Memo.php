<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Embedded;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\HasLifecycleCallbacks;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\JoinColumn;
use SymPress\Orm\Mapping\ManyToOne;
use SymPress\Orm\Mapping\PrePersist;

/** Mapped properties that a new object may leave uninitialized: a column set by prePersist, a nullable column, a to-one and an embeddable. */
#[Entity(table: 'sympress_memos')]
#[HasLifecycleCallbacks]
final class Memo
{
    #[Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[Column(type: 'string', length: 50, nullable: true)]
    public ?string $note;

    #[ManyToOne(targetEntity: Author::class)]
    #[JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true)]
    public ?Author $author;

    #[Embedded(class: CatalogAddress::class, columnPrefix: 'address_')]
    public CatalogAddress $address;

    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[Column(type: 'string', length: 50)]
        public string $title = '',
    ) {
    }

    #[PrePersist]
    public function stamp(): void
    {
        $this->createdAt = new \DateTimeImmutable('2026-11-23 10:00:00');
    }
}

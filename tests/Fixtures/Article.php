<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\JoinColumn;
use SymPress\Orm\Mapping\ManyToOne;

#[Entity(table: 'sympress_articles')]
final class Article
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[Column(type: 'string', length: 150)]
        public string $title,
        #[ManyToOne(targetEntity: Author::class, inversedBy: 'articles')]
        #[JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false)]
        public Author $author,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Collection\Collection;
use SymPress\Orm\Mapping\Cache;
use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\OneToMany;

#[Entity(table: 'sympress_cached_authors')]
#[Cache(region: 'authors')]
final class CachedAuthor
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[Column(type: 'string', length: 100)]
        public string $name,
        #[OneToMany(targetEntity: CachedArticle::class, mappedBy: 'author')]
        #[Cache(region: 'author_articles')]
        public Collection $articles = new Collection(),
    ) {
    }
}

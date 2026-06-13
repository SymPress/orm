<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Collection\Collection;
use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\HasLifecycleCallbacks;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\OneToMany;
use SymPress\Orm\Mapping\PostLoad;
use SymPress\Orm\Mapping\PrePersist;

#[Entity(table: 'sympress_authors')]
#[HasLifecycleCallbacks]
final class Author
{
    public int $prePersistCalls = 0;
    public int $postLoadCalls = 0;

    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[Column(type: 'string', length: 100)]
        public string $name,
        #[OneToMany(targetEntity: Article::class, mappedBy: 'author')]
        public Collection $articles = new Collection(),
    ) {
    }

    #[PrePersist]
    public function onPrePersist(): void
    {
        $this->prePersistCalls++;
    }

    #[PostLoad]
    public function onPostLoad(): void
    {
        $this->postLoadCalls++;
    }
}
